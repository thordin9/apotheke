// Apotheke edge (plan P4 i): the Worker `apotheke-api` forwards every request to one Durable Object, which starts
// and reuses the Apotheke container (Dockerfile.frankenphp) through the Durable Object Container API.
// D1 and R2 are reached from the container at http://d1.internal and http://r2.internal via outbound handlers
// (ADR-002, P4-3 to P4-5). Both are selected only when the storage fragment's bindings are on the Worker:
// R2 for files and the Installation Durable Object's SQLite for the database.
import { DurableObject, WorkerEntrypoint } from "cloudflare:workers";
import { d1UnboundResponse, type D1Env } from "./d1";
import { installationStub, type InstallationEnv } from "./installation";
import { handleR2Request, r2UnboundResponse, selectedBucket, storageBackend, storageSelected, type R2Env } from "./r2";

export { Installation } from "./installation";

const PORT = 9091;
const READY_TIMEOUT_MS = 30_000;

/**
 * Outbound Worker for the container's http://d1.internal/{query,batch,exec}.
 * Bound with interceptOutboundHttp so the container never holds a Cloudflare token. Answers only when
 * STORAGE, DB and INSTALLATION are all bound (edge/storage.jsonc, merged behind EDGE_STORAGE_READY).
 */
export class D1Outbound extends WorkerEntrypoint<Env & D1Env & InstallationEnv> {
	async fetch(request: Request): Promise<Response> {
		// The database is the Installation object's SQLite, and only in storage mode. DB stays the control plane.
		if (!storageSelected(this.env) || !this.env.INSTALLATION) return d1UnboundResponse();
		return installationStub(this.env.INSTALLATION).fetch(request);
	}
}

export class R2Outbound extends WorkerEntrypoint<Env & R2Env> {
	async fetch(request: Request): Promise<Response> {
		const bucket = selectedBucket(this.env);
		if (!bucket) return r2UnboundResponse();
		return handleR2Request(request, bucket);
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
		// Throws on half a storage fragment: the Worker answers 503 and the container never starts on a fallback.
		const backend = storageBackend(this.env);
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
				// The Worker terminates TLS. The app then honours X-Forwarded-Proto/Host (overwritten below).
				TRUST_PROXY: "1",
				// Only with the merged fragment; otherwise unset, so PHP keeps its LocalStorage default.
				...(backend === "r2" ? { STORAGE_BACKEND: "r2" } : {}),
			},
		});
		await c.setInactivityTimeout(this.sleepAfter());
		// Do not call monitor() or waitUntil(monitor()). A pending monitor, and a
		// promise passed to waitUntil, each keep this Durable Object from becoming
		// inactive for up to 15 minutes from when they start, so the inactivity
		// timeout would not begin until then (Cloudflare Durable Object lifecycle).
		const port = c.getTcpPort(PORT);
		const probe = readinessRequest(this.env.ALLOWED_HOSTS ?? "");
		while (Date.now() - t0 < READY_TIMEOUT_MS) {
			try {
				const r = await port.fetch(probe, { redirect: "manual" });
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
		const res = await this.ctx.container!.getTcpPort(PORT).fetch(toContainer(request), { redirect: "manual" });
		const out = new Response(res.body, res);
		out.headers.append("Server-Timing", `container-start;dur=${cold}`);
		return out;
	}
}


/** First public host from ALLOWED_HOSTS (comma or space separated, a port is kept).
 *  "*" is skipped: it is not a Host the app's allow-list can accept as a name. */
export function firstAllowedHost(allowedHosts: string): string | undefined {
	for (const part of allowedHosts.split(/[\s,]+/)) {
		if (part !== "" && part !== "*") return part;
	}
	return undefined;
}

/** The readiness probe goes through toContainer so Host and the forwarded headers
 *  match a public request. The first ALLOWED_HOSTS entry is that public host.
 *  When the list is empty or only "*", use 127.0.0.1 (always on the allow-list,
 *  same as the image HEALTHCHECK) and log; do not use Host "container". */
function readinessRequest(allowedHosts: string): Request {
	const host = firstAllowedHost(allowedHosts);
	if (host === undefined) {
		console.warn("ALLOWED_HOSTS is empty or unset: readiness probe uses 127.0.0.1");
	}
	return toContainer(new Request(`https://${host ?? "127.0.0.1"}/healthz`));
}

/** Cloudflare Containers accept only http: to the TCP port. Rewrite the incoming URL, overwrite
 *  forwarded proto/host (never pass through a client value), and do not follow a 3xx inside the Worker. */
function toContainer(request: Request): Request {
	const url = new URL(request.url);
	const proto = url.protocol === "https:" ? "https" : "http";
	const host = url.host;
	url.protocol = "http:";
	url.hash = "";
	const headers = new Headers(request.headers);
	headers.set("X-Forwarded-Proto", proto);
	headers.set("X-Forwarded-Host", host);
	const init: RequestInit & { duplex?: "half" } = {
		method: request.method,
		headers,
		redirect: "manual",
	};
	if (request.body !== null) {
		init.body = request.body;
		init.duplex = "half";
	}
	return new Request(url, init);
}

export default {
	async fetch(request, env): Promise<Response> {
		return env.APOTHEKE.getByName("primary").fetch(request);
	},
} satisfies ExportedHandler<Env>;
