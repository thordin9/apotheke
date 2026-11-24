// The v4 Worker entrypoint. It stays tiny on purpose: S0.2–S0.4 need a real workerd that boots and
// answers `/healthz`, because `v4.yml` probes that route on every push. The router and the filter
// chain arrive in `./http` (S0.5); the templates are P2.
//
// `/healthz` must not touch a database backend, and must not consult readiness either: it is the
// probe the self-host healthcheck and the compose start order use (§11 rule 7), so a database still
// electing a leader — or an install whose seed was refused — must not restart-loop the app.
//
// Everything else goes through the installation's boot gate (D12): if migrations or the seed phase
// have not completed, the answer is 503 problem+json with the reason and nothing else is served.
// Handler code never talks to a driver directly; the DO owns that decision.
//
// `/healthz` and `/readyz` are answered *before* the router on purpose. `/healthz` is the container
// probe (§11 rule 7) and must answer 200 whatever the filters would say about the Host header; and
// `/readyz` is the one answer that must exist while the installation is not ready — it is answered
// from the boot gate's own result. Every other path, the legacy `health` alias included, goes
// through the chain (S0.5), so the route table stays the single list of what exists.

import type { Env } from "./env";
import { handleRoute } from "./http/router";
import { loadConfig } from "./platform/config";
import { problem, type ReadyResult, readinessResponse } from "./readiness";

export { Installation } from "./installation";

/** Self-host and local dev are one installation per process; hosted resolves this in S0.7. */
const INSTALLATION = "default";

const json = (body: unknown, status = 200): Response =>
	new Response(JSON.stringify(body), {
		status,
		headers: { "content-type": "application/json; charset=utf-8" },
	});

/**
 * The DO's boot gate. The stub is cast to the one method the Worker uses: `Env` keeps
 * `DurableObjectNamespace` untyped so `env.ts` stays free of the object's imports.
 */
const installationReady = (env: Env): Promise<ReadyResult> => {
	const stub = env.INSTALLATION.get(env.INSTALLATION.idFromName(INSTALLATION)) as unknown as {
		ready(): Promise<ReadyResult>;
	};
	return stub.ready();
};

export default {
	async fetch(request: Request, env: Env): Promise<Response> {
		const { pathname } = new URL(request.url);

		if (pathname === "/healthz") {
			// The driver name is reported but not probed: `/healthz` says "the process is up".
			return json({ ok: true, driver: env.DB_DRIVER });
		}

		let readiness: ReadyResult;
		try {
			readiness = await installationReady(env);
		} catch (error) {
			return problem(503, "boot", error instanceof Error ? error.message : String(error));
		}

		if (pathname === "/readyz") {
			return readinessResponse(readiness, env.DB_DRIVER);
		}
		if (!readiness.ok) {
			return problem(503, readiness.code, readiness.detail);
		}

		// S0.5: the route table and the filter chain. Everything a phase-0 route can answer is either
		// a refusal, the /api 404, or 501 "not ported yet" — see src/http/router.ts.
		const config = loadConfig(env);
		return handleRoute(request, {
			publicUrl: config.publicUrl,
			https: config.publicUrl.toLowerCase().startsWith("https://"),
			hosted: config.hosted,
			maxUploadMb: config.uploads.maxMb,
		});
	},
} satisfies ExportedHandler<Env>;
