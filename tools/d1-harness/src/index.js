// Local-only mirror of edge/src/d1.ts (ADR-002, P4-3). Never --remote.
// POST /batch [{sql, params}] -> env.DB.batch(); POST /query {sql, params} -> .all(); POST /exec {sql} -> env.DB.exec()
const prep = (db, s) => db.prepare(s.sql).bind(...(s.params ?? []));
const json = (body, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });

export default {
  async fetch(req, env) {
    const path = new URL(req.url).pathname;
    if (req.method !== 'POST') return json({ ok: false, error: 'POST only' }, 405);
    let body;
    try {
      body = await req.json();
    } catch {
      return json({ ok: false, error: 'invalid JSON' }, 400);
    }
    try {
      if (path === '/batch') {
        if (!Array.isArray(body)) return json({ ok: false, error: 'batch body must be an array' }, 400);
        const res = await env.DB.batch(body.map((s) => prep(env.DB, s)));
        return json({
          ok: true,
          results: res.map((r) => ({
            rows: r.results ?? [],
            changes: r.meta?.changes,
            last_row_id: r.meta?.last_row_id,
          })),
        });
      }
      if (path === '/query') {
        if (typeof body?.sql !== 'string') return json({ ok: false, error: 'query needs sql' }, 400);
        const r = await prep(env.DB, body).all();
        return json({
          ok: true,
          rows: r.results ?? [],
          changes: r.meta?.changes,
          last_row_id: r.meta?.last_row_id,
        });
      }
      if (path === '/exec') {
        if (typeof body?.sql !== 'string') return json({ ok: false, error: 'exec needs sql' }, 400);
        const r = await env.DB.exec(body.sql);
        return json({ ok: true, count: r.count });
      }
      return json({ ok: false, error: 'not found' }, 404);
    } catch (e) {
      return json(
        { ok: false, error: String(e?.message ?? e), cause: e?.cause ? String(e.cause.message ?? e.cause) : null },
        500,
      );
    }
  },
};
