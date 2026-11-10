// Durable Object storage over a real SQLite (node:sqlite), for tests: exec refuses transaction statements as the
// runtime does, and transactionSync commits or rolls back. Shared by installation.test.mjs and do-bridge-server.mjs.
import { DatabaseSync } from 'node:sqlite';

export function doStorage() {
  const db = new DatabaseSync(':memory:');
  return {
    db,
    sql: {
      exec(sql, ...params) {
        if (/^\s*(BEGIN|COMMIT|END|ROLLBACK|SAVEPOINT|RELEASE)\b/i.test(sql)) throw new Error('transaction statements are not allowed');
        if (params.length === 0 && /;\s*\S/.test(sql.trim().replace(/;\s*$/, ''))) { db.exec(sql); return { toArray: () => [] }; }
        const rows = db.prepare(sql).all(...params).map((r) => ({ ...r }));
        return { toArray: () => rows };
      },
    },
    transactionSync(fn) {
      db.exec('BEGIN');
      try { const r = fn(); db.exec('COMMIT'); return r; } catch (e) { db.exec('ROLLBACK'); throw e; }
    },
  };
}
