// `/login`, `/logout` and `/consent` (plan §5 S1.2) and `/claim` (S1.2b): `SignInController`,
// `ConsentController` and `ClaimController`, ported.
//
// These are the slice's observable behaviour, and they are handlers rather than filters for v3's own
// reason: the routes are `none` and skip `sessioncsrf`, and each POST calls `checkCsrf()` itself,
// after its own redirects — so a request that is already signed in gets the redirect, not a 419.
//
// The sign-in attempt itself lives in `sign-in.ts`: S1.4 added a second caller (`POST
// /api/v1/session`) and v3 keeps one implementation for both, so this file now maps its outcome to
// the page's answers and the API maps it to problem+json. Two things this file does differently from
// v3, both named:
//
//   * the failed sign-in is padded to a fixed deadline (`SignInPace`, in `session/sign-in-pace.ts`)
//     by `attemptSignIn()`. The pace exists because bcrypt's time depends on the stored hash; a
//     Worker cannot `usleep`, so the wait is an `await`ed timer. The deadline is in `RouterConfig`
//     so a test can set it to 0 and production keeps v3's 1000 ms.
//   * nothing here writes a session without cookie consent: `SessionRequest.ensureToken()` and
//     `signIn()` both require it, and `consent` refuses the write before either runs (D20).

import { claimPage, errorPage, loginPage } from "../html/views";
import type { Ctx } from "../http/filters";
import { formFields } from "../http/form";
import { html, json, problem } from "../http/problem";
import {
	CONSENT_CODE,
	CONSENT_MESSAGE,
	CONSENT_STATUS,
	consentCookieLine,
	consentExpiryLine,
	returnPath,
} from "../session/consent";
import { CSRF_MESSAGE, CSRF_STATUS, csrfPasses } from "../session/csrf";
import { sessionEpoch } from "../session/epoch";
import { hit } from "../session/throttle";
import { ClaimError, claimAdmin } from "./first-admin-claim";
import {
	attemptSignIn,
	cleanLine,
	clientAddress,
	PENDING,
	startSignIn,
	USERNAME_MAX,
	WRONG,
} from "./sign-in";

/** `WantsJson::check()`: a fetch, or a client that asked for JSON. */
export const wantsJson = (ctx: Ctx): boolean =>
	(ctx.request.headers.get("x-requested-with") ?? "") === "fetch" ||
	(ctx.request.headers.get("accept") ?? "").includes("application/json");

/** `AuthPages::redirectTo()`: 303 with `Location`, or `{ok, redirect}` for a fetch. */
export const redirectTo = (ctx: Ctx, to: string): Response => {
	if (wantsJson(ctx)) {
		return json({ ok: true, redirect: to }, 200, ctx.config.https);
	}
	return new Response(null, {
		status: 303,
		headers: { Location: to, "Cache-Control": "no-store" },
	});
};

/** `AuthPages::page()`: the app's page, with the shell's own values and this request's token. */
const page = (body: string, status = 200): Response => html(body, status);

/** The page settings this request renders with: its token and whether it carries consent. */
export const pageSettings = (ctx: Ctx) => ({
	...ctx.config.page,
	csrf: ctx.session.csrf,
	consent: ctx.session.consent,
	requestUri: `${new URL(ctx.request.url).pathname}${new URL(ctx.request.url).search}`,
});

/** The 429 `RateLimiter::hit()` ends in, with the `Retry-After` v4 adds (§5, D21). */
const throttled = (ctx: Ctx, retryAfterSeconds: number, message: string): Response => {
	if (ctx.config.https || wantsJson(ctx)) {
		return problem(ctx.path, ctx.config.https, 429, "auth.throttled", message, {
			extra: { "Retry-After": String(retryAfterSeconds) },
		});
	}
	return html(errorPage(pageSettings(ctx), message), 429, {
		"Retry-After": String(retryAfterSeconds),
	});
};

/**
 * `SessionController`'s own answer for a page route whose request is not a page: the 403/401/419 body
 * is the app's error page, which is what v3's `page()` renders for these codes.
 */
const errorAnswer = (ctx: Ctx, status: number, message: string): Response =>
	html(errorPage(pageSettings(ctx), message), status);

/** `SignInController::login()`: GET (and every other verb) renders; POST signs in. */
export const signInHandler = async (ctx: Ctx): Promise<Response> => {
	// `SignInController::login()` starts the attempt before anything it answers: a failed sign-in must
	// answer at the deadline even when the failure is a missing CSRF token... except that v3's page
	// answers 419 from `checkCsrf()` before `attemptSignIn()`, so only the attempt itself is paced.
	const startedAt = startSignIn();
	if (ctx.identity !== null && ctx.method !== "POST") {
		return redirectTo(ctx, "/"); // `signedIn()`: the page is not for someone who is already in
	}
	if (!ctx.config.signInEnabled) {
		return redirectTo(ctx, "/"); // `!$this->settings()->signInEnabled()`
	}
	if (ctx.method !== "POST") {
		// Every other verb shows the page, as v3 does (`N-63`). `headasget` has already turned HEAD
		// into GET, so this is the whole non-POST surface.
		await ctx.session.ensureToken();
		const registered = new URL(ctx.request.url).searchParams.get("registered");
		return page(
			loginPage(pageSettings(ctx), {
				error: null,
				username: "",
				note: registered === "pending" ? "pending" : null,
				registrationOpen: ctx.config.login.registrationOpen,
				guestBrowsing: ctx.config.login.guestBrowsing,
			}),
		);
	}

	const post = await formFields(ctx.request);
	if (!csrfPasses(ctx.session.csrf, post, ctx.request.headers.get("x-csrf-token"))) {
		return errorAnswer(ctx, CSRF_STATUS, CSRF_MESSAGE);
	}

	const raw = post.get("username") ?? "";
	const who = cleanLine(raw, USERNAME_MAX * 2);
	const outcome = await attemptSignIn(ctx, {
		started: startedAt,
		raw,
		who,
		password: post.get("password") ?? "",
	});
	switch (outcome.kind) {
		case "throttled":
			return throttled(ctx, outcome.retryAfterSeconds, outcome.message);
		case "invalid":
			return errorAnswerWithUser(ctx, 401, WRONG, who);
		case "pending":
			return errorAnswerWithUser(ctx, 403, PENDING, who);
		case "ok":
			return redirectTo(ctx, "/");
	}
};

/** A failed attempt renders the page again with what was typed, which is what v3's `loginPage()` does. */
const errorAnswerWithUser = (ctx: Ctx, status: number, message: string, username: string): Response =>
	page(
		loginPage(pageSettings(ctx), {
			error: message,
			username,
			note: null,
			registrationOpen: ctx.config.login.registrationOpen,
			guestBrowsing: ctx.config.login.guestBrowsing,
		}),
		status,
	);

/**
 * `SignInController::logout()`: a live session still needs the token; one that already ended has
 * nothing left to protect, so the open page can sign out instead of answering 419.
 */
export const logoutHandler = async (ctx: Ctx): Promise<Response> => {
	if (ctx.identity !== null) {
		const post = await formFields(ctx.request);
		if (!csrfPasses(ctx.session.csrf, post, ctx.request.headers.get("x-csrf-token"))) {
			return errorAnswer(ctx, CSRF_STATUS, CSRF_MESSAGE);
		}
		await ctx.session.signOut();
	} else if (ctx.session.loaded || ctx.session.carried.length > 0) {
		await ctx.session.signOut();
	}
	return redirectTo(ctx, ctx.config.signInEnabled ? "/login" : "/");
};

/** `SameOrigin::strict()`: no CSRF token exists yet, so the origin is the whole check. */
const sameOriginStrict = (ctx: Ctx): boolean => {
	const site = (ctx.request.headers.get("sec-fetch-site") ?? "").trim().toLowerCase();
	if (site !== "" && site !== "same-origin") {
		return false;
	}
	const origin = (ctx.request.headers.get("origin") ?? "").trim();
	const own = new URL(ctx.request.url);
	const same = `${own.protocol}//${own.host}`.toLowerCase();
	if (origin !== "") {
		return origin.toLowerCase().replace(/\/+$/, "") === same;
	}
	return site === "same-origin";
};

/** `ConsentController::accept()`: the notice's Accept and Decline. No token: there is no session yet. */
export const consentHandler = async (ctx: Ctx): Promise<Response> => {
	if (!sameOriginStrict(ctx)) {
		return errorAnswer(ctx, 403, "This request came from another site and was refused.");
	}
	const post = await formFields(ctx.request);
	const choice = post.get("consent");
	if (choice !== "accept" && choice !== "decline") {
		return errorAnswer(ctx, 400, "Bad request");
	}
	const lines: string[] = [];
	if (choice === "decline") {
		// A session the request carries ends with the consent, as `SessionCookie` ends one it may not load.
		if (ctx.session.loaded || ctx.session.carried.length > 0) {
			await ctx.session.signOut();
		}
		lines.push(consentExpiryLine(ctx.session.secure));
	} else {
		lines.push(consentCookieLine(ctx.session.secure));
	}
	const headers = new Headers({
		Location: returnPath(post.get("return")),
		"Cache-Control": "no-store",
	});
	if (lines.length > 0) {
		headers.set("Set-Cookie", lines.join(", "));
	}
	return new Response(null, { status: 303, headers });
};

/** `CookieConsent::STATUS`/`CODE`: re-exported so a test can name the refusal without a second import. */
export const consentRefusal = { status: CONSENT_STATUS, code: CONSENT_CODE, message: CONSENT_MESSAGE };

/**
 * `ClaimController::claim()` (plan §5 S1.2b): the hosted installation's first-admin page. Off without
 * `APOTHEKE_HOSTED` — 404, the same answer v3 gives and the gate the DoD names — and the check comes
 * before any token work, so a self-hosted install learns nothing from a POST either.
 *
 * The page and every refusal render `AuthViews::claim()`, as v3 does: a failed claim shows what was
 * typed at the status v3 gives it. The one exception is the missing CSRF token, which v3 throws out
 * of the controller and which arrives as the app's error page at 419.
 */
export const claimHandler = async (ctx: Ctx): Promise<Response> => {
	if (!ctx.config.hosted) {
		return errorAnswer(ctx, 404, "Page not found.");
	}
	if (ctx.method !== "POST") {
		await ctx.session.ensureToken();
		return page(claimPage(pageSettings(ctx), { error: null, username: "" }));
	}
	const post = await formFields(ctx.request);
	if (!csrfPasses(ctx.session.csrf, post, ctx.request.headers.get("x-csrf-token"))) {
		return errorAnswer(ctx, CSRF_STATUS, CSRF_MESSAGE);
	}
	const posted = post.get("username") ?? "";
	const who = cleanLine(posted, USERNAME_MAX * 2);
	// v3 limits a claim like a registration: `register`, 5 per hour per address, counted before the
	// claim runs and taken back if it is refused.
	const tried = await hit(ctx.session.sql, {
		kind: "register",
		client: clientAddress(ctx),
		now: ctx.session.now,
	});
	if (!tried.ok) {
		return html(claimPage(pageSettings(ctx), { error: tried.message, username: who }), 429);
	}
	try {
		const user = await claimAdmin(ctx.session.sql, {
			token: post.get("token") ?? "",
			// A name with a null character reaches `nameValid()` untrimmed, as v3 passes it, so the refusal
			// names the shape rather than quietly matching a cleaned-up form of it.
			username: posted.includes("\0") ? posted : who,
			password: post.get("new") ?? "",
			confirm: post.get("confirm") ?? "",
			now: ctx.session.now,
		});
		const epoch = await sessionEpoch({
			id: user.id,
			passwordHash: user.passwordHash,
			sessionEpoch: user.sessionEpoch,
		});
		await ctx.session.signIn({ userId: user.id, authEpoch: epoch });
		return redirectTo(ctx, "/?done=welcome");
	} catch (error) {
		if (error instanceof ClaimError) {
			return html(claimPage(pageSettings(ctx), { error: error.message, username: who }), error.status);
		}
		throw error;
	}
};
