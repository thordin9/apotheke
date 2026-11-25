// `/account`, `POST /account` and `POST /account/signout` (plan §5 S1.5): a port of
// `modules/Auth/Controllers/AccountController.php`.
//
// v3 answers a refusal on these routes by **re-rendering the page with the sentence**, not with a
// problem document or a redirect: 403 "The current password is not correct." and the four 422s from
// `passwordValid()` all arrive as the account page with `#acct-err` filled in and the page's own
// status. The two successes answer 303 (to `/account?done=pw` and `/account?done=out`) after signing
// this session back in with the new epoch, so this device stays signed in and every other one stops.
//
// The route's permission is `self`, and it is checked before the handler — which is why the guest
// branch below can only be reached in principle: `PermissionCheck::allows()` refuses the guest with
// "sign in" first, so v3 answers a visitor 303 to `/login` and never the controller's redirect. The
// branch is ported as v3 has it, and the fixture records v3's actual answer to a guest (twice) so the
// difference cannot drift into a claim.
//
// The page's CSRF is the page's own (`sessioncsrf`, 419) — v3's order: permission, then CSRF, then
// the controller. Nothing here re-checks the token.

import { type AccountView, accountPage } from "../html/account-view";
import { bodyClasses, type MenuViewer } from "../html/menu";
import type { Ctx } from "../http/filters";
import { html } from "../http/problem";
import { changeOwnPassword, signOutEverywhere } from "./account";
import { type Actor, allows } from "./permission";
import { ALL } from "./permissions";
import { pageSettings, redirectTo } from "./session-routes";
import { PASSWORD_MIN, UserError } from "./users";

/** The toast a `?done=` asks for (`AccountController::index()`'s two). */
const TOASTS: Readonly<Record<string, string>> = {
	pw: "Password changed",
	out: "Signed out on every other device",
};

/** The permission names the account card sentences its role with (`Permissions::ALL`). */
const PERM_NAMES: Readonly<Record<string, string>> = Object.fromEntries(
	ALL.map(([key, name]) => [key, name]),
);

/** v3's `status(UserError)`: the code it carries, or 422 when it carries none. */
const statusOf = (error: UserError): number =>
	Number.isFinite(error.status) && error.status !== 0 ? error.status : 422;

/** The account a `self` route admitted; `null` is v3's RuntimeException and answers 500. */
interface SignedIn {
	readonly id: number;
	readonly username: string;
	readonly role: string;
}

const signedIn = (ctx: Ctx): SignedIn => {
	const actor = ctx.actor;
	if (actor === null || actor.guest || actor.id === null) {
		throw new Error("The self permission admitted a visitor without an account.");
	}
	return { id: actor.id, username: actor.username, role: actor.role };
};

/** What the header's menu needs of the request's user (`Page::menu()`'s `Viewer`). */
const viewerOf = (actor: Actor): MenuViewer => ({
	username: actor.username,
	role: actor.role,
	roleName: actor.roleName,
	guest: actor.guest,
	anon: actor.anon,
	allows: (permission) => allows(actor, permission),
});

/** `AccountController::accountPage()`: the document, with the request's token and the role's classes. */
const render = (
	ctx: Ctx,
	actor: Actor,
	me: SignedIn,
	error: string | null,
	toast: string | null,
	status = 200,
	extra: Readonly<Record<string, string>> = {},
): Response => {
	const viewer = viewerOf(actor);
	const view: AccountView = {
		viewer,
		username: me.username,
		role: me.role,
		roleName: actor.roleName,
		perms: actor.perms,
		permNames: PERM_NAMES,
		passwordMin: PASSWORD_MIN,
	};
	const settings = { ...pageSettings(ctx), bodyClasses: bodyClasses(viewer) };
	return html(accountPage(settings, view, error, toast), status, extra);
};

/**
 * `/account`: POST changes the password, every other method shows the page (v3's N-63). `posted()`
 * is the *raw* method, so a POST read through `_method` is still a password change there and here.
 */
export const accountHandler = async (ctx: Ctx): Promise<Response> => {
	const actor = ctx.actor;
	if (actor === null || actor.guest || actor.id === null) {
		// Legacy: a guest goes to the user settings when it may manage users, else home. Unreachable
		// through this route (see the file's comment); ported because v3 has it.
		return redirectTo(ctx, allows(actor, "users") ? "/settings/security" : "/");
	}
	await ctx.session.ensureToken();
	const me: SignedIn = { id: actor.id, username: actor.username, role: actor.role };
	if (ctx.rawMethod === "POST") {
		const field = (name: string): string => ctx.post.get(name) ?? "";
		try {
			const epoch = await changeOwnPassword(ctx, me, field("current"), field("new"), field("confirm"));
			// `SessionGate::signIn(..., keepSignIn: true)`: this session keeps its sign-in time.
			await ctx.session.signIn({ userId: me.id, authEpoch: epoch, keepSignIn: true });
			return redirectTo(ctx, "/account?done=pw");
		} catch (error) {
			if (error instanceof UserError) {
				const extra =
					error.retryAfterSeconds === undefined ? {} : { "Retry-After": String(error.retryAfterSeconds) };
				return render(ctx, actor, me, error.message, null, statusOf(error), extra);
			}
			throw error;
		}
	}
	const done = ctx.url.searchParams.get("done") ?? "";
	return render(ctx, actor, me, null, TOASTS[done] ?? null);
};

/** `POST /account/signout`: a new epoch for the account, this session back in with it, 303. */
export const accountSignOutHandler = async (ctx: Ctx): Promise<Response> => {
	const me = signedIn(ctx);
	const epoch = await signOutEverywhere(ctx.session.sql, me.id);
	await ctx.session.signIn({ userId: me.id, authEpoch: epoch, keepSignIn: true });
	return redirectTo(ctx, "/account?done=out");
};
