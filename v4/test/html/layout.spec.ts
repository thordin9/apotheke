// The Layout module's own subjects (plan §5 S1.7), judged method by method against `src/html/`:
// `tests/Modules/Layout/LayoutTest.php` (read at `5d65939e^`) held six, and five of their subjects exist in v4.
// Every `modules/` path below is read at that commit (D168); every `v4/` line at `55bd1a1a`.
//
// What is *not* here, because v4 has neither shape:
//   * v3's `ErrorPage` guarded a lazy page closure that could throw while the shell was drawn
//     (`modules/Layout/ErrorPage.php:29-46`: the closure is called at `:32`, the catch is `:39`). v4's error
//     page is a pure function of the settings the request read before routing (`src/html/views.ts:104`),
//     so that failure cannot happen inside it.
//   * v3's `Viewer` read the user through closures on every call and caught a throw from the read
//     (`modules/Layout/Viewer.php:66-69`); v4 passes a resolved viewer or `null` (`src/html/menu.ts:43`).
//   * The server-side `theme` cookie (`Theme::cookie()`): v4 reads it since D152 (`src/settings.ts:101`,
//     `:147`); writing it (`POST /theme`) is still P2. Asserted in `test/settings.spec.ts`, and recorded
//     in the ledger row's note.
//
// The escaping case is v3's own: hostile values in every setting the shell prints, and hostile text
// in the message, so a value that reaches the document unescaped is a red test rather than a review.

import { SELF } from "cloudflare:test";
import { describe, expect, it } from "vitest";
import { ALL } from "../../src/auth/permissions";
import { icon } from "../../src/html/icons";
import { md5 } from "../../src/html/md5";
import { bodyClasses, type MenuViewer, menu } from "../../src/html/menu";
import { type PageSettings, safeBack } from "../../src/html/page";
import { errorPage } from "../../src/html/views";

/** A viewer as `menu()` and `bodyClasses()` take it: v3's account plus the permission check. */
const viewer = (role: string, allowed: readonly string[], extra: Partial<MenuViewer> = {}): MenuViewer => ({
	username: "u",
	role,
	roleName: `Role ${role}`,
	guest: false,
	anon: false,
	allows: (permission) => allowed.includes(permission),
	...extra,
});

/** The shell's values, at the defaults; `over` is where a case puts its hostile input. */
const settings = (over: Partial<PageSettings> = {}): PageSettings => ({
	appName: "Apotheke",
	tagline: "the storehouse for your stuff",
	theme: "auto",
	accent: "#5b3b8e",
	csrf: "",
	requestUri: "/",
	consent: true,
	signInEnabled: true,
	...over,
});

describe("the app's error page", () => {
	it("escapes every value it prints, and prints no style or script of its own", () => {
		const hostile = settings({
			appName: '<Box> & "Co"',
			tagline: "it's <i>",
			accent: "#C8502D",
			csrf: 'tok"en',
			requestUri: '/a"b',
			bodyClasses: bodyClasses(viewer('x"y', [])),
		});
		const html = errorPage(hostile, "<img src=x onerror=alert(1)>");

		expect(html).toContain("<title>Error \u00b7 &lt;Box&gt; &amp; &quot;Co&quot;</title>");
		expect(html).toContain("<p>&lt;img src=x onerror=alert(1)&gt;</p>");
		expect(html).toContain('<html lang="en" data-theme="auto">');
		expect(html).toContain('<body class="role-xy no-view');
		expect(html).toContain(
			`<link rel="stylesheet" href="/theme.css?v=${md5("#C8502D").slice(0, 8)}" id="accent-css">`,
		);
		// The two shapes v3's own assertions refused: an inline script, an inline style, and the
		// hostile `<i>` arriving as markup rather than text.
		expect(html).not.toContain("<script>");
		expect(html).not.toContain("<i>");
		expect(html).not.toContain("style=");
	});

	it("starts no session: a 404 page answers without a Set-Cookie", async () => {
		const response = await SELF.fetch("http://localhost/nope");
		expect(response.status).toBe(404);
		expect(response.headers.get("set-cookie")).toBeNull();
	});
});

describe("Viewer::bodyClass(), as bodyClasses()", () => {
	it("gives no role class to nobody, and none to an admin", () => {
		expect(bodyClasses(null)).toBe("");
		expect(
			bodyClasses(
				viewer(
					"admin",
					ALL.map(([key]) => key),
				),
			),
		).toBe("");
	});

	it("gives a real role its class, a no- for every permission it lacks, and is-guest for the visitor", () => {
		expect(bodyClasses(viewer("guest", ["view"], { guest: true, anon: true }))).toBe(
			"role-guest no-create no-edit no-delete no-move no-bulk no-lend no-import no-export" +
				" no-stocktake no-labels no-settings no-users no-backup no-restore is-guest",
		);
	});

	it("sanitises the role key the way v3's preg_replace does", () => {
		expect(bodyClasses(viewer('x"y', []))).toMatch(/^role-xy /);
	});
});

describe("Page::menu(), per role", () => {
	const ids = ["labels", "export", "import", "stocktake", "backup", "settings"] as const;

	it("shows an admin every link, Settings included", () => {
		const html = menu(
			settings(),
			viewer(
				"admin",
				ALL.map(([key]) => key),
			),
		);
		for (const id of ids) {
			expect(html, `menu-${id}`).toContain(`id="menu-${id}"`);
		}
		expect(html).toContain('<a class="menu-item" href="/settings" role="menuitem" id="menu-settings">');
	});

	it("gives Users without Settings the sign-in page, and hides what the role may not use", () => {
		const html = menu(settings(), viewer("manager", ["export", "users"]));
		expect(html).toContain('id="menu-export"');
		expect(html).toContain(
			'<a class="menu-item" href="/settings/security" role="menuitem" id="menu-settings">',
		);
		// v3's one-line dependency: Settings wins over the users-only link when the role has both,
		// and `manager` has only `users`.
		expect(html).not.toContain('href="/settings" role="menuitem" id="menu-settings"');
		for (const id of ["labels", "import", "stocktake", "backup"] as const) {
			expect(html, `menu-${id}`).not.toContain(`id="menu-${id}"`);
		}
	});

	it("links the account only for a real account, and Sign in only for the guest", () => {
		expect(menu(settings(), viewer("viewer", ["view"]))).toContain('id="menu-account"');
		expect(menu(settings(), viewer("viewer", ["view"]))).not.toContain('id="menu-login"');
		const guest = menu(settings(), viewer("guest", ["view"], { guest: true, anon: true }));
		expect(guest).toContain('id="menu-login"');
		expect(guest).not.toContain('id="menu-account"');
	});
});

describe("Theme::safeBack(), as safeBack()", () => {
	it("refuses control characters the way CI4 refused them in a Location header", () => {
		for (const back of ["/a\u0000b", "/a\u0001b", "/a\u001fb", "/a\u007fb", "/a\tb", "/a\rb"]) {
			expect(safeBack(back), JSON.stringify(back)).toBe("/");
		}
	});

	it("keeps a same-site path, and refuses another site", () => {
		for (const back of ["/", "/x?y=1&z=%00", "/\u00e9/\u00fc", "/~a"]) {
			expect(safeBack(back), back).toBe(back);
		}
		expect(safeBack("//other.example")).toBe("/");
		expect(safeBack("/\\other.example")).toBe("/");
		expect(safeBack("https://other.example/")).toBe("/");
	});
});

describe("Icons::svg(), as icon()", () => {
	it("draws a known icon, and an unknown name is an empty icon rather than an error", () => {
		expect(icon("box")).toMatch(/^<svg class="i"/);
		expect(icon("")).toBe(icon("nope"));
	});
});
