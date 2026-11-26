// The v4 Worker entrypoint. It stays tiny on purpose: S0.2–S0.4 need a real workerd that boots and
// answers `/healthz`, because `v4.yml` probes that route on every push. The router and the filter
// chain live in `./http` (S0.5); the page shell and `/login` in `./html` (S1.1). Every other page
// is P2, and every page route without a handler still answers 501 rather than a pretend 200.
//
// `/healthz` must not touch a database backend, and must not consult readiness either: it is the
// probe the self-host healthcheck and the compose start order use (§11 rule 7), so a database still
// electing a leader — or an install whose seed was refused — must not restart-loop the app.
//
// Everything else goes through the installation's boot gate (D12): if migrations or the seed phase
// have not completed, the answer is a 503 and nothing else is served — the error page on a page path,
// RFC 9457 problem+json under /api, with one fixed sentence and never the exception's text (D151).
// The reason goes to the log; `src/http/server-error.ts` says which page each site may draw (D157).
// Handler code never talks to a driver directly; the DO owns that decision.
//
// `/healthz` and `/readyz` are answered *before* the router on purpose. `/healthz` is the container
// probe (§11 rule 7) and must answer 200 whatever the filters would say about the Host header; and
// `/readyz` is the one answer that must exist while the installation is not ready — it is answered
// from the boot gate's own result. Every other path, the legacy `health` alias included, goes
// through the chain (S0.5), so the route table stays the single list of what exists.

import type { InstallationStub } from "./db/do-sql";
import { requestSql } from "./db/request-sql";
import type { Env } from "./env";
import { serveAsset } from "./http/assets";
import { HEALTHZ_PATH, READYZ_PATH } from "./http/probe-paths";
import { handleRoute } from "./http/router";
import { logServerError, serverError } from "./http/server-error";
import { loadConfig } from "./platform/config";
import { type ReadyResult, readinessResponse } from "./readiness";
import { secureCookie } from "./session/cookie";
import { openSession } from "./session/request";
import { consentGiven, readPageData } from "./settings";

export { Installation } from "./installation";

/** Self-host and local dev are one installation per process; hosted resolves this in S0.7. */
const INSTALLATION = "default";

const json = (body: unknown, status = 200): Response =>
	new Response(JSON.stringify(body), {
		status,
		headers: { "content-type": "application/json; charset=utf-8" },
	});

/**
 * The DO's boot gate. The stub is cast to the methods the Worker uses: `Env` keeps
 * `DurableObjectNamespace` untyped so `env.ts` stays free of the object's imports.
 */
interface InstallationRpc {
	ready(): Promise<ReadyResult>;
}

/** The stub answers `ready()` always, and `query()`/`batch()` under `DB_DRIVER=sqlite` (D12). */
type InstallationHandle = InstallationRpc & InstallationStub;

const installationStub = (env: Env): InstallationHandle =>
	env.INSTALLATION.get(env.INSTALLATION.idFromName(INSTALLATION)) as unknown as InstallationHandle;

/**
 * `PlainHandler::problem()`'s HSTS input: a direct HTTPS connection counts, and so does a `PUBLIC_URL`
 * that is https. The three sites that answer before `loadConfig()` runs have no config to ask, so they
 * pass the request alone rather than a guess.
 */
const httpsOf = (url: URL, publicUrl = ""): boolean =>
	url.protocol === "https:" || publicUrl.toLowerCase().startsWith("https://");

export default {
	async fetch(request: Request, env: Env): Promise<Response> {
		const url = new URL(request.url);
		const { pathname } = url;

		if (pathname === HEALTHZ_PATH) {
			// The driver name is reported but not probed: `/healthz` says "the process is up".
			return json({ ok: true, driver: env.DB_DRIVER });
		}

		// `/assets/…` is static bytes: answered before the readiness gate and the chain, as the web
		// server answers it in v3, so a page still styles itself while the database is coming up.
		const asset = serveAsset(pathname, request.method);
		if (asset !== null) {
			return asset;
		}

		const stub = installationStub(env);
		let readiness: ReadyResult;
		try {
			readiness = await stub.ready();
		} catch (error) {
			// v3's `BootError`: one public sentence, the detail only in the error log (D151).
			logServerError("boot", error);
			return serverError(pathname, httpsOf(url));
		}

		if (pathname === READYZ_PATH) {
			return readinessResponse(readiness, env.DB_DRIVER);
		}
		if (!readiness.ok) {
			// Not an exception, so there is no class to name: the reason code is what the log keeps.
			console.error(`[apotheke] 5xx readiness: ${readiness.code}`);
			return serverError(pathname, httpsOf(url));
		}

		// S0.5: the route table and the filter chain. S1.1 adds one live handler (`/login`) and the
		// app's error pages on page paths; every other route is still a refusal, the /api 404, or
		// 501 "not ported yet" — see src/http/router.ts.
		const config = loadConfig(env);
		// One settings read per routed request: it is what `/login` and the error pages render from,
		// and it is the same row the PHP app reads per key. An API-only answer does not need it, but
		// no API route exists until P2, and a second code path here would be one more thing that can
		// differ; P2 can skip the read for `/api` when there is an API to skip for.
		let data: Awaited<ReturnType<typeof readPageData>>;
		try {
			const requestUri = `${pathname}${url.search}`;
			// One cookie header, read twice: consent decides whether a session starts, the theme what the
			// shell renders before first paint (`Theme::pref()`, D152).
			const cookies = request.headers.get("cookie");
			data = await readPageData(
				requestSql(env, stub),
				config,
				requestUri,
				consentGiven(cookies, config.consentRequired),
				cookies,
			);
		} catch (error) {
			// The settings row is as necessary as the schema: no page can be drawn without it, so this
			// site has no shell to draw and answers the plain page (D157).
			logServerError("settings", error);
			return serverError(pathname, httpsOf(url));
		}

		const sql = requestSql(env, stub);
		const now = Math.floor(Date.now() / 1000);
		// `RequestScheme::secureCookie()`: the inputs are the `trust_proxy` and `public_url` settings
		// rows, read above, plus what the request itself says. This is the bit that picks between
		// `apothekesid` and `__Host-apothekesid` — a blind `__Host-` is dropped by the browser without
		// a word on a self-hosted `http://192.168.x.x`, which is not a secure context.
		const secure = secureCookie({
			url: new URL(request.url),
			host: request.headers.get("host") ?? "",
			forwardedProto: request.headers.get("x-forwarded-proto") ?? "",
			trustedProxy: data.security.trustProxy,
			publicUrl: data.security.publicUrl,
		});
		// The session is loaded before the chain: a filter cannot await, and the load is a round trip.
		// `load()` is also where consent decides that nothing is loaded or started (D20).
		const session = await openSession({
			sql,
			request,
			now,
			secure,
			limits: {
				lifetimeSeconds: data.security.sessionDays * 86_400,
				idleSeconds: data.security.sessionIdleHours * 3_600,
			},
		});

		let response: Response;
		try {
			response = await handleRoute(
				request,
				{
					publicUrl: config.publicUrl,
					https: config.publicUrl.toLowerCase().startsWith("https://"),
					hosted: config.hosted,
					maxUploadMb: config.uploads.maxMb,
					page: data.page,
					login: { registrationOpen: data.registrationOpen, guestBrowsing: data.guestBrowsing },
					signInEnabled: data.signInEnabled,
				},
				session,
			);
			// The writes are flushed before the answer leaves: a sign-in whose row did not store must
			// not answer 303, which is why a failure here becomes a 503 rather than a lost session.
			await session.flush();
		} catch (error) {
			// The one site with the settings row in hand, so the one that can draw the app's own page.
			logServerError("handler", error);
			return serverError(pathname, httpsOf(url, config.publicUrl), data.page);
		}

		const lines = session.cookieLines();
		if (lines.length === 0) {
			return response;
		}
		const headers = new Headers(response.headers);
		for (const line of lines) {
			headers.append("set-cookie", line);
		}
		return new Response(response.body, { status: response.status, headers });
	},
} satisfies ExportedHandler<Env>;
