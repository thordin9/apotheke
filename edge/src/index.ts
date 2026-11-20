// Apotheke edge (plan P4 i): the Worker `apotheke-api` forwards every request to one Durable Object, which starts
// and reuses the Apotheke container (Dockerfile.frankenphp) through the Durable Object Container API.
// D1 and R2 are reached from the container at http://d1.internal and http://r2.internal via outbound handlers
// (ADR-002, P4-3 to P4-5). Both are selected only when the storage fragment's bindings are on the Worker:
// R2 for files and the Installation Durable Object's SQLite for the database.
import { DurableObject, WorkerEntrypoint } from "cloudflare:workers";
import { d1UnboundResponse, type D1Env } from "./d1";
import { INSTALLATION_NAME, installationAppKey, installationPrefix, installationStub, lifecycleName, propsDoName, validDoName, type InstallationEnv, type StorageProps } from "./installation";
import { routeFor, routeResponse } from "./route";
import { handleR2Request, r2UnboundResponse, selectedBucket, storageBackend, storageSelected, type R2Env, type StorageMeter } from "./r2";
import { AUTOFILL_HOST, handleAutofill, type AutofillEnv, type AutofillProps } from "./autofill";
import { forwardWithLimits, isPreflight, LIMITS_HEADER, limitsEnabled, takeLimits, UsageMeter, WARNING_HEADER, withCors, type Limits } from "./limits";
import { opsBackupRequest } from "./ops-signature";
import { isOpsAccountsAction, opsAccountsRequest, opsAccountsResult, opsUsersResult, OPS_USERS_URL, resolveOpsInstallation, type OpsAccountsAction, type OpsAccountsResult, type OpsClaimResult, type OpsResetResult, type OpsUsersResult } from "./ops-accounts";

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
			? { add: (delta, enforce) => installationStub(ns, doName).storageAdd(delta, enforce && typeof hard === "number" ? hard : null, doName) }
			: undefined;
		return handleR2Request(request, bucket, installationPrefix(doName), meter);
	}
}

/** Outbound Worker for the container's http://autofill.internal (autofill.ts). Wired only with limits on, for an
 *  installation other than primary; the props come from the container object, never from PHP. */
export class AutofillOutbound extends WorkerEntrypoint<Env & AutofillEnv, AutofillProps> {
	async fetch(request: Request): Promise<Response> {
		return handleAutofill(request, this.env, this.ctx?.props);
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
	/** True only while opsBackup starts the container: the start gate then also accepts suspended and deleting. */
	private opsStart = false;
	/** A public request must pass the public start gate before it is forwarded: after opsBackup, or when this
	 *  object restarts over a running non-primary container. */
	private opsOnly = false;

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
			const doName = ctx.id?.name;
			// Re-check status on the next public request: opsOnly is in memory and would otherwise stay false.
			if (validDoName(doName) && doName !== INSTALLATION_NAME) this.opsOnly = true;
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
		// Limits (L-2190): autofill for this installation, with its monthly limit (null for none).
		if (limits && validDoName(doName)) {
			const autofill: AutofillProps = { doName, autofill: limits.autofill_calls_month ?? null };
			await c.interceptOutboundHttp(AUTOFILL_HOST, this.ctx.exports.AutofillOutbound({ props: autofill }));
		}
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

	/** APP_KEY and ALLOWED_HOSTS for this object's installation. primary (or an unnamed object) gets the Worker's own
	 *  values unchanged and the Worker's APP_PASSWORD. Another installation gets its derived key and its own hosts from
	 *  installation_routes, or does not start (also when its status is not provisioning or active); it gets no
	 *  APP_PASSWORD, and APOTHEKE_HOSTED=1 with APOTHEKE_INSTALLATION set to this object's own name. */
	private async installationEnv(): Promise<{ appKey: string; allowedHosts: string; account: Record<string, string> }> {
		const doName = this.ctx.id?.name ?? INSTALLATION_NAME;
		const appKey = await installationAppKey(doName, this.env.APP_KEY);
		// The entrypoint refuses to start without a valid APP_PASSWORD (exit 78): the Worker answers 503.
		if (doName === INSTALLATION_NAME) return { appKey, allowedHosts: this.env.ALLOWED_HOSTS ?? "", account: { APP_PASSWORD: this.env.APP_PASSWORD ?? "" } };
		if (!validDoName(doName) || !this.env.DB) throw new Error("no installation hosts");
		const { results } = await this.env.DB
			// Read fresh at every start: a suspended or deleted installation does not start, even through a cached route.
			.prepare("SELECT r.host FROM installation_routes r JOIN installations i ON i.id = r.installation_id WHERE i.do_name = ? AND (i.status IN ('provisioning', 'active') OR (? = 1 AND i.status IN ('suspended', 'deleting'))) ORDER BY r.host")
			.bind(doName, this.opsStart ? 1 : 0)
			.all<{ host: string }>();
		if (results.length === 0) throw new Error("no installation hosts");
		return { appKey, allowedHosts: results.map((r) => r.host).join(","), account: { APOTHEKE_HOSTED: "1", APOTHEKE_INSTALLATION: doName } };
	}

	/** Starts the container unless it is running, sets the inactivity timeout and waits for /healthz. */
	private async startAndWait(c: NonNullable<DurableObjectState["container"]>, backend: ReturnType<typeof storageBackend>): Promise<number> {
		const t0 = Date.now();
		const site = await this.installationEnv();
		// A container left running but not ready (a timed-out start, a deploy or restart) gets the timeout and probe too.
		if (!c.running) this.timedOutWaits = 0;
		if (!c.running) c.start({
			enableInternet: false,
			env: {
				...site.account,
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

	/** Lifecycle (src/lifecycle.ts): stops and destroys this installation's container. Data is not touched. Refused for
	 *  primary and for an unnamed object, whatever the control plane says. */
	async stopContainer(): Promise<void> {
		lifecycleName(this.ctx.id?.name);
		this.ready = false;
		this.opsOnly = false;
		const c = this.ctx.container;
		if (c?.running) await c.destroy();
	}

	/** Lifecycle purge: stops the container, then deletes this object's own storage. Refused like stopContainer. */
	async purge(): Promise<void> {
		await this.stopContainer();
		await this.ctx.storage.deleteAll();
	}

	/** Lifecycle export (C2): the app's backup for jobId, through the container's port like the readiness probe, never the
	 *  public forward (POST http://ops.internal/internal/backup, signed with this installation's APP_KEY). May start the
	 *  container while the installation is suspended or deleting. Refused like stopContainer. Returns the full key, the
	 *  status when there is none (200: an answer without a usable key), or a code for a permanent refusal (primary, not
	 *  startable, missing APP_KEY). */
	async opsBackup(jobId: string): Promise<{ key: string } | { status: number } | { code: string }> {
		try {
			const doName = lifecycleName(this.ctx.id?.name);
			const c = this.ctx.container;
			if (!c) throw new Error("no container configured");
			this.opsOnly = true;
			if (!c.running || !this.ready) {
				// A public start already in flight bound the status check without opsStart: wait for it, then start
				// for the backup. Joining it would fail a suspended or deleting installation as not_startable.
				if (this.readying && !this.opsStart) await this.readying.catch(() => {});
				this.opsStart = true;
				try {
					await this.ensureReady();
				} finally {
					this.opsStart = false;
				}
			}
			const res = await c.getTcpPort(PORT).fetch(await opsBackupRequest(await installationAppKey(doName, this.env.APP_KEY), jobId), { redirect: "manual" });
			if (res.status !== 200) return { status: res.status };
			const body = (await res.json().catch(() => null)) as { ok?: unknown; backup?: { job_id?: unknown; key?: unknown } } | null;
			if (body === null) return { status: 502 };
			const rel = body.backup?.key;
			const valid = body.ok === true && body.backup?.job_id === jobId && typeof rel === "string" && BACKUP_MANIFEST_KEY.test(rel);
			return valid ? { key: installationPrefix(doName) + rel } : { status: 200 };
		} catch (e) {
			const code = permanentBackupCode(e);
			if (code) return { code };
			throw e;
		}
	}

	/** Operator console (PR0b): the first-admin claim or the admin reset, through the container's port like opsBackup,
	 *  never the public forward (POST http://ops.internal/internal/accounts/..., signed with this installation's
	 *  APP_KEY). Refused for primary and an unnamed object before any network call. Starts the container only like a
	 *  public request would (provisioning or active), and checks that status again when the container is already up.
	 *  The claim token is only in the returned result: it is never logged and never put in an error. */
	async opsAccounts(action: OpsAccountsAction): Promise<OpsAccountsResult> {
		if (!isOpsAccountsAction(action)) return { ok: false, code: "bad_action" };
		let doName: string;
		try {
			doName = lifecycleName(this.ctx.id?.name);
		} catch {
			return { ok: false, code: "refused" };
		}
		const c = this.ctx.container;
		if (!c) return { ok: false, code: "not_startable" };
		let req: Request;
		try {
			// A backup may have started this container for a suspended or deleting installation: never act on that.
			if (this.readying && this.opsStart) await this.readying.catch(() => {});
			// Warm: keep the storage wiring the last public request set (with its limits).
			if (!c.running || !this.ready) await this.ensureReady();
			await this.publicStart();
			req = await opsAccountsRequest(await installationAppKey(doName, this.env.APP_KEY), action);
		} catch (e) {
			const code = permanentBackupCode(e) ?? (e instanceof Error && e.message === "installation not startable" ? "not_startable" : undefined);
			if (code) return { ok: false, code };
			console.error("ops accounts start failed", String(e));
			return { ok: false, code: "unavailable" };
		}
		let res: Response;
		try {
			res = await c.getTcpPort(PORT).fetch(req, { redirect: "manual" });
		} catch (e) {
			this.ready = false;
			console.error("ops accounts forward failed", String(e));
			return { ok: false, code: "unreachable" };
		}
		return opsAccountsResult(action, res);
	}

	async fetch(request: Request): Promise<Response> {
		let cold: number;
		const taken = takeLimits(request, this.env, this.ctx.id?.name, INSTALLATION_NAME);
		const limits = taken.limits;
		request = taken.request;
		const meter = limits ? (this.meter ??= new UsageMeter(this.ctx.storage, () => this.sleepAfter())) : null;
		if (limits && meter) {
			const refused = await meter.admit(limits, Date.now(), isPreflight(request, this.env.CORS_ALLOWED_ORIGINS));
			if (refused) return withCors(refused, request, this.env.CORS_ALLOWED_ORIGINS);
		}
		try {
			cold = await this.ensureReady(limits);
			if (this.opsOnly) await this.publicStart();
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
		// Only the edge sets the warning (below): never one from the container.
		out.headers.delete(WARNING_HEADER);
		if (limits && meter) await meter.done(limits, out.headers);
		return out;
	}

	/** The public start gate for a container opsBackup started: throws unless the installation is provisioning or active. */
	private async publicStart(): Promise<void> {
		const row = await this.env.DB?.prepare("SELECT 1 AS ok FROM installations WHERE do_name = ? AND status IN ('provisioning', 'active')").bind(this.ctx.id?.name ?? "").first();
		if (!row) throw new Error("installation not startable");
		this.opsOnly = false;
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

/** PHP StoredBackups: the relative key is exactly backups/<id>/manifest.json, id YYYYMMDD-HHMMSS- plus six hex digits. */
const BACKUP_MANIFEST_KEY = /^backups\/\d{8}-\d{6}-[a-f0-9]{6}\/manifest\.json$/;

/** Permanent opsBackup failures: returned as a code so the Workflow fails the job instead of retrying. */
function permanentBackupCode(e: unknown): string | undefined {
	const m = e instanceof Error ? e.message : "";
	if (m === "lifecycle refused for this installation") return "refused";
	if (m === "APP_KEY is not a 32-byte key") return "bad_app_key";
	if (m === "no installation hosts" || m === "no installation name" || m === "no container configured") return "not_startable";
	return undefined;
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
 *  forwarded proto/host (never pass through a client value), and do not follow a 3xx inside the Worker.
 *  The limits headers never reach PHP, whoever sent them and whatever LIMITS_ENABLED is. */
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
	headers.delete(LIMITS_HEADER);
	headers.delete(WARNING_HEADER);
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

/** Operator console (PR0b): the first-admin claim and the admin reset for one installation, by its id or do_name. For
 *  the operator Worker's service binding only (added later with E6-1): this Worker has no route or binding for it. The
 *  installation is read from the control plane (DB); primary, unknown installations and any not provisioning or active
 *  are refused before its container object is called. */
export class Ops extends WorkerEntrypoint<Env & D1Env & R2Env & InstallationEnv> {
	async claimCreate(installation: string): Promise<OpsClaimResult> {
		return (await opsAccountsFor(this.env, installation, "claim")) as OpsClaimResult;
	}

	async resetAdmins(installation: string): Promise<OpsResetResult> {
		return (await opsAccountsFor(this.env, installation, "reset-admins")) as OpsResetResult;
	}

	/** The installation's accounts, metadata only (bridge.ts GET /users), from its Installation object's database: the
	 *  container is not started. Refused like claimCreate (primary, unknown, not provisioning or active). Without the
	 *  storage bindings: unbound. */
	async listUsers(installation: string): Promise<OpsUsersResult> {
		const found = await resolveOpsInstallation(this.env.DB, installation);
		if (!("doName" in found)) return found;
		const ns = this.env.INSTALLATION;
		if (!ns || !storageSelected(this.env)) return { ok: false, code: "unbound" };
		let res: Response;
		try {
			res = await installationStub(ns, found.doName).fetch(new Request(OPS_USERS_URL));
		} catch (e) {
			console.error("ops users read failed", e instanceof Error ? e.name : "error");
			return { ok: false, code: "unreachable" };
		}
		return opsUsersResult(res);
	}
}

async function opsAccountsFor(env: Env & D1Env, installation: unknown, action: OpsAccountsAction): Promise<OpsAccountsResult> {
	const found = await resolveOpsInstallation(env.DB, installation);
	if (!("doName" in found)) return found;
	return (await env.APOTHEKE.getByName(found.doName).opsAccounts(action)) as OpsAccountsResult;
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
		return forwardWithLimits(request, route.limits ?? {}, (r) => stub.fetch(r), env.CORS_ALLOWED_ORIGINS);
	},
} satisfies ExportedHandler<Env & D1Env>;
