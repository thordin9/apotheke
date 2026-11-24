// The router (plan §5 S0.5): match, run the filters, dispatch, run the after filters.
//
// It is deliberately thin. The decisions live in the filters and the matching lives in
// `route-table.ts`; this file only knows the order CI4 applies them in and what a refusal means:
//
//   1. resolve the route from the generated table (HEAD already looked up as GET);
//   2. run the before chain — a refusal returns at once, skipping the handler and the globals after
//      filters, but still getting the *required* after filters (CodeIgniter::run does that too);
//   3. dispatch the matched route's handler;
//   4. run the after chain (globals, then required).
//
// Phase 0 has two handlers: the `/api` fallback (`ApiController::notFound`, which is a frozen
// interface — §4 rule 3 freezes the 404 problem+json under /api) and "not ported yet", which answers
// 501 problem+json. Every other controller arrives in P1/P2; until then a request that survives the
// chain — a route whose permission is `none` or `public`, on a safe method — gets 501 and nothing
// else. That is what makes the fail-closed promise checkable: no route in the table answers 2xx.

import { resolveActor } from "../auth/permission";
import { roleHandler, securityPageHandler, userHandler } from "../auth/security-routes";
import { claimHandler, consentHandler, logoutHandler, signInHandler } from "../auth/session-routes";
import type { PageSettings } from "../html/page";
import { SessionRequest } from "../session/request";
import { afterChain, beforeChain, refusalAfterChain } from "./chain";
import { type Ctx, DEFERRED, FILTERS } from "./filters";
import { emptyFormPost, formFields } from "./form";
import { problem } from "./problem";
import { allowedMethods, catchAllRoute, effectiveMethod, matchRoute } from "./route-table";

/** What the router needs from the app's configuration (§11) and its settings row (§5 S1.1). */
export interface RouterConfig {
	/** `PUBLIC_URL`, trailing slash removed; empty when unset. */
	readonly publicUrl: string;
	/** The API's HSTS decision: whether PUBLIC_URL is https. */
	readonly https: boolean;
	readonly hosted: boolean;
	readonly maxUploadMb: number;
	/** The shell's values, read once per request (`src/settings.ts`). */
	readonly page: PageSettings;
	/** The two decisions `/login` prints: `registrationOpen` and `guestBrowsing`. */
	readonly login: {
		readonly registrationOpen: boolean;
		readonly guestBrowsing: boolean;
	};
	/** `SettingsService::signInEnabled()`: `/login` redirects rather than renders when it is off. */
	readonly signInEnabled: boolean;
	/**
	 * `SignInPace::deadlineMs`: how long a failed sign-in is padded to. v3's 1000 ms; a test sets 0,
	 * and the default is v3's, so production keeps the timing it had.
	 */
	readonly signInDeadlineMs?: number;
}

/** A handler may be async: the ones that touch the database are (S1.2). */
type Handler = (ctx: Ctx) => Response | Promise<Response>;

/** `ApiController::notFound()`: the frozen /api 404 (§4 rule 3). */
const apiNotFound: Handler = (ctx) =>
	problem(ctx.path, ctx.config.https, 404, "not_found", "No such API endpoint.");

/** The one honest answer for a route whose controller has not been ported: 501, never a pretend 200. */
const notPorted: Handler = (ctx) => {
	const handler = ctx.route?.handler ?? ctx.catchAll?.handler ?? "";
	return problem(
		ctx.path,
		ctx.config.https,
		501,
		"route.not_implemented",
		`${handler} is not ported yet: S0.5 is the routing skeleton, the controllers are P1/P2.`,
	);
};

/** `Permissions::NONE` and the API's `public`: a route that answers before a permission check. */
const NO_PERMISSION_NEEDS: readonly string[] = ["none", "public"];

const HANDLERS: ReadonlyMap<string, Handler> = new Map([
	["\\Apotheke\\Core\\Controllers\\ApiController::notFound", apiNotFound],
	["\\Apotheke\\Core\\Controllers\\ConsentController::accept", consentHandler],
	["\\Apotheke\\Auth\\Controllers\\SignInController::login", signInHandler],
	["\\Apotheke\\Auth\\Controllers\\SignInController::logout", logoutHandler],
	["\\Apotheke\\Auth\\Controllers\\ClaimController::claim", claimHandler],
	["\\Apotheke\\Auth\\Controllers\\SecurityController::index", securityPageHandler],
	["\\Apotheke\\Auth\\Controllers\\SecurityController::user", userHandler],
	["\\Apotheke\\Auth\\Controllers\\SecurityController::user/$1", userHandler],
	["\\Apotheke\\Auth\\Controllers\\SecurityController::user/$1/$2", userHandler],
	["\\Apotheke\\Auth\\Controllers\\SecurityController::role", roleHandler],
	["\\Apotheke\\Auth\\Controllers\\SecurityController::role/$1", roleHandler],
	["\\Apotheke\\Auth\\Controllers\\SecurityController::role/$1/delete", roleHandler],
]);

const runAfter = (ctx: Ctx, response: Response, names: readonly string[]): Response => {
	let out = response;
	for (const name of names) {
		const filter = FILTERS[name];
		if (filter === undefined) {
			throw new Error(`unknown after filter: ${name}`);
		}
		ctx.trace.push(`after:${name}`);
		out = filter.after?.(ctx, out) ?? out;
	}
	return out;
};

/** Everything one request produced: the answer, the filters that ran, and the ones that deferred. */
export interface RoutedResponse {
	readonly response: Response;
	/** Filter names in the order they ran; after filters are prefixed `after:`. */
	readonly trace: readonly string[];
	/** Filters that declared their PHP behaviour deferred on this request. */
	readonly deferred: readonly string[];
}

/**
 * Answers a request that is past the installation's readiness gate. `/healthz` and `/readyz` are
 * answered before this, in `index.ts`, and keep the semantics they have had since S0.3 (D14).
 */
export const handleRoute = async (
	request: Request,
	config: RouterConfig,
	session: SessionRequest,
): Promise<Response> => (await routeRequest(request, config, session)).response;

/** `handleRoute`, plus what the order test needs to see: the chain that actually ran. */
export const routeRequest = async (
	request: Request,
	config: RouterConfig,
	session?: SessionRequest,
): Promise<RoutedResponse> => {
	const url = new URL(request.url);
	const path = url.pathname === "" ? "/" : url.pathname;
	const method = effectiveMethod(request.method);
	const route = matchRoute(method, path);
	const catchAll = route === null ? catchAllRoute(method, path) : null;
	const ctx: Ctx = createContext(request, config, session);
	// The permission filter cannot await, so the actor it judges is resolved here, once — the same
	// place `index.ts` loads the session. A read that fails leaves `actor` null and the filter
	// refuses, which is the fail-closed answer for a check that could not be made.
	const need = route?.permission ?? null;
	if (need !== null && !NO_PERMISSION_NEEDS.includes(need)) {
		try {
			ctx.actor = await resolveActor(ctx.session.sql, ctx.session.identity, {
				signInEnabled: config.signInEnabled,
				guestBrowsing: config.login.guestBrowsing,
			});
		} catch {
			ctx.actor = null;
		}
	}

	// `sessioncsrf` is a decision a filter must take, and a filter cannot await: the body is parsed
	// here, once, only for the unsafe requests whose chain checks the token.
	if (ctx.post.size === 0 && method !== "GET" && method !== "HEAD" && method !== "OPTIONS") {
		if (beforeChain(path).includes("sessioncsrf")) {
			ctx.post = await formFields(request.clone());
		}
	}

	for (const name of beforeChain(path)) {
		const filter = FILTERS[name];
		if (filter === undefined) {
			throw new Error(`unknown before filter: ${name}`);
		}
		ctx.trace.push(name);
		if (filter.deferred !== undefined) {
			ctx.deferred.add(name);
		}
		const refusal = filter.before?.(ctx) ?? null;
		if (refusal !== null) {
			return {
				response: runAfter(ctx, refusal, refusalAfterChain()),
				trace: ctx.trace,
				deferred: [...ctx.deferred].sort(),
			};
		}
	}

	const handler = HANDLERS.get(route?.handler ?? catchAll?.handler ?? "") ?? notPorted;
	const response = runAfter(ctx, await handler(ctx), afterChain(path));
	return { response, trace: ctx.trace, deferred: [...ctx.deferred].sort() };
};

/** The context a filter sees, for a test that exercises one filter on its own. */
export const createContext = (request: Request, config: RouterConfig, session?: SessionRequest): Ctx => {
	const carried = session ?? SessionRequest.detached(request, config.https);
	const url = new URL(request.url);
	const path = url.pathname === "" ? "/" : url.pathname;
	const method = effectiveMethod(request.method);
	const route = matchRoute(method, path);
	return {
		request,
		url,
		rawMethod: request.method.toUpperCase(),
		method,
		path,
		route,
		catchAll: route === null ? catchAllRoute(method, path) : null,
		allow: route === null ? allowedMethods(path) : [],
		config,
		baseUrl: "",
		session: carried,
		identity: carried.identity,
		actor: null,
		post: emptyFormPost(),
		trace: [],
		deferred: new Set<string>(),
	};
};

/** The filters that declared themselves deferred, by name — for a test or a diagnostic. */
export const deferredFilters = (): readonly string[] => Object.keys(DEFERRED).sort();
