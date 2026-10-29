<?php

declare(strict_types=1);

namespace Apotheke\ControlPanel\Views;

use Apotheke\Layout\Icons;
use Apotheke\Layout\Page;
use Apotheke\Settings\Branding\Accent;
use Apotheke\Settings\SettingsSchema;
use DateTimeZone;

/**
 * Control Panel pages (legacy settings_views.php): the index
 * (view_settings_home()) and a section page (view_settings_section() with
 * setting_field_html(), accent_preview_html(), settings_appearance_extra()
 * and settings_system_html()). Kept line for line so the output is
 * byte-identical to legacy; every value is escaped with Page::e(). The AI
 * autofill parts are AutofillView's. Users & sign-in is the Auth module's
 * SecurityView.
 */
final class ControlPanelView
{
    /** @param string $publicBase legacy public_url(''): the setting, or the address in use */
    public function __construct(private readonly Page $page, private readonly string $publicBase)
    {
    }

    /** Legacy view_settings_home(). */
    public function home(?string $toast): string
    {
        $head = $this->page->start('Settings', 'page-settings') . $this->page->simpleHeader('Settings', '/');
        ob_start();
        ?>
<main class="wrap narrow">
  <p class="hint">Everything here is stored in the database and applies right away. Database and storage paths come from
    <code>config.php</code> or environment variables and are shown read-only under <a href="/settings/system">System</a>.</p>
  <nav class="setlist" id="setlist" aria-label="Settings sections">
    <?php foreach (SettingsSchema::SECTIONS as $k => [$label, $desc, $ic]): ?>
    <a class="setlink" href="/settings/<?= $k ?>" id="sl-<?= $k ?>"><?= Icons::svg($ic) ?><span><b><?= self::h($label) ?></b><small><?= self::h($desc) ?></small></span></a>
    <?php endforeach; ?>
  </nav>
</main>
<?php if ($toast !== null): ?><div class="toast" role="status"><?= self::h($toast) ?></div><?php endif; ?>
<?php
        return $head . ob_get_clean() . $this->page->end();
    }

    /**
     * Legacy view_settings_section() for every section but security.
     *
     * @param list<array{key:string,label:string,type:string,default:mixed,help:string,options:array<string,mixed>,value:mixed}> $fields
     * @param array<string,string> $errors by key ('_' = a general message)
     * @param list<string> $notes warnings shown above the form
     * @param array{
     *   logo?:string, logoUrl?:string, system?:list<array{0:string,1:string,2:string}>, version?:string, engine?:string,
     *   engineLabel?:string, schema?:string, migrations?:int, autofill?:AutofillView, afTest?:array<string,mixed>|null, tokenSet?:bool,
     *   secretWarning?:?string, itemDefaults?:string
     * } $extra the section's own parts
     */
    public function section(string $section, array $fields, array $errors, ?string $toast, array $notes = [], array $extra = []): string
    {
        [$label, $desc] = SettingsSchema::SECTIONS[$section];
        $head = $this->page->start($label . ' · Settings', 'page-settings') . $this->page->simpleHeader($label, '/settings');
        $tok = self::h($this->page->csrf());
        $af = $section === 'autofill' ? ($extra['autofill'] ?? null) : null;
        ob_start();
        ?>
<main class="wrap narrow set-<?= $section ?>">
  <p class="hint"><?= self::h($desc) ?></p>
  <?php if ($errors): ?><div class="err" role="alert" id="set-errors">Please check the highlighted <?= count($errors) === 1 ? 'field' : 'fields' ?>.<?= isset($errors['_']) ? ' ' . self::h($errors['_']) : '' ?></div><?php endif; ?>
  <?php foreach ($notes as $n): ?><p class="warnbox" role="status"><?= Icons::svg('alert') ?> <span><?= self::h($n) ?></span></p><?php endforeach; ?>

  <?php if ($section === 'appearance') echo $this->appearanceExtra($tok, (string)($extra['logo'] ?? ''), (string)($extra['logoUrl'] ?? '')); ?>
  <?php /* security: the Auth module's page */ ?>
  <?php if ($section === 'data') echo $this->data($tok); ?>
  <?php if ($section === 'system') echo $this->system($extra); ?>

  <?php if ($fields): ?>
  <form method="post" action="/settings/<?= $section ?>" class="setform" id="setform" novalidate>
    <input type="hidden" name="csrf" value="<?= $tok ?>">
    <?php foreach ($fields as $f): ?>
      <?= $af !== null && ($f['type'] === 'af_model' || $f['type'] === 'af_size') ? $af->choiceField($f, $errors[$f['key']] ?? null) : $this->field($f, $errors[$f['key']] ?? null) ?>
      <?php if ($f['key'] === 'accent') echo $this->accentPreview(self::str($f['value'])); ?>
    <?php endforeach; ?>
    <?php if ($section === 'items') echo $extra['itemDefaults'] ?? ''; ?>
    <div class="set-actions"><button class="btn btn-primary btn-wide" id="set-save"><?= Icons::svg('check') ?> Save</button></div>
    <?php if ($af !== null) echo $af->testHtml($extra['afTest'] ?? null); ?>
  </form>
  <?php if ($af !== null) echo $af->tokenHtml($tok, $errors['autofill_token'] ?? null, (bool)($extra['tokenSet'] ?? false), $extra['secretWarning'] ?? null); ?>
  <form method="post" action="/settings/<?= $section ?>/reset" class="set-reset" data-confirm="Reset “<?= self::h($label) ?>” to the defaults from config.php?">
    <input type="hidden" name="csrf" value="<?= $tok ?>">
    <button class="btn btn-wide" id="set-reset"><?= Icons::svg('undo') ?> Reset this section to defaults</button>
  </form>
  <?php endif; ?>
</main>
<?php if ($toast !== null): ?><div class="toast" role="status"><?= self::h($toast) ?></div><?php endif; ?>
<?php
        return $head . ob_get_clean() . $this->page->end();
    }

    /**
     * Legacy setting_field_html() for every type but the autofill choices.
     *
     * @param array{key:string,label:string,type:string,default:mixed,help:string,options:array<string,mixed>,value:mixed} $f
     */
    public function field(array $f, ?string $error): string
    {
        $h = static fn (?string $s): string => self::h($s);
        ['key' => $key, 'label' => $label, 'type' => $type, 'default' => $def, 'help' => $help, 'options' => $o, 'value' => $value] = $f;
        $id = 's-' . $key;
        $err = $error !== null ? '<p class="field-err" id="' . $id . '-err">' . $h($error) . '</p>' : '';
        $desc = ($help !== '' ? '<small class="field-help">' . $h($help) . '</small>' : '');
        $options = is_array($o['options'] ?? null) ? $o['options'] : [];
        $defText = match (true) {
            $type === 'bool' => $def ? 'on' : 'off',
            $type === 'enum' => self::str($options[self::str($def)] ?? $def),
            $def === null || $def === '' => 'empty',
            default => self::str($def),
        };
        $desc .= '<small class="field-def">Default: ' . $h($defText) . '</small>';
        $aria = ' aria-describedby="' . $id . '-d"' . ($error !== null ? ' aria-invalid="true"' : '');
        $wrapD = '<div class="field-desc" id="' . $id . '-d">' . $desc . '</div>';
        $cls = 'field set-field' . ($error !== null ? ' has-err' : '');
        switch ($type) {
            case 'bool':
                return '<div class="' . $cls . ' set-bool"><label class="switch" for="' . $id . '"><input type="hidden" name="' . $key . '" value="0">'
                    . '<input type="checkbox" role="switch" name="' . $key . '" id="' . $id . '" value="1"' . ($value ? ' checked' : '') . $aria . '>'
                    . '<span class="switch-ui" aria-hidden="true"></span><span class="switch-label">' . $h($label) . '</span></label>' . $wrapD . $err . '</div>';
            case 'enum':
            case 'timezone':
                $opts = $type === 'enum' ? $options : array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers());
                $sel = '';
                foreach ($opts as $k => $l) {
                    $sel .= '<option value="' . $h((string)$k) . '"' . ((string)$k === self::str($value) ? ' selected' : '') . '>' . $h(self::str($l)) . '</option>';
                }

                return '<div class="' . $cls . '"><label class="field-l" for="' . $id . '">' . $h($label) . '</label><select name="' . $key . '" id="' . $id . '"' . $aria . '>' . $sel . '</select>' . $wrapD . $err . '</div>';
            case 'int':
                return '<div class="' . $cls . '"><label class="field-l" for="' . $id . '">' . $h($label) . '</label><input type="number" name="' . $key . '" id="' . $id . '" value="' . $h($value === null ? '' : self::str($value))
                    . '" min="' . self::str($o['min'] ?? '') . '" max="' . self::str($o['max'] ?? '') . '" step="1" inputmode="numeric"' . (!empty($o['nullable']) ? ' placeholder="empty"' : ' required') . $aria . '>' . $wrapD . $err . '</div>';
            case 'color':
                return '<div class="' . $cls . '"><label class="field-l" for="' . $id . '">' . $h($label) . '</label><div class="colorrow"><input type="color" id="' . $id . '-pick" value="' . $h(strtolower(self::str($value))) . '" aria-label="' . $h($label) . ' picker" data-color-for="#' . $id . '">'
                    . '<input type="text" name="' . $key . '" id="' . $id . '" value="' . $h(self::str($value)) . '" maxlength="7" pattern="#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?" autocomplete="off" spellcheck="false"' . $aria . '></div>' . $wrapD . $err . '</div>';
            case 'url':
                return '<div class="' . $cls . '"><label class="field-l" for="' . $id . '">' . $h($label) . '</label><input type="url" name="' . $key . '" id="' . $id . '" value="' . $h(self::str($value)) . '" maxlength="200" placeholder="' . $h(self::str($o['placeholder'] ?? $this->publicBase)) . '" autocomplete="off"' . $aria . '>' . $wrapD . $err . '</div>';
            case 'af_bytes':
                return '<div class="' . $cls . '"><label class="field-l" for="' . $id . '">' . $h($label) . '</label><input type="text" name="' . $key . '" id="' . $id . '" value="' . $h(self::str($value)) . '" maxlength="10" spellcheck="false" autocomplete="off" placeholder="auto"' . $aria . '>' . $wrapD . $err . '</div>';
            default:
                return '<div class="' . $cls . '"><label class="field-l" for="' . $id . '">' . $h($label) . '</label><input type="text" name="' . $key . '" id="' . $id . '" value="' . $h(self::str($value)) . '" maxlength="' . (int)($o['max'] ?? 200) . '"' . (!empty($o['required']) ? ' required' : '') . ' autocomplete="off"' . $aria . '>' . $wrapD . $err . '</div>';
        }
    }

    /** Legacy accent_preview_html(). */
    public function accentPreview(string $hex): string
    {
        $v = Accent::variants($hex);
        $presets = '';
        $i = 0;
        foreach (Accent::PRESETS as $c => $name) {
            $presets .= '<button type="button" class="preset sw-' . $i++ . (strcasecmp($c, $hex) === 0 ? ' on' : '') . '" data-accent="' . self::h($c) . '" aria-label="' . self::h($name) . '" title="' . self::h($name) . '"></button>';
        }
        $pane = static fn (string $m, string $title): string => '<div class="pv pv-' . $m . '" id="pv-' . $m . '"><b class="pv-title">' . $title . '</b>'
            . '<span class="pv-btn">Save</span> <span class="pv-link">Link text</span> <span class="pv-chip">Chip</span>'
            . '<span class="pv-ratio" data-mode="' . $m . '">' . number_format((float)$v[$m]['contrast'], 2) . ':1 ' . ($v[$m]['contrast'] >= 4.5 ? '✓ AA' : '✗') . '</span></div>';
        $w = '';
        foreach (Accent::warnings($hex) as $x) {
            $w .= '<li>' . self::h($x) . '</li>';
        }

        return '<div class="presets" role="group" aria-label="Preset colours">' . $presets . '</div>'
            . '<div class="accent-preview" id="accent-preview" aria-live="polite">' . $pane('light', 'Light') . $pane('dark', 'Dark') . '</div>'
            . '<ul class="accent-warn" id="accent-warn"' . ($w === '' ? ' hidden' : '') . '>' . $w . '</ul>';
    }

    /** Legacy settings_appearance_extra(): the logo card. */
    private function appearanceExtra(string $tok, string $custom, string $logoUrl): string
    {
        $o = '<section class="set-card" id="logo-card"><h2 class="set-h">Logo</h2>'
            . '<div class="logo-now"><img src="' . self::h($logoUrl) . '" width="64" height="64" alt="Current logo" id="logo-current"><span>'
            . ($custom === '' ? 'The default Apotheke logo.' : ($custom === 'svg' ? 'Your SVG logo (also used as the favicon).' : 'Your logo; favicon and app icons were generated from it.')) . '</span></div>'
            . '<form method="post" action="/settings/logo" enctype="multipart/form-data" class="logo-form" id="logo-form">'
            . '<input type="hidden" name="csrf" value="' . $tok . '">'
            . '<label class="field"><span>Upload a logo <em>SVG, PNG, JPEG or WebP · max 2 MB · square works best</em></span>'
            . '<input type="file" name="logo" id="logo-file" accept=".svg,image/svg+xml,image/png,image/jpeg,image/webp" required></label>'
            . '<button class="btn btn-primary btn-wide" id="logo-upload">' . Icons::svg('image') . ' Upload logo</button></form>';
        if ($custom !== '') {
            $o .= '<form method="post" action="/settings/logo/reset" data-confirm="Go back to the default Apotheke logo?"><input type="hidden" name="csrf" value="' . $tok . '">'
                . '<button class="btn btn-wide" id="logo-reset">' . Icons::svg('undo') . ' Use the default Apotheke logo</button></form>';
        }

        return $o . '<p class="hint">SVG files are cleaned (scripts, links and external references are removed). For PNG, JPEG and WebP the favicon, the iPhone home-screen icon and the app icons are generated automatically; an SVG logo is used for the header and the favicon while the PNG app icons stay the default ones.</p></section>';
    }

    /**
     * Legacy settings_items_defaults_html(): the default location and category.
     *
     * @param list<array{kind:string,One:string,Many:string,current:?int,options:list<array{0:int,1:string}>}> $kinds
     */
    public function itemDefaults(array $kinds): string
    {
        $o = '';
        foreach ($kinds as $k) {
            $opts = '<option value="">None</option>';
            foreach ($k['options'] as [$id, $text]) {
                $opts .= '<option value="' . $id . '"' . ($id === $k['current'] ? ' selected' : '') . '>' . self::h($text) . '</option>';
            }
            $o .= '<div class="field set-field"><label class="field-l" for="s-default_' . $k['kind'] . '">' . self::h($k['One']) . '</label><select name="default_' . $k['kind'] . '" id="s-default_' . $k['kind'] . '">' . $opts . '</select>'
                . '<div class="field-desc"><small class="field-help">Pre-selected for new items (same as ★ on the ' . self::h($k['Many']) . ' page).</small></div></div>';
        }

        return $o;
    }

    /** Legacy settings_data_html(). */
    private function data(string $tok): string
    {
        return '<section class="set-card"><h2 class="set-h">Your catalogue</h2><div class="set-links">'
            . '<a class="btn btn-wide" href="/backup" id="d-backup">' . Icons::svg('archive') . ' Backup &amp; restore</a>'
            . '<a class="btn btn-wide" href="/export.csv" id="d-csv">' . Icons::svg('download') . ' Export CSV</a>'
            . '<a class="btn btn-wide" href="/import" id="d-import">' . Icons::svg('archive') . ' Import CSV…</a></div></section>'
            . '<section class="set-card"><h2 class="set-h">Settings file</h2>'
            . '<p class="hint">Download every setting as JSON (the password and the logo are not included), or load such a file. Invalid values in the file are skipped and listed.</p>'
            . '<a class="btn btn-wide" href="/settings/export.json" id="d-export" download>' . Icons::svg('download') . ' Export settings (JSON)</a>'
            . '<form method="post" action="/settings/import" enctype="multipart/form-data" class="setform" id="import-form"><input type="hidden" name="csrf" value="' . $tok . '">'
            . '<label class="field"><span>Import settings</span><input type="file" name="file" id="import-file" accept="application/json,.json" required></label>'
            . '<button class="btn btn-primary btn-wide" id="import-go">' . Icons::svg('undo') . ' Import settings</button></form></section>';
    }

    /**
     * Legacy settings_system_html().
     *
     * @param array<string,mixed> $extra see section()
     */
    private function system(array $extra): string
    {
        $rows = '';
        /** @var list<array{0:string,1:string,2:string}> $info */
        $info = $extra['system'] ?? [];
        foreach ($info as [$l, $v, $src]) {
            $rows .= '<tr><th scope="row">' . self::h($l) . '</th><td><code>' . self::h($v) . '</code><small>' . self::h($src) . '</small></td></tr>';
        }

        return '<section class="set-card"><p class="hint">These values are needed before the database can be opened, so they cannot be stored in it. '
            . 'Change them in <code>config.php</code> or with environment variables (environment wins) and restart the app. '
            . 'See <code>docs/configuration.md</code>.</p>'
            . '<table class="systable" id="systable"><tbody>'
            . '<tr><th scope="row">Version</th><td><code id="app-version">' . self::h(self::str($extra['version'] ?? '')) . '</code><small>VERSION · <a href="https://github.com/thordin9/apotheke/releases">release notes</a></small></td></tr>' . $rows
            . '<tr><th scope="row">Search engine</th><td><code id="search-engine">' . self::h(self::str($extra['engine'] ?? '')) . '</code><small>' . self::h(self::str($extra['engineLabel'] ?? '')) . '</small></td></tr>'
            . '<tr><th scope="row">Database schema</th><td><code>' . self::h(self::str($extra['schema'] ?? '')) . '</code><small>' . (int)($extra['migrations'] ?? 0) . ' migrations applied</small></td></tr>'
            . '</tbody></table>'
            . '<p class="hint">All other options (everything in the other sections) were copied from <code>config.php</code> / the environment when this database was created or upgraded, and are edited here from then on.</p></section>';
    }

    /** Legacy (string) of a value: an array reads as "Array". */
    public static function str(mixed $v): string
    {
        return is_array($v) ? 'Array' : (is_scalar($v) ? (string)$v : '');
    }

    private static function h(?string $s): string
    {
        return Page::e($s);
    }
}
