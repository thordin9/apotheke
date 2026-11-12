-- Control plane for the hosted service (docs/operator-console.md). D1 apotheke-api is the source of truth for
-- installations. Additive only: later migrations add tables or columns, never drop or rename.

CREATE TABLE plans (
  id TEXT NOT NULL PRIMARY KEY,
  name TEXT NOT NULL,
  active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- No row for a key means no limit for that plan.
CREATE TABLE plan_limits (
  plan_id TEXT NOT NULL REFERENCES plans (id),
  limit_key TEXT NOT NULL CHECK (limit_key IN ('items', 'storage_bytes', 'photos', 'users', 'autofill_calls_month',
    'requests_month', 'container_hours_month', 'sleep_after_ms', 'import_file_bytes')),
  soft_value INTEGER CHECK (soft_value >= 0),
  hard_value INTEGER CHECK (hard_value >= 0),
  PRIMARY KEY (plan_id, limit_key),
  CHECK (soft_value IS NULL OR hard_value IS NULL OR soft_value <= hard_value)
);

-- do_name is the name given to idFromName (Installation, EU jurisdiction) and getByName (ApothekeContainer);
-- r2_prefix is installations/<do_name>/. Neither ever changes for an installation (triggers refuse it, including
-- through REPLACE, which deletes and re-inserts without firing UPDATE triggers). do_name is ^[a-z0-9]+(-[a-z0-9]+)*$.
CREATE TABLE installations (
  id TEXT NOT NULL PRIMARY KEY,
  do_name TEXT NOT NULL UNIQUE CHECK (length(do_name) BETWEEN 1 AND 63 AND do_name NOT GLOB '*[^a-z0-9-]*'
    AND do_name NOT GLOB '-*' AND do_name NOT GLOB '*-' AND do_name NOT GLOB '*--*'),
  r2_prefix TEXT NOT NULL UNIQUE CHECK (r2_prefix = 'installations/' || do_name || '/'),
  status TEXT NOT NULL CHECK (status IN ('provisioning', 'active', 'suspended', 'deleting', 'deleted')),
  owner_contact TEXT,
  notes TEXT,
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);
CREATE TRIGGER installations_names_fixed BEFORE UPDATE OF do_name, r2_prefix ON installations
WHEN NEW.do_name IS NOT OLD.do_name OR NEW.r2_prefix IS NOT OLD.r2_prefix
BEGIN SELECT RAISE(ABORT, 'do_name and r2_prefix never change'); END;
CREATE TRIGGER installations_no_replace BEFORE INSERT ON installations
WHEN EXISTS (SELECT 1 FROM installations WHERE id = NEW.id OR do_name = NEW.do_name OR r2_prefix = NEW.r2_prefix)
BEGIN SELECT RAISE(ABORT, 'installation already exists'); END;

-- Host (lower case, no port, no empty label, no label starting or ending with '-') to installation. More than one host may point at one installation.
CREATE TABLE installation_routes (
  host TEXT NOT NULL PRIMARY KEY CHECK (length(host) BETWEEN 1 AND 253 AND host NOT GLOB '*[^a-z0-9.-]*'
    AND host NOT GLOB '.*' AND host NOT GLOB '*.' AND host NOT GLOB '*..*'
    AND host NOT GLOB '-*' AND host NOT GLOB '*-' AND host NOT GLOB '*.-*' AND host NOT GLOB '*-.*'),
  installation_id TEXT NOT NULL REFERENCES installations (id),
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);
CREATE INDEX installation_routes_installation ON installation_routes (installation_id);

-- Set by hand in the console; no billing provider.
CREATE TABLE subscriptions (
  installation_id TEXT NOT NULL PRIMARY KEY REFERENCES installations (id),
  plan_id TEXT NOT NULL REFERENCES plans (id),
  status TEXT NOT NULL CHECK (status IN ('trial', 'active', 'past_due', 'cancelled')),
  trial_end TEXT,
  period_end TEXT,
  notes TEXT,
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE TABLE usage_counters (
  installation_id TEXT NOT NULL REFERENCES installations (id),
  period TEXT NOT NULL CHECK (period GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]' AND substr(period, 6, 2) BETWEEN '01' AND '12'),
  metric TEXT NOT NULL,
  value INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (installation_id, period, metric)
);

-- Append-only: control-plane fields only, never tenant content.
CREATE TABLE operator_audit (
  id TEXT NOT NULL PRIMARY KEY,
  at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
  operator TEXT NOT NULL,
  action TEXT NOT NULL,
  installation_id TEXT,
  before_json TEXT,
  after_json TEXT,
  request_id TEXT
);
CREATE INDEX operator_audit_installation ON operator_audit (installation_id, at);
CREATE TRIGGER operator_audit_no_replace BEFORE INSERT ON operator_audit
WHEN EXISTS (SELECT 1 FROM operator_audit WHERE id = NEW.id)
BEGIN SELECT RAISE(ABORT, 'operator_audit is append-only'); END;
CREATE TRIGGER operator_audit_no_update BEFORE UPDATE ON operator_audit
BEGIN SELECT RAISE(ABORT, 'operator_audit is append-only'); END;
CREATE TRIGGER operator_audit_no_delete BEFORE DELETE ON operator_audit
BEGIN SELECT RAISE(ABORT, 'operator_audit is append-only'); END;
