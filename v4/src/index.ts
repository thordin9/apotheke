// The v4 Worker entrypoint. Deliberately tiny: S0.2 only needs a real workerd that boots and answers
// `/healthz`, because `v4.yml` returns early on `v4/package.json` + `v4/package-lock.json` and then
// probes this route. The router, the filter chain and the `Sql` adapters arrive in S0.3 and S0.5.
//
// `/healthz` must not touch a database backend: it is the probe the self-host healthcheck and the
// compose start order use (§11), so a database that is still electing a leader must not restart-loop
// the app. Backend reachability gets its own `/readyz`, which is what the compose healthcheck calls.

export interface Env {
	readonly DB: D1Database;
	readonly DB_DRIVER: string;
	readonly RQLITE_URL: string;
}

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
