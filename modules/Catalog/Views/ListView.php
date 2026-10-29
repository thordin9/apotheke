<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Views;

use Apotheke\Catalog\Calendar;
use Apotheke\Catalog\ListFilters;
use Apotheke\Catalog\Stats;
use Apotheke\Layout\Icons;
use Apotheke\Layout\Page;
use Apotheke\Media\ImageStore;
use Apotheke\Taxonomy\Kind;
use Apotheke\Taxonomy\LocationTree;

/**
 * The list page, its cards and chips, the overview and the saved filters page
 * (legacy src/views.php: view_list(), card_html(), active_chips_html(),
 * stats_body_html(), view_saved_filters() and their helpers), byte-identical
 * to legacy. Every value is escaped here.
 */
final class ListView
{
    /**
     * @param array{default_sort:string,list_view:string,currency:string,currency_position:string,money_format:string,app_name:string} $settings
     * @param string $version the app version for the footer
     */
    public function __construct(
        private readonly Page $page,
        private readonly Calendar $calendar,
        private readonly array $settings,
        private readonly string $version,
        private readonly bool $signInEnabled,
    ) {
    }

    private static function e(?string $s): string
    {
        return Page::e($s);
    }

    private static function icon(string $name): string
    {
        return Icons::svg($name);
    }

    public static function itemNoun(int $n): string
    {
        return $n . ' item' . ($n === 1 ? '' : 's');
    }

    public static function statsBrief(int $items, int $qty): string
    {
        return self::itemNoun($items) . ' · ' . $qty . ' pc' . ($qty === 1 ? '' : 's');
    }

    public static function countText(int $total, bool $filtered): string
    {
        $s = self::itemNoun($total);

        return $filtered ? $s . ' found' : $s;
    }

    /** @param array<string,string> $fixed "· showing “drill” for “drlil”" when typo tolerance stepped in */
    public static function fixedNote(array $fixed): string
    {
        if ($fixed === []) {
            return '';
        }

        return ' · showing ' . implode(', ', array_map(static fn (string $from, string $to): string => '“' . $to . '” for “' . $from . '”', array_keys($fixed), $fixed));
    }

    public static function bulkToast(int $n, string $action): string
    {
        $s = self::itemNoun($n);

        return match ($action) {
            'move' => "Moved $s", 'category' => "Set the category of $s", 'tag_add' => "Tagged $s",
            'tag_remove' => "Removed the tag from $s", 'delete' => "Deleted $s", default => "Updated $s",
        };
    }

    public function money(?int $cents): string
    {
        if ($cents === null) {
            return '';
        }
        [$d, $t] = match ($this->settings['money_format']) {
            'de' => [',', '.'], 'fr' => [',', "\u{202F}"], 'ch' => ['.', '’'], 'plain' => ['.', ''], default => ['.', ','],
        };
        $n = number_format($cents / 100, 2, $d, $t);
        $c = $this->settings['currency'];

        return $this->settings['currency_position'] === 'before' ? $c . ' ' . $n : $n . ' ' . $c;
    }

    public function chip(string $kind, ?int $id, string $name): string
    {
        $t = Kind::get($kind);
        if ($id === null) {
            return '';
        }

        return '<a class="chip chip-' . $kind . '" href="/?' . $t['param'] . '=' . $id . '" data-' . $t['param'] . '="' . $id . '">' . self::e($name) . '</a>';
    }

    /** @param array<string,mixed> $it */
    public function card(array $it, bool $select = false, bool $checked = false): string
    {
        $id = (int)$it['id'];
        $img = ImageStore::validName(is_string($it['image'] ?? null) ? $it['image'] : null)
            ? '<img src="/media/thumb/' . self::e((string)$it['image']) . '" alt="" loading="lazy" decoding="async">'
            : '<span class="ph">' . self::icon('box') . '</span>';
        $locId = $it['location_id'] !== null ? (int)$it['location_id'] : null;
        $catId = $it['category_id'] !== null ? (int)$it['category_id'] : null;
        $chips = $this->chip('location', $locId, (string)$it['location']) . $this->chip('category', $catId, (string)$it['category']);
        if ($chips === '') {
            $chips = '<span class="chip chip-muted">No location</span>';
        }
        $q = (int)$it['quantity'];
        $min = ($it['min_qty'] ?? null) === null ? null : (int)$it['min_qty'];
        $name = (string)$it['name'];

        return '<article class="card' . ($min !== null && $q <= $min ? ' is-low' : '') . ($select ? ' selectable' : '') . '" data-id="' . $id . '"' . ($min !== null ? ' data-min="' . $min . '"' : '') . '>'
            . ($select ? '<label class="sel"><input type="checkbox" class="selbox" name="ids[]" value="' . $id . '" form="bulkform"' . ($checked ? ' checked' : '') . ' aria-label="Select ' . self::e($name) . '"><span class="selmark" aria-hidden="true">' . self::icon('check') . '</span></label>' : '')
            . '<a class="card-img" href="/item/' . $id . '" aria-label="' . self::e($name) . '">' . $img . '</a>'
            . ($select ? '' : $this->favButton($id, $name, !empty($it['favourite']), '/'))
            . '<div class="card-body">'
            . '<a class="card-title" href="/item/' . $id . '">' . (isset($it['hl']) ? (string)$it['hl'] : self::e($name)) . '</a>'
            . (!empty($it['snip']) ? '<p class="snip">' . (string)$it['snip'] . '</p>' : '')
            . '<div class="chips">' . $chips . '</div>'
            . ($min !== null ? '<span class="lowbadge" role="status">' . self::icon('alert') . ' Running low</span>' : '')
            . $this->lendBadge($it)
            . $this->expiryBadge($it)
            . '<div class="qty" data-id="' . $id . '">'
            . '<button type="button" class="qbtn" data-delta="-1" aria-label="Decrease"' . ($q <= 0 ? ' disabled' : '') . '>' . self::icon('minus') . '</button>'
            . '<output class="qval">' . $q . '</output>'
            . '<button type="button" class="qbtn" data-delta="1" aria-label="Increase">' . self::icon('plus') . '</button>'
            . '</div></div></article>';
    }

    /** @param list<array<string,mixed>> $items */
    public function cards(array $items, bool $select = false, bool $checked = false): string
    {
        return implode('', array_map(fn (array $it): string => $this->card($it, $select, $checked), $items));
    }

    /** Star toggle: a small POST form, so it works without JavaScript. */
    public function favButton(int $id, string $name, bool $on, string $back): string
    {
        return '<form class="favf" method="post" action="/item/' . $id . '/fav"><input type="hidden" name="csrf" value="' . self::e($this->page->csrf()) . '">'
            . '<input type="hidden" name="on" value="' . ($on ? '0' : '1') . '"><input type="hidden" name="back" value="' . self::e($back) . '">'
            . '<button class="favbtn" aria-pressed="' . ($on ? 'true' : 'false') . '" aria-label="Favourite: ' . self::e($name) . '" title="Favourite">' . self::icon('star') . '</button></form>';
    }

    /** @param list<array<string,mixed>> $items "Recently viewed" strip */
    public function recent(array $items): string
    {
        if ($items === []) {
            return '';
        }
        $o = '<section class="recent" id="recent" aria-labelledby="recent-h"><div class="recent-head"><h2 id="recent-h">' . self::icon('history') . ' Recently viewed</h2>'
            . '<form method="post" action="/recent/clear"><input type="hidden" name="csrf" value="' . self::e($this->page->csrf()) . '"><button class="btn btn-ghost btn-sm" id="recent-clear">Clear</button></form></div><ol class="recent-row">';
        foreach ($items as $it) {
            $img = ImageStore::validName(is_string($it['image'] ?? null) ? $it['image'] : null) ? '<img src="/media/thumb/' . self::e((string)$it['image']) . '" alt="" loading="lazy" decoding="async">' : '<span class="ph">' . self::icon('box') . '</span>';
            $o .= '<li><a href="/item/' . (int)$it['id'] . '" class="recent-it">' . $img . '<span>' . self::e((string)$it['name']) . '</span>'
                . (!empty($it['favourite']) ? '<b class="recent-fav" aria-label="Favourite">' . self::icon('star') . '</b>' : '') . '</a></li>';
        }

        return $o . '</ol></section>';
    }

    /** @param array<string,mixed> $it "Expired" / "Expires in 5 days" badge, or '' */
    private function expiryBadge(array $it): string
    {
        $st = $this->calendar->expiryState($it);
        if ($st === '') {
            return '';
        }
        $n = $this->calendar->days((string)$it['expires_on']);
        $txt = $st === 'expired' ? 'Expired' : ($n === 0 ? 'Expires today' : 'Expires in ' . $n . ' day' . ($n === 1 ? '' : 's'));

        return '<span class="expbadge is-' . $st . '">' . self::icon($st === 'expired' ? 'alert' : 'clock') . ' ' . $txt . '</span>';
    }

    /** @param array<string,mixed> $it "Lent · X" / "Overdue · X" badge, or '' */
    private function lendBadge(array $it): string
    {
        if (($it['lent_to'] ?? null) === null) {
            return '';
        }
        $late = $this->calendar->isOverdue($it);

        return '<span class="lendbadge' . ($late ? ' is-overdue' : '') . '">' . self::icon($late ? 'alert' : 'handoff') . ' '
            . ($late ? 'Overdue' : 'Lent') . ' · ' . self::e((string)$it['lent_to']) . '</span>';
    }

    /**
     * Removable chips for the active filters, each linking to the list
     * without it, and "Clear all".
     *
     * @param array<string,string> $f
     * @param list<array<string,mixed>> $locs
     * @param list<array<string,mixed>> $cats
     * @param list<array{id:int,name:string,n:int}> $tags
     * @param string $rawQ the request's q as given (legacy reads $_GET['q'] here)
     */
    public function activeChips(array $f, string $sort, array $locs, array $cats, array $tags, string $rawQ): string
    {
        $f = ListFilters::norm($f);
        $name = static function (string $kind, array $list, string $v): string {
            if ($v === 'none') {
                return Kind::get($kind)['none'];
            }
            foreach ($list as $l) {
                if ((string)$l['id'] === $v) {
                    return (string)($l['path'] ?? $l['name']);
                }
            }

            return '?';
        };
        $default = $this->settings['default_sort'];
        $base = ListFilters::query($rawQ, $f, $sort, $default);
        $href = static function (string $drop) use ($base): string {
            $qs = array_diff_key($base, [$drop => 1]);

            return '/' . ($qs !== [] ? '?' . http_build_query($qs) : '');
        };
        $o = '';
        if ($f['loc'] !== '') {
            $o .= '<a class="achip chip-location" href="' . self::e($href('loc')) . '" data-clear="loc" aria-label="Remove location filter">' . self::icon('pin') . '<span>' . self::e($name('location', $locs, $f['loc'])) . '</span>' . self::icon('x') . '</a>';
        }
        if ($f['cat'] !== '') {
            $o .= '<a class="achip chip-category" href="' . self::e($href('cat')) . '" data-clear="cat" aria-label="Remove category filter">' . self::icon('tag') . '<span>' . self::e($name('category', $cats, $f['cat'])) . '</span>' . self::icon('x') . '</a>';
        }
        if ($f['tag'] !== '') {
            $tn = '?';
            foreach ($tags as $t) {
                if ((string)$t['id'] === $f['tag']) {
                    $tn = $t['name'];
                }
            }
            $o .= '<a class="achip chip-tagf" href="' . self::e($href('tag')) . '" data-clear="tag" aria-label="Remove tag filter">#<span>' . self::e($tn) . '</span>' . self::icon('x') . '</a>';
        }
        foreach (ListFilters::DEFS as $k => $d) {
            if ($f[$k] === '') {
                continue;
            }
            $o .= '<a class="achip ' . $d['class'] . '" href="' . self::e($href($k)) . '" data-clear="' . $k . '" aria-label="Remove ' . self::e($d['what']) . '">'
                . self::icon($d['icon']) . '<span>' . self::e($d['opts'][$f[$k]]) . '</span>' . self::icon('x') . '</a>';
        }
        if ($sort !== $default && isset(ListFilters::SORTS[$sort])) {
            $o .= '<a class="achip" href="' . self::e($href('sort')) . '" data-clear="sort" aria-label="Reset sort">Sort: ' . self::e(ListFilters::SORTS[$sort]) . self::icon('x') . '</a>';
        }
        if ($o !== '') {
            $o .= '<a class="achip achip-clear" href="/" data-clear="all">Clear all</a>';
        }

        return $o;
    }

    /** @param list<array{key:string,label:string,n:int}> $series vertical bars as inline SVG */
    private static function svgBars(array $series, string $label): string
    {
        $W = 320;
        $H = 84;
        $n = max(1, count($series));
        $max = max(1, ...array_map(static fn (array $p): int => $p['n'], $series !== [] ? $series : [['n' => 0]]));
        $gap = $n > 20 ? 2 : 6;
        $bw = ($W - $gap * ($n - 1)) / $n;
        $out = '<div class="chart-wrap"><svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" role="img" aria-label="' . self::e($label) . '">';
        foreach ([0.5, 1] as $g) {
            $y = round($H - ($H - 4) * $g, 1);
            $out .= '<line class="grid" vector-effect="non-scaling-stroke" x1="0" x2="' . $W . '" y1="' . $y . '" y2="' . $y . '"/>';
        }
        foreach ($series as $i => $p) {
            $x = round($i * ($bw + $gap), 2);
            $h = $p['n'] > 0 ? max(3, round(($H - 4) * $p['n'] / $max, 1)) : 1.5;
            $out .= '<rect class="' . ($p['n'] > 0 ? 'bar' : 'bar bar-zero') . '" x="' . $x . '" y="' . round($H - $h, 1) . '" width="' . round($bw, 2) . '" height="' . $h . '" rx="' . min(3, $bw / 3) . '">'
                . '<title>' . self::e($p['label']) . ': ' . $p['n'] . '</title></rect>';
        }
        $out .= '</svg><div class="chart-axis" aria-hidden="true">';
        $ticks = $n > 12 ? [0, (int)floor(($n - 1) / 2), $n - 1] : range(0, $n - 1, $n > 6 ? 2 : 1);
        foreach ($ticks as $i) {
            $anchor = $i === 0 ? 'start' : ($i === $n - 1 && $n > 12 ? 'end' : 'middle');
            $out .= '<span class="ax ax-' . $anchor . '">' . self::e($series[$i]['label']) . '</span>';
        }

        return $out . '</div></div>';
    }

    /**
     * Horizontal "top N" bars; labels filter the list by $param.
     *
     * @param list<array{id:int|string|null,name:string,n:int,other?:bool}> $rows
     * @param (callable(int): string)|null $fmt
     */
    private static function hbars(array $rows, string $param, string $empty, ?callable $fmt = null): string
    {
        $fmt ??= static fn (int $v): string => (string)$v;
        if ($rows === []) {
            return '<p class="st-empty">' . self::e($empty) . '</p>';
        }
        $max = max(1, ...array_map(static fn (array $r): int => (int)$r['n'], $rows));
        $o = '<ul class="hbars">';
        foreach ($rows as $r) {
            $pct = round(100 * (int)$r['n'] / $max, 1);
            $name = self::e($r['name']);
            $label = $r['id'] !== null
                ? '<a href="/?' . $param . '=' . self::e((string)$r['id']) . '" data-' . $param . '="' . self::e((string)$r['id']) . '">' . $name . '</a>'
                : '<span>' . $name . '</span>';
            $o .= '<li class="' . (!empty($r['other']) || $r['id'] === 'none' ? 'muted' : '') . '"><span class="hb-name">' . $label . '</span>'
                . '<svg class="hb-bar" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true"><rect class="hb-track" width="100" height="10" rx="5"/><rect class="hb-fill" width="' . max(2, $pct) . '" height="10" rx="5"/></svg>'
                . '<span class="hb-n">' . self::e($fmt((int)$r['n'])) . '</span></li>';
        }

        return $o . '</ul>';
    }

    /** The overview's body (legacy stats_body_html(); GET /stats). */
    public function statsBody(Stats $stats): string
    {
        $t = $stats->totals();
        $tiles = [
            ['Items', $t['items']], ['Total qty', $t['qty']], ['Locations', $t['locations']],
            ['Categories', $t['categories']], ['This week', $t['week']], ['This month', $t['month']],
        ];
        $o = '<div class="tiles">';
        foreach ($tiles as [$k, $v]) {
            $o .= '<div class="tile"><b>' . number_format($v) . '</b><span>' . self::e($k) . '</span></div>';
        }
        $o .= '<a class="tile tile-link tile-low' . ($t['low'] > 0 ? ' has-low' : '') . '" href="/?stock=low" data-fk="stock" data-fv="low"><b>' . number_format($t['low']) . '</b><span>Running low</span></a>';
        $o .= '<a class="tile tile-link tile-exp' . ($t['expiring'] > 0 ? ' has-exp' : '') . '" href="/?exp=soon" data-fk="exp" data-fv="soon" id="tile-expiring"><b>' . number_format($t['expiring']) . '</b><span>Expiring soon</span></a>';
        $o .= '<a class="tile tile-link tile-expired' . ($t['expired'] > 0 ? ' has-expired' : '') . '" href="/?exp=expired" data-fk="exp" data-fv="expired" id="tile-expired"><b>' . number_format($t['expired']) . '</b><span>Expired</span></a>';
        $o .= '</div>';
        $days = $stats->addedSeries('day');
        $months = $stats->addedSeries('month');
        $busy = static fn (array $s): int => count(array_filter($s, static fn (array $p): bool => $p['n'] > 0));
        if ($busy($months) < 2 && $busy($days) < 2) {
            // everything was added on one day: a chart with a single bar says nothing
            $o .= '<section class="st-card st-wide" id="st-added"><div class="st-head"><h3>Items added</h3></div><p class="st-empty" id="chart-few">'
                . 'The chart appears once items have been added on more than one day.</p></section>';
        } else {
            $o .= '<section class="st-card st-wide" id="st-added">'
                . '<input type="radio" class="vh" name="strange" id="rg-d" value="d" checked><input type="radio" class="vh" name="strange" id="rg-m" value="m">'
                . '<div class="st-head"><h3>Items added</h3>'
                . '<div class="seg" role="group" aria-label="Range"><label for="rg-d">30 days</label><label for="rg-m">12 months</label></div></div>'
                . '<div class="chart-d">' . self::svgBars($days, 'Items added per day, last 30 days') . '<p class="st-sub">' . array_sum(array_column($days, 'n')) . ' in the last 30 days · peak ' . max(array_column($days, 'n')) . '/day</p></div>'
                . '<div class="chart-m">' . self::svgBars($months, 'Items added per month, last 12 months') . '<p class="st-sub">' . array_sum(array_column($months, 'n')) . ' in the last 12 months · peak ' . max(array_column($months, 'n')) . '/month</p></div>'
                . '</section>';
        }
        $val = $stats->value(5);
        if ($val['priced'] > 0) {
            $money = fn (int $c): string => $this->money($c);
            $o .= '<section class="st-card st-wide st-value" id="st-value"><div class="st-head"><h3>Value</h3>'
                . '<a class="st-link" href="/?sort=value">Most valuable</a></div>'
                . '<p class="st-total"><b id="value-total">' . self::e($this->money($val['total'])) . '</b> <span class="st-sub">total purchase price of '
                . self::itemNoun($val['priced']) . ' with a price</span></p>'
                . '<div class="st-value-grid"><div><h4>By location</h4>' . self::hbars($val['by_location'], 'loc', 'No prices yet.', $money) . '</div>'
                . '<div><h4>By category</h4>' . self::hbars($val['by_category'], 'cat', 'No prices yet.', $money) . '</div></div></section>';
        }
        foreach (Kind::KINDS as $kind) {
            $k = Kind::get($kind);
            $o .= '<section class="st-card st-' . $kind . '"><div class="st-head"><h3>By ' . self::e($k['one']) . '</h3><a class="st-link" href="' . $k['path'] . '">All</a></div>'
                . self::hbars($stats->by($kind, 5), $k['param'], 'No items yet.')
                . ($kind === 'location' && $stats->locationsNested() ? '<p class="st-sub" id="st-rollup">Top-level locations, counting everything inside them</p>' : '')
                . '</section>';
        }

        return $o;
    }

    /** @param array{items:int,qty:int} $brief */
    private function statsSection(array $brief, Stats $stats, bool $open): string
    {
        if ($brief['items'] === 0) {
            return '';   // the list's own empty state takes over
        }

        return '<details class="stats-box" id="stats"' . ($open ? ' open' : '') . ' data-loaded="' . ($open ? '1' : '0') . '">'
            . '<summary><span class="st-title">' . self::icon('chart') . ' Overview</span><span class="st-mini">'
            . self::e(self::statsBrief($brief['items'], $brief['qty'])) . '</span><span class="chev" aria-hidden="true"></span></summary>'
            . '<div class="stats-body" id="stats-body">' . ($open ? $this->statsBody($stats) : '<p class="st-sub">Loading…</p>') . '</div>'
            . '</details>';
    }

    /** @param array<string,string> $f */
    private static function newItemHref(array $f): string
    {
        $qs = [];
        foreach (['loc', 'cat'] as $k) {
            if (ctype_digit($f[$k] ?? '')) {
                $qs[$k] = $f[$k];
            }
        }

        return '/new' . ($qs !== [] ? '?' . http_build_query($qs) : '');
    }

    /** @param list<array<string,mixed>> $list */
    private static function filterSelect(string $kind, array $list, string $cur, int $none): string
    {
        $t = Kind::get($kind);
        $o = '<label class="field"><span>' . self::e($t['One']) . '</span><select name="' . $t['param'] . '" id="' . $t['param'] . '">'
            . '<option value="">All ' . self::e($t['many']) . '</option>';
        foreach ($list as $l) {
            $o .= '<option value="' . (int)$l['id'] . '"' . ((string)$l['id'] === $cur ? ' selected' : '') . '>' . self::e(LocationTree::optionLabel($l)) . ' (' . (int)($l['total'] ?? $l['n']) . ')</option>';
        }
        if ($none > 0 || $cur === 'none') {
            $o .= '<option value="none"' . ($cur === 'none' ? ' selected' : '') . '>' . self::e($t['none']) . ' (' . $none . ')</option>';
        }

        return $o . '</select></label>';
    }

    /**
     * Saved filter chips; the one matching the current view is highlighted.
     *
     * @param list<array{id:int,name:string,query:string}> $list
     */
    public function savedChips(array $list, string $current): string
    {
        if ($list === []) {
            return '';
        }
        $o = '';
        foreach ($list as $sf) {
            $on = $sf['query'] === $current;
            $o .= '<a class="achip sfchip' . ($on ? ' is-on' : '') . '" href="/?' . self::e($sf['query']) . '" data-query="' . self::e($sf['query']) . '"'
                . ($on ? ' aria-current="true"' : '') . '>' . self::icon('star') . '<span>' . self::e($sf['name']) . '</span></a>';
        }

        return $o . '<a class="achip sfchip-manage" href="/filters" aria-label="Manage saved filters">' . self::icon('edit') . '<span>Edit</span></a>';
    }

    /**
     * @param list<array<string,mixed>> $locs
     * @param list<array<string,mixed>> $cats
     * @param list<array{id:int,name:string,n:int}> $tags
     */
    private function bulkBar(array $locs, array $cats, array $tags, string $back, string $allHref): string
    {
        $opts = static fn (array $rows, string $none): string => '<option value="">' . self::e($none) . '</option>'
            . implode('', array_map(static fn (array $r): string => '<option value="' . (int)$r['id'] . '">' . self::e(LocationTree::optionLabel($r)) . '</option>', $rows));
        ob_start(); ?>
<form class="bulkbar" id="bulkform" method="post" action="/bulk">
  <input type="hidden" name="csrf" value="<?= self::e($this->page->csrf()) ?>">
  <input type="hidden" name="back" value="<?= self::e($back) ?>">
  <div class="bulkbar-in">
    <span class="selcount" id="selcount" role="status">Select items</span>
    <a class="btn" href="<?= self::e($allHref) ?>" id="sel-all">All on page</a>
    <details class="fsheet bulksheet" id="bulksheet">
      <summary class="btn btn-primary" id="bulk-open">Actions</summary>
      <div class="fpanel" role="dialog" aria-labelledby="bulk-title">
        <div class="fpanel-head"><h2 id="bulk-title">Selected items</h2><button type="button" class="btn btn-ghost" id="bulk-close">Done</button></div>
        <div class="bulkrow"><label class="field"><span>Move to location</span><select name="location_id" id="bulk-loc"><?= $opts($locs, 'No location') ?></select></label>
          <button class="btn btn-primary" name="action" value="move" id="bulk-move">Move</button></div>
        <div class="bulkrow"><label class="field"><span>Set category</span><select name="category_id" id="bulk-cat"><?= $opts($cats, 'No category') ?></select></label>
          <button class="btn btn-primary" name="action" value="category" id="bulk-category">Set</button></div>
        <div class="bulkrow"><label class="field"><span>Add tags</span><input type="text" name="tags" id="bulk-tags" placeholder="e.g. Garage, Winter" autocomplete="off" maxlength="400"></label>
          <button class="btn btn-primary" name="action" value="tag_add" id="bulk-tag-add">Add</button></div>
        <?php if ($tags): ?>
        <div class="bulkrow"><label class="field"><span>Remove tag</span><select name="tag_id" id="bulk-tag"><?php foreach ($tags as $t): ?><option value="<?= (int)$t['id'] ?>"><?= self::e($t['name']) ?></option><?php endforeach; ?></select></label>
          <button class="btn" name="action" value="tag_remove" id="bulk-tag-remove">Remove</button></div>
        <?php endif; ?>
        <button class="btn btn-wide" name="action" value="labels" id="bulk-labels"><?= self::icon('qr') ?> Print QR labels</button>
        <button class="btn btn-danger btn-wide" name="action" value="delete" id="bulk-delete"><?= self::icon('trash') ?> Delete selected…</button>
      </div>
    </details>
  </div>
</form>
<?php
        return (string)ob_get_clean();
    }

    /**
     * The list page (legacy view_list()).
     *
     * @param array<string,string> $f
     * @param array{items: list<array<string,mixed>>, total: int, more: bool, fixed: array<string,string>} $res
     * @param list<array<string,mixed>> $locs
     * @param list<array<string,mixed>> $cats
     * @param array{items:int,qty:int,unassigned:int,uncategorized:int} $brief
     * @param list<array{id:int,name:string,n:int}> $tags
     * @param list<array{id:int,name:string,query:string}> $saved
     * @param list<array<string,mixed>> $recent
     */
    public function page(string $q, string $rawQ, array $f, string $sort, array $res, array $locs, array $cats, array $brief, Stats $stats, array $tags, int $nFav, array $saved, array $recent, ?string $toast, bool $statsOpen, bool $select, bool $all): string
    {
        $default = $this->settings['default_sort'];
        $head = $this->page->start('', $select ? 'page-select' : 'page-list');
        $f = ListFilters::norm($f);
        $qs = ListFilters::query($q, $f, $sort, $default);
        $listHref = '/' . ($qs !== [] ? '?' . http_build_query($qs) : '');
        $selHref = '/?' . http_build_query($qs + ['select' => '1']);
        $filtered = $q !== '' || ListFilters::active($f);
        $nActive = ListFilters::activeCount($f, $sort, $default);
        ob_start(); ?>
<header class="top">
  <div class="bar">
    <h1 class="brand"><?= $this->page->brandLogo(28) ?><span><?= self::e($this->settings['app_name']) ?></span></h1>
    <span class="stats"><?= self::e(self::statsBrief($brief['items'], $brief['qty'])) ?></span>
    <a class="btn btn-ghost btn-round hide-sm" href="/locations" aria-label="Manage locations &amp; categories" title="Locations &amp; categories"><?= self::icon('sliders') ?></a>
    <?= $this->page->menu() ?>
    <a class="btn btn-primary btn-round" href="<?= self::e(self::newItemHref($f)) ?>" aria-label="Add item"><?= self::icon('plus') ?></a>
  </div>
  <form class="filters" action="/" method="get" role="search" id="filters">
    <label class="search">
      <?= self::icon('search') ?>
      <input type="search" name="q" value="<?= self::e($q) ?>" placeholder="Search items…" aria-label="Search names, places, categories, notes" autocomplete="off" autocorrect="off" spellcheck="false" enterkeyhint="search" id="q">
    </label>
    <div class="frow">
      <details class="fsheet" id="fsheet">
        <summary class="btn fbtn" id="filter-open" aria-label="Filter and sort"><?= self::icon('filter') ?><span>Filter</span><b class="fcount" id="fcount"<?= $nActive ? '' : ' hidden' ?>><?= $nActive ?></b></summary>
        <div class="fpanel" role="dialog" aria-labelledby="fpanel-title">
          <div class="fpanel-head"><h2 id="fpanel-title">Filter &amp; sort</h2><button type="button" class="btn btn-ghost" id="filter-close">Done</button></div>
          <?= self::filterSelect('location', $locs, $f['loc'], $brief['unassigned']) ?>
          <?= self::filterSelect('category', $cats, $f['cat'], $brief['uncategorized']) ?>
          <?php if ($tags || $f['tag'] !== ''): ?>
          <label class="field"><span>Tag</span>
          <select name="tag" id="tag">
            <option value="">All tags</option>
            <?php foreach ($tags as $t): ?>
            <option value="<?= (int)$t['id'] ?>"<?= (string)$t['id'] === $f['tag'] ? ' selected' : '' ?>><?= self::e($t['name']) ?> (<?= (int)$t['n'] ?>)</option>
            <?php endforeach; ?>
          </select></label>
          <?php endif; ?>
          <?php foreach (ListFilters::DEFS as $k => $d): ?>
          <label class="field"><span><?= self::e($d['label']) ?></span>
          <select name="<?= $k ?>" id="<?= $k ?>" data-filter>
            <option value=""><?= self::e($d['all']) ?></option>
            <?php foreach ($d['opts'] as $v => $lbl): ?><option value="<?= self::e((string)$v) ?>"<?= $f[$k] === (string)$v ? ' selected' : '' ?>><?= self::e($lbl) ?></option><?php endforeach; ?>
          </select></label>
          <?php endforeach; ?>
          <label class="field"><span>Sort by</span>
          <select name="sort" id="sort" data-default="<?= self::e($default) ?>">
            <?php foreach (ListFilters::SORTS as $k => $v): ?>
            <option value="<?= $k ?>"<?= $sort === $k ? ' selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></label>
          <div class="fpanel-actions">
            <a class="btn" href="/" id="filter-clear">Clear all</a>
            <button class="btn btn-primary" id="filter-apply">Show results</button>
          </div>
        </div>
      </details>
      <a class="btn fbtn<?= $select ? ' is-on' : '' ?>" id="select-toggle" href="<?= self::e($select ? $listHref : $selHref) ?>"<?= $select ? ' aria-pressed="true"' : '' ?>><?= self::icon($select ? 'x' : 'check') ?><span><?= $select ? 'Done' : 'Select' ?></span></a>
      <?php if ($select): ?><input type="hidden" name="select" value="1"><?php endif; ?>
      <div class="achips" id="achips"><?= $this->activeChips($f, $sort, $locs, $cats, $tags, $rawQ) ?></div>
    </div>
    <?php $curQuery = http_build_query($qs); ?>
    <div class="saved-row" id="saved-row">
      <?php if ($nFav || $f['fav'] !== ''): $favOn = $f['fav'] === '1'; ?>
      <a class="achip chip-fav<?= $favOn ? ' is-on' : '' ?>" id="fav-chip" href="<?= self::e('/' . (($fq = http_build_query(ListFilters::query($q, ['fav' => $favOn ? '' : '1'] + $f, $sort, $default))) ? '?' . $fq : '')) ?>"<?= $favOn ? ' aria-pressed="true"' : '' ?>><?= self::icon('star') ?> Favourites <b><?= $nFav ?></b></a>
      <?php endif; ?>
      <div class="saved-chips" id="saved-chips"><?= $this->savedChips($saved, $curQuery) ?></div>
      <details class="savefilter" id="savefilter"<?= $curQuery === '' ? ' hidden' : '' ?>>
        <summary class="btn btn-sm" id="savefilter-open"><?= self::icon('star') ?> Save filter</summary>
        <div class="savefilter-form">
          <input type="text" name="name" id="sf-name" form="sf-form" maxlength="60" required autocomplete="off" enterkeyhint="done" placeholder="Name, e.g. Garage tools" aria-label="Name of the saved filter">
          <button class="btn btn-primary" form="sf-form" id="sf-save">Save</button>
        </div>
      </details>
    </div>
  </form>
  <form method="post" action="/filters" id="sf-form" class="vh-form">
    <input type="hidden" name="csrf" value="<?= self::e($this->page->csrf()) ?>">
    <?php foreach ($qs as $k => $v): ?><input type="hidden" name="<?= self::e($k) ?>" value="<?= self::e((string)$v) ?>" data-sf><?php endforeach; ?>
  </form>
</header>
<main class="wrap">
  <?= !$select && !$filtered ? $this->recent($recent) : '' ?>
  <?= $this->statsSection($brief, $stats, $statsOpen) ?>
  <p class="count" id="count"><?= self::e(self::countText($res['total'], $filtered) . self::fixedNote($res['fixed'])) ?></p>
  <div class="grid<?= $this->settings['list_view'] === 'compact' ? ' is-compact' : '' ?>" id="grid" aria-live="polite"><?= $this->cards($res['items'], $select, $all) ?></div>
  <div class="empty" id="empty"<?= $res['total'] ? ' hidden' : '' ?>>
    <?= self::icon('box') ?>
    <p class="empty-title" id="empty-title"><?= $filtered ? 'Nothing matches.' : 'Your catalog is empty.' ?></p>
    <p class="empty-sub" id="empty-sub"><?= $filtered ? 'Try another word or clear the filters.' : 'Snap a photo of something, give it a name and a place. That’s it.' ?></p>
    <a class="btn btn-primary" id="empty-add" href="/new"<?= $filtered ? ' hidden' : '' ?>><?= self::icon('camera') ?> Add your first item</a>
    <a class="btn" id="empty-clear" href="/"<?= $filtered ? '' : ' hidden' ?>>Clear search &amp; filters</a>
  </div>
  <button class="btn btn-wide" id="more" data-page="1"<?= $res['more'] ? '' : ' hidden' ?>>Show more</button>
  <footer class="foot">
    <a href="/locations">Locations</a>
    <a href="/categories">Categories</a>
    <a href="/export.csv" download>Export CSV</a>
    <?php if ($this->signInEnabled): ?>
    <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= self::e($this->page->csrf()) ?>"><button class="linkbtn">Sign out</button></form>
    <?php endif; ?>
    <span class="app-version" title="Apotheke version">v<?= self::e($this->version) ?></span>
  </footer>
</main>
<?php if ($select): ?>
<?= $this->bulkBar($locs, $cats, $tags, $selHref, $selHref . '&all=1') ?>
<?php else: ?>
<a class="fab" href="<?= self::e(self::newItemHref($f)) ?>" id="fab" aria-label="Add item"><?= self::icon('camera') ?></a>
<?php endif; ?>
<?php if ($toast !== null): ?><div class="toast" role="status"><?= self::e($toast) ?></div><?php endif; ?>
<?php
        return $head . ob_get_clean() . $this->page->end();
    }

    /**
     * Saved filters: open, rename, delete (legacy view_saved_filters()).
     *
     * @param list<array{id:int,name:string,query:string}> $list
     * @param callable(string): string $describe
     */
    public function savedFiltersPage(array $list, callable $describe, ?string $error, ?string $toast): string
    {
        $head = $this->page->start('Saved filters', 'page-locs');
        $tok = self::e($this->page->csrf());
        ob_start(); ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="/" aria-label="Back"><?= self::icon('back') ?></a>
    <h1 class="title-sm">Saved filters</h1>
    <?= $this->page->menu() ?>
  </div>
</header>
<main class="wrap narrow">
  <div class="err" role="alert"<?= $error === null ? ' hidden' : '' ?>><?= self::e($error) ?></div>
  <p class="hint">Set up a search, filters and sort on the list, then tap <b>Save filter</b>. Saved filters appear as chips above the list.</p>
  <?php if (!$list): ?>
  <div class="empty"><?= self::icon('star') ?><p>No saved filters yet.</p><a class="btn" href="/">Go to the list</a></div>
  <?php else: ?>
  <ul class="loclist" id="sflist">
    <?php foreach ($list as $sf): ?>
    <li class="loc" id="sf-<?= $sf['id'] ?>">
      <div class="loc-main sf-main">
        <a class="loc-name" href="/?<?= self::e($sf['query']) ?>"><?= self::e($sf['name']) ?></a>
        <span class="loc-count sf-desc"><?= self::e($describe($sf['query'])) ?></span>
      </div>
      <details class="loc-more">
        <summary><?= self::icon('edit') ?> Edit</summary>
        <div class="loc-actions">
          <form method="post" action="/filters/<?= $sf['id'] ?>/rename" class="loc-rename">
            <input type="hidden" name="csrf" value="<?= $tok ?>">
            <input type="text" name="name" value="<?= self::e($sf['name']) ?>" required maxlength="60" autocomplete="off" aria-label="Rename “<?= self::e($sf['name']) ?>”">
            <button class="btn">Rename</button>
          </form>
          <div class="loc-btns">
            <form method="post" action="/filters/<?= $sf['id'] ?>/delete" data-confirm="Delete the saved filter “<?= self::e($sf['name']) ?>”?">
              <input type="hidden" name="csrf" value="<?= $tok ?>">
              <button class="btn btn-danger"><?= self::icon('trash') ?> Delete</button>
            </form>
          </div>
        </div>
      </details>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</main>
<?php if ($toast !== null): ?><div class="toast" role="status"><?= self::e($toast) ?></div><?php endif; ?>
<?php
        return $head . ob_get_clean() . $this->page->end();
    }
}
