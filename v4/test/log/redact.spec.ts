// Citations (D168/D172): every `modules/`, `tests/Modules` and `app/Config` path below is read at
// `5d65939e^`, the last commit before the PHP app was retired.
//
// v3: `tests/Modules/Core/RedactingFileHandlerTest.php` (2 methods) — the row the ledger had `dropped`
// as "PHP log redaction of file writes" until D165 ruled that a rule written for a transport is still a
// rule about the data. The cases are v3's own, string for string, including the ones that make the
// pattern's shape matter: a value that itself contains `in x on line 1.`, a localised `DÉTAIL :` label
// no pattern names, and a lower-case `detail:`.

import { describe, expect, it, vi } from "vitest";
import { logServerError } from "../../src/http/server-error";
import { strip } from "../../src/log/redact";

const DRIVER = "\nin SYSTEMPATH/Database/Postgre/Connection.php on line 210.\n#0 trace";

describe("strip (v3 RedactingFileHandler::strip, D165)", () => {
	it("drops the row detail a driver appends, to the last driver line", () => {
		// `testTheRowDetailIsDropped`'s first line: the secret spans two lines and the driver's line
		// follows the detail, so a lazy match would stop inside the value.
		expect(
			strip(
				'pg_query(): Query failed: ERROR:  duplicate key value violates unique constraint "k"\n' +
					"DETAIL:  Key (name)=(placeholder-secret\nmore) already exists." +
					DRIVER,
			),
		).toBe(
			'pg_query(): Query failed: ERROR:  duplicate key value violates unique constraint "k"\n' +
				"DETAIL: (removed)" +
				DRIVER,
		);
		// One line, detail to the end of the message.
		expect(strip("ERROR:  x DETAIL:  Failing row contains (placeholder-secret).")).toBe(
			"ERROR:  x DETAIL: (removed)",
		);
		// A message without a detail is untouched.
		expect(strip("no detail here")).toBe("no detail here");
	});

	it("runs to the end of the detail, whatever the label's case or language", () => {
		// `testTheStripRunsToTheEndOfTheDetail`: a value holding the driver's own line text.
		expect(
			strip(
				`ERROR:  dup\nDETAIL:  Key (name)=(a\nin x on line 1.\nplaceholder-secret) already exists.${DRIVER}`,
			),
		).toBe(`ERROR:  dup\nDETAIL: (removed)${DRIVER}`);
		// Lower-case and mixed-case labels (#97 N-33).
		expect(strip("ERROR:  x detail:  Failing row contains (placeholder-secret).")).toBe(
			"ERROR:  x DETAIL: (removed)",
		);
		expect(strip("ERROR:  x Detail:  (placeholder-secret\nsecond line)")).toBe("ERROR:  x DETAIL: (removed)");
		// #101 N-75: a localised label is dropped without the label being matched at all.
		expect(
			strip(
				"pg_query(): Query failed: ERREUR:  la valeur viole la contrainte\n" +
					"DÉTAIL : La clé (name)=(placeholder-secret) existe déjà.\nCONTEXTE : x" +
					DRIVER,
			),
		).toBe(`pg_query(): Query failed: ERREUR:  la valeur viole la contrainte\nDETAIL: (removed)${DRIVER}`);
		// A query error whose detail sits after the first line, and one whose driver line follows.
		expect(
			strip(
				"pg_query(): Query failed: ERROR:  dup\nDETAIL:  Key (name)=(placeholder-secret) already exists." +
					DRIVER,
			),
		).toBe(`pg_query(): Query failed: ERROR:  dup\nDETAIL: (removed)${DRIVER}`);
		// No detail at all: the driver's line survives, because the negative lookahead sees it.
		expect(strip(`pg_query(): Query failed: ERROR:  syntax${DRIVER}`)).toBe(
			`pg_query(): Query failed: ERROR:  syntax${DRIVER}`,
		);
		// A label in another language, no driver line: the tail is dropped to the end.
		expect(strip("pg_query(): Query failed: ERROR:  x\nПОДРОБНОСТИ:  placeholder-secret")).toBe(
			"pg_query(): Query failed: ERROR:  x\nDETAIL: (removed)",
		);
	});

	it("is what the shipped log path applies, so no constraint value reaches console.error", () => {
		// v3's first method also asserted the handler was in `Config\Logger`'s handlers, "the shipped log
		// uses it". v4 writes no log file and configures no CI4 handler, so that assertion retires with
		// CI4's `Logger` config and this one takes its place: the line the deployed path writes.
		const lines: string[] = [];
		const spy = vi.spyOn(console, "error").mockImplementation((line: unknown) => {
			lines.push(String(line));
		});
		try {
			logServerError(
				"handler",
				new Error(
					'pg_query(): Query failed: ERROR:  duplicate key value violates unique constraint "k"\n' +
						"DETAIL:  Key (name)=(placeholder-secret\nmore) already exists." +
						DRIVER,
				),
				'pg_query(): Query failed: ERROR:  duplicate key value violates unique constraint "k"\n' +
					"DETAIL:  Key (name)=(placeholder-secret\nmore) already exists." +
					DRIVER,
			);
		} finally {
			spy.mockRestore();
		}
		expect(lines).toEqual([
			'[apotheke] 5xx handler: Error: pg_query(): Query failed: ERROR:  duplicate key value violates unique constraint "k"\nDETAIL: (removed)' +
				DRIVER,
		]);
		expect(lines[0]).not.toContain("placeholder-secret");
	});
});
