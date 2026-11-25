// `/api/v1/session` (GET, POST, DELETE) and `/api/v1/me` (plan §5 S1.4): v3's
// `modules/Auth/Controllers/Api/SessionController.php`, ported.
//
// The API is the same code as `/login` and `/logout`, on purpose (v3's own comment): the attempt is
// `attemptSignIn()`, the session is `SessionRequest.signIn()`/`signOut()`, and what differs is only
// the answer — problem+json with a stable `code` instead of a page.
//
// Three shapes, and they are not interchangeable (the OpenAPI document says so with
// `additionalProperties: false`):
//
//   state()    signed_in, sign_in_enabled, registration_open, user ({id, username, role} | null),
//              permissions, csrf_token                                         (GET and POST /session)
//   account()  id, username, role, permissions                               (GET /me)
//   204        no body at all                                                (DELETE /session)
//
// The CSRF rule on this surface is `ApiCsrfFilter`'s, not the pages': a non-safe method needs a
// same-origin request whose `X-CSRF-Token` is the session's token. That filter refuses *before* the
// handler, so a POST that would have been 422 answers 403 without the token. See `http/filters.ts`.
//
// What is deliberately not here: `AuthPages::firstAdmin()`. v3 creates the account `admin` from
// `APP_PASSWORD` on a running install that has no accounts; v4 creates it once at boot, in the
// seed phase (S0.3), so the request path has nothing left to do. The observable state is the same;
// what differs is that v4 does not write the row on a GET.

import type { Ctx } from "../http/filters";
import { json, type ProblemCode, problem } from "../http/problem";
import { LIMITS } from "../session/throttle";
import { type Actor, allows, resolveActor } from "./permission";
import { ALL_KEYS } from "./permissions";
import { attemptSignIn, cleanLine, PENDING, startSignIn, USERNAME_MAX, WRONG } from "./sign-in";

/** `ApiCsrf::DETAIL`, re-stated here only so a test can name the page-free refusal. */
export { API_CSRF_DETAIL } from "../http/filters";

/** `Permissions::ALL`'s keys, in PHP's order, filtered by `PermissionCheck::allows()`. */
const permissionsOf = (actor: Actor | null): string[] => ALL_KEYS.filter((key) => allows(actor, key));

/**
 * v3's `me()` (`CurrentUser::resolve()`), for a route whose permission is `public` and therefore
 * skipped the router's own resolve. One roles read, plus one account read when there is a session.
 */
const visitor = async (ctx: Ctx): Promise<Actor | null> =>
	ctx.actor ??
	(await resolveActor(ctx.session.sql, ctx.session.identity, {
		signInEnabled: ctx.config.signInEnabled,
		guestBrowsing: ctx.config.login.guestBrowsing,
	}));

/** `ApiResponse::json()`: 200, `application/json; charset=utf-8`, `no-store`. */
const apiJson = (ctx: Ctx, body: unknown, status = 200): Response => json(body, status, ctx.config.https);

/** `ApiResponse::problem()`, with the 422 `errors` map and the 429's `Retry-After`. */
const apiProblem = (
	ctx: Ctx,
	status: number,
	code: ProblemCode,
	detail: string,
	errors?: Readonly<Record<string, string>>,
	retryAfter?: number,
): Response =>
	problem(ctx.path, ctx.config.https, status, code, detail, {
		...(errors === undefined ? {} : { errors }),
		...(retryAfter === undefined ? {} : { extra: { "Retry-After": String(retryAfter) } }),
	});

/** `SessionController::state()`: the body of GET and of a successful POST. */
const state = async (ctx: Ctx): Promise<Record<string, unknown>> => {
	// `SessionToken::get()`: a page that asks for the token starts the session — but only with the
	// cookie consent v3 requires, which is why a consentless GET answers `csrf_token: ""`.
	const csrf = await ctx.session.ensureToken();
	const me = await visitor(ctx);
	const signedIn = me !== null && !me.guest;
	return {
		signed_in: signedIn,
		sign_in_enabled: ctx.config.signInEnabled,
		registration_open: ctx.config.login.registrationOpen,
		user: signedIn && me !== null ? { id: me.id, username: me.username, role: me.role } : null,
		permissions: permissionsOf(me),
		csrf_token: csrf,
	};
};

/** GET /api/v1/session: who is signed in, what they may do, and the CSRF token. */
export const apiSessionShow = async (ctx: Ctx): Promise<Response> => apiJson(ctx, await state(ctx));

/** The nesting `json_decode(..., 8)` refuses: PHP counts the outermost container as depth 1. */
const tooDeep = (value: unknown): boolean => {
	const depth = (node: unknown): number => {
		if (node === null || typeof node !== "object") {
			return 0;
		}
		let deepest = 0;
		for (const child of Object.values(node as Record<string, unknown>)) {
			deepest = Math.max(deepest, depth(child));
		}
		return deepest + 1;
	};
	return depth(value) >= 8;
};

/** `is_array($body)`: a JSON object, or an array that the field loop treats as a map. */
const asFieldMap = (value: unknown): Record<string, unknown> =>
	value !== null && typeof value === "object" ? (value as Record<string, unknown>) : {};

/** POST /api/v1/session `{username, password}`: sign in. */
export const apiSessionCreate = async (ctx: Ctx): Promise<Response> => {
	// `SignInController` and this controller both start the attempt first: a deadline is measured
	// from here, so a fast refusal (sign-in off, a bad body) does not wait for one.
	const started = startSignIn();
	if (!ctx.config.signInEnabled) {
		return apiProblem(ctx, 409, "auth.sign_in_off", "Sign-in is turned off on this server.");
	}
	// `trim((string)$this->request->getBody())`, so an empty body is checked like `{}`: both fields
	// are missing, and the answer is the same 422 as an empty object.
	const text = (await ctx.request.text()).trim();
	let parsed: unknown = {};
	if (text !== "") {
		try {
			parsed = JSON.parse(text);
		} catch {
			return apiProblem(ctx, 400, "bad_request", "The body is not valid JSON.");
		}
		if (tooDeep(parsed)) {
			// PHP's `json_decode($text, true, 8, JSON_THROW_ON_ERROR)` refuses this as well, and the
			// answer is the same 400 — a stack limit is not a field error.
			return apiProblem(ctx, 400, "bad_request", "The body is not valid JSON.");
		}
	}
	const body = asFieldMap(parsed);
	const errors: Record<string, string> = {};
	for (const field of ["username", "password"] as const) {
		const value = body[field];
		if (value !== undefined && value !== null && typeof value !== "string") {
			errors[field] = "Must be text.";
		} else if ((value ?? "") === "") {
			errors[field] = "Required.";
		}
	}
	if (Object.keys(errors).length > 0) {
		return apiProblem(ctx, 422, "validation.failed", "Enter a user name and password.", errors);
	}
	const raw = String(body.username);
	const who = cleanLine(raw, USERNAME_MAX * 2);
	const outcome = await attemptSignIn(ctx, { started, raw, who, password: String(body.password) });
	switch (outcome.kind) {
		case "throttled":
			// `RateLimiter::LIMITS['login'][1]`: v3 sends the same 900 for either limit.
			return apiProblem(ctx, 429, "rate_limited", outcome.message, undefined, LIMITS.login?.[1]);
		case "invalid":
			return apiProblem(ctx, 401, "auth.invalid_credentials", WRONG);
		case "pending":
			return apiProblem(ctx, 403, "auth.pending_approval", PENDING);
		case "ok":
			return apiJson(ctx, await state(ctx));
	}
};

/** DELETE /api/v1/session: sign out — the row goes and the cookie expires, in a 204 with no body. */
export const apiSessionDelete = async (ctx: Ctx): Promise<Response> => {
	await ctx.session.signOut();
	return new Response(null, { status: 204 });
};

/**
 * GET /api/v1/me: your own account, four keys (`SessionController::account()`). The route's
 * permission is `self`, so the filter has already refused everyone who is not a real account — the
 * anonymous admin (sign-in off) included, which is why `id` can be a number here without a cast.
 */
export const apiMe = async (ctx: Ctx): Promise<Response> => {
	const me = await visitor(ctx);
	return apiJson(ctx, {
		id: me?.id ?? null,
		username: me?.username ?? "",
		role: me?.role ?? "",
		permissions: permissionsOf(me),
	});
};
