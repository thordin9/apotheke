// The page shell's header and menu (plan §5 S1.5): `modules/Layout/Page.php`'s `simpleHeader()`,
// `menu()` and `Viewer::bodyClass()`, ported.
//
// These were named P2 until `/account` needed them: the account page is the first page in this port
// whose document v3 renders with a header, and the header's menu is where the account's own role and
// permissions appear. Rendering the section under an empty bar would not be v3's page, so the shell
// arrives with the slice that first shows it — and every later page gets it for free.
//
// The strings are copied character for character, which matters more here than anywhere else in the
// port: the menu has five conditional items, two alternative settings links and three theme buttons
// whose `on` class depends on the preference, and the fixture (`test/fixtures/php-account.json`) holds
// what PHP printed for a viewer and for an admin. A permission read the wrong way round shows up as a
// missing or extra link, byte for byte.
//
// What is *not* here: `undoBar()` (a page with an undo token is P2) and the `consentDomain` the
// consent notice would carry (no configured cookie domain in phase 1).

import { ALL } from "../auth/permissions";
import { e } from "./escape";
import { icon } from "./icons";
import { type PageSettings, safeBack } from "./page";

/** What the menu needs of the request's user: v3's `current_user()` plus the checks it makes of it. */
export interface MenuViewer {
	/** The signed-in account, or v3's synthetic visitor; null when nobody. */
	readonly username: string;
	readonly role: string;
	/** `Viewer::roleName()`. */
	readonly roleName: string;
	/** v3's `guest`: nobody real (the anonymous admin, the guest-browsing visitor). */
	readonly guest: boolean;
	/** v3's `anon`: the guest-browsing visitor, the one the menu offers "Sign in" to. */
	readonly anon: boolean;
	/** `Viewer::allows($me, $permission)` for the account above. */
	readonly allows: (permission: string) => boolean;
}

/**
 * `Viewer::bodyClass()`: `role-<key>`, `no-<permission>` for every permission the role lacks, and
 * `is-guest` for the anonymous visitor. `''` for nobody, an admin, or a user that could not be read
 * — and v3's own sanitising of the role key (`preg_replace('/[^a-z0-9-]/', '', ...)`).
 */
export const bodyClasses = (viewer: MenuViewer | null): string => {
	if (viewer === null || viewer.role === "admin") {
		return "";
	}
	const classes = [`role-${viewer.role.replace(/[^a-z0-9-]/g, "")}`];
	for (const [key] of ALL) {
		if (!viewer.allows(key)) {
			classes.push(`no-${key}`);
		}
	}
	if (viewer.anon) {
		classes.push("is-guest");
	}
	return classes.join(" ");
};

/** The three theme preferences, in `menu()`'s order: key => [label, icon]. */
const THEMES: ReadonlyArray<readonly [string, string, string]> = [
	["auto", "Auto", "auto"],
	["light", "Light", "sun"],
	["dark", "Dark", "moon"],
];

/**
 * `Page::menu()`: appearance, the links the role may use, the account, and sign-in or sign-out.
 *
 * The token is the page's own (`$this->csrf()` → the session's token, minted if absent), which is why
 * the caller runs `ensureToken()` before rendering.
 */
export const menu = (settings: PageSettings, viewer: MenuViewer | null): string => {
	const tok = e(settings.csrf);
	const current = settings.theme;
	const back = e(safeBack(settings.requestUri));
	const can = (permission: string): boolean => viewer?.allows(permission) === true;

	let out =
		'<details class="menu" id="menu"><summary class="btn btn-ghost btn-round" id="menu-btn" aria-label="Menu" title="Menu">' +
		icon("menu") +
		"</summary>" +
		'<div class="menu-panel" role="menu">' +
		'<form method="post" action="/theme" class="theme-form" id="theme-form"><input type="hidden" name="csrf" value="' +
		tok +
		'"><input type="hidden" name="back" value="' +
		back +
		'"><p class="menu-label" id="theme-label">Appearance</p><div class="seg seg-lg" role="radiogroup" aria-labelledby="theme-label">';
	for (const [key, label, iconName] of THEMES) {
		const on = key === current;
		out +=
			'<button name="theme" value="' +
			key +
			'" role="radio" aria-checked="' +
			(on ? "true" : "false") +
			'" class="seg-btn' +
			(on ? " on" : "") +
			'">' +
			icon(iconName) +
			"<span>" +
			label +
			"</span></button>";
	}
	out +=
		"</div></form>" +
		'<a class="menu-item" href="/locations" role="menuitem">' +
		icon("pin") +
		" Locations</a>" +
		'<a class="menu-item" href="/categories" role="menuitem">' +
		icon("tag") +
		" Categories</a>" +
		'<a class="menu-item" href="/barcode" role="menuitem">' +
		icon("search") +
		" Find by barcode</a>" +
		(can("labels")
			? '<a class="menu-item" href="/labels" role="menuitem" id="menu-labels">' +
				icon("qr") +
				" Print QR labels</a>"
			: "") +
		(can("export")
			? '<a class="menu-item" href="/export.csv" role="menuitem" id="menu-export">' +
				icon("download") +
				" Export CSV</a>"
			: "") +
		(can("import")
			? '<a class="menu-item" href="/import" role="menuitem" id="menu-import">' +
				icon("archive") +
				" Import CSV</a>"
			: "") +
		'<a class="menu-item" href="/lent" role="menuitem">' +
		icon("handoff") +
		" Lent out</a>" +
		(can("stocktake")
			? '<a class="menu-item" href="/stocktake" role="menuitem" id="menu-stocktake">' +
				icon("check") +
				" Stocktake</a>"
			: "") +
		'<a class="menu-item" href="/activity" role="menuitem">' +
		icon("clock") +
		" Recent activity</a>" +
		(can("backup") || can("restore")
			? '<a class="menu-item" href="/backup" role="menuitem" id="menu-backup">' +
				icon("archive") +
				" Backup &amp; restore</a>"
			: "") +
		(can("settings")
			? '<a class="menu-item" href="/settings" role="menuitem" id="menu-settings">' +
				icon("sliders") +
				" Settings</a>"
			: can("users")
				? '<a class="menu-item" href="/settings/security" role="menuitem" id="menu-settings">' +
					icon("sliders") +
					" Users &amp; sign-in</a>"
				: "");
	if (viewer?.guest === false) {
		out +=
			'<a class="menu-item" href="/account" role="menuitem" id="menu-account">' +
			icon("lock") +
			" <span>" +
			e(viewer.username) +
			' <small class="menu-role">' +
			e(viewer.roleName) +
			"</small></span></a>";
	}
	if (viewer?.anon === true) {
		out += `<a class="menu-item" href="/login" role="menuitem" id="menu-login">${icon("lock")} Sign in</a>`;
	} else if (settings.signInEnabled) {
		out += `<form method="post" action="/logout"><input type="hidden" name="csrf" value="${tok}"><button class="menu-item" role="menuitem">${icon("logout")} Sign out</button></form>`;
	}

	return out + "</div></details>";
};

/** `Page::simpleHeader()`: the back button, the title and the menu. */
export const simpleHeader = (
	settings: PageSettings,
	title: string,
	back: string,
	viewer: MenuViewer | null,
): string =>
	'<header class="top top-simple"><div class="bar">' +
	'<a class="btn btn-ghost btn-round" href="' +
	e(back) +
	'" aria-label="Back">' +
	icon("back") +
	"</a>" +
	'<h1 class="title-sm">' +
	e(title) +
	"</h1>" +
	menu(settings, viewer) +
	"</div></header>";
