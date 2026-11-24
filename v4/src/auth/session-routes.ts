// `/login`, `/logout` and `/consent` (plan §5 S1.2) and `/claim` (S1.2b): `SignInController`,
// `ConsentController` and `ClaimController`, ported.
//
// These are the slice's observable behaviour, and they are handlers rather than filters for v3's own
// reason: the routes are `none` and skip `sessioncsrf`, and each POST calls `checkCsrf()` itself,
// after its own redirects — so a request that is already signed in gets the redirect, not a 419.
//
// Two things this file does differently from v3, both named:
//
//   * the failed sign-in is padded to a fixed deadline (`SignInPace`) only when a deadline is
//     configured. The pace exists because bcrypt's time depends on the stored hash; a Worker cannot
//     `usleep`, and `await`-ing a timer for a second per wrong password is a denial-of-service on
//     ourselves. The deadline is in `RouterConfig` so a test can set it to 0 and production can keep
//     v3's 1000 ms.
//   * nothing here writes a session without cookie consent: `SessionRequest.ensureToken()` and
//     `signIn()` both require it, and `consent` refuses the write before either runs (D20).

import { statement } from "../db/sql";
import { claimPage, errorPage, loginPage } from "../html/views";
import type { Ctx } from "../http/filters";
import { formFields } from "../http/form";
import { html, json, problem } from "../http/problem";
import { verifyPassword } from "../platform/passwords";
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
import { accountKey, hit, succeeded } from "../session/throttle";
import { ClaimError, claimAdmin } from "./first-admin-claim";

/** `Accounts::USERNAME_MAX`, doubled: the field's `maxlength`, which is also `cleanLine()`'s limit. */
const USERNAME_MAX = 40;

/** `Accounts::DUMMY_HASH`: an unknown name costs one verify, so the answer does not time the name. */
const DUMMY_HASH = "$2y$12$rGbXD6ftSShKrjjQ/V0Mp.DKflUCQZ2CgnF1mN0dY.pmyaxcHRNr2";

/** `Accounts::verify()`'s one message for a wrong name and a wrong password. */
const WRONG = "Wrong user name or password.";

/** `Text::cleanLine()`: control characters out, trimmed, and at most `max` characters. */
export const cleanLine = (raw: string, max: number): string => {
	let out = "";
	for (const ch of raw) {
		const code = ch.codePointAt(0) ?? 0;
		if (code < 0x20 || code === 0x7f) {
			continue;
		}
		out += ch;
	}
	return out.trim().slice(0, max);
};

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

/** `ClientAddress`: Cloudflare's header, then the first forwarded value, then nothing. */
const clientAddress = (ctx: Ctx): string => {
	const cf = (ctx.request.headers.get("cf-connecting-ip") ?? "").trim();
	if (cf !== "") {
		return cf;
	}
	const forwarded = ctx.request.headers.get("x-forwarded-for") ?? "";
	const first = (forwarded.split(",")[0] ?? "").trim();
	return first;
};

/** The account row a user name names, or null. `users.username_key` is the repository's key. */
const findUser = async (
	ctx: Ctx,
	key: string,
): Promise<{
	id: number;
	username: string;
	passwordHash: string;
	status: string;
	sessionEpoch: string;
} | null> => {
	const rows = await ctx.session.sql.all(
		statement("SELECT id, username, password_hash, status, session_epoch FROM users WHERE username_key = ?", [
			key,
		]),
	);
	const row = rows[0];
	if (row === undefined) {
		return null;
	}
	return {
		id: Number(row.id ?? 0),
		username: String(row.username ?? ""),
		passwordHash: String(row.password_hash ?? ""),
		status: String(row.status ?? "active"),
		sessionEpoch: String(row.session_epoch ?? ""),
	};
};

/** `Accounts::touchLastLogin()`: one column, the app's stored timestamp form. */
const touchLastLogin = async (ctx: Ctx, userId: number): Promise<void> => {
	await ctx.session.sql.batch([
		statement("UPDATE users SET last_login_at = ? WHERE id = ?", [
			new Date(ctx.session.now * 1000).toISOString().slice(0, 19).replace("T", " "),
			userId,
		]),
	]);
};

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
	const password = post.get("password") ?? "";

	const address = clientAddress(ctx);
	const everyName = await hit(ctx.session.sql, {
		kind: "login_address",
		client: address,
		now: ctx.session.now,
	});
	if (!everyName.ok) {
		return throttled(ctx, everyName.retryAfterSeconds, everyName.message);
	}
	const thisName = await hit(ctx.session.sql, {
		kind: "login",
		client: address,
		account: who,
		now: ctx.session.now,
	});
	if (!thisName.ok) {
		return throttled(ctx, thisName.retryAfterSeconds, thisName.message);
	}

	// A name with a null character anywhere, checked before trimming, never matches an account.
	const lookup = raw.includes("\0") ? raw : who;
	const user = lookup.includes("\0") ? null : await findUser(ctx, accountKey(lookup));
	const matches = await verifyPassword(password, user?.passwordHash ?? DUMMY_HASH);
	if (user === null || !matches) {
		return errorAnswerWithUser(ctx, 401, WRONG, who);
	}
	if (user.status !== "active") {
		return errorAnswerWithUser(ctx, 403, "Your account is waiting for an admin’s approval.", who);
	}
	await touchLastLogin(ctx, user.id);
	const epoch = await sessionEpoch({
		id: user.id,
		passwordHash: user.passwordHash,
		sessionEpoch: user.sessionEpoch,
	});
	await ctx.session.signIn({ userId: user.id, authEpoch: epoch });
	await succeeded(ctx.session.sql, {
		client: address,
		account: who,
		now: ctx.session.now,
	});
	return redirectTo(ctx, "/");
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
