// Apotheke edge (plan P4 i): the Worker `apotheke-api` forwards every request to one Durable Object, which starts
// and reuses the Apotheke container (Dockerfile.frankenphp) through the Durable Object Container API.
// D1 and R2 bindings and their outbound handlers come with the storage adapters (plan P4 c, f).
import { DurableObject } from "cloudflare:workers";

const PORT = 9091;
const READY_TIMEOUT_MS = 30_000;

export class ApothekeContainer extends DurableObject<Env> {
	private sleepAfter(): number {
		return Number(this.env.SLEEP_AFTER_MS) || 15 * 60 * 1000;
	}

	constructor(ctx: DurableObjectState, env: Env) {
		super(ctx, env);
		const c = ctx.container;
		// A restarted Durable Object starts without a timeout: set it again when the container is already up.
		if (c?.running) void ctx.blockConcurrencyWhile(() => c.setInactivityTimeout(this.sleepAfter()));
	}

	/** Starts the container if needed and waits for /healthz. Returns the cold-start time in ms (0 when warm). */
	private async ensureReady(): Promise<number> {
		const c = this.ctx.container;
		if (!c) throw new Error("no container configured");
		if (c.running) return 0;
		const t0 = Date.now();
		c.start({
			enableInternet: false,
			env: {
				// The entrypoint refuses to start without a valid APP_PASSWORD (exit 78): the Worker answers 503.
				APP_PASSWORD: this.env.APP_PASSWORD ?? "",
				APP_KEY: this.env.APP_KEY ?? "",
				ALLOWED_HOSTS: this.env.ALLOWED_HOSTS ?? "",
				COOKIE_DOMAIN: this.env.COOKIE_DOMAIN ?? "",
				CORS_ALLOWED_ORIGINS: this.env.CORS_ALLOWED_ORIGINS ?? "",
				APOTHEKE_JOBS_WORKER: this.env.APOTHEKE_JOBS_WORKER ?? "0",
				PORT: String(PORT),
				TZ: "Europe/Athens",
			},
		});
		await c.setInactivityTimeout(this.sleepAfter());
		this.ctx.waitUntil(
			c.monitor().then(
				() => console.log("container exited"),
				(e: { exitCode?: number }) => console.error("container stopped", e?.exitCode ?? "error"),
			),
		);
		const port = c.getTcpPort(PORT);
		while (Date.now() - t0 < READY_TIMEOUT_MS) {
			try {
				const r = await port.fetch("http://container/healthz");
				if (r.status === 200) return Date.now() - t0;
			} catch {
				// not listening yet
			}
			if (!c.running) throw new Error("container stopped during start");
			await new Promise((r) => setTimeout(r, 100));
		}
		throw new Error("container not ready");
	}

	async fetch(request: Request): Promise<Response> {
		let cold: number;
		try {
			cold = await this.ensureReady();
		} catch (e) {
			console.error("start failed", String(e));
			// Never show the reason to the client (plan 1A.6).
			return new Response("Apotheke is not available right now.\n", {
				status: 503,
				headers: { "Retry-After": "5", "Cache-Control": "no-store" },
			});
		}
		const res = await this.ctx.container!.getTcpPort(PORT).fetch(request);
		const out = new Response(res.body, res);
		out.headers.append("Server-Timing", `container-start;dur=${cold}`);
		return out;
	}
}

export default {
	async fetch(request, env): Promise<Response> {
		return env.APOTHEKE.getByName("primary").fetch(request);
	},
} satisfies ExportedHandler<Env>;
