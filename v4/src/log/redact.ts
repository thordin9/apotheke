// v3: `modules/Core/Log/RedactingFileHandler.php:25-31`, read at `5d65939e^` (#97 N-33, #101 N-75).
//
// A driver's message can carry a stored value: a PostgreSQL constraint failure appends
// `DETAIL:  Failing row contains (…)` or `Key (…)=(…)`. v3's rule is not about the log file, it is
// about the text, so it survives the transport it was written for (D165): everything from `DETAIL:`
// in any case to the driver's last `in <file> on line <n>.` is replaced with `DETAIL: (removed)`, and
// after a `Query failed:` first line every following line goes the same way — a localised label
// (`DÉTAIL :`, lc_messages) is dropped without the label being matched at all, because it is the
// lines after a failed query that carry the values, not the label that introduces them.
//
// Both patterns are greedy to the LAST driver line, so a value that itself contains
// `in x on line 1.` is still dropped whole; that is why the lookahead is paired with `[\s\S]*`
// rather than a lazy match, which would stop at the first one.

/** The driver's own `in <path> on line <n>.` line: the anchor both patterns stop at. */
const DRIVER_LINE = "\\nin \\S[^\\n]* on line \\d+\\.";

/**
 * `RedactingFileHandler::strip()`: a log line's text without the row detail a driver appends.
 * `DETAIL:` first, then the `Query failed:` rule, because the first leaves `DETAIL: (removed)`
 * where the second expects the remainder of the message.
 */
export const strip = (message: string): string =>
	message
		.replace(new RegExp(`\\bDETAIL:(?:[\\s\\S]*(?=${DRIVER_LINE})|[\\s\\S]*)`, "gi"), "DETAIL: (removed)")
		.replace(
			new RegExp(
				`(Query failed: [^\\n]*)\\n(?!in \\S[^\\n]* on line \\d+\\.)(?:[\\s\\S]*(?=${DRIVER_LINE})|[\\s\\S]*)`,
				"g",
			),
			"$1\nDETAIL: (removed)",
		);
