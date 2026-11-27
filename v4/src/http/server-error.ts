// Citations (D168/D172): every `modules/`, `tests/Modules` and `app/Config` path below is read at
// `5d65939e^`, the last commit before the PHP app was retired.
//
// The 5xx answers the Worker builds outside the filter chain (plan §5 S1.7, D151/D157).
//
// v3's `PlainHandler` is the source, and it says the rule twice: `BootError` — "one public sentence,
// the detail only in the error log" — and `PlainHandler` — "a 5xx detail is generic, never the
// exception's text". So a page path renders the app's error page with one fixed sentence, a 5xx under
// `/api` is RFC 9457 problem+json with `server_error` and the same sentence, and the exception's class
// is what every log line carries.
//
// The log's text is `RedactingFileHandler::strip()`ed (D165), not verbatim: v3's handler logged
// `<class>: <message with DETAIL: (removed)>` and its file handler stripped the row detail on the way
// out, because a driver's constraint failure appends the stored values. A rule about the data does not
// die with the file transport it was written for, so `strip` runs here, on the one path a log line
// takes, rather than at each caller.
//
// Why the headers are set here rather than by the `securityheaders` after-filter: these answers are
// built before the chain, exactly as v3's handler built them after skipping the global filters — that
// is SEC-N8, and `html()` applies the same four `SecurityPolicy` headers the filter would have.
//
// Which page a site may draw is decided by what it has in hand, not by taste (D157): the settings row
// is read at `src/index.ts:113` (read at `55bd1a1a`), so only the catch around the handler has `data`; the three sites that
// answer before it — the boot gate's throw, "not ready", the settings read's own catch — cannot draw
// the shell and answer `Layout\ErrorPage`'s `FALLBACK_PREFIX` document instead.

import { e } from "../html/escape";
import type { PageSettings } from "../html/page";
import { errorPage } from "../html/views";
import { strip } from "../log/redact";
import { html, isApi, problem } from "./problem";

/** `PlainHandler::render()` and `::problem()`: the sentence every 5xx carries, in v3's words. */
export const SERVER_ERROR = "Something went wrong on the server.";

/**
 * `Layout\ErrorPage::FALLBACK_PREFIX` and the one line after it: the page for a request whose shell
 * cannot be drawn, because drawing it is what failed.
 */
export const plainErrorPage = (message: string = SERVER_ERROR): string =>
	`<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head><body><p>${e(message)}</p></body></html>`;

/**
 * The 5xx for one path: RFC 9457 under `/api`, the error page everywhere else. `settings` is passed
 * only by the site that has the row; without it the plain page is the answer, never a guess.
 */
export const serverError = (pathname: string, https: boolean, settings?: PageSettings): Response =>
	isApi(pathname)
		? problem(pathname, https, 503, "server_error", SERVER_ERROR)
		: html(settings === undefined ? plainErrorPage() : errorPage(settings, SERVER_ERROR), 503);

/** A caught value's text, for the log only: `String(error)` is what a non-Error throw has. */
export const errorText = (error: unknown): string => (error instanceof Error ? error.message : String(error));

/**
 * v3 logs the failure (`BootError`: "the detail only in the error log"). The body cannot carry the
 * text (D151/D159), so the log is the one place it may go, and it goes there from the site that
 * caught it: `detail` is that site's own text, passed only where a site has one (D161).
 *
 * `strip` is applied here rather than at the callers (D165): `RedactingFileHandler` ran on every line
 * v3 wrote, and its rule is about the message's content — a constraint failure's `DETAIL:` holds the
 * stored row — so the one function that writes a 5xx line is the one place it cannot be forgotten.
 */
export const logServerError = (site: string, error: unknown, detail?: string): void => {
	const name = error instanceof Error ? error.constructor.name : typeof error;
	console.error(`[apotheke] 5xx ${site}: ${name}${detail === undefined ? "" : `: ${strip(detail)}`}`);
};

/**
 * The handler catch (`src/index.ts:171`, `handlerFailure` at `:174` — read at `55bd1a1a`) as one call: a 500 that is not a user error logs
 * `<class>: <message with DETAIL: (removed)>` — `PlainHandler::beforeAnswer`'s second line
 * (`modules/Core/Exceptions/PlainHandler.php:96-97`), which is the class AND the message — and the
 * answer is the app's own page, because this is the one site holding the settings row (D157).
 */
export const handlerFailure = (
	pathname: string,
	https: boolean,
	settings: PageSettings,
	error: unknown,
): Response => {
	logServerError("handler", error, errorText(error));
	return serverError(pathname, https, settings);
};
