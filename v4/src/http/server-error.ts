// The 5xx answers the Worker builds outside the filter chain (plan §5 S1.7, D151/D157).
//
// v3's `PlainHandler` is the source, and it says the rule twice: `BootError` — "one public sentence,
// the detail only in the error log" — and `PlainHandler` — "a 5xx detail is generic, never the
// exception's text". So a page path renders the app's error page with one fixed sentence, a 5xx under
// `/api` is RFC 9457 problem+json with `server_error` and the same sentence, and the exception's class
// (never its text) is what a log line may carry.
//
// Why the headers are set here rather than by the `securityheaders` after-filter: these answers are
// built before the chain, exactly as v3's handler built them after skipping the global filters — that
// is SEC-N8, and `html()` applies the same four `SecurityPolicy` headers the filter would have.
//
// Which page a site may draw is decided by what it has in hand, not by taste (D157): the settings row
// is read at `src/index.ts:99`, so only the catch around the handler has `data`; the three sites that
// answer before it — the boot gate's throw, "not ready", the settings read's own catch — cannot draw
// the shell and answer `Layout\ErrorPage`'s `FALLBACK_PREFIX` document instead.

import { e } from "../html/escape";
import type { PageSettings } from "../html/page";
import { errorPage } from "../html/views";
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

/**
 * v3 logs the failure (`BootError`: "the detail only in the error log"). The body cannot carry the
 * text (D151/D159), so the log is the one place it may go, and it goes there from the site that
 * caught it: `detail` is that site's own text, passed only where a site has one (D161).
 */
export const logServerError = (site: string, error: unknown, detail?: string): void => {
	const name = error instanceof Error ? error.constructor.name : typeof error;
	console.error(`[apotheke] 5xx ${site}: ${name}${detail === undefined ? "" : `: ${detail}`}`);
};
