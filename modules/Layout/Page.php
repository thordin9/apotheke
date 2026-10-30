<?php

declare(strict_types=1);

namespace Apotheke\Layout;

use Apotheke\Core\Libraries\AssetUrl;
use Apotheke\Settings\Branding\Branding;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use Closure;

/**
 * The shared page shell (legacy views.php): page_start(), page_end(), the
 * menu, the simple header, the brand logo and the toast and error boxes.
 * Output is byte-identical to legacy; every value is escaped here. The page
 * has no inline script or style, so the CSP stays legacy's. Built per request
 * by Config\Services; holds no state of its own.
 */
final class Page
{
    /**
     * @param Closure(bool): string $csrf legacy csrf_token($create): may start the session, before any output
     * @param Closure(): bool $signInEnabled legacy auth_enabled()
     * @param mixed $themeCookie the request's `theme` cookie
     * @param string $requestUri the request's path and query, for the menu's way back
     * @param (Closure(): ?array{label:string,left:int,token:string})|null $undo the Undo bar for this request's ?undo= token (the Undo module's), or null
     * @param bool $consent the request carries cookie consent (Core\Http\CookieConsent); false shows the consent notice
     */
    public function __construct(
        private readonly SettingsService $settings,
        private readonly Branding $branding,
        private readonly AssetUrl $assets,
        private readonly Viewer $viewer,
        private readonly Closure $csrf,
        private readonly Closure $signInEnabled,
        private readonly mixed $themeCookie,
        private readonly string $requestUri,
        private readonly ?Closure $undo = null,
        private readonly bool $consent = true,
    ) {
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Legacy csrf_token(): the session's form token (starts the session; call before output is sent). */
    public function csrf(): string
    {
        return ($this->csrf)(true);
    }

    public function theme(): string
    {
        return Theme::pref($this->themeCookie, (string)$this->settings->get('theme_default'));
    }

    /**
     * Legacy page_start(): the document up to and including <body>. $session
     * false: never start a session for this page (error pages); an existing
     * session's token is still used.
     */
    public function start(string $title, string $bodyClass = '', bool $session = true): string
    {
        $name = (string)$this->settings->get('app_name');
        $tagline = (string)$this->settings->get('app_tagline');
        $t = $title === '' ? $name . ' – ' . $tagline : $title . ' · ' . $name;
        $theme = $this->theme();
        $csrf = ($this->csrf)($session);
        $accent = (string)$this->settings->get('accent');
        $o = "<!doctype html>\n<html lang=\"en\" data-theme=\"" . self::e($theme) . "\">\n<head>\n<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1, viewport-fit=cover\">\n"
            . '<title>' . self::e($t) . "</title>\n";
        if ($theme === 'auto') {
            $o .= "<meta name=\"color-scheme\" content=\"light dark\">\n"
                . "<meta name=\"theme-color\" content=\"#f5f5f7\" media=\"(prefers-color-scheme: light)\">\n"
                . "<meta name=\"theme-color\" content=\"#111113\" media=\"(prefers-color-scheme: dark)\">\n";
        } else {
            $o .= '<meta name="color-scheme" content="' . self::e($theme) . "\">\n"
                . '<meta name="theme-color" content="' . ($theme === 'dark' ? '#111113' : '#f5f5f7') . "\">\n";
        }
        $o .= "<meta name=\"apple-mobile-web-app-capable\" content=\"yes\">\n"
            . "<meta name=\"mobile-web-app-capable\" content=\"yes\">\n"
            . '<meta name="apple-mobile-web-app-title" content="' . self::e($name) . "\">\n"
            . "<meta name=\"apple-mobile-web-app-status-bar-style\" content=\"default\">\n"
            . "<meta name=\"format-detection\" content=\"telephone=no\">\n"
            . '<meta name="description" content="' . self::e($name . ', ' . $tagline) . "\">\n"
            . '<meta name="csrf" content="' . self::e($csrf) . "\">\n"
            . "<link rel=\"manifest\" href=\"/manifest.webmanifest\">\n"
            . "<link rel=\"icon\" href=\"/favicon.ico\" sizes=\"16x16 32x32 48x48\">\n"
            . '<link rel="icon" href="' . self::e($this->branding->faviconSvgUrl()) . "\" type=\"image/svg+xml\">\n"
            . '<link rel="apple-touch-icon" href="' . self::e($this->branding->iconUrl('180')) . "\">\n"
            . '<link rel="stylesheet" href="' . self::e($this->assets->url('app.css')) . "\">\n";
        if (strcasecmp($accent, SettingsSchema::ACCENT_DEFAULT) !== 0) {
            $o .= '<link rel="stylesheet" href="/theme.css?v=' . substr(md5($accent), 0, 8) . "\" id=\"accent-css\">\n";
        }

        return $o . '<script src="' . self::e($this->assets->url('theme.js')) . "\"></script>\n"
            . '<script src="' . self::e($this->assets->url('app.js')) . "\" defer></script>\n"
            . "</head>\n"
            . '<body class="' . self::e(trim($bodyClass . ' ' . $this->viewer->bodyClass())) . "\">\n"
            . $this->consentNotice();
    }

    /**
     * The cookie-consent notice, on every page until the request carries consent. Accept (theme.js) sets the
     * consent cookie and reloads; no cookie is set before that. Without JavaScript the notice says so.
     */
    public function consentNotice(): string
    {
        if ($this->consent) {
            return '';
        }

        return '<div class="consent" id="consent" role="region" aria-label="Cookies"><p>This app uses cookies only to keep you signed in and to protect '
            . 'your changes. Nothing is stored in your browser until you accept.</p>'
            . '<button type="button" class="btn btn-primary" id="consent-accept" data-consent="' . self::e(\Config\Consent::CURRENT) . '">Accept cookies</button>'
            . "<noscript><p>Turn on JavaScript to accept cookies.</p></noscript></div>\n";
    }

    /** Legacy page_end(): the Undo bar, then the end of the document. */
    public function end(): string
    {
        return $this->undoBar() . "\n</body>\n</html>\n";
    }

    /** Legacy undo_bar_html(): "… · Undo" for a live ?undo= token, else ''. */
    public function undoBar(): string
    {
        $t = $this->undo !== null ? ($this->undo)() : null;
        if ($t === null) {
            return '';
        }

        return '<div class="undo-bar" id="undo-bar" role="status" data-left="' . $t['left'] . '">'
            . '<span class="undo-label">' . self::e($t['label']) . '</span>'
            . '<form method="post" action="/undo"><input type="hidden" name="csrf" value="' . self::e($this->csrf()) . '"><input type="hidden" name="token" value="' . self::e($t['token']) . '">'
            . '<button class="btn undo-btn" id="undo-btn">' . Icons::svg('undo') . ' Undo</button></form>'
            . '<button type="button" class="btn btn-ghost undo-x" id="undo-x" aria-label="Dismiss">' . Icons::svg('x') . '</button></div>';
    }

    /** Legacy brand_logo(). */
    public function brandLogo(int $size): string
    {
        return '<img class="brand-logo" src="' . self::e($this->branding->logoUrl()) . '" width="' . $size . '" height="' . $size . '" alt="" decoding="async">';
    }

    /** Legacy settings_header(): back button, title and the menu. */
    public function simpleHeader(string $title, string $back): string
    {
        return '<header class="top top-simple"><div class="bar">'
            . '<a class="btn btn-ghost btn-round" href="' . self::e($back) . '" aria-label="Back">' . Icons::svg('back') . '</a>'
            . '<h1 class="title-sm">' . self::e($title) . '</h1>' . $this->menu() . '</div></header>';
    }

    /** The status toast legacy pages print after an action ('' for none). */
    public function toast(?string $message): string
    {
        return $message !== null && $message !== '' ? '<div class="toast" role="status">' . self::e($message) . '</div>' : '';
    }

    /** The error box legacy forms print ('' for none). */
    public function errorBox(?string $message, string $id = ''): string
    {
        return $message !== null && $message !== ''
            ? '<div class="err" role="alert"' . ($id !== '' ? ' id="' . self::e($id) . '"' : '') . '>' . self::e($message) . '</div>' : '';
    }

    /** Legacy menu_html(): appearance, the links the role may use, the account and sign-in or sign-out. */
    public function menu(): string
    {
        $tok = self::e(($this->csrf)(true));
        $cur = $this->theme();
        $back = self::e(Theme::safeBack($this->requestUri));
        $me = $this->viewer->user();
        $can = fn (string $p): bool => $this->viewer->allows($me, $p);
        $o = '<details class="menu" id="menu"><summary class="btn btn-ghost btn-round" id="menu-btn" aria-label="Menu" title="Menu">' . Icons::svg('menu') . '</summary>'
            . '<div class="menu-panel" role="menu">'
            . '<form method="post" action="/theme" class="theme-form" id="theme-form"><input type="hidden" name="csrf" value="' . $tok . '"><input type="hidden" name="back" value="' . $back . '">'
            . '<p class="menu-label" id="theme-label">Appearance</p><div class="seg seg-lg" role="radiogroup" aria-labelledby="theme-label">';
        foreach (['auto' => ['Auto', 'auto'], 'light' => ['Light', 'sun'], 'dark' => ['Dark', 'moon']] as $k => [$label, $ic]) {
            $o .= '<button name="theme" value="' . $k . '" role="radio" aria-checked="' . ($k === $cur ? 'true' : 'false') . '" class="seg-btn' . ($k === $cur ? ' on' : '') . '">' . Icons::svg($ic) . '<span>' . $label . '</span></button>';
        }
        $o .= '</div></form>'
            . '<a class="menu-item" href="/locations" role="menuitem">' . Icons::svg('pin') . ' Locations</a>'
            . '<a class="menu-item" href="/categories" role="menuitem">' . Icons::svg('tag') . ' Categories</a>'
            . '<a class="menu-item" href="/barcode" role="menuitem">' . Icons::svg('search') . ' Find by barcode</a>'
            . ($can('labels') ? '<a class="menu-item" href="/labels" role="menuitem" id="menu-labels">' . Icons::svg('qr') . ' Print QR labels</a>' : '')
            . ($can('export') ? '<a class="menu-item" href="/export.csv" role="menuitem" id="menu-export">' . Icons::svg('download') . ' Export CSV</a>' : '')
            . ($can('import') ? '<a class="menu-item" href="/import" role="menuitem" id="menu-import">' . Icons::svg('archive') . ' Import CSV</a>' : '')
            . '<a class="menu-item" href="/lent" role="menuitem">' . Icons::svg('handoff') . ' Lent out</a>'
            . ($can('stocktake') ? '<a class="menu-item" href="/stocktake" role="menuitem" id="menu-stocktake">' . Icons::svg('check') . ' Stocktake</a>' : '')
            . '<a class="menu-item" href="/activity" role="menuitem">' . Icons::svg('clock') . ' Recent activity</a>'
            . ($can('backup') ? '<a class="menu-item" href="/backup" role="menuitem" id="menu-backup">' . Icons::svg('archive') . ' Backup &amp; restore</a>' : '')
            . ($can('settings') ? '<a class="menu-item" href="/settings" role="menuitem" id="menu-settings">' . Icons::svg('sliders') . ' Settings</a>'
                : ($can('users') ? '<a class="menu-item" href="/settings/security" role="menuitem" id="menu-settings">' . Icons::svg('sliders') . ' Users &amp; sign-in</a>' : ''));
        if ($me !== null && !$me['guest']) {
            $o .= '<a class="menu-item" href="/account" role="menuitem" id="menu-account">' . Icons::svg('lock') . ' <span>' . self::e($me['username']) . ' <small class="menu-role">' . self::e($this->viewer->roleName($me['role'])) . '</small></span></a>';
        }
        if ($me !== null && $me['anon']) {
            $o .= '<a class="menu-item" href="/login" role="menuitem" id="menu-login">' . Icons::svg('lock') . ' Sign in</a>';
        } elseif (($this->signInEnabled)()) {
            $o .= '<form method="post" action="/logout"><input type="hidden" name="csrf" value="' . $tok . '"><button class="menu-item" role="menuitem">' . Icons::svg('logout') . ' Sign out</button></form>';
        }

        return $o . '</div></details>';
    }
}
