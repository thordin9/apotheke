// The console's reads under /api/ops/v1 (docs/operator-console.md#implemented): GET or HEAD only, control-plane D1
// rows only. Nothing here returns a token or a secret: the control plane stores none, and audit rows refuse them
// (audit.ts). A bad query value is 422 invalid_query with the parameter as detail; unknown parameters are ignored.
// Lists are paged with limit (1 to 100, default 50) and an opaque cursor; next_cursor is null on the last page.
// Installations are in id order, jobs and audit rows newest first.
import { failure, json, PRIMARY } from "./api";

export type ReadDb = Pick<D1Database, "prepare">;

const INSTALLATION_STATUSES = ["provisioning", "active", "suspended", "deleting", "deleted"] as const;
const SUBSCRIPTION_STATUSES = ["trial", "active", "past_due", "cancelled"] as const;
const JOB_KINDS = ["provision", "suspend", "resume", "export", "delete", "purge"] as const;
const JOB_STATES = ["paused", "queued", "running", "done", "failed"] as const;
/** Jobs that have not finished: the overview counts these. */
const OPEN_JOB_STATES = ["paused", "queued", "running"] as const;

export const DEFAULT_PAGE = 50;
export const MAX_PAGE = 100;
/** Jobs and audit rows on an installation's detail. */
export const DETAIL_ROWS = 20;
/** Usage counter rows on an installation's detail (newest periods first). */
const DETAIL_USAGE_ROWS = 120;
/** Rows of GET /subscriptions. */
const MAX_DUE_ROWS = 500;
export const MAX_DUE_DAYS = 365;
export const DEFAULT_DUE_DAYS = 14;
/** Rows of GET /audit.csv; X-Truncated: 1 when more match. */
export const CSV_MAX_ROWS = 10_000;

class BadQuery extends Error {
	readonly param: string;
	constructor(param: string) {
		super(param);
		this.param = param;
	}
}

/** A plain text value: at most max characters, no control characters. "" or absent is null. */
function text(q: URLSearchParams, name: string, max = 128): string | null {
	const v = q.get(name);
	if (v === null || v === "") return null;
	if (v.length > max || /[\u0000-\u001f\u007f]/.test(v)) throw new BadQuery(name);
	return v;
}

function oneOf<T extends string>(q: URLSearchParams, name: string, allowed: readonly T[]): T | null {
	const v = q.get(name);
	if (v === null || v === "") return null;
	if (!(allowed as readonly string[]).includes(v)) throw new BadQuery(name);
	return v as T;
}

function pageSize(q: URLSearchParams): number {
	const v = q.get("limit");
	if (v === null || v === "") return DEFAULT_PAGE;
	if (!/^\d{1,3}$/.test(v) || Number(v) < 1 || Number(v) > MAX_PAGE) throw new BadQuery("limit");
	return Number(v);
}

/** The cursor is base64url JSON of the last row's sort keys. */
function encodeCursor(keys: string[]): string {
	return btoa(JSON.stringify(keys)).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

function cursor(q: URLSearchParams, parts: number): string[] | null {
	const v = q.get("cursor");
	if (v === null || v === "") return null;
	try {
		if (v.length > 1024 || !/^[A-Za-z0-9_-]+$/.test(v)) throw new Error();
		const keys = JSON.parse(atob(v.replace(/-/g, "+").replace(/_/g, "/") + "=".repeat((4 - (v.length % 4)) % 4)));
		if (!Array.isArray(keys) || keys.length !== parts || !keys.every((k) => typeof k === "string" && k.length <= 256)) throw new Error();
		return keys as string[];
	} catch {
		throw new BadQuery("cursor");
	}
}

/** An ISO date (2026-11-20) or UTC date-time (2026-11-20T10:00:00Z, seconds and Z optional) as operator_audit.at
 *  writes it (YYYY-MM-DDTHH:MM:SSZ). */
function instant(q: URLSearchParams, name: string): string | null {
	const v = q.get(name);
	if (v === null || v === "") return null;
	const m = /^(\d{4}-\d{2}-\d{2})(?:T(\d{2}:\d{2})(:\d{2})?Z?)?$/.exec(v);
	const iso = m ? `${m[1]}T${m[2] ?? "00:00"}${m[3] ?? ":00"}Z` : "";
	const t = Date.parse(iso);
	if (!m || Number.isNaN(t) || new Date(t).toISOString().slice(0, 19) + "Z" !== iso) throw new BadQuery(name);
	return iso;
}

const isPrimary = (row: { id: string; do_name: string }) => row.id === PRIMARY || row.do_name === PRIMARY;

/** Splits "p_x" columns of a joined row into objects by prefix; an object whose key column is null is null. */
function split(row: Record<string, unknown>, prefix: string, key: string): Record<string, unknown> | null {
	const out: Record<string, unknown> = {};
	for (const [k, v] of Object.entries(row)) if (k.startsWith(prefix)) out[k.slice(prefix.length)] = v;
	return out[key] === null || out[key] === undefined ? null : out;
}

const INSTALLATION_COLS = "i.id AS i_id, i.do_name AS i_do_name, i.r2_prefix AS i_r2_prefix, i.status AS i_status, " +
	"i.owner_contact AS i_owner_contact, i.notes AS i_notes, i.created_at AS i_created_at, i.updated_at AS i_updated_at";
const SUBSCRIPTION_COLS = "s.installation_id AS s_installation_id, s.plan_id AS s_plan_id, s.status AS s_status, " +
	"s.trial_end AS s_trial_end, s.period_end AS s_period_end, s.notes AS s_notes, s.updated_at AS s_updated_at";
const PLAN_COLS = "p.id AS p_id, p.name AS p_name, p.active AS p_active, p.created_at AS p_created_at";
const SUMMARY_FROM = `SELECT ${INSTALLATION_COLS}, ${SUBSCRIPTION_COLS}, ${PLAN_COLS} FROM installations i ` +
	"LEFT JOIN subscriptions s ON s.installation_id = i.id LEFT JOIN plans p ON p.id = s.plan_id";

type Route = { host: string; installation_id: string; created_at: string };

async function routesFor(db: ReadDb, ids: string[]): Promise<Map<string, Route[]>> {
	const byId = new Map<string, Route[]>(ids.map((id) => [id, []]));
	if (ids.length === 0) return byId;
	// One bound parameter for any number of ids (D1 allows 100 per query).
	const { results } = await db
		.prepare("SELECT host, installation_id, created_at FROM installation_routes WHERE installation_id IN (SELECT value FROM json_each(?)) ORDER BY host")
		.bind(JSON.stringify(ids))
		.all<Route>();
	for (const r of results) byId.get(r.installation_id)?.push(r);
	return byId;
}

function summary(row: Record<string, unknown>, routes: Map<string, Route[]>) {
	const installation = split(row, "i_", "id") as { id: string; do_name: string };
	return {
		installation,
		routes: routes.get(installation.id) ?? [],
		subscription: split(row, "s_", "installation_id"),
		plan: split(row, "p_", "id"),
		// primary is listed and readable, never actionable (the actions refuse it).
		primary: isPrimary(installation),
	};
}

async function overview(db: ReadDb, nowMs: number): Promise<Response> {
	const period = new Date(nowMs).toISOString().slice(0, 7);
	const [byStatus, byPlan, subs, jobs, usage] = await Promise.all([
		db.prepare("SELECT status, COUNT(*) AS count FROM installations GROUP BY status").all<{ status: string; count: number }>(),
		db.prepare("SELECT p.id AS plan_id, p.name AS plan_name, COUNT(s.installation_id) AS count FROM plans p LEFT JOIN subscriptions s ON s.plan_id = p.id GROUP BY p.id ORDER BY p.id").all<{ plan_id: string; plan_name: string; count: number }>(),
		db.prepare("SELECT status, COUNT(*) AS count FROM subscriptions GROUP BY status").all<{ status: string; count: number }>(),
		db.prepare("SELECT state, COUNT(*) AS count FROM lifecycle_jobs WHERE state IN ('paused', 'queued', 'running') GROUP BY state").all<{ state: string; count: number }>(),
		db.prepare("SELECT metric, SUM(value) AS value FROM usage_counters WHERE period = ? GROUP BY metric ORDER BY metric").bind(period).all<{ metric: string; value: number }>(),
	]);
	const counts = <T extends string>(all: readonly T[], rows: Record<string, unknown>[], key: string) =>
		all.map((v) => ({ [key]: v, count: Number(rows.find((r) => r[key] === v)?.count ?? 0) }));
	return json(200, {
		ok: true,
		// No billing provider and no prices: always null.
		revenue: null,
		// The stats freshness stamp; null until the stats Cron writes one.
		as_of: null,
		installations_by_status: counts(INSTALLATION_STATUSES, byStatus.results, "status"),
		installations_by_plan: byPlan.results.map((r) => ({ ...r, count: Number(r.count) })),
		subscriptions_by_status: counts(SUBSCRIPTION_STATUSES, subs.results, "status"),
		open_jobs_by_state: counts(OPEN_JOB_STATES, jobs.results, "state"),
		usage_period: period,
		usage_totals: usage.results.map((r) => ({ metric: r.metric, value: Number(r.value) })),
	});
}

async function installations(db: ReadDb, q: URLSearchParams): Promise<Response> {
	const status = oneOf(q, "status", INSTALLATION_STATUSES);
	const plan = text(q, "plan", 64);
	const search = text(q, "q", 253)?.trim().toLowerCase() ?? "";
	const limit = pageSize(q);
	const after = cursor(q, 1);
	const where: string[] = [];
	const args: unknown[] = [];
	if (status) where.push("i.status = ?"), args.push(status);
	if (plan) where.push("s.plan_id = ?"), args.push(plan);
	if (search) {
		where.push("EXISTS (SELECT 1 FROM installation_routes r WHERE r.installation_id = i.id AND instr(r.host, ?) > 0)");
		args.push(search);
	}
	if (after) where.push("i.id > ?"), args.push(after[0]);
	const sql = `${SUMMARY_FROM}${where.length ? " WHERE " + where.join(" AND ") : ""} ORDER BY i.id LIMIT ?`;
	const { results } = await db.prepare(sql).bind(...args, limit + 1).all<Record<string, unknown>>();
	const page = results.slice(0, limit);
	const routes = await routesFor(db, page.map((r) => r.i_id as string));
	const last = page[page.length - 1];
	return json(200, {
		ok: true,
		installations: page.map((r) => summary(r, routes)),
		next_cursor: results.length > limit && last ? encodeCursor([last.i_id as string]) : null,
	});
}

async function installation(db: ReadDb, rawId: string): Promise<Response> {
	let id: string;
	try {
		id = decodeURIComponent(rawId);
	} catch {
		return failure(404, "not_found");
	}
	if (id === "" || id.length > 128 || /[\u0000-\u001f\u007f]/.test(id)) return failure(404, "not_found");
	const row = await db.prepare(`${SUMMARY_FROM} WHERE i.id = ?`).bind(id).first<Record<string, unknown>>();
	if (!row) return failure(404, "not_found");
	const planId = row.p_id as string | null;
	const [routes, limits, usage, jobs, audit] = await Promise.all([
		routesFor(db, [id]),
		planId
			? db.prepare("SELECT plan_id, limit_key, soft_value, hard_value FROM plan_limits WHERE plan_id = ? ORDER BY limit_key").bind(planId).all()
			: Promise.resolve({ results: [] }),
		db.prepare("SELECT installation_id, period, metric, value FROM usage_counters WHERE installation_id = ? ORDER BY period DESC, metric LIMIT ?").bind(id, DETAIL_USAGE_ROWS).all(),
		db.prepare(`SELECT ${JOB_COLS} FROM lifecycle_jobs WHERE installation_id = ? ORDER BY created_at DESC, id DESC LIMIT ?`).bind(id, DETAIL_ROWS).all(),
		db.prepare(`SELECT ${AUDIT_COLS} FROM operator_audit WHERE installation_id = ? ORDER BY at DESC, id DESC LIMIT ?`).bind(id, DETAIL_ROWS).all(),
	]);
	return json(200, { ok: true, ...summary(row, routes), limits: limits.results, usage: usage.results, jobs: jobs.results, audit: audit.results });
}

async function plans(db: ReadDb): Promise<Response> {
	const [p, l] = await Promise.all([
		db.prepare("SELECT id, name, active, created_at FROM plans ORDER BY id").all<{ id: string }>(),
		db.prepare("SELECT plan_id, limit_key, soft_value, hard_value FROM plan_limits ORDER BY plan_id, limit_key").all<{ plan_id: string }>(),
	]);
	return json(200, { ok: true, plans: p.results.map((plan) => ({ ...plan, limits: l.results.filter((x) => x.plan_id === plan.id) })) });
}

/** due=N or Nd: 0 to 365 days, default 14. A subscription is due on its trial_end while trial, else its period_end. */
async function subscriptionsDue(db: ReadDb, q: URLSearchParams, nowMs: number): Promise<Response> {
	const raw = q.get("due");
	let days = DEFAULT_DUE_DAYS;
	if (raw !== null && raw !== "") {
		const m = /^(\d{1,3})d?$/.exec(raw);
		if (!m || Number(m[1]) > MAX_DUE_DAYS) throw new BadQuery("due");
		days = Number(m[1]);
	}
	const now = new Date(nowMs).toISOString().slice(0, 19) + "Z";
	const until = new Date(nowMs + days * 86_400_000).toISOString().slice(0, 19) + "Z";
	const { results } = await db
		.prepare(
			"SELECT * FROM (SELECT s.installation_id, s.plan_id, p.name AS plan_name, s.status, s.trial_end, s.period_end, s.notes, s.updated_at, " +
				"CASE WHEN s.status = 'trial' THEN s.trial_end ELSE s.period_end END AS due_at FROM subscriptions s LEFT JOIN plans p ON p.id = s.plan_id " +
				"WHERE s.status <> 'cancelled') WHERE julianday(due_at) IS NOT NULL AND julianday(due_at) <= julianday(?) " +
				"ORDER BY julianday(due_at), installation_id LIMIT ?",
		)
		.bind(until, MAX_DUE_ROWS + 1)
		.all<Record<string, unknown> & { due_at: string; installation_id: string }>();
	const page = results.slice(0, MAX_DUE_ROWS);
	const routes = await routesFor(db, page.map((r) => r.installation_id));
	return json(200, {
		ok: true,
		due_days: days,
		now,
		subscriptions: page.map((r) => ({ ...r, hosts: (routes.get(r.installation_id) ?? []).map((x) => x.host), overdue: julian(r.due_at) < julian(now) })),
		truncated: results.length > MAX_DUE_ROWS,
	});
}

/** Comparable instant of a stored date or date-time ("2026-11-20", "2026-11-20 10:00:00", "...Z"). */
function julian(v: string): number {
	const t = Date.parse(/^\d{4}-\d{2}-\d{2}$/.test(v) ? `${v}T00:00:00Z` : /[zZ]|[+-]\d{2}:\d{2}$/.test(v) ? v.replace(" ", "T") : `${v.replace(" ", "T")}Z`);
	return Number.isNaN(t) ? Number.POSITIVE_INFINITY : t;
}

const JOB_COLS = "id, installation_id, kind, idempotency_key, state, step, params_json, result_json, error_code, created_by, confirmed_by, confirmed_at, created_at, updated_at";

async function jobs(db: ReadDb, q: URLSearchParams): Promise<Response> {
	const kind = oneOf(q, "kind", JOB_KINDS);
	// state is the column; status is accepted as the same filter.
	const state = oneOf(q, "state", JOB_STATES);
	const status = oneOf(q, "status", JOB_STATES);
	if (state && status && state !== status) throw new BadQuery("status");
	const installationId = text(q, "installation");
	const limit = pageSize(q);
	const after = cursor(q, 2);
	const where: string[] = [];
	const args: unknown[] = [];
	if (kind) where.push("kind = ?"), args.push(kind);
	if (state ?? status) where.push("state = ?"), args.push(state ?? status);
	if (installationId) where.push("installation_id = ?"), args.push(installationId);
	if (after) where.push("(created_at < ? OR (created_at = ? AND id < ?))"), args.push(after[0], after[0], after[1]);
	const sql = `SELECT ${JOB_COLS} FROM lifecycle_jobs${where.length ? " WHERE " + where.join(" AND ") : ""} ORDER BY created_at DESC, id DESC LIMIT ?`;
	const { results } = await db.prepare(sql).bind(...args, limit + 1).all<{ id: string; created_at: string }>();
	const page = results.slice(0, limit);
	const last = page[page.length - 1];
	return json(200, { ok: true, jobs: page, next_cursor: results.length > limit && last ? encodeCursor([last.created_at, last.id]) : null });
}

const AUDIT_COLS = "id, at, operator, action, installation_id, before_json, after_json, request_id";
const AUDIT_FIELDS = ["id", "at", "operator", "action", "installation_id", "before_json", "after_json", "request_id"] as const;

/** installation, operator (any case), action: exact. from: at or after. to: before (exclusive). */
function auditFilter(q: URLSearchParams): { where: string[]; args: unknown[] } {
	const installationId = text(q, "installation");
	const operator = text(q, "operator", 254)?.toLowerCase() ?? null;
	const action = text(q, "action", 64);
	const from = instant(q, "from");
	const to = instant(q, "to");
	const where: string[] = [];
	const args: unknown[] = [];
	if (installationId) where.push("installation_id = ?"), args.push(installationId);
	if (operator) where.push("operator = ?"), args.push(operator);
	if (action) where.push("action = ?"), args.push(action);
	if (from) where.push("at >= ?"), args.push(from);
	if (to) where.push("at < ?"), args.push(to);
	return { where, args };
}

async function audit(db: ReadDb, q: URLSearchParams): Promise<Response> {
	const { where, args } = auditFilter(q);
	const limit = pageSize(q);
	const after = cursor(q, 2);
	if (after) where.push("(at < ? OR (at = ? AND id < ?))"), args.push(after[0], after[0], after[1]);
	const sql = `SELECT ${AUDIT_COLS} FROM operator_audit${where.length ? " WHERE " + where.join(" AND ") : ""} ORDER BY at DESC, id DESC LIMIT ?`;
	const { results } = await db.prepare(sql).bind(...args, limit + 1).all<{ id: string; at: string }>();
	const page = results.slice(0, limit);
	const last = page[page.length - 1];
	return json(200, { ok: true, audit: page, next_cursor: results.length > limit && last ? encodeCursor([last.at, last.id]) : null });
}

/** One CSV cell: a value a spreadsheet would run as a formula (= + - @, tab or CR first) gets a leading ', and a cell
 *  with a quote, comma, CR or LF is quoted with its quotes doubled. null is empty. */
export function csvCell(value: unknown): string {
	if (value === null || value === undefined) return "";
	let s = String(value);
	if (/^[=+\-@\t\r]/.test(s)) s = "'" + s;
	return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

async function auditCsv(db: ReadDb, q: URLSearchParams): Promise<Response> {
	const { where, args } = auditFilter(q);
	const sql = `SELECT ${AUDIT_COLS} FROM operator_audit${where.length ? " WHERE " + where.join(" AND ") : ""} ORDER BY at DESC, id DESC LIMIT ?`;
	const { results } = await db.prepare(sql).bind(...args, CSV_MAX_ROWS + 1).all<Record<string, unknown>>();
	const lines = [AUDIT_FIELDS.join(",")];
	for (const row of results.slice(0, CSV_MAX_ROWS)) lines.push(AUDIT_FIELDS.map((f) => csvCell(row[f])).join(","));
	return new Response(lines.join("\r\n") + "\r\n", {
		status: 200,
		headers: {
			"Content-Type": "text/csv; charset=utf-8",
			"Content-Disposition": 'attachment; filename="operator-audit.csv"',
			"X-Truncated": results.length > CSV_MAX_ROWS ? "1" : "0",
		},
	});
}

const DETAIL_PATH = /^\/installations\/([^/]+)$/;

/** The read for path (below API_PREFIX), or null when path is not a read. method is already GET or HEAD. */
export function readRoute(path: string): ((db: ReadDb, q: URLSearchParams, nowMs: number) => Promise<Response>) | null {
	switch (path) {
		case "/overview":
			return (db, _q, now) => overview(db, now);
		case "/installations":
			return (db, q) => installations(db, q);
		case "/plans":
			return (db) => plans(db);
		case "/subscriptions":
			return (db, q, now) => subscriptionsDue(db, q, now);
		case "/jobs":
			return (db, q) => jobs(db, q);
		case "/audit":
			return (db, q) => audit(db, q);
		case "/audit.csv":
			return (db, q) => auditCsv(db, q);
	}
	const m = DETAIL_PATH.exec(path);
	return m ? (db) => installation(db, m[1]) : null;
}

/** Runs a read; a bad query value is 422 invalid_query with the parameter as detail. */
export async function runRead(read: NonNullable<ReturnType<typeof readRoute>>, db: ReadDb, q: URLSearchParams, nowMs: number): Promise<Response> {
	try {
		return await read(db, q, nowMs);
	} catch (e) {
		if (e instanceof BadQuery) return failure(422, "invalid_query", { detail: e.param });
		throw e;
	}
}
