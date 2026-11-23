// The rqlite driver's contract (plan §5 S0.8, §10 rules 2, 8 and 10). `fetch` is a stub here on
// purpose: the failures that matter — an election answering 503, a reset after the bytes left, a
// replayed unit hitting its key — are the ones a healthy node never produces, so they cannot be
// tested against one. The real server is `rqlite-live.spec.ts`, which runs in the `v4.yml` rqlite job.
//
// The retry policy in one line: reads retry freely, a write retries only when it carries D10's key
// (or provably never left the machine), and a replay maps to success. Every branch below is one of
// those sentences.

import { describe, expect, it } from "vitest";
import { isKeyedUnit, RqliteError, RqliteSql } from "../src/db/rqlite";
import { type Statement, statement } from "../src/db/sql";
import { loadDatabaseConfig } from "../src/platform/config";

const json = (body: unknown, status = 200): Response =>
	new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } });

interface Call {
	readonly url: string;
	readonly body: unknown;
	readonly authorization: string | null;
}

interface Stub {
	readonly fetch: typeof fetch;
	readonly calls: Call[];
}

/** A fetch that answers from a list; the last entry repeats, so a retry has something to hit. */
const stubFetch = (...answers: readonly (Response | Error)[]): Stub => {
	const calls: Call[] = [];
	const fetchFn = (async (input: RequestInfo | URL, init?: RequestInit) => {
		const answer = answers[Math.min(calls.length, answers.length - 1)] ?? new Error("no answer");
		const headers = new Headers(init?.headers as HeadersInit | undefined);
		calls.push({
			url: String(input),
			body: typeof init?.body === "string" ? JSON.parse(init.body) : null,
			authorization: headers.get("authorization"),
		});
		if (answer instanceof Error) {
			throw answer;
		}

		return answer.clone();
	}) as typeof fetch;

	return { fetch: fetchFn, calls };
};

const driver = (fetchFn: typeof fetch, overrides: Partial<ConstructorParameters<typeof RqliteSql>[0]> = {}) =>
	new RqliteSql({
		url: "http://rq.example:4001",
		fetch: fetchFn,
		sleep: async () => undefined,
		...overrides,
	});

const KEYED: Statement = statement("INSERT INTO _write_log (id, created_at) VALUES (?, ?)", ["key-1", "t"]);
const BOOTSTRAP_KEY: Statement = statement(
	"INSERT INTO schema_migrations (version, name, applied_at) VALUES (?,?,?)",
	["0001", "init", "t"],
);
const WRITE: Statement = statement("INSERT INTO roles (role_key) VALUES (?)", ["editor"]);

describe("the rqlite driver's wire shape", () => {
	it("zips positional rows into objects, with blobs as bytes and nulls intact", async () => {
		const stub = stubFetch(
			json({
				results: [
					{
						columns: ["id", "name", "data", "n"],
						types: ["integer", "text", "blob", "text"],
						values: [[1, "λ-ünicode", [1, 2, 255, 0], null]],
					},
				],
			}),
		);
		const rows = await driver(stub.fetch).all(statement("SELECT id, name, data, n FROM t WHERE id = ?", [1]));

		expect(rows).toEqual([{ id: 1, name: "λ-ünicode", data: new Uint8Array([1, 2, 255, 0]), n: null }]);
		expect(stub.calls[0]?.url).toBe("http://rq.example:4001/db/query?blob_array&level=weak");
		expect(stub.calls[0]?.body).toEqual([["SELECT id, name, data, n FROM t WHERE id = ?", 1]]);
	});

	it("asks for a strong read when the caller needs read-after-write", async () => {
		const stub = stubFetch(json({ results: [{ columns: ["a"], types: ["integer"], values: [[1]] }] }));
		await driver(stub.fetch).all(statement("SELECT 1 AS a"), { strong: true });

		expect(stub.calls[0]?.url).toBe("http://rq.example:4001/db/query?blob_array&level=strong");
	});

	it("sends basic auth, trims the base URL, and carries a Uint8Array as byte values", async () => {
		const stub = stubFetch(json({ results: [{ last_insert_id: 1, rows_affected: 1 }] }));
		await driver(stub.fetch, {
			url: "http://rq.example:4001/",
			auth: { username: "ci", password: "s3cret" },
		}).batch([statement("INSERT INTO b (v) VALUES (?)", [new Uint8Array([1, 2, 255])])]);

		expect(stub.calls[0]?.url).toBe("http://rq.example:4001/db/execute?transaction");
		expect(stub.calls[0]?.body).toEqual([["INSERT INTO b (v) VALUES (?)", [1, 2, 255]]]);
		expect(stub.calls[0]?.authorization).toBe(`Basic ${btoa("ci:s3cret")}`);
	});

	it("sends one request for a multi-statement unit", async () => {
		const stub = stubFetch(
			json({
				results: [
					{ last_insert_id: 1, rows_affected: 1 },
					{ last_insert_id: 2, rows_affected: 1 },
					{ rows_affected: 0 },
				],
			}),
		);
		const results = await driver(stub.fetch).batch([
			statement("INSERT INTO items (name) VALUES (?)", ["A"]),
			statement("INSERT INTO items (name) VALUES (?)", ["B"]),
			statement("DELETE FROM items WHERE id = ?", [99]),
		]);

		expect(stub.calls).toHaveLength(1);
		expect(stub.calls[0]?.body).toHaveLength(3);
		expect(results).toEqual([
			{ changes: 1, lastRowId: 1 },
			{ changes: 1, lastRowId: 2 },
			{ changes: 0, lastRowId: null },
		]);
	});

	it("names the failing statement and does not retry a statement error", async () => {
		const stub = stubFetch(
			json({
				results: [
					{ last_insert_id: 1, rows_affected: 1 },
					{ error: "no such table: nope", error_v2: { message: "no such table: nope", sqlite: { code: 1 } } },
				],
			}),
		);

		await expect(
			driver(stub.fetch).batch([
				statement("INSERT INTO t (a) VALUES (1)"),
				statement("INSERT INTO nope (a) VALUES (1)"),
			]),
		).rejects.toThrow(/statement 2: no such table: nope/);
		expect(stub.calls).toHaveLength(1);
	});
});

describe("D10's replay mapping (§10 rule 10)", () => {
	it("treats a key conflict on the envelope as success", async () => {
		const stub = stubFetch(
			json({
				results: [
					{
						error: "UNIQUE constraint failed: _write_log.id",
						error_v2: { message: "UNIQUE constraint failed: _write_log.id", sqlite: { code: 19 } },
					},
				],
			}),
		);
		const results = await driver(stub.fetch).batch([KEYED, WRITE]);

		expect(results).toEqual([
			{ changes: 0, lastRowId: null },
			{ changes: 0, lastRowId: null },
		]);
	});

	it("accepts the bootstrap key before 0036 exists", async () => {
		const stub = stubFetch(
			json({
				results: [
					{
						error: "UNIQUE constraint failed: schema_migrations.version",
						error_v2: { message: "UNIQUE constraint failed: schema_migrations.version" },
					},
				],
			}),
		);

		await expect(driver(stub.fetch).batch([BOOTSTRAP_KEY])).resolves.toHaveLength(1);
	});

	it("does not mistake another unique conflict for a replay", async () => {
		const stub = stubFetch(
			json({
				results: [
					{
						error: "UNIQUE constraint failed: roles.role_key",
						error_v2: { message: "UNIQUE constraint failed: roles.role_key" },
					},
				],
			}),
		);

		await expect(driver(stub.fetch).batch([WRITE])).rejects.toThrow(
			/UNIQUE constraint failed: roles\.role_key/,
		);
	});

	it("knows a keyed unit by its first statement, and nothing else", () => {
		expect(isKeyedUnit([KEYED, WRITE])).toBe(true);
		expect(isKeyedUnit([BOOTSTRAP_KEY])).toBe(true);
		expect(isKeyedUnit([WRITE, KEYED])).toBe(false);
		expect(isKeyedUnit([])).toBe(false);
	});
});

describe("the retry policy (§10 rule 10)", () => {
	it("retries a read through an election, on the backoff schedule", async () => {
		const delays: number[] = [];
		const stub = stubFetch(
			new Response("no leader", { status: 503 }),
			json({ results: [{ columns: ["a"], types: ["integer"], values: [[1]] }] }),
		);
		const sql = new RqliteSql({
			url: "http://rq.example:4001",
			fetch: stub.fetch,
			delaysMs: [100, 200],
			sleep: async (ms) => {
				delays.push(ms);
			},
		});

		await expect(sql.all(statement("SELECT 1 AS a"))).resolves.toEqual([{ a: 1 }]);
		expect(stub.calls).toHaveLength(2);
		expect(delays).toEqual([100]);
	});

	it("retries a keyed write through the same 503", async () => {
		const stub = stubFetch(
			new Response("no leader", { status: 503 }),
			json({ results: [{ rows_affected: 1 }] }),
		);

		await expect(driver(stub.fetch).batch([KEYED, WRITE])).resolves.toHaveLength(2);
		expect(stub.calls).toHaveLength(2);
	});

	it("refuses to retry an unkeyed write on a 503, because the batch may have committed", async () => {
		const stub = stubFetch(new Response("no leader", { status: 503 }));

		await expect(driver(stub.fetch).batch([WRITE])).rejects.toThrow(/503/);
		expect(stub.calls).toHaveLength(1);
	});

	it("retries an unkeyed write when the connection was refused, because nothing was sent", async () => {
		const stub = stubFetch(
			new TypeError("connect: connection refused"),
			json({ results: [{ rows_affected: 1 }] }),
		);

		await expect(driver(stub.fetch).batch([WRITE])).resolves.toHaveLength(1);
		expect(stub.calls).toHaveLength(2);
	});

	it("does not retry an unkeyed write on an ambiguous transport failure", async () => {
		const stub = stubFetch(new TypeError("fetch failed"));

		await expect(driver(stub.fetch).batch([WRITE])).rejects.toThrow(/fetch failed/);
		expect(stub.calls).toHaveLength(1);
	});

	it("does not retry a timeout on an unkeyed write", async () => {
		const timeout = new Error("The operation timed out.");
		timeout.name = "TimeoutError";
		const stub = stubFetch(timeout);

		await expect(driver(stub.fetch).batch([WRITE])).rejects.toThrow(/timed out/);
		expect(stub.calls).toHaveLength(1);
	});

	it("stops when the backoff budget is spent", async () => {
		const stub = stubFetch(new Response("no leader", { status: 503 }));

		await expect(driver(stub.fetch, { delaysMs: [10, 20] }).all(statement("SELECT 1 AS a"))).rejects.toThrow(
			RqliteError,
		);
		// One initial attempt plus two retries: the delays bound the attempts, not the other way round.
		expect(stub.calls).toHaveLength(3);
	});

	it("never echoes the credential when rqlite refuses it", async () => {
		const stub = stubFetch(new Response("unauthorized", { status: 401 }));

		const db = driver(stub.fetch, { auth: { username: "ci", password: "s3cret" } });
		let failure: Error | null = null;
		try {
			await db.all(statement("SELECT 1"));
		} catch (error) {
			failure = error instanceof Error ? error : new Error(String(error));
		}

		expect(failure?.message).toMatch(/RQLITE_AUTH/);
		expect(failure?.message).not.toMatch(/s3cret/);
		expect(stub.calls).toHaveLength(1);
	});
});

describe("the database contract", () => {
	it("infers the self-host default from RQLITE_URL and hosted from the binding", () => {
		expect(loadDatabaseConfig({})).toEqual({ mode: "d1" });
		expect(loadDatabaseConfig({ DB_DRIVER: "" })).toEqual({ mode: "d1" });
		expect(loadDatabaseConfig({ DB_DRIVER: "sqlite" })).toEqual({ mode: "sqlite" });
		expect(loadDatabaseConfig({ RQLITE_URL: "http://rqlite:4001" })).toEqual({
			mode: "rqlite",
			url: "http://rqlite:4001",
			auth: null,
			readConsistency: "weak",
		});
	});

	it("normalises the URL and splits the auth on the first colon", () => {
		expect(
			loadDatabaseConfig({
				DB_DRIVER: "rqlite",
				RQLITE_URL: " https://rq.example:4001/ ",
				RQLITE_AUTH: "ci:pa:ss",
				RQLITE_READ_CONSISTENCY: "STRONG",
			}),
		).toEqual({
			mode: "rqlite",
			url: "https://rq.example:4001",
			auth: { username: "ci", password: "pa:ss" },
			readConsistency: "strong",
		});
	});

	it("refuses instead of falling back", () => {
		expect(() => loadDatabaseConfig({ DB_DRIVER: "postgres" })).toThrow(/not a driver/);
		expect(() => loadDatabaseConfig({ DB_DRIVER: "rqlite" })).toThrow(/RQLITE_URL/);
		expect(() => loadDatabaseConfig({ RQLITE_URL: "http://" })).toThrow(/not a URL/);
		expect(() => loadDatabaseConfig({ RQLITE_URL: "ftp://rq.example:4001" })).toThrow(/http or https/);
		expect(() => loadDatabaseConfig({ RQLITE_URL: "http://rq.example:4001/?level=strong" })).toThrow(
			/no query or fragment/,
		);
		expect(() => loadDatabaseConfig({ RQLITE_URL: "http://rq.example:4001", RQLITE_AUTH: "ci" })).toThrow(
			/username:password/,
		);
		expect(() =>
			loadDatabaseConfig({ RQLITE_URL: "http://rq.example:4001", RQLITE_READ_CONSISTENCY: "eventual" }),
		).toThrow(/weak, strong or none/);
	});
});
