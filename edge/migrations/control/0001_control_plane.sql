-- Control plane for the hosted service (docs/operator-console.md). D1 apotheke-api is the source of truth for
-- installations. Additive only: later migrations add tables or columns, never drop or rename.

CREATE TABLE plans (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- No row for a key means no limit for that plan.
CREATE TABLE plan_limits (
  plan_id TEXT NOT NULL REFERENCES plans (id),
  limit_key TEXT NOT NULL CHECK (limit_key IN ('items', 'storage_bytes', 'photos', 'users', 'autofill_calls_month',
    'requests_month', 'container_hours_month', 'sleep_after_ms', 'import_file_bytes')),
  soft_value INTEGER,
  hard_value INTEGER,
  PRIMARY KEY (plan_id, limit_key)
);

-- do_name is the name given to idFromName (Installation, EU jurisdiction) and getByName (ApothekeContainer);
-- r2_prefix is installations/<do_name>/. Neither ever changes for an installation.
CREATE TABLE installations (
  id TEXT PRIMARY KEY,
  do_name TEXT NOT NULL UNIQUE,
  r2_prefix TEXT NOT NULL UNIQUE CHECK (r2_prefix = 'installations/' || do_name || '/'),
  status TEXT NOT NULL CHECK (status IN ('provisioning', 'active', 'suspended', 'deleting', 'deleted')),
  owner_contact TEXT,
  notes TEXT,
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- Host (lower case, no port) to installation. More than one host may point at one installation.
CREATE TABLE installation_routes (
  host TEXT PRIMARY KEY CHECK (host = lower(host) AND instr(host, ':') = 0),
  installation_id TEXT NOT NULL REFERENCES installations (id),
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);
CREATE INDEX installation_routes_installation ON installation_routes (installation_id);

-- Set by hand in the console; no billing provider.
CREATE TABLE subscriptions (
  installation_id TEXT PRIMARY KEY REFERENCES installations (id),
  plan_id TEXT NOT NULL REFERENCES plans (id),
  status TEXT NOT NULL CHECK (status IN ('trial', 'active', 'past_due', 'cancelled')),
  trial_end TEXT,
  period_end TEXT,
  notes TEXT,
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE TABLE usage_counters (
  installation_id TEXT NOT NULL REFERENCES installations (id),
  period TEXT NOT NULL CHECK (period GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]'),
  metric TEXT NOT NULL,
  value INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (installation_id, period, metric)
);

-- Append-only: control-plane fields only, never tenant content.
CREATE TABLE operator_audit (
  id TEXT PRIMARY KEY,
  at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
  operator TEXT NOT NULL,
  action TEXT NOT NULL,
  installation_id TEXT,
  before_json TEXT,
  after_json TEXT,
  request_id TEXT
);
CREATE INDEX operator_audit_installation ON operator_audit (installation_id, at);
CREATE TRIGGER operator_audit_no_update BEFORE UPDATE ON operator_audit
BEGIN SELECT RAISE(ABORT, 'operator_audit is append-only'); END;
CREATE TRIGGER operator_audit_no_delete BEFORE DELETE ON operator_audit
BEGIN SELECT RAISE(ABORT, 'operator_audit is append-only'); END;
