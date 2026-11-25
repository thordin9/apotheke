// S1.7's port of `RegisterFormTest` (plan D37): a null character at either end of a new user name is
// refused as sent, never trimmed away into a name that would have been valid (v3's N-261/N-291).
//
// The rule lives in `Accounts::nameValid()` (modules/Auth/Accounts.php) and v4's `validName()` is its
// port. The PHP test exists because PHP's `trim()` strips NUL (`" \t\n\r\0\x0B"`), so "rez\0" would
// trim into the valid name "rez"; the guard therefore sits *before* the trim. JavaScript's `trim()` does
// not strip NUL, so in v4 the character class refuses it whichever branch runs, and the explicit
// `raw.includes("\0")` guard beside it is retained as the PHP rule stated directly rather than as the
// deciding branch — a planted regression showed the spec passes with the guard disabled, which is why
// the negative control for this file is the PHP bug itself (strip the NULs, then validate).
//
// That is the whole content of the PHP test, which drove the rule through `POST /register`; v4's
// registration page answers 501 today (the row is scheduled to P6 for the page itself), so this spec
// asserts the rule at the function the page will call — and the two things the page's shape would
// otherwise let a port get wrong: the refusal is 422 with v3's one shape message, and a name that is
// nothing but a NUL is refused as a shape, not as "empty".

import { env } from "cloudflare:workers";
import { beforeEach, describe, expect, it } from "vitest";
import { SHAPE, UserError, validName } from "../../src/auth/users";
import { durableObjectSql, type InstallationStub } from "../../src/db/do-sql";
import type { Sql } from "../../src/db/sql";
import { resetSchema } from "../helpers/schema";

const NOW = "2026-01-01 00:00:00";
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

const sqlite = (name: string): Sql =>
	durableObjectSql(env.INSTALLATION.get(env.INSTALLATION.idFromName(name)) as unknown as InstallationStub);

describe("Accounts::nameValid()'s null-character rule (RegisterFormTest ported)", () => {
	let sql: Sql;

	beforeEach(async () => {
		sql = sqlite("s1.7-name-shape");
		await resetSchema(sql);
		await sql.batch([{ sql: USERS_TABLE }]);
	});

	const refusal = async (name: string): Promise<UserError> => {
		try {
			await validName(sql, name);
		} catch (error) {
			expect(error).toBeInstanceOf(UserError);
			return error as UserError;
		}
		throw new Error(`validName accepted ${JSON.stringify(name)}`);
	};

	it("refuses a null character at either end rather than trimming it into a valid name", async () => {
		for (const name of ["\u0000rez", "rez\u0000"]) {
			const error = await refusal(name);
			expect(error.message).toBe(SHAPE);
			expect(error.status).toBe(422);
		}
	});

	it("refuses a name that is only a null character as a shape, not as an empty name", async () => {
		const error = await refusal("\u0000");
		expect(error.message).toBe(SHAPE);
		expect(error.status).toBe(422);
	});

	it("accepts the same name without the character, trimmed", async () => {
		expect(await validName(sql, "  rez  ")).toBe("rez");
	});

	it("refuses a taken name with the one message the page prints", async () => {
		await validName(sql, "ada");
		await sql.batch([
			{
				sql: "INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES ('ada','ada','x','admin','active',?)",
				params: [NOW],
			},
		]);
		const error = await refusal("ADA");
		expect(error.message).toBe("The user name \u201cADA\u201d is taken.");
		expect(error.status).toBe(422);
	});
});
