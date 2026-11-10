// Outbound bridge for http://d1.internal (ADR-002, P4-3): POST /query, /batch and /exec.
// The container calls these paths through interceptOutboundHttp; the Worker holds the D1
// binding so the container never sees a Cloudflare token. Same JSON shape as D1HttpExecutor
// and tools/d1-harness (ok/rows/results/count on success).
// Failure bodies are generic: {ok:false} plus a stable `code`. A unit-of-work guard failure is
// reported with the single allow-listed marker string below (the only text D1Limits::isGuardFailure
// matches), so the PHP side still maps it to GUARD_FAILED without any raw driver text reaching a
// response. Real details stay in the server log.

/** Env may lack DB until EDGE_STORAGE_READY merges edge/storage.jsonc. */
export type D1Env = { DB?: D1Database };

type D1Rows = { results?: unknown[]; meta?: { changes?: number; last_row_id?: number } };
/** A prepared statement as handleD1Request uses it: D1's own, or the Installation object's (installation.ts). */
export type D1LikeStatement = { bind(...values: unknown[]): D1LikeStatement; all(): Promise<D1Rows> };
/** What handleD1Request needs from a database: D1Database, or the Installation object's SQLite. */
export type D1Like = {
	prepare(sql: string): D1LikeStatement;
	batch(statements: D1LikeStatement[]): Promise<D1Rows[]>;
	exec(sql: string): Promise<{ count: number }>;
};

const json = (body: unknown, status = 200): Response =>
	new Response(JSON.stringify(body), {
		status,
		headers: { "content-type": "application/json" },
	});

type Statement = { sql: string; params?: unknown[] };

const prepare = (db: D1Like, s: Statement): D1LikeStatement => {
	const stmt = db.prepare(s.sql);
	const params = s.params ?? [];
	return params.length === 0 ? stmt : stmt.bind(...params);
};

// The exact SQLite text isGuardFailure anchors on for the unit-of-work guard (uow_guard.ok NOT NULL).
// Returned instead of any driver message so guard recognition keeps working with nothing else exposed.
const GUARD_MARKER = "NOT NULL constraint failed: uow_guard.ok";

// Whether a thrown D1/SQLite error is the unit-of-work guard. Only the marker is ever echoed, never the text.
function isGuardError(e: unknown): boolean {
	const seen: unknown[] = [e];
	for (let i = 0; i < 4; i++) {
		const cur = seen[i];
		if (cur == null) break;
		const text =
			typeof cur === "string"
				? cur
				: typeof (cur as { message?: unknown })?.message === "string"
					? (cur as { message: string }).message
					: "";
		if (/NOT NULL constraint failed: uow_guard\.ok/.test(text)) return true;
		seen.push((cur as { cause?: unknown })?.cause);
	}
	return false;
}

const fail = (e: unknown): Response => {
	// Details (message, stack, D1/SQLite text) stay in the Worker log only, never in the body.
	console.error("d1.internal request failed", e);
	if (isGuardError(e)) {
		return json({ ok: false, code: "guard_failed", error: GUARD_MARKER }, 500);
	}
	return json({ ok: false, code: "write_failed", error: "the batch" }, 500);
};

/**
 * Serve one d1.internal request against a D1 binding or the Installation object's SQLite.
 * Exported for the deploy-scripts unit tests; the Worker entry is D1Outbound.
 */
export async function handleD1Request(request: Request, db: D1Like): Promise<Response> {
	const path = new URL(request.url).pathname;
	if (request.method !== "POST") {
		return json({ ok: false, code: "method_not_allowed", error: "POST only" }, 405);
	}
	let body: unknown;
	try {
		body = await request.json();
	} catch {
		return json({ ok: false, code: "bad_request", error: "invalid JSON" }, 400);
	}
	try {
		if (path === "/batch") {
			if (!Array.isArray(body)) {
				return json({ ok: false, code: "bad_request", error: "batch body must be an array" }, 400);
			}
			const statements = body as Statement[];
			const res = await db.batch(statements.map((s) => prepare(db, s)));
			return json({
				ok: true,
				results: res.map((r) => ({
					rows: r.results ?? [],
					changes: r.meta?.changes,
					last_row_id: r.meta?.last_row_id,
				})),
			});
		}
		if (path === "/query") {
			const s = body as Statement;
			if (typeof s?.sql !== "string") {
				return json({ ok: false, code: "bad_request", error: "query needs sql" }, 400);
			}
			const r = await prepare(db, s).all();
			return json({
				ok: true,
				rows: r.results ?? [],
				changes: r.meta?.changes,
				last_row_id: r.meta?.last_row_id,
			});
		}
		if (path === "/exec") {
			const s = body as { sql?: unknown };
			if (typeof s?.sql !== "string") {
				return json({ ok: false, code: "bad_request", error: "exec needs sql" }, 400);
			}
			const r = await db.exec(s.sql);
			return json({ ok: true, count: r.count });
		}
		return json({ ok: false, code: "not_found", error: "not found" }, 404);
	} catch (e) {
		return fail(e);
	}
}

/** Used when the D1 binding is not on the Worker yet (storage gate still closed). */
export function d1UnboundResponse(): Response {
	return json({ ok: false, code: "unbound", error: "D1 is not bound" }, 503);
}
