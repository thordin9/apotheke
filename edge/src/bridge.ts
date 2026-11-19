// Read-only bridge endpoints of an installation (operator console C1): GET /stats and GET /users answer fixed fields
// from the installation's own database, and GET /limits answers its plan's limits from the control plane (D1 DB,
// edge/migrations/control/). The Installation object serves them on d1.internal next to /query, /batch and /exec.
// Numbers and account metadata only: never item rows, password hashes or other content.
import { describeError, type D1Like } from "./d1";
import { PLAN_LIMITS_JSON_SQL, parseLimits } from "./limits";

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

/**
 * The plan's limits per key ({soft, hard}), loaded and read as the Worker's plan limits are (limits.ts): only keys
 * with a hard value, soft 80% of hard when it has none. No subscription means no plan.
 */
async function limits(control: ControlDb | undefined, doName: string | undefined): Promise<Response> {
	if (!control || doName === undefined) return json({ ok: false, code: "unbound", error: "limits are not available" }, 503);
	const { results } = await control
		.prepare(
			`SELECT s.plan_id AS plan, ${PLAN_LIMITS_JSON_SQL} AS limits FROM installations i`
				+ " JOIN subscriptions s ON s.installation_id = i.id WHERE i.do_name = ?",
		)
		.bind(doName)
		.all<{ plan: string; limits: unknown }>();
	if (results.length === 0) return json({ ok: false, code: "unknown_installation", error: "no plan for this installation" }, 404);
	return json({ ok: true, plan: results[0].plan, limits: parseLimits(results[0].limits) });
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
