// The rqlite driver (plan §5 S0.8, §10): `Sql` over the HTTP API, one request per unit of work.
//
// Three things here are the plan's rules, not preferences:
//   - `batch()` is **one** `POST /db/execute?transaction` for the whole unit (§10 rule 2, plan-then-flush).
//     The spike proved a failed statement aborts the transaction, so a unit commits whole or not at all.
//   - Reads retry freely; a write retries only when it carries D10's idempotency key, or when the failure
//     provably happened before a byte of the request reached the server (§10 rule 10).
//   - A replayed unit dies on its key, and this driver maps that conflict to success: exactly-once with
//     no idempotency feature from rqlite and no judgement at the call site.
//
// Rows arrive positional (`{columns, types, values}`) in v10.7.4, not associative; zipping them is this
// file's job so nothing above it ever sees the wire shape. BLOBs travel as an array of byte values
// (`blob_array` on the query, an array parameter on the write) and surface as `Uint8Array`.

import type { Row, Sql, Statement, StatementResult } from "./sql";

/** Where a read is answered: `weak` is the default from config; `strong` is for read-after-write. */
export type ReadConsistency = "weak" | "strong" | "none";

export interface RqliteOptions {
	/** `http(s)://host:4001`, already validated by `loadDatabaseConfig()`. */
	readonly url: string;
	/** Basic auth, from `RQLITE_AUTH`. Null means the node has no `-auth` file. */
	readonly auth?: { readonly username: string; readonly password: string } | null;
	readonly readConsistency?: ReadConsistency;
	readonly timeoutMs?: number;
	/** Test seams: a fetch and a sleep the tests can watch instead of waiting. */
	readonly fetch?: typeof fetch;
	readonly sleep?: (ms: number) => Promise<void>;
	readonly delaysMs?: readonly number[];
}

/**
 * Bounded exponential backoff, ~3.1 s in total. The phase-0 spike measured a ~1.6 s window where a
 * leader election answers `503` and the node is healthy again at 2.0 s, so the budget has to cover it
 * from inside one request — the compose healthcheck cannot help here (§11 rule 2).
 */
export const RETRY_DELAYS_MS: readonly number[] = [100, 200, 400, 800, 1_600];

/** The one thing this driver will not do slowly: wait forever for a node that is not there. */
const DEFAULT_TIMEOUT_MS = 5_000;

/**
 * A unit is replay-safe when its first statement is the idempotency key D10 defines: `_write_log`'s
 * row, or — before 0036 exists — the unit's own `schema_migrations` row (§10 rule 10, bootstrap
 * exception). Nothing else may be retried once it may have been applied.
 */
export const isKeyedUnit = (statements: readonly Statement[]): boolean => {
	const first = statements[0];
	if (first === undefined) {
		return false;
	}
	return /^\s*INSERT\s+INTO\s+(?:_write_log|schema_migrations)\s*\(/i.test(first.sql);
};

/** The key conflict a replay produces; it means "this unit already ran", not "this unit failed". */
const KEY_CONFLICT = /UNIQUE constraint failed: (?:_write_log\.id|schema_migrations\.version)/i;

/**
 * A transport failure that provably happened before the request body was sent (connection refused,
 * DNS, TLS handshake). Only these may be retried without the key. Anything else — a reset after the
 * bytes left, a timeout — may have been applied, so an unkeyed unit fails closed instead.
 */
const BEFORE_SEND =
	/connection refused|econnrefused|enotfound|getaddrinfo|eai_again|handshake|certificate|tls/i;

export class RqliteError extends Error {
	constructor(
		message: string,
		/** The HTTP status, or null when the failure never got an answer. */
		readonly status: number | null,
	) {
		super(message);
		this.name = "RqliteError";
	}
}

/** A statement failed inside an otherwise successful response (rqlite reports these with HTTP 200). */
export class RqliteStatementError extends RqliteError {
	constructor(
		message: string,
		/** Zero-based position in the unit, so the message can name the statement. */
		readonly index: number,
		/** `error_v2.sqlite.code`, when SQLite raised it. */
		readonly code: number | null,
	) {
		super(`statement ${index + 1}: ${message}`, null);
		this.name = "RqliteStatementError";
	}
}

interface RqliteResult {
	readonly columns?: readonly string[];
	readonly types?: readonly string[];
	readonly values?: readonly (readonly unknown[] | null)[];
	readonly last_insert_id?: number;
	readonly rows_affected?: number;
	readonly error?: string;
	readonly error_v2?: { readonly message?: string; readonly sqlite?: { readonly code?: number } };
}

interface RqliteResponse {
	readonly results?: readonly RqliteResult[];
}

interface Failure {
	readonly message: string;
	readonly status: number | null;
	readonly beforeSend: boolean;
}

const asFailure = (error: unknown): Failure => {
	if (error instanceof RqliteError) {
		return { message: error.message, status: error.status, beforeSend: false };
	}
	const message = error instanceof Error ? error.message : String(error);
	const cause = error instanceof Error && error.cause instanceof Error ? error.cause.message : "";
	const text = `${cause} ${message}`;

	// A timeout is not a connect failure: the request may have been applied before the answer was lost.
	const timedOut = error instanceof Error && (error.name === "TimeoutError" || error.name === "AbortError");

	return { message, status: null, beforeSend: !timedOut && BEFORE_SEND.test(text) };
};

/** The hint that turns a status code into something an operator can act on. */
const describeStatus = (status: number, body: string): string => {
	const hint =
		status === 401
			? "rqlite answered 401: set RQLITE_AUTH to the username:password from its -auth file"
			: status === 403
				? "rqlite refused the credential (403): check the perms in its -auth file"
				: status === 404
					? "rqlite answered 404: check RQLITE_URL, there is no such endpoint on that server"
					: status === 503
						? "rqlite has no leader yet (503 during an election)"
						: "rqlite answered with an error";
	const snippet = body.trim().slice(0, 200);

	return snippet === "" ? `${status} ${hint}` : `${status} ${hint}: ${snippet}`;
};

/** `username:password` → the value of an `Authorization` header. UTF-8 safe, never logged. */
const basicAuth = (auth: { readonly username: string; readonly password: string }): string => {
	const bytes = new TextEncoder().encode(`${auth.username}:${auth.password}`);
	let binary = "";
	for (const byte of bytes) {
		binary += String.fromCharCode(byte);
	}

	return btoa(binary);
};

const encodeParam = (param: unknown): unknown => {
	if (param === null || typeof param === "string" || typeof param === "number") {
		return param;
	}
	// rqlite's documented BLOB parameter form: an array of byte values.
	if (param instanceof Uint8Array) {
		return Array.from(param);
	}
	throw new RqliteError(
		`rqlite cannot carry a ${typeof param} parameter: use a string, number, Uint8Array or null.`,
		null,
	);
};

const encodeStatements = (statements: readonly Statement[]): string =>
	JSON.stringify(statements.map((s) => [s.sql, ...(s.params ?? []).map(encodeParam)]));

/** A BLOB comes back as byte values (`blob_array`); everything else is already JSON's own types. */
const decodeValue = (type: string | undefined, value: unknown): unknown =>
	type === "blob" && Array.isArray(value) ? Uint8Array.from(value as readonly number[]) : value;

const rowsFrom = (result: RqliteResult): Row[] => {
	const columns = result.columns ?? [];
	const types = result.types ?? [];

	return (result.values ?? []).map((values) => {
		const row: Row = {};
		columns.forEach((column, index) => {
			row[column] = decodeValue(types[index], values?.[index]);
		});

		return row;
	});
};

const statementResult = (result: RqliteResult): StatementResult => ({
	changes: Number(result.rows_affected ?? 0),
	lastRowId:
		result.last_insert_id === undefined || result.last_insert_id === 0 ? null : result.last_insert_id,
});

const errorOf = (result: RqliteResult): string =>
	result.error_v2?.message ?? result.error ?? "rqlite reported a failure without a message";

export class RqliteSql implements Sql {
	private readonly url: string;
	private readonly auth: { readonly username: string; readonly password: string } | null;
	private readonly readConsistency: ReadConsistency;
	private readonly timeoutMs: number;
	private readonly fetchFn: typeof fetch;
	private readonly sleep: (ms: number) => Promise<void>;
	private readonly delaysMs: readonly number[];

	constructor(options: RqliteOptions) {
		this.url = options.url.replace(/\/+$/, "");
		this.auth = options.auth ?? null;
		this.readConsistency = options.readConsistency ?? "weak";
		this.timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;
		this.fetchFn = options.fetch ?? ((input, init) => fetch(input, init));
		this.sleep = options.sleep ?? ((ms) => new Promise((resolve) => setTimeout(resolve, ms)));
		this.delaysMs = options.delaysMs ?? RETRY_DELAYS_MS;
	}

	/**
	 * A read. `strong` asks the leader for the current state instead of a possibly lagging replica, for
	 * a read that follows a write in the same request (§10 rule 8).
	 */
	async all(statement: Statement, options: { readonly strong?: boolean } = {}): Promise<Row[]> {
		const level: ReadConsistency = options.strong === true ? "strong" : this.readConsistency;
		const response = await this.request(
			`/db/query?blob_array&level=${level}`,
			encodeStatements([statement]),
			(failure) => failure.status === null || failure.status === 503,
		);
		const result = response.results?.[0];
		if (result === undefined) {
			throw new RqliteError("rqlite answered a query with no result", null);
		}
		if (result.error !== undefined || result.error_v2 !== undefined) {
			throw new RqliteStatementError(errorOf(result), 0, result.error_v2?.sqlite?.code ?? null);
		}

		return rowsFrom(result);
	}

	/** One atomic unit of work: a single `?transaction` request, or nothing. */
	async batch(statements: readonly Statement[]): Promise<readonly StatementResult[]> {
		if (statements.length === 0) {
			return [];
		}
		const keyed = isKeyedUnit(statements);
		const response = await this.request("/db/execute?transaction", encodeStatements(statements), (failure) =>
			// A keyed unit is replay-safe, so it retries like a read. An unkeyed one retries only when the
			// request provably never reached the server.
			keyed
				? failure.status === null || failure.status === 503
				: failure.status === null && failure.beforeSend,
		);
		const results = response.results ?? [];
		const failed = results.findIndex((r) => r.error !== undefined || r.error_v2 !== undefined);

		if (failed !== -1) {
			const result = results[failed];
			const message = result === undefined ? "rqlite reported a failure without a message" : errorOf(result);
			// D10 (§10 rule 10): the unit already ran. The batch is atomic, so the conflict on its first
			// statement means no other statement executed and there is nothing to redo.
			if (keyed && failed === 0 && KEY_CONFLICT.test(message)) {
				return statements.map(() => ({ changes: 0, lastRowId: null }));
			}
			throw new RqliteStatementError(message, failed, results[failed]?.error_v2?.sqlite?.code ?? null);
		}

		return statements.map((_, index) => statementResult(results[index] ?? {}));
	}

	private async request(
		path: string,
		body: string,
		canRetry: (failure: Failure) => boolean,
	): Promise<RqliteResponse> {
		for (let attempt = 0; ; attempt += 1) {
			try {
				return await this.send(path, body);
			} catch (error) {
				const failure = asFailure(error);
				const delay = this.delaysMs[attempt];
				if (delay === undefined || !canRetry(failure)) {
					throw error instanceof RqliteError ? error : new RqliteError(failure.message, null);
				}
				await this.sleep(delay);
			}
		}
	}

	private async send(path: string, body: string): Promise<RqliteResponse> {
		const headers: Record<string, string> = { "content-type": "application/json" };
		if (this.auth !== null) {
			headers.authorization = `Basic ${basicAuth(this.auth)}`;
		}

		const response = await this.fetchFn(`${this.url}${path}`, {
			method: "POST",
			headers,
			body,
			signal: AbortSignal.timeout(this.timeoutMs),
		});

		if (response.status !== 200) {
			const text = await response.text().catch(() => "");

			throw new RqliteError(describeStatus(response.status, text), response.status);
		}

		return (await response.json()) as RqliteResponse;
	}
}

/** The driver for a validated `DB_DRIVER=rqlite` configuration. */
export const rqliteSql = (config: {
	readonly url: string;
	readonly auth: { readonly username: string; readonly password: string } | null;
	readonly readConsistency: ReadConsistency;
}): Sql => new RqliteSql(config);
