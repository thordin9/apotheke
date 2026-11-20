// Control-plane migrations (edge/migrations/control/): applied in file-name order to a fresh SQLite, as
// `wrangler d1 migrations apply` does, then the primary seed is checked against the names the Worker uses today.
// Loaded by r2.test.mjs, so CI's existing node --test command runs it.
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { createHash } from 'node:crypto';
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

// The one exception to additive only: 0004 rebuilds plan_limits (same columns and rows) to allow one more key in its
// CHECK, which SQLite cannot alter in place. Only these exact statements are allowed, and only in that file.
const REBUILDS = { '0004_plan_limits_photos_per_item.sql': ['DROP TABLE plan_limits;', 'ALTER TABLE plan_limits_0004 RENAME TO plan_limits;'] };

test('control migrations are numbered wrangler-style and additive only', () => {
  assert.ok(files.length >= 2);
  files.forEach((f, i) => assert.match(f, new RegExp(`^${String(i + 1).padStart(4, '0')}_[a-z0-9_]+\\.sql$`)));
  for (const f of files) {
    let sql = readFileSync(new URL(f, dir), 'utf8').replace(/--.*$/gm, '');
    for (const allowed of REBUILDS[f] ?? []) {
      assert.equal(sql.split(allowed).length, 2, `${f}: ${allowed}`);
      sql = sql.replace(allowed, '');
    }
    assert.doesNotMatch(sql, /\b(DROP|RENAME|DELETE\s+FROM|UPDATE\s+\w+\s+SET|REPLACE|BEGIN\s+TRANSACTION|COMMIT)\b/i, f);
  }
});

test('primary is seeded with its existing DO name and R2 prefix, plan internal, active', () => {
  const db = migrated();
  const row = db.prepare(`SELECT i.id, i.do_name, i.r2_prefix, i.status, s.plan_id, s.status AS sub, s.period_end, r.host
    FROM installations i JOIN subscriptions s ON s.installation_id = i.id JOIN installation_routes r ON r.installation_id = i.id`).all();
  assert.deepEqual(row.map((r) => ({ ...r })), [{ id: 'primary', do_name: 'primary', r2_prefix: 'installations/primary/', status: 'active',
    plan_id: 'internal', sub: 'active', period_end: null, host: 'api.services.getapotheke.com' }]);
  assert.ok(src('installation.ts').includes(`export const INSTALLATION_NAME = "${row[0].do_name}";`));
  assert.ok(src('installation.ts').includes('return `installations/${doName}/`;'));
  assert.equal(row[0].r2_prefix, `installations/${row[0].do_name}/`);
  assert.ok(src('route.ts').includes('const FALLBACK: Route = { doName: INSTALLATION_NAME, status: "active" };'));
  assert.ok(readFileSync(new URL('../wrangler.jsonc', import.meta.url), 'utf8').includes(`"ALLOWED_HOSTS": "${row[0].host}"`));
  assert.equal(db.prepare('SELECT count(*) AS n FROM plan_limits').get().n, 0);
});

// A migration that has been applied must never change: a fix is a new, later file.
const PINNED = {
  '0001_control_plane.sql': 'a066b939768e2180e782e2e83fc53d03f611b6b7edac3b6991f4d5301eb4ecb7',
  '0002_seed_primary.sql': '7c0ecfebc8603b75300154ac1ba66adabebc550ddb0fdbb445df5994a9db8dcc',
  '0003_lifecycle_jobs.sql': '932738cbb9f76105a4d9f8a1333341ad0cf0c3d503bfebe3de53693571d6fd83',
  '0004_plan_limits_photos_per_item.sql': 'c2720cf2f3b4fa03bd6655d483d076be0c3cbe235c0e1ff5ab469c3d5f2a3c78',
  '0005_limit_overrides.sql': 'af48096ca6be8a5609351fcf465723589baf9d8931505a0ea33c402455cb146f',
};

test('applied migrations are pinned by sha256', () => {
  for (const [f, sum] of Object.entries(PINNED)) {
    assert.equal(createHash('sha256').update(readFileSync(new URL(f, dir))).digest('hex'), sum, f);
  }
});

const refused = (db, sql, re) => assert.throws(() => db.exec(sql), re, sql);
const install = (id, doName, prefix = `installations/${doName}/`) =>
  `INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('${id}', '${doName}', '${prefix}', 'active')`;

test('installations: do_name charset, prefix follows do_name, both unique and fixed, statuses are fixed', () => {
  const db = migrated();
  for (const name of ['', 'B', 'a.b', 'a/b', '..', 'a b', 'a_b', '-a', 'a-', 'a--b', 'a'.repeat(64)]) refused(db, install('x', name), /CHECK/);
  for (const name of ['a', 'a-b', 'tenant-2-x', 'a'.repeat(63)]) db.exec(install(`ok-${name}`, name));
  refused(db, install('x', 'b', 'installations/c/'), /CHECK/);
  refused(db, `INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('x', 'b', 'installations/b/', 'paused')`, /CHECK/);
  refused(db, install('x', 'primary'), /already exists/);
  refused(db, `INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('x', 'b', 'installations/primary/', 'active')`, /CHECK|UNIQUE|already exists/);
  db.exec(install('b2', 'tenant-2'));
  refused(db, `UPDATE installations SET do_name = 'tenant-3', r2_prefix = 'installations/tenant-3/' WHERE id = 'b2'`, /never change/);
  refused(db, `UPDATE installations SET r2_prefix = 'installations/other/' WHERE id = 'primary'`, /never change/);
  db.exec(`UPDATE installations SET status = 'suspended', do_name = do_name WHERE id = 'b2'`);
  // REPLACE deletes and re-inserts without UPDATE triggers: renaming, or taking another installation's name, is refused.
  refused(db, `REPLACE INTO installations (id, do_name, r2_prefix, status) VALUES ('primary', 'renamed', 'installations/renamed/', 'active')`, /already exists/);
  refused(db, `INSERT OR REPLACE INTO installations (id, do_name, r2_prefix, status) VALUES ('primary', 'renamed', 'installations/renamed/', 'active')`, /already exists/);
  refused(db, `REPLACE INTO installations (id, do_name, r2_prefix, status) VALUES ('other', 'primary', 'installations/primary/', 'active')`, /already exists/);
  assert.deepEqual({ ...db.prepare(`SELECT do_name, r2_prefix FROM installations WHERE id = 'primary'`).get() }, { do_name: 'primary', r2_prefix: 'installations/primary/' });
  const cols = Object.fromEntries(db.prepare(`PRAGMA index_list('installations')`).all().filter((i) => i.unique)
    .map((i) => [db.prepare(`PRAGMA index_info('${i.name}')`).all().map((c) => c.name).join(), true]));
  assert.ok(cols.do_name && cols.r2_prefix);
});

test('routes: hosts are lower case without port, slash, space, empty or trailing dot; the installation must exist', () => {
  const db = migrated();
  for (const host of ['', 'Api.example.test', 'a.example.test:443', 'a.example.test.', 'a b.test', 'a.test/x', '.a.test', 'a..example.test',
    '-a.example.test', 'a-.example.test', 'a.-b.test', 'a.b-.test', 'example.test-']) {
    refused(db, `INSERT INTO installation_routes (host, installation_id) VALUES ('${host}', 'primary')`, /CHECK/);
  }
  refused(db, `INSERT INTO installation_routes (host, installation_id) VALUES ('x.example.test', 'nope')`, /FOREIGN KEY/);
  db.exec(`INSERT INTO installation_routes (host, installation_id) VALUES ('x-1.example.test', 'primary'), ('a--b.example.test', 'primary')`);
});

test('subscriptions, limits and usage: plan required and real, statuses fixed, limits sane, months 01-12', () => {
  const db = migrated();
  const plan = db.prepare(`PRAGMA table_info('subscriptions')`).all().find((c) => c.name === 'plan_id');
  assert.equal(plan.notnull, 1);
  assert.ok(db.prepare(`PRAGMA foreign_key_list('subscriptions')`).all().some((k) => k.from === 'plan_id' && k.table === 'plans' && k.to === 'id'));
  db.exec(install('b', 'b'));
  refused(db, `INSERT INTO subscriptions (installation_id, status) VALUES ('b', 'active')`, /NOT NULL/);
  refused(db, `INSERT INTO subscriptions (installation_id, plan_id, status) VALUES ('b', 'nope', 'active')`, /FOREIGN KEY/);
  refused(db, `UPDATE subscriptions SET status = 'paid'`, /CHECK/);
  for (const [soft, hard] of [[-1, 'NULL'], ['NULL', -1], [5, 4]]) {
    refused(db, `INSERT INTO plan_limits (plan_id, limit_key, soft_value, hard_value) VALUES ('internal', 'items', ${soft}, ${hard})`, /CHECK/);
  }
  db.exec(`INSERT INTO plan_limits (plan_id, limit_key, soft_value, hard_value) VALUES ('internal', 'items', 4, 4), ('internal', 'users', NULL, 0)`);
  for (const period of ['2026-1', '2026-00', '2026-13']) {
    refused(db, `INSERT INTO usage_counters (installation_id, period, metric) VALUES ('primary', '${period}', 'requests')`, /CHECK/);
  }
  db.exec(`INSERT INTO usage_counters (installation_id, period, metric) VALUES ('primary', '2026-12', 'requests')`);
});

test('operator_audit is append-only: no update, delete, or insert over an existing id', () => {
  const db = migrated();
  const row = `INTO operator_audit (id, operator, action, installation_id) VALUES ('01TEST', 'op@example.test', 'set_status', 'primary')`;
  db.exec(`INSERT ${row}`);
  for (const sql of [`UPDATE operator_audit SET action = 'x'`, `DELETE FROM operator_audit`, `INSERT ${row}`, `INSERT OR REPLACE ${row}`, `REPLACE ${row}`]) {
    refused(db, sql, /append-only/);
  }
  assert.deepEqual({ ...db.prepare('SELECT count(*) AS n, max(action) AS a FROM operator_audit').get() }, { n: 1, a: 'set_status' });
});

test('text primary keys refuse NULL', () => {
  const db = migrated();
  for (const sql of [
    `INSERT INTO plans (id, name) VALUES (NULL, 'n')`,
    `INSERT INTO installations (id, do_name, r2_prefix, status) VALUES (NULL, 'n', 'installations/n/', 'active')`,
    `INSERT INTO installation_routes (host, installation_id) VALUES (NULL, 'primary')`,
    `INSERT INTO subscriptions (installation_id, plan_id, status) VALUES (NULL, 'internal', 'active')`,
    `INSERT INTO operator_audit (id, operator, action) VALUES (NULL, 'op@example.test', 'x')`,
  ]) refused(db, sql, /NOT NULL/);
});

test('0003 lifecycle_jobs: only CREATE ... IF NOT EXISTS, and applying it twice changes nothing', () => {
  const sql = readFileSync(new URL('0003_lifecycle_jobs.sql', dir), 'utf8');
  const statements = sql.replace(/--.*$/gm, '').split(';').map((s) => s.trim()).filter(Boolean);
  for (const s of statements) assert.match(s, /^CREATE (TABLE|INDEX) IF NOT EXISTS lifecycle_jobs(_[a-z]+)?\s/, s);
  const db = migrated();
  db.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('t', 't', 'installations/t/', 'active')`);
  db.exec(`INSERT INTO lifecycle_jobs (id, installation_id, kind, idempotency_key, created_by) VALUES ('j', 't', 'suspend', 'suspend:t:n1', 'op')`);
  const schema = () => db.prepare('SELECT type, name, sql FROM sqlite_master ORDER BY name').all().map((r) => ({ ...r }));
  const before = [schema(), db.prepare('SELECT * FROM lifecycle_jobs').all().map((r) => ({ ...r }))];
  db.exec(sql);
  assert.deepEqual([schema(), db.prepare('SELECT * FROM lifecycle_jobs').all().map((r) => ({ ...r }))], before);
});

test('lifecycle_jobs: kinds and states fixed, created paused, key is <kind>:<installation_id>:<nonce> and unique', () => {
  const db = migrated();
  db.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('t', 't', 'installations/t/', 'active')`);
  const job = (id, kind, key, extra = '') => `INSERT INTO lifecycle_jobs (id, installation_id, kind, idempotency_key, created_by${extra ? ', state, confirmed_by' : ''})
    VALUES ('${id}', 't', '${kind}', '${key}', 'op'${extra})`;
  db.exec(job('j1', 'purge', 'purge:t:n1'));
  assert.equal(db.prepare(`SELECT state FROM lifecycle_jobs WHERE id = 'j1'`).get().state, 'paused');
  refused(db, job('j2', 'wipe', 'wipe:t:n1'), /CHECK/);
  for (const key of ['purge:t:', 'purge:t', 'suspend:t:n1', 'purge:u:n1', 'purge:tt:n1', 'x'.repeat(301)]) refused(db, job('j2', 'purge', key), /CHECK/);
  refused(db, job('j2', 'purge', 'purge:t:n1'), /UNIQUE/);
  refused(db, `INSERT INTO lifecycle_jobs (id, installation_id, kind, idempotency_key, created_by) VALUES ('j2', 'nope', 'purge', 'purge:nope:n1', 'op')`, /FOREIGN KEY/);
  refused(db, job('j2', 'resume', 'resume:t:n2', `, 'queued', NULL`), /CHECK/);
  refused(db, job('j2', 'resume', 'resume:t:n2', `, 'paused-ish', 'op'`), /CHECK/);
  for (const s of ['paused', 'queued', 'running', 'done', 'failed']) db.exec(job(`s-${s}`, 'export', `export:t:${s}`, `, '${s}', 'op'`));
});

test('0004 plan_limits: photos_per_item allowed, columns, checks and rows kept, nothing seeded', () => {
  const db = new DatabaseSync(':memory:');
  db.exec('PRAGMA foreign_keys = ON');
  for (const f of files.filter((f) => f < '0004')) db.exec(readFileSync(new URL(f, dir), 'utf8'));
  const cols = () => db.prepare(`PRAGMA table_info('plan_limits')`).all().map((c) => ({ ...c }));
  const fks = () => db.prepare(`PRAGMA foreign_key_list('plan_limits')`).all().map((k) => ({ ...k }));
  const before = [cols(), fks()];
  refused(db, `INSERT INTO plan_limits (plan_id, limit_key, hard_value) VALUES ('internal', 'photos_per_item', 4)`, /CHECK/);
  db.exec(`INSERT INTO plans (id, name) VALUES ('starter', 'Starter')`);
  db.exec(`INSERT INTO plan_limits (plan_id, limit_key, soft_value, hard_value) VALUES ('starter', 'items', 400, 500), ('starter', 'photos', NULL, 1000)`);
  db.exec(readFileSync(new URL('0004_plan_limits_photos_per_item.sql', dir), 'utf8'));
  assert.deepEqual([cols(), fks()], before);
  const rows = () => db.prepare('SELECT * FROM plan_limits ORDER BY plan_id, limit_key').all().map((r) => ({ ...r }));
  assert.deepEqual(rows(), [{ plan_id: 'starter', limit_key: 'items', soft_value: 400, hard_value: 500 },
    { plan_id: 'starter', limit_key: 'photos', soft_value: null, hard_value: 1000 }]);
  db.exec(`INSERT INTO plan_limits (plan_id, limit_key, soft_value, hard_value) VALUES ('starter', 'photos_per_item', 3, 4)`);
  refused(db, `INSERT INTO plan_limits (plan_id, limit_key, hard_value) VALUES ('starter', 'photos_each', 4)`, /CHECK/);
  refused(db, `INSERT INTO plan_limits (plan_id, limit_key, soft_value, hard_value) VALUES ('starter', 'users', 5, 4)`, /CHECK/);
  refused(db, `INSERT INTO plan_limits (plan_id, limit_key, hard_value) VALUES ('starter', 'items', -1)`, /CHECK/);
  refused(db, `INSERT INTO plan_limits (plan_id, limit_key, hard_value) VALUES ('nope', 'photos_per_item', 4)`, /FOREIGN KEY/);
  refused(db, `INSERT INTO plan_limits (plan_id, limit_key, hard_value) VALUES ('starter', 'items', 1)`, /UNIQUE|PRIMARY KEY/);
  assert.equal(db.prepare(`SELECT count(*) AS n FROM sqlite_master WHERE name LIKE 'plan_limits_0004%'`).get().n, 0);
  assert.equal(db.prepare(`SELECT count(*) AS n FROM plan_limits WHERE plan_id = 'internal'`).get().n, 0);
});

test('0004 applied twice: same schema and rows', () => {
  const db = migrated();
  db.exec(`INSERT INTO plan_limits (plan_id, limit_key, soft_value, hard_value) VALUES ('internal', 'photos_per_item', 8, 12), ('internal', 'users', NULL, 3)`);
  const state = () => [db.prepare('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY name').all().map((r) => ({ ...r })),
    db.prepare('SELECT * FROM plan_limits ORDER BY limit_key').all().map((r) => ({ ...r }))];
  const before = state();
  db.exec(readFileSync(new URL('0004_plan_limits_photos_per_item.sql', dir), 'utf8'));
  assert.deepEqual(state(), before);
  assert.equal(before[1].length, 2);
});


test('0005 limit_overrides: columns, primary key, foreign key, checks, idempotent apply', () => {
  const db = migrated();
  const cols = db.prepare(`PRAGMA table_info('limit_overrides')`).all().map((c) => ({ name: c.name, type: c.type, notnull: c.notnull, pk: c.pk, dflt: c.dflt_value }));
  assert.deepEqual(cols.map((c) => c.name), ['installation_id', 'limit_key', 'hard_value', 'reason', 'expires_at', 'created_by', 'created_at']);
  assert.equal(cols.filter((c) => c.pk).map((c) => c.name).join(','), 'installation_id,limit_key');
  assert.equal(cols.find((c) => c.name === 'hard_value').notnull, 1);
  assert.equal(cols.find((c) => c.name === 'created_by').notnull, 1);
  const fks = db.prepare(`PRAGMA foreign_key_list('limit_overrides')`).all();
  assert.equal(fks.length, 1);
  assert.equal(fks[0].table, 'installations');
  assert.equal(fks[0].from, 'installation_id');
  assert.equal(fks[0].to, 'id');
  db.exec(`INSERT INTO limit_overrides (installation_id, limit_key, hard_value, reason, expires_at, created_by)
    VALUES ('primary', 'photos_per_item', 8, 'raise', NULL, 'ops@example.test')`);
  refused(db, `INSERT INTO limit_overrides (installation_id, limit_key, hard_value, created_by) VALUES ('primary', 'photos_each', 4, 'ops@example.test')`, /CHECK/);
  refused(db, `INSERT INTO limit_overrides (installation_id, limit_key, hard_value, created_by) VALUES ('primary', 'items', -1, 'ops@example.test')`, /CHECK/);
  refused(db, `INSERT INTO limit_overrides (installation_id, limit_key, hard_value, created_by) VALUES ('primary', 'items', 'nope', 'ops@example.test')`, /CHECK/);
  db.exec(`INSERT INTO limit_overrides (installation_id, limit_key, hard_value, created_by) VALUES ('primary', 'items', 0, 'ops@example.test')`);
  assert.equal(db.prepare(`SELECT hard_value AS n FROM limit_overrides WHERE installation_id = 'primary' AND limit_key = 'items'`).get().n, 0);
  refused(db, `INSERT INTO limit_overrides (installation_id, limit_key, hard_value, expires_at, created_by) VALUES ('primary', 'users', 1, 'not-a-date', 'ops@example.test')`, /CHECK/);
  db.exec(`INSERT INTO limit_overrides (installation_id, limit_key, hard_value, expires_at, created_by) VALUES ('primary', 'users', 1, '2099-01-01T00:00:00Z', 'ops@example.test')`);
  refused(db, `INSERT INTO limit_overrides (installation_id, limit_key, hard_value, created_by) VALUES ('nope', 'items', 1, 'ops@example.test')`, /FOREIGN KEY/);
  refused(db, `INSERT INTO limit_overrides (installation_id, limit_key, hard_value, created_by) VALUES ('primary', 'photos_per_item', 9, 'ops@example.test')`, /UNIQUE|PRIMARY KEY/);
  refused(db, `INSERT INTO limit_overrides (installation_id, limit_key, hard_value) VALUES ('primary', 'items', 1)`, /NOT NULL/);
  const sql = readFileSync(new URL('0005_limit_overrides.sql', dir), 'utf8');
  db.exec(sql);
  assert.equal(db.prepare(`SELECT hard_value AS n FROM limit_overrides WHERE installation_id = 'primary' AND limit_key = 'photos_per_item'`).get().n, 8);
  assert.equal(db.prepare(`SELECT count(*) AS n FROM sqlite_master WHERE name LIKE 'limit_overrides%'`).get().n, 1);
});

// Every plan_limits key a migration has ever allowed: a later migration may add keys, never drop one (N-2200).
const LIMIT_KEYS = ['items', 'storage_bytes', 'photos', 'users', 'autofill_calls_month', 'requests_month', 'container_hours_month',
  'sleep_after_ms', 'import_file_bytes', 'photos_per_item'];

test('plan_limits keeps every key it ever allowed after each migration', () => {
  const db = new DatabaseSync(':memory:');
  db.exec('PRAGMA foreign_keys = ON');
  let allowed = [];
  for (const f of files) {
    db.exec(readFileSync(new URL(f, dir), 'utf8'));
    db.exec(`INSERT OR IGNORE INTO plans (id, name) VALUES ('k', 'Keys')`);
    const now = LIMIT_KEYS.filter((key) => {
      try {
        db.exec(`INSERT INTO plan_limits (plan_id, limit_key, hard_value) VALUES ('k', '${key}', 1)`);
        db.exec(`DELETE FROM plan_limits WHERE plan_id = 'k'`);
        return true;
      } catch (e) {
        if (!/CHECK/.test(String(e))) throw e;
        return false;
      }
    });
    for (const key of allowed) assert.ok(now.includes(key), `${f} dropped ${key}`);
    allowed = now;
  }
  assert.deepEqual(allowed, LIMIT_KEYS);
  const overrideKeys = LIMIT_KEYS.filter((key) => {
    try {
      db.exec(`INSERT INTO limit_overrides (installation_id, limit_key, hard_value, created_by) VALUES ('primary', '${key}', 1, 'test')`);
      db.exec(`DELETE FROM limit_overrides WHERE installation_id = 'primary'`);
      return true;
    } catch (e) {
      if (!/CHECK/.test(String(e))) throw e;
      return false;
    }
  });
  assert.deepEqual(overrideKeys, LIMIT_KEYS);
});

test('no foreign key cascades or sets null: every reference is NO ACTION, as 0001 made them', () => {
  const db = migrated();
  const tables = db.prepare(`SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name`).all().map((t) => t.name);
  const refs = tables.flatMap((t) => db.prepare(`PRAGMA foreign_key_list('${t}')`).all().map((k) => `${t}.${k.from} -> ${k.table}.${k.to} ${k.on_update}/${k.on_delete}/${k.match}`));
  assert.deepEqual(refs.sort(), [
    'installation_routes.installation_id -> installations.id NO ACTION/NO ACTION/NONE',
    'lifecycle_jobs.installation_id -> installations.id NO ACTION/NO ACTION/NONE',
    'limit_overrides.installation_id -> installations.id NO ACTION/NO ACTION/NONE',
    'plan_limits.plan_id -> plans.id NO ACTION/NO ACTION/NONE',
    'subscriptions.installation_id -> installations.id NO ACTION/NO ACTION/NONE',
    'subscriptions.plan_id -> plans.id NO ACTION/NO ACTION/NONE',
    'usage_counters.installation_id -> installations.id NO ACTION/NO ACTION/NONE',
  ]);
});
