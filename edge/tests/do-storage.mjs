// Durable Object storage over a real SQLite (node:sqlite), for tests: exec refuses transaction statements as the
// runtime does, and transactionSync commits or rolls back. Shared by installation.test.mjs and do-bridge-server.mjs.
import { DatabaseSync } from 'node:sqlite';

// The pragmas the runtime's SQLite allows, as a statement or a table-valued pragma_*() function (checked on workerd's
// SQLite-backed Durable Objects). Any other pragma, and the dbstat table, is refused as the runtime refuses it.
export const ALLOWED_PRAGMAS = new Set(['case_sensitive_like', 'data_version', 'defer_foreign_keys', 'foreign_key_check',
  'foreign_key_list', 'foreign_keys', 'ignore_check_constraints', 'index_list', 'legacy_alter_table', 'optimize', 'page_size',
  'quick_check', 'recursive_triggers', 'reverse_unordered_selects', 'table_info', 'table_list', 'table_xinfo']);

export function refusedByRuntime(sql) {
  const names = [...sql.matchAll(/^\s*PRAGMA\s+(?:"?\w+"?\.)?"?(\w+)/gim), ...sql.matchAll(/\bpragma_(\w+)\s*\(/gi)].map((m) => m[1].toLowerCase());
  return names.some((n) => !ALLOWED_PRAGMAS.has(n)) || /\bdbstat\b/i.test(sql);
}

export function doStorage() {
  const db = new DatabaseSync(':memory:');
  return {
    db,
    sql: {
      exec(sql, ...params) {
        if (/^\s*(BEGIN|COMMIT|END|ROLLBACK|SAVEPOINT|RELEASE)\b/i.test(sql)) throw new Error('transaction statements are not allowed');
        if (refusedByRuntime(sql)) throw new Error('not authorized: SQLITE_AUTH');
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
