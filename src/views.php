<?php
declare(strict_types=1);

/* ---------- tiny icon set (inline SVG, no requests) ---------- */

function icon(string $n): string
{
    static $p = [
        'plus'   => '<path d="M12 5v14M5 12h14"/>',
        'minus'  => '<path d="M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'camera' => '<path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.5"/>',
        'image'  => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>',
        'box'    => '<path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z"/><path d="m3 7.5 9 4.5 9-4.5M12 12v9"/>',
        'back'   => '<path d="M15 5l-7 7 7 7"/>',
        'edit'   => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
        'trash'  => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'x'      => '<path d="M6 6l12 12M18 6 6 18"/>',
        'pin'    => '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'archive' => '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8M10 12h4"/>',
        'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'star'   => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
        'move'   => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'chart'  => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'tag'    => '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><circle cx="8" cy="8" r="1.6"/>',
        'sun'    => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'   => '<path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/>',
        'auto'   => '<circle cx="12" cy="12" r="8.5"/><path d="M12 3.5v17A8.5 8.5 0 0 0 12 3.5z" fill="currentColor"/>',
        'menu'   => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'filter' => '<path d="M4 5h16l-6 7.5V19l-4 2v-8.5z"/>',
        'download'=> '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
        'logout' => '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/>',
        'qr'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 17h4v4h-4"/>',
        'printer'=> '<path d="M6 9V3h12v6M6 18H4a1 1 0 0 1-1-1v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6a1 1 0 0 1-1 1h-2"/><rect x="6" y="14" width="12" height="7"/>',
        'check'  => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'alert'  => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17v.01"/>',
        'sliders'=> '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
    ];
    return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ($p[$n] ?? '') . '</svg>';
}

/* ---------- layout ---------- */

function page_start(string $title, string $bodyClass = ''): void
{
    $t = $title === '' ? APP_NAME . ' – ' . APP_TAGLINE : $title . ' · ' . APP_NAME;
    $theme = theme_pref();
    ?><!doctype html>
<html lang="en" data-theme="<?= h($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($t) ?></title>
<?php if ($theme === 'auto'): ?>
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f5f5f7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#111113" media="(prefers-color-scheme: dark)">
<?php else: ?>
<meta name="color-scheme" content="<?= $theme ?>">
<meta name="theme-color" content="<?= $theme === 'dark' ? '#111113' : '#f5f5f7' ?>">
<?php endif; ?>
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h(APP_NAME) ?>">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="format-detection" content="telephone=no">
<meta name="description" content="<?= h(APP_NAME . ', ' . APP_TAGLINE) ?>">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
<link rel="stylesheet" href="<?= h(asset('app.css')) ?>">
<script src="<?= h(asset('theme.js')) ?>"></script>
<script src="<?= h(asset('app.js')) ?>" defer></script>
</head>
<body class="<?= h($bodyClass) ?>">
<?php
}

function page_end(): void
{
    echo "\n</body>\n</html>\n";
}

/* ---------- partials ---------- */

function chip_html(string $kind, ?int $id, string $name, bool $large = false): string
{
    $t = tax($kind);
    $cls = 'chip chip-' . $kind . ($large ? ' chip-lg' : '');
    if ($id === null) return $large ? '<span class="' . $cls . ' chip-muted">' . h($t['none']) . '</span>' : '';
    return '<a class="' . $cls . '" href="/?' . $t['param'] . '=' . $id . '" data-' . $t['param'] . '="' . $id . '">'
        . ($large ? icon($t['icon']) . ' ' : '') . h($name) . '</a>';
}

function card_html(array $it, bool $select = false, bool $checked = false): string
{
    $id = (int)$it['id'];
    $img = valid_image_name($it['image'] ?? null)
        ? '<img src="/media/thumb/' . h($it['image']) . '" alt="" loading="lazy" decoding="async">'
        : '<span class="ph">' . icon('box') . '</span>';
    $locId = $it['location_id'] !== null ? (int)$it['location_id'] : null;
    $catId = $it['category_id'] !== null ? (int)$it['category_id'] : null;
    $chips = chip_html('location', $locId, (string)$it['location']) . chip_html('category', $catId, (string)$it['category']);
    if ($chips === '') $chips = '<span class="chip chip-muted">No location</span>';
    $q = (int)$it['quantity'];
    $min = ($it['min_qty'] ?? null) === null ? null : (int)$it['min_qty'];
    return '<article class="card' . (is_low($it) ? ' is-low' : '') . ($select ? ' selectable' : '') . '" data-id="' . $id . '"' . ($min !== null ? ' data-min="' . $min . '"' : '') . '>'
        . ($select ? '<label class="sel"><input type="checkbox" class="selbox" name="ids[]" value="' . $id . '" form="bulkform"' . ($checked ? ' checked' : '') . ' aria-label="Select ' . h($it['name']) . '"><span class="selmark" aria-hidden="true">' . icon('check') . '</span></label>' : '')
        . '<a class="card-img" href="/item/' . $id . '" aria-label="' . h($it['name']) . '">' . $img . '</a>'
        . '<div class="card-body">'
        . '<a class="card-title" href="/item/' . $id . '">' . h($it['name']) . '</a>'
        . '<div class="chips">' . $chips . '</div>'
        . ($min !== null ? low_badge_html() : '')
        . '<div class="qty" data-id="' . $id . '">'
        . '<button type="button" class="qbtn" data-delta="-1" aria-label="Decrease"' . ($q <= 0 ? ' disabled' : '') . '>' . icon('minus') . '</button>'
        . '<output class="qval">' . $q . '</output>'
        . '<button type="button" class="qbtn" data-delta="1" aria-label="Increase">' . icon('plus') . '</button>'
        . '</div></div></article>';
}

/** "Running low" badge; shown by CSS while the surrounding card/page has .is-low (so +/- can toggle it). */
function low_badge_html(): string
{
    return '<span class="lowbadge" role="status">' . icon('alert') . ' Running low</span>';
}

function cards_html(array $items, bool $select = false, bool $checked = false): string
{
    return implode('', array_map(fn($it) => card_html($it, $select, $checked), $items));
}

/** Toast after a bulk action. */
function bulk_toast(int $n, string $action): string
{
    $s = $n . ' item' . ($n === 1 ? '' : 's');
    return match ($action) {
        'move' => "Moved $s", 'category' => "Set the category of $s", 'tag_add' => "Tagged $s",
        'tag_remove' => "Removed the tag from $s", 'delete' => "Deleted $s", default => "Updated $s",
    };
}

/** Fixed bottom bar of the list's selection mode: count, select all on page, actions sheet. */
function bulk_bar_html(array $locs, array $cats, array $tags, string $back, string $allHref): string
{
    $opts = fn(array $rows, string $none) => '<option value="">' . h($none) . '</option>'
        . implode('', array_map(fn($r) => '<option value="' . (int)$r['id'] . '">' . h($r['name']) . '</option>', $rows));
    ob_start(); ?>
<form class="bulkbar" id="bulkform" method="post" action="/bulk">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="back" value="<?= h($back) ?>">
  <div class="bulkbar-in">
    <span class="selcount" id="selcount" role="status">Select items</span>
    <a class="btn" href="<?= h($allHref) ?>" id="sel-all">All on page</a>
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
        <div class="bulkrow"><label class="field"><span>Remove tag</span><select name="tag_id" id="bulk-tag"><?php foreach ($tags as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?></select></label>
          <button class="btn" name="action" value="tag_remove" id="bulk-tag-remove">Remove</button></div>
        <?php endif; ?>
        <button class="btn btn-danger btn-wide" name="action" value="delete" id="bulk-delete"><?= icon('trash') ?> Delete selected…</button>
      </div>
    </details>
  </div>
</form>
<?php
    return (string)ob_get_clean();
}

function view_bulk_delete(array $items, string $back): void
{
    page_start('Delete items');
    $n = count($items);
    ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="<?= h($back) ?>" aria-label="Back"><?= icon('back') ?></a>
    <h1 class="title-sm">Delete items</h1>
    <span class="spacer"></span>
  </div>
</header>
<main class="wrap narrow">
  <h2 class="item-name">Delete <?= $n ?> item<?= $n === 1 ? '' : 's' ?>?</h2>
  <p class="lead">Their photos are deleted too. The history keeps a record. This can’t be undone.</p>
  <ul class="bulklist" id="bulklist">
    <?php foreach ($items as $it): ?><li><?= h($it['name']) ?><?= $it['location'] !== '' ? ' <span class="hint">· ' . h($it['location']) . '</span>' : '' ?></li><?php endforeach; ?>
  </ul>
  <form method="post" action="/bulk" class="itemform" id="bulkdel">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="confirm" value="1">
    <input type="hidden" name="back" value="<?= h($back) ?>">
    <?php foreach ($items as $it): ?><input type="hidden" name="ids[]" value="<?= (int)$it['id'] ?>"><?php endforeach; ?>
    <div class="actionbar actionbar-form">
      <div class="actionbar-in">
        <a class="btn" href="<?= h($back) ?>">Cancel</a>
        <button class="btn btn-danger-solid" id="bulk-confirm"><?= icon('trash') ?> Delete <?= $n ?></button>
      </div>
    </div>
  </form>
</main>
<?php
    page_end();
}

function count_text(int $total, bool $filtered): string
{
    $s = $total . ' item' . ($total === 1 ? '' : 's');
    return $filtered ? $s . ' found' : $s;
}

/** Header "more" menu: theme switch (Auto/Light/Dark), manage, export, sign out. Works without JS (<details> + forms). */
function menu_html(): string
{
    $tok = h(csrf_token());
    $cur = theme_pref();
    $back = h(safe_back((string)($_SERVER['REQUEST_URI'] ?? '/')));
    $o = '<details class="menu" id="menu"><summary class="btn btn-ghost btn-round" id="menu-btn" aria-label="Menu" title="Menu">' . icon('menu') . '</summary>'
       . '<div class="menu-panel" role="menu">'
       . '<form method="post" action="/theme" class="theme-form" id="theme-form"><input type="hidden" name="csrf" value="' . $tok . '"><input type="hidden" name="back" value="' . $back . '">'
       . '<p class="menu-label" id="theme-label">Appearance</p><div class="seg seg-lg" role="radiogroup" aria-labelledby="theme-label">';
    foreach (['auto' => ['Auto', 'auto'], 'light' => ['Light', 'sun'], 'dark' => ['Dark', 'moon']] as $k => [$label, $ic]) {
        $o .= '<button name="theme" value="' . $k . '" role="radio" aria-checked="' . ($k === $cur ? 'true' : 'false') . '" class="seg-btn' . ($k === $cur ? ' on' : '') . '">' . icon($ic) . '<span>' . $label . '</span></button>';
    }
    $o .= '</div></form>'
        . '<a class="menu-item" href="/locations" role="menuitem">' . icon('pin') . ' Locations</a>'
        . '<a class="menu-item" href="/categories" role="menuitem">' . icon('tag') . ' Categories</a>'
        . '<a class="menu-item" href="/labels" role="menuitem">' . icon('qr') . ' Print QR labels</a>'
        . '<a class="menu-item" href="/export.csv" role="menuitem">' . icon('download') . ' Export CSV</a>'
        . '<a class="menu-item" href="/activity" role="menuitem">' . icon('clock') . ' Recent activity</a>'
        . '<a class="menu-item" href="/backup" role="menuitem">' . icon('archive') . ' Backup &amp; restore</a>';
    if (app_password() !== '') {
        $o .= '<form method="post" action="/logout"><input type="hidden" name="csrf" value="' . $tok . '"><button class="menu-item" role="menuitem">' . icon('logout') . ' Sign out</button></form>';
    }
    return $o . '</div></details>';
}

function active_filter_count(array $f, string $sort): int
{
    return ($f['loc'] !== '' ? 1 : 0) + ($f['cat'] !== '' ? 1 : 0) + (($f['tag'] ?? '') !== '' ? 1 : 0) + (($f['stock'] ?? '') !== '' ? 1 : 0) + ($sort !== 'recent' && isset(SORTS[$sort]) ? 1 : 0);
}

const SORTS = ['recent' => 'Recently added', 'name' => 'Name', 'location' => 'Location', 'category' => 'Category', 'qty' => 'Quantity'];

/** Removable chips for the active filters (each links to the list without that filter) + "Clear all". */
function active_chips_html(array $f, string $sort, array $locs, array $cats, ?array $tags = null): string
{
    $f += ['tag' => '', 'stock' => ''];
    $name = function (string $kind, array $list, string $v): string {
        if ($v === 'none') return tax($kind)['none'];
        foreach ($list as $l) if ((string)$l['id'] === $v) return (string)$l['name'];
        return '?';
    };
    $base = ['q' => $_GET['q'] ?? '', 'loc' => $f['loc'], 'cat' => $f['cat'], 'tag' => $f['tag'], 'stock' => $f['stock'], 'sort' => $sort === 'recent' ? '' : $sort];
    $href = function (string $drop) use ($base): string {
        $qs = array_filter(array_diff_key($base, [$drop => 1]), fn($v) => $v !== '' && $v !== null);
        return '/' . ($qs ? '?' . http_build_query($qs) : '');
    };
    $o = '';
    if ($f['loc'] !== '') $o .= '<a class="achip chip-location" href="' . h($href('loc')) . '" data-clear="loc" aria-label="Remove location filter">' . icon('pin') . '<span>' . h($name('location', $locs, $f['loc'])) . '</span>' . icon('x') . '</a>';
    if ($f['cat'] !== '') $o .= '<a class="achip chip-category" href="' . h($href('cat')) . '" data-clear="cat" aria-label="Remove category filter">' . icon('tag') . '<span>' . h($name('category', $cats, $f['cat'])) . '</span>' . icon('x') . '</a>';
    if ($f['tag'] !== '') {
        $tn = '?';
        foreach ($tags ?? tags_all() as $t) if ((string)$t['id'] === $f['tag']) $tn = (string)$t['name'];
        $o .= '<a class="achip chip-tagf" href="' . h($href('tag')) . '" data-clear="tag" aria-label="Remove tag filter">#<span>' . h($tn) . '</span>' . icon('x') . '</a>';
    }
    if ($f['stock'] === 'low') $o .= '<a class="achip chip-low" href="' . h($href('stock')) . '" data-clear="stock" aria-label="Remove running-low filter">' . icon('alert') . '<span>Running low</span>' . icon('x') . '</a>';
    if ($sort !== 'recent' && isset(SORTS[$sort])) $o .= '<a class="achip" href="' . h($href('sort')) . '" data-clear="sort" aria-label="Reset sort">Sort: ' . h(SORTS[$sort]) . icon('x') . '</a>';
    if ($o !== '') $o .= '<a class="achip achip-clear" href="/" data-clear="all">Clear all</a>';
    return $o;
}

/* ---------- statistics ---------- */

/** Vertical bar chart as inline SVG (styled from app.css, so CSP-safe and dark/light aware). */
function svg_bars(array $series, string $label): string
{
    $W = 320; $H = 84; $pad = 15; $n = max(1, count($series));
    $max = max(1, ...array_map(fn($p) => $p['n'], $series ?: [['n' => 0]]));
    $gap = $n > 20 ? 2 : 6;
    $bw = ($W - $gap * ($n - 1)) / $n;
    $out = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . ($H + $pad) . '" role="img" aria-label="' . h($label) . '">';
    foreach ([0.5, 1] as $g) {
        $y = round($H - ($H - 4) * $g, 1);
        $out .= '<line class="grid" x1="0" x2="' . $W . '" y1="' . $y . '" y2="' . $y . '"/>';
    }
    foreach ($series as $i => $p) {
        $x = round($i * ($bw + $gap), 2);
        $h = $p['n'] > 0 ? max(3, round(($H - 4) * $p['n'] / $max, 1)) : 1.5;
        $out .= '<rect class="' . ($p['n'] > 0 ? 'bar' : 'bar bar-zero') . '" x="' . $x . '" y="' . round($H - $h, 1) . '" width="' . round($bw, 2) . '" height="' . $h . '" rx="' . min(3, $bw / 3) . '">'
              . '<title>' . h($p['label']) . ': ' . $p['n'] . '</title></rect>';
    }
    $ticks = $n > 12 ? [0, (int)floor(($n - 1) / 2), $n - 1] : range(0, $n - 1, $n > 6 ? 2 : 1);
    foreach ($ticks as $i) {
        $anchor = $i === 0 ? 'start' : ($i === $n - 1 && $n > 12 ? 'end' : 'middle');
        $x = $anchor === 'start' ? 0 : ($anchor === 'end' ? $W : round($i * ($bw + $gap) + $bw / 2, 1));
        $out .= '<text class="axis" x="' . $x . '" y="' . ($H + $pad - 2) . '" text-anchor="' . $anchor . '">' . h($series[$i]['label']) . '</text>';
    }
    return $out . '</svg>';
}

/** Horizontal "top N" bars (HTML rows + tiny SVG bar each); labels filter the list by $param. */
function hbars(array $rows, string $param, string $empty): string
{
    if (!$rows) return '<p class="st-empty">' . h($empty) . '</p>';
    $max = max(1, ...array_map(fn($r) => (int)$r['n'], $rows));
    $o = '<ul class="hbars">';
    foreach ($rows as $r) {
        $pct = round(100 * (int)$r['n'] / $max, 1);
        $name = h($r['name']);
        $label = $r['id'] !== null
            ? '<a href="/?' . $param . '=' . h((string)$r['id']) . '" data-' . $param . '="' . h((string)$r['id']) . '">' . $name . '</a>'
            : '<span>' . $name . '</span>';
        $o .= '<li class="' . (!empty($r['other']) || $r['id'] === 'none' ? 'muted' : '') . '"><span class="hb-name">' . $label . '</span>'
            . '<svg class="hb-bar" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true"><rect class="hb-track" width="100" height="10" rx="5"/><rect class="hb-fill" width="' . max(2, $pct) . '" height="10" rx="5"/></svg>'
            . '<span class="hb-n">' . (int)$r['n'] . '</span></li>';
    }
    return $o . '</ul>';
}

function stats_body_html(): string
{
    $t = stats_totals();
    $tiles = [
        ['Items', $t['items']], ['Total qty', $t['qty']], ['Locations', $t['locations']],
        ['Categories', $t['categories']], ['This week', $t['week']], ['This month', $t['month']],
    ];
    $o = '<div class="tiles">';
    foreach ($tiles as [$k, $v]) $o .= '<div class="tile"><b>' . number_format($v) . '</b><span>' . h($k) . '</span></div>';
    $o .= '<a class="tile tile-low' . ($t['low'] > 0 ? ' has-low' : '') . '" href="/?stock=low" data-stock="low"><b>' . number_format($t['low']) . '</b><span>Running low</span></a>';
    $o .= '</div>';
    $days = stats_added_series('day');
    $months = stats_added_series('month');
    $o .= '<section class="st-card st-wide">'
        . '<input type="radio" class="vh" name="strange" id="rg-d" value="d" checked><input type="radio" class="vh" name="strange" id="rg-m" value="m">'
        . '<div class="st-head"><h3>Items added</h3>'
        . '<div class="seg" role="group" aria-label="Range"><label for="rg-d">30 days</label><label for="rg-m">12 months</label></div></div>'
        . '<div class="chart-d">' . svg_bars($days, 'Items added per day, last 30 days') . '<p class="st-sub">' . array_sum(array_column($days, 'n')) . ' in the last 30 days · peak ' . max(array_column($days, 'n')) . '/day</p></div>'
        . '<div class="chart-m">' . svg_bars($months, 'Items added per month, last 12 months') . '<p class="st-sub">' . array_sum(array_column($months, 'n')) . ' in the last 12 months · peak ' . max(array_column($months, 'n')) . '/month</p></div>'
        . '</section>';
    foreach (KINDS as $kind) {
        $k = tax($kind);
        $o .= '<section class="st-card st-' . $kind . '"><div class="st-head"><h3>By ' . h($k['one']) . '</h3><a class="st-link" href="' . $k['path'] . '">All</a></div>'
            . hbars(stats_by($kind, 5), $k['param'], 'No items yet.') . '</section>';
    }
    return $o;
}

function stats_section(array $stats, bool $open): string
{
    if ((int)$stats['items'] === 0) return '';   // the list's own empty state takes over
    return '<details class="stats-box" id="stats"' . ($open ? ' open' : '') . ' data-loaded="' . ($open ? '1' : '0') . '">'
        . '<summary><span class="st-title">' . icon('chart') . ' Overview</span><span class="st-mini">'
        . (int)$stats['items'] . ' items · ' . (int)$stats['qty'] . ' pcs</span><span class="chev" aria-hidden="true"></span></summary>'
        . '<div class="stats-body" id="stats-body">' . ($open ? stats_body_html() : '<p class="st-sub">Loading…</p>') . '</div>'
        . '</details>';
}

/* ---------- pages ---------- */

function new_item_href(array $f): string
{
    $qs = [];
    foreach (['loc', 'cat'] as $k) if (ctype_digit($f[$k] ?? '')) $qs[$k] = $f[$k];
    return '/new' . ($qs ? '?' . http_build_query($qs) : '');
}

function filter_select(string $kind, array $list, string $cur, int $none): string
{
    $t = tax($kind);
    $o = '<label class="field"><span>' . h($t['One']) . '</span><select name="' . $t['param'] . '" id="' . $t['param'] . '">'
       . '<option value="">All ' . h($t['many']) . '</option>';
    foreach ($list as $l) {
        $o .= '<option value="' . (int)$l['id'] . '"' . ((string)$l['id'] === $cur ? ' selected' : '') . '>' . h($l['name']) . ' (' . (int)$l['n'] . ')</option>';
    }
    if ($none > 0 || $cur === 'none') $o .= '<option value="none"' . ($cur === 'none' ? ' selected' : '') . '>' . h($t['none']) . ' (' . $none . ')</option>';
    return $o . '</select></label>';
}

function view_list(string $q, array $f, string $sort, array $res, array $locs, array $cats, array $stats, ?string $toast, bool $statsOpen = true, bool $select = false, bool $all = false): void
{
    page_start('', $select ? 'page-select' : '');
    $f += ['tag' => '', 'stock' => ''];
    $qs = array_filter(['q' => $q, 'loc' => $f['loc'], 'cat' => $f['cat'], 'tag' => $f['tag'], 'stock' => $f['stock'], 'sort' => $sort === 'recent' ? '' : $sort], fn($v) => $v !== '');
    $listHref = '/' . ($qs ? '?' . http_build_query($qs) : '');
    $selHref = '/?' . http_build_query($qs + ['select' => '1']);
    $filtered = $q !== '' || $f['loc'] !== '' || $f['cat'] !== '' || $f['tag'] !== '' || $f['stock'] !== '';
    $tags = tags_all();
    ?>
<header class="top">
  <div class="bar">
    <h1 class="brand"><?= icon('box') ?><span><?= h(APP_NAME) ?></span></h1>
    <span class="stats"><?= (int)$stats['items'] ?> items · <?= (int)$stats['qty'] ?> pcs</span>
    <a class="btn btn-ghost btn-round hide-sm" href="/locations" aria-label="Manage locations &amp; categories" title="Locations &amp; categories"><?= icon('sliders') ?></a>
    <?= menu_html() ?>
    <a class="btn btn-primary btn-round" href="<?= h(new_item_href($f)) ?>" aria-label="Add item"><?= icon('plus') ?></a>
  </div>
  <form class="filters" action="/" method="get" role="search" id="filters">
    <label class="search">
      <?= icon('search') ?>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search names, places, categories, notes" autocomplete="off" autocorrect="off" spellcheck="false" enterkeyhint="search" id="q">
    </label>
    <div class="frow">
      <details class="fsheet" id="fsheet">
        <summary class="btn fbtn" id="filter-open" aria-label="Filter and sort"><?= icon('filter') ?><span>Filter</span><b class="fcount" id="fcount"<?= active_filter_count($f, $sort) ? '' : ' hidden' ?>><?= active_filter_count($f, $sort) ?></b></summary>
        <div class="fpanel" role="dialog" aria-labelledby="fpanel-title">
          <div class="fpanel-head"><h2 id="fpanel-title">Filter &amp; sort</h2><button type="button" class="btn btn-ghost" id="filter-close">Done</button></div>
          <?= filter_select('location', $locs, $f['loc'], $stats['unassigned']) ?>
          <?= filter_select('category', $cats, $f['cat'], $stats['uncategorized']) ?>
          <?php if ($tags || $f['tag'] !== ''): ?>
          <label class="field"><span>Tag</span>
          <select name="tag" id="tag">
            <option value="">All tags</option>
            <?php foreach ($tags as $t): ?>
            <option value="<?= (int)$t['id'] ?>"<?= (string)$t['id'] === $f['tag'] ? ' selected' : '' ?>><?= h($t['name']) ?> (<?= (int)$t['n'] ?>)</option>
            <?php endforeach; ?>
          </select></label>
          <?php endif; ?>
          <label class="field"><span>Stock</span>
          <select name="stock" id="stock">
            <option value="">All items</option>
            <option value="low"<?= $f['stock'] === 'low' ? ' selected' : '' ?>>Running low</option>
          </select></label>
          <label class="field"><span>Sort by</span>
          <select name="sort" id="sort">
            <?php foreach (SORTS as $k => $v): ?>
            <option value="<?= $k ?>"<?= $sort === $k ? ' selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></label>
          <div class="fpanel-actions">
            <a class="btn" href="/" id="filter-clear">Clear all</a>
            <button class="btn btn-primary" id="filter-apply">Show results</button>
          </div>
        </div>
      </details>
      <a class="btn fbtn<?= $select ? ' is-on' : '' ?>" id="select-toggle" href="<?= h($select ? $listHref : $selHref) ?>"<?= $select ? ' aria-pressed="true"' : '' ?>><?= icon($select ? 'x' : 'check') ?><span><?= $select ? 'Done' : 'Select' ?></span></a>
      <?php if ($select): ?><input type="hidden" name="select" value="1"><?php endif; ?>
      <div class="achips" id="achips"><?= active_chips_html($f, $sort, $locs, $cats, $tags) ?></div>
    </div>
  </form>
</header>
<main class="wrap">
  <?= stats_section($stats, $statsOpen) ?>
  <p class="count" id="count"><?= h(count_text($res['total'], $filtered)) ?></p>
  <div class="grid" id="grid" aria-live="polite"><?= cards_html($res['items'], $select, $all) ?></div>
  <div class="empty" id="empty"<?= $res['total'] ? ' hidden' : '' ?>>
    <?= icon('box') ?>
    <p class="empty-title" id="empty-title"><?= $filtered ? 'Nothing matches.' : 'Your catalog is empty.' ?></p>
    <p class="empty-sub" id="empty-sub"><?= $filtered ? 'Try another word or clear the filters.' : 'Snap a photo of something, give it a name and a place. That’s it.' ?></p>
    <a class="btn btn-primary" id="empty-add" href="/new"<?= $filtered ? ' hidden' : '' ?>><?= icon('camera') ?> Add your first item</a>
    <a class="btn" id="empty-clear" href="/"<?= $filtered ? '' : ' hidden' ?>>Clear search &amp; filters</a>
  </div>
  <button class="btn btn-wide" id="more" data-page="1"<?= $res['more'] ? '' : ' hidden' ?>>Show more</button>
  <footer class="foot">
    <a href="/locations">Locations</a>
    <a href="/categories">Categories</a>
    <a href="/export.csv" download>Export CSV</a>
    <?php if (app_password() !== ''): ?>
    <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><button class="linkbtn">Sign out</button></form>
    <?php endif; ?>
  </footer>
</main>
<?php if ($select): ?>
<?= bulk_bar_html($locs, $cats, $tags, $selHref, $selHref . '&all=1') ?>
<?php else: ?>
<a class="fab" href="<?= h(new_item_href($f)) ?>" id="fab" aria-label="Add item"><?= icon('camera') ?></a>
<?php endif; ?>
<?php if ($toast !== null): ?><div class="toast" role="status"><?= h($toast) ?></div><?php endif; ?>
<?php
    page_end();
}

function view_item(array $it, array $photos = [], int $cur = 0, array $history = [], bool $historyMore = false): void
{
    page_start($it['name'], 'page-item');
    $id = (int)$it['id'];
    $n = count($photos);
    $cur = $n ? min($cur, $n - 1) : 0;
    $tok = h(csrf_token());
    ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="/" data-back aria-label="Back"><?= icon('back') ?></a>
    <h1 class="title-sm">Item</h1>
    <a class="btn btn-ghost" href="/item/<?= $id ?>/edit" aria-label="Edit item"><?= icon('edit') ?><span class="hide-xs">Edit</span></a>
  </div>
</header>
<main class="wrap narrow">
  <?php if ($n): $ph = $photos[$cur]; ?>
  <div class="gallery" id="gallery" data-count="<?= $n ?>" data-index="<?= $cur ?>">
    <a class="hero" id="hero" href="/media/full/<?= h($ph['file']) ?>" target="_blank" rel="noopener">
      <img id="hero-img" src="/media/full/<?= h($ph['file']) ?>" alt="<?= h($it['name']) ?> – photo <?= $cur + 1 ?> of <?= $n ?>" decoding="async">
    </a>
    <?php if ($n > 1): ?>
    <a class="gal-nav gal-prev" id="gal-prev" href="/item/<?= $id ?>?p=<?= ($cur - 1 + $n) % $n ?>" aria-label="Previous photo"><?= icon('back') ?></a>
    <a class="gal-nav gal-next" id="gal-next" href="/item/<?= $id ?>?p=<?= ($cur + 1) % $n ?>" aria-label="Next photo"><?= icon('back') ?></a>
    <span class="gal-count" id="gal-count" aria-live="polite"><?= $cur + 1 ?> / <?= $n ?></span>
    <?php endif; ?>
  </div>
  <?php if ($n > 1): ?>
  <nav class="thumbs" aria-label="Photos">
    <?php foreach ($photos as $i => $p): ?>
    <a href="/item/<?= $id ?>?p=<?= $i ?>" data-i="<?= $i ?>" data-full="/media/full/<?= h($p['file']) ?>"<?= $i === $cur ? ' aria-current="true"' : '' ?> aria-label="Photo <?= $i + 1 ?><?= $i === 0 ? ' (cover)' : '' ?>"><img src="/media/thumb/<?= h($p['file']) ?>" alt="" loading="lazy"></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <?php else: ?>
  <a class="hero hero-empty" href="/item/<?= $id ?>/edit"><?= icon('camera') ?><span>Add a photo</span></a>
  <?php endif; ?>
  <h2 class="item-name"><?= h($it['name']) ?></h2>
  <div class="item-meta">
    <?= chip_html('location', $it['location_id'] !== null ? (int)$it['location_id'] : null, $it['location'], true) ?>
    <?= chip_html('category', $it['category_id'] !== null ? (int)$it['category_id'] : null, $it['category'], true) ?>
  </div>
  <?php if (($it['min_qty'] ?? null) !== null): ?>
  <div class="lowbox<?= is_low($it) ? ' is-low' : '' ?>" id="lowbox" data-min="<?= (int)$it['min_qty'] ?>"><?= low_badge_html() ?><span class="hint">Alert at <?= (int)$it['min_qty'] ?> or fewer</span></div>
  <?php endif; ?>
  <div class="qty qty-lg" data-id="<?= $id ?>">
    <button type="button" class="qbtn" data-delta="-1" aria-label="Decrease"<?= (int)$it['quantity'] <= 0 ? ' disabled' : '' ?>><?= icon('minus') ?></button>
    <output class="qval"><?= (int)$it['quantity'] ?></output>
    <button type="button" class="qbtn" data-delta="1" aria-label="Increase"><?= icon('plus') ?></button>
  </div>
  <?php if (!empty($it['tags'])): ?>
  <ul class="tags" aria-label="Tags">
    <?php foreach ($it['tags'] as $t): ?><li><a class="tagchip" href="/?tag=<?= (int)$t['id'] ?>">#<?= h($t['name']) ?></a></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php $facts = array_filter(['Value' => fmt_money($it['value_cents'] ?? null), 'Purchased' => (string)($it['purchase_date'] ?? ''), 'Serial no.' => (string)($it['serial'] ?? '')], fn($v) => $v !== ''); ?>
  <?php if ($facts): ?>
  <dl class="facts" id="facts">
    <?php foreach ($facts as $k => $v): ?><div><dt><?= h($k) ?></dt><dd<?= $k === 'Serial no.' ? ' class="mono"' : '' ?>><?= h($v) ?></dd></div><?php endforeach; ?>
  </dl>
  <?php endif; ?>
  <?php if (trim($it['notes']) !== ''): ?>
  <div class="notes"><?= nl2br(h($it['notes'])) ?></div>
  <?php endif; ?>
  <details class="photos-box" id="photos"<?= $n === 0 ? '' : '' ?>>
    <summary><?= icon('image') ?> Photos <span class="muted-n"><?= $n ?>/<?= MAX_PHOTOS ?></span></summary>
    <?php if ($n): ?>
    <ul class="pm-list">
      <?php foreach ($photos as $i => $p): $act = '/item/' . $id . '/photos/' . $p['id'] . '/'; ?>
      <li class="pm<?= $i === 0 ? ' is-cover' : '' ?>">
        <img src="/media/thumb/<?= h($p['file']) ?>" alt="Photo <?= $i + 1 ?>" loading="lazy">
        <?php if ($i === 0): ?><span class="pm-badge">Cover</span><?php endif; ?>
        <div class="pm-btns">
          <form method="post" action="<?= $act ?>left"><input type="hidden" name="csrf" value="<?= $tok ?>"><button class="pbtn" aria-label="Move photo <?= $i + 1 ?> left"<?= $i === 0 ? ' disabled' : '' ?>><?= icon('back') ?></button></form>
          <form method="post" action="<?= $act ?>cover"><input type="hidden" name="csrf" value="<?= $tok ?>"><button class="pbtn" aria-label="Make photo <?= $i + 1 ?> the cover"<?= $i === 0 ? ' disabled' : '' ?>><?= icon('star') ?></button></form>
          <form method="post" action="<?= $act ?>right"><input type="hidden" name="csrf" value="<?= $tok ?>"><button class="pbtn pbtn-r" aria-label="Move photo <?= $i + 1 ?> right"<?= $i === $n - 1 ? ' disabled' : '' ?>><?= icon('back') ?></button></form>
          <form method="post" action="<?= $act ?>delete" data-confirm="Delete this photo?"><input type="hidden" name="csrf" value="<?= $tok ?>"><button class="pbtn pbtn-del" aria-label="Delete photo <?= $i + 1 ?>"><?= icon('trash') ?></button></form>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($n < MAX_PHOTOS): ?>
    <form method="post" action="/item/<?= $id ?>/photos" enctype="multipart/form-data" class="pm-add" id="photoadd">
      <input type="hidden" name="csrf" value="<?= $tok ?>">
      <label class="btn"><input type="file" name="photos[]" accept="image/*" capture="environment" class="file" id="photoadd-cam"><?= icon('camera') ?> Take photo</label>
      <label class="btn"><input type="file" name="photos[]" accept="image/*" multiple class="file" id="photoadd-lib"><?= icon('image') ?> Add from library</label>
      <noscript><button class="btn btn-primary">Upload</button></noscript>
      <p class="hint pm-status" id="photoadd-status" role="status" hidden></p>
    </form>
    <?php endif; ?>
  </details>
  <p class="item-links"><a class="btn btn-sm" href="/item/<?= $id ?>/label"><?= icon('qr') ?> QR label</a></p>
  <?php if ($history): ?>
  <section class="history" id="history" aria-labelledby="hist-title">
    <h2 id="hist-title"><?= icon('clock') ?> History</h2>
    <?= activity_items_html($history, false) ?>
    <?php if ($historyMore): ?><a class="btn btn-sm" href="/activity?item=<?= $id ?>" id="hist-more">Full history</a><?php endif; ?>
  </section>
  <?php endif; ?>
  <p class="times">Added <?= h(fmt_time($it['created_at'])) ?><?php if ($it['updated_at'] !== $it['created_at']): ?> · Updated <?= h(fmt_time($it['updated_at'])) ?><?php endif; ?></p>
</main>
<div class="actionbar">
  <div class="actionbar-in">
    <form method="post" action="/item/<?= $id ?>/delete" data-confirm="Delete “<?= h($it['name']) ?>”? This cannot be undone.">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button class="btn btn-danger" aria-label="Delete item"><?= icon('trash') ?> Delete</button>
    </form>
    <a class="btn btn-primary" href="/item/<?= $id ?>/edit"><?= icon('edit') ?> Edit</a>
  </div>
</div>
<?php
    page_end();
}

/** Dropdown + inline "＋ New …" for a kind (ids: locsel/locadd/newloc/newlocname/newlocok and cat… equivalents). */
function picker_html(string $kind, array $list, ?int $selected, string $newVal): string
{
    $t = tax($kind);
    $p = $t['param'];
    $field = $t['col'];
    $newField = 'new_' . $kind;
    $o = '<div class="field picker" data-kind="' . $kind . '" data-prefix="' . $p . '" data-endpoint="' . $t['path'] . '">'
       . '<span class="field-head"><label for="' . $p . 'sel">' . h($t['One']) . '</label><a href="' . $t['path'] . '" class="field-link">Manage</a></span>'
       . '<div class="locrow"><select name="' . $field . '" id="' . $p . 'sel" class="locsel">'
       . '<option value="">— ' . h($t['none']) . ' —</option>';
    foreach ($list as $l) {
        $o .= '<option value="' . (int)$l['id'] . '"' . ((int)$l['id'] === $selected ? ' selected' : '') . '>' . h($l['name']) . ($l['is_default'] ? ' ★' : '') . '</option>';
    }
    $o .= '<option value="__new"' . ($newVal !== '' ? ' selected' : '') . '>＋ New ' . h($t['one']) . '…</option></select>'
       . '<button type="button" class="btn btn-square" id="' . $p . 'add" aria-label="New ' . h($t['one']) . '" title="New ' . h($t['one']) . '">' . icon('plus') . '</button></div>'
       . '<div class="newloc" id="new' . $p . '"' . ($newVal === '' ? ' hidden' : '') . '>'
       . '<input type="text" name="' . $newField . '" id="new' . $p . 'name" value="' . h($newVal) . '" maxlength="120" autocomplete="off" autocorrect="off" enterkeyhint="done" placeholder="New ' . h($t['one']) . ', e.g. ' . h($t['example']) . '" aria-label="New ' . h($t['one']) . ' name">'
       . '<button type="button" class="btn btn-primary" id="new' . $p . 'ok">Add</button></div>'
       . '<noscript><input type="text" name="' . $newField . '" maxlength="120" placeholder="…or type a new ' . h($t['one']) . '" class="ns-newloc"></noscript>'
       . '</div>';
    return $o;
}

function view_form(?array $it, array $vals, array $locs, array $cats, ?string $error, ?string $toast = null): void
{
    $isNew = $it === null;
    page_start($isNew ? 'Add item' : 'Edit ' . $it['name'], 'page-form');
    $hasImg = !$isNew && valid_image_name($it['image']);
    ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="<?= $isNew ? '/' : '/item/' . (int)$it['id'] ?>" data-back aria-label="Cancel"><?= icon('x') ?></a>
    <h1 class="title-sm"><?= $isNew ? 'New item' : 'Edit item' ?></h1>
    <span class="spacer"></span>
  </div>
</header>
<main class="wrap narrow">
  <form method="post" action="/save" enctype="multipart/form-data" id="itemform" class="itemform" novalidate>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int)$it['id'] ?>"><?php endif; ?>
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_UPLOAD ?>">

    <div class="err" id="err" role="alert"<?= $error === null ? ' hidden' : '' ?>><?= h($error) ?></div>

    <div class="photo<?= $hasImg ? ' has-img' : '' ?>" id="photo">
      <img id="preview" alt=""<?= $hasImg ? ' src="/media/thumb/' . h($it['image']) . '"' : ' hidden' ?>>
      <label class="photo-take">
        <input type="file" name="photo" accept="image/*" capture="environment" class="file">
        <?= icon('camera') ?><span><?= $hasImg ? 'Retake photo' : 'Take photo' ?></span>
      </label>
      <label class="photo-lib">
        <input type="file" name="photo2" accept="image/*" class="file">
        <?= icon('image') ?><span>Library</span>
      </label>
      <span class="photo-status" id="pstatus" hidden></span>
    </div>
    <?php if ($hasImg): ?>
    <label class="check"><input type="checkbox" name="remove_image" value="1" id="rmimg"> <?= icon('trash') ?> Remove photo</label>
    <?php endif; ?>

    <label class="field">
      <span>Name</span>
      <input type="text" name="name" id="name" value="<?= h($vals['name']) ?>" required maxlength="200" autocomplete="off" autocapitalize="sentences" enterkeyhint="next" placeholder="e.g. Cordless drill">
    </label>
    <?= picker_html('location', $locs, $vals['location_id'], $vals['new_location']) ?>
    <?= picker_html('category', $cats, $vals['category_id'], $vals['new_category']) ?>
    <div class="field">
      <span>Quantity</span>
      <div class="stepper">
        <button type="button" class="qbtn" data-step="-1" aria-label="Decrease"><?= icon('minus') ?></button>
        <input type="number" name="quantity" value="<?= (int)$vals['quantity'] ?>" min="0" max="1000000" step="1" inputmode="numeric" pattern="[0-9]*" aria-label="Quantity">
        <button type="button" class="qbtn" data-step="1" aria-label="Increase"><?= icon('plus') ?></button>
      </div>
    </div>
    <label class="field">
      <span>Running low at <em>optional – alert when the quantity is this or less</em></span>
      <input type="number" name="min_qty" id="min_qty" value="<?= h((string)($vals['min_qty'] ?? '')) ?>" min="0" max="1000000" step="1" inputmode="numeric" pattern="[0-9]*" placeholder="e.g. 2">
    </label>
    <label class="field">
      <span>Notes <em>optional</em></span>
      <textarea name="notes" rows="3" maxlength="5000" placeholder="Model, where you bought it…"><?= h($vals['notes']) ?></textarea>
    </label>
    <label class="field">
      <span>Tags <em>optional, comma-separated</em></span>
      <input type="text" name="tags" id="tags" value="<?= h($vals['tags'] ?? '') ?>" maxlength="2000" autocomplete="off" autocapitalize="none" list="tag-list" placeholder="e.g. garage, warranty">
    </label>
    <datalist id="tag-list"><?php foreach (tags_all() as $t): ?><option value="<?= h($t['name']) ?>"></option><?php endforeach; ?></datalist>
    <details class="more-fields"<?= ($vals['value'] ?? '') !== '' || ($vals['purchase_date'] ?? '') !== '' || ($vals['serial'] ?? '') !== '' ? ' open' : '' ?>>
      <summary>Value, purchase date &amp; serial</summary>
      <div class="more-grid">
        <label class="field">
          <span>Value <em><?= h(cfg()['currency']) ?></em></span>
          <input type="text" name="value" id="value" value="<?= h($vals['value'] ?? '') ?>" inputmode="decimal" maxlength="32" autocomplete="off" placeholder="0.00">
        </label>
        <label class="field">
          <span>Purchase date</span>
          <input type="date" name="purchase_date" id="purchase_date" value="<?= h($vals['purchase_date'] ?? '') ?>" max="<?= date('Y-m-d', app_time()) ?>">
        </label>
        <label class="field">
          <span>Serial number</span>
          <input type="text" name="serial" id="serial" value="<?= h($vals['serial'] ?? '') ?>" maxlength="100" autocomplete="off" autocapitalize="characters" spellcheck="false">
        </label>
      </div>
    </details>

    <div class="actionbar actionbar-form">
      <div class="actionbar-in<?= $isNew ? '' : ' one' ?>">
      <?php if ($isNew): ?>
      <button class="btn" name="after" value="another" id="save-another">Save &amp; new</button>
      <?php endif; ?>
      <button class="btn btn-primary" name="after" value="view" id="save">Save</button>
      </div>
    </div>
  </form>
</main>
<?php if ($toast !== null): ?><div class="toast" role="status"><?= h($toast) ?></div><?php endif; ?>
<?php
    page_end();
}

function manage_tabs(string $kind): string
{
    $o = '<nav class="tabs" aria-label="Manage">';
    foreach (KINDS as $k) {
        $t = tax($k);
        $o .= '<a href="' . $t['path'] . '"' . ($k === $kind ? ' class="on" aria-current="page"' : '') . '>' . icon($t['icon']) . ' ' . h($t['Many']) . '</a>';
    }
    return $o . '</nav>';
}

function view_manage(string $kind, array $list, int $unassigned, ?string $error, ?string $toast): void
{
    $t = tax($kind);
    page_start($t['Many'], 'page-locs');
    $tok = h(csrf_token());
    $base = $t['path'];
    ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="/" aria-label="Back"><?= icon('back') ?></a>
    <h1 class="title-sm">Manage</h1>
    <?= menu_html() ?>
  </div>
  <?= manage_tabs($kind) ?>
</header>
<main class="wrap narrow">
  <form method="post" action="<?= $base ?>" class="addloc" id="addloc">
    <input type="hidden" name="csrf" value="<?= $tok ?>">
    <input type="text" name="name" required maxlength="120" autocomplete="off" autocorrect="off" enterkeyhint="done" placeholder="New <?= h($t['one']) ?>, e.g. <?= h($t['example']) ?>" aria-label="New <?= h($t['one']) ?> name">
    <button class="btn btn-primary"><?= icon('plus') ?> Add</button>
  </form>
  <div class="err" role="alert"<?= $error === null ? ' hidden' : '' ?>><?= h($error) ?></div>
  <?php if ($kind === 'location'): ?><p class="item-links"><a class="btn btn-sm" href="/labels"><?= icon('qr') ?> Print QR labels for all locations</a></p><?php endif; ?>
  <p class="hint">★ The default <?= h($t['one']) ?> is preselected when you add a new item. Tap ★ again to clear it.</p>

  <?php if (!$list): ?>
  <div class="empty"><?= icon($t['icon']) ?><p>No <?= h($t['many']) ?> yet. Add your first one above.</p></div>
  <?php else: ?>
  <ul class="loclist">
    <?php foreach ($list as $l): $id = (int)$l['id']; $n = (int)$l['n']; ?>
    <li class="loc<?= $l['is_default'] ? ' is-default' : '' ?>" id="<?= $t['param'] ?>-<?= $id ?>">
      <div class="loc-main">
        <form method="post" action="<?= $base ?>/<?= $id ?>/default" class="loc-star-form">
          <input type="hidden" name="csrf" value="<?= $tok ?>">
          <input type="hidden" name="on" value="<?= $l['is_default'] ? '0' : '1' ?>">
          <button class="loc-star" aria-label="<?= $l['is_default'] ? 'Unset default' : 'Set as default' ?>" title="<?= $l['is_default'] ? 'Default – tap to unset' : 'Set as default' ?>"><?= icon('star') ?></button>
        </form>
        <a class="loc-name" href="/?<?= $t['param'] ?>=<?= $id ?>"><?= h($l['name']) ?><?php if ($l['is_default']): ?> <span class="badge">Default</span><?php endif; ?></a>
        <a class="loc-count" href="/?<?= $t['param'] ?>=<?= $id ?>"><?= $n ?> item<?= $n === 1 ? '' : 's' ?></a>
      </div>
      <details class="loc-more">
        <summary><?= icon('edit') ?> Edit</summary>
        <div class="loc-actions">
          <form method="post" action="<?= $base ?>/<?= $id ?>/rename" class="loc-rename">
            <input type="hidden" name="csrf" value="<?= $tok ?>">
            <input type="text" name="name" value="<?= h($l['name']) ?>" required maxlength="120" autocomplete="off" autocorrect="off" aria-label="Rename <?= h($t['one']) ?>">
            <button class="btn">Rename</button>
          </form>
          <div class="loc-btns">
            <form method="post" action="<?= $base ?>/<?= $id ?>/default">
              <input type="hidden" name="csrf" value="<?= $tok ?>">
              <input type="hidden" name="on" value="<?= $l['is_default'] ? '0' : '1' ?>">
              <button class="btn"><?= icon('star') ?> <?= $l['is_default'] ? 'Unset default' : 'Set as default' ?></button>
            </form>
            <?php if ($kind === 'location'): ?><a class="btn" href="/labels?loc=<?= $id ?>"><?= icon('qr') ?> Label</a><?php endif; ?>
            <?php if ($n > 0): ?>
            <a class="btn btn-danger" href="<?= $base ?>/<?= $id ?>/delete"><?= icon('trash') ?> Delete…</a>
            <?php else: ?>
            <form method="post" action="<?= $base ?>/<?= $id ?>/delete" data-confirm="Delete <?= h($t['one']) ?> “<?= h($l['name']) ?>”?">
              <input type="hidden" name="csrf" value="<?= $tok ?>">
              <input type="hidden" name="action" value="unassign">
              <button class="btn btn-danger"><?= icon('trash') ?> Delete</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </details>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($unassigned > 0): ?>
  <p class="hint"><a href="/?<?= $t['param'] ?>=none"><?= $unassigned ?> item<?= $unassigned === 1 ? '' : 's' ?> without a <?= h($t['one']) ?> →</a></p>
  <?php endif; ?>
</main>
<?php if ($toast !== null): ?><div class="toast" role="status"><?= h($toast) ?></div><?php endif; ?>
<?php
    page_end();
}

function view_tax_delete(string $kind, array $entry, array $others): void
{
    $t = tax($kind);
    page_start('Delete ' . $entry['name'], 'page-locs');
    $n = (int)$entry['n'];
    ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="<?= $t['path'] ?>" aria-label="Back"><?= icon('back') ?></a>
    <h1 class="title-sm">Delete <?= h($t['one']) ?></h1>
    <span class="spacer"></span>
  </div>
</header>
<main class="wrap narrow">
  <h2 class="item-name">“<?= h($entry['name']) ?>”</h2>
  <p class="lead"><a href="/?<?= $t['param'] ?>=<?= (int)$entry['id'] ?>"><?= $n ?> item<?= $n === 1 ? ' is' : 's are' ?></a> in this <?= h($t['one']) ?>. What should happen to <?= $n === 1 ? 'it' : 'them' ?>?</p>
  <form method="post" action="<?= $t['path'] ?>/<?= (int)$entry['id'] ?>/delete" class="itemform" id="locdel">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php if ($others): ?>
    <label class="opt">
      <input type="radio" name="action" value="move" checked>
      <span><strong>Move items to</strong>
        <select name="target" aria-label="Move to <?= h($t['one']) ?>">
          <?php foreach ($others as $o): ?>
          <option value="<?= (int)$o['id'] ?>"<?= $o['is_default'] ? ' selected' : '' ?>><?= h($o['name']) ?> (<?= (int)$o['n'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </span>
    </label>
    <?php endif; ?>
    <label class="opt">
      <input type="radio" name="action" value="unassign"<?= $others ? '' : ' checked' ?>>
      <span><strong>Leave items without a <?= h($t['one']) ?></strong><small>The items are kept; only the <?= h($t['one']) ?> is removed.</small></span>
    </label>
    <div class="actionbar actionbar-form">
      <div class="actionbar-in">
        <a class="btn" href="<?= $t['path'] ?>">Cancel</a>
        <button class="btn btn-danger-solid"><?= icon('trash') ?> Delete</button>
      </div>
    </div>
  </form>
</main>
<?php
    page_end();
}

function view_login(?string $error): void
{
    page_start('Sign in', 'page-login');
    ?>
<main class="wrap narrow login">
  <div class="brand brand-lg"><?= icon('box') ?><span><?= h(APP_NAME) ?></span></div>
  <p class="tagline"><?= h(ucfirst(APP_TAGLINE)) ?></p>
  <form method="post" action="/login" class="itemform">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php if ($error): ?><div class="err" role="alert"><?= h($error) ?></div><?php endif; ?>
    <label class="field"><span>Password</span><input type="password" name="password" autocomplete="current-password" required autofocus enterkeyhint="go"></label>
    <button class="btn btn-primary btn-wide">Sign in</button>
  </form>
</main>
<?php
    page_end();
}

function view_error(int $code, string $msg): void
{
    http_response_code($code);
    page_start('Error');
    ?>
<main class="wrap narrow login">
  <div class="empty"><?= icon('box') ?><p><?= h($msg) ?></p><a class="btn btn-primary" href="/">Back to catalog</a></div>
</main>
<?php
    page_end();
}

/* ---------- QR labels ---------- */

/**
 * Print-friendly sheet of QR labels.
 * @param array<int,array{title:string,sub:string,path:string}> $labels
 */
const ACTIVITY_FIELDS = ['name' => 'Name', 'category' => 'Category', 'notes' => 'Notes', 'value' => 'Value', 'purchase_date' => 'Purchase date', 'serial' => 'Serial no.', 'tags' => 'Tags', 'min_qty' => 'Running low at'];

/** One activity entry as HTML (already escaped). */
function activity_html(array $e): string
{
    $d = $e['details'];
    $q = fn($v) => ($v === '' || $v === null) ? '<i>empty</i>' : '“' . h((string)$v) . '”';
    $none = fn($v) => (string)$v === '' ? '<i>none</i>' : '<b>' . h((string)$v) . '</b>';
    switch ($e['action']) {
        case 'created':
            $t = 'Created' . (!empty($d['location']) ? ' in <b>' . h($d['location']) . '</b>' : '') . (isset($d['quantity']) ? ' · quantity ' . (int)$d['quantity'] : '') . (isset($d['min_qty']) ? ' · running low at ' . (int)$d['min_qty'] : '')
               . (!empty($d['photo']) ? ' · with photo' : '') . (!empty($d['tags']) ? ' · tags ' . h(implode(', ', $d['tags'])) : '');
            break;
        case 'edited':
            $parts = [];
            foreach (($d['changes'] ?? []) as $k => $ch) {
                $label = ACTIVITY_FIELDS[$k] ?? ucfirst((string)$k);
                $val = $k === 'value' ? fn($v) => $v === '' ? '<i>empty</i>' : h($v . ' ' . cfg()['currency']) : $q;
                $parts[] = '<li><span class="act-field">' . h($label) . '</span> ' . $val($ch[0] ?? '') . ' → ' . $val($ch[1] ?? '') . '</li>';
            }
            $t = 'Edited' . (!empty($d['reason']) ? ' (' . h($d['reason']) . ')' : '') . '<ul class="act-changes">' . implode('', $parts) . '</ul>';
            break;
        case 'moved':
            $t = 'Moved from ' . $none($d['from'] ?? '') . ' to ' . $none($d['to'] ?? '') . (!empty($d['reason']) ? ' (' . h($d['reason']) . ')' : '');
            break;
        case 'quantity':
            $t = 'Quantity <b>' . (int)($d['from'] ?? 0) . '</b> → <b>' . (int)($d['to'] ?? 0) . '</b>';
            break;
        case 'photo':
            $t = ['added' => 'Added a photo', 'removed' => 'Removed the cover photo', 'replaced' => 'Replaced the cover photo', 'cover' => 'Chose a new cover photo',
                  'reordered' => 'Reordered photos', 'deleted' => 'Deleted a photo'][$d['change'] ?? ''] ?? 'Changed photos';
            if (isset($d['count'])) $t .= ' · ' . (int)$d['count'] . ' total';
            break;
        case 'deleted':
            $t = 'Deleted' . (!empty($d['location']) ? ' from <b>' . h($d['location']) . '</b>' : '');
            break;
        default:
            $t = h($e['action']);
    }
    return $t;
}

const ACTIVITY_ICONS = ['created' => 'plus', 'edited' => 'edit', 'moved' => 'pin', 'quantity' => 'box', 'photo' => 'camera', 'deleted' => 'trash'];

function activity_items_html(array $entries, bool $withItem): string
{
    $o = '<ol class="activity">';
    foreach ($entries as $e) {
        $o .= '<li class="act act-' . h($e['action']) . '"><span class="act-ic">' . icon(ACTIVITY_ICONS[$e['action']] ?? 'clock') . '</span><div class="act-body">';
        if ($withItem) {
            $o .= $e['exists'] && $e['item_id'] !== null
                ? '<a class="act-item" href="/item/' . (int)$e['item_id'] . '">' . h($e['item_name']) . '</a>'
                : '<span class="act-item act-gone">' . h($e['item_name']) . '</span>';
        }
        $o .= '<div class="act-text">' . activity_html($e) . '</div><time datetime="' . h(str_replace(' ', 'T', $e['created_at']) . 'Z') . '">' . h(fmt_time($e['created_at'])) . '</time></div></li>';
    }
    return $o . '</ol>';
}

function view_activity(array $entries, int $page, bool $more, ?array $item = null): void
{
    page_start($item ? 'History: ' . $item['name'] : 'Recent activity', 'page-activity');
    $qs = fn(int $p) => ($q = http_build_query(array_filter(['item' => $item ? (int)$item['id'] : null, 'page' => $p > 1 ? $p : null]))) !== '' ? '?' . $q : '';
    ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="<?= $item ? '/item/' . (int)$item['id'] : '/' ?>" data-back aria-label="Back"><?= icon('back') ?></a>
    <h1 class="title-sm"><?= $item ? 'History' : 'Recent activity' ?></h1>
    <span class="spacer"></span>
  </div>
</header>
<main class="wrap narrow" id="activity">
  <?php if ($item): ?><h2 class="item-name"><?= h($item['name']) ?></h2><?php endif; ?>
  <?php if (!$entries): ?>
  <div class="empty"><?= icon('clock') ?><p class="empty-title">No activity yet.</p><p class="empty-sub">Changes to your items show up here.</p></div>
  <?php else: ?>
  <?= activity_items_html($entries, $item === null) ?>
  <nav class="pager" aria-label="Pages">
    <?php if ($page > 1): ?><a class="btn" href="/activity<?= h($qs($page - 1)) ?>" rel="prev">Newer</a><?php endif; ?>
    <?php if ($more): ?><a class="btn" href="/activity<?= h($qs($page + 1)) ?>" rel="next" id="act-older">Older</a><?php endif; ?>
  </nav>
  <?php endif; ?>
</main>
<?php
    page_end();
}

function backup_table_rows(array $current, ?array $incoming = null): string
{
    $labels = ['items' => 'Items', 'locations' => 'Locations', 'categories' => 'Categories', 'tags' => 'Tags', 'item_photos' => 'Photos', 'activity' => 'History entries'];
    $o = '<table class="btable"><thead><tr><th scope="col"></th><th scope="col">Now</th>' . ($incoming !== null ? '<th scope="col">In backup</th>' : '') . '</tr></thead><tbody>';
    foreach ($labels as $t => $l) {
        if (!isset($current[$t]) && !isset($incoming[$t])) continue;
        $o .= '<tr><th scope="row">' . $l . '</th><td>' . (int)($current[$t] ?? 0) . '</td>' . ($incoming !== null ? '<td data-t="' . $t . '"><b>' . (int)($incoming[$t] ?? 0) . '</b></td>' : '') . '</tr>';
    }
    return $o . '</tbody></table>';
}

/** Backup & restore page. $preview (dry run) shows what a restore would do and asks for confirmation. */
function view_backup(array $current, ?string $error = null, ?array $preview = null, ?string $toast = null): void
{
    page_start('Backup & restore', 'page-backup');
    $tok = h(csrf_token());
    ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="/" data-back aria-label="Back"><?= icon('back') ?></a>
    <h1 class="title-sm">Backup &amp; restore</h1>
    <span class="spacer"></span>
  </div>
</header>
<main class="wrap narrow">
  <?php if ($error !== null): ?><div class="err" role="alert" id="backup-err"><?= h($error) ?></div><?php endif; ?>
  <?php if ($preview !== null): ?>
  <section class="panel panel-warn" id="restore-preview" aria-labelledby="rp-title">
    <h2 id="rp-title">Restore this backup?</h2>
    <p>Backup from <b><?= h($preview['created_at'] !== '' ? fmt_time($preview['created_at']) : 'an unknown date') ?></b> (<?= h($preview['driver'] === 'pgsql' ? 'PostgreSQL' : 'SQLite') ?>), with <b><?= h(backup_summary_text($preview['tables'], $preview['photos'])) ?></b>.</p>
    <?= backup_table_rows($current, $preview['tables']) ?>
    <?php foreach ($preview['warnings'] as $w): ?><p class="hint warn">⚠ <?= h($w) ?></p><?php endforeach; ?>
    <p class="hint"><b>Everything currently in the catalog will be replaced.</b> Download a backup first if you might want it back.</p>
    <form method="post" action="/backup/restore/confirm" class="restore-confirm">
      <input type="hidden" name="csrf" value="<?= $tok ?>">
      <input type="hidden" name="token" value="<?= h($preview['token']) ?>">
      <label class="check check-ok"><input type="checkbox" name="confirm" value="1" required id="restore-ok"> Yes, replace my catalog with this backup</label>
      <div class="row-btns">
        <a class="btn" href="/backup">Cancel</a>
        <button class="btn btn-danger-solid" id="restore-go">Restore</button>
      </div>
    </form>
  </section>
  <?php endif; ?>
  <section class="panel" aria-labelledby="bk-title">
    <h2 id="bk-title"><?= icon('download') ?> Download a backup</h2>
    <p class="hint">One zip file with all items, locations, categories, tags<?= isset($current['activity']) ? ', history' : '' ?> and photos. It works with both SQLite and PostgreSQL installs.</p>
    <?= backup_table_rows($current) ?>
    <a class="btn btn-primary btn-block" href="/backup.zip" id="backup-dl" download><?= icon('archive') ?> Download backup (.zip)</a>
  </section>
  <section class="panel" aria-labelledby="rs-title">
    <h2 id="rs-title"><?= icon('archive') ?> Restore from a backup</h2>
    <p class="hint">Choose an Apotheke backup zip. You'll see what it contains before anything changes.</p>
    <form method="post" action="/backup/restore" enctype="multipart/form-data" id="restore-form" class="restore-form">
      <input type="hidden" name="csrf" value="<?= $tok ?>">
      <label class="btn btn-block filepick"><input type="file" name="backup" accept=".zip,application/zip" required id="restore-file" class="file"> <span id="restore-name">Choose backup file…</span></label>
      <button class="btn btn-block" id="restore-check">Check backup</button>
    </form>
    <p class="hint">Large backups can also be restored on the server: <code>php bin/restore.php backup.zip</code>.</p>
  </section>
</main>
<?php if ($toast !== null): ?><div class="toast" role="status"><?= h($toast) ?></div><?php endif; ?>
<?php
    page_end();
}

function view_labels(string $heading, array $labels, string $back, array $options = []): void
{
    page_start('Labels', 'page-labels');
    ?>
<header class="top top-simple no-print">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="<?= h($back) ?>" data-back aria-label="Back"><?= icon('back') ?></a>
    <h1 class="title-sm">QR labels</h1>
    <button type="button" class="btn btn-primary" id="print" data-print><?= icon('printer') ?><span class="hide-xs">Print</span></button>
  </div>
</header>
<main class="wrap labels-wrap">
  <div class="no-print labels-intro">
    <h2 class="item-name"><?= h($heading) ?></h2>
    <p class="hint">Scan a label with the phone camera to open <?= $options ? 'the matching list or item' : 'it' ?> in Apotheke. Links point to <b><?= h(public_url('')) ?></b><?= cfg()['public_url'] === '' ? ' (set <code>PUBLIC_URL</code> to change)' : '' ?>.</p>
    <?php if ($options): ?><nav class="label-opts" aria-label="Label sets"><?php foreach ($options as $href => $txt): ?><a class="achip" href="<?= h($href) ?>"><?= h($txt) ?></a><?php endforeach; ?></nav><?php endif; ?>
  </div>
  <?php if (!$labels): ?>
  <div class="empty"><?= icon('qr') ?><p class="empty-title">Nothing to print yet.</p></div>
  <?php else: ?>
  <div class="labels" id="labels">
    <?php foreach ($labels as $l): $url = public_url($l['path']); ?>
    <figure class="label" data-url="<?= h($url) ?>">
      <?= qr_svg($url, 'QR code for ' . $l['title']) ?>
      <figcaption><b><?= h($l['title']) ?></b><?php if ($l['sub'] !== ''): ?><span><?= h($l['sub']) ?></span><?php endif; ?><small><?= h(preg_replace('#^https?://#', '', $url)) ?></small></figcaption>
    </figure>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</main>
<?php
    page_end();
}
