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
        'star'   => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
        'move'   => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'chart'  => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'tag'    => '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><circle cx="8" cy="8" r="1.6"/>',
        'sliders'=> '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
    ];
    return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ($p[$n] ?? '') . '</svg>';
}

/* ---------- layout ---------- */

function page_start(string $title, string $bodyClass = ''): void
{
    $t = $title === '' ? APP_NAME . ' – ' . APP_TAGLINE : $title . ' · ' . APP_NAME;
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($t) ?></title>
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f5f5f7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#111113" media="(prefers-color-scheme: dark)">
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

function card_html(array $it): string
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
    return '<article class="card" data-id="' . $id . '">'
        . '<a class="card-img" href="/item/' . $id . '" aria-label="' . h($it['name']) . '">' . $img . '</a>'
        . '<div class="card-body">'
        . '<a class="card-title" href="/item/' . $id . '">' . h($it['name']) . '</a>'
        . '<div class="chips">' . $chips . '</div>'
        . '<div class="qty" data-id="' . $id . '">'
        . '<button type="button" class="qbtn" data-delta="-1" aria-label="Decrease"' . ($q <= 0 ? ' disabled' : '') . '>' . icon('minus') . '</button>'
        . '<output class="qval">' . $q . '</output>'
        . '<button type="button" class="qbtn" data-delta="1" aria-label="Increase">' . icon('plus') . '</button>'
        . '</div></div></article>';
}

function cards_html(array $items): string
{
    return implode('', array_map('card_html', $items));
}

function count_text(int $total, bool $filtered): string
{
    $s = $total . ' item' . ($total === 1 ? '' : 's');
    return $filtered ? $s . ' found' : $s;
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
    $o = '<select name="' . $t['param'] . '" id="' . $t['param'] . '" aria-label="' . h($t['One']) . '">'
       . '<option value="">All ' . h($t['many']) . '</option>';
    foreach ($list as $l) {
        $o .= '<option value="' . (int)$l['id'] . '"' . ((string)$l['id'] === $cur ? ' selected' : '') . '>' . h($l['name']) . ' (' . (int)$l['n'] . ')</option>';
    }
    if ($none > 0 || $cur === 'none') $o .= '<option value="none"' . ($cur === 'none' ? ' selected' : '') . '>' . h($t['none']) . ' (' . $none . ')</option>';
    return $o . '</select>';
}

function view_list(string $q, array $f, string $sort, array $res, array $locs, array $cats, array $stats, ?string $toast, bool $statsOpen = true): void
{
    page_start('');
    $filtered = $q !== '' || $f['loc'] !== '' || $f['cat'] !== '';
    ?>
<header class="top">
  <div class="bar">
    <h1 class="brand"><?= icon('box') ?><span><?= h(APP_NAME) ?></span></h1>
    <span class="stats"><?= (int)$stats['items'] ?> items · <?= (int)$stats['qty'] ?> pcs</span>
    <a class="btn btn-ghost btn-round" href="/locations" aria-label="Manage locations &amp; categories" title="Locations &amp; categories"><?= icon('sliders') ?></a>
    <a class="btn btn-primary btn-round" href="<?= h(new_item_href($f)) ?>" aria-label="Add item"><?= icon('plus') ?></a>
  </div>
  <form class="filters" action="/" method="get" role="search" id="filters">
    <label class="search">
      <?= icon('search') ?>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search names, places, categories, notes" autocomplete="off" autocorrect="off" spellcheck="false" enterkeyhint="search" id="q">
    </label>
    <div class="row row3">
      <?= filter_select('location', $locs, $f['loc'], $stats['unassigned']) ?>
      <?= filter_select('category', $cats, $f['cat'], $stats['uncategorized']) ?>
      <select name="sort" id="sort" aria-label="Sort">
        <?php foreach (['recent' => 'Recent', 'name' => 'Name', 'location' => 'Location', 'category' => 'Category', 'qty' => 'Quantity'] as $k => $v): ?>
        <option value="<?= $k ?>"<?= $sort === $k ? ' selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <noscript><button class="btn">Search</button></noscript>
  </form>
</header>
<main class="wrap">
  <?= stats_section($stats, $statsOpen) ?>
  <p class="count" id="count"><?= h(count_text($res['total'], $filtered)) ?></p>
  <div class="grid" id="grid"><?= cards_html($res['items']) ?></div>
  <div class="empty" id="empty"<?= $res['total'] ? ' hidden' : '' ?>>
    <?= icon('box') ?>
    <p><?= $filtered ? 'Nothing matches.' : 'Your catalog is empty.' ?></p>
    <a class="btn btn-primary" href="/new"><?= icon('camera') ?> Add your first item</a>
  </div>
  <button class="btn btn-wide" id="more" data-page="1"<?= $res['more'] ? '' : ' hidden' ?>>Show more</button>
  <footer class="foot">
    <a href="/locations">Locations</a>
    <a href="/categories">Categories</a>
    <a href="/export.csv">Export CSV</a>
    <?php if (app_password() !== ''): ?>
    <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><button class="linkbtn">Sign out</button></form>
    <?php endif; ?>
  </footer>
</main>
<a class="fab" href="<?= h(new_item_href($f)) ?>" id="fab" aria-label="Add item"><?= icon('camera') ?></a>
<?php if ($toast !== null): ?><div class="toast" role="status"><?= h($toast) ?></div><?php endif; ?>
<?php
    page_end();
}

function view_item(array $it): void
{
    page_start($it['name'], 'page-item');
    $id = (int)$it['id'];
    ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="/" data-back aria-label="Back"><?= icon('back') ?></a>
    <h1 class="title-sm">Item</h1>
    <a class="btn btn-ghost" href="/item/<?= $id ?>/edit"><?= icon('edit') ?> Edit</a>
  </div>
</header>
<main class="wrap narrow">
  <?php if (valid_image_name($it['image'])): ?>
  <a class="hero" href="/media/full/<?= h($it['image']) ?>" target="_blank" rel="noopener">
    <img src="/media/full/<?= h($it['image']) ?>" alt="<?= h($it['name']) ?>" decoding="async">
  </a>
  <?php else: ?>
  <a class="hero hero-empty" href="/item/<?= $id ?>/edit"><?= icon('camera') ?><span>Add a photo</span></a>
  <?php endif; ?>
  <h2 class="item-name"><?= h($it['name']) ?></h2>
  <div class="item-meta">
    <?= chip_html('location', $it['location_id'] !== null ? (int)$it['location_id'] : null, $it['location'], true) ?>
    <?= chip_html('category', $it['category_id'] !== null ? (int)$it['category_id'] : null, $it['category'], true) ?>
  </div>
  <div class="qty qty-lg" data-id="<?= $id ?>">
    <button type="button" class="qbtn" data-delta="-1" aria-label="Decrease"<?= (int)$it['quantity'] <= 0 ? ' disabled' : '' ?>><?= icon('minus') ?></button>
    <output class="qval"><?= (int)$it['quantity'] ?></output>
    <button type="button" class="qbtn" data-delta="1" aria-label="Increase"><?= icon('plus') ?></button>
  </div>
  <?php if (trim($it['notes']) !== ''): ?>
  <div class="notes"><?= nl2br(h($it['notes'])) ?></div>
  <?php endif; ?>
  <p class="times">Added <?= h(fmt_time($it['created_at'])) ?><?php if ($it['updated_at'] !== $it['created_at']): ?> · Updated <?= h(fmt_time($it['updated_at'])) ?><?php endif; ?></p>
  <form method="post" action="/item/<?= $id ?>/delete" data-confirm="Delete “<?= h($it['name']) ?>”? This cannot be undone.">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <button class="btn btn-danger btn-wide"><?= icon('trash') ?> Delete item</button>
  </form>
</main>
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
    <label class="check"><input type="checkbox" name="remove_image" value="1" id="rmimg"> Remove photo</label>
    <?php endif; ?>

    <label class="field">
      <span>Name</span>
      <input type="text" name="name" value="<?= h($vals['name']) ?>" required maxlength="200" autocomplete="off" autocapitalize="sentences" enterkeyhint="next" placeholder="e.g. Cordless drill">
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
      <span>Notes <em>optional</em></span>
      <textarea name="notes" rows="3" maxlength="5000" placeholder="Model, serial, purchase date…"><?= h($vals['notes']) ?></textarea>
    </label>

    <div class="actions">
      <button class="btn btn-primary btn-wide" name="after" value="view" id="save">Save</button>
      <?php if ($isNew): ?>
      <button class="btn btn-wide" name="after" value="another">Save &amp; add another here</button>
      <?php endif; ?>
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
    <span class="spacer"></span>
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
        <summary>Edit</summary>
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
    <button class="btn btn-danger btn-wide"><?= icon('trash') ?> Delete “<?= h($entry['name']) ?>”</button>
    <a class="btn btn-wide" href="<?= $t['path'] ?>">Cancel</a>
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
    <label class="field"><span>Password</span><input type="password" name="password" autocomplete="current-password" required autofocus></label>
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
