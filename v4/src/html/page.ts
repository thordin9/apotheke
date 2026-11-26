// The page shell (plan §5 S1.1), a port of `modules/Layout/Page.php`'s `start()`, `end()`,
// `brandLogo()` and `consentNotice()` — the parts the login page and the error page render.
//
// Byte identity is the point, not a nicety: the whitespace in the strings below is the whitespace
// PHP emits, including the artifacts. A PHP block that stands on its own line prints its leading
// spaces and no newline (`?>` swallows one), which is why the login view indents its first field by
// eight spaces; that is v3's markup, and `test/fixtures/php-html.json` is the oracle that says so.
//
// Not ported here, because phase 1 does not render it: the menu, the undo bar, the toast, the
// header, and custom branding (`logo`/`app_icon` settings) — every page that needs them is P2.
// `consent` is `CookieConsent::request()` for this request (`settings.ts`): false until the visitor
// accepts, and a request without the cookie is exactly the first-visit render the golden fixture was
// generated from.

import { assetUrl } from "../assets/bundle";
import { e } from "./escape";
import { md5 } from "./md5";

/** `Config\Consent::CURRENT`. */
const CONSENT_VERSION = "v1";

/** What the shell needs, read once per request from the settings table (see `src/settings.ts`). */
export interface PageSettings {
	/** `app_name`. */
	readonly appName: string;
	/** `app_tagline`, lower case as stored; the login page upper-cases its first letter. */
	readonly tagline: string;
	/** `auto` | `light` | `dark`: `theme_default` (v3 let a valid `theme` cookie win; v4 does not read it). */
	readonly theme: string;
	/** `accent`; only `#5b3b8e` (the default) suppresses the generated `/theme.css` link. */
	readonly accent: string;
	/** The form token. Empty until S1.2 has a session to keep it in. */
	readonly csrf: string;
	/** The request's path and query, for the consent form's way back. */
	readonly requestUri: string;
	/** Whether the request carries consent (`CookieConsent::request()`). */
	readonly consent: boolean;
	/** `SettingsService::signInEnabled()`: the menu offers "Sign out" when it is on and nobody is anon. */
	readonly signInEnabled: boolean;
	/**
	 * `Viewer::bodyClass()` for this request's user, when the page renders the signed-in shell
	 * (`html/menu.ts`'s `bodyClasses()`). Absent on pages v3 renders without a resolved user.
	 */
	readonly bodyClasses?: string;
}

/** `SettingsSchema::ACCENT_DEFAULT`. */
const ACCENT_DEFAULT = "#5b3b8e";

/**
 * `Theme::safeBack()`: `#^/(?![/\\])[^\s\x00-\x1F\x7F]*$#D` — a same-site, path-absolute target
 * and nothing else (`//host` and `/\host` are other sites). Written as a loop rather than the
 * character class so the control characters stay readable.
 */
export const safeBack = (back: string): string => {
	if (!back.startsWith("/") || back.startsWith("//") || back.startsWith("/\\")) {
		return "/";
	}
	for (const ch of back) {
		const code = ch.codePointAt(0) ?? 0;
		// PCRE's \s in the C locale: space, \t, \n, \v, \f, \r; plus the DEL and C0 controls.
		if (code <= 0x20 || code === 0x7f) {
			return "/";
		}
	}
	return back;
};

/** Legacy `brand_logo()`. */
export const brandLogo = (size: number): string =>
	`<img class="brand-logo" src="${e(assetUrl("icon.svg"))}" width="${size}" height="${size}" alt="" decoding="async">`;

/**
 * The cookie-consent notice, printed until the request carries consent, with the same words and
 * buttons the PHP side prints. Accept (`app.js`) sets the cookie and reloads; Decline posts to
 * `/consent`. Neither handler is ported yet (S1.2), which is exactly why the notice is shown: no
 * cookie is set before Accept, and nothing is set after it either until S1.2.
 */
const consentNotice = (settings: PageSettings): string =>
	settings.consent
		? ""
		: '<div class="consent" id="consent" role="region" aria-labelledby="consent-title"><div class="consent-copy">' +
			'<p id="consent-title" class="consent-title">Cookies</p>' +
			"<p>This site stores nothing until you accept. Accept keeps one cookie for 180 days; signing in adds a session cookie.</p></div>" +
			'<form method="post" action="/consent" class="consent-actions"><input type="hidden" name="return" value="' +
			e(safeBack(settings.requestUri)) +
			'">' +
			'<button type="submit" class="btn" id="consent-decline" name="consent" value="decline">Decline</button>' +
			'<button type="submit" class="btn" id="consent-accept" data-consent="' +
			CONSENT_VERSION +
			'" data-domain="" name="consent" value="accept">Accept</button></form></div>\n';

/** `Page::start()`: the document up to and including `<body>` and the consent notice. */
export const pageStart = (settings: PageSettings, title: string, bodyClass = ""): string => {
	// `trim($bodyClass . ' ' . $viewer->bodyClass())`: the page's own class, then the role's.
	const body = `${bodyClass} ${settings.bodyClasses ?? ""}`.trim();
	const name = settings.appName;
	const tagline = settings.tagline;
	const documentTitle = title === "" ? `${name} \u2013 ${tagline}` : `${title} \u00b7 ${name}`;
	const theme = settings.theme;

	let out =
		'<!doctype html>\n<html lang="en" data-theme="' +
		e(theme) +
		'"' +
		(settings.consent ? "" : " data-no-cookies") +
		">\n<head>\n" +
		'<meta charset="utf-8">\n' +
		'<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">\n' +
		`<title>${e(documentTitle)}</title>\n`;

	if (theme === "auto") {
		out +=
			'<meta name="color-scheme" content="light dark">\n' +
			'<meta name="theme-color" content="#fdfcfa" media="(prefers-color-scheme: light)">\n' +
			'<meta name="theme-color" content="#07161c" media="(prefers-color-scheme: dark)">\n';
	} else {
		out +=
			`<meta name="color-scheme" content="${e(theme)}">\n` +
			`<meta name="theme-color" content="${theme === "dark" ? "#07161c" : "#fdfcfa"}">\n`;
	}

	out +=
		'<meta name="apple-mobile-web-app-capable" content="yes">\n' +
		'<meta name="mobile-web-app-capable" content="yes">\n' +
		`<meta name="apple-mobile-web-app-title" content="${e(name)}">\n` +
		'<meta name="apple-mobile-web-app-status-bar-style" content="default">\n' +
		'<meta name="format-detection" content="telephone=no">\n' +
		`<meta name="description" content="${e(`${name}, ${tagline}`)}">\n` +
		`<meta name="csrf" content="${e(settings.csrf)}">\n` +
		'<link rel="manifest" href="/manifest.webmanifest">\n' +
		'<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">\n' +
		`<link rel="icon" href="${e(assetUrl("favicon.svg"))}" type="image/svg+xml">\n` +
		`<link rel="apple-touch-icon" href="${e(assetUrl("apple-touch-icon.png"))}">\n` +
		`<link rel="stylesheet" href="${e(assetUrl("app.css"))}">\n`;

	if (settings.accent.toLowerCase() !== ACCENT_DEFAULT) {
		// `substr(md5($accent), 0, 8)`; the stylesheet itself is P2 (it draws the Control Panel).
		out += `<link rel="stylesheet" href="/theme.css?v=${accentVersion(settings.accent)}" id="accent-css">\n`;
	}

	return (
		out +
		`<script src="${e(assetUrl("theme.js"))}"></script>\n` +
		`<script src="${e(assetUrl("app.js"))}" defer></script>\n` +
		"</head>\n" +
		`<body class="${e(body)}">\n` +
		consentNotice(settings)
	);
};

/** `Page::end()`: no undo bar in phase 1, so just the end of the document. */
export const pageEnd = (): string => "\n</body>\n</html>\n";

/** `substr(md5($accent), 0, 8)`: the `/theme.css` cache key, byte for byte what PHP computes. */
const accentVersion = (accent: string): string => md5(accent).slice(0, 8);
