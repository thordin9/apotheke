// The Users & sign-in page (plan §5 S1.3): `SecurityView::page()`, ported.
//
// `modules/Auth/Views/SecurityView.php` is the source — its `security()` (legacy
// `settings_security_html()`), its `roles()` (legacy `settings_roles_html()`) and its `field()` (legacy
// `setting_field_html()` for the three types this section uses). The strings are copied character for
// character, including the whitespace PHP's `?>` leaves behind (see `page.ts`), because
// `test/fixtures/php-security.json` holds what the PHP app printed on a pinned state and
// `test/html/security.spec.ts` compares this file's output with it byte for byte.
//
// Two things are deliberately not here, and both are named in the docs rather than hidden:
//
//   * the header (`Page::simpleHeader()`, and through it the menu). The menu is P2 in this port
//     (`page.ts` says why), so the page renders the section and not the bar above it;
//   * the settings *save* and *reset* forms render, but their POST handlers are still 501 — the
//     route table says so, and 2b is where the roles editor's POSTs land.
//
// The page is one document in v3 — users, the password card and the roles editor together — so it is
// one view here too; a fixture cannot cover half a page.

import { e } from "./escape";
import { icon } from "./icons";
import { type PageSettings, pageEnd, pageStart } from "./page";

/** `SecurityView::LABEL` / `DESCRIPTION`. */
export const LABEL = "Users & sign-in";
export const DESCRIPTION = "Accounts, roles, passwords; sign-in on or off";

/** `Accounts::USERNAME_MAX` / `PASSWORD_MIN`, the two numbers the form prints. */
export const USERNAME_MAX = 40;
export const PASSWORD_MIN = 8;

/** `Permissions::ALL`: `[key, label, hint]`, in the order every list and grid uses. */
export interface Permission {
	readonly key: string;
	readonly label: string;
	readonly hint: string;
}

/** `RoleAdmin::NOT_FOR_GUESTS`. */
export const NOT_FOR_GUESTS: readonly string[] = ["users", "settings", "backup", "restore"];

/** One role as the page prints it (`RoleRepository::listed()`'s row). */
export interface SecurityRole {
	readonly key: string;
	readonly name: string;
	readonly builtin: boolean;
	readonly users: number;
	readonly perms: readonly string[];
}

/** One account as the page prints it (`UserAdmin::all()`'s row). */
export interface SecurityUser {
	readonly id: number;
	readonly username: string;
	readonly role: string;
	readonly status: string;
	readonly createdAt: string;
	readonly lastLoginAt: string | null;
}

/** One settings field, as `SecurityController::securityPage()` builds it. */
export interface SecurityField {
	readonly key: string;
	readonly label: string;
	readonly type: string;
	readonly default: unknown;
	readonly help: string;
	readonly options: Readonly<Record<string, unknown>>;
	readonly value: unknown;
}

/** Everything the view needs: `$v` in `SecurityView::page()`. */
export interface SecurityState {
	/** `SettingsService::signInEnabled()`. */
	readonly on: boolean;
	/** `signInEnabled() && !auth_guest_disabled`. */
	readonly guestBrowsing: boolean;
	/** The visitor as the request began. */
	readonly me: {
		readonly id: number | null;
		readonly username: string;
		readonly role: string;
		readonly guest: boolean;
	};
	/** `UserAdmin::activeAdmins()`. */
	readonly admins: number;
	readonly users: readonly SecurityUser[];
	/** The roles, in `RoleRepository::listed()`'s order. */
	readonly roles: readonly SecurityRole[];
	readonly fields: readonly SecurityField[];
	/** `Accounts::USERNAME_MAX` / `PASSWORD_MIN`. */
	readonly usernameMax: number;
	readonly passwordMin: number;
	/** `time_format` / `timezone` settings, for `last_login_at`. */
	readonly timeFormat: string;
	readonly timezone: string;
	/** `Permissions::ALL`, so the grid and the summary come from one table. */
	readonly permissions: readonly Permission[];
}

const MONTHS: readonly string[] = [
	"Jan",
	"Feb",
	"Mar",
	"Apr",
	"May",
	"Jun",
	"Jul",
	"Aug",
	"Sep",
	"Oct",
	"Nov",
	"Dec",
];

/**
 * `SecurityView::time()`: a stored UTC time in the app's zone, `j M Y, ` + `time_format`.
 *
 * PHP formats `DateTimeImmutable($utc, new DateTimeZone('UTC'))->setTimezone($tz)`; this reads the
 * same instant in the same zone through `Intl`, so a DST boundary lands on the same hour, and then
 * formats it with PHP's own tokens rather than a locale's.
 */
export const formatTime = (utc: string, timeFormat: string, timezone: string): string => {
	const date = new Date(`${utc.replace(" ", "T")}Z`);
	if (Number.isNaN(date.getTime())) {
		return utc;
	}
	let zone = timezone;
	let parts: Intl.DateTimeFormatPart[];
	try {
		parts = new Intl.DateTimeFormat("en-GB", {
			timeZone: zone,
			year: "numeric",
			month: "2-digit",
			day: "2-digit",
			hour: "2-digit",
			minute: "2-digit",
			hourCycle: "h23",
		}).formatToParts(date);
	} catch {
		zone = "UTC";
		parts = new Intl.DateTimeFormat("en-GB", {
			timeZone: zone,
			year: "numeric",
			month: "2-digit",
			day: "2-digit",
			hour: "2-digit",
			minute: "2-digit",
			hourCycle: "h23",
		}).formatToParts(date);
	}
	const part = (type: string): string => parts.find((entry) => entry.type === type)?.value ?? "";
	const day = Number(part("day"));
	const month = MONTHS[Number(part("month")) - 1] ?? "";
	const year = part("year");
	const hour = Number(part("hour"));
	const minute = part("minute");
	const clock =
		timeFormat === "g:i A"
			? `${hour % 12 === 0 ? 12 : hour % 12}:${String(minute).padStart(2, "0")} ${hour < 12 ? "AM" : "PM"}`
			: `${String(hour).padStart(2, "0")}:${String(minute).padStart(2, "0")}`;
	return `${day} ${month} ${year}, ${clock}`;
};

/** `SecurityView::roleSummary()`: "view, add items, edit …". */
export const roleSummary = (
	key: string,
	roles: readonly SecurityRole[],
	permissions: readonly Permission[],
): string => {
	if (key === "admin") {
		return "everything";
	}
	const role = roles.find((row) => row.key === key);
	const held = role?.perms ?? [];
	if (held.length === 0) {
		return "nothing";
	}
	return held
		.map((perm) => (permissions.find((entry) => entry.key === perm)?.label ?? perm).toLowerCase())
		.join(", ");
};

/** `ucfirst()` over the ASCII range only, as PHP's C locale does. */
const ucfirst = (value: string): string =>
	/^[a-z]/.test(value) ? value[0]?.toUpperCase() + value.slice(1) : value;

/** The permission grid: one checkbox per permission, guest's forbidden ones disabled. */
const permGrid = (
	key: string,
	have: readonly string[],
	locked: boolean,
	permissions: readonly Permission[],
): string => {
	let out = '<fieldset class="perm-grid"><legend class="vh">Permissions</legend>';
	for (const permission of permissions) {
		const disabled = locked || (key === "guest" && NOT_FOR_GUESTS.includes(permission.key));
		out +=
			`<label class="perm"><input type="checkbox" name="perms[]" value="${permission.key}" id="perm-${e(key)}-${permission.key}"` +
			(have.includes(permission.key) ? " checked" : "") +
			(disabled ? " disabled" : "") +
			`><span><b>${e(permission.label)}</b><small>${e(permission.hint)}</small></span></label>`;
	}
	return `${out}</fieldset>`;
};

/** Legacy `setting_field_html()` for `bool`, `role` and `int` (the types this section uses). */
const settingField = (field: SecurityField, roles: readonly SecurityRole[], error: string | null): string => {
	const key = field.key;
	const label = field.label;
	const type = field.type;
	const def = field.default;
	const help = field.help;
	const options = field.options;
	const value = field.value;
	const id = `s-${key}`;
	const err = error !== null ? `<p class="field-err" id="${id}-err">${e(error)}</p>` : "";
	const defText =
		type === "bool"
			? def
				? "on"
				: "off"
			: def === null || def === ""
				? "empty"
				: typeof def === "string" || typeof def === "number" || typeof def === "boolean"
					? String(def)
					: "";
	const desc =
		(help !== "" ? `<small class="field-help">${e(help)}</small>` : "") +
		`<small class="field-def">Default: ${e(defText)}</small>`;
	const aria = ` aria-describedby="${id}-d"` + (error !== null ? ' aria-invalid="true"' : "");
	const wrapD = `<div class="field-desc" id="${id}-d">${desc}</div>`;
	const cls = `field set-field${error !== null ? " has-err" : ""}`;
	const str = (x: unknown): string =>
		Array.isArray(x) ? "Array" : x === null || x === undefined ? "" : String(x);
	switch (type) {
		case "bool":
			return (
				`<div class="${cls} set-bool"><label class="switch" for="${id}"><input type="hidden" name="${key}" value="0">` +
				`<input type="checkbox" role="switch" name="${key}" id="${id}" value="1"${value ? " checked" : ""}${aria}>` +
				`<span class="switch-ui" aria-hidden="true"></span><span class="switch-label">${e(label)}</span></label>${wrapD}${err}</div>`
			);
		case "role": {
			let sel = "";
			for (const role of roles) {
				if (role.key !== "admin" && role.key !== "guest") {
					sel += `<option value="${e(role.key)}"${role.key === value ? " selected" : ""}>${e(role.name)}</option>`;
				}
			}
			return `<div class="${cls}"><label class="field-l" for="${id}">${e(label)}</label><select name="${key}" id="${id}"${aria}>${sel}</select>${wrapD}${err}</div>`;
		}
		case "int":
			return (
				`<div class="${cls}"><label class="field-l" for="${id}">${e(label)}</label><input type="number" name="${key}" id="${id}" value="${e(str(value))}"` +
				` min="${str(options.min ?? "")}" max="${str(options.max ?? "")}" step="1" inputmode="numeric"${options.nullable ? ' placeholder="empty"' : " required"}${aria}>${wrapD}${err}</div>`
			);
		default:
			return (
				`<div class="${cls}"><label class="field-l" for="${id}">${e(label)}</label><input type="text" name="${key}" id="${id}" value="${e(str(value))}"` +
				` maxlength="${Math.trunc(Number(options.max ?? 200))}"${options.required ? " required" : ""} autocomplete="off"${aria}>${wrapD}${err}</div>`
			);
	}
};

/** The password card, the users list and the roles editor: legacy `settings_security_html()`. */
const securitySection = (v: SecurityState, tok: string, errors: Readonly<Record<string, string>>): string => {
	const h = e;
	const on = v.on;
	const me = v.me;
	const roles = v.roles;
	const min = v.passwordMin;
	const max = v.usernameMax;
	const roleName = (key: string): string => roles.find((row) => row.key === key)?.name ?? ucfirst(key);
	const accountRoles = roles.filter((role) => role.key !== "guest");
	const err = (key: string, id: string): string =>
		errors[key] !== undefined ? `<div class="err" role="alert" id="${id}">${h(errors[key] ?? "")}</div>` : "";
	const pw = (id: string, label = "Password"): string =>
		`<label class="field"><span>${label} <em>at least ${min} characters</em></span><input type="password" name="new" id="${id}" autocomplete="new-password" minlength="${min}" required></label>` +
		`<label class="field"><span>Repeat ${label.toLowerCase()}</span><input type="password" name="confirm" id="${id}-confirm" autocomplete="new-password" minlength="${min}" required></label>`;
	const cur =
		'<label class="field"><span>Your current password</span><input type="password" name="current" id="pw-current" autocomplete="current-password" required></label>';
	const roleSel = (name: string, id: string, current: string): string => {
		let out = `<select name="${name}" id="${id}">`;
		for (const role of accountRoles) {
			out += `<option value="${h(role.key)}"${role.key === current ? " selected" : ""}>${h(role.name)} \u2013 ${h(roleSummary(role.key, roles, v.permissions))}</option>`;
		}
		return `${out}</select>`;
	};

	let o =
		`<section class="set-card" id="pw-card"><p class="pw-state" id="pw-state">${icon("lock")} ` +
		(on
			? `Accounts are on: everybody signs in with their own user name and password${v.guestBrowsing ? "; visitors who are not signed in can look around as guests." : "."}`
			: "Accounts are off: everyone is an anonymous guest with admin rights (fine on a private home network).") +
		`</p>${err("password", "pw-err")}`;
	if (on) {
		o +=
			`<form method="post" action="/settings/password" class="setform" id="pw-change"><input type="hidden" name="csrf" value="${tok}"><input type="hidden" name="mode" value="change">` +
			`<h2 class="set-h">Change your password</h2><p class="hint">Signed in as <b id="me-name">${h(me.username)}</b> (${h(roleName(me.role))}).</p>` +
			cur +
			pw("pw-new", "New password")
				.replace('name="new" id="pw-new"', 'name="new" id="pw-new"')
				.replace('id="pw-new-confirm"', 'id="pw-confirm"') +
			'<button class="btn btn-primary btn-wide" id="pw-save">Change password</button></form>' +
			'<form method="post" action="/settings/password" class="setform" id="pw-disable" data-confirm="Turn sign-in off? Anyone on the network can then use Apotheke with full rights. The accounts are kept."><input type="hidden" name="csrf" value="' +
			tok +
			'"><input type="hidden" name="mode" value="disable">' +
			'<h2 class="set-h">Turn sign-in off</h2>' +
			cur.replace('id="pw-current"', 'id="pw-current-off"') +
			'<button class="btn btn-danger btn-wide" id="pw-off">Turn sign-in off</button></form>';
	} else {
		const first = v.admins === 0;
		o +=
			'<form method="post" action="/settings/password" class="setform" id="pw-enable"><input type="hidden" name="csrf" value="' +
			tok +
			'"><input type="hidden" name="mode" value="change">' +
			'<h2 class="set-h">Turn sign-in on</h2><p class="hint">' +
			(first
				? "Create the first account \u2013 an admin. You are signed in with it right away."
				: "Accounts exist already: enter an admin\u2019s user name and password to turn sign-in on again.") +
			"</p>" +
			`<label class="field"><span>User name</span><input type="text" name="username" id="pw-user" value="admin" autocomplete="username" autocapitalize="off" spellcheck="false" maxlength="${max}" required></label>` +
			(first
				? pw("pw-new").replace('id="pw-new-confirm"', 'id="pw-confirm"')
				: '<label class="field"><span>Password</span><input type="password" name="new" id="pw-new" autocomplete="current-password" required></label>') +
			'<button class="btn btn-primary btn-wide" id="pw-save">Turn sign-in on</button></form>';
	}
	o += "</section>";

	const users = v.users;
	o += `<section class="set-card" id="users"><h2 class="set-h">Users <span class="muted-n">${users.length}</span></h2>${err("users", "users-err")}`;
	if (users.length === 0) {
		o += '<p class="hint">No accounts yet.</p>';
	}
	o += '<ul class="userlist" id="userlist">';
	for (const u of users) {
		const self = !me.guest && me.id === u.id;
		const id = u.id;
		o +=
			`<li class="user" id="user-${id}"><details><summary><span class="user-ic">${icon("lock")}</span><span class="user-t"><b>${h(u.username)}</b>${self ? " <em>(you)</em>" : ""}` +
			`<span class="role role-${h(u.role)}">${h(roleName(u.role))}</span>` +
			(u.status === "pending"
				? `<span class="role role-pending" id="pending-${id}">Waiting for approval</span>`
				: "") +
			`<small>${u.lastLoginAt ? `Last sign-in ${h(formatTime(u.lastLoginAt, v.timeFormat, v.timezone))}` : "Never signed in"}</small></span></summary>` +
			'<div class="user-forms">' +
			(u.status === "pending"
				? `<form method="post" action="/settings/users/${id}/approve" class="user-f"><input type="hidden" name="csrf" value="${tok}"><button class="btn btn-primary btn-wide" id="approve-${id}">${icon("check")} Approve account</button></form>`
				: "") +
			`<form method="post" action="/settings/users/${id}/role" class="user-f"><input type="hidden" name="csrf" value="${tok}"><label class="field"><span>Role</span>${roleSel("role", `role-${id}`, u.role)}</label><button class="btn" id="role-save-${id}">Change role</button></form>` +
			`<form method="post" action="/settings/users/${id}/rename" class="user-f"><input type="hidden" name="csrf" value="${tok}"><label class="field"><span>User name</span><input type="text" name="username" id="uname-${id}" value="${h(u.username)}" maxlength="${max}" autocapitalize="off" spellcheck="false" required></label><button class="btn">Rename</button></form>` +
			`<form method="post" action="/settings/users/${id}/password" class="user-f"><input type="hidden" name="csrf" value="${tok}">${pw(`upw-${id}`, "New password")}<button class="btn" id="upw-save-${id}">Set password</button></form>` +
			(!self
				? `<form method="post" action="/settings/users/${id}/delete" data-confirm="Delete the account \u201c${h(u.username)}\u201d? Their history entries keep the name."><input type="hidden" name="csrf" value="${tok}"><button class="btn btn-danger btn-wide" id="udel-${id}">${icon("trash")} Delete account</button></form>`
				: "") +
			"</div></details></li>";
	}
	o +=
		'</ul><form method="post" action="/settings/users" class="setform" id="user-add"><input type="hidden" name="csrf" value="' +
		tok +
		'"><h2 class="set-h">Add a user</h2>' +
		`<label class="field"><span>User name</span><input type="text" name="username" id="nu-name" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="${max}" required></label>` +
		`<label class="field"><span>Role</span>${roleSel("role", "nu-role", "editor")}</label>` +
		pw("nu-pw") +
		`<button class="btn btn-primary btn-wide" id="nu-save">${icon("plus")} Add user</button></form>` +
		(on ? "" : '<p class="hint">Accounts only count while sign-in is on.</p>') +
		"</section>";
	o += rolesSection(v, tok, errors);

	return (
		o +
		'<p class="hint">Locked out? On the server run <code>php bin/settings.php user password admin</code> to set a new password, <code>php bin/settings.php user role NAME admin</code> to make someone an admin, <code>php bin/settings.php roles reset</code> to restore the built-in roles, or <code>php bin/settings.php auth off</code> to turn accounts off.</p>'
	);
};

/** Legacy `settings_roles_html()`: one card per role with a permission checklist; admin read-only. */
const rolesSection = (v: SecurityState, tok: string, errors: Readonly<Record<string, string>>): string => {
	const h = e;
	const roles = v.roles;
	let o =
		`<section class="set-card" id="roles"><h2 class="set-h">Roles &amp; permissions <span class="muted-n">${roles.length}</span></h2>` +
		(errors.roles !== undefined
			? `<div class="err" role="alert" id="roles-err">${h(errors.roles)}</div>`
			: "") +
		'<p class="hint">A role is a set of permissions. Admin always has every permission. Guest is what visitors who are not signed in may do when guest browsing is on.</p><ul class="rolelist" id="rolelist">';
	for (const role of roles) {
		const ek = h(role.key);
		o +=
			`<li class="rolecard" id="rc-${ek}"><details><summary><span class="user-t"><b>${h(role.name)}</b>${role.builtin ? " <em>built-in</em>" : ""}` +
			`<small>${role.key === "guest" ? "visitors" : `${role.users} ${role.users === 1 ? "account" : "accounts"}`} \u00b7 ${h(roleSummary(role.key, roles, v.permissions))}</small></span></summary>`;
		if (role.key === "admin") {
			o += `<div class="user-forms">${permGrid(role.key, role.perms, true, v.permissions)}<p class="hint">The admin role cannot be changed or deleted.</p></div></details></li>`;
			continue;
		}
		o +=
			`<div class="user-forms"><form method="post" action="/settings/roles/${ek}" class="user-f" id="role-form-${ek}"><input type="hidden" name="csrf" value="${tok}">` +
			(role.builtin
				? ""
				: `<label class="field"><span>Name</span><input type="text" name="name" id="role-name-${ek}" value="${h(role.name)}" maxlength="60" required></label>`) +
			permGrid(role.key, role.perms, false, v.permissions) +
			`<button class="btn btn-primary btn-wide" id="role-save-perm-${ek}">Save ${h(role.name)}</button></form>`;
		if (!role.builtin) {
			let sel = "";
			for (const other of roles) {
				if (other.key !== "guest" && other.key !== role.key) {
					sel += `<option value="${h(other.key)}"${other.key === "viewer" ? " selected" : ""}>${h(other.name)}</option>`;
				}
			}
			o +=
				`<form method="post" action="/settings/roles/${ek}/delete" class="user-f" data-confirm="Delete the role \u201c${h(role.name)}\u201d?"><input type="hidden" name="csrf" value="${tok}">` +
				`<label class="field"><span>Move its accounts to</span><select name="move_to" id="role-move-${ek}">${sel}</select></label>` +
				`<button class="btn btn-danger btn-wide" id="role-del-${ek}">${icon("trash")} Delete role</button></form>`;
		}
		o += "</div></details></li>";
	}
	return (
		o +
		'</ul><form method="post" action="/settings/roles" class="setform" id="role-add"><input type="hidden" name="csrf" value="' +
		tok +
		'"><h2 class="set-h">Add a role</h2>' +
		'<label class="field"><span>Name</span><input type="text" name="name" id="nr-name" maxlength="60" required></label>' +
		permGrid("new", ["view"], false, v.permissions) +
		`<button class="btn btn-primary btn-wide" id="nr-save">${icon("plus")} Add role</button></form></section>`
	);
};

/**
 * `SecurityView::page()`: the whole document.
 *
 * `settings` carries the shell's values and this request's token; `errors` is the re-render after a
 * refused write, keyed as v3 keys it (`users`, `roles`, `password`, `_`).
 */
export const securityPage = (
	settings: PageSettings,
	v: SecurityState,
	errors: Readonly<Record<string, string>> = {},
	toast: string | null = null,
): string => {
	const head = pageStart(settings, `${LABEL} \u00b7 Settings`, "page-settings");
	const tok = e(settings.csrf);
	const fields = v.fields;
	const count = Object.keys(errors).length;

	let body = '<main class="wrap narrow set-security">\n';
	body += `  <p class="hint">${e(DESCRIPTION)}</p>\n`;
	body += "  ";
	if (count > 0) {
		body += `<div class="err" role="alert" id="set-errors">Please check the highlighted ${count === 1 ? "field" : "fields"}.${errors._ !== undefined ? ` ${e(errors._)}` : ""}</div>`;
	}
	body += "  \n    ";
	body += securitySection(v, tok, errors);
	body += "    \n";
	if (fields.length > 0) {
		body +=
			'    <form method="post" action="/settings/security" class="setform" id="setform" novalidate>\n' +
			`    <input type="hidden" name="csrf" value="${tok}">` +
			"\n          ";
		for (let index = 0; index < fields.length; index += 1) {
			const field = fields[index];
			if (field === undefined) {
				continue;
			}
			if (index > 0) {
				body += "      ";
			}
			body += settingField(field, v.roles, errors[field.key] ?? null) + "          ";
		}
		body +=
			"        " +
			'<div class="set-actions"><button class="btn btn-primary btn-wide" id="set-save">' +
			icon("check") +
			" Save</button></div>\n" +
			"      </form>\n" +
			'    <form method="post" action="/settings/security/reset" class="set-reset" data-confirm="Reset \u201c' +
			e(LABEL) +
			'\u201d to the defaults from config.php?">\n' +
			`    <input type="hidden" name="csrf" value="${tok}">\n` +
			'    <button class="btn btn-wide" id="set-reset">' +
			icon("undo") +
			" Reset this section to defaults</button>\n" +
			"  </form>\n" +
			"  </main>";
	} else {
		body += "  " + "  </main>";
	}

	const tail = toast !== null ? `<div class="toast" role="status">${e(toast)}</div>` : "";
	return head + body + "\n" + tail + pageEnd();
};
