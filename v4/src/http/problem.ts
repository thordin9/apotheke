// problem+json and the security headers (plan §5 S0.5, §4 rule 3).
//
// One shape for every error the router answers, copied from the PHP app rather than invented:
// `modules/Core/Http/ApiResponse::problem()` — `type` is `about:blank`, `title` is the HTTP reason
// phrase, and `code` is the stable machine name (`auth.required`, `not_found`, …). The HTTP status
// is the source of truth; the code is what a client matches on.
//
// The two header sets are `modules/Core/Http/SecurityPolicy.php` (pages, four headers) and
// `ApiSecurityPolicy.php` (/api, HSTS only when the public URL is https) — copied verbatim, because
// §4 freezes the API contract and a hand-tuned CSP would be a silent difference from v3.
//
// `/readyz` keeps the body it has had since S0.3 (`readiness.ts`), which names the reason in `title`
// instead of a `code`: it is a v4-only probe, unlike every route below, and §5 S0.9's walkthrough and
// smoke test are written against it.

/** `ApiResponse::isApi()`: `/api` and everything under it. Takes a path with or without its slash. */
export const isApi = (path: string): boolean => {
	const trimmed = path.replace(/^\/+/, "");
	return trimmed === "api" || trimmed.startsWith("api/");
};

/** The HTTP reason phrases this router answers with. Unknown statuses still get a `title`. */
const REASONS: Readonly<Record<number, string>> = {
	400: "Bad Request",
	401: "Unauthorized",
	403: "Forbidden",
	409: "Conflict",
	404: "Not Found",
	405: "Method Not Allowed",
	413: "Payload Too Large",
	415: "Unsupported Media Type",
	419: "Authentication Timeout",
	421: "Misdirected Request",
	422: "Unprocessable Content",
	429: "Too Many Requests",
	501: "Not Implemented",
	503: "Service Unavailable",
};

/** The stable machine codes this router can answer with. One list, so a typo cannot slip in. */
export type ProblemCode =
	| "auth.required"
	| "auth.invalid_credentials"
	| "auth.pending_approval"
	| "auth.sign_in_off"
	| "bad_request"
	| "consent_required"
	| "auth.throttled"
	| "csrf.invalid"
	| "csrf.unavailable"
	| "origin.refused"
	| "permission.denied"
	| "rate_limited"
	| "unsupported_media_type"
	| "validation.failed"
	| "host.untrusted"
	| "method_not_allowed"
	| "not_found"
	| "upload.too_large"
	| "route.not_implemented";

/** `SecurityPolicy::headers()`: what every page answer carries. */
export const PAGE_HEADERS: Readonly<Record<string, string>> = {
	"X-Content-Type-Options": "nosniff",
	"X-Frame-Options": "SAMEORIGIN",
	"Referrer-Policy": "same-origin",
	"Content-Security-Policy":
		"default-src 'self'; connect-src 'self' blob:; img-src 'self' blob: data:; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'",
};

/** `ApiSecurityPolicy::headers()`: the /api set, in place of the page set. */
export const apiHeaders = (https: boolean): Record<string, string> => {
	const headers: Record<string, string> = {
		"Content-Security-Policy": "default-src 'none'; frame-ancestors 'none'; sandbox",
		"X-Content-Type-Options": "nosniff",
		"Referrer-Policy": "no-referrer",
		"Cross-Origin-Resource-Policy": "same-site",
		"Cross-Origin-Opener-Policy": "same-origin",
		"Cache-Control": "no-store",
	};
	if (https) {
		headers["Strict-Transport-Security"] = "max-age=31536000";
	}
	return headers;
};

/** The headers an error on this path carries: the API's under /api, the page's elsewhere. */
export const headersFor = (path: string, https: boolean): Record<string, string> =>
	isApi(path) ? apiHeaders(https) : { ...PAGE_HEADERS };

export interface ProblemOptions {
	/** Extra response headers a filter needs (`Allow` on a 405, `Retry-After`, …). */
	readonly extra?: Readonly<Record<string, string>>;
	/** Field => message, for a 422 (`ApiResponse::problem()`'s `$errors`), in this order. */
	readonly errors?: Readonly<Record<string, string>>;
}

/**
 * The RFC 9457 answer. `detail` is for a human reading a log; `code` is what a client matches on;
 * `title` is the reason phrase, as PHP sends it. Never cached, as the PHP side does it.
 */
export const problem = (
	path: string,
	https: boolean,
	status: number,
	code: ProblemCode,
	detail: string,
	options: ProblemOptions = {},
): Response => {
	// The key order is PHP's, because the fixture compares these answers byte for byte.
	const body: Record<string, unknown> = {
		type: "about:blank",
		title: REASONS[status] ?? "Error",
		status,
		detail,
		code,
	};
	if (options.errors !== undefined && Object.keys(options.errors).length > 0) {
		body.errors = options.errors;
	}
	return new Response(JSON.stringify(body), {
		status,
		headers: {
			...headersFor(path, https),
			"Content-Type": "application/problem+json",
			"Cache-Control": "no-store",
			...(options.extra ?? {}),
		},
	});
};

/**
 * A page answer: `text/html; charset=UTF-8`, `no-store`, and the page header set (plan §5 S1.1).
 * The single `no-store` is what `ConsentFilter::after()` adds to a response that carries none — no
 * consent cookie, no session — and a refusal answered from a before filter carries the header set
 * itself, because the globals after filters do not run for it (`RouteMethodFilter::notAllowed()`).
 */
export const html = (body: string, status = 200, extra: Readonly<Record<string, string>> = {}): Response =>
	new Response(body, {
		status,
		headers: {
			...PAGE_HEADERS,
			"Content-Type": "text/html; charset=UTF-8",
			"Cache-Control": "no-store",
			...extra,
		},
	});

/** `application/json` for a handler's own answer, with the no-store the API contract asks for. */
export const json = (body: unknown, status = 200, https = false): Response =>
	new Response(JSON.stringify(body), {
		status,
		headers: {
			...apiHeaders(https),
			"Content-Type": "application/json; charset=utf-8",
		},
	});
