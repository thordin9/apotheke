// S1.7's port of `RoleRepositoryTest` (plan D37): the built-in roles a caller sees when there are no
// `roles` rows to read, and `has()` over them.
//
// v3's `RoleRepository` caught a PDOException/DatabaseException and fell back to `Permissions::ROLE_DEFAULTS`
// — the "the table does not exist yet" state of a 2.6.x install. v4 keeps the *answer* (built-ins when the
// read is empty) and drops the *catch*, deliberately: a v4 installation serves only after its migrations
// ran (prepareInstallation, plan §5 S0.3), so a failed `roles` read is an outage and not an old schema.
// Masking it as built-ins would render a roles editor that then fails every write, and would report a
// database that is down as a working install. The last case pins that decision so it cannot drift back.
//
// The failing read is a `Sql` of the test's own (as v3's mock connection was): a dropped table would also
// satisfy the assertion, but it makes the Durable Object log an uncaught exception on the way out.

import { env } from "cloudflare:workers";
import { beforeEach, describe, expect, it } from "vitest";
import { ROLE_DEFAULTS } from "../../src/auth/permissions";
import { has, listed } from "../../src/auth/roles";
import { durableObjectSql, type InstallationStub } from "../../src/db/do-sql";
import type { Sql } from "../../src/db/sql";
import { resetSchema } from "../helpers/schema";

const USERS_TABLE = `CREATE TABLE IF NOT EXISTS users (
	id            INTEGER PRIMARY KEY AUTOINCREMENT,
	username      TEXT NOT NULL,
	username_key  TEXT NOT NULL UNIQUE,
	password_hash TEXT NOT NULL,
	role          TEXT NOT NULL,
	created_at    TEXT NOT NULL,
	last_login_at TEXT,
	status        TEXT NOT NULL DEFAULT 'active',
	session_epoch TEXT NOT NULL DEFAULT ''
)`;
const ROLES_TABLE = `CREATE TABLE IF NOT EXISTS roles (
	id         INTEGER PRIMARY KEY AUTOINCREMENT,
	role_key   TEXT NOT NULL UNIQUE,
	name       TEXT NOT NULL,
	perms      TEXT NOT NULL,
	builtin    INTEGER NOT NULL DEFAULT 0,
	created_at TEXT NOT NULL
)`;

const sqlite = (name: string): Sql =>
	durableObjectSql(env.INSTALLATION.get(env.INSTALLATION.idFromName(name)) as unknown as InstallationStub);

/** The real database, except that a read of `roles` fails the way a missing table does. */
const rolesFailing = (inner: Sql): Sql => ({
	all: async (query) => {
		if (query.sql.includes("FROM roles")) {
			throw new Error("SQLITE_ERROR: no such table: roles");
		}
		return inner.all(query);
	},
	batch: (statements) => inner.batch(statements),
});

describe("RoleRepository::listed() with no rows (RoleRepositoryTest ported)", () => {
	let sql: Sql;

	beforeEach(async () => {
		sql = sqlite("s1.7-roles");
		await resetSchema(sql);
		await sql.batch([{ sql: USERS_TABLE }, { sql: ROLES_TABLE }]);
	});

	it("answers the built-in roles, in ROLE_DEFAULTS order, with v3's names and permissions", async () => {
		const roles = await listed(sql);
		expect(roles.map((role) => [role.key, role.name, [...role.perms]])).toEqual(
			ROLE_DEFAULTS.map(([key, name, perms]) => [key, name, [...perms]]),
		);
		for (const role of roles) {
			expect(role.builtin).toBe(true);
			expect(role.users).toBe(0);
		}
	});

	it("gives admin every permission, an unknown role none, and viewer view but not settings", async () => {
		const roles = await listed(sql);
		const of = (key: string) => roles.find((role) => role.key === key);
		expect(has(of("admin"), "settings")).toBe(true);
		expect(has(of("admin"), "not-a-permission")).toBe(true);
		expect(has(undefined, "view")).toBe(false);
		expect(has(of("viewer"), "view")).toBe(true);
		expect(has(of("viewer"), "settings")).toBe(false);
	});

	it("fails loudly when the read itself fails: v4 does not mask an outage as built-ins", async () => {
		await expect(listed(rolesFailing(sql))).rejects.toThrow("no such table: roles");
	});
});
