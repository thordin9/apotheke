-- Lifecycle jobs (edge/src/lifecycle.ts): provision, suspend, resume, export, delete and purge, one row per request.
-- A job is created paused and runs only after an operator confirms it (state queued). idempotency_key is
-- <kind>:<installation_id>:<nonce>, so the same request twice finds the same job. Additive and safe to apply again.
CREATE TABLE IF NOT EXISTS lifecycle_jobs (
  id TEXT NOT NULL PRIMARY KEY,
  installation_id TEXT NOT NULL REFERENCES installations (id),
  kind TEXT NOT NULL CHECK (kind IN ('provision', 'suspend', 'resume', 'export', 'delete', 'purge')),
  idempotency_key TEXT NOT NULL UNIQUE CHECK (length(idempotency_key) <= 300
    AND substr(idempotency_key, 1, length(kind) + length(installation_id) + 2) = kind || ':' || installation_id || ':'
    AND length(idempotency_key) > length(kind) + length(installation_id) + 2),
  state TEXT NOT NULL DEFAULT 'paused' CHECK (state IN ('paused', 'queued', 'running', 'done', 'failed')),
  step TEXT,
  -- Control-plane values only (for example the retention days of a delete, an export's object key), never tenant data.
  params_json TEXT,
  result_json TEXT,
  error_code TEXT,
  created_by TEXT NOT NULL,
  confirmed_by TEXT,
  confirmed_at TEXT,
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
  CHECK (state = 'paused' OR confirmed_by IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS lifecycle_jobs_installation ON lifecycle_jobs (installation_id, created_at);
CREATE INDEX IF NOT EXISTS lifecycle_jobs_state ON lifecycle_jobs (state, updated_at);
