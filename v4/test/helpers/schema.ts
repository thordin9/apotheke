// Test helpers for the schema-parity comparison. The normalisation here must stay identical to
// normalise() in test/fixtures/php-schema-dump.php — that is the whole point of the comparison.

import type { Sql } from "../../src/db/sql";

export interface SchemaObject {
	readonly type: string;
	readonly name: string;
	readonly table: string;
	readonly sql: string | null;
}

export interface SchemaDump {
	readonly objects: readonly SchemaObject[];
}

/**
 * The same rule as normalise() in test/fixtures/php-schema-dump.php, in the same order: strip `--`
 * comments first (collapsing newlines before that would let a comment swallow the rest of the
 * statement), then collapse whitespace, then drop the padding around `(`, `)` and `,`.
 */
export const normaliseSql = (sql: string | null): string | null =>
	sql === null
		? null
		: sql
				.replace(/--[^\n]*/g, "")
				.replace(/\s+/g, " ")
				.trim()
				.replace(/\s*([(),])\s*/g, "$1");

/**
 * A virtual table's shadow tables are created by SQLite, not by a migration, and their stored DDL is
 * the engine's own formatting. Their names are compared; their text is not, because two SQLite
 * versions may spell the same shadow table differently.
 */
const isShadowTable = (o: SchemaObject): boolean =>
	o.type === "table" && (o.sql ?? "").startsWith("CREATE TABLE '");

/**
 * D1 keeps its own bookkeeping table (`_cf_METADATA`) inside the database; it is not part of the
 * schema and cannot be dropped, so every helper here ignores it by name.
 */
const isDriverInternal = (name: string): boolean => name.startsWith("_cf_");

export const dumpSchema = async (sql: Sql): Promise<SchemaObject[]> => {
	const rows = await sql.all({
		sql: "SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name",
	});

	return rows
		.filter((r) => !isDriverInternal(String(r.name)))
		.map((r) => ({
			type: String(r.type),
			name: String(r.name),
			table: String(r.tbl_name),
			sql: r.sql === null ? null : String(r.sql),
		}));
};

/**
 * G0's rule (plan §5, gate G0): v4 ⊇ PHP, and the difference is exactly the allowlist.
 * Returns one line per difference, so a failure says what is missing, extra or changed.
 */
export const compareSchema = (
	php: readonly SchemaObject[],
	v4: readonly SchemaObject[],
	allowlist: readonly string[],
): string[] => {
	const byName = (list: readonly SchemaObject[]) => new Map(list.map((o) => [o.name, o]));
	const phpByName = byName(php);
	const v4ByName = byName(v4);
	const allowed = new Set(allowlist);
	const problems: string[] = [];

	for (const o of php) {
		const mine = v4ByName.get(o.name);
		if (mine === undefined) {
			problems.push(`missing in v4: ${o.type} ${o.name}`);
			continue;
		}
		if (mine.type !== o.type) {
			problems.push(`type differs: ${o.name} is ${mine.type} in v4, ${o.type} in PHP`);
		}
		if (!isShadowTable(o) && normaliseSql(mine.sql) !== normaliseSql(o.sql)) {
			problems.push(
				`sql differs: ${o.name}\n  PHP: ${normaliseSql(o.sql)}\n  v4:  ${normaliseSql(mine.sql)}`,
			);
		}
	}

	for (const o of v4) {
		if (!phpByName.has(o.name) && !allowed.has(o.name)) {
			problems.push(`extra in v4 and not allowlisted: ${o.type} ${o.name}`);
		}
	}

	for (const name of allowed) {
		if (!v4ByName.has(name)) {
			problems.push(`allowlisted but absent in v4: ${name}`);
		}
	}

	return problems;
};

/**
 * Drop everything the runner created, so one test file can build several databases in sequence.
 *
 * Virtual tables go first and on their own: SQLite refuses `DROP TABLE` on an FTS5 shadow table
 * ("table search_fts_config may not be dropped") and the shadows disappear with their virtual table.
 */
export const resetSchema = async (sql: Sql): Promise<void> => {
	const virtual = await sql.all({
		sql: "SELECT name FROM sqlite_master WHERE type = 'table' AND sql LIKE 'CREATE VIRTUAL TABLE%'",
	});
	for (const v of virtual) {
		await sql.batch([{ sql: `DROP TABLE IF EXISTS "${String(v.name)}"` }]);
	}

	const objects = await sql.all({
		sql: "SELECT type, name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY rowid DESC",
	});
	for (const o of objects.filter((o) => !isDriverInternal(String(o.name)))) {
		const type = String(o.type);
		const name = String(o.name);
		if (type === "table") {
			await sql.batch([{ sql: `DROP TABLE IF EXISTS "${name}"` }]);
		} else if (type === "index") {
			await sql.batch([{ sql: `DROP INDEX IF EXISTS "${name}"` }]);
		} else if (type === "view") {
			await sql.batch([{ sql: `DROP VIEW IF EXISTS "${name}"` }]);
		} else {
			throw new Error(`resetSchema does not know how to drop a ${type} (${name})`);
		}
	}
};
