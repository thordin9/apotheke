// Control-plane migrations (edge/migrations/control/): applied in file-name order to a fresh SQLite, as
// `wrangler d1 migrations apply` does, then the primary seed is checked against the names the Worker uses today.
// Loaded by r2.test.mjs, so CI's existing node --test command runs it.
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';

const dir = new URL('../migrations/control/', import.meta.url);
const files = readdirSync(dir).filter((f) => f.endsWith('.sql')).sort();
const src = (f) => readFileSync(new URL(`../src/${f}`, import.meta.url), 'utf8');

function migrated() {
  const db = new DatabaseSync(':memory:');
  db.exec('PRAGMA foreign_keys = ON');
  for (const f of files) db.exec(readFileSync(new URL(f, dir), 'utf8'));
  return db;
}

test('control migrations are numbered wrangler-style and additive only', () => {
  assert.ok(files.length >= 2);
  files.forEach((f, i) => assert.match(f, new RegExp(`^${String(i + 1).padStart(4, '0')}_[a-z0-9_]+\\.sql$`)));
  for (const f of files) {
    const sql = readFileSync(new URL(f, dir), 'utf8').replace(/--.*$/gm, '');
    assert.doesNotMatch(sql, /\b(DROP|RENAME|DELETE\s+FROM|UPDATE\s+\w+\s+SET|BEGIN\s+TRANSACTION|COMMIT)\b/i, f);
  }
});

test('primary is seeded with its existing DO name and R2 prefix, plan internal, active', () => {
  const db = migrated();
  const row = db.prepare(`SELECT i.id, i.do_name, i.r2_prefix, i.status, s.plan_id, s.status AS sub, s.period_end, r.host
    FROM installations i JOIN subscriptions s ON s.installation_id = i.id JOIN installation_routes r ON r.installation_id = i.id`).all();
  assert.deepEqual(row.map((r) => ({ ...r })), [{ id: 'primary', do_name: 'primary', r2_prefix: 'installations/primary/', status: 'active',
    plan_id: 'internal', sub: 'active', period_end: null, host: 'api.services.getapotheke.com' }]);
  assert.ok(src('installation.ts').includes(`export const INSTALLATION_NAME = "${row[0].do_name}";`));
  assert.ok(src('r2.ts').includes(`const PREFIX = "${row[0].r2_prefix}";`));
  assert.ok(src('route.ts').includes('const FALLBACK: Route = { doName: INSTALLATION_NAME, status: "active" };'));
  assert.ok(readFileSync(new URL('../wrangler.jsonc', import.meta.url), 'utf8').includes(`"ALLOWED_HOSTS": "${row[0].host}"`));
  assert.equal(db.prepare('SELECT count(*) AS n FROM plan_limits').get().n, 0);
});

test('constraints: prefix follows do_name, hosts are lower case, statuses are fixed, audit is append-only', () => {
  const db = migrated();
  assert.throws(() => db.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('b', 'b', 'installations/c/', 'active')`), /CHECK/);
  assert.throws(() => db.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('b', 'b', 'installations/b/', 'paused')`), /CHECK/);
  assert.throws(() => db.exec(`INSERT INTO installation_routes (host, installation_id) VALUES ('Api.example.test', 'primary')`), /CHECK/);
  assert.throws(() => db.exec(`INSERT INTO installation_routes (host, installation_id) VALUES ('x.example.test', 'nope')`), /FOREIGN KEY/);
  assert.throws(() => db.exec(`UPDATE subscriptions SET status = 'paid'`), /CHECK/);
  assert.throws(() => db.exec(`INSERT INTO usage_counters (installation_id, period, metric) VALUES ('primary', '2026-1', 'requests')`), /CHECK/);
  db.exec(`INSERT INTO operator_audit (id, operator, action, installation_id) VALUES ('01TEST', 'op@example.test', 'set_status', 'primary')`);
  assert.throws(() => db.exec(`UPDATE operator_audit SET action = 'x'`), /append-only/);
  assert.throws(() => db.exec(`DELETE FROM operator_audit`), /append-only/);
});
