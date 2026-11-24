// Control Panel → Users & sign-in (plan §5 S1.3, increment 1's surface): `SecurityController`'s page
// and its users half.
//
// Ported from `modules/Auth/Controllers/SecurityController.php`: `index()` (the page and its
// `?done=` toasts) and `user()` (`POST /settings/users`, `/settings/users/{id}` and
// `/settings/users/{id}/{action}`). v3 answers every refusal by re-rendering the page with the message
// and v3's status — 409 for the last admin, 403 for the admin-only guards, 404 for an unknown action —
// so that is what this file does: no handler here invents an error page for a refusal a person caused.
//
// Not in this file, and deliberately: `save()`/`reset()` (the section's settings form) and
// `role()` (the roles editor). Their routes still answer 501, exactly as the generated table says;
// the roles half is increment 2b, and the settings form's own validation is not this slice.
//
// The CSRF token is already checked by `sessioncsrf` before the handler runs — v3's order, the global
// filter before the controller — so nothing here re-checks it.

import type { SettingValue } from "../db/settings-schema";
import { decodeSetting, SECURITY_SECTION_FIELDS, SETTING_SPECS } from "../db/settings-schema";
import {
	type SecurityField,
	type SecurityRole,
	type SecurityState,
	securityPage,
} from "../html/security-view";
import type { Ctx } from "../http/filters";
import { html } from "../http/problem";
import { routeParams } from "../http/route-table";
import { ALL } from "./permissions";
import { listed } from "./roles";
import { pageSettings, redirectTo } from "./session-routes";
import {
	accountRoles,
	activeAdmins,
	all as allUsers,
	approve,
	create,
	PASSWORD_MIN,
	remove,
	rename,
	setPassword,
	setRole,
	USERNAME_MAX,
	UserError,
} from "./users";

/** `SecurityController::TOASTS`: the `?done=` messages, v3's words. */
const TOASTS: Readonly<Record<string, string>> = {
	saved: "Settings saved",
	reset: "Section reset to defaults",
	logo: "Logo updated",
	"logo-reset": "Default logo restored",
	pw: "Password saved",
	"pw-off": "Sign-in turned off",
	"user-added": "User added",
	"user-role": "Role changed",
	"user-pw": "Password set",
	"user-renamed": "User renamed",
	"user-deleted": "User deleted",
	"user-approved": "Account approved",
	"token-saved": "Token saved",
	"token-cleared": "Token cleared",
	"saved-token-cleared":
		"Settings saved. The token was cleared because the service address changed: enter it again.",
	"reset-token-cleared":
		"Section reset to defaults. The token was cleared because the service address changed: enter it again.",
	"role-added": "Role added",
	"role-saved": "Role saved",
	"role-deleted": "Role deleted",
};

/** `setting_default()` for a literal: the table above spells it, `cfg:` rows fall back to null here. */
const defaultOf = (key: string): SettingValue => {
	const spec = SETTING_SPECS.find(([name]) => name === key)?.[1];
	return spec === undefined || (typeof spec === "string" && spec.startsWith("cfg")) ? null : spec;
};

/**
 * `SecurityController::securityPage()`'s `$v`, from the tables the request can read: the settings
 * rows (decoded), the accounts, the roles, and the actor the permission check already resolved.
 */
const securityState = async (ctx: Ctx): Promise<SecurityState> => {
	const rows = await ctx.session.sql.all({ sql: "SELECT name, value FROM settings" });
	const values = new Map<string, SettingValue>();
	for (const row of rows) {
		if (typeof row.name === "string") {
			values.set(row.name, decodeSetting(typeof row.value === "string" ? row.value : null));
		}
	}
	const text = (key: string, fallback: string): string => {
		const value = values.get(key);
		return typeof value === "string" && value !== "" ? value : fallback;
	};

	const fields: SecurityField[] = SECURITY_SECTION_FIELDS.map((field) => ({
		...field,
		default: defaultOf(field.key),
		value: values.has(field.key) ? (values.get(field.key) ?? null) : defaultOf(field.key),
	}));

	const accounts = await allUsers(ctx.session.sql);
	const roles: SecurityRole[] = (await listed(ctx.session.sql)).map((role) => ({
		key: role.key,
		name: role.name,
		builtin: role.builtin,
		users: role.users,
		perms: role.perms,
	}));
	const actor = ctx.actor;
	const me = {
		id: actor?.id ?? null,
		username: accounts.find((account) => account.id === actor?.id)?.username ?? "",
		role: actor?.role ?? "",
		guest: actor?.guest ?? false,
	};

	return {
		on: ctx.config.signInEnabled,
		guestBrowsing: ctx.config.login.guestBrowsing,
		me,
		admins: await activeAdmins(ctx.session.sql),
		users: accounts,
		roles,
		fields,
		usernameMax: USERNAME_MAX,
		passwordMin: PASSWORD_MIN,
		timeFormat: text("time_format", "H:i"),
		timezone: text("timezone", "UTC"),
		permissions: ALL.map(([key, label, hint]) => ({ key, label, hint })),
	};
};

/** `GET /settings/security`: the page, with the toast a `?done=` asks for. */
export const securityPageHandler = async (ctx: Ctx): Promise<Response> => {
	await ctx.session.ensureToken();
	const done = ctx.url.searchParams.get("done") ?? "";
	const state = await securityState(ctx);
	return html(securityPage(pageSettings(ctx), state, {}, TOASTS[done] ?? null));
};

/** v3's `status(UserError)`: the code it carries, or 422 when it carries none. */
const statusOf = (error: UserError): number => (Number.isFinite(error.status) ? error.status : 422);

/** A refusal re-renders the page with the message under Users, exactly as v3's catch does. */
const refused = async (ctx: Ctx, error: UserError): Promise<Response> => {
	const state = await securityState(ctx);
	return html(securityPage(pageSettings(ctx), state, { users: error.message }, null), statusOf(error));
};

/**
 * `POST /settings/users`, `/settings/users/{id}` and `/settings/users/{id}/{action}`.
 *
 * The actions are v3's, in its order: no id creates, then role, password, rename, approve, delete;
 * anything else is `Page not found.` at 404 — including a bare `/settings/users/{id}`, which is what
 * v3's own `else` does.
 */
export const userHandler = async (ctx: Ctx): Promise<Response> => {
	const route = ctx.route;
	const params = route === null ? [] : routeParams(route, ctx.path);
	const id = params[0] ?? "";
	const action = params[1] ?? "";
	const uid = id !== "" ? Number.parseInt(id, 10) : 0;
	const actor = ctx.actor;
	if (actor === null) {
		return refused(ctx, new UserError("Not signed in", 401));
	}
	const post = ctx.post;
	const field = (name: string): string => post.get(name) ?? "";
	const roleList = accountRoles(
		(await listed(ctx.session.sql)).map((role) => ({ key: role.key, name: role.name })),
	);

	try {
		let done: string;
		if (uid === 0) {
			await create(
				ctx.session.sql,
				actor,
				{
					username: field("username"),
					password: field("new"),
					confirm: field("confirm"),
					role: field("role"),
					roles: roleList,
				},
				ctx.session.now,
			);
			done = "user-added";
		} else if (action === "role") {
			await setRole(ctx.session.sql, actor, uid, field("role"), roleList);
			done = "user-role";
		} else if (action === "password") {
			const epoch = await setPassword(ctx.session.sql, actor, uid, field("new"), field("confirm"));
			if (uid === actor.id) {
				// v3's own account: the session is re-signed in with the new epoch so it stays valid,
				// which is what `SessionGate::signIn(..., keepSignIn: true)` does there.
				await ctx.session.signIn({ userId: uid, authEpoch: epoch });
				await ctx.session.ensureToken();
			}
			done = "user-pw";
		} else if (action === "rename") {
			await rename(ctx.session.sql, actor, uid, field("username"));
			done = "user-renamed";
		} else if (action === "approve") {
			await approve(ctx.session.sql, actor, uid);
			done = "user-approved";
		} else if (action === "delete") {
			if (uid === actor.id) {
				throw new UserError("You cannot delete your own account while signed in with it.", 409);
			}
			await remove(ctx.session.sql, actor, uid);
			done = "user-deleted";
		} else {
			throw new UserError("Page not found.", 404);
		}
		return redirectTo(ctx, `/settings/security?done=${done}#users`);
	} catch (error) {
		if (error instanceof UserError) {
			return refused(ctx, error);
		}
		throw error;
	}
};
