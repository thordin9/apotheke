// Read-only bridge endpoints of an installation (operator console C1): GET /stats and GET /users answer fixed fields
// from the installation's own database, and GET /limits answers its plan's limits from the control plane (D1 DB,
// edge/migrations/control/). The Installation object serves them on d1.internal next to /query, /batch and /exec.
// Numbers and account metadata only: never item rows, password hashes or other content.
import { describeError, type D1Like } from "./d1";

/** The control-plane query /limits needs: D1's prepare/bind/all. */
export type ControlDb = { prepare(sql: string): { bind(...v: unknown[]): { all<T>(): Promise<{ results: T[] }> } } };

export const BRIDGE_READS = ["/stats", "/users", "/limits"] as const;

const json = (body: unknown, status = 200): Response =>
	new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json", "cache-control": "no-store" } });

const num = (v: unknown): number => (typeof v === "number" ? v : Number(v ?? 0));

async function rows(db: D1Like, sql: string): Promise<Record<string, unknown>[]> {
	return ((await db.prepare(sql).all()).results ?? []) as Record<string, unknown>[];
}

/** Counts of the installation's own rows; users active in 30 days by their last sign-in (stored as UTC). */
async function stats(db: D1Like): Promise<Response> {
	const [r] = await rows(
		db,
		"SELECT (SELECT COUNT(*) FROM items) AS items, (SELECT COUNT(*) FROM item_photos) AS photos,"
			+ " (SELECT COUNT(*) FROM users) AS users,"
			+ " (SELECT COUNT(*) FROM users WHERE last_login_at >= strftime('%Y-%m-%d %H:%M:%S', 'now', '-30 days')) AS users_active_30d",
	);
	return json({ ok: true, stats: { items: num(r?.items), photos: num(r?.photos), users: num(r?.users), users_active_30d: num(r?.users_active_30d) } });
}

/** Account metadata only: no password hash, session epoch or anything else. */
async function users(db: D1Like): Promise<Response> {
	const list = await rows(db, "SELECT id, username, role, status, created_at, last_login_at FROM users ORDER BY id");
	return json({
		ok: true,
		users: list.map((u) => ({
			id: num(u.id),
			username: String(u.username ?? ""),
			role: String(u.role ?? ""),
			status: String(u.status ?? ""),
			created_at: u.created_at == null ? null : String(u.created_at),
			last_login_at: u.last_login_at == null ? null : String(u.last_login_at),
		})),
	});
}

type LimitRow = { plan: string; limit_key: string | null; soft_value: number | null; hard_value: number | null };

/** The plan's soft and hard value per key. No row for a key means no limit; no subscription means no plan. */
async function limits(control: ControlDb | undefined, doName: string | undefined): Promise<Response> {
	if (!control || doName === undefined) return json({ ok: false, code: "unbound", error: "limits are not available" }, 503);
	const { results } = await control
		.prepare(
			"SELECT s.plan_id AS plan, pl.limit_key, pl.soft_value, pl.hard_value FROM installations i"
				+ " JOIN subscriptions s ON s.installation_id = i.id LEFT JOIN plan_limits pl ON pl.plan_id = s.plan_id"
				+ " WHERE i.do_name = ? ORDER BY pl.limit_key",
		)
		.bind(doName)
		.all<LimitRow>();
	if (results.length === 0) return json({ ok: false, code: "unknown_installation", error: "no plan for this installation" }, 404);
	const out: Record<string, { soft: number | null; hard: number | null }> = {};
	for (const r of results) {
		if (r.limit_key !== null) out[r.limit_key] = { soft: r.soft_value ?? null, hard: r.hard_value ?? null };
	}
	return json({ ok: true, plan: results[0].plan, limits: out });
}

/** One GET bridge read, or null when the path is not one (the caller answers it as before). */
export async function handleBridgeRead(request: Request, db: D1Like, control: ControlDb | undefined, doName: string | undefined): Promise<Response | null> {
	const path = new URL(request.url).pathname;
	if (request.method !== "GET" || !(BRIDGE_READS as readonly string[]).includes(path)) return null;
	try {
		if (path === "/stats") return await stats(db);
		if (path === "/users") return await users(db);
		return await limits(control, doName);
	} catch (e) {
		const { sqlite, reason } = describeError(e);
		console.error("d1.internal read failed", JSON.stringify({ request: path.slice(1), sqlite, reason }));
		return json({ ok: false, code: "read_failed", error: "the read", sqlite, reason }, 503);
	}
}
