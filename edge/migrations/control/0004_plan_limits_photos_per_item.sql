-- Plan limit key photos_per_item: the most photos one item may have (the app's max_photos setting is capped by it).
-- photos stays the total across all items. 0001 lists the keys in a CHECK, which SQLite cannot alter, so plan_limits
-- is rebuilt with the same columns and rows and one more allowed key. Nothing references plan_limits. Safe to apply
-- again: a second run rebuilds it the same way. No limit rows are added, so no plan gets a new limit.
CREATE TABLE IF NOT EXISTS plan_limits_0004 (
  plan_id TEXT NOT NULL REFERENCES plans (id),
  limit_key TEXT NOT NULL CHECK (limit_key IN ('items', 'storage_bytes', 'photos', 'users', 'autofill_calls_month',
    'requests_month', 'container_hours_month', 'sleep_after_ms', 'import_file_bytes', 'photos_per_item')),
  soft_value INTEGER CHECK (soft_value >= 0),
  hard_value INTEGER CHECK (hard_value >= 0),
  PRIMARY KEY (plan_id, limit_key),
  CHECK (soft_value IS NULL OR hard_value IS NULL OR soft_value <= hard_value)
);
INSERT INTO plan_limits_0004 (plan_id, limit_key, soft_value, hard_value)
  SELECT plan_id, limit_key, soft_value, hard_value FROM plan_limits;
DROP TABLE plan_limits;
ALTER TABLE plan_limits_0004 RENAME TO plan_limits;
