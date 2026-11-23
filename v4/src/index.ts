// The v4 Worker entrypoint. It stays tiny on purpose: S0.2–S0.4 need a real workerd that boots and
// answers `/healthz`, because `v4.yml` probes that route on every push. The router, the filter chain
// and the templates arrive in S0.5.
//
// `/healthz` must not touch a database backend, and must not consult readiness either: it is the
// probe the self-host healthcheck and the compose start order use (§11 rule 7), so a database still
// electing a leader — or an install whose seed was refused — must not restart-loop the app.
//
// Everything else goes through the installation's boot gate (D12): if migrations or the seed phase
// have not completed, the answer is 503 problem+json with the reason and nothing else is served.
// Handler code never talks to a driver directly; the DO owns that decision.

import type { Env } from "./env";
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

		return json({ ok: false, error: "not found" }, 404);
	},
} satisfies ExportedHandler<Env>;
