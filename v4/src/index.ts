// The v4 Worker entrypoint. It stays tiny on purpose: S0.2–S0.4 need a real workerd that boots and
// answers `/healthz`, because `v4.yml` probes that route on every push. The router, the filter chain
// and the readiness route arrive in S0.3 and S0.5.
//
// `/healthz` must not touch a database backend: it is the probe the self-host healthcheck and the
// compose start order use (§11), so a database still electing a leader must not restart-loop the app.
// Backend reachability belongs on `/readyz`, which is where `/healthz` sends nobody today because
// that route does not exist yet.

import type { Env } from "./env";

export { Installation } from "./installation";

const json = (body: unknown, status = 200): Response =>
	new Response(JSON.stringify(body), {
		status,
		headers: { "content-type": "application/json; charset=utf-8" },
	});

export default {
	fetch(request: Request, env: Env): Response {
		const { pathname } = new URL(request.url);

		if (pathname === "/healthz") {
			// The driver name is reported but not probed: `/healthz` says "the process is up".
			return json({ ok: true, driver: env.DB_DRIVER });
		}

		return json({ ok: false, error: "not found" }, 404);
	},
} satisfies ExportedHandler<Env>;
