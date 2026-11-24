// Matching a request against the generated table (plan §5 S0.5).
//
// The table is `routes.generated.ts` — one row per (method, path), with the permission the PHP app
// records and the filter chain it runs. This file adds only what a matcher needs: a compiled
// regular expression per path, a lookup per method, and the two answers the PHP side derives from
// the same data — the allowed methods for a 405, and whether a match is the /api catch-all.
//
// CI4 matches with `preg_match('#^' . $routeKey . '$#u', $uri)`: the route key *is* the pattern
// (placeholders are regex fragments already), so this compiles it the same way and only ASCII-case
// matters. `HEAD` is looked up as `GET`, which is what the `headasget` filter does to the request
// before routing — the table still keeps the explicit HEAD rows the PHP side declares.

import { ROUTES, type RouteRow } from "./routes.generated";

export type { RouteRow };

/** The verbs `RouteMethodFilter` reports in an `Allow` header, in its order. */
const ALLOW_VERBS: readonly string[] = ["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"];

/** A path as CI4 matches it: `/` stays `/`, everything else loses its surrounding slashes. */
export const matchPath = (path: string): string => (path === "/" ? "/" : path.replace(/^\/+|\/+$/g, ""));

const compiled = new Map<string, RegExp>();

const patternFor = (path: string): RegExp => {
	const cached = compiled.get(path);
	if (cached) {
		return cached;
	}
	const source = matchPath(path);
	const regex = new RegExp(`^${source}$`, "u");
	compiled.set(path, regex);
	return regex;
};

/** The effective method: HEAD is answered as GET (`headasget`), everything else upper-cased. */
export const effectiveMethod = (method: string): string => {
	const upper = method.toUpperCase();
	return upper === "HEAD" ? "GET" : upper;
};

/**
 * The rows a request's effective verb can match. `HEAD` rows exist in the table because the PHP app
 * declares them, but `headasget` has already turned the request into `GET`, so they are not reached
 * here — the GET row answers it, exactly as the filter intends.
 */
const byMethod = (method: string): readonly RouteRow[] => ROUTES.filter((route) => route.method === method);

/** The route the router dispatches on, or null. The /api catch-all is never it (see `catchAllRoute`). */
export const matchRoute = (method: string, path: string): RouteRow | null => {
	const uri = matchPath(path);
	const candidates = byMethod(effectiveMethod(method));
	// First match wins, as `RouteCollection::getRoutes()` order does: declared order, top to bottom.
	for (const route of candidates) {
		if (!route.catchAll && patternFor(route.path).test(uri)) {
			return route;
		}
	}
	return null;
};

/** The `/api` fallback (`ApiController::notFound`), when this path is under /api and unmatched. */
export const catchAllRoute = (method: string, path: string): RouteRow | null => {
	const uri = matchPath(path);
	for (const route of byMethod(effectiveMethod(method))) {
		if (route.catchAll && patternFor(route.path).test(uri)) {
			return route;
		}
	}
	return null;
};

/** The methods another verb would take this path with, for a 405's `Allow` — GET implies HEAD. */
export const allowedMethods = (path: string): readonly string[] => {
	const uri = matchPath(path);
	const allow: string[] = [];
	for (const verb of ALLOW_VERBS) {
		const matches = ROUTES.some(
			(route) => !route.catchAll && route.method === verb && patternFor(route.path).test(uri),
		);
		if (matches) {
			allow.push(verb);
		}
	}
	if (allow.includes("GET")) {
		allow.splice(allow.indexOf("GET") + 1, 0, "HEAD");
	}
	return allow;
};

/** Every distinct path in the table, for the tests that walk it. */
export const distinctPaths = (): readonly string[] => [...new Set(ROUTES.map((route) => route.path))];
