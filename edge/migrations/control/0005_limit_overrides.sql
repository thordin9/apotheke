-- Per-installation temporary overrides (raise or lower) of a plan hard limit (docs/operator-console.md). Additive only: a new table.
-- One live row per installation and key; grant history is not stored here. Safe to apply again.
CREATE TABLE IF NOT EXISTS limit_overrides (
  installation_id TEXT NOT NULL REFERENCES installations (id),
  limit_key TEXT NOT NULL CHECK (limit_key IN ('items', 'storage_bytes', 'photos', 'users', 'autofill_calls_month',
    'requests_month', 'container_hours_month', 'sleep_after_ms', 'import_file_bytes', 'photos_per_item')),
  hard_value INTEGER NOT NULL CHECK (typeof(hard_value) = 'integer' AND hard_value >= 0),
  reason TEXT,
  expires_at TEXT,
  created_by TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
  PRIMARY KEY (installation_id, limit_key)
);
