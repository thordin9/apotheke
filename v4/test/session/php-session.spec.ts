// The `sessions.data` column, byte for byte against PHP's own `session_encode()` (plan §5 S1.2 DoD).
//
// The fixture's `sessionData.data` is not a description of the format — it is what v3's serialiser
// printed for the five keys `SessionGate::signIn()` writes, captured by `gen-session-fixture.sh`.

import { describe, expect, it } from "vitest";
import { decodeSession, encodeSession } from "../../src/session/php-session";
import fixture from "../fixtures/php-session.json";

const values = fixture.sessionData.values;

describe("the session row's data column", () => {
	it("is the string PHP's serialiser would have written", () => {
		expect(encodeSession(values)).toBe(fixture.sessionData.data);
	});

	it("reads back every key, including the CSRF token and the sign-in time", () => {
		const decoded = decodeSession(fixture.sessionData.data);
		expect(decoded.uid).toBe(values.uid);
		expect(decoded.authEpoch).toBe(values.authEpoch);
		expect(decoded.csrf).toBe(values.csrf);
		expect(decoded.lastActivity).toBe(values.lastActivity);
		expect(decoded.signedInAt).toBe(values.signedInAt);
		expect(decoded.rest).toEqual({});
	});

	it("keeps a key it does not use instead of dropping it on a rewrite", () => {
		const decoded = decodeSession('uid|i:3;legacy_theme|s:4:"dark";');
		expect(decoded.uid).toBe(3);
		expect(decoded.rest).toEqual({ legacy_theme: "dark" });
	});

	it("counts string lengths in bytes, not characters", () => {
		const decoded = decodeSession('uid|i:1;note|s:2:"é";');
		expect(decoded.rest).toEqual({ note: "é" });
	});

	it("refuses what PHP would not have written, rather than guessing an identity", () => {
		expect(() => decodeSession("uid|i:notanumber;")).toThrow("Malformed session data.");
		expect(() => decodeSession('uid|s:9:"short";')).toThrow("Malformed session data.");
		expect(() => decodeSession("uid")).toThrow("Malformed session data.");
		expect(() => decodeSession("|i:1;")).toThrow("Malformed session data.");
		expect(() => decodeSession("uid|a:1:{}")).toThrow("Malformed session data.");
	});
});
