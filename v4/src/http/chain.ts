// The filter chain, in the order `app/Config/Filters.php` runs it (plan §5 S0.5).
//
// This file is a copy, deliberately: the Worker cannot read PHP, so the chain is data in code and
// `test/http/chain.spec.ts` compares every value below with `test/fixtures/php-routes.json`, which
// `scripts/gen-routes.mjs` reads out of Config/Filters.php. A typo here fails that test; a change
// there fails `npm run routes:check`.
//
// The order is CI4 4.x's with `Config\Feature::$oldFilterOrder = false` (the app's setting), which
// `CodeIgniter::handleRequest()` turns into three runs:
//
//   1. required before  — every request, routed or not, in `$required['before']` order;
//   2. globals + path   — `$globals['before']` minus each entry's `except`, then the per-path
//                         `$filters` (CI4 prepends globals to the path list);
//   3. the route's own filters — none: this app declares no per-route filters.
//
// and after the response, in this order:
//
//   4. globals after (`securityheaders`), then the path filters, then
//   5. required after (`requestcleanup`, `api`, `consent`) — required after filters run last,
//      after the controller, so `api` sets the API headers over whatever came before.
//
// `headasget` and `consent`/`formfields`' `except` lists are matched against the URI the way CI4
// does it: trimmed of slashes, lower-cased, `/` escaped, `*` → `.*`.

export interface GlobalFilter {
	readonly name: string;
	readonly except?: readonly string[];
}

export interface PathFilter {
	readonly name: string;
	readonly before: readonly string[];
}

/** `$required['before']`, in order: every request runs these, matched route or not. */
export const REQUIRED_BEFORE: readonly string[] = [
	"opsbackup",
	"opsaccounts",
	"headasget",
	"harness",
	"originalsguard",
	"hostcheck",
	"publicbase",
	"api",
	"routemethod",
	"posttoolarge",
	"consent",
	"session",
	"sameorigin",
	"permission",
];

/** `$required['after']`. They run last, after the response exists. */
export const REQUIRED_AFTER: readonly string[] = ["requestcleanup", "api", "consent"];

/** `$globals['before']`, with their `except` lists. */
export const GLOBALS_BEFORE: readonly GlobalFilter[] = [
	{ name: "sessioncsrf", except: ["login", "register", "logout", "consent", "claim", "api", "api/*"] },
	{ name: "formfields", except: ["api", "api/*"] },
];

/** `$globals['after']`. */
export const GLOBALS_AFTER: readonly string[] = ["securityheaders"];

/** `$filters`: the only per-path rule the app declares. */
export const PATH_FILTERS: readonly PathFilter[] = [{ name: "apicsrf", before: ["api", "api/*"] }];

/** CI4's URI for a request path: URL-decoded once, lower-cased, no leading or trailing slash. */
export const ci4Uri = (path: string): string => {
	let decoded = path;
	try {
		decoded = decodeURIComponent(path);
	} catch {
		decoded = path; // a malformed escape: CI4's urldecode() leaves it alone too
	}
	return decoded.replace(/^\/+|\/+$/g, "").toLowerCase();
};

/** A filter path pattern as CI4's pseudo-regex: `/` escaped, `*` → `.*`, anchored. */
const pseudoRegex = (pattern: string): RegExp => {
	const source = pattern
		.replace(/^\/+|\/+$/g, "")
		.replace(/\//g, "\\/")
		.toLowerCase()
		.replace(/\*/g, ".*");
	return new RegExp(`^${source}$`, "u");
};

/** `Filters::pathApplies()`: an empty list applies to every path. */
export const pathApplies = (uri: string, patterns: readonly string[]): boolean =>
	patterns.length === 0 || patterns.some((pattern) => pseudoRegex(pattern).test(uri));

/** The before-filters a request on this path runs, in order. */
export const beforeChain = (path: string): readonly string[] => {
	const uri = ci4Uri(path);
	return [
		...REQUIRED_BEFORE,
		...GLOBALS_BEFORE.filter((entry) => entry.except === undefined || !pathApplies(uri, entry.except)).map(
			(entry) => entry.name,
		),
		...PATH_FILTERS.filter((entry) => pathApplies(uri, entry.before)).map((entry) => entry.name),
	];
};

/**
 * The after-filters a request on this path runs, in order. The app declares no per-path `after`
 * rule (`$filters` has only `before`), and `scripts/gen-routes.mjs` fails if one appears — so this
 * list is the same for every path until the PHP side adds one.
 */
export const afterChain = (_path: string): readonly string[] => [...GLOBALS_AFTER, ...REQUIRED_AFTER];

/**
 * The after-filters a *refusal* runs. A response from a before filter returns before the controller,
 * so CI4 skips the globals (and any path) after filters — the refusal sets its own header set — but
 * `CodeIgniter::run()` still runs the required after filters on that response. That is why a refusal
 * under /api carries the API headers: `api` is one of them.
 */
export const refusalAfterChain = (): readonly string[] => [...REQUIRED_AFTER];
