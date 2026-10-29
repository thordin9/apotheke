<?php

declare(strict_types=1);

namespace Apotheke\ControlPanel\Views;

use Apotheke\Autofill\AutofillService;
use Apotheke\Layout\Icons;
use Apotheke\Layout\Page;

/**
 * Control Panel → AI autofill parts (legacy settings_views.php):
 * autofill_choice_field_html() (Model and Image size from the service's
 * /v1/info), autofill_test_html(), autofill_test_result_html() and
 * settings_autofill_token_html(). The token is write-only: the page only
 * says whether one is set and never prints it.
 */
final class AutofillView
{
    /**
     * @param array<string,mixed>|null $info the /v1/info the form offers choices from (legacy autofill_form_info()):
     *        the one a Test just fetched, else the saved service's last known one
     */
    public function __construct(private readonly AutofillService $autofill, private readonly ?array $info)
    {
    }

    /**
     * Legacy autofill_choice_field_html(): a select when the service lists
     * models or image sizes, else a hidden input that keeps the stored value.
     *
     * @param array{key:string,label:string,type:string,default:mixed,help:string,options:array<string,mixed>,value:mixed} $f
     */
    public function choiceField(array $f, ?string $error): string
    {
        $h = static fn (?string $s): string => Page::e($s);
        ['key' => $key, 'label' => $label, 'type' => $type, 'help' => $help, 'value' => $value] = $f;
        $info = $this->info;
        $id = 's-' . $key;
        $opts = [];
        $models = is_array($info['models'] ?? null) ? $info['models'] : [];
        if ($type === 'af_model') {
            $value = ControlPanelView::str($value);
            if ($models !== []) {
                $def = $this->autofill->defaultModel($info);
                $opts[''] = 'Service default' . ($def !== null ? ' (' . $def . ')' : '');
                foreach ($models as $m) {
                    $opts[(string)$m['id']] = $m['id'] . (($m['loaded'] ?? null) === true ? ' · loaded' : '') . (($m['avg_latency_ms'] ?? null) !== null ? ' · about ' . self::seconds((int)$m['avg_latency_ms']) : '');
                }
                if ($value !== '' && !isset($opts[$value])) {
                    $opts[$value] = $value . ' (not offered by the service: it uses its default)';
                }
            }
        } else {
            $value = is_array($value) ? 1 : (int)(is_scalar($value) ? $value : 0);   // legacy (int)
            $sizes = is_array($info['image_sizes'] ?? null) ? $info['image_sizes'] : [];
            if ($sizes !== []) {
                $opts['0'] = 'Recommended (' . $this->autofill->recommendedSide($sizes) . ' px)';
                foreach ($sizes as $sz) {
                    $opts[(string)$sz['long_edge']] = $sz['long_edge'] . ' px' . ($sz['recommended'] ? ' (recommended)' : '');
                }
                if ($value > 0 && !isset($opts[(string)$value])) {
                    $opts[(string)$value] = $value . ' px (not offered by the service)';
                }
            }
        }
        if ($opts === []) {
            return '<input type="hidden" name="' . $key . '" id="' . $id . '" value="' . $h((string)$value) . '" data-af-hidden="1">';
        }
        // One model only (ALLOWED_MODELS unset on the service): shown, disabled; a hidden input keeps the stored value.
        $single = $type === 'af_model' && count($models) === 1;
        if ($single) {
            $only = (string)$models[0]['id'];
            $opts = [$only => $opts[$only]];
        }
        $sel = '';
        foreach ($opts as $k => $l) {
            $sel .= '<option value="' . $h((string)$k) . '"' . ($single || (string)$k === (string)$value ? ' selected' : '') . '>' . $h((string)$l) . '</option>';
        }
        $err = $error !== null ? '<p class="field-err" id="' . $id . '-err">' . $h($error) . '</p>' : '';
        $defText = $type === 'af_model' ? 'the service’s default model' : 'the size the service recommends';
        $one = $single ? '<small class="field-help af-one-model" id="' . $id . '-one">More models appear when ALLOWED_MODELS is set on the service.</small>' : '';

        return '<div class="field set-field' . ($error !== null ? ' has-err' : '') . '"><label class="field-l" for="' . $id . '">' . $h($label) . '</label>'
            . ($single ? '<input type="hidden" name="' . $key . '" value="' . $h((string)$value) . '">' : '')
            . '<select ' . ($single ? 'disabled data-af-single="1"' : 'name="' . $key . '"') . ' id="' . $id . '" aria-describedby="' . $id . '-d"' . ($error !== null ? ' aria-invalid="true"' : '') . '>' . $sel . '</select>'
            . '<div class="field-desc" id="' . $id . '-d">' . $one . '<small class="field-help">' . $h($help) . '</small><small class="field-def">Default: ' . $h($defText) . '</small></div>' . $err . '</div>';
    }

    /**
     * Legacy autofill_test_html(): Test connection, Test with a photo and the result.
     *
     * @param array<string,mixed>|null $r
     */
    public function testHtml(?array $r): string
    {
        $o = '<div class="af-test"><div class="af-test-btns">'
           . '<button class="btn btn-wide" id="af-test" formaction="/settings/autofill/test" formnovalidate>' . Icons::svg('sparkle') . ' Test connection</button>'
           . '<button class="btn btn-wide" id="af-test-photo" formaction="/settings/autofill/test-photo" formnovalidate>' . Icons::svg('image') . ' Test with a photo</button></div>'
           . '<p class="hint af-test-hint">Test with a photo sends a sample photo (a claw hammer) through the full suggestion step and shows what comes back. It can take as long as the timeout.</p>'
           . '<div id="af-test-result" class="af-test-result" role="status" aria-live="polite">';
        if ($r !== null) {
            $o .= $this->resultHtml($r);
        }

        return $o . '</div></div>';
    }

    /**
     * Legacy autofill_test_result_html().
     *
     * @param array<string,mixed> $r
     */
    public function resultHtml(array $r): string
    {
        $h = static fn (mixed $s): string => Page::e(ControlPanelView::str($s));
        $cls = $r['ok'] ? 'okbox' : ($r['code'] === 'warming_up' ? 'warnbox' : 'err');
        $o = '<div class="af-res ' . $cls . '" data-code="' . $h($r['code']) . '" id="af-res"><p>' . Icons::svg($r['ok'] ? 'check' : 'alert') . ' <span>' . $h($r['message']) . '</span></p>';
        if (!empty($r['info']) && is_array($r['info'])) {
            $i = $r['info'];
            $o .= '<dl class="af-info"><dt>Name</dt><dd id="af-name">' . $h($i['name']) . '</dd><dt>API version</dt><dd id="af-version">' . $h($i['version']) . '</dd>'
                . '<dt>Suggests</dt><dd id="af-caps">' . ($i['capabilities'] ? $h(implode(', ', $i['capabilities'])) : 'nothing yet') . '</dd>'
                . ($i['backend'] !== null ? '<dt>Backend</dt><dd>' . $h($i['backend']) . '</dd>' : '')
                . ($i['max_image_bytes'] !== null ? '<dt>Image limit</dt><dd>' . $h($this->autofill->limits()->mib((int)$i['max_image_bytes'])) . '</dd>' : '')
                . (($i['avg_latency_ms'] ?? null) !== null ? '<dt>Typical answer</dt><dd id="af-latency">' . $h(self::seconds((int)$i['avg_latency_ms'])) . '</dd>' : '')
                . (!empty($i['models']) ? '<dt>Models</dt><dd id="af-models">' . $h(implode(', ', array_map(static fn (array $m): string => $m['id'] . ($m['default'] ? ' (default)' : ''), $i['models']))) . '</dd>' : '')
                . (!empty($i['image_sizes']) ? '<dt>Image sizes</dt><dd id="af-sizes">' . $h(implode(', ', array_map(static fn (array $z): string => $z['long_edge'] . ' px' . ($z['recommended'] ? ' (recommended)' : ''), $i['image_sizes']))) . '</dd>' : '') . '</dl>';
        }
        if (isset($r['checks']) && is_array($r['checks'])) {
            // The state is in the words, not only the icon (screen readers): [ok, failed, not checked].
            $labels = ['reachable' => ['Reachable', 'Not reachable', 'Reachable: not checked'],
                       'token' => ['Token accepted', 'Token not accepted', 'Token: not checked'],
                       'ready' => ['Model ready', 'Model not ready', 'Model: not checked']];
            $o .= '<ul class="af-checks" id="af-checks" aria-label="Checks">';
            foreach ($labels as $k => [$yes, $no, $skip]) {
                $v = $r['checks'][$k] ?? null;
                $o .= '<li class="af-check ' . ($v === null ? 'skip' : ($v ? 'yes' : 'no')) . '" data-check="' . $k . '" data-state="' . ($v === null ? 'skipped' : ($v ? 'ok' : 'failed')) . '">'
                    . Icons::svg($v === null ? 'minus' : ($v ? 'check' : 'x')) . ' <span>' . $h($v === null ? $skip : ($v ? $yes : $no)) . '</span></li>';
            }
            $o .= '</ul>';
        }
        if (isset($r['suggestions']) && is_array($r['suggestions'])) {
            $s = $r['suggestions'];
            $c = is_array($r['confidence'] ?? null) ? $r['confidence'] : [];
            $pct = static fn (mixed $v): string => $v === null ? '' : ' <span class="af-conf">' . (int)round((float)$v * 100) . ' %</span>';
            $o .= '<div class="af-sample"><img src="/assets/autofill-sample.jpg" alt="The sample photo: a claw hammer" width="96" height="44" loading="lazy">'
                . '<dl class="af-info" id="af-suggestion"><dt>Name</dt><dd id="af-s-name">' . ($s['name'] !== null ? $h($s['name']) . $pct($c['name'] ?? null) : '–') . '</dd>'
                . '<dt>Category</dt><dd id="af-s-category">' . ($s['category'] !== null ? $h($s['category']) . $pct($c['category'] ?? null) : '–') . '</dd>'
                . '<dt>Tags</dt><dd id="af-s-tags">' . ($s['tags'] ? $h(implode(', ', $s['tags'])) : '–') . '</dd>'
                . '<dt>Description</dt><dd id="af-s-description">' . ($s['description'] !== null ? $h($s['description']) . $pct($c['description'] ?? null) : '–') . '</dd>'
                . (($r['model_used'] ?? null) !== null || ($r['model_sent'] ?? null) !== null
                    ? '<dt>Model used</dt><dd id="af-s-model">' . $h($r['model_used'] ?? 'not reported')
                      . (($r['model_sent'] ?? null) !== null && ($r['model_used'] ?? null) !== $r['model_sent'] ? ' <span class="af-conf">(asked for ' . $h($r['model_sent']) . ')</span>' : '') . '</dd>' : '')
                . (($r['image_side'] ?? null) !== null ? '<dt>Image size</dt><dd id="af-s-size">' . (int)$r['image_side'] . ' px</dd>' : '')
                . '<dt>Time</dt><dd id="af-s-elapsed">' . $h(self::seconds((int)($r['elapsed_ms'] ?? 0))) . '</dd></dl></div>';
        }

        return $o . '</div>';
    }

    /**
     * Legacy settings_autofill_token_html(): whether a token is set, and the
     * forms to replace or clear it. The token itself is never printed.
     *
     * @param string $tok the escaped CSRF token
     * @param ?string $secretWarning legacy secret_storage_warning_html()'s text, or null
     */
    public function tokenHtml(string $tok, ?string $error, bool $set, ?string $secretWarning): string
    {
        $o = '<section class="set-card" id="af-token"><h2 class="set-h">' . Icons::svg('lock') . ' Access token</h2>'
           . ($secretWarning !== null ? '<p class="set-warn" role="status" id="secret-key-warn">' . Page::e($secretWarning) . '</p>' : '')
           . '<p class="hint">Only needed when the service was started with <code>AUTOFILL_TOKEN</code>. It is stored on this server and sent as a bearer token; it is never shown again and not included in exports or backups. With <code>APP_KEY</code> set it is encrypted at rest.</p>'
           . '<p class="pw-state" id="af-token-state">' . ($set ? Icons::svg('check') . ' A token is <b>set</b>.' : 'No token set.') . '</p>'
           . ($error !== null ? '<p class="field-err" id="af-token-err" role="alert">' . Page::e($error) . '</p>' : '')
           . '<form method="post" action="/settings/autofill/token" class="setform" id="af-token-form" autocomplete="off"><input type="hidden" name="csrf" value="' . $tok . '">'
           . '<label class="field"><span>' . ($set ? 'Replace the token' : 'Token') . '</span><input type="password" name="token" id="af-token-input" maxlength="512" autocomplete="new-password" spellcheck="false" required' . ($error !== null ? ' aria-invalid="true" aria-describedby="af-token-err"' : '') . '></label>'
           . '<button class="btn btn-primary btn-wide" name="action" value="set" id="af-token-save">' . Icons::svg('check') . ' Save token</button></form>';
        if ($set) {
            $o .= '<form method="post" action="/settings/autofill/token" data-confirm="Remove the stored token?"><input type="hidden" name="csrf" value="' . $tok . '">'
                . '<button class="btn btn-danger btn-wide" name="action" value="clear" id="af-token-clear">' . Icons::svg('trash') . ' Clear token</button></form>';
        }

        return $o . '</section>';
    }

    /** Legacy autofill_seconds(). */
    private static function seconds(int $ms): string
    {
        return number_format($ms / 1000, 1, '.', '') . ' s';
    }
}
