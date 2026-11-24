// The session store (plan §5 S1.2): the row v3 would have written, the expiry rules, and the epoch
// check that makes "sign out everywhere" mean something.

import { env } from "cloudflare:workers";
import { beforeEach, describe, expect, it } from "vitest";
import { durableObjectSql, type InstallationStub } from "../../src/db/do-sql";
import type { Sql } from "../../src/db/sql";
import { sessionEpoch } from "../../src/session/epoch";
import { encodeSession } from "../../src/session/php-session";
import {
	authenticate,
	destroySession,
	loadSession,
	sessionExpired,
	sessionKey,
	writeSession,
} from "../../src/session/store";

const sqlite = (name: string): Sql =>
	durableObjectSql(env.INSTALLATION.get(env.INSTALLATION.idFromName(name)) as unknown as InstallationStub);

const NOW = 1_764_000_000;
const LIFETIME = 30 * 86_400;
const ID = "abcdefghijklmnopqrstuv"; // 22 characters, the shortest a session id may be

const VALUES = {
	uid: 7,
	authEpoch: "a1b2c3d4e5f60718",
	csrf: "5f".repeat(32),
	lastActivity: NOW - 10,
	signedInAt: NOW - 100,
};

const PASSWORD_HASH = "$2y$12$abcdefghijklmnopqrstuuJ0hR3S7b9m0m2mC7t6y5o4k3j2h1g0f9";

/** The three tables this file needs, at v4's own definitions (0034, 0018, 0026). */
const createTables = async (sql: Sql): Promise<void> => {
	await sql.batch([
		{
			sql: `CREATE TABLE IF NOT EXISTS sessions (
				id           TEXT NOT NULL PRIMARY KEY,
				data         TEXT NOT NULL,
				expires_at   INTEGER NOT NULL,
				lock_token   TEXT NOT NULL DEFAULT '',
				locked_until INTEGER NOT NULL DEFAULT 0
			)`,
		},
		{ sql: "CREATE INDEX IF NOT EXISTS sessions_expires_at ON sessions (expires_at)" },
		{
			sql: `CREATE TABLE IF NOT EXISTS users (
				id            INTEGER PRIMARY KEY AUTOINCREMENT,
				username      TEXT NOT NULL,
				username_key  TEXT NOT NULL UNIQUE,
				password_hash TEXT NOT NULL,
				role          TEXT NOT NULL,
				created_at    TEXT NOT NULL,
				last_login_at TEXT,
				session_epoch TEXT NOT NULL DEFAULT ''
			)`,
		},
		{
			sql: `CREATE TABLE IF NOT EXISTS auth_attempts (
				id     INTEGER PRIMARY KEY AUTOINCREMENT,
				kind   TEXT NOT NULL,
				client TEXT NOT NULL,
				at     INTEGER NOT NULL
			)`,
		},
	]);
};

describe("the session store", () => {
	let sql: Sql;

	beforeEach(async () => {
		sql = sqlite("sessions-store");
		await sql.batch([
			{ sql: "DROP TABLE IF EXISTS sessions" },
			{ sql: "DROP TABLE IF EXISTS auth_attempts" },
			{ sql: "DROP TABLE IF EXISTS users" },
		]);
		await createTables(sql);
	});

	it("writes the row v3 writes: hashed id, PHP-serialised data, the same five columns", async () => {
		await writeSession(sql, { id: ID, values: VALUES, now: NOW, lifetimeSeconds: LIFETIME });
		const rows = await sql.all({ sql: "SELECT * FROM sessions" });
		expect(rows).toHaveLength(1);
		const row = rows[0] ?? {};
		// key(): the SHA-256 of the cookie value, never the value itself.
		expect(String(row.id)).toBe(await sessionKey(ID));
		expect(String(row.id)).toMatch(/^[0-9a-f]{64}$/);
		expect(String(row.data)).toBe(encodeSession(VALUES));
		expect(Number(row.expires_at)).toBe(NOW + LIFETIME);
		expect(String(row.lock_token)).toBe("");
		expect(Number(row.locked_until)).toBe(0);
	});

	it("replaces the row on a rewrite instead of growing a second one", async () => {
		await writeSession(sql, { id: ID, values: VALUES, now: NOW, lifetimeSeconds: LIFETIME });
		await writeSession(sql, {
			id: ID,
			values: { ...VALUES, lastActivity: NOW },
			now: NOW,
			lifetimeSeconds: LIFETIME,
		});
		const rows = await sql.all({ sql: "SELECT COUNT(*) AS n FROM sessions" });
		expect(Number(rows[0]?.n)).toBe(1);
		const loaded = await loadSession(sql, ID, NOW);
		expect(loaded?.values.lastActivity).toBe(NOW);
	});

	it("refuses an id that is not a session id before it hashes one", async () => {
		await writeSession(sql, { id: ID, values: VALUES, now: NOW, lifetimeSeconds: LIFETIME });
		expect(await loadSession(sql, "short", NOW)).toBeNull();
		expect(await loadSession(sql, `${ID}/../`, NOW)).toBeNull();
		// And nothing was deleted by the refusal: a malformed id names no row, so it cannot destroy one.
		expect(await loadSession(sql, ID, NOW)).not.toBeNull();
	});

	it("removes an expired row on read, so a copied cookie stops naming anything", async () => {
		await writeSession(sql, { id: ID, values: VALUES, now: NOW, lifetimeSeconds: 60 });
		const later = NOW + 61;
		expect(await loadSession(sql, ID, later)).toBeNull();
		const rows = await sql.all({ sql: "SELECT COUNT(*) AS n FROM sessions" });
		expect(Number(rows[0]?.n)).toBe(0);
	});

	it("applies v3's idle and absolute limits", () => {
		const limits = { now: NOW, idleSeconds: 3600, absoluteSeconds: 86_400 };
		expect(sessionExpired({ ...VALUES, lastActivity: NOW - 3599 }, limits)).toBe(false);
		expect(sessionExpired({ ...VALUES, lastActivity: NOW - 3600 }, limits)).toBe(true);
		// Signed in longer ago than the absolute limit, however recent the last request.
		expect(sessionExpired({ ...VALUES, lastActivity: NOW - 1, signedInAt: NOW - 86_400 }, limits)).toBe(true);
		// An anonymous session is ended by the absolute limit measured from its last use.
		expect(sessionExpired({ uid: null, lastActivity: NOW - 86_399, signedInAt: 0 }, limits)).toBe(false);
	});

	it("stops authenticating a pre-rotation session once the epoch moves", async () => {
		// The account as 0028 leaves it: no stored epoch, so the fingerprint applies.
		await sql.batch([
			{
				sql: "INSERT INTO users (id, username, username_key, password_hash, role, created_at) VALUES (?,?,?,?,?,?)",
				params: [7, "admin", "admin", PASSWORD_HASH, "admin", "2026-11-23 12:00:00"],
			},
		]);
		const epoch = await sessionEpoch({ id: 7, passwordHash: PASSWORD_HASH, sessionEpoch: "" });
		await writeSession(sql, {
			id: ID,
			values: { ...VALUES, authEpoch: epoch },
			now: NOW,
			lifetimeSeconds: LIFETIME,
		});

		const limits = { now: NOW, idleSeconds: 604_800, absoluteSeconds: 2_592_000 };
		const before = await authenticate(sql, { id: ID, ...limits });
		expect(before?.userId).toBe(7);

		// A password change (or "sign out everywhere") stores a new epoch.
		await sql.batch([
			{ sql: "UPDATE users SET session_epoch = ? WHERE id = ?", params: ["ffffffffffffffff", 7] },
		]);
		expect(await authenticate(sql, { id: ID, ...limits })).toBeNull();
		// The row is gone: the session ended, it did not merely stop being accepted.
		expect(await loadSession(sql, ID, NOW)).toBeNull();
	});

	it("refuses a session whose account no longer exists", async () => {
		await writeSession(sql, { id: ID, values: VALUES, now: NOW, lifetimeSeconds: LIFETIME });
		expect(
			await authenticate(sql, { id: ID, now: NOW, idleSeconds: 604_800, absoluteSeconds: 2_592_000 }),
		).toBeNull();
	});

	it("destroys a row by its cookie value, and a bogus one destroys nothing", async () => {
		await writeSession(sql, { id: ID, values: VALUES, now: NOW, lifetimeSeconds: LIFETIME });
		await destroySession(sql, "nonsense");
		expect(await loadSession(sql, ID, NOW)).not.toBeNull();
		await destroySession(sql, ID);
		expect(await loadSession(sql, ID, NOW)).toBeNull();
	});
});
