// `PermissionCheck`, `RoutePermission`'s answer and v3's refusal (plan §5 S1.3).
//
// Ported from `modules/Auth/PermissionCheck.php` and `modules/Auth/Filters/PermissionFilter.php`. The
// route's own `permission` is already in the generated table, so what is left is: who is asking, and
// what the answer is when the role does not allow it.
//
// The who is resolved once per request, before the filter chain, because a filter cannot await and the
// role's permissions are a database read (`Ctx.actor`). Three cases, all v3's:
//
//   * sign-in off — the anonymous admin: role `admin`, which allows everything, as v3's
//     `CurrentUser` resolves. It is *anon*, so an unmapped route still refuses;
//   * a session — the account's own role, with the roles table's permissions;
//   * nobody — guest browsing when it is on (role `guest`, v3's visitor), otherwise no actor at all.
//
// Fail closed: if the roles read throws, the caller keeps `actor = null` and every route that needs a
// permission refuses, which is the honest answer when the check could not be made.

import type { Sql } from "../db/sql";
import type { Identity } from "../session/store";
import { ALL, BACKUP_OR_RESTORE, clean, name as permissionName, PUBLIC, ROLE_DEFAULTS, SELF, UNMAPPED } from "./permissions";
import { listed, roleName } from "./roles";
import { find } from "./users";

/** The account a request is made by, as the permission check sees it. */
export interface Actor {
	readonly id: number | null;
	readonly role: string;
	/** v3's `anon`: the anonymous admin, which only exists while sign-in is off. */
	readonly anon: boolean;
	/** v3's `guest`: the guest-browsing visitor, signed in as nobody. */
	readonly guest: boolean;
	readonly roleName: string;
	readonly perms: readonly string[];
}

export interface ActorConfig {
	readonly signInEnabled: boolean;
	readonly guestBrowsing: boolean;
}

/** The built-in permissions of a role the table does not have (v3's fallback before migration 0022). */
const fallbackPerms = (role: string): readonly string[] => {
	const builtin = ROLE_DEFAULTS.find(([key]) => key === role);
	return builtin === undefined ? [] : clean(role, builtin[2]);
};

/** Resolve the actor for this request. One roles read, plus the account row when there is a session. */
export const resolveActor = async (sql: Sql, identity: Identity | null, config: ActorConfig): Promise<Actor | null> => {
	if (!config.signInEnabled) {
		// `CurrentUser` with accounts off: the anonymous admin, full rights, never a real account.
		return { id: null, role: "admin", anon: true, guest: false, roleName: "Admin", perms: [...ALL.map(([key]) => key)] };
	}
	const roles = await listed(sql);
	const forRole = (role: string): Pick<Actor, "roleName" | "perms"> => ({
		roleName: roleName(roles, role),
		perms: roles.find((row) => row.key === role)?.perms ?? fallbackPerms(role),
	});
	if (identity === null) {
		if (!config.guestBrowsing) {
			return null;
		}
		return { id: null, role: "guest", anon: false, guest: true, ...forRole("guest") };
	}
	const user = await find(sql, identity.userId);
	if (user === null) {
		return null; // a session whose account is gone authenticates as nobody
	}
	return { id: user.id, role: user.role, anon: false, guest: user.role === "guest", ...forRole(user.role) };
};

/** `PermissionCheck::allows()`. */
export const allows = (actor: Actor | null, need: string): boolean => {
	if (need === PUBLIC) {
		return true;
	}
	if (actor === null || need === UNMAPPED) {
		return false;
	}
	if (need === SELF) {
		return !actor.guest;
	}
	if (need === BACKUP_OR_RESTORE) {
		return actor.role === "admin" || actor.perms.includes("backup") || actor.perms.includes("restore");
	}
	return actor.role === "admin" || actor.perms.includes(need);
};

/** `PermissionCheck::SIGN_IN`: nobody, or the anonymous admin. */
export const SIGN_IN = "sign-in";

/**
 * `PermissionCheck::refusal()`: null when the request may go on, `SIGN_IN` when the visitor must sign
 * in, and otherwise v3's 403 sentence, naming the role and the permission it lacks.
 */
export const refusal = (actor: Actor | null, need: string): string | null => {
	if (allows(actor, need)) {
		return null;
	}
	if (actor === null || actor.anon) {
		return SIGN_IN;
	}
	const named = ALL.find(([key]) => key === need)?.[1];
	const hint =
		named !== undefined
			? ` \u2013 it needs the \u201c${named}\u201d permission`
			: need === BACKUP_OR_RESTORE
				? " \u2013 it needs the \u201cBackup\u201d or the \u201cRestore\u201d permission"
				: "";
	return `Your role (${actor.roleName}) does not allow this${hint}. Ask an admin.`;
};

/** The permission's display name, for a caller that needs to say what was missing. */
export const needed = permissionName;
