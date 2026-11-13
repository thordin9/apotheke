// Operator console E4 (docs/operator-console.md#lifecycle-jobs): provision, suspend, resume, export, soft delete and
// purge of an installation, as rows in lifecycle_jobs run step by step. Every step is idempotent: it checks the state it
// would create before acting, so a retry repeats nothing. A job is created paused and runs only once an operator has
// confirmed it. primary is refused by every function here, whatever its row says.
// Pure functions over injected dependencies; the Workflow that runs them is lifecycle-workflow.ts.
import type { WorkflowStep } from "cloudflare:workers";
import { NonRetryableError } from "cloudflare:workflows";
import { INSTALLATION_NAME, installationPrefix, lifecycleName } from "./installation";
import { sweepExpiredMultipartUploads } from "./r2";

export const JOB_KINDS = ["provision", "suspend", "resume", "export", "delete", "purge"] as const;
export type JobKind = (typeof JOB_KINDS)[number];

export type Job = {
	id: string;
	installation_id: string;
	kind: JobKind;
	idempotency_key: string;
	state: "paused" | "queued" | "running" | "done" | "failed";
	step: string | null;
	params_json: string | null;
	result_json: string | null;
	error_code: string | null;
	created_by: string;
	confirmed_by: string | null;
};

/** The installation's container, reached through its ApothekeContainer object. */
export interface ContainerOps {
	/** Stops and destroys the container (ApothekeContainer.stopContainer). Nothing running is a no-op. */
	stop(doName: string): Promise<void>;
	/** Deletes the container object's storage (ApothekeContainer.purge). */
	purge(doName: string): Promise<void>;
	/** Starts a new installation's container with BOOTSTRAP=1 and no APP_PASSWORD: migrations only, no account
	 *  (C1). Calling it again for the same installation is a no-op. */
	bootstrap(doName: string): Promise<void>;
	/** The app's own backup, written under the installation's prefix. The same backupId twice returns the same object
	 *  without making a second backup. */
	backup(doName: string, backupId: string): Promise<{ key: string }>;
}

/** C1's first-admin claim. One claim per job (the same jobId twice is a no-op); the link is shown by the console, never
 *  stored here. */
export interface FirstAdminClaim {
	create(doName: string, jobId: string): Promise<void>;
}

/** Drops cached routes for these hosts. Today routes are cached only inside each apotheke-api isolate, for up to 30 s,
 *  so a status change can take that long to show in routing (the container itself refuses to start unless the
 *  installation is provisioning or active). */
export interface RouteCache {
	purge(hosts: string[]): Promise<void>;
}

export type LifecycleDeps = {
	db: D1Database;
	bucket: R2Bucket;
	/** The installation's database object (Installation.purge). */
	installation: { purge(doName: string): Promise<void> };
	container: ContainerOps;
	claim: FirstAdminClaim;
	routeCache: RouteCache;
};

/** A refusal or a conflict: never retried (NonRetryableError), the job fails at once. code is stored on the failed job;
 *  the message never holds tenant data. */
export class LifecycleError extends NonRetryableError {
	readonly code: string;
	constructor(code: string) {
		super(`lifecycle: ${code}`);
		this.code = code;
	}
}

/** A transient failure (an R2 call that did not finish): the Workflow retries the step. */
export class LifecycleRetry extends Error {
	readonly code: string;
	constructor(code: string) {
		super(`lifecycle: ${code}`);
		this.code = code;
	}
}

/** R2 deletes at most this many keys per call; each page is its own step. */
export const PURGE_PAGE = 1000;
const NOW = "strftime('%Y-%m-%dT%H:%M:%SZ', 'now')";

type Target = { id: string; doName: string; prefix: string; status: string; hosts: string[] };

/** The installation a job may touch. primary (by id or DO name), an unknown id, or an inconsistent row is refused. */
async function target(db: D1Database, installationId: string): Promise<Target> {
	if (installationId === INSTALLATION_NAME) throw new LifecycleError("refused");
	const row = await db
		.prepare("SELECT id, do_name, r2_prefix, status FROM installations WHERE id = ?")
		.bind(installationId)
		.first<{ id: string; do_name: string; r2_prefix: string; status: string }>();
	if (!row) throw new LifecycleError("not_found");
	let doName: string;
	try {
		doName = lifecycleName(row.do_name);
	} catch {
		throw new LifecycleError("refused");
	}
	if (row.id === INSTALLATION_NAME || row.r2_prefix !== installationPrefix(doName)) throw new LifecycleError("refused");
	const { results } = await db
		.prepare("SELECT host FROM installation_routes WHERE installation_id = ? ORDER BY host")
		.bind(row.id)
		.all<{ host: string }>();
	return { id: row.id, doName, prefix: row.r2_prefix, status: row.status, hosts: results.map((r) => r.host) };
}

/** A purge needs a delete job for the installation that finished (so its export was made) and whose retention has run
 *  out: now >= the time it finished plus its retention_days. Otherwise it is refused, with nothing changed. A finished
 *  job is never updated again, so its updated_at is when it finished. */
async function retentionOver(db: D1Database, installationId: string): Promise<void> {
	const row = await db
		.prepare(`SELECT 1 AS ok FROM lifecycle_jobs WHERE installation_id = ? AND kind = 'delete' AND state = 'done'
			AND json_extract(result_json, '$.key') IS NOT NULL AND CAST(json_extract(params_json, '$.retention_days') AS INTEGER) >= 1
			AND ${NOW} >= strftime('%Y-%m-%dT%H:%M:%SZ', updated_at, '+' || CAST(json_extract(params_json, '$.retention_days') AS INTEGER) || ' days')
			LIMIT 1`)
		.bind(installationId)
		.first<{ ok: number }>();
	if (!row) throw new LifecycleError("retention");
}

async function job(db: D1Database, jobId: string): Promise<Job> {
	const row = await db.prepare("SELECT * FROM lifecycle_jobs WHERE id = ?").bind(jobId).first<Job>();
	if (!row) throw new LifecycleError("job_not_found");
	return row;
}

/** Creates a paused job, or returns the existing one for the same idempotency key (<kind>:<installation_id>:<nonce>).
 *  A delete needs retentionDays (how long its export is kept); there is no default here. */
export async function createJob(
	db: D1Database,
	req: { kind: string; installationId: string; nonce: string; createdBy: string; retentionDays?: number },
): Promise<Job> {
	if (!(JOB_KINDS as readonly string[]).includes(req.kind)) throw new LifecycleError("bad_kind");
	if (!/^[A-Za-z0-9_-]{8,128}$/.test(req.nonce) || req.createdBy === "") throw new LifecycleError("bad_request");
	await target(db, req.installationId);
	if (req.kind === "purge") await retentionOver(db, req.installationId);
	let params: string | null = null;
	if (req.kind === "delete") {
		if (!Number.isInteger(req.retentionDays) || (req.retentionDays as number) < 1) throw new LifecycleError("retention_required");
		params = JSON.stringify({ retention_days: req.retentionDays });
	}
	const key = `${req.kind}:${req.installationId}:${req.nonce}`;
	await db
		.prepare("INSERT INTO lifecycle_jobs (id, installation_id, kind, idempotency_key, params_json, created_by) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (idempotency_key) DO NOTHING")
		.bind(crypto.randomUUID(), req.installationId, req.kind, key, params, req.createdBy)
		.run();
	const row = await db.prepare("SELECT * FROM lifecycle_jobs WHERE idempotency_key = ?").bind(key).first<Job>();
	if (!row) throw new LifecycleError("job_not_found");
	return row;
}

/** The operator's confirmation: paused → queued. A purge also needs one of the installation's hosts typed back. */
export async function confirmJob(db: D1Database, jobId: string, operator: string, confirmHost?: string): Promise<Job> {
	const j = await job(db, jobId);
	const t = await target(db, j.installation_id);
	if (operator === "") throw new LifecycleError("bad_request");
	if (j.kind === "purge") {
		if (confirmHost === undefined || !t.hosts.includes(confirmHost)) throw new LifecycleError("confirm_host");
		await retentionOver(db, t.id);
	}
	await db.batch([
		db.prepare(`UPDATE lifecycle_jobs SET state = 'queued', confirmed_by = ?, confirmed_at = ${NOW}, updated_at = ${NOW} WHERE id = ? AND state = 'paused'`).bind(operator, j.id),
		audit(db, { ...j, confirmed_by: operator }, "confirm"),
	]);
	return job(db, jobId);
}

/** One append-only audit row per job and action (id `<job id>:<action>`): installation id and action only. */
function audit(db: D1Database, j: Job, action: string, status?: string): D1PreparedStatement {
	const id = `${j.id}:${action}`;
	return db
		.prepare(`INSERT INTO operator_audit (id, operator, action, installation_id) SELECT ?, ?, ?, ?
			WHERE NOT EXISTS (SELECT 1 FROM operator_audit WHERE id = ?) AND EXISTS (SELECT 1 FROM installations WHERE id = ? AND (? IS NULL OR status = ?))`)
		.bind(id, j.confirmed_by ?? j.created_by, `lifecycle.${j.kind}.${action}`, j.installation_id, id, j.installation_id, status ?? null, status ?? null);
}

/** Every step starts here: the job must be running (confirmed and started), the installation must not be primary and
 *  its status one of `allowed`. Records the step name (only when it changes). */
async function begin(db: D1Database, jobId: string, step: string, allowed: string[]): Promise<{ j: Job; t: Target }> {
	const j = await job(db, jobId);
	const t = await target(db, j.installation_id);
	if (j.state !== "running") throw new LifecycleError("not_running");
	if (!allowed.includes(t.status)) throw new LifecycleError("status_conflict");
	if (j.kind === "purge") await retentionOver(db, t.id);
	await db.prepare(`UPDATE lifecycle_jobs SET step = ?, updated_at = ${NOW} WHERE id = ? AND step IS NOT ?`).bind(step, j.id, step).run();
	return { j, t };
}

/** Sets the status from one of `from` to `to` with its audit row, in one batch. Already `to` changes nothing. */
async function setStatus(db: D1Database, j: Job, t: Target, to: string, from: string[]): Promise<void> {
	await db.batch([
		db
			.prepare(`UPDATE installations SET status = ?, updated_at = ${NOW} WHERE id = ? AND do_name <> ? AND status IN (${from.map(() => "?").join(", ")})`)
			.bind(to, t.id, INSTALLATION_NAME, ...from),
		audit(db, j, `status.${to}`, to),
	]);
	const now = await db.prepare("SELECT status FROM installations WHERE id = ?").bind(t.id).first<{ status: string }>();
	if (now?.status !== to) throw new LifecycleError("status_conflict");
}

/** A purge page runs once per job: its count is recorded on the job (result_json, under the step name), and running the
 *  same page again returns that count without touching R2. */
async function paged(d: LifecycleDeps, jobId: string, name: string, act: (t: Target) => Promise<number>): Promise<{ count: number }> {
	const { j, t } = await begin(d.db, jobId, name, ["deleting", "deleted"]);
	const done = j.result_json ? (JSON.parse(j.result_json) as Record<string, unknown>)[name] : undefined;
	if (typeof done === "number") return { count: done };
	const count = await act(t);
	const path = `$."${name}"`;
	await d.db
		.prepare(`UPDATE lifecycle_jobs SET result_json = json_set(coalesce(result_json, '{}'), ?, ?), updated_at = ${NOW} WHERE id = ? AND json_extract(coalesce(result_json, '{}'), ?) IS NULL`)
		.bind(path, count, j.id, path)
		.run();
	return { count };
}

/** paused jobs never start: queued → running. A running job (a Workflow retry) carries on. Returns the kind. */
export async function startJob(db: D1Database, jobId: string): Promise<JobKind> {
	const j = await job(db, jobId);
	await target(db, j.installation_id);
	if (j.state !== "queued" && j.state !== "running") throw new LifecycleError("not_confirmed");
	if (j.kind === "purge") await retentionOver(db, j.installation_id);
	await db.prepare(`UPDATE lifecycle_jobs SET state = 'running', updated_at = ${NOW} WHERE id = ? AND state = 'queued'`).bind(j.id).run();
	return j.kind;
}

export async function finishJob(db: D1Database, jobId: string): Promise<void> {
	await db.prepare(`UPDATE lifecycle_jobs SET state = 'done', updated_at = ${NOW} WHERE id = ? AND state = 'running'`).bind(jobId).run();
}

export async function failJob(db: D1Database, jobId: string, code: string): Promise<void> {
	await db.prepare(`UPDATE lifecycle_jobs SET state = 'failed', error_code = ?, updated_at = ${NOW} WHERE id = ? AND state = 'running'`).bind(code, jobId).run();
}

/** The steps, each idempotent. Exported for tests; runJob is the order they run in. */
export const steps = {
	/** The new installation needs a route, and its R2 prefix must be empty so a reused name inherits no files. */
	async provisionCheck(d: LifecycleDeps, jobId: string): Promise<void> {
		const { t } = await begin(d.db, jobId, "provision.check", ["provisioning", "active"]);
		if (t.status === "active") return;
		if (t.hosts.length === 0) throw new LifecycleError("no_route");
		const page = await d.bucket.list({ prefix: t.prefix, limit: 1 });
		if (page.objects.length > 0) throw new LifecycleError("prefix_not_empty");
	},
	async provisionBootstrap(d: LifecycleDeps, jobId: string): Promise<void> {
		const { t } = await begin(d.db, jobId, "provision.bootstrap", ["provisioning", "active"]);
		if (t.status === "provisioning") await d.container.bootstrap(t.doName);
	},
	async provisionClaim(d: LifecycleDeps, jobId: string): Promise<void> {
		const { j, t } = await begin(d.db, jobId, "provision.claim", ["provisioning", "active"]);
		if (t.status === "provisioning") await d.claim.create(t.doName, j.id);
	},
	async provisionActivate(d: LifecycleDeps, jobId: string): Promise<void> {
		const { j, t } = await begin(d.db, jobId, "provision.activate", ["provisioning", "active"]);
		await setStatus(d.db, j, t, "active", ["provisioning"]);
		await d.routeCache.purge(t.hosts);
	},
	async suspendStatus(d: LifecycleDeps, jobId: string): Promise<void> {
		const { j, t } = await begin(d.db, jobId, "suspend.status", ["active", "suspended"]);
		await setStatus(d.db, j, t, "suspended", ["active"]);
		await d.routeCache.purge(t.hosts);
	},
	async suspendStop(d: LifecycleDeps, jobId: string): Promise<void> {
		const { t } = await begin(d.db, jobId, "suspend.stop", ["suspended"]);
		await d.container.stop(t.doName);
	},
	async resumeStatus(d: LifecycleDeps, jobId: string): Promise<void> {
		const { j, t } = await begin(d.db, jobId, "resume.status", ["suspended", "active"]);
		await setStatus(d.db, j, t, "active", ["suspended"]);
		await d.routeCache.purge(t.hosts);
	},
	/** The app's backup, once per job (the job id is the backup id), recorded on the job. The download link is the
	 *  console's (E6), not made here. */
	async exportBackup(d: LifecycleDeps, jobId: string): Promise<{ key: string }> {
		const { j, t } = await begin(d.db, jobId, "export.backup", ["active", "suspended", "deleting"]);
		const done = j.result_json ? (JSON.parse(j.result_json) as { key?: string }).key : undefined;
		if (done) return { key: done };
		const { key } = await d.container.backup(t.doName, j.id);
		if (typeof key !== "string" || !key.startsWith(t.prefix) || key.length === t.prefix.length) throw new LifecycleError("bad_export");
		const params = j.params_json ? (JSON.parse(j.params_json) as { retention_days?: number }) : {};
		await d.db.batch([
			d.db
				.prepare(`UPDATE lifecycle_jobs SET result_json = ?, updated_at = ${NOW} WHERE id = ? AND result_json IS NULL`)
				.bind(JSON.stringify({ key, ...(params.retention_days ? { retention_days: params.retention_days } : {}) }), j.id),
			audit(d.db, j, "export"),
		]);
		return { key };
	},
	/** Soft delete: routing answers 410 from here on. Nothing is removed until a separate, confirmed purge job. */
	async deleteStatus(d: LifecycleDeps, jobId: string): Promise<void> {
		const { j, t } = await begin(d.db, jobId, "delete.status", ["provisioning", "active", "suspended", "deleting"]);
		await setStatus(d.db, j, t, "deleting", ["provisioning", "active", "suspended"]);
		await d.routeCache.purge(t.hosts);
	},
	async deleteStop(d: LifecycleDeps, jobId: string): Promise<void> {
		const { t } = await begin(d.db, jobId, "delete.stop", ["deleting"]);
		await d.container.stop(t.doName);
	},
	/** Purge page `page`, phase 1: aborts up to 100 unfinished multipart uploads that have a marker under the prefix and
	 *  deletes their markers. Returns how many markers it handled; 0 means none are left. */
	purgeUploads(d: LifecycleDeps, jobId: string, page: number): Promise<{ count: number }> {
		return paged(d, jobId, `purge.uploads.${page}`, async (t) => {
			// Every marker counts as expired: the installation is being purged. Handled markers are deleted, so each page lists
			// from the start.
			const r = await sweepExpiredMultipartUploads(d.bucket, t.prefix, Date.now(), { limit: 100, retentionMs: Number.NEGATIVE_INFINITY });
			if (r.interrupted) throw new LifecycleRetry("r2_interrupted");
			return r.markersDeleted;
		});
	},
	/** Purge page `page`, phase 2: deletes the first (at most 1000) keys under the prefix with one bucket.delete. Lists
	 *  from the start, so a retry continues where the last one stopped. Returns how many; 0 means the prefix is empty. */
	purgeObjects(d: LifecycleDeps, jobId: string, page: number): Promise<{ count: number }> {
		return paged(d, jobId, `purge.objects.${page}`, async (t) => {
			const list = await d.bucket.list({ prefix: t.prefix, limit: PURGE_PAGE });
			const keys = list.objects.map((o) => o.key).filter((k) => k.startsWith(t.prefix));
			if (keys.length > 0) await d.bucket.delete(keys);
			return keys.length;
		});
	},
	async purgeStorage(d: LifecycleDeps, jobId: string): Promise<void> {
		const { t } = await begin(d.db, jobId, "purge.storage", ["deleting", "deleted"]);
		await d.installation.purge(t.doName);
		await d.container.purge(t.doName);
	},
	/** Tombstone: the row, its name and its prefix stay, so the DO names are never reused. */
	async purgeStatus(d: LifecycleDeps, jobId: string): Promise<void> {
		const { j, t } = await begin(d.db, jobId, "purge.status", ["deleting", "deleted"]);
		await setStatus(d.db, j, t, "deleted", ["deleting"]);
		await d.routeCache.purge(t.hosts);
	},
};

type Runner = Pick<WorkflowStep, "do">;

/** Runs a confirmed job's steps in order, each as its own Workflow step. A failure marks the job failed and rethrows. */
export async function runJob(d: LifecycleDeps, jobId: string, step: Runner): Promise<void> {
	try {
		const kind = await step.do("start", () => startJob(d.db, jobId));
		const one = (name: string, fn: (d: LifecycleDeps, jobId: string) => Promise<unknown>) => step.do(name, async () => void (await fn(d, jobId)));
		switch (kind) {
			case "provision":
				await one("provision.check", steps.provisionCheck);
				await one("provision.bootstrap", steps.provisionBootstrap);
				await one("provision.claim", steps.provisionClaim);
				await one("provision.activate", steps.provisionActivate);
				break;
			case "suspend":
				await one("suspend.status", steps.suspendStatus);
				await one("suspend.stop", steps.suspendStop);
				break;
			case "resume":
				await one("resume.status", steps.resumeStatus);
				break;
			case "export":
				await one("export.backup", steps.exportBackup);
				break;
			case "delete":
				await one("delete.status", steps.deleteStatus);
				await one("delete.stop", steps.deleteStop);
				await one("delete.export", steps.exportBackup);
				break;
			case "purge":
				for (let i = 0; (await step.do(`purge.uploads.${i}`, () => steps.purgeUploads(d, jobId, i))).count > 0; i++);
				for (let i = 0; (await step.do(`purge.objects.${i}`, () => steps.purgeObjects(d, jobId, i))).count > 0; i++);
				await one("purge.storage", steps.purgeStorage);
				await one("purge.status", steps.purgeStatus);
				break;
		}
		await step.do("done", () => finishJob(d.db, jobId));
	} catch (e) {
		const code = e instanceof LifecycleError || e instanceof LifecycleRetry ? e.code : "error";
		await step.do("failed", () => failJob(d.db, jobId, code));
		throw e;
	}
}
