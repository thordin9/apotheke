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

import { afterChain, beforeChain, refusalAfterChain } from "./chain";
import { type Ctx, DEFERRED, FILTERS } from "./filters";
import { problem } from "./problem";
import { allowedMethods, catchAllRoute, effectiveMethod, matchRoute } from "./route-table";

/** What the router needs from the app's configuration (§11). */
export interface RouterConfig {
	/** `PUBLIC_URL`, trailing slash removed; empty when unset. */
	readonly publicUrl: string;
	/** The API's HSTS decision: whether PUBLIC_URL is https. */
	readonly https: boolean;
	readonly hosted: boolean;
	readonly maxUploadMb: number;
}

type Handler = (ctx: Ctx) => Response;

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

const HANDLERS: ReadonlyMap<string, Handler> = new Map([
	["\\Apotheke\\Core\\Controllers\\ApiController::notFound", apiNotFound],
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
export const handleRoute = (request: Request, config: RouterConfig): Response =>
	routeRequest(request, config).response;

/** `handleRoute`, plus what the order test needs to see: the chain that actually ran. */
export const routeRequest = (request: Request, config: RouterConfig): RoutedResponse => {
	const url = new URL(request.url);
	const path = url.pathname === "" ? "/" : url.pathname;
	const method = effectiveMethod(request.method);
	const route = matchRoute(method, path);
	const catchAll = route === null ? catchAllRoute(method, path) : null;
	const ctx: Ctx = createContext(request, config);

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
	const response = runAfter(ctx, handler(ctx), afterChain(path));
	return { response, trace: ctx.trace, deferred: [...ctx.deferred].sort() };
};

/** The context a filter sees, for a test that exercises one filter on its own. */
export const createContext = (request: Request, config: RouterConfig): Ctx => {
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
		identity: null,
		trace: [],
		deferred: new Set<string>(),
	};
};

/** The filters that declared themselves deferred, by name — for a test or a diagnostic. */
export const deferredFilters = (): readonly string[] => Object.keys(DEFERRED).sort();
