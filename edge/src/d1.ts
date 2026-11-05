// Outbound bridge for http://d1.internal (ADR-002, P4-3): POST /query, /batch and /exec.
// The container calls these paths through interceptOutboundHttp; the Worker holds the D1
// binding so the container never sees a Cloudflare token. Same JSON shape as D1HttpExecutor
// and tools/d1-harness (ok/rows/results/count; error text only on failure).

/** Env may lack DB until EDGE_STORAGE_READY merges edge/storage.jsonc. */
export type D1Env = { DB?: D1Database };

const json = (body: unknown, status = 200): Response =>
	new Response(JSON.stringify(body), {
		status,
		headers: { "content-type": "application/json" },
	});

type Statement = { sql: string; params?: unknown[] };

const prepare = (db: D1Database, s: Statement): D1PreparedStatement => {
	const stmt = db.prepare(s.sql);
	const params = s.params ?? [];
	return params.length === 0 ? stmt : stmt.bind(...params);
};

const fail = (e: unknown): Response => {
	const err = e as { message?: unknown; cause?: { message?: unknown } };
	const message = typeof err?.message === "string" ? err.message : String(e);
	const cause =
		err?.cause && typeof err.cause.message === "string" ? err.cause.message : null;
	return json({ ok: false, error: message, cause }, 500);
};

/**
 * Serve one d1.internal request against a D1 (or DO SQLite) binding.
 * Exported for the deploy-scripts unit tests; the Worker entry is D1Outbound.
 */
export async function handleD1Request(request: Request, db: D1Database): Promise<Response> {
	const path = new URL(request.url).pathname;
	if (request.method !== "POST") {
		return json({ ok: false, error: "POST only" }, 405);
	}
	let body: unknown;
	try {
		body = await request.json();
	} catch {
		return json({ ok: false, error: "invalid JSON" }, 400);
	}
	try {
		if (path === "/batch") {
			if (!Array.isArray(body)) {
				return json({ ok: false, error: "batch body must be an array" }, 400);
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
				return json({ ok: false, error: "query needs sql" }, 400);
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
				return json({ ok: false, error: "exec needs sql" }, 400);
			}
			const r = await db.exec(s.sql);
			return json({ ok: true, count: r.count });
		}
		return json({ ok: false, error: "not found" }, 404);
	} catch (e) {
		return fail(e);
	}
}

/** Used when the D1 binding is not on the Worker yet (storage gate still closed). */
export function d1UnboundResponse(): Response {
	return json({ ok: false, error: "D1 is not bound" }, 503);
}
