// Citations (D168/D172): every `modules/`, `tests/Modules` and `app/Config` path below is read at
// `5d65939e^`, the last commit before the PHP app was retired.
//
// The 35 PHP migrations as typed steps, plus 0036, which exists only in v4 (D10's `_write_log`).
// One SQLite dialect: D1, rqlite and local DO SQLite all run this file, and there is no dialect
// branch anywhere in it (plan §10 rule 4).
//
// Shapes, both faithful to the PHP files under `migrations/`:
//   - `sql`: statements with no data dependency, in the order PHP ran them;
//   - `plan()`: a step whose statements depend on rows (0002's sort keys, 0018's adopt path,
//     0028's session fingerprints, 0033's duplicate cleanup). The reads happen while planning and the
//     writes go into the one batch, which is the same contract every other step follows — a batch is
//     sent in one shot and TypeScript never sees an intermediate result (§10.2).
//
// `deferredSeed` marks a step whose only job is writing configuration rows. Those rows are the S0.3
// config loader's single source of truth, so this port leaves them alone rather than seeding them
// from a second copy of the defaults; the seed phase (S0.3) must reproduce them, and `/readyz` refuses
// to serve until it has (the runner stamps `v4_seed=pending` on a fresh database).
//
// The schema this produces is held to PHP's by `test/schema-parity.spec.ts`: v4 must contain every
// object the PHP runner creates, and v4 may add exactly `_write_log` and its index — nothing else.

import { natKey } from "./natural-key";
import type { Statement as DriverStatement, Row } from "./sql";

/** A statement plus the one bit the runner needs: whether it may be dropped if the backend lacks FTS5. */
export interface Statement extends DriverStatement {
	readonly optional?: boolean;
}

export interface MigrationContext {
	/** `gmdate('Y-m-d H:i:s')` at the start of the run. */
	readonly now: string;
	/** A read, answered by whichever backend is running. Never a write. */
	all(sql: string, params?: readonly unknown[]): Promise<Row[]>;
}

export interface Migration {
	/** Four digits, `schema_migrations.version` on every backend. */
	readonly version: string;
	readonly name: string;
	readonly sql?: readonly string[];
	plan?(ctx: MigrationContext): Promise<readonly Statement[]>;
	/** Set when the step writes configuration rows that belong to S0.3's seed phase. */
	readonly deferredSeed?: string;
}

/** SHA-256 of the first `length` characters, hex — the shape 0028 stores. */
const shortSha256 = async (value: string, length: number): Promise<string> => {
	const bytes = new TextEncoder().encode(value);
	const digest = await crypto.subtle.digest("SHA-256", bytes);

	return [...new Uint8Array(digest)]
		.map((b) => b.toString(16).padStart(2, "0"))
		.join("")
		.slice(0, length);
};

/**
 * 0022 inserts the role rows the 2.6.x migration inserted (`ROLE_DEFAULTS` in
 * modules/Migrations/LegacyFunctions.php) — constant data, not configuration, so it stays here.
 * The newer permission lists are applied by `FreshInstallRoles::statements()`, which is deferred.
 */
const LEGACY_ROLE_DEFAULTS: ReadonlyArray<readonly [string, string, readonly string[]]> = [
	[
		"admin",
		"Admin",
		[
			"view",
			"create",
			"edit",
			"delete",
			"move",
			"bulk",
			"lend",
			"import",
			"export",
			"stocktake",
			"labels",
			"settings",
			"users",
			"backup",
		],
	],
	[
		"editor",
		"Editor",
		["view", "create", "edit", "delete", "move", "bulk", "lend", "import", "export", "stocktake", "labels"],
	],
	["viewer", "Viewer", ["view", "export", "labels"]],
	["guest", "Guest", ["view"]],
];

export const MIGRATIONS: readonly Migration[] = [
	{
		version: "0001",
		name: "initial_schema",
		sql: [
			`CREATE TABLE locations (
				id         INTEGER PRIMARY KEY AUTOINCREMENT,
				name       TEXT    NOT NULL,
				name_key   TEXT    NOT NULL UNIQUE,   -- fold(name): case/accent-insensitive uniqueness
				is_default INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1)),
				created_at TEXT    NOT NULL
			)`,
			"CREATE UNIQUE INDEX idx_locations_one_default ON locations(is_default) WHERE is_default = 1",
			`CREATE TABLE categories (
				id         INTEGER PRIMARY KEY AUTOINCREMENT,
				name       TEXT    NOT NULL,
				name_key   TEXT    NOT NULL UNIQUE,
				is_default INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1)),
				created_at TEXT    NOT NULL
			)`,
			"CREATE UNIQUE INDEX idx_categories_one_default ON categories(is_default) WHERE is_default = 1",
			`CREATE TABLE items (
				id          INTEGER PRIMARY KEY AUTOINCREMENT,
				name        TEXT    NOT NULL,
				location_id INTEGER REFERENCES locations(id) ON DELETE SET NULL,
				quantity    INTEGER NOT NULL DEFAULT 1 CHECK (quantity >= 0),
				notes       TEXT    NOT NULL DEFAULT '',
				image       TEXT,
				search      TEXT    NOT NULL DEFAULT '',  -- fold(name + notes)
				created_at  TEXT    NOT NULL,
				updated_at  TEXT    NOT NULL,
				category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL
			)`,
			"CREATE INDEX idx_items_location ON items(location_id)",
			"CREATE INDEX idx_items_category ON items(category_id)",
			"CREATE INDEX idx_items_name     ON items(name COLLATE NOCASE)",
			"CREATE INDEX idx_items_updated  ON items(updated_at)",
		],
	},
	{
		version: "0002",
		name: "sort_keys_and_settings",
		// Portable natural sorting (the key is computed in PHP, so it is computed here) plus
		// app_settings. The reads happen before the ALTERs: `sort_key` has a default, so the rows the
		// key is computed from are the same either way, and the batch is one unit.
		plan: async (ctx) => {
			const keys: Array<{ table: string; rows: Row[] }> = [];
			for (const table of ["locations", "categories", "items"]) {
				keys.push({ table, rows: await ctx.all(`SELECT id, name FROM ${table}`) });
			}
			const out: Statement[] = [];
			for (const { table, rows } of keys) {
				out.push({ sql: `ALTER TABLE ${table} ADD COLUMN sort_key TEXT NOT NULL DEFAULT ''` });
				for (const row of rows) {
					out.push({
						sql: `UPDATE ${table} SET sort_key = ? WHERE id = ?`,
						params: [natKey(String(row.name)), row.id],
					});
				}
				out.push({ sql: `CREATE INDEX idx_${table}_sort ON ${table}(sort_key)` });
			}
			out.push({ sql: "CREATE TABLE app_settings (name TEXT NOT NULL PRIMARY KEY, value TEXT NOT NULL)" });

			return out;
		},
	},
	{
		version: "0003",
		name: "item_photos",
		sql: [
			`CREATE TABLE item_photos (
				id         INTEGER PRIMARY KEY AUTOINCREMENT,
				item_id    INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
				file       TEXT    NOT NULL,
				position   INTEGER NOT NULL DEFAULT 0,
				created_at TEXT    NOT NULL
			)`,
			"CREATE INDEX idx_item_photos_item ON item_photos(item_id, position)",
			"INSERT INTO item_photos (item_id, file, position, created_at) SELECT id, image, 0, updated_at FROM items WHERE image IS NOT NULL",
		],
	},
	{
		version: "0004",
		name: "tags_and_custom_fields",
		sql: [
			`CREATE TABLE tags (
				id         INTEGER PRIMARY KEY AUTOINCREMENT,
				name       TEXT NOT NULL,
				name_key   TEXT NOT NULL UNIQUE,
				sort_key   TEXT NOT NULL DEFAULT '',
				created_at TEXT NOT NULL
			)`,
			`CREATE TABLE item_tags (
				item_id INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
				tag_id  INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
				PRIMARY KEY (item_id, tag_id)
			)`,
			"CREATE INDEX idx_item_tags_tag ON item_tags(tag_id)",
			"ALTER TABLE items ADD COLUMN value_cents BIGINT",
			"ALTER TABLE items ADD COLUMN purchase_date TEXT",
			"ALTER TABLE items ADD COLUMN serial TEXT NOT NULL DEFAULT ''",
		],
	},
	{
		version: "0005",
		name: "activity_log",
		sql: [
			`CREATE TABLE activity (
				id         INTEGER PRIMARY KEY AUTOINCREMENT,
				item_id    INTEGER,
				item_name  TEXT NOT NULL,
				action     TEXT NOT NULL,
				details    TEXT NOT NULL DEFAULT '{}',
				created_at TEXT NOT NULL
			)`,
			"CREATE INDEX idx_activity_item ON activity(item_id, id)",
		],
	},
	{
		version: "0006",
		name: "low_stock",
		sql: ["ALTER TABLE items ADD COLUMN min_qty INTEGER"],
	},
	{
		version: "0007",
		name: "lending",
		sql: [
			"ALTER TABLE items ADD COLUMN lent_to TEXT",
			"ALTER TABLE items ADD COLUMN lent_at TEXT",
			"ALTER TABLE items ADD COLUMN lent_due TEXT",
			"CREATE INDEX idx_items_lent ON items(lent_due) ",
		],
	},
	{
		version: "0008",
		name: "nested_locations",
		sql: [
			"ALTER TABLE locations ADD COLUMN parent_id INTEGER REFERENCES locations(id) ON DELETE SET NULL",
			"CREATE INDEX idx_locations_parent ON locations(parent_id)",
		],
	},
	{
		version: "0009",
		name: "expiry",
		sql: [
			"ALTER TABLE items ADD COLUMN expires_on TEXT",
			"CREATE INDEX idx_items_expires ON items(expires_on)",
		],
	},
	{
		version: "0010",
		name: "value_index",
		sql: ["CREATE INDEX idx_items_value ON items(value_cents)"],
	},
	{
		version: "0011",
		name: "saved_filters",
		sql: [
			`CREATE TABLE saved_filters (
				id         INTEGER PRIMARY KEY AUTOINCREMENT,
				name       TEXT NOT NULL,
				name_key   TEXT NOT NULL UNIQUE,
				query      TEXT NOT NULL,
				sort_key   TEXT NOT NULL,
				created_at TEXT NOT NULL
			)`,
		],
	},
	{
		version: "0012",
		name: "barcode",
		sql: ["ALTER TABLE items ADD COLUMN barcode TEXT", "CREATE INDEX idx_items_barcode ON items(barcode)"],
	},
	{
		version: "0013",
		name: "settings",
		// The table is schema; its rows come from settings_schema()/setting_default() and belong to
		// S0.3's config loader.
		sql: [
			`CREATE TABLE settings (
				name       TEXT NOT NULL PRIMARY KEY,
				value      TEXT NOT NULL,
				updated_at TEXT NOT NULL
			)`,
		],
		deferredSeed: "settings_seed() — every settings row (S0.3 config loader)",
	},
	{
		version: "0014",
		name: "attachments",
		sql: [
			`CREATE TABLE item_attachments (
				id         INTEGER PRIMARY KEY AUTOINCREMENT,
				item_id    INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
				file       TEXT NOT NULL UNIQUE,
				name       TEXT NOT NULL,
				mime       TEXT NOT NULL,
				size       BIGINT NOT NULL,
				created_at TEXT NOT NULL
			)`,
			"CREATE INDEX idx_item_attachments_item ON item_attachments(item_id)",
		],
	},
	{
		version: "0015",
		name: "favourites_recent",
		sql: [
			"ALTER TABLE items ADD COLUMN favourite INTEGER NOT NULL DEFAULT 0",
			"CREATE INDEX idx_items_favourite ON items(favourite)",
			`CREATE TABLE recent_views (
				scope     TEXT NOT NULL,
				item_id   INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
				seq       BIGINT NOT NULL,
				viewed_at TEXT NOT NULL,
				PRIMARY KEY (scope, item_id)
			)`,
			"CREATE INDEX idx_recent_views_scope ON recent_views(scope, seq)",
			"CREATE INDEX idx_recent_views_item ON recent_views(item_id)",
		],
	},
	{
		version: "0016",
		name: "undo",
		sql: [
			`CREATE TABLE undo_tokens (
				token      TEXT PRIMARY KEY,
				action     TEXT NOT NULL,
				label      TEXT NOT NULL,
				payload    TEXT NOT NULL,
				files      TEXT NOT NULL,
				created_at TEXT NOT NULL,
				expires_at TEXT NOT NULL
			)`,
			"CREATE INDEX idx_undo_tokens_expires ON undo_tokens(expires_at)",
		],
	},
	{
		version: "0017",
		name: "stocktake",
		sql: [
			`CREATE TABLE stocktakes (
				id            INTEGER PRIMARY KEY AUTOINCREMENT,
				location_id   INTEGER REFERENCES locations(id) ON DELETE SET NULL,
				location_name TEXT NOT NULL,
				status        TEXT NOT NULL,
				started_at    TEXT NOT NULL,
				finished_at   TEXT,
				moves_applied INTEGER NOT NULL DEFAULT 0,
				summary       TEXT NOT NULL DEFAULT '{}'
			)`,
			"CREATE INDEX idx_stocktakes_status ON stocktakes(status, location_id)",
			`CREATE TABLE stocktake_lines (
				id               INTEGER PRIMARY KEY AUTOINCREMENT,
				stocktake_id     INTEGER NOT NULL REFERENCES stocktakes(id) ON DELETE CASCADE,
				item_id          INTEGER REFERENCES items(id) ON DELETE SET NULL,
				item_name        TEXT NOT NULL,
				expected         INTEGER NOT NULL,
				home_location_id INTEGER,
				home_location    TEXT NOT NULL DEFAULT '',
				found            INTEGER NOT NULL DEFAULT 0,
				found_at         TEXT,
				moved            INTEGER NOT NULL DEFAULT 0
			)`,
			"CREATE UNIQUE INDEX idx_stocktake_lines_item ON stocktake_lines(stocktake_id, item_id)",
		],
	},
	{
		version: "0018",
		name: "users",
		// The adopt path: an existing shared password becomes the account "admin". On a fresh v4
		// install there are no settings rows yet (their seed is deferred), so this is a no-op there —
		// which is exactly why the seed gate exists before the app serves traffic.
		plan: async (ctx) => {
			const out: Statement[] = [
				{
					sql: `CREATE TABLE users (
					id            INTEGER PRIMARY KEY AUTOINCREMENT,
					username      TEXT NOT NULL,
					username_key  TEXT NOT NULL UNIQUE,
					password_hash TEXT NOT NULL,
					role          TEXT NOT NULL,
					created_at    TEXT NOT NULL,
					last_login_at TEXT
				)`,
				},
				{ sql: "ALTER TABLE activity ADD COLUMN actor TEXT" },
			];
			const rows = await ctx.all(
				"SELECT name, value FROM settings WHERE name IN ('auth_enabled', 'password_hash')",
			);
			const set = new Map(rows.map((r) => [String(r.name), String(r.value ?? "")]));
			const parsed: unknown = JSON.parse(set.get("password_hash") ?? '""');
			if (typeof parsed === "string" && parsed !== "") {
				const authEnabled: unknown = JSON.parse(set.get("auth_enabled") ?? "false");
				if (authEnabled === true) {
					out.push({
						sql: "INSERT INTO users (username, username_key, password_hash, role, created_at) VALUES (?,?,?,?,?)",
						params: ["admin", "admin", parsed, "admin", ctx.now],
					});
				}
				out.push({ sql: "UPDATE settings SET value = '\"\"' WHERE name = 'password_hash'" });
			}

			return out;
		},
	},
	{
		version: "0019",
		name: "search",
		// FTS5 is optional on every backend: PHP caught the create and fell back to substring search.
		// The batch cannot catch, so the two statements are `optional` and the runner retries without
		// them when the backend has no fts5 module. `SearchIndex::engine()` keeps probing at runtime,
		// so nothing needs to remember which way this went.
		plan: async () => [
			{
				sql: `CREATE TABLE search_docs (
					item_id  BIGINT NOT NULL PRIMARY KEY REFERENCES items(id) ON DELETE CASCADE,
					name_f   TEXT NOT NULL,
					tags_f   TEXT NOT NULL,
					other_f  TEXT NOT NULL,
					other    TEXT NOT NULL
				)`,
			},
			{ sql: "CREATE TABLE search_meta (k TEXT NOT NULL PRIMARY KEY, v TEXT NOT NULL)" },
			{
				sql: "CREATE VIRTUAL TABLE search_fts USING fts5(name, tags, other, tokenize = 'unicode61 remove_diacritics 2')",
				optional: true,
			},
			{ sql: "CREATE VIRTUAL TABLE search_fts_v USING fts5vocab(search_fts, 'row')", optional: true },
		],
	},
	{
		version: "0020",
		name: "import",
		sql: [
			`CREATE TABLE imports (
				id          INTEGER PRIMARY KEY AUTOINCREMENT,
				file_name   TEXT NOT NULL,
				status      TEXT NOT NULL,
				total_rows  INTEGER NOT NULL DEFAULT 0,
				mapping     TEXT NOT NULL,
				options     TEXT NOT NULL,
				result      TEXT,
				actor       TEXT,
				created_at  TEXT NOT NULL,
				finished_at TEXT
			)`,
		],
	},
	{
		version: "0021",
		name: "photo_versions",
		sql: [
			"ALTER TABLE item_photos ADD COLUMN version INTEGER NOT NULL DEFAULT 1",
			"ALTER TABLE item_photos ADD COLUMN edited_at TEXT",
		],
	},
	{
		version: "0022",
		name: "roles",
		plan: async (ctx) => {
			const out: Statement[] = [
				{
					sql: `CREATE TABLE roles (
					id         INTEGER PRIMARY KEY AUTOINCREMENT,
					role_key   TEXT NOT NULL UNIQUE,
					name       TEXT NOT NULL,
					perms      TEXT NOT NULL,
					builtin    INTEGER NOT NULL DEFAULT 0,
					created_at TEXT NOT NULL
				)`,
				},
			];
			for (const [key, name, perms] of LEGACY_ROLE_DEFAULTS) {
				out.push({
					sql: "INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES (?,?,?,1,?)",
					params: [key, name, JSON.stringify(perms), ctx.now],
				});
			}
			out.push(
				{ sql: "ALTER TABLE users ADD COLUMN status TEXT NOT NULL DEFAULT 'active'" },
				{ sql: "UPDATE users SET role = 'viewer' WHERE role NOT IN ('admin', 'editor', 'viewer')" },
				{
					sql: `CREATE TABLE auth_attempts (
					id     INTEGER PRIMARY KEY AUTOINCREMENT,
					kind   TEXT NOT NULL,
					client TEXT NOT NULL,
					at     INTEGER NOT NULL
				)`,
				},
				{ sql: "CREATE INDEX auth_attempts_k ON auth_attempts (kind, client, at)" },
			);

			return out;
		},
		deferredSeed:
			"settings_seed() — the settings rows 0022 seeds, and FreshInstallRoles::statements() (S0.3)",
	},
	{
		version: "0023",
		name: "autofill",
		// No schema change: the step only seeded autofill settings.
		sql: [],
		deferredSeed: "settings_seed() — autofill_enabled/url/timeout/token defaults (S0.3)",
	},
	{
		version: "0024",
		name: "autofill_timeout",
		// No schema change. The UPDATE moves the seeded 30 to 50; without the seed there is no row to
		// move, so the seed phase (S0.3) owns both halves of it.
		sql: [],
		deferredSeed: "settings row write — autofill_timeout 30 → 50 (S0.3)",
	},
	{
		version: "0025",
		name: "autofill_on_demand",
		sql: [],
		deferredSeed: "settings row writes — autofill_auto/model/image_size (S0.3)",
	},
	{
		version: "0026",
		name: "autofill_max_image_bytes",
		sql: [],
		deferredSeed: "settings row write — autofill_max_image_bytes (S0.3)",
	},
	{
		version: "0027",
		name: "session_idle_hours",
		sql: [],
		deferredSeed: "settings row write — session_idle_hours (S0.3)",
	},
	{
		version: "0028",
		name: "session_epoch",
		plan: async (ctx) => {
			const columns = await ctx.all("SELECT name FROM pragma_table_info('users')");
			const out: Statement[] = [];
			const have = columns.some((c) => String(c.name) === "session_epoch");
			if (!have) {
				out.push({ sql: "ALTER TABLE users ADD COLUMN session_epoch TEXT NOT NULL DEFAULT ''" });
			}
			// `have` decides the read, because the ALTER above is in the batch that has not been sent
			// yet: a re-run of this step on a database that already has the column still sees it.
			const users = await ctx.all(
				have
					? "SELECT id, password_hash FROM users WHERE session_epoch = ''"
					: "SELECT id, password_hash FROM users",
			);
			for (const user of users) {
				out.push({
					sql: "UPDATE users SET session_epoch = ? WHERE id = ?",
					params: [await shortSha256(`${String(user.password_hash)}|${String(user.id)}`, 16), user.id],
				});
			}

			return out;
		},
	},
	{
		version: "0029",
		name: "jobs",
		sql: [
			`CREATE TABLE jobs (
				id           INTEGER PRIMARY KEY AUTOINCREMENT,
				type         TEXT NOT NULL,
				status       TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed')),
				payload      TEXT NOT NULL,
				checkpoint   TEXT,
				result       TEXT,
				error        TEXT,
				attempts     INTEGER NOT NULL DEFAULT 0,
				max_attempts INTEGER NOT NULL DEFAULT 3,
				actor        TEXT,
				lease_owner  TEXT,
				lease_until  TEXT,
				run_after    TEXT NOT NULL,
				created_at   TEXT NOT NULL,
				updated_at   TEXT NOT NULL,
				started_at   TEXT,
				finished_at  TEXT
			)`,
			"CREATE INDEX idx_jobs_due ON jobs(status, run_after, id)",
		],
	},
	{
		version: "0030",
		name: "import_rows",
		sql: [
			`CREATE TABLE import_rows (
				import_id INTEGER NOT NULL,
				line      INTEGER NOT NULL,
				state     TEXT NOT NULL CHECK (state IN ('new', 'update', 'same', 'error')),
				error     TEXT NOT NULL DEFAULT '',
				made      TEXT NOT NULL DEFAULT '{}',
				PRIMARY KEY (import_id, line)
			)`,
		],
	},
	{
		version: "0031",
		name: "unit_of_work",
		sql: [
			"CREATE TABLE uow_guard (ok INTEGER NOT NULL)",
			"CREATE TABLE uow_refs (ulid TEXT PRIMARY KEY, id BIGINT NOT NULL)",
		],
	},
	{
		version: "0032",
		name: "restore_marker",
		sql: [
			"CREATE TABLE restore_marker (run TEXT PRIMARY KEY, sha256 TEXT NOT NULL, applied_at TEXT NOT NULL)",
		],
	},
	{
		version: "0033",
		name: "restore_job_once",
		plan: async (ctx) => {
			const rows = await ctx.all(
				"SELECT id, payload FROM jobs WHERE type = 'restore.confirm' AND status IN ('queued', 'running') ORDER BY id",
			);
			const seen = new Set<string>();
			const out: Statement[] = [];
			for (const row of rows) {
				const payload = String(row.payload);
				if (seen.has(payload)) {
					out.push({
						sql: "UPDATE jobs SET status = 'failed', error = 'This restore is already queued.', finished_at = created_at, updated_at = created_at WHERE id = ?",
						params: [row.id as number],
					});
					continue;
				}
				seen.add(payload);
			}
			out.push({
				sql: "CREATE UNIQUE INDEX idx_jobs_restore_confirm_active ON jobs (payload) WHERE type = 'restore.confirm' AND status IN ('queued', 'running')",
			});

			return out;
		},
	},
	{
		version: "0034",
		name: "sessions",
		sql: [
			`CREATE TABLE sessions (
				id           TEXT NOT NULL PRIMARY KEY,
				data         TEXT NOT NULL,
				expires_at   INTEGER NOT NULL,
				lock_token   TEXT NOT NULL DEFAULT '',
				locked_until INTEGER NOT NULL DEFAULT 0
			)`,
			"CREATE INDEX sessions_expires_at ON sessions (expires_at)",
		],
	},
	{
		version: "0035",
		name: "hosted_disabled_users",
		sql: [
			`CREATE TABLE hosted_disabled_users (
				user_id     INTEGER NOT NULL PRIMARY KEY,
				disabled_at TEXT NOT NULL
			)`,
		],
	},
	{
		version: "0036",
		name: "write_log",
		// v4-only (D10, plan §10 rule 10): the idempotency key every write unit opens with.
		// The allowlist in test/schema-parity.spec.ts names this table and this index, exactly.
		sql: [
			`CREATE TABLE _write_log (
				id         TEXT NOT NULL PRIMARY KEY,
				created_at TEXT NOT NULL
			)`,
			"CREATE INDEX idx_write_log_created ON _write_log(created_at)",
		],
	},
];

/** The versions the v4 code knows; a database ahead of this list is refused, never downgraded. */
export const KNOWN_VERSIONS: readonly string[] = MIGRATIONS.map((m) => m.version);

/** What a fresh install still owes the S0.3 seed phase, in the order PHP applied it. */
export const DEFERRED_SEEDS: readonly { version: string; what: string }[] = MIGRATIONS.filter(
	(m) => m.deferredSeed,
).map((m) => ({ version: m.version, what: m.deferredSeed as string }));
