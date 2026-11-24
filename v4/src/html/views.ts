// The two pages phase 1 renders (plan §5 S1.1): `/login` and the error page.
//
// Both are ports, not redraws. `AuthViews::login()` and `Layout\ErrorPage::html()` are the sources,
// and their strings are copied character for character, including the indentation PHP's `?>` leaves
// behind (see the comment in `page.ts`). `test/fixtures/php-html.json` holds what the PHP app printed
// when the fixture was generated, and the golden test compares this file's output with it byte for
// byte, normalised only for the CSRF token — so any change here that PHP would not make is a red
// test rather than a matter of taste.

import { e, ucfirst } from "./escape";
import { icon } from "./icons";
import { brandLogo, type PageSettings, pageEnd, pageStart } from "./page";

/** `Accounts::USERNAME_MAX`; the field's `maxlength` is twice it, as the PHP view prints it. */
const USERNAME_MAX = 40;

export interface LoginView {
	/** The error the last attempt answered, or null. Only S1.2 produces one. */
	readonly error: string | null;
	/** What the form keeps in the user-name field after a failed attempt. */
	readonly username: string;
	/** `registered=pending` after a registration that needs approval. */
	readonly note: "pending" | null;
	/** `SettingsService::registrationOpen()`. */
	readonly registrationOpen: boolean;
	/** `signInEnabled() && !auth_guest_disabled`. */
	readonly guestBrowsing: boolean;
}

/** `AuthViews::login()`. */
export const loginPage = (settings: PageSettings, view: LoginView): string => {
	const head = pageStart(settings, "Sign in", "page-login");
	const error = view.error === null ? "" : `<div class="err" role="alert">${e(view.error)}</div>`;
	const autofocusUser = view.username === "" ? " autofocus" : "";
	const autofocusPassword = view.username !== "" ? " autofocus" : "";
	const pending =
		view.note === "pending"
			? '<p class="okbox" role="status" id="login-pending">Account created. You can sign in once an admin has approved it.</p>'
			: "";
	const register = view.registrationOpen
		? '<p class="login-alt"><a class="btn btn-wide" href="/register" id="login-register">Create an account</a></p>'
		: "";
	const guest = view.guestBrowsing
		? '<p class="login-alt"><a class="btn btn-wide" href="/" id="login-guest">Look around as a guest</a></p>'
		: "";

	const body =
		'<main class="wrap narrow login">\n' +
		`  <div class="brand brand-lg">${brandLogo(56)}<span>${e(settings.appName)}</span></div>\n` +
		`  <p class="tagline">${e(ucfirst(settings.tagline))}</p>\n` +
		'  <form method="post" action="/login" class="itemform" id="login-form">\n' +
		`    <input type="hidden" name="csrf" value="${e(settings.csrf)}">\n` +
		// The four spaces before `error` are the `<?php if` line's own indentation; PHP's `?>`
		// swallows the newline that followed it, so the label that comes next keeps its four and
		// the two runs add up to the eight spaces v3 prints.
		`    ${error}    <label class="field"><span>User name</span><input type="text" name="username" id="login-user" value="${e(view.username)}" autocomplete="username" autocapitalize="off" autocorrect="off" spellcheck="false" maxlength="${USERNAME_MAX * 2}" required${autofocusUser}></label>\n` +
		`    <label class="field"><span>Password</span><input type="password" name="password" id="login-pw" autocomplete="current-password" required enterkeyhint="go"${autofocusPassword}></label>\n` +
		'    <button class="btn btn-primary btn-wide" id="login-go">Sign in</button>\n' +
		"  </form>\n" +
		`  ${pending}  ${register}  ${guest}</main>\n`;

	return head + body + pageEnd();
};

/** `Accounts::PASSWORD_MIN`; the two password fields' `minlength`. */
const PASSWORD_MIN = 8;

export interface ClaimView {
	/** What the last attempt answered, or null. */
	readonly error: string | null;
	/** What the form keeps in the user-name field after a failed attempt. */
	readonly username: string;
}

/**
 * `AuthViews::claim()`: the hosted first-admin page (plan §5 S1.2b). Ported line for line, with the
 * same `?>`-swallows-a-newline artifact the login view carries: the error block and the first field
 * are on one output line, so an absent error leaves four spaces before the label.
 */
export const claimPage = (settings: PageSettings, view: ClaimView): string => {
	const head = pageStart(settings, "Set up your Apotheke", "page-login page-claim");
	const error =
		view.error === null ? "" : `<div class="err" role="alert" id="claim-err">${e(view.error)}</div>`;

	const body =
		'<main class="wrap narrow login">\n' +
		`  <div class="brand brand-lg">${brandLogo(56)}<span>${e(settings.appName)}</span></div>\n` +
		'  <h1 class="tagline">Set up your Apotheke</h1>\n' +
		'  <form method="post" action="/claim" class="itemform" id="claim-form">\n' +
		`    <input type="hidden" name="csrf" value="${e(settings.csrf)}">\n` +
		`    ${error}    <label class="field"><span>Claim code <em>from your link, after #</em></span><input type="text" name="token" id="claim-token" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" maxlength="100" required></label>\n` +
		`    <label class="field"><span>User name <em>letters, digits, . _ @ -</em></span><input type="text" name="username" id="claim-user" value="${e(view.username)}" autocomplete="username" autocapitalize="off" autocorrect="off" spellcheck="false" maxlength="${USERNAME_MAX}" required></label>\n` +
		`    <label class="field"><span>Password <em>at least ${PASSWORD_MIN} characters</em></span><input type="password" name="new" id="claim-pw" autocomplete="new-password" minlength="${PASSWORD_MIN}" required></label>\n` +
		`    <label class="field"><span>Repeat password</span><input type="password" name="confirm" id="claim-confirm" autocomplete="new-password" minlength="${PASSWORD_MIN}" required></label>\n` +
		'    <p class="hint" id="claim-hint">This account becomes the admin. The link works once.</p>\n' +
		'    <button class="btn btn-primary btn-wide" id="claim-go">Create admin account</button>\n' +
		"  </form>\n" +
		"</main>\n";

	return head + body + pageEnd();
};

/** `Layout\ErrorPage::html()`: the message on the app's own page, which never starts a session. */
export const errorPage = (settings: PageSettings, message: string): string => {
	const head = pageStart(settings, "Error", "");
	const body =
		'<main class="wrap narrow login">\n' +
		`  <div class="empty">${icon("box")}<p>${e(message)}</p><a class="btn btn-primary" href="/">Back to catalog</a></div>\n` +
		"</main>\n";

	return head + body + pageEnd();
};
