// `Permissions` (plan §5 S1.3): the permission list and the built-in roles' defaults.
//
// Ported from `modules/Auth/Permissions.php`, including the part that is not obvious: how the
// roles table stores backup and restore. The table is shared with 2.6.x, where "backup" means
// both, so a stored "backup" stands for backup *and* restore here too — and a role that holds
// only one of them is stored under a name 2.6.x does not know (`backup-only` / `restore-only`),
// so a rollback never gives a role more than it has. `stored()` writes that form and
// `fromStored()` reads it; `clean()` is the permission order every answer uses.

/** Needed by nothing: the sign-in pages. */
export const PUBLIC = "public";

/** Any signed-in account, not the guest (own account pages). */
export const SELF = "self";

/** A route legacy answers before its permission check. */
export const NONE = "none";

/** A routed request whose route records no permission: refused to everyone, the admin included. */
export const UNMAPPED = "unmapped";

/** The backup page: backup or restore. */
export const BACKUP_OR_RESTORE = "backup-or-restore";

export const STORED_BACKUP_ONLY = "backup-only";
export const STORED_RESTORE_ONLY = "restore-only";

/** `key => [name, description]`, in the order every list and message uses. */
export const ALL: ReadonlyArray<readonly [string, string, string]> = [
	["view", "View", "look at items, locations, history and photos"],
	["create", "Add items", "new items and copies"],
	["edit", "Edit", "change items, photos, notes, quantities, locations and categories"],
	["delete", "Delete", "delete items (undo still works)"],
	["move", "Move", "change an item’s location"],
	["bulk", "Bulk actions", "select several items and act on all of them"],
	["lend", "Lend", "lend and return items"],
	["import", "Import", "CSV import"],
	["export", "Export", "CSV export"],
	["stocktake", "Stocktake", "run location stocktakes"],
	["labels", "Labels", "print QR labels"],
	["settings", "Settings", "the Control Panel (everything but users)"],
	["users", "Users", "accounts, roles and sign-in options"],
	["backup", "Backup", "download a backup of the whole catalog, and the kept ones"],
	["restore", "Restore", "replace the whole catalog with a backup, for everyone"],
];

export const ALL_KEYS: readonly string[] = ALL.map(([key]) => key);

/** A permission's display name, for a refusal message. */
export const name = (key: string): string => ALL.find(([k]) => k === key)?.[1] ?? key;

/** `role_key => [name, permissions]`: the built-in roles before migration 0022 and after a reset. */
export const ROLE_DEFAULTS: ReadonlyArray<readonly [string, string, readonly string[]]> = [
	["admin", "Admin", ALL_KEYS],
	["editor", "Editor", ["view", "create", "edit", "delete", "move", "bulk", "lend", "import", "export", "stocktake", "labels", "backup", "restore"]],
	["viewer", "Viewer", ["view", "export", "labels", "backup"]],
	["guest", "Guest", ["view"]],
];

/** What the guest role can never have (`RoleAdmin::NOT_FOR_GUESTS`). */
export const NOT_FOR_GUESTS: readonly string[] = ["users", "settings", "backup", "restore"];

/** `Permissions::clean()`: admin has every permission; any other role keeps the known ones, in ALL order. */
export const clean = (role: string, perms: readonly string[]): string[] => {
	if (role === "admin") {
		return [...ALL_KEYS];
	}
	return ALL_KEYS.filter((key) => perms.includes(key));
};

/** A role's permissions as the roles table keeps them (`storage()`): "backup" splits as 2.6.x needs. */
export const stored = (perms: readonly string[]): string[] => {
	const backup = perms.includes("backup");
	const restore = perms.includes("restore");
	const out: string[] = [];
	for (const permission of perms) {
		if (permission === "restore") {
			continue;
		}
		out.push(permission === "backup" && !restore ? STORED_BACKUP_ONLY : permission);
	}
	if (restore && !backup) {
		out.push(STORED_RESTORE_ONLY);
	}
	return out;
};

/** The permissions a stored list stands for: "backup" is both, the two `…-only` names one of them. */
export const fromStored = (raw: readonly unknown[]): unknown[] => {
	const out: unknown[] = [];
	for (const permission of raw) {
		if (permission === "backup") {
			out.push("backup", "restore");
		} else if (permission === STORED_BACKUP_ONLY) {
			out.push("backup");
		} else if (permission === STORED_RESTORE_ONLY || permission === "restore") {
			out.push("restore");
		} else {
			out.push(permission);
		}
	}
	return out;
};

/** The JSON a role's `perms` column holds, in the one form `stored()` produces. */
export const permsJson = (perms: readonly string[]): string => JSON.stringify(stored(perms));

/**
 * Whether a role's stored `perms` column holds the `users` permission. The column is written only
 * by `permsJson()`, so the canonical `"users"` element is what the guard statements look for — and
 * they must look in SQL, because the last-manager decision is taken by the statement, not here.
 */
export const USERS_PERM = `'"users"'`;
