// The sign-in throttle (plan §5 S1.2 DoD): N failed sign-ins are refused with a 429 and the same N
// from a fresh session are not — and the refusal names how long to wait.

import { env } from "cloudflare:workers";
import { beforeEach, describe, expect, it } from "vitest";
import { durableObjectSql, type InstallationStub } from "../../src/db/do-sql";
import type { Sql } from "../../src/db/sql";
import { accountKey, addressScope, bucket, hit, KEEP_SECONDS, LIMITS } from "../../src/session/throttle";

const sqlite = (name: string): Sql =>
	durableObjectSql(env.INSTALLATION.get(env.INSTALLATION.idFromName(name)) as unknown as InstallationStub);

const NOW = 1_764_000_000;
const ADDRESS = "203.0.113.9";

describe("the sign-in throttle", () => {
	let sql: Sql;

	beforeEach(async () => {
		sql = sqlite("sessions-throttle");
		await sql.batch([{ sql: "DROP TABLE IF EXISTS auth_attempts" }]);
		await sql.batch([
			{
				sql: `CREATE TABLE auth_attempts (
					id     INTEGER PRIMARY KEY AUTOINCREMENT,
					kind   TEXT NOT NULL,
					client TEXT NOT NULL,
					at     INTEGER NOT NULL
				)`,
			},
			{ sql: "CREATE INDEX auth_attempts_k ON auth_attempts (kind, client, at)" },
		]);
	});

	it("keeps v3's limits", () => {
		expect(LIMITS.login).toEqual([10, 900]);
		expect(LIMITS.login_address).toEqual([30, 900]);
		expect(LIMITS.register).toEqual([5, 3600]);
	});

	it("refuses the eleventh attempt for one account from one address, and says how long to wait", async () => {
		for (let i = 0; i < 10; i += 1) {
			const result = await hit(sql, { kind: "login", client: ADDRESS, account: "admin", now: NOW + i });
			expect(result.ok, `attempt ${i + 1}`).toBe(true);
		}
		const refused = await hit(sql, { kind: "login", client: ADDRESS, account: "admin", now: NOW + 10 });
		expect(refused.ok).toBe(false);
		if (refused.ok) {
			return;
		}
		expect(refused.retryAfterSeconds).toBe(900);
		expect(refused.message).toBe(
			"Too many attempts for this account from this address – please wait 15 minutes and try again.",
		);
		// The refused attempt took its own row back: the count did not grow past the limit.
		const rows = await sql.all({ sql: "SELECT COUNT(*) AS n FROM auth_attempts" });
		expect(Number(rows[0]?.n)).toBe(10);
	});

	it("does not let one account's limit refuse a different account from the same address", async () => {
		for (let i = 0; i < 10; i += 1) {
			await hit(sql, { kind: "login", client: ADDRESS, account: "admin", now: NOW + i });
		}
		const other = await hit(sql, { kind: "login", client: ADDRESS, account: "alice", now: NOW + 11 });
		expect(other.ok).toBe(true);
		// The per-address cap is higher on purpose: 30 across every name.
		for (let i = 0; i < 30; i += 1) {
			await hit(sql, { kind: "login_address", client: ADDRESS, now: NOW + 20 + i });
		}
		const capped = await hit(sql, { kind: "login_address", client: ADDRESS, now: NOW + 60 });
		expect(capped.ok).toBe(false);
	});

	it("lets a fresh session try again: the counter is the address and the account, not the browser", async () => {
		for (let i = 0; i < 10; i += 1) {
			await hit(sql, { kind: "login", client: ADDRESS, account: "admin", now: NOW + i });
		}
		// "A fresh session from the same address" is refused — the counter never lived in the session.
		expect((await hit(sql, { kind: "login", client: ADDRESS, account: "admin", now: NOW + 20 })).ok).toBe(
			false,
		);
		// Once every attempt has left the window, the same attempt is allowed again.
		expect((await hit(sql, { kind: "login", client: ADDRESS, account: "admin", now: NOW + 911 })).ok).toBe(
			true,
		);
	});

	it("counts registration per address, five to the hour", async () => {
		for (let i = 0; i < 5; i += 1) {
			expect((await hit(sql, { kind: "register", client: ADDRESS, now: NOW + i })).ok).toBe(true);
		}
		expect((await hit(sql, { kind: "register", client: ADDRESS, now: NOW + 6 })).ok).toBe(false);
	});

	it("prunes attempts older than a day, as RateLimiter does", async () => {
		await hit(sql, { kind: "login", client: ADDRESS, account: "admin", now: NOW - KEEP_SECONDS - 1 });
		expect(Number((await sql.all({ sql: "SELECT COUNT(*) AS n FROM auth_attempts" }))[0]?.n)).toBe(1);
		await hit(sql, { kind: "login", client: ADDRESS, account: "admin", now: NOW });
		const left = await sql.all({ sql: "SELECT at FROM auth_attempts" });
		expect(left.map((r) => Number(r.at))).toEqual([NOW]);
	});

	it("hashes the bucket the way legacy client_key() does, and folds names the way UserRepository does", async () => {
		expect(await bucket("login", ADDRESS, " Alice ")).toHaveLength(32);
		expect(await bucket("login", ADDRESS, " Alice ")).toBe(
			await bucket("login", ADDRESS, accountKey(" alice")),
		);
		expect(await bucket("login", ADDRESS, "admin")).not.toBe(await bucket("login", ADDRESS, "alice"));
		// An address-only kind is scoped by address, not by name.
		expect(await bucket("login_address", ADDRESS, "admin")).toBe(
			await bucket("login_address", ADDRESS, null),
		);
	});

	it("scopes an IPv6 client by its /64 and leaves IPv4 whole", () => {
		expect(addressScope("203.0.113.9")).toBe("203.0.113.9");
		expect(addressScope("2001:db8:1:2:aaaa:bbbb:cccc:dddd")).toBe("2001:db8:1:2::/64");
		expect(addressScope("::ffff:203.0.113.9")).toBe("::ffff:203.0.113.9");
	});
});
