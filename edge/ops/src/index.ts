// apotheke-ops: the operator console (ops/console, Workers static assets) and its API /api/ops/v1, on its own host
// behind Cloudflare Access (docs/operator-console.md#console-api). Every request runs this script first:
//  1. ACCESS_TEAM_DOMAIN or ACCESS_AUD missing: 503 not_configured for everything.
//  2. OPTIONS: 405. There are no CORS headers on any answer; the console calls same-origin.
//  3. Access: a valid Cf-Access-Jwt-Assertion for this application (access.ts), else 401 (403 without an email claim).
//     The operator is the token's email.
//  4. A write (POST, PUT, PATCH, DELETE) needs `X-CSRF: 1` and an Origin equal to this request's origin, else 403 csrf.
//  5. /api/ops/v1/... goes to api.ts, any other /api/ path is 404, everything else is the console's files.
// Every answer is Cache-Control: no-store and carries X-Request-Id, which is also the audit row's request_id.
import { accessConfig, verifyAccess, type Fetch } from "./access";
import { API_PREFIX, failure, handleApi } from "./api";

const WRITES = new Set(["POST", "PUT", "PATCH", "DELETE"]);

/** For the console's files and every API answer: same-origin scripts, styles, fonts and calls only; never framed. */
const CSP =
	"default-src 'none'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data:; connect-src 'self'; " +
	"base-uri 'none'; form-action 'none'; frame-ancestors 'none'; object-src 'none'";

const HEADERS: Record<string, string> = {
	"Cache-Control": "no-store",
	"Content-Security-Policy": CSP,
	"X-Content-Type-Options": "nosniff",
	"X-Frame-Options": "DENY",
	"Referrer-Policy": "no-referrer",
	"Strict-Transport-Security": "max-age=31536000",
};

export type Deps = { fetch: Fetch; now: () => number };
const DEFAULT_DEPS: Deps = { fetch: (input, init) => fetch(input, init), now: () => Date.now() };

function finish(res: Response, requestId: string): Response {
	const out = new Response(res.body, res);
	for (const name of [...out.headers.keys()]) if (name.toLowerCase().startsWith("access-control-")) out.headers.delete(name);
	for (const [k, v] of Object.entries(HEADERS)) out.headers.set(k, v);
	out.headers.set("X-Request-Id", requestId);
	return out;
}

/** A write passes only with `X-CSRF: 1` and an Origin equal to this request's own origin. */
export function csrfOk(request: Request): boolean {
	const origin = request.headers.get("Origin");
	return request.headers.get("X-CSRF") === "1" && origin !== null && origin === new URL(request.url).origin;
}

async function route(request: Request, env: Env, deps: Deps, requestId: string): Promise<Response> {
	const method = request.method.toUpperCase();
	const config = accessConfig(env);
	if (!config) return failure(503, "not_configured");
	if (method === "OPTIONS") return failure(405, "method_not_allowed");
	const access = await verifyAccess(request, config, deps.fetch, deps.now());
	if (!access.ok) return failure(access.code === "access_unavailable" ? 503 : access.code === "forbidden" ? 403 : 401, access.code);
	if (WRITES.has(method) && !csrfOk(request)) return failure(403, "csrf");
	const path = new URL(request.url).pathname;
	if (path === API_PREFIX || path.startsWith(API_PREFIX + "/")) return handleApi(request, env, { email: access.email, requestId });
	if (path === "/api" || path.startsWith("/api/")) return failure(404, "not_found");
	if (method !== "GET" && method !== "HEAD") return failure(405, "method_not_allowed");
	if (!env.ASSETS) return failure(404, "not_found");
	return env.ASSETS.fetch(request);
}

/** The Worker's fetch with injectable dependencies (the tests' JWKS and clock). */
export async function handle(request: Request, env: Env, deps: Deps = DEFAULT_DEPS): Promise<Response> {
	const requestId = crypto.randomUUID();
	try {
		return finish(await route(request, env, deps, requestId), requestId);
	} catch (e) {
		console.error("ops request failed", requestId, e instanceof Error ? e.name : "error");
		return finish(failure(503, "unavailable"), requestId);
	}
}

export default {
	fetch: (request, env) => handle(request, env),
} satisfies ExportedHandler<Env>;
