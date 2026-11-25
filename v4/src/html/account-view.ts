// `/account` (plan §5 S1.5): `modules/Auth/Views/AuthViews.php`'s `account()`, ported.
//
// The page is one document — shell, header, menu, card, toast — so this file renders all of it and
// `test/fixtures/php-account.json` holds what v3 printed for a viewer (with the not-granted classes
// and a short menu), an admin (no classes, every link) and every refusal. Byte identity is the
// contract: the eight spaces before the password form are the `<?php if ($error)` line's own
// indentation with the newline `?>` swallowed, and a re-render with a message closes that gap with
// four spaces instead of eight.
//
// `#acct-err` is v3's own error slot (the controller re-renders this page for a refusal rather than
// answering a problem document), and the toast is printed after `</main>` by the page, not by the
// shell.

import { e } from "./escape";
import { icon } from "./icons";
import { type MenuViewer, simpleHeader } from "./menu";
import { type PageSettings, pageEnd, pageStart } from "./page";

/** What `AccountController::accountPage()` hands the view. */
export interface AccountView {
	/** The request's user, for the header's menu: v3 renders the account page inside it. */
	readonly viewer: MenuViewer;
	readonly username: string;
	readonly role: string;
	/** `RoleRepository::name($role)`: "Viewer", "Admin", the custom name. */
	readonly roleName: string;
	/** The role's permissions, in `Permissions::ALL` order (`$roles[$role]['perms']`). */
	readonly perms: readonly string[];
	/** key => display name, for the sentence under the account's name. */
	readonly permNames: Readonly<Record<string, string>>;
	/** `Accounts::PASSWORD_MIN`, printed by the new-password field. */
	readonly passwordMin: number;
}

/** `AuthViews::account()`: the page, with the refusal v3 re-renders and the toast a `?done=` asks for. */
export const accountPage = (
	settings: PageSettings,
	me: AccountView,
	error: string | null,
	toast: string | null,
): string => {
	const head =
		pageStart(settings, "Your account", "page-account") +
		simpleHeader(settings, "Your account", "/", me.viewer);
	const tok = e(settings.csrf);
	// `implode(', ', array_map(fn($p) => mb_strtolower($permNames[$p]), $perms))` — the labels, lower
	// case, in the role's own order. A permission the labels do not know prints as PHP prints it.
	const youMay = me.perms.map((p) => (me.permNames[p] ?? p).toLowerCase()).join(", ");
	const err = error === null ? "" : `<div class="err" role="alert" id="acct-err">${e(error)}</div>`;
	const tail = toast === null ? "" : `<div class="toast" role="status">${e(toast)}</div>`;

	const body =
		'<main class="wrap narrow">\n' +
		'  <section class="set-card" id="acct-card">\n' +
		`    <p class="pw-state">${icon("lock")} Signed in as <b id="acct-name">${e(me.username)}</b> · <span class="role role-${e(me.role)}" id="acct-role">${e(me.roleName)}</span></p>\n` +
		`    <p class="hint" id="acct-perms">You may: ${e(youMay)}.</p>\n` +
		// The `<?php if ($error)` line keeps its four spaces and `?>` eats the newline: with a message
		// the four of the div and the four of the form meet, without one the form's four become eight.
		`    ${err}    <form method="post" action="/account" class="setform" id="acct-pw">\n` +
		`      <input type="hidden" name="csrf" value="${tok}">\n` +
		'      <h2 class="set-h">Change your password</h2>\n' +
		'      <label class="field"><span>Current password</span><input type="password" name="current" id="acct-current" autocomplete="current-password" required></label>\n' +
		`      <label class="field"><span>New password <em>at least ${me.passwordMin} characters</em></span><input type="password" name="new" id="acct-new" autocomplete="new-password" minlength="${me.passwordMin}" required></label>\n` +
		`      <label class="field"><span>Repeat new password</span><input type="password" name="confirm" id="acct-confirm" autocomplete="new-password" minlength="${me.passwordMin}" required></label>\n` +
		'      <button class="btn btn-primary btn-wide" id="acct-save">Change password</button>\n' +
		"    </form>\n" +
		'    <form method="post" action="/account/signout" id="acct-signout">\n' +
		`      <input type="hidden" name="csrf" value="${tok}">\n` +
		'      <h2 class="set-h">Sign out everywhere</h2>\n' +
		'      <p class="hint" id="acct-signout-hint">Ends this account\'s other sessions. This device stays signed in.</p>\n' +
		'      <button class="btn btn-wide" type="submit" id="acct-signout-go">Sign out everywhere</button>\n' +
		"    </form>\n" +
		`    <form method="post" action="/logout"><input type="hidden" name="csrf" value="${tok}"><button class="btn btn-wide" id="acct-logout">${icon("logout")} Sign out</button></form>\n` +
		"  </section>\n" +
		"</main>\n";

	return head + body + tail + pageEnd();
};
