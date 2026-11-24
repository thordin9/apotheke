// `/assets/…`: the bundle, served the way the shipped web server serves it (plan §5 S1.1).
//
// In v3 these files never reach the app — nginx's `location ^~ /assets/` answers them, with
// `Cache-Control: public, max-age=31536000, immutable` for a hit, `no-store` for a miss, and
// `X-Content-Type-Options: nosniff` on both (docker/nginx.conf, and the same in the FrankenPHP
// variant). A Worker has no such layer, so this file is that location block: the bytes come from
// `bundle.generated.ts`, the headers are copied, and every other `/assets/…` spelling — a
// subdirectory, a traversal, a name that is not in the bundle — is the same uncacheable 404.
//
// It runs before the router and before the readiness gate, as it does in v3: static bytes need no
// database, and a container whose database is still electing a leader can still style its own error
// page.

import { asset, assetBytes } from "../assets/bundle";

const ASSET_PREFIX = "/assets/";

/** Headers nginx's asset location sets, on a hit and on a miss alike. */
const NOSNIFF = { "X-Content-Type-Options": "nosniff" };

/**
 * The answer for an `/assets/…` path, or null when the path is something else (the router's).
 * `method` is the request's: HEAD gets the same headers and no body, which is what a web server does.
 */
export const serveAsset = (path: string, method: string): Response | null => {
	if (!path.startsWith(ASSET_PREFIX)) {
		return null;
	}
	const name = path.slice(ASSET_PREFIX.length);
	// Only a bundle name is servable: no `/`, no `.`, no percent-decoding games.
	const hit = /^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(name) ? asset(name) : undefined;
	if (hit === undefined) {
		// The FrankenPHP image's own 404 for a missing asset: a plain-text status line.
		return new Response(method === "HEAD" ? null : "404 Not Found\n", {
			status: 404,
			headers: {
				...NOSNIFF,
				"Content-Type": "text/plain; charset=utf-8",
				"Cache-Control": "no-store",
			},
		});
	}
	const body = method === "HEAD" ? null : assetBytes(name);
	return new Response(body, {
		status: 200,
		headers: {
			...NOSNIFF,
			"Content-Type": hit.contentType,
			"Content-Length": String(hit.size),
			// nginx's `$apotheke_asset_cache` map: a 200 is immutable for a year.
			"Cache-Control": "public, max-age=31536000, immutable",
		},
	});
};
