// Apotheke edge (plan P4 i): the Worker `apotheke-api` forwards every request to one Durable Object, which starts
// and reuses the Apotheke container (Dockerfile.frankenphp) through the Durable Object Container API.
// D1 is reached from the container at http://d1.internal via an outbound handler (ADR-002, P4-3); R2 follows in P4-4.
import { DurableObject, WorkerEntrypoint } from "cloudflare:workers";
import { d1UnboundResponse, handleD1Request, type D1Env } from "./d1";
import { handleR2Request, r2UnboundResponse, type R2Env } from "./r2";

const PORT = 9091;
const READY_TIMEOUT_MS = 30_000;

/**
 * Outbound Worker for the container's http://d1.internal/{query,batch,exec}.
 * Bound with interceptOutboundHttp so the container never holds a Cloudflare token.
 * DB is present only after EDGE_STORAGE_READY merges edge/storage.jsonc.
 */
export class D1Outbound extends WorkerEntrypoint<Env & D1Env> {
	async fetch(request: Request): Promise<Response> {
		const db = this.env.DB;
		if (!db) return d1UnboundResponse();
		return handleD1Request(request, db);
	}
}

export class R2Outbound extends WorkerEntrypoint<Env & R2Env> {
	async fetch(request: Request): Promise<Response> {
		if (!this.env.STORAGE) return r2UnboundResponse();
		return handleR2Request(request, this.env.STORAGE);
	}
}

export class ApothekeContainer extends DurableObject<Env & D1Env & R2Env> {
	private sleepAfter(): number {
		return Number(this.env.SLEEP_AFTER_MS) || 15 * 60 * 1000;
	}

	constructor(ctx: DurableObjectState, env: Env & D1Env) {
		super(ctx, env);
		const c = ctx.container;
		// A restarted Durable Object starts without a timeout: set it again when the container is already up.
		// Re-bind d1.internal so a warm container keeps the outbound handler after a DO eviction.
		if (c?.running) {
			void ctx.blockConcurrencyWhile(async () => {
				await c.setInactivityTimeout(this.sleepAfter());
				await this.wireStorage(c);
			});
		}
	}

	/** Point d1.internal and r2.internal at their handlers (safe to call while the container is running). */
	private async wireStorage(c: NonNullable<DurableObjectState["container"]>): Promise<void> {
		await c.interceptOutboundHttp("d1.internal", this.ctx.exports.D1Outbound({ props: {} }));
		await c.interceptOutboundHttp("r2.internal", this.ctx.exports.R2Outbound({ props: {} }));
	}

	/** Starts the container if needed and waits for /healthz. Returns the cold-start time in ms (0 when warm). */
	private async ensureReady(): Promise<number> {
		const c = this.ctx.container;
		if (!c) throw new Error("no container configured");
		await this.wireStorage(c);
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
