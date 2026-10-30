// getapotheke-app: routing, /config.json and headers in front of the static assets (frontend/dist).
//  - /            the landing page and its static pages (no SPA fallback; a missing page is a 404, with
//                 dist/404.html as its body when the build has one)
//  - /app         301 to /app/
//  - /app/...     the SPA: an existing file, otherwise /app/index.html (client-side routes); a missing file with an
//                 extension (for example /app/assets/x.js) stays a 404
//  - /config.json the SPA's runtime config, from the vars below, never cached
// Every response gets the security headers (HSTS included); the SPA and the landing page get their own CSP.

const APP = "/app/";

const COMMON: Record<string, string> = {
	"Strict-Transport-Security": "max-age=31536000",
	"X-Content-Type-Options": "nosniff",
	"X-Frame-Options": "DENY",
	"Referrer-Policy": "same-origin",
	"Cross-Origin-Opener-Policy": "same-origin",
	"Permissions-Policy": "geolocation=(), microphone=(), payment=(), usb=()",
};

// The landing page runs no script: default-src 'self' and no script-src.
const LANDING_CSP =
	"default-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; " +
	"object-src 'none'; upgrade-insecure-requests";

function spaCsp(api: string): string {
	return (
		`default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' blob: data: ${api}; ` +
		`connect-src 'self' ${api}; font-src 'self'; manifest-src 'self'; worker-src 'self'; base-uri 'none'; ` +
		"form-action 'none'; frame-ancestors 'none'; object-src 'none'; upgrade-insecure-requests"
	);
}

function cacheControl(path: string, res: Response): string | null {
	if (path.startsWith("/assets/brand/") || path.startsWith("/assets/mascot/")) return "public, max-age=86400";
	if (path.startsWith("/assets/") || path.startsWith(APP + "assets/")) {
		return res.status === 200 ? "public, max-age=31536000, immutable" : "no-store";
	}
	if ((res.headers.get("Content-Type") ?? "").startsWith("text/html")) return "no-cache";
	return null;
}

function finish(path: string, res: Response, env: Env): Response {
	const out = new Response(res.body, res);
	for (const [k, v] of Object.entries(COMMON)) out.headers.set(k, v);
	const spa = path === "/app" || path.startsWith(APP);
	out.headers.set("Content-Security-Policy", spa ? spaCsp(env.API_ORIGIN) : LANDING_CSP);
	const cc = cacheControl(path, out);
	if (cc) out.headers.set("Cache-Control", cc);
	return out;
}

export default {
	async fetch(request, env): Promise<Response> {
		const url = new URL(request.url);
		const path = url.pathname;
		if (request.method !== "GET" && request.method !== "HEAD") {
			return finish(path, new Response("Method Not Allowed\n", { status: 405, headers: { Allow: "GET, HEAD" } }), env);
		}
		if (path === "/config.json") {
			const body = JSON.stringify({ apiBase: env.API_BASE });
			const res = new Response(request.method === "HEAD" ? null : body, {
				headers: { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-cache" },
			});
			return finish(path, res, env);
		}
		if (path === "/app") {
			return finish(path, new Response(null, { status: 301, headers: { Location: APP + url.search } }), env);
		}
		let res = await env.ASSETS.fetch(request);
		if (res.status === 404 && path.startsWith(APP) && !/\.[A-Za-z0-9]+$/.test(path)) {
			res = await env.ASSETS.fetch(new Request(new URL(APP, url), request));
			if (res.status === 200) res = new Response(res.body, { status: 200, headers: res.headers });
		}
		return finish(path, res, env);
	},
} satisfies ExportedHandler<Env>;
