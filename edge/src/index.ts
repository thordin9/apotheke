// Apotheke edge (plan P4 i): the Worker `apotheke-api` forwards every request to one Durable Object, which starts
// and reuses the Apotheke container (Dockerfile.frankenphp) through the Durable Object Container API.
// D1 and R2 are reached from the container at http://d1.internal and http://r2.internal via outbound handlers
// (ADR-002, P4-3 to P4-5). Both are selected only when the storage fragment's bindings are on the Worker:
// R2 for files and the Installation Durable Object's SQLite for the database.
import { DurableObject, WorkerEntrypoint } from "cloudflare:workers";
import { d1UnboundResponse, type D1Env } from "./d1";
import { INSTALLATION_NAME, installationAppKey, installationPrefix, installationStub, propsDoName, validDoName, type InstallationEnv, type StorageProps } from "./installation";
import { routeFor, routeResponse } from "./route";
import { handleR2Request, r2UnboundResponse, selectedBucket, storageBackend, storageSelected, type R2Env, type StorageMeter } from "./r2";
import { forwardWithLimits, limitsEnabled, takeLimits, UsageMeter, type Limits } from "./limits";

export { Installation } from "./installation";

const PORT = 9091;
// A first start that runs migrations took 29.4 s against the old 30 s: 90 s leaves about 3x headroom.
const READY_TIMEOUT_MS = 90_000;

/**
 * Outbound Worker for the container's http://d1.internal/{query,batch,exec}.
 * Bound with interceptOutboundHttp so the container never holds a Cloudflare token. Answers only when
 * STORAGE, DB and INSTALLATION are all bound (edge/storage.jsonc, merged behind EDGE_STORAGE_READY).
 */
export class D1Outbound extends WorkerEntrypoint<Env & D1Env & InstallationEnv, StorageProps> {
	async fetch(request: Request): Promise<Response> {
		// The database is the Installation object's SQLite, and only in storage mode. DB stays the control plane.
		// The installation comes only from the props the container object set (wireStorage); none means no database.
		const doName = propsDoName(this.ctx?.props);
		if (!storageSelected(this.env) || !this.env.INSTALLATION || !doName) return d1UnboundResponse();
		return installationStub(this.env.INSTALLATION, doName).fetch(request);
	}
}

export class R2Outbound extends WorkerEntrypoint<Env & R2Env & InstallationEnv, StorageProps> {
	async fetch(request: Request): Promise<Response> {
		const bucket = selectedBucket(this.env);
		const doName = propsDoName(this.ctx?.props);
		if (!bucket || !doName) return r2UnboundResponse();
		// Limits (E5): stored bytes are counted in the installation's own object when the props carry storageHard.
		const hard = this.ctx?.props?.storageHard;
		const ns = this.env.INSTALLATION;
		const meter: StorageMeter | undefined = hard !== undefined && ns && doName !== INSTALLATION_NAME && limitsEnabled(this.env)
			? { add: (delta, enforce) => installationStub(ns, doName).storageAdd(delta, enforce && typeof hard === "number" ? hard : null) }
			: undefined;
		return handleR2Request(request, bucket, installationPrefix(doName), meter);
	}
}

export class ApothekeContainer extends DurableObject<Env & D1Env & R2Env> {
	/** /healthz answered 200 for the running container. False after a timed-out start or a failed forward. */
	private ready = false;
	/** The one start-and-wait in flight: concurrent requests share it (L-1911). Cleared when it settles. */
	private readying: Promise<number> | null = null;
	/** Readiness waits in a row that timed out while the container kept running (L-1912). */
	private timedOutWaits = 0;
	/** Limits (E5): this installation's requests and active time this month. Unused for primary or with limits off. */
	private meter?: UsageMeter;

	private sleepAfter(): number {
		return Number(this.env.SLEEP_AFTER_MS) || 15 * 60 * 1000;
	}

	constructor(ctx: DurableObjectState, env: Env & D1Env) {
		super(ctx, env);
		const c = ctx.container;
		// A restarted Durable Object starts without a timeout: set it again when the container is already up.
		// Re-bind d1.internal so a warm container keeps the outbound handler after a DO eviction.
		if (c?.running) {
			this.ready = true;
			void ctx.blockConcurrencyWhile(async () => {
				await c.setInactivityTimeout(this.sleepAfter());
				await this.wireStorage(c);
			});
		}
	}

	/** Point d1.internal and r2.internal at their handlers (safe to call while the container is running).
	 *  The installation is this object's own name (getByName in the default fetch), never anything from PHP. */
	private async wireStorage(c: NonNullable<DurableObjectState["container"]>, limits: Limits | null = null): Promise<void> {
		const doName = this.ctx.id?.name;
		// With limits, storageHard (the storage_bytes hard value, or null for none) turns on the stored-bytes counter.
		const props = !validDoName(doName) ? {} : limits ? { doName, prefix: installationPrefix(doName), storageHard: limits.storage_bytes?.hard ?? null } : { doName, prefix: installationPrefix(doName) };
		await c.interceptOutboundHttp("d1.internal", this.ctx.exports.D1Outbound({ props }));
		await c.interceptOutboundHttp("r2.internal", this.ctx.exports.R2Outbound({ props }));
	}

	/** Starts the container if needed and waits for /healthz. Returns the cold-start time in ms (0 when warm). */
	private async ensureReady(limits: Limits | null = null): Promise<number> {
		const c = this.ctx.container;
		if (!c) throw new Error("no container configured");
		// Throws on half a storage fragment: the Worker answers 503 and the container never starts on a fallback.
		const backend = storageBackend(this.env);
		// Storage mode needs this object's name for its database and prefix: without it, never start.
		if (backend === "r2" && !validDoName(this.ctx.id?.name)) throw new Error("no installation name");
		await this.wireStorage(c, limits);
		if (c.running && this.ready) return 0;
		this.readying ??= this.startAndWait(c, backend).finally(() => {
			this.readying = null;
		});
		return this.readying;
	}

	/** Starts the container unless it is running, sets the inactivity timeout and waits for /healthz. */
	/** APP_KEY and ALLOWED_HOSTS for this object's installation. primary (or an unnamed object) gets the Worker's own
	 *  values unchanged. Another installation gets its derived key and its own hosts from installation_routes, or
	 *  does not start. */
	private async installationEnv(): Promise<{ appKey: string; allowedHosts: string }> {
		const doName = this.ctx.id?.name ?? INSTALLATION_NAME;
		const appKey = await installationAppKey(doName, this.env.APP_KEY);
		if (doName === INSTALLATION_NAME) return { appKey, allowedHosts: this.env.ALLOWED_HOSTS ?? "" };
		if (!validDoName(doName) || !this.env.DB) throw new Error("no installation hosts");
		const { results } = await this.env.DB
			.prepare("SELECT r.host FROM installation_routes r JOIN installations i ON i.id = r.installation_id WHERE i.do_name = ? ORDER BY r.host")
			.bind(doName)
			.all<{ host: string }>();
		if (results.length === 0) throw new Error("no installation hosts");
		return { appKey, allowedHosts: results.map((r) => r.host).join(",") };
	}

	private async startAndWait(c: NonNullable<DurableObjectState["container"]>, backend: ReturnType<typeof storageBackend>): Promise<number> {
		const t0 = Date.now();
		const site = await this.installationEnv();
		// A container left running but not ready (a timed-out start, a deploy or restart) gets the timeout and probe too.
		if (!c.running) this.timedOutWaits = 0;
		if (!c.running) c.start({
			enableInternet: false,
			env: {
				// The entrypoint refuses to start without a valid APP_PASSWORD (exit 78): the Worker answers 503.
				APP_PASSWORD: this.env.APP_PASSWORD ?? "",
				APP_KEY: site.appKey,
				ALLOWED_HOSTS: site.allowedHosts,
				COOKIE_DOMAIN: this.env.COOKIE_DOMAIN ?? "",
				CORS_ALLOWED_ORIGINS: this.env.CORS_ALLOWED_ORIGINS ?? "",
				APOTHEKE_JOBS_WORKER: this.env.APOTHEKE_JOBS_WORKER ?? "0",
				APOTHEKE_PHP_WORKER: this.env.APOTHEKE_PHP_WORKER ?? "0",
				PORT: String(PORT),
				TZ: "Europe/Athens",
				// The Worker terminates TLS. The app then honours X-Forwarded-Proto/Host (overwritten below).
				TRUST_PROXY: "1",
				// Per-client rate-limit keys: not TRUST_PROXY (self-host may set that behind other proxies).
				APOTHEKE_EDGE_CLIENT_IP: "1",
				// Only with the merged fragment, and never from Worker vars; otherwise unset, so PHP keeps
				// LocalStorage and its local database. Storage mode is R2 files plus the Installation object.
				...(backend === "r2" ? { STORAGE_BACKEND: "r2", DB_DRIVER: "d1" } : {}),
			},
		});
		await c.setInactivityTimeout(this.sleepAfter());
		// Do not call monitor() or waitUntil(monitor()). A pending monitor, and a
		// promise passed to waitUntil, each keep this Durable Object from becoming
		// inactive for up to 15 minutes from when they start, so the inactivity
		// timeout would not begin until then (Cloudflare Durable Object lifecycle).
		const port = c.getTcpPort(PORT);
		const probe = readinessRequest(site.allowedHosts);
		while (Date.now() - t0 < READY_TIMEOUT_MS) {
			try {
				const r = await port.fetch(probe, { redirect: "manual" });
				if (r.status === 200) {
					this.ready = true;
					this.timedOutWaits = 0;
					return Date.now() - t0;
				}
			} catch {
				// not listening yet
			}
			if (!c.running) throw new Error("container stopped during start");
			await new Promise((r) => setTimeout(r, 100));
		}
		// The first timed-out wait keeps the container, so a slow first start can finish. A second one in a
		// row means it is stuck: destroy it, so the next request starts a fresh one instead of waiting again.
		this.timedOutWaits += 1;
		if (this.timedOutWaits >= 2) {
			this.timedOutWaits = 0;
			await c.destroy().catch((e: unknown) => console.error("destroy failed", String(e)));
		}
		throw new Error("container not ready");
	}

	async fetch(request: Request): Promise<Response> {
		let cold: number;
		const taken = takeLimits(request, this.env, this.ctx.id?.name, INSTALLATION_NAME);
		const limits = taken.limits;
		request = taken.request;
		const meter = limits ? (this.meter ??= new UsageMeter(this.ctx.storage, () => this.sleepAfter())) : null;
		if (limits && meter) {
			const refused = await meter.admit(limits);
			if (refused) return refused;
		}
		try {
			cold = await this.ensureReady(limits);
		} catch (e) {
			console.error("start failed", String(e));
			return unavailable();
		}
		let res: Response;
		try {
			res = await this.ctx.container!.getTcpPort(PORT).fetch(toContainer(request), { redirect: "manual" });
		} catch (e) {
			// Running but not listening (an earlier start timed out): 503 like a failed start, not error 1101.
			this.ready = false;
			console.error("forward failed", String(e));
			return unavailable();
		}
		const out = new Response(res.body, res);
		out.headers.append("Server-Timing", `container-start;dur=${cold}`);
		if (limits && meter) await meter.done(limits, out.headers);
		return out;
	}

	/** Writes the limit counters (set at most FLUSH_MS after a change). */
	async alarm(): Promise<void> {
		await this.meter?.counter.flush();
	}

	/** This month's request and active-time totals, for the Cron that will copy them into D1 usage_counters (not
	 *  scheduled yet). Zero for primary and with limits off, which never count. */
	async usage(): Promise<{ period: string; requests: number; container_seconds: number }> {
		return (this.meter ??= new UsageMeter(this.ctx.storage, () => this.sleepAfter())).usage();
	}
}

/** Never show the reason to the client (plan 1A.6). */
function unavailable(): Response {
	return new Response("Apotheke is not available right now.\n", {
		status: 503,
		headers: { "Retry-After": "5", "Cache-Control": "no-store" },
	});
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
	headers.delete("X-Apotheke-Client-IP");
	const connecting = (request.headers.get("cf-connecting-ip") ?? "").trim();
	if (connecting !== "") {
		headers.set("X-Apotheke-Client-IP", connecting);
	}
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
	async fetch(request, env: Env & D1Env): Promise<Response> {
		const limited = limitsEnabled(env);
		const route = await routeFor(new URL(request.url).host, env.DB, Date.now(), limited);
		const early = routeResponse(route);
		if (early) return early;
		const stub = env.APOTHEKE.getByName(route.doName);
		// primary is never limited, and with LIMITS_ENABLED off every request is forwarded as before.
		if (!limited || route.doName === INSTALLATION_NAME) return stub.fetch(request);
		return forwardWithLimits(request, route.limits ?? {}, (r) => stub.fetch(r));
	},
} satisfies ExportedHandler<Env & D1Env>;
