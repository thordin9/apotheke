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
//     `CurrentUser` resolves. v3 marks it **guest** (so `signedIn()` is false and `self` is refused)
//     and *not* `anon`; both flags are reproduced here rather than tidied up, because S1.4's
//     `GET /api/v1/session` prints `signed_in: false` and `GET /api/v1/me` answers 403 for it;
//   * a session — the account's own role, with the roles table's permissions;
//   * nobody — guest browsing when it is on (role `guest`, v3's visitor, which v3 marks **anon** so
//     the permission check answers "sign in", 401 on the API), otherwise no actor at all.
//
// Fail closed: if the roles read throws, the caller keeps `actor = null` and every route that needs a
// permission refuses, which is the honest answer when the check could not be made.

import type { Sql } from "../db/sql";
import type { Identity } from "../session/store";
import { ALL, BACKUP_OR_RESTORE, clean, PUBLIC, ROLE_DEFAULTS, SELF, UNMAPPED } from "./permissions";
import { listed, roleName } from "./roles";
import { find } from "./users";

/** The account a request is made by, as the permission check sees it. */
export interface Actor {
	readonly id: number | null;
	readonly role: string;
	/** v3's `anon`: the guest-browsing visitor. A refusal for it is "sign in", not "your role". */
	readonly anon: boolean;
	/** v3's `guest`: nobody real — the anonymous admin (sign-in off) or the guest-browsing visitor. */
	readonly guest: boolean;
	readonly username: string;
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
export const resolveActor = async (
	sql: Sql,
	identity: Identity | null,
	config: ActorConfig,
): Promise<Actor | null> => {
	if (!config.signInEnabled) {
		// `CurrentUser` with accounts off: the anonymous admin, full rights, never a real account. v3
		// returns `guest: true, anon: false` for it (`CurrentUser::resolve()`), so it is not "signed
		// in" and `self` is refused to it.
		return {
			id: null,
			role: "admin",
			anon: false,
			guest: true,
			username: "",
			roleName: "Admin",
			perms: [...ALL.map(([key]) => key)],
		};
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
		return { id: null, role: "guest", anon: true, guest: true, username: "", ...forRole("guest") };
	}
	const user = await find(sql, identity.userId);
	if (user === null) {
		return null; // a session whose account is gone authenticates as nobody
	}
	// A real account is never `guest` or `anon`, whatever its role is: v3's two flags describe how the
	// visitor was resolved, not which role the account has.
	return {
		id: user.id,
		role: user.role,
		anon: false,
		guest: false,
		username: user.username,
		...forRole(user.role),
	};
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
