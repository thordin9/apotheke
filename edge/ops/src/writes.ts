// The console's writes under /api/ops/v1 (docs/operator-console.md#writes): plan limits, a subscription's plan and
// status, lifecycle jobs (create and confirm) and per-installation limit overrides. The caller is already an operator
// and has passed the CSRF check (index.ts). primary is refused by every write (403 primary), by id before any lookup
// and again by its row. Each D1 change is written with its operator_audit row in one D1 batch; an audit row holds
// control-plane fields only (audit.ts refuses anything that looks like a credential). A body is a JSON object of at
// most 4 KiB; an unknown or invalid field is 422 invalid_body with the field as detail.
import { failure, json, PRIMARY, readBody, type ApiEnv, type Operator } from "./api";
import { auditStatement, type AuditDb } from "./audit";

/** plan_limits.limit_key and limit_overrides.limit_key (edge/migrations/control 0004, 0005). */
export const LIMIT_KEYS = [
	"items",
	"storage_bytes",
	"photos",
	"users",
	"autofill_calls_month",
	"requests_month",
	"container_hours_month",
	"sleep_after_ms",
	"import_file_bytes",
	"photos_per_item",
] as const;
const SUBSCRIPTION_STATUSES = ["trial", "active", "past_due", "cancelled"] as const;
/** lifecycle_jobs.kind (edge/src/lifecycle.ts JOB_KINDS). */
export const JOB_KINDS = ["provision", "suspend", "resume", "export", "delete", "purge"] as const;
/** The longest retention a delete job may keep its export, in days. */
export const MAX_RETENTION_DAYS = 3650;
const MAX_REASON = 200;
const NOW = "strftime('%Y-%m-%dT%H:%M:%SZ', 'now')";

/** apotheke-ops's binding to the lifecycle Workflow (edge/src/lifecycle-workflow.ts), when one is declared. It is not
 *  declared in edge/ops/wrangler.jsonc yet: without it, confirming a job is 503 not_configured. */
export type LifecycleWorkflowBinding = {
	create(options: { id: string; params: { jobId: string } }): Promise<unknown>;
	get(id: string): Promise<unknown>;
};
export type WritesEnv = ApiEnv & { LIFECYCLE?: LifecycleWorkflowBinding };
type Handler = (request: Request, env: WritesEnv, op: Operator, nowMs: number) => Promise<Response>;

class Invalid extends Error {
	readonly field: string;
	constructor(field: string) {
		super(field);
		this.field = field;
	}
}

const invalid = (field: string) => failure(422, "invalid_body", { detail: field.slice(0, 64) });

function decodeSegment(raw: string): string | null {
	let v: string;
	try {
		v = decodeURIComponent(raw);
	} catch {
		return null;
	}
	return v === "" || v.length > 128 || /[\u0000-\u001f\u007f]/.test(v) ? null : v;
}

/** Only these fields; anything else is invalid. */
function onlyFields(body: Record<string, unknown>, allowed: readonly string[]): void {
	for (const k of Object.keys(body)) if (!allowed.includes(k)) throw new Invalid(k);
}

const isCount = (v: unknown): v is number => typeof v === "number" && Number.isSafeInteger(v) && v >= 0;

function optionalText(body: Record<string, unknown>, field: string, max: number): string | null {
	const v = body[field];
	if (v === undefined || v === null) return null;
	if (typeof v !== "string" || v.length > max || /[\u0000-\u001f\u007f]/.test(v)) throw new Invalid(field);
	return v === "" ? null : v;
}

/** A real calendar date "YYYY-MM-DD" or UTC date-time "YYYY-MM-DDTHH:MM:SSZ", as stored. */
function isDate(v: string, withTime: boolean): boolean {
	const re = withTime ? /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/ : /^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}Z)?$/;
	if (!re.test(v)) return false;
	const iso = v.length === 10 ? `${v}T00:00:00Z` : v;
	const t = Date.parse(iso);
	return !Number.isNaN(t) && new Date(t).toISOString().slice(0, 19) + "Z" === iso;
}

type InstallationRow = { id: string; do_name: string; status: string };

/** The installation a write may touch. primary: 403 (by id before any lookup, again by its row). Unknown: 404.
 *  deleted: 410 gone. */
async function writable(db: AuditDb, rawId: string): Promise<InstallationRow | Response> {
	const id = decodeSegment(rawId);
	if (id === null) return failure(404, "not_found");
	if (id === PRIMARY) return failure(403, "primary");
	const row = await db.prepare("SELECT id, do_name, status FROM installations WHERE id = ?").bind(id).first<InstallationRow>();
	if (!row) return failure(404, "not_found");
	if (row.id === PRIMARY || row.do_name === PRIMARY) return failure(403, "primary");
	if (row.status === "deleted") return failure(410, "gone");
	return row;
}

type LimitRow = { limit_key: string; soft_value: number | null; hard_value: number | null };

async function planLimits(db: AuditDb, planId: string): Promise<LimitRow[]> {
	const { results } = await db.prepare("SELECT limit_key, soft_value, hard_value FROM plan_limits WHERE plan_id = ? ORDER BY limit_key").bind(planId).all<LimitRow>();
	return results;
}

const limitMap = (rows: LimitRow[]) => Object.fromEntries(rows.map((r) => [r.limit_key, { soft_value: r.soft_value, hard_value: r.hard_value }]));

// ---- PUT /plans/:id/limits ----------------------------------------------------------------------------------------

/** The new limits of a plan, replacing all of its rows: {"limits": {"<key>": {"soft_value": n|null, "hard_value":
 *  n|null} | null}}. A key left out, null, or with both values null has no row (no limit). Values are non-negative
 *  integers; soft_value is at most hard_value. The plan primary's subscription uses is refused (403 primary). */
async function putPlanLimits(request: Request, env: WritesEnv, op: Operator, rawId: string): Promise<Response> {
	const db = env.DB;
	if (!db) return failure(503, "not_configured");
	const planId = decodeSegment(rawId);
	if (planId === null) return failure(404, "not_found");
	const plan = await db.prepare("SELECT id FROM plans WHERE id = ?").bind(planId).first<{ id: string }>();
	if (!plan) return failure(404, "not_found");
	const primaryPlan = await db.prepare("SELECT plan_id FROM subscriptions WHERE installation_id = ?").bind(PRIMARY).first<{ plan_id: string }>();
	if (primaryPlan?.plan_id === planId) return failure(403, "primary");
	const body = await readBody(request);
	if (!body) return failure(422, "invalid_body");
	const rows: LimitRow[] = [];
	try {
		onlyFields(body, ["limits"]);
		const limits = body.limits;
		if (limits === null || typeof limits !== "object" || Array.isArray(limits)) throw new Invalid("limits");
		for (const [key, value] of Object.entries(limits as Record<string, unknown>)) {
			if (!(LIMIT_KEYS as readonly string[]).includes(key)) throw new Invalid(`limits.${key}`);
			if (value === null) continue;
			if (typeof value !== "object" || Array.isArray(value)) throw new Invalid(`limits.${key}`);
			const v = value as Record<string, unknown>;
			for (const f of Object.keys(v)) if (f !== "soft_value" && f !== "hard_value") throw new Invalid(`limits.${key}.${f}`);
			const soft = v.soft_value ?? null;
			const hard = v.hard_value ?? null;
			if (soft !== null && !isCount(soft)) throw new Invalid(`limits.${key}.soft_value`);
			if (hard !== null && !isCount(hard)) throw new Invalid(`limits.${key}.hard_value`);
			if (soft !== null && hard !== null && soft > hard) throw new Invalid(`limits.${key}.soft_value`);
			if (soft === null && hard === null) continue;
			rows.push({ limit_key: key, soft_value: soft as number | null, hard_value: hard as number | null });
		}
	} catch (e) {
		if (e instanceof Invalid) return invalid(e.field);
		throw e;
	}
	rows.sort((a, b) => (a.limit_key < b.limit_key ? -1 : 1));
	const before = await planLimits(db, planId);
	await db.batch([
		db.prepare("DELETE FROM plan_limits WHERE plan_id = ?").bind(planId),
		...rows.map((r) => db.prepare("INSERT INTO plan_limits (plan_id, limit_key, soft_value, hard_value) VALUES (?, ?, ?, ?)").bind(planId, r.limit_key, r.soft_value, r.hard_value)),
		auditStatement(db, {
			operator: op.email,
			action: "plan.limits",
			target: null,
			before: { plan_id: planId, limits: limitMap(before) },
			after: { plan_id: planId, limits: limitMap(rows) },
			requestId: op.requestId,
		}),
	]);
	return json(200, { ok: true, plan_id: planId, limits: (await planLimits(db, planId)).map((r) => ({ plan_id: planId, ...r })) });
}

// ---- PUT /installations/:id/subscription --------------------------------------------------------------------------

type SubscriptionRow = { installation_id: string; plan_id: string; status: string; trial_end: string | null; period_end: string | null };
const SUBSCRIPTION_COLS = "installation_id, plan_id, status, trial_end, period_end, updated_at";

/** The keys whose hard value the new plan sets lower than the current one (no row or no hard value is no limit). */
function lowerHardLimits(from: LimitRow[], to: LimitRow[]): string[] {
	const old = new Map(from.map((r) => [r.limit_key, r.hard_value]));
	return to
		.filter((r) => r.hard_value !== null && (old.get(r.limit_key) == null || (r.hard_value as number) < (old.get(r.limit_key) as number)))
		.map((r) => r.limit_key)
		.sort();
}

/** Sets an installation's subscription: {"plan_id", "status", "trial_end", "period_end", "confirm"}, each optional
 *  (an installation without a subscription needs plan_id and status). A plan change to a plan with any lower hard
 *  limit is 409 downgrade_requires_confirm (with keys) unless confirm is true. */
async function putSubscription(request: Request, env: WritesEnv, op: Operator, rawId: string): Promise<Response> {
	const db = env.DB;
	if (!db) return failure(503, "not_configured");
	const inst = await writable(db, rawId);
	if (inst instanceof Response) return inst;
	const body = await readBody(request);
	if (!body) return failure(422, "invalid_body");
	const current = await db.prepare(`SELECT ${SUBSCRIPTION_COLS} FROM subscriptions WHERE installation_id = ?`).bind(inst.id).first<SubscriptionRow & { updated_at: string }>();
	let next: SubscriptionRow;
	let confirm: boolean;
	try {
		onlyFields(body, ["plan_id", "status", "trial_end", "period_end", "confirm"]);
		if (!["plan_id", "status", "trial_end", "period_end"].some((k) => k in body)) throw new Invalid("plan_id");
		if (body.plan_id !== undefined && (typeof body.plan_id !== "string" || decodeSegment(body.plan_id) === null)) throw new Invalid("plan_id");
		if (body.status !== undefined && !(SUBSCRIPTION_STATUSES as readonly unknown[]).includes(body.status)) throw new Invalid("status");
		for (const f of ["trial_end", "period_end"]) {
			const v = body[f];
			if (v !== undefined && v !== null && (typeof v !== "string" || !isDate(v, false))) throw new Invalid(f);
		}
		if (body.confirm !== undefined && typeof body.confirm !== "boolean") throw new Invalid("confirm");
		confirm = body.confirm === true;
		const plan = (body.plan_id as string | undefined) ?? current?.plan_id;
		const status = (body.status as string | undefined) ?? current?.status;
		if (plan === undefined) throw new Invalid("plan_id");
		if (status === undefined) throw new Invalid("status");
		const date = (f: "trial_end" | "period_end") => (body[f] === undefined ? (current?.[f] ?? null) : (body[f] as string | null));
		next = { installation_id: inst.id, plan_id: plan, status, trial_end: date("trial_end"), period_end: date("period_end") };
	} catch (e) {
		if (e instanceof Invalid) return invalid(e.field);
		throw e;
	}
	if (next.plan_id !== current?.plan_id) {
		const plan = await db.prepare("SELECT id, active FROM plans WHERE id = ?").bind(next.plan_id).first<{ id: string; active: number }>();
		if (!plan) return failure(422, "unknown_plan");
		if (Number(plan.active) !== 1) return failure(409, "plan_inactive");
		if (current) {
			const keys = lowerHardLimits(await planLimits(db, current.plan_id), await planLimits(db, next.plan_id));
			if (keys.length > 0 && !confirm) return failure(409, "downgrade_requires_confirm", { keys });
		}
	}
	const before = current ? { plan_id: current.plan_id, status: current.status, trial_end: current.trial_end, period_end: current.period_end } : null;
	const after = { plan_id: next.plan_id, status: next.status, trial_end: next.trial_end, period_end: next.period_end };
	await db.batch([
		db
			.prepare(
				`INSERT INTO subscriptions (installation_id, plan_id, status, trial_end, period_end) VALUES (?, ?, ?, ?, ?)
				ON CONFLICT (installation_id) DO UPDATE SET plan_id = excluded.plan_id, status = excluded.status,
				trial_end = excluded.trial_end, period_end = excluded.period_end, updated_at = ${NOW}`,
			)
			.bind(inst.id, next.plan_id, next.status, next.trial_end, next.period_end),
		auditStatement(db, { operator: op.email, action: "subscription.update", target: inst.id, before, after, requestId: op.requestId }),
	]);
	const row = await db.prepare(`SELECT ${SUBSCRIPTION_COLS} FROM subscriptions WHERE installation_id = ?`).bind(inst.id).first();
	return json(200, { ok: true, subscription: row });
}

// ---- POST /jobs, POST /jobs/:id/confirm ---------------------------------------------------------------------------

const JOB_COLS =
	"id, installation_id, kind, idempotency_key, state, step, params_json, result_json, error_code, created_by, confirmed_by, confirmed_at, created_at, updated_at";
type JobRow = { id: string; installation_id: string; kind: string; state: string };

/** The same check as edge/src/lifecycle.ts retentionOver: a finished delete job with an export whose retention ran out. */
async function retentionOver(db: AuditDb, installationId: string): Promise<boolean> {
	const row = await db
		.prepare(`SELECT 1 AS ok FROM lifecycle_jobs WHERE installation_id = ? AND kind = 'delete' AND state = 'done'
			AND json_extract(result_json, '$.key') IS NOT NULL AND CAST(json_extract(params_json, '$.retention_days') AS INTEGER) >= 1
			AND ${NOW} >= strftime('%Y-%m-%dT%H:%M:%SZ', updated_at, '+' || CAST(json_extract(params_json, '$.retention_days') AS INTEGER) || ' days')
			LIMIT 1`)
		.bind(installationId)
		.first<{ ok: number }>();
	return row !== null;
}

/** A job's audit row, id <job id>:<action> as lifecycle.ts writes them, inserted only when the condition holds (so a
 *  repeated request writes none). */
export function jobAudit(db: AuditDb, jobId: string, kind: string, action: string, installationId: string, op: Operator, after: unknown, condition: string, args: unknown[]) {
	const id = `${jobId}:${action}`;
	const afterJson = JSON.stringify(after);
	if (/"(?:token|link|claim_token|password|secret)"\s*:/i.test(afterJson)) throw new Error("audit: refused a value that looks like a credential");
	return db
		.prepare(`INSERT INTO operator_audit (id, operator, action, installation_id, after_json, request_id) SELECT ?, ?, ?, ?, ?, ?
			WHERE NOT EXISTS (SELECT 1 FROM operator_audit WHERE id = ?) AND ${condition}`)
		.bind(id, op.email, `lifecycle.${kind}.${action}`, installationId, afterJson, op.requestId, id, ...args);
}

/** Creates a paused job: {"kind", "installation_id", "nonce", "retention_days"} (retention_days for a delete only, 1 to
 *  3650). The same kind, installation and nonce again returns the same job (200, created false). provision is 403
 *  provisioning_disabled unless OPS_PROVISIONING is "1"; a purge before its delete's retention is over is 409
 *  retention. */
async function createJob(request: Request, env: WritesEnv, op: Operator): Promise<Response> {
	const db = env.DB;
	if (!db) return failure(503, "not_configured");
	const body = await readBody(request);
	if (!body) return failure(422, "invalid_body");
	let kind: string, nonce: string, retention: number | null;
	try {
		onlyFields(body, ["kind", "installation_id", "nonce", "retention_days"]);
		if (!(JOB_KINDS as readonly unknown[]).includes(body.kind)) throw new Invalid("kind");
		kind = body.kind as string;
		if (typeof body.installation_id !== "string") throw new Invalid("installation_id");
		if (typeof body.nonce !== "string" || !/^[A-Za-z0-9_-]{8,128}$/.test(body.nonce)) throw new Invalid("nonce");
		nonce = body.nonce;
		retention = null;
		if (kind === "delete") {
			const r = body.retention_days;
			if (typeof r !== "number" || !Number.isSafeInteger(r) || r < 1 || r > MAX_RETENTION_DAYS) throw new Invalid("retention_days");
			retention = r;
		} else if (body.retention_days !== undefined) throw new Invalid("retention_days");
	} catch (e) {
		if (e instanceof Invalid) return invalid(e.field);
		throw e;
	}
	if (kind === "provision" && env.OPS_PROVISIONING !== "1") return failure(403, "provisioning_disabled");
	const inst = await writable(db, encodeURIComponent(body.installation_id as string));
	if (inst instanceof Response) return inst;
	if (kind === "purge" && !(await retentionOver(db, inst.id))) return failure(409, "retention");
	const key = `${kind}:${inst.id}:${nonce}`;
	const jobId = crypto.randomUUID();
	const params = retention === null ? null : JSON.stringify({ retention_days: retention });
	await db.batch([
		db
			.prepare("INSERT INTO lifecycle_jobs (id, installation_id, kind, idempotency_key, params_json, created_by) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (idempotency_key) DO NOTHING")
			.bind(jobId, inst.id, kind, key, params, op.email),
		jobAudit(db, jobId, kind, "create", inst.id, op, { job_id: jobId, ...(retention === null ? {} : { retention_days: retention }) }, "EXISTS (SELECT 1 FROM lifecycle_jobs WHERE id = ?)", [jobId]),
	]);
	const job = await db.prepare(`SELECT ${JOB_COLS} FROM lifecycle_jobs WHERE idempotency_key = ?`).bind(key).first<JobRow>();
	if (!job) return failure(503, "unavailable");
	const created = job.id === jobId;
	return json(created ? 201 : 200, { ok: true, created, job });
}

/** Confirms a paused job (state queued, audit lifecycle.<kind>.confirm) and starts its Workflow instance (the job id
 *  is the instance id). Needs the LIFECYCLE binding, else 503 not_configured before anything is read or changed. A
 *  purge needs {"confirm_host"}: one of the installation's hosts. A queued job (confirmed, but its instance did not
 *  start) is started again; running, done or failed is 409 not_paused. */
async function confirmJob(request: Request, env: WritesEnv, op: Operator, rawId: string): Promise<Response> {
	const db = env.DB;
	const workflow = env.LIFECYCLE;
	if (!db || !workflow) return failure(503, "not_configured");
	const jobId = decodeSegment(rawId);
	if (jobId === null) return failure(404, "not_found");
	const body = await readBody(request);
	if (!body) return failure(422, "invalid_body");
	try {
		onlyFields(body, ["confirm_host"]);
		if (body.confirm_host !== undefined && typeof body.confirm_host !== "string") throw new Invalid("confirm_host");
	} catch (e) {
		if (e instanceof Invalid) return invalid(e.field);
		throw e;
	}
	const job = await db.prepare(`SELECT ${JOB_COLS} FROM lifecycle_jobs WHERE id = ?`).bind(jobId).first<JobRow>();
	if (!job) return failure(404, "not_found");
	const inst = await writable(db, encodeURIComponent(job.installation_id));
	if (inst instanceof Response) return inst;
	if (job.kind === "provision" && env.OPS_PROVISIONING !== "1") return failure(403, "provisioning_disabled");
	if (job.state !== "paused" && job.state !== "queued") return failure(409, "not_paused", { detail: job.state });
	if (job.state === "paused") {
		let host: string | undefined;
		if (job.kind === "purge") {
			const confirm = typeof body.confirm_host === "string" ? body.confirm_host.trim().toLowerCase() : "";
			const { results } = await db.prepare("SELECT host FROM installation_routes WHERE installation_id = ?").bind(inst.id).all<{ host: string }>();
			host = results.find((r) => r.host === confirm)?.host;
			if (!host) return failure(422, "confirm_host");
			if (!(await retentionOver(db, inst.id))) return failure(409, "retention");
		}
		await db.batch([
			db
				.prepare(`UPDATE lifecycle_jobs SET state = 'queued', confirmed_by = ?, confirmed_at = ${NOW}, updated_at = ${NOW} WHERE id = ? AND state = 'paused'`)
				.bind(op.email, job.id),
			jobAudit(db, job.id, job.kind, "confirm", inst.id, op, { job_id: job.id, ...(host ? { host } : {}) }, "EXISTS (SELECT 1 FROM lifecycle_jobs WHERE id = ? AND state = 'queued' AND confirmed_by = ?)", [job.id, op.email]),
		]);
	}
	try {
		await workflow.create({ id: job.id, params: { jobId: job.id } });
	} catch (e) {
		// A second confirm (or a retry) finds the instance already there.
		const exists = await workflow.get(job.id).then(
			() => true,
			() => false,
		);
		if (!exists) {
			console.error("ops workflow start failed", op.requestId, e instanceof Error ? e.name : "error");
			return failure(503, "workflow_unavailable");
		}
	}
	return json(200, { ok: true, job: await db.prepare(`SELECT ${JOB_COLS} FROM lifecycle_jobs WHERE id = ?`).bind(job.id).first() });
}

// ---- PUT, DELETE /installations/:id/limits/:key -------------------------------------------------------------------

type OverrideRow = { installation_id: string; limit_key: string; hard_value: number; reason: string | null; expires_at: string | null; created_by: string; created_at: string };
const OVERRIDE_COLS = "installation_id, limit_key, hard_value, reason, expires_at, created_by, created_at";
const overrideAudit = (r: OverrideRow | null) => (r ? { limit_key: r.limit_key, hard_value: r.hard_value, reason: r.reason, expires_at: r.expires_at } : null);

async function overrideTarget(db: AuditDb, rawId: string, rawKey: string): Promise<{ inst: InstallationRow; key: string; current: OverrideRow | null } | Response> {
	const inst = await writable(db, rawId);
	if (inst instanceof Response) return inst;
	const key = decodeSegment(rawKey);
	if (key === null || !(LIMIT_KEYS as readonly string[]).includes(key)) return failure(404, "unknown_limit");
	const current = await db.prepare(`SELECT ${OVERRIDE_COLS} FROM limit_overrides WHERE installation_id = ? AND limit_key = ?`).bind(inst.id, key).first<OverrideRow>();
	return { inst, key, current };
}

/** Grants or replaces an override: {"hard_value", "reason", "expires_at"}. The key must have a hard value on the
 *  installation's plan (else 409 no_plan_limit; no subscription is 409 no_subscription), and hard_value may not be
 *  below it (422 below_plan_limit). expires_at is null (never) or a future "YYYY-MM-DDTHH:MM:SSZ". */
async function putOverride(request: Request, env: WritesEnv, op: Operator, rawId: string, rawKey: string, nowMs: number): Promise<Response> {
	const db = env.DB;
	if (!db) return failure(503, "not_configured");
	const t = await overrideTarget(db, rawId, rawKey);
	if (t instanceof Response) return t;
	const body = await readBody(request);
	if (!body) return failure(422, "invalid_body");
	let hard: number, reason: string | null, expires: string | null;
	try {
		onlyFields(body, ["hard_value", "reason", "expires_at"]);
		if (!isCount(body.hard_value)) throw new Invalid("hard_value");
		hard = body.hard_value;
		reason = optionalText(body, "reason", MAX_REASON);
		expires = body.expires_at === undefined || body.expires_at === null ? null : (body.expires_at as string);
		if (expires !== null && (typeof expires !== "string" || !isDate(expires, true) || Date.parse(expires) <= nowMs)) throw new Invalid("expires_at");
	} catch (e) {
		if (e instanceof Invalid) return invalid(e.field);
		throw e;
	}
	const plan = await db
		.prepare("SELECT s.plan_id AS plan_id, l.hard_value AS hard_value FROM subscriptions s LEFT JOIN plan_limits l ON l.plan_id = s.plan_id AND l.limit_key = ? WHERE s.installation_id = ?")
		.bind(t.key, t.inst.id)
		.first<{ plan_id: string; hard_value: number | null }>();
	if (!plan) return failure(409, "no_subscription");
	if (plan.hard_value === null) return failure(409, "no_plan_limit");
	if (hard < plan.hard_value) return failure(422, "below_plan_limit", { plan_hard_value: plan.hard_value });
	const after = { limit_key: t.key, hard_value: hard, reason, expires_at: expires };
	await db.batch([
		db
			.prepare(
				`INSERT INTO limit_overrides (installation_id, limit_key, hard_value, reason, expires_at, created_by) VALUES (?, ?, ?, ?, ?, ?)
				ON CONFLICT (installation_id, limit_key) DO UPDATE SET hard_value = excluded.hard_value, reason = excluded.reason,
				expires_at = excluded.expires_at, created_by = excluded.created_by, created_at = ${NOW}`,
			)
			.bind(t.inst.id, t.key, hard, reason, expires, op.email),
		auditStatement(db, { operator: op.email, action: "limit_override.set", target: t.inst.id, before: overrideAudit(t.current), after, requestId: op.requestId }),
	]);
	const row = await db.prepare(`SELECT ${OVERRIDE_COLS} FROM limit_overrides WHERE installation_id = ? AND limit_key = ?`).bind(t.inst.id, t.key).first();
	return json(200, { ok: true, override: row });
}

/** Withdraws an override; none for the key is 404 not_found. No body is read. */
async function deleteOverride(env: WritesEnv, op: Operator, rawId: string, rawKey: string): Promise<Response> {
	const db = env.DB;
	if (!db) return failure(503, "not_configured");
	const t = await overrideTarget(db, rawId, rawKey);
	if (t instanceof Response) return t;
	if (!t.current) return failure(404, "not_found");
	await db.batch([
		db.prepare("DELETE FROM limit_overrides WHERE installation_id = ? AND limit_key = ?").bind(t.inst.id, t.key),
		auditStatement(db, { operator: op.email, action: "limit_override.delete", target: t.inst.id, before: overrideAudit(t.current), requestId: op.requestId }),
	]);
	return json(200, { ok: true });
}

// ---- routing --------------------------------------------------------------------------------------------------------

const PLAN_LIMITS_PATH = /^\/plans\/([^/]+)\/limits$/;
const SUBSCRIPTION_PATH = /^\/installations\/([^/]+)\/subscription$/;
const OVERRIDE_PATH = /^\/installations\/([^/]+)\/limits\/([^/]+)$/;
const CONFIRM_PATH = /^\/jobs\/([^/]+)\/confirm$/;

const notAllowed = (allow: string) => json(405, { ok: false, code: "method_not_allowed" }, { Allow: allow });

/** The write for path and method (path below API_PREFIX): a handler, a 405 for a write path with another method, or
 *  null when path is not a write path (GET and HEAD /jobs are the jobs read). */
export function writeRoute(path: string, method: string): Handler | Response | null {
	if (path === "/jobs") {
		if (method === "GET" || method === "HEAD") return null;
		return method === "POST" ? (req, env, op) => createJob(req, env, op) : notAllowed("GET, HEAD, POST");
	}
	let m = CONFIRM_PATH.exec(path);
	if (m) {
		const id = m[1];
		return method === "POST" ? (req, env, op) => confirmJob(req, env, op, id) : notAllowed("POST");
	}
	m = PLAN_LIMITS_PATH.exec(path);
	if (m) {
		const id = m[1];
		return method === "PUT" ? (req, env, op) => putPlanLimits(req, env, op, id) : notAllowed("PUT");
	}
	m = SUBSCRIPTION_PATH.exec(path);
	if (m) {
		const id = m[1];
		return method === "PUT" ? (req, env, op) => putSubscription(req, env, op, id) : notAllowed("PUT");
	}
	m = OVERRIDE_PATH.exec(path);
	if (m) {
		const [, id, key] = m;
		if (method === "PUT") return (req, env, op, now) => putOverride(req, env, op, id, key, now);
		if (method === "DELETE") return (_req, env, op) => deleteOverride(env, op, id, key);
		return notAllowed("PUT, DELETE");
	}
	return null;
}
