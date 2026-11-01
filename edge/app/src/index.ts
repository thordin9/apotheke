// getapotheke-app: the Worker in front of the static assets (frontend/dist).
//
// The static assets serve the landing page and its files (/, /privacy/, /404.html, robots.txt, sitemap.xml, icons,
// site.webmanifest, /assets/...) directly, with the headers in frontend/public/_headers: the landing CSP lives
// there only, and this Worker never sets it. Cloudflare does not apply _headers to responses the Worker makes, so
// for the paths in run_worker_first (wrangler.jsonc) this script sets the headers itself, mirroring _headers:
//  - /app          301 to /app/
//  - /app/...      the SPA: an existing file, otherwise /app/index.html (client-side routes); a missing file with an
//                  extension (for example /app/assets/x.js) stays a 404. The `/*` headers plus the `/app/*` CSP.
//  - /config.json  the SPA's runtime config, from the vars in wrangler.jsonc, never cached
//  - /fonts/...    unhashed fonts: one day, like /assets/brand/* and /assets/mascot/*
// Keep COMMON and APP_CSP in step with frontend/public/_headers (`/*` and `/app/*`).

const APP = "/app/";

// _headers `/*`, apart from its Content-Security-Policy (the landing CSP) and Cache-Control.
const COMMON: Record<string, string> = {
	"Strict-Transport-Security": "max-age=31536000",
	"X-Content-Type-Options": "nosniff",
	"Referrer-Policy": "strict-origin-when-cross-origin",
	"X-Frame-Options": "DENY",
	"Permissions-Policy":
		"camera=(), microphone=(), geolocation=(), interest-cohort=(), accelerometer=(), gyroscope=(), magnetometer=(), " +
		"payment=(), usb=(), bluetooth=(), serial=(), hid=(), midi=(), display-capture=(), browsing-topics=()",
};

// _headers `/app/*` (which detaches the landing CSP with `! Content-Security-Policy`).
const APP_CSP =
	"default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' blob: data: https://api.services.getapotheke.com; " +
	"connect-src 'self' https://api.services.getapotheke.com https://analytics.services.webapp.gr; font-src 'self'; manifest-src 'self'; worker-src 'self'; " +
	"base-uri 'none'; form-action 'none'; frame-ancestors 'none'; object-src 'none'";

// For what the Worker serves outside /app/ (config.json, fonts, errors): nothing to run, embed or frame.
const DATA_CSP = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

const DAY = "public, max-age=86400";

function finish(path: string, res: Response): Response {
	const out = new Response(res.body, res);
	for (const [k, v] of Object.entries(COMMON)) out.headers.set(k, v);
	const app = path === "/app" || path.startsWith(APP);
	out.headers.set("Content-Security-Policy", app ? APP_CSP : DATA_CSP);
	// A 304 carries the Cache-Control its 200 would have (RFC 9110 15.4.5), so a revalidated font keeps its day
	// (N-1435). The SPA's hashed files are under /assets/ (served from the assets with _headers, not this Worker).
	let cc = "no-cache";
	if ((res.status === 200 || res.status === 304) && path.startsWith("/fonts/")) cc = DAY;
	else if (res.status >= 400 && res.status !== 404) cc = "no-store";
	out.headers.set("Cache-Control", cc);
	return out;
}

export default {
	async fetch(request, env): Promise<Response> {
		// Every response this Worker makes, errors included, goes through finish() and so carries the headers.
		try {
			return await route(request, env);
		} catch {
			const path = new URL(request.url).pathname;
			return finish(path, new Response("Internal Server Error\n", { status: 500 }));
		}
	},
} satisfies ExportedHandler<Env>;

async function route(request: Request, env: Env): Promise<Response> {
	const url = new URL(request.url);
	const path = url.pathname;
	if (request.method !== "GET" && request.method !== "HEAD") {
		return finish(path, new Response("Method Not Allowed\n", { status: 405, headers: { Allow: "GET, HEAD" } }));
	}
	if (path === "/config.json") {
		const body = JSON.stringify({ apiBase: env.API_BASE });
		return finish(
			path,
			new Response(request.method === "HEAD" ? null : body, {
				headers: { "Content-Type": "application/json; charset=utf-8" },
			}),
		);
	}
	if (path === "/app") {
		return finish(path, new Response(null, { status: 301, headers: { Location: APP + url.search } }));
	}
	let res = await env.ASSETS.fetch(request);
	if (res.status === 404 && path.startsWith(APP) && !/\.[A-Za-z0-9]+$/.test(path)) {
		res = await env.ASSETS.fetch(new Request(new URL(APP, url), request));
		if (res.status === 200) res = new Response(res.body, { status: 200, headers: res.headers });
	}
	return finish(path, res);
}
