// The pinned state `test/fixtures/php-security.json` was generated from (plan §5 S1.3).
//
// The fixture is only deterministic if the state is: five roles, four accounts (one pending, one that
// never signed in, one whose name needs escaping), fixed UTC timestamps read in a fixed zone, and the
// settings section's real fields. `scripts/gen-security-fixture.php` holds the same values on the PHP
// side; if one moves, the byte comparison in `test/html/security.spec.ts` fails, which is the point.

import { ALL } from "../../src/auth/permissions";
import type { SecurityField, SecurityRole, SecurityState, SecurityUser } from "../../src/html/security-view";

export const SECURITY_PERMISSIONS = ALL.map(([key, label, hint]) => ({ key, label, hint }));

const ALL_PERMS = SECURITY_PERMISSIONS.map((permission) => permission.key);

export const SECURITY_ROLES: readonly SecurityRole[] = [
	{ key: "admin", name: "Admin", builtin: true, users: 1, perms: ALL_PERMS },
	{
		key: "editor",
		name: "Editor",
		builtin: true,
		users: 1,
		perms: [
			"view",
			"create",
			"edit",
			"delete",
			"move",
			"bulk",
			"lend",
			"import",
			"export",
			"stocktake",
			"labels",
			"backup",
			"restore",
		],
	},
	{ key: "viewer", name: "Viewer", builtin: true, users: 1, perms: ["view", "export", "labels", "backup"] },
	{ key: "guest", name: "Guest", builtin: true, users: 0, perms: ["view"] },
	{ key: "manager", name: "Manager", builtin: false, users: 0, perms: ["view", "users"] },
];

export const SECURITY_USERS: readonly SecurityUser[] = [
	{
		id: 1,
		username: "admin",
		role: "admin",
		status: "active",
		createdAt: "2025-12-31 22:00:00",
		lastLoginAt: "2026-01-02 03:04:05",
	},
	{
		id: 2,
		username: "bea",
		role: "editor",
		status: "active",
		createdAt: "2026-01-01 09:30:00",
		lastLoginAt: null,
	},
	{
		id: 3,
		username: "cy",
		role: "viewer",
		status: "pending",
		createdAt: "2026-01-02 10:00:00",
		lastLoginAt: null,
	},
	{
		id: 4,
		username: "own<er> \"q\" 'z'",
		role: "viewer",
		status: "active",
		createdAt: "2026-01-02 11:00:00",
		lastLoginAt: "2026-01-03 00:30:00",
	},
];

/** `SettingsSchema::sectionKeys('security')` with the schema's own labels, help and defaults. */
export const SECURITY_FIELDS: readonly SecurityField[] = [
	{
		key: "auth_guest_disabled",
		label: "Disable guest browsing",
		type: "bool",
		default: true,
		help: "On: visitors must sign in before they see anything. Off: they can look around with the permissions of the Guest role.",
		options: {},
		value: true,
	},
	{
		key: "auth_register_disabled",
		label: "Disable registrations",
		type: "bool",
		default: true,
		help: "Off: anyone who can reach this address can create an account at /register (limited to 5 per hour per address).",
		options: {},
		value: true,
	},
	{
		key: "auth_register_role",
		label: "Role for new registrations",
		type: "role",
		default: "viewer",
		help: "Admin cannot be chosen.",
		options: {},
		value: "viewer",
	},
	{
		key: "auth_register_approval",
		label: "Registrations need an admin’s approval",
		type: "bool",
		default: false,
		help: "New accounts can only sign in once an admin approves them under Users.",
		options: {},
		value: false,
	},
	{
		key: "session_days",
		label: "Stay signed in for (days)",
		type: "int",
		default: 30,
		help: "The longest a sign-in lasts, counted from sign-in. Later visits do not extend it. The server ends it when this time has passed.",
		options: { min: 1, max: 365 },
		value: 30,
	},
	{
		key: "session_idle_hours",
		label: "Sign out after idle (hours)",
		type: "int",
		default: 168,
		help: "End a sign-in after this many hours with no request. 1–8760 (365 days). The stay-signed-in limit above still applies.",
		options: { min: 1, max: 8760 },
		value: 168,
	},
	{
		key: "trust_proxy",
		label: "Trust the reverse proxy",
		type: "bool",
		default: false,
		help: "Turn on only when the app sits behind your own HTTPS proxy (nginx, Caddy, Traefik) that sets X-Forwarded-Proto. Then sign-in cookies are marked Secure. Leave off when the app is reachable directly.",
		options: {},
		value: false,
	},
];

/** `$v` in `SecurityView::page()`, as the fixture's generator pinned it. */
export const SECURITY_STATE: SecurityState = {
	on: true,
	guestBrowsing: false,
	me: { id: 1, username: "admin", role: "admin", guest: false },
	admins: 1,
	users: SECURITY_USERS,
	roles: SECURITY_ROLES,
	fields: SECURITY_FIELDS,
	usernameMax: 40,
	passwordMin: 8,
	timeFormat: "H:i",
	timezone: "Europe/Athens",
	permissions: SECURITY_PERMISSIONS,
};
