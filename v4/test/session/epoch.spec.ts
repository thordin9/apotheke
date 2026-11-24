// The epoch (plan §4 rule 4, migration 0028): a session is valid only while it holds the account's
// current epoch, so a password change or "sign out everywhere" ends every older session.

import { describe, expect, it } from "vitest";
import { epochHolds, freshEpoch, sessionEpoch } from "../../src/session/epoch";
import fixture from "../fixtures/php-session.json";

describe("the session epoch", () => {
	it("is SessionEpoch::of() for every fixture account", async () => {
		for (const row of fixture.epoch) {
			const got = await sessionEpoch({
				id: row.id,
				passwordHash: row.password_hash,
				sessionEpoch: row.session_epoch,
			});
			expect(got, `account ${row.id}`).toBe(row.epoch);
		}
	});

	it("holds only the identical value, and never holds an empty one", () => {
		expect(epochHolds("a1b2c3d4e5f60718", "a1b2c3d4e5f60718")).toBe(true);
		expect(epochHolds("a1b2c3d4e5f60718", "a1b2c3d4e5f60719")).toBe(false);
		expect(epochHolds("", "")).toBe(false);
		expect(epochHolds("a1b2c3d4e5f60718", "")).toBe(false);
		expect(epochHolds("", "a1b2c3d4e5f60718")).toBe(false);
	});

	it("mints a fresh epoch that is 16 hex digits and does not repeat", () => {
		const a = freshEpoch();
		const b = freshEpoch();
		expect(a).toMatch(/^[0-9a-f]{16}$/);
		expect(a).not.toBe(b);
	});
});
