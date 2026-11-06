// Local-only mirror of edge/src/d1.ts (ADR-002, P4-3). Never --remote (the launcher forces --local).
// POST /batch [{sql, params}] -> env.DB.batch(); POST /query {sql, params} -> .all(); POST /exec {sql} -> env.DB.exec()
// Failure bodies are generic like production; the guard failure echoes only the allow-listed marker.
const GUARD_MARKER = 'NOT NULL constraint failed: uow_guard.ok';
const prep = (db, s) => {
  const stmt = db.prepare(s.sql);
  const params = s.params ?? [];
  return params.length === 0 ? stmt : stmt.bind(...params);
};
const json = (body, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });

function isGuardError(e) {
  const seen = [e];
  for (let i = 0; i < 4; i++) {
    const cur = seen[i];
    if (cur == null) break;
    const text = typeof cur === 'string' ? cur : typeof cur?.message === 'string' ? cur.message : '';
    if (/NOT NULL constraint failed: uow_guard\.ok/.test(text)) return true;
    seen.push(cur?.cause);
  }
  return false;
}

export default {
  async fetch(req, env) {
    const path = new URL(req.url).pathname;
    if (req.method !== 'POST') return json({ ok: false, code: 'method_not_allowed', error: 'POST only' }, 405);
    let body;
    try {
      body = await req.json();
    } catch {
      return json({ ok: false, code: 'bad_request', error: 'invalid JSON' }, 400);
    }
    try {
      if (path === '/batch') {
        if (!Array.isArray(body)) return json({ ok: false, code: 'bad_request', error: 'batch body must be an array' }, 400);
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
        if (typeof body?.sql !== 'string') return json({ ok: false, code: 'bad_request', error: 'query needs sql' }, 400);
        const r = await prep(env.DB, body).all();
        return json({
          ok: true,
          rows: r.results ?? [],
          changes: r.meta?.changes,
          last_row_id: r.meta?.last_row_id,
        });
      }
      if (path === '/exec') {
        if (typeof body?.sql !== 'string') return json({ ok: false, code: 'bad_request', error: 'exec needs sql' }, 400);
        const r = await env.DB.exec(body.sql.replace(/;$/, ''));
        return json({ ok: true, count: r.count });
      }
      return json({ ok: false, code: 'not_found', error: 'not found' }, 404);
    } catch (e) {
      console.error('d1-harness request failed', e?.message ?? 'request failed');
      if (isGuardError(e)) return json({ ok: false, code: 'guard_failed', error: GUARD_MARKER }, 500);
      return json({ ok: false, code: 'write_failed', error: 'the batch' }, 500);
    }
  },
};
