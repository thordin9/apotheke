// The filter chain's filters (plan §5 S0.5): each one a named object, run in `chain.ts`'s order.
//
// Every filter here is a *decision*, ported from its PHP counterpart in `modules/Core/Filters/` and
// `modules/Auth/Filters/` where phase 0 has what the decision needs. Where phase 0 does not — a
// session store, a CSRF token, a consent cookie, the hosted operator console — the filter either
// fails closed (refuses the request) or is listed in `DEFERRED` with the reason, and the request goes
// on to be refused by a filter that *can* decide. Nothing here quietly allows a request whose
// precondition it cannot evaluate:
//
//   session       no session store yet, so it records "no identity" — and `permission` refuses every
//                 route that needs one. It never invents a user.
//   sessioncsrf   a form token cannot be checked without a session, so an unsafe request is refused.
//   apicsrf      the same for /api's Origin + token check.
//   consent      the cookie contract arrives with sessions (P1); the writes it would refuse are
//                 refused by `permission` (401) and `sessioncsrf`/`apicsrf` (403) first.
//   formfields   a validation rule, not an access decision; the writes it would 422 are refused first.
//   harness      an environment mark in the PHP process; there is nothing to read in a Worker.
//   requestcleanup, opsbackup, opsaccounts  nothing of theirs exists in phase 0 (no uploads, no hosted
//                 operator console), and the /internal paths they own answer 501 rather than pass.
//
// The refusals answer problem+json with the PHP app's header set for their path — see `problem.ts`.

import { type Actor, refusal as permissionRefusal, SIGN_IN } from "../auth/permission";
import type { PageSettings } from "../html/page";
import { errorPage } from "../html/views";
import { CONSENT_CODE, CONSENT_MESSAGE, CONSENT_STATUS, consentNeeded } from "../session/consent";
import type { SessionRequest } from "../session/request";
import type { Identity } from "../session/store";
import { apiHeaders, html, isApi, PAGE_HEADERS, problem } from "./problem";
import type { RouteRow } from "./route-table";

/** What the filters decide on: the request, the resolved route, and what earlier filters set. */
export interface Ctx {
	readonly request: Request;
	readonly url: URL;
	/** The verb as sent. */
	readonly rawMethod: string;
	/** The effective verb: `headasget` turns HEAD into GET. */
	method: string;
	/** The request path as sent, with a leading slash. */
	readonly path: string;
	/** The route this request dispatches on, if any (never the /api catch-all). */
	readonly route: RouteRow | null;
	/** The /api catch-all, when the path is under /api and nothing else matched. */
	readonly catchAll: RouteRow | null;
	/** The verbs this path takes, for a 405's `Allow`. */
	readonly allow: readonly string[];
	readonly config: {
		/** `PUBLIC_URL`, trailing slash removed; empty in a fresh install. */
		readonly publicUrl: string;
		/** Whether the public URL is https — the API's HSTS decision. */
		readonly https: boolean;
		readonly hosted: boolean;
		readonly maxUploadMb: number;
		/** The shell's values, for the error pages a page-path refusal renders (S1.1). */
		readonly page: PageSettings;
		readonly login: {
			readonly registrationOpen: boolean;
			readonly guestBrowsing: boolean;
		};
		/** `SettingsService::signInEnabled()`: `/login` redirects rather than renders when it is off. */
		readonly signInEnabled: boolean;
		readonly signInDeadlineMs?: number;
	};
	/** Set by `publicbase`: the origin the app thinks it is served from. */
	baseUrl: string;
	/**
	 * The session this request carries, loaded before the chain (`index.ts`): the filters cannot do
	 * I/O, so the round trips happen at the edge and every decision below is taken on the result.
	 */
	readonly session: SessionRequest;
	/** Set by `session`: the account this request is signed in as, or null. */
	identity: Identity | null;
	/**
	 * The account a *permission* check sees (`src/auth/permission.ts`): the session's role and what
	 * the roles table allows it, or v3's anonymous admin while sign-in is off, or nobody. Resolved
	 * once before the chain, because a filter cannot await and this is a database read.
	 */
	actor: Actor | null;
	/** Filter names in the order they ran — the order test reads this. */
	readonly trace: string[];
	/** Filters that declared themselves deferred on this request. */
	readonly deferred: Set<string>;
}

export interface Filter {
	/** Returns a refusal, or null to continue. A refusal skips every later filter and the handler. */
	readonly before?: (ctx: Ctx) => Response | null;
	/** Rewrites a response every request gets, routed or not. */
	readonly after?: (ctx: Ctx, response: Response) => Response;
	/** Why this filter does not do its PHP work in phase 0, and what closes the gap. */
	readonly deferred?: string;
}

const SAFE_METHODS: readonly string[] = ["GET", "HEAD", "OPTIONS"];

const isSafe = (method: string): boolean => SAFE_METHODS.includes(method.toUpperCase());

const contentLength = (request: Request): number => {
	const raw = request.headers.get("content-length");
	const value = raw === null ? 0 : Number.parseInt(raw, 10);
	return Number.isFinite(value) && value > 0 ? value : 0;
};

/* ------------------------------------------------------------------------------------------------
 * Host (HostFilter): IP literals, localhost and *.localhost always pass; anything else must be
 * PUBLIC_URL's host. `ALLOWED_HOSTS` is not in v4's environment contract (§11), so it is not read
 * here — the walkthrough documents that limit.
 * ---------------------------------------------------------------------------------------------- */

/** Legacy `request_host_refuse()`, verbatim; it never repeats the name that was sent. */
export const HOST_REFUSAL =
	"Unknown host name. Open the app by its address, or add this name to ALLOWED_HOSTS (or PUBLIC_URL).\n";

const HOST_NAME = /^([a-z0-9_.-]+)(?::(\d{1,5}))?$/;
const HOST_LITERAL = /^\[([0-9a-f:.]+)\](?::(\d{1,5}))?$/;

const validPort = (port: string | undefined): boolean =>
	port === undefined || (Number(port) >= 1 && Number(port) <= 65535);

/** The host name of a `Host` header (port dropped), or null when it is not `name[:port]`. */
export const splitHost = (header: string): string | null => {
	const value = header.trim().toLowerCase();
	if (value === "") {
		return null;
	}
	const literal = HOST_LITERAL.exec(value);
	if (literal !== null) {
		const name = literal[1];
		if (name === undefined || !name.includes(":") || !validPort(literal[2]) || !/^[0-9a-f:.]+$/.test(name)) {
			return null;
		}
		return name;
	}
	const match = HOST_NAME.exec(value);
	const raw = match?.[1];
	if (raw === undefined || !validPort(match?.[2])) {
		return null;
	}
	const name = raw.replace(/\.+$/, "");
	if (name === "" || name.startsWith(".") || name.startsWith("-") || name.includes("..")) {
		return null;
	}
	return name;
};

const isIpv4 = (name: string): boolean =>
	/^\d{1,3}(?:\.\d{1,3}){3}$/.test(name) && name.split(".").every((part) => Number(part) <= 255);

const publicUrlHost = (publicUrl: string): string | null => {
	if (publicUrl === "") {
		return null;
	}
	try {
		const host = new URL(publicUrl).hostname.toLowerCase();
		return host.replace(/^\[|\]$/g, "");
	} catch {
		return null;
	}
};

const hostAllowed = (hostHeader: string, publicUrl: string): boolean => {
	const name = splitHost(hostHeader);
	if (name === null) {
		return false;
	}
	if (isIpv4(name) || name.includes(":")) {
		return true;
	}
	if (name === "localhost" || name.endsWith(".localhost")) {
		return true;
	}
	const configured = publicUrlHost(publicUrl);
	return configured !== null && name === configured;
};

const hostcheck: Filter = {
	before: (ctx) => {
		const host = ctx.request.headers.get("host") ?? ctx.url.host;
		if (hostAllowed(host, ctx.config.publicUrl)) {
			return null;
		}
		return new Response(HOST_REFUSAL, {
			status: 421,
			headers: { ...PAGE_HEADERS, "Content-Type": "text/plain; charset=UTF-8", "Cache-Control": "no-store" },
		});
	},
};

/* ------------------------------------------------------------------------------------------------
 * The rest of the before chain
 * ---------------------------------------------------------------------------------------------- */

/**
 * The hosted operator console (C2) answers `POST /internal/…` before every other filter. Phase 0 has
 * no console, so the paths it owns refuse instead of falling through to a route that does not exist.
 */
const operatorConsole = (owns: string, note: string): Filter => ({
	before: (ctx) => {
		const path = ctx.path.replace(/^\/+/, "").toLowerCase();
		if (path !== "internal" && !path.startsWith("internal/")) {
			return null;
		}
		return problem(
			ctx.path,
			ctx.config.https,
			501,
			"route.not_implemented",
			`${owns} is the hosted operator console (C2), which phase 0 does not have; ${note}`,
		);
	},
	deferred: "the hosted operator console is C2; the paths it owns answer 501 rather than pass",
});

const headasget: Filter = {
	before: (ctx) => {
		if (ctx.method === "HEAD") {
			ctx.method = "GET";
		}
		return null;
	},
};

const originalsguard: Filter = {
	before: (ctx) => {
		let path = ctx.path;
		try {
			path = decodeURIComponent(path);
		} catch {
			// A malformed escape cannot spell /originals/ in any case the guard would miss.
		}
		if (!/(?:^|\/)originals(?:\/|$)/i.test(path)) {
			return null;
		}
		// No security headers on purpose: v3 answers this before them (OriginalsGuardFilter).
		return new Response("Not found", {
			status: 404,
			headers: { "Content-Type": "text/plain; charset=UTF-8" },
		});
	},
};

const publicbase: Filter = {
	before: (ctx) => {
		ctx.baseUrl = ctx.config.publicUrl === "" ? `${ctx.url.protocol}//${ctx.url.host}` : ctx.config.publicUrl;
		return null;
	},
};

const api: Filter = {
	before: (ctx) => {
		// A CORS preflight is answered before the session and permission filters, as ApiFilter does.
		if (!isApi(ctx.path) || ctx.method !== "OPTIONS") {
			return null;
		}
		return new Response(null, { status: 204 });
	},
	after: (ctx, response) => {
		if (!isApi(ctx.path)) {
			return response;
		}
		const headers = new Headers(response.headers);
		headers.delete("X-Frame-Options"); // the HTML set's; frame-ancestors covers it
		for (const [name, value] of Object.entries(apiHeaders(ctx.config.https))) {
			headers.set(name, value);
		}
		// No CORS headers: `CORS_ALLOWED_ORIGINS` is not in v4's environment contract (§11), so the
		// list is empty and the PHP side sends none either — the self-hosted default.
		return new Response(response.body, { status: response.status, headers });
	},
	deferred: "CORS_ALLOWED_ORIGINS is not in v4's env contract; the list is empty as it is by default",
};

/**
 * `RouteMethodFilter`: the verbs a 405 lists, with `HEAD` spliced in after `GET` exactly once. The
 * table carries explicit `HEAD` rows (the PHP side declares them), and the filter's own list does
 * not — it inserts one — so both inputs must land on the same header.
 */
const allowHeader = (allow: readonly string[]): string => {
	const verbs = allow.filter((verb) => verb !== "HEAD");
	const at = verbs.indexOf("GET");
	return (at === -1 ? verbs : [...verbs.slice(0, at + 1), "HEAD", ...verbs.slice(at + 1)]).join(", ");
};

/** `WantsJson`: a fetch (`X-Requested-With: fetch`) or an `Accept` that names JSON. */
const wantsJson = (request: Request): boolean =>
	request.headers.get("x-requested-with") === "fetch" ||
	(request.headers.get("accept") ?? "").includes("application/json");

/**
 * CI4's error response for a page path, as the PHP app sends it: `Cache-Control: no-store,
 * max-age=0, no-cache` on top of the page header set, which a before-filter refusal must carry
 * itself because the globals after filters do not run for it.
 */
const ERROR_PAGE_CACHE: Readonly<Record<string, string>> = {
	"Cache-Control": "no-store, max-age=0, no-cache",
};

/** `Services::errorPage()->html($status, $message)`: the app's error page, with the page headers. */
const pageError = (
	ctx: Ctx,
	status: number,
	message: string,
	extra: Readonly<Record<string, string>>,
	cache: Readonly<Record<string, string>>,
): Response => html(errorPage(ctx.config.page, message), status, { ...cache, ...extra });

const routemethod: Filter = {
	before: (ctx) => {
		if (ctx.route !== null) {
			return null;
		}
		// A doubled slash is not /api to the api filter; under /api it is the JSON 404 as well.
		const collapsed = ctx.path.replace(/\/{2,}/g, "/");
		if (isApi(collapsed) && ctx.path.includes("//")) {
			return problem(ctx.path, ctx.config.https, 404, "not_found", "No such API endpoint.");
		}
		if (ctx.allow.length > 0) {
			if (isApi(collapsed)) {
				return problem(
					ctx.path,
					ctx.config.https,
					405,
					"method_not_allowed",
					"This endpoint does not take this method.",
					{ extra: { Allow: allowHeader(ctx.allow) } },
				);
			}
			// A page path: v3's error page, or legacy JSON for a fetch (`RouteMethodFilter::notAllowed`).
			const allow = allowHeader(ctx.allow);
			if (wantsJson(ctx.request)) {
				return new Response(JSON.stringify({ ok: false, error: "Method not allowed" }), {
					status: 405,
					headers: {
						...PAGE_HEADERS,
						"Content-Type": "application/json; charset=utf-8",
						"Cache-Control": "no-store",
						Allow: allow,
					},
				});
			}
			return pageError(
				ctx,
				405,
				"This page does not take this kind of request.",
				{ Allow: allow },
				ERROR_PAGE_CACHE,
			);
		}
		if (isApi(collapsed)) {
			return problem(ctx.path, ctx.config.https, 404, "not_found", "No such API endpoint.");
		}
		// `PlainHandler`: an unknown page is 404 "Page not found." on the app's own error page.
		return pageError(ctx, 404, "Page not found.", {}, ERROR_PAGE_CACHE);
	},
};

const posttoolarge: Filter = {
	before: (ctx) => {
		const limit = ctx.config.maxUploadMb * 1024 * 1024;
		if (contentLength(ctx.request) <= limit) {
			return null;
		}
		return problem(
			ctx.path,
			ctx.config.https,
			413,
			"upload.too_large",
			`Upload too large (max ${ctx.config.maxUploadMb} MB).`,
		);
	},
};

/**
 * `SessionCookie::before()`: the session this request carries, or none. The row is loaded before the
 * chain — a filter cannot await, and the load is a database round trip — so the decision here is the
 * one the filter owns: who this request is, and nothing invented when the answer is nobody.
 */
const session: Filter = {
	before: (ctx) => {
		ctx.identity = ctx.session.identity;
		return null;
	},
};

/** `SameOrigin::ownOrigins()`: this request's own origin, and PUBLIC_URL's when set. */
const ownOrigins = (ctx: Ctx): readonly string[] => {
	const origins = [`${ctx.url.protocol}//${ctx.url.host}`];
	if (ctx.config.publicUrl !== "") {
		try {
			const url = new URL(ctx.config.publicUrl);
			origins.push(`${url.protocol}//${url.host}`);
		} catch {
			// A malformed PUBLIC_URL fails at boot, not here.
		}
	}
	return origins.map((origin) => origin.toLowerCase().replace(/\/+$/, ""));
};

/** The origin of a `Referer`, or "" when there is none. */
const refererOrigin = (referer: string): string => {
	if (referer.trim() === "") {
		return "";
	}
	try {
		const url = new URL(referer.trim());
		return url.protocol === "http:" || url.protocol === "https:"
			? `${url.protocol}//${url.host}`.toLowerCase()
			: "";
	} catch {
		return "";
	}
};

const sameorigin: Filter = {
	before: (ctx) => {
		if (isSafe(ctx.method) || isApi(ctx.path)) {
			return null;
		}
		const site = (ctx.request.headers.get("sec-fetch-site") ?? "").trim().toLowerCase();
		let origin = (ctx.request.headers.get("origin") ?? "").trim().toLowerCase().replace(/\/+$/, "");
		if (site !== "") {
			if (site === "same-origin" || site === "none") {
				return null;
			}
		} else {
			if (origin === "") {
				origin = refererOrigin(ctx.request.headers.get("referer") ?? "");
				if (origin === "") {
					return null; // curl, scripts, old clients: the CSRF token decides, as in v3
				}
			}
			if (ownOrigins(ctx).includes(origin)) {
				return null;
			}
		}
		return problem(
			ctx.path,
			ctx.config.https,
			403,
			"origin.refused",
			"This request came from another site and was refused. Open the app directly and try again.",
		);
	},
};

/** `Permissions::NONE` and the API's `public`: both answer before a permission check. */
const NO_PERMISSION: readonly string[] = ["none", "public"];

/**
 * `PermissionCheck::refusal()`'s answers, as `PermissionFilter` writes them: problem+json under
 * `/api`, legacy JSON for a fetch, and for a page either 303 to `/login` (nobody) or v3's 403 page.
 */
const permissionRefused = (ctx: Ctx, verdict: string): Response => {
	if (isApi(ctx.path)) {
		return verdict === SIGN_IN
			? problem(ctx.path, ctx.config.https, 401, "auth.required", "Sign in first.")
			: problem(ctx.path, ctx.config.https, 403, "permission.denied", verdict);
	}
	const json = wantsJson(ctx.request);
	if (verdict === SIGN_IN) {
		if (json) {
			return new Response(JSON.stringify({ ok: false, error: "Not signed in", redirect: "/login" }), {
				status: 401,
				headers: {
					...PAGE_HEADERS,
					"Content-Type": "application/json; charset=utf-8",
					"Cache-Control": "no-store",
				},
			});
		}
		// v3's exception: media is fetched by the page, so it answers 401 with no body and no redirect.
		if (ctx.path.startsWith("/media/")) {
			return new Response(null, { status: 401, headers: { ...PAGE_HEADERS, "Content-Type": "" } });
		}
		// Since S1.1 there is a `/login` to send them to, so v3's answer is the real one again.
		return new Response(null, {
			status: 303,
			headers: { ...PAGE_HEADERS, Location: "/login", "Cache-Control": "no-store, max-age=0, no-cache" },
		});
	}
	if (json) {
		return new Response(JSON.stringify({ ok: false, error: verdict }), {
			status: 403,
			headers: {
				...PAGE_HEADERS,
				"Content-Type": "application/json; charset=utf-8",
				"Cache-Control": "no-store",
			},
		});
	}
	return pageError(ctx, 403, verdict, {}, ERROR_PAGE_CACHE);
};

const permission: Filter = {
	before: (ctx) => {
		if (ctx.route === null) {
			return null;
		}
		const need = ctx.route.permission;
		if (need === null) {
			// `PermissionFilter` refuses a route without the option to everyone (fail closed).
			return problem(
				ctx.path,
				ctx.config.https,
				403,
				"permission.denied",
				"This route has no permission recorded.",
			);
		}
		if (NO_PERMISSION.includes(need)) {
			return null;
		}
		// S1.3: the role's own permissions decide (`PermissionCheck`), not the phase-0 401. The actor
		// was resolved before the chain; a read that failed left it null, which refuses here.
		const verdict = permissionRefusal(ctx.actor, need);
		return verdict === null ? null : permissionRefused(ctx, verdict);
	},
};

const sessioncsrf: Filter = {
	before: (ctx) => {
		if (isSafe(ctx.request.method)) {
			return null;
		}
		return problem(
			ctx.path,
			ctx.config.https,
			403,
			"csrf.unavailable",
			"A form write needs the session's CSRF token, which phase 0 cannot check; the write is refused (P1).",
		);
	},
	deferred: "the CSRF token lives in the session (P1); an unsafe request is refused rather than allowed",
};

const formfields: Filter = {
	before: () => null,
	deferred:
		"a validation rule, not an access decision; the writes it would 422 are refused before it (P1/P2)",
};

const apicsrf: Filter = {
	before: (ctx) => {
		if (isSafe(ctx.request.method)) {
			return null;
		}
		return problem(
			ctx.path,
			ctx.config.https,
			403,
			"csrf.unavailable",
			"An /api write needs the session's CSRF token and the Origin check; phase 0 can check neither (P1).",
		);
	},
	deferred: "Origin + token checks need the session (P1); an unsafe /api request is refused",
};

/* ------------------------------------------------------------------------------------------------
 * The after chain
 * ---------------------------------------------------------------------------------------------- */

const securityheaders: Filter = {
	after: (_ctx, response) => {
		const headers = new Headers(response.headers);
		// A problem+json answer carries its own set (SecurityHeaders::after).
		if ((headers.get("content-type") ?? "").startsWith("application/problem+json")) {
			return response;
		}
		for (const [name, value] of Object.entries(PAGE_HEADERS)) {
			if (name === "Content-Security-Policy" && headers.has(name)) {
				continue; // a route that sets its own, stricter CSP keeps it (legacy brand_serve())
			}
			headers.set(name, value);
		}
		// EmptyBody::untype(): an answer with no body has no Content-Type (204, 304, every error).
		const bodyless = response.body === null || response.status === 204 || response.status === 304;
		if (bodyless && (response.status === 204 || response.status === 304 || response.status >= 400)) {
			headers.delete("Content-Type");
		}
		return new Response(response.body, { status: response.status, headers });
	},
};

const requestcleanup: Filter = {
	after: (_ctx, response) => response,
	deferred: "it frees an unfinished PHP upload's files; there are no uploads in phase 0 (P2)",
};

/**
 * `ConsentFilter` (plan §5 S1.2, D20). Without the consent cookie:
 * - a state-changing request is refused `403 consent_required` — problem+json under /api, the app's
 *   error page elsewhere (which is where the notice the user must answer is already printed);
 * - no session is loaded or started: `SessionRequest.load()` returns before it reads anything, and
 *   the only `Set-Cookie` values that survive are the consent cookie's own and a session's expiry
 *   (`SessionRequest.cookieLines()` applies `keepOnlyConsentCookies()`);
 * - an answer without `Cache-Control` gets `no-store`, because no session sent its own.
 *
 * POST /consent is exempt: it is how a browser without JavaScript answers the notice.
 */
const consent: Filter = {
	before: (ctx) => {
		if (ctx.session.consent || !consentNeeded(ctx.method) || ctx.path === "/consent") {
			return null;
		}
		if (isApi(ctx.path)) {
			return problem(ctx.path, ctx.config.https, CONSENT_STATUS, CONSENT_CODE, CONSENT_MESSAGE);
		}
		return html(errorPage(ctx.config.page, CONSENT_MESSAGE), CONSENT_STATUS);
	},
	after: (ctx, response) => {
		if (ctx.session.consent || response.headers.has("Cache-Control")) {
			return response;
		}
		const headers = new Headers(response.headers);
		headers.set("Cache-Control", "no-store");
		return new Response(response.body, { status: response.status, headers });
	},
};

/* ------------------------------------------------------------------------------------------------
 * The registry
 * ---------------------------------------------------------------------------------------------- */

export const FILTERS: Readonly<Record<string, Filter>> = {
	opsbackup: operatorConsole("POST /internal/backup", "an /internal path never falls through"),
	opsaccounts: operatorConsole(
		"POST /internal/accounts/{claim,reset-admins}",
		"an /internal path never falls through",
	),
	headasget,
	harness: {
		before: () => null,
		deferred: "APOTHEKE_HARNESS marks a PHPUnit request in the PHP process; a Worker has no such mark",
	},
	originalsguard,
	hostcheck,
	publicbase,
	api,
	routemethod,
	posttoolarge,
	consent,
	session,
	sameorigin,
	permission,
	sessioncsrf,
	formfields,
	apicsrf,
	securityheaders,
	requestcleanup,
};

/** Every filter that declares itself deferred, with the reason. The tests assert this list by name. */
export const DEFERRED: Readonly<Record<string, string>> = Object.fromEntries(
	Object.entries(FILTERS)
		.filter(([, filter]) => filter.deferred !== undefined)
		.map(([name, filter]) => [name, filter.deferred as string]),
);
