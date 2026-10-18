<?php
declare(strict_types=1);

/* ---------- tiny icon set (inline SVG, no requests) ---------- */

function icon(string $n): string
{
    static $p = [
        'plus'   => '<path d="M12 5v14M5 12h14"/>',
        'paperclip' => '<path d="m21 11-8.5 8.5a5 5 0 0 1-7-7L14 4a3.5 3.5 0 0 1 5 5l-8.5 8.5a2 2 0 0 1-3-3L15 7"/>',
        'file'   => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'merge'  => '<path d="M7 4v4a5 5 0 0 0 5 5h0a5 5 0 0 1 5 5v2M17 4v4a5 5 0 0 1-2 4M4 7l3-3 3 3M14 7l3-3 3 3"/>',
        'eye'    => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'minus'  => '<path d="M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'sparkle' => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
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
        'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
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
        'handoff'=> '<path d="M7 11V7a5 5 0 0 1 10 0v4"/><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M12 15v2"/>',
        'undo'   => '<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
        'copy'   => '<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
        'check'  => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'alert'  => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17v.01"/>',
        'lock'   => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'sliders'=> '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
    ];
    return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ($p[$n] ?? '') . '</svg>';
}

/* ---------- layout ---------- */

/**
 * "no-<permission>" for every permission the current role lacks (plus "role-<key>", and "is-guest" for visitors who are
 * not signed in): CSS hides the controls they may not use; the server checks every request anyway.
 */
function body_role_class(): string
{
    try { $u = current_user(); } catch (Throwable) { return ''; }
    if (!$u || $u['role'] === 'admin') return '';
    $c = ['role-' . preg_replace('/[^a-z0-9-]/', '', $u['role'])];
    foreach (array_keys(PERMISSIONS) as $p) if (!user_can($p, $u)) $c[] = 'no-' . $p;
    if ($u['anon']) $c[] = 'is-guest';
    return implode(' ', $c);
}

function page_start(string $title, string $bodyClass = ''): void
{
    $t = $title === '' ? app_name() . ' – ' . app_tagline() : $title . ' · ' . app_name();
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
<meta name="apple-mobile-web-app-title" content="<?= h(app_name()) ?>">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="format-detection" content="telephone=no">
<meta name="description" content="<?= h(app_name() . ', ' . app_tagline()) ?>">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
<link rel="icon" href="<?= h(brand_favicon_svg_url()) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= h(brand_icon_url('180')) ?>">
<link rel="stylesheet" href="<?= h(asset('app.css')) ?>">
<?php if (strcasecmp((string)setting('accent'), ACCENT_DEFAULT) !== 0): ?><link rel="stylesheet" href="/theme.css?v=<?= substr(md5((string)setting('accent')), 0, 8) ?>" id="accent-css">
<?php endif; ?>
<script src="<?= h(asset('theme.js')) ?>"></script>
<script src="<?= h(asset('app.js')) ?>" defer></script>
</head>
<body class="<?= h(trim($bodyClass . ' ' . body_role_class())) ?>">
<?php
}

function page_end(): void
{
    echo undo_bar_html();
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

/** Item page: the location chip, preceded by its parent locations (Garage › Shelf 2 › [Box 1024]). */
function location_trail_html(?int $id, string $name): string
{
    $chip = chip_html('location', $id, $name, true);
    if ($id === null) return $chip;
    $up = location_ancestors($id);
    if (!$up) return $chip;
    $o = '<span class="loctrail" id="loctrail">';
    foreach ($up as $a) {
        $o .= '<a class="chip chip-location chip-lg chip-parent" href="/?loc=' . $a['id'] . '">' . h($a['name']) . '</a><span class="trail-sep" aria-hidden="true">›</span>';
    }
    return $o . $chip . '</span>';
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
        . ($select ? '' : fav_button_html($id, (string)$it['name'], !empty($it['favourite']), '/'))
        . '<div class="card-body">'
        . '<a class="card-title" href="/item/' . $id . '">' . ($it['hl'] ?? h($it['name'])) . '</a>'
        . (!empty($it['snip']) ? '<p class="snip">' . $it['snip'] . '</p>' : '')
        . '<div class="chips">' . $chips . '</div>'
        . ($min !== null ? low_badge_html() : '')
        . lend_badge_html($it)
        . expiry_badge_html($it)
        . '<div class="qty" data-id="' . $id . '">'
        . '<button type="button" class="qbtn" data-delta="-1" aria-label="Decrease"' . ($q <= 0 ? ' disabled' : '') . '>' . icon('minus') . '</button>'
        . '<output class="qval">' . $q . '</output>'
        . '<button type="button" class="qbtn" data-delta="1" aria-label="Increase">' . icon('plus') . '</button>'
        . '</div></div></article>';
}

/** Star toggle (a small POST form, so it works without JavaScript; app.js toggles it in place). */
function fav_button_html(int $id, string $name, bool $on, string $back): string
{
    return '<form class="favf" method="post" action="/item/' . $id . '/fav"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<input type="hidden" name="on" value="' . ($on ? '0' : '1') . '"><input type="hidden" name="back" value="' . h($back) . '">'
        . '<button class="favbtn" aria-pressed="' . ($on ? 'true' : 'false') . '" aria-label="Favourite: ' . h($name) . '" title="Favourite">' . icon('star') . '</button></form>';
}

/** "Recently viewed" strip on the list page (thumbnails, horizontally scrollable). */
function recent_html(array $items): string
{
    if (!$items) return '';
    $o = '<section class="recent" id="recent" aria-labelledby="recent-h"><div class="recent-head"><h2 id="recent-h">' . icon('history') . ' Recently viewed</h2>'
        . '<form method="post" action="/recent/clear"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><button class="btn btn-ghost btn-sm" id="recent-clear">Clear</button></form></div><ol class="recent-row">';
    foreach ($items as $it) {
        $img = valid_image_name($it['image'] ?? null) ? '<img src="/media/thumb/' . h($it['image']) . '" alt="" loading="lazy" decoding="async">' : '<span class="ph">' . icon('box') . '</span>';
        $o .= '<li><a href="/item/' . (int)$it['id'] . '" class="recent-it">' . $img . '<span>' . h($it['name']) . '</span>'
            . (!empty($it['favourite']) ? '<b class="recent-fav" aria-label="Favourite">' . icon('star') . '</b>' : '') . '</a></li>';
    }
    return $o . '</ol></section>';
}

/** "Running low" badge; shown by CSS while the surrounding card/page has .is-low (so +/- can toggle it). */
function low_badge_html(): string
{
    return '<span class="lowbadge" role="status">' . icon('alert') . ' Running low</span>';
}

/** "Expired" / "Expires in 5 days" badge, or ''. */
function expiry_badge_html(array $it): string
{
    $st = expiry_state($it);
    if ($st === '') return '';
    $n = expiry_days((string)$it['expires_on']);
    $txt = $st === 'expired' ? 'Expired' : ($n === 0 ? 'Expires today' : 'Expires in ' . $n . ' day' . ($n === 1 ? '' : 's'));
    return '<span class="expbadge is-' . $st . '">' . icon($st === 'expired' ? 'alert' : 'clock') . ' ' . $txt . '</span>';
}

/** "Lent to X" / "Overdue" badge, or ''. */
function lend_badge_html(array $it): string
{
    if (!is_lent($it)) return '';
    $late = is_overdue($it);
    return '<span class="lendbadge' . ($late ? ' is-overdue' : '') . '">' . icon($late ? 'alert' : 'handoff') . ' '
        . ($late ? 'Overdue' : 'Lent') . ' · ' . h((string)$it['lent_to']) . '</span>';
}

/** The item page's lending box: status + Return, or a form to lend it. */
function lend_section_html(array $it): string
{
    $id = (int)$it['id'];
    $tok = h(csrf_token());
    $today = local_today();
    $form = fn(string $btn) => '<form method="post" action="/item/' . $id . '/lend" class="lendform" id="lendform">'
        . '<input type="hidden" name="csrf" value="' . $tok . '">'
        . '<label class="field"><span>Lent to</span><input type="text" name="lent_to" id="lent_to" required maxlength="100" autocomplete="name" value="' . h((string)($it['lent_to'] ?? '')) . '" placeholder="Name"></label>'
        . '<div class="lendrow"><label class="field"><span>On</span><input type="date" name="lent_at" id="lent_at" max="' . $today . '" value="' . h((string)($it['lent_at'] ?? $today)) . '"></label>'
        . '<label class="field"><span>Due back <em>optional</em></span><input type="date" name="lent_due" id="lent_due" value="' . h((string)($it['lent_to'] !== null ? ($it['lent_due'] ?? '') : default_lend_due())) . '"></label></div>'
        . '<button class="btn btn-primary btn-wide" id="lend-save">' . icon('handoff') . ' ' . $btn . '</button></form>';
    if (!is_lent($it)) {
        return '<details class="lendbox" id="lending"><summary>' . icon('handoff') . ' Lend this item</summary>' . $form('Mark as lent') . '</details>';
    }
    $late = is_overdue($it, $today);
    return '<section class="lendbox is-out' . ($late ? ' is-overdue' : '') . '" id="lending" aria-labelledby="lend-title">'
        . '<h2 id="lend-title">' . icon($late ? 'alert' : 'handoff') . ' ' . ($late ? 'Overdue' : 'Lent out') . '</h2>'
        . '<p class="lendinfo">With <b>' . h((string)$it['lent_to']) . '</b> since ' . h(fmt_date((string)$it['lent_at']))
        . ($it['lent_due'] !== null ? ' · due back <b>' . h(fmt_date((string)$it['lent_due'])) . '</b>' : '') . '</p>'
        . '<form method="post" action="/item/' . $id . '/return"><input type="hidden" name="csrf" value="' . $tok . '">'
        . '<button class="btn btn-primary btn-wide" id="lend-return">' . icon('undo') . ' Mark as returned</button></form>'
        . '<details class="lendedit"><summary>Change</summary>' . $form('Save') . '</details></section>';
}

function view_lent(array $items, string $today): void
{
    page_start('Lent out', 'page-lent');
    $late = count(array_filter($items, fn($it) => is_overdue($it, $today)));
    ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="/" aria-label="Back"><?= icon('back') ?></a>
    <h1 class="title-sm">Lent out</h1>
    <span class="spacer"></span>
    <?= menu_html() ?>
  </div>
</header>
<main class="wrap narrow">
  <p class="lead" id="lent-summary"><?= count($items) ? item_noun(count($items)) . ' out' . ($late ? ' · <b class="late">' . $late . ' overdue</b>' : '') : 'Nothing is lent out right now.' ?></p>
  <?php if ($items): ?>
  <ul class="lentlist" id="lentlist">
    <?php foreach ($items as $it): $o = is_overdue($it, $today); ?>
    <li class="lent<?= $o ? ' is-overdue' : '' ?>">
      <a class="lent-main" href="/item/<?= (int)$it['id'] ?>">
        <span class="lent-img"><?= valid_image_name($it['image'] ?? null) ? '<img src="/media/thumb/' . h($it['image']) . '" alt="" loading="lazy">' : icon('box') ?></span>
        <span class="lent-text"><b><?= h($it['name']) ?></b>
          <span><?= h((string)$it['lent_to']) ?> · since <?= h(fmt_date((string)$it['lent_at'])) ?></span>
          <span class="lent-due"><?= $it['lent_due'] !== null ? ($o ? 'Overdue · was due ' : 'Due ') . h(fmt_date((string)$it['lent_due'])) : 'No due date' ?></span></span>
      </a>
      <form method="post" action="/item/<?= (int)$it['id'] ?>/return"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="back" value="/lent">
        <button class="btn btn-sm" aria-label="Mark <?= h($it['name']) ?> as returned"><?= icon('undo') ?> Returned</button></form>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</main>
<?php
    page_end();
}

function cards_html(array $items, bool $select = false, bool $checked = false): string
{
    return implode('', array_map(fn($it) => card_html($it, $select, $checked), $items));
}

/** Toast after a bulk action. */
function bulk_toast(int $n, string $action): string
{
    $s = item_noun($n);
    return match ($action) {
        'move' => "Moved $s", 'category' => "Set the category of $s", 'tag_add' => "Tagged $s",
        'tag_remove' => "Removed the tag from $s", 'delete' => "Deleted $s", default => "Updated $s",
    };
}

/** Fixed bottom bar of the list's selection mode: count, select all on page, actions sheet. */
function bulk_bar_html(array $locs, array $cats, array $tags, string $back, string $allHref): string
{
    $opts = fn(array $rows, string $none) => '<option value="">' . h($none) . '</option>'
        . implode('', array_map(fn($r) => '<option value="' . (int)$r['id'] . '">' . h(tax_option_label($r)) . '</option>', $rows));
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
        <button class="btn btn-wide" name="action" value="labels" id="bulk-labels"><?= icon('qr') ?> Print QR labels</button>
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
  <p class="lead">Their photos are deleted too. The history keeps a record. <?= undo_enabled() ? 'You can undo this for ' . (int)setting('undo_seconds') . ' seconds afterwards.' : 'This can’t be undone.' ?></p>
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

/** "· showing “drill” for “drlil”" when typo tolerance stepped in. */
function search_fixed_note(array $fixed): string
{
    if (!$fixed) return '';
    return ' · showing ' . implode(', ', array_map(fn($from, $to) => '“' . $to . '” for “' . $from . '”', array_keys($fixed), $fixed));
}

function count_text(int $total, bool $filtered): string
{
    $s = item_noun($total);
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
        . '<a class="menu-item" href="/barcode" role="menuitem">' . icon('search') . ' Find by barcode</a>'
        . (user_can('labels') ? '<a class="menu-item" href="/labels" role="menuitem" id="menu-labels">' . icon('qr') . ' Print QR labels</a>' : '')
        . (user_can('export') ? '<a class="menu-item" href="/export.csv" role="menuitem" id="menu-export">' . icon('download') . ' Export CSV</a>' : '')
        . (user_can('import') ? '<a class="menu-item" href="/import" role="menuitem" id="menu-import">' . icon('archive') . ' Import CSV</a>' : '')
        . '<a class="menu-item" href="/lent" role="menuitem">' . icon('handoff') . ' Lent out</a>'
        . (user_can('stocktake') ? '<a class="menu-item" href="/stocktake" role="menuitem" id="menu-stocktake">' . icon('check') . ' Stocktake</a>' : '')
        . '<a class="menu-item" href="/activity" role="menuitem">' . icon('clock') . ' Recent activity</a>'
        . (user_can('backup') ? '<a class="menu-item" href="/backup" role="menuitem" id="menu-backup">' . icon('archive') . ' Backup &amp; restore</a>' : '')
        . (user_can('settings') ? '<a class="menu-item" href="/settings" role="menuitem" id="menu-settings">' . icon('sliders') . ' Settings</a>'
            : (user_can('users') ? '<a class="menu-item" href="/settings/security" role="menuitem" id="menu-settings">' . icon('sliders') . ' Users &amp; sign-in</a>' : ''));
    $me = current_user();
    if ($me && !$me['guest']) {
        $o .= '<a class="menu-item" href="/account" role="menuitem" id="menu-account">' . icon('lock') . ' <span>' . h($me['username']) . ' <small class="menu-role">' . h(role_name($me['role'])) . '</small></span></a>';
    }
    if ($me && $me['anon']) {
        $o .= '<a class="menu-item" href="/login" role="menuitem" id="menu-login">' . icon('lock') . ' Sign in</a>';
    } elseif (auth_enabled()) {
        $o .= '<form method="post" action="/logout"><input type="hidden" name="csrf" value="' . $tok . '"><button class="menu-item" role="menuitem">' . icon('logout') . ' Sign out</button></form>';
    }
    return $o . '</div></details>';
}

function active_filter_count(array $f, string $sort): int
{
    return count(array_filter(filters_norm($f), fn($v) => $v !== '')) + ($sort !== default_sort() && isset(SORTS[$sort]) ? 1 : 0);
}

/** Removable chips for the active filters (each links to the list without that filter) + "Clear all". */
function active_chips_html(array $f, string $sort, array $locs, array $cats, ?array $tags = null): string
{
    $f = filters_norm($f);
    $name = function (string $kind, array $list, string $v): string {
        if ($v === 'none') return tax($kind)['none'];
        foreach ($list as $l) if ((string)$l['id'] === $v) return (string)($l['path'] ?? $l['name']);
        return '?';
    };
    $base = list_query(is_string($_GET['q'] ?? null) ? $_GET['q'] : '', $f, $sort);
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
    foreach (list_filter_defs() as $k => $d) {
        if ($f[$k] === '') continue;
        $o .= '<a class="achip ' . $d['class'] . '" href="' . h($href($k)) . '" data-clear="' . $k . '" aria-label="Remove ' . h($d['what']) . '">'
            . icon($d['icon']) . '<span>' . h($d['opts'][$f[$k]]) . '</span>' . icon('x') . '</a>';
    }
    if ($sort !== default_sort() && isset(SORTS[$sort])) $o .= '<a class="achip" href="' . h($href('sort')) . '" data-clear="sort" aria-label="Reset sort">Sort: ' . h(SORTS[$sort]) . icon('x') . '</a>';
    if ($o !== '') $o .= '<a class="achip achip-clear" href="/" data-clear="all">Clear all</a>';
    return $o;
}

/* ---------- statistics ---------- */

/** Vertical bar chart as inline SVG (styled from app.css, so CSP-safe and dark/light aware). */
function svg_bars(array $series, string $label): string
{
    $W = 320; $H = 84; $n = max(1, count($series));
    $max = max(1, ...array_map(fn($p) => $p['n'], $series ?: [['n' => 0]]));
    $gap = $n > 20 ? 2 : 6;
    $bw = ($W - $gap * ($n - 1)) / $n;
    // the bars stretch to the card width (preserveAspectRatio none, fixed CSS height); the axis labels are HTML so
    // their text stays at 12px instead of growing with the width
    $out = '<div class="chart-wrap"><svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" role="img" aria-label="' . h($label) . '">';
    foreach ([0.5, 1] as $g) {
        $y = round($H - ($H - 4) * $g, 1);
        $out .= '<line class="grid" vector-effect="non-scaling-stroke" x1="0" x2="' . $W . '" y1="' . $y . '" y2="' . $y . '"/>';
    }
    foreach ($series as $i => $p) {
        $x = round($i * ($bw + $gap), 2);
        $h = $p['n'] > 0 ? max(3, round(($H - 4) * $p['n'] / $max, 1)) : 1.5;
        $out .= '<rect class="' . ($p['n'] > 0 ? 'bar' : 'bar bar-zero') . '" x="' . $x . '" y="' . round($H - $h, 1) . '" width="' . round($bw, 2) . '" height="' . $h . '" rx="' . min(3, $bw / 3) . '">'
              . '<title>' . h($p['label']) . ': ' . $p['n'] . '</title></rect>';
    }
    $out .= '</svg><div class="chart-axis" aria-hidden="true">';
    $ticks = $n > 12 ? [0, (int)floor(($n - 1) / 2), $n - 1] : range(0, $n - 1, $n > 6 ? 2 : 1);
    foreach ($ticks as $i) {
        $anchor = $i === 0 ? 'start' : ($i === $n - 1 && $n > 12 ? 'end' : 'middle');
        $out .= '<span class="ax ax-' . $anchor . '">' . h($series[$i]['label']) . '</span>';
    }
    return $out . '</div></div>';
}

/** Horizontal "top N" bars (HTML rows + tiny SVG bar each); labels filter the list by $param. */
function hbars(array $rows, string $param, string $empty, ?callable $fmt = null): string
{
    $fmt ??= fn(int $v): string => (string)$v;
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
            . '<span class="hb-n">' . h($fmt((int)$r['n'])) . '</span></li>';
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
    $o .= '<a class="tile tile-link tile-low' . ($t['low'] > 0 ? ' has-low' : '') . '" href="/?stock=low" data-fk="stock" data-fv="low"><b>' . number_format($t['low']) . '</b><span>Running low</span></a>';
    $o .= '<a class="tile tile-link tile-exp' . ($t['expiring'] > 0 ? ' has-exp' : '') . '" href="/?exp=soon" data-fk="exp" data-fv="soon" id="tile-expiring"><b>' . number_format($t['expiring']) . '</b><span>Expiring soon</span></a>';
    $o .= '<a class="tile tile-link tile-expired' . ($t['expired'] > 0 ? ' has-expired' : '') . '" href="/?exp=expired" data-fk="exp" data-fv="expired" id="tile-expired"><b>' . number_format($t['expired']) . '</b><span>Expired</span></a>';
    $o .= '</div>';
    $days = stats_added_series('day');
    $months = stats_added_series('month');
    $busy = fn(array $s) => count(array_filter($s, fn($p) => $p['n'] > 0));
    if ($busy($months) < 2 && $busy($days) < 2) {
        // everything was added on one day: a chart with a single bar says nothing
        $o .= '<section class="st-card st-wide" id="st-added"><div class="st-head"><h3>Items added</h3></div><p class="st-empty" id="chart-few">'
            . 'The chart appears once items have been added on more than one day.</p></section>';
    } else $o .= '<section class="st-card st-wide" id="st-added">'
        . '<input type="radio" class="vh" name="strange" id="rg-d" value="d" checked><input type="radio" class="vh" name="strange" id="rg-m" value="m">'
        . '<div class="st-head"><h3>Items added</h3>'
        . '<div class="seg" role="group" aria-label="Range"><label for="rg-d">30 days</label><label for="rg-m">12 months</label></div></div>'
        . '<div class="chart-d">' . svg_bars($days, 'Items added per day, last 30 days') . '<p class="st-sub">' . array_sum(array_column($days, 'n')) . ' in the last 30 days · peak ' . max(array_column($days, 'n')) . '/day</p></div>'
        . '<div class="chart-m">' . svg_bars($months, 'Items added per month, last 12 months') . '<p class="st-sub">' . array_sum(array_column($months, 'n')) . ' in the last 12 months · peak ' . max(array_column($months, 'n')) . '/month</p></div>'
        . '</section>';
    $val = stats_value(5);
    if ($val['priced'] > 0) {
        $money = fn(int $c): string => fmt_money($c);
        $o .= '<section class="st-card st-wide st-value" id="st-value"><div class="st-head"><h3>Value</h3>'
            . '<a class="st-link" href="/?sort=value">Most valuable</a></div>'
            . '<p class="st-total"><b id="value-total">' . h(fmt_money($val['total'])) . '</b> <span class="st-sub">total purchase price of '
            . item_noun($val['priced']) . ' with a price</span></p>'
            . '<div class="st-value-grid"><div><h4>By location</h4>' . hbars($val['by_location'], 'loc', 'No prices yet.', $money) . '</div>'
            . '<div><h4>By category</h4>' . hbars($val['by_category'], 'cat', 'No prices yet.', $money) . '</div></div></section>';
    }
    foreach (KINDS as $kind) {
        $k = tax($kind);
        $o .= '<section class="st-card st-' . $kind . '"><div class="st-head"><h3>By ' . h($k['one']) . '</h3><a class="st-link" href="' . $k['path'] . '">All</a></div>'
            . hbars(stats_by($kind, 5), $k['param'], 'No items yet.')
            . ($kind === 'location' && locations_nested() ? '<p class="st-sub" id="st-rollup">Top-level locations, counting everything inside them</p>' : '')
            . '</section>';
    }
    return $o;
}

function stats_section(array $stats, bool $open): string
{
    if ((int)$stats['items'] === 0) return '';   // the list's own empty state takes over
    return '<details class="stats-box" id="stats"' . ($open ? ' open' : '') . ' data-loaded="' . ($open ? '1' : '0') . '">'
        . '<summary><span class="st-title">' . icon('chart') . ' Overview</span><span class="st-mini">'
        . h(stats_brief((int)$stats['items'], (int)$stats['qty'])) . '</span><span class="chev" aria-hidden="true"></span></summary>'
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
        $o .= '<option value="' . (int)$l['id'] . '"' . ((string)$l['id'] === $cur ? ' selected' : '') . '>' . h(tax_option_label($l)) . ' (' . (int)($l['total'] ?? $l['n']) . ')</option>';
    }
    if ($none > 0 || $cur === 'none') $o .= '<option value="none"' . ($cur === 'none' ? ' selected' : '') . '>' . h($t['none']) . ' (' . $none . ')</option>';
    return $o . '</select></label>';
}

function view_list(string $q, array $f, string $sort, array $res, array $locs, array $cats, array $stats, ?string $toast, bool $statsOpen = true, bool $select = false, bool $all = false): void
{
    page_start('', $select ? 'page-select' : 'page-list');
    $f = filters_norm($f);
    $qs = list_query($q, $f, $sort);
    $listHref = '/' . ($qs ? '?' . http_build_query($qs) : '');
    $selHref = '/?' . http_build_query($qs + ['select' => '1']);
    $filtered = $q !== '' || filters_active($f);
    $tags = tags_all();
    ?>
<header class="top">
  <div class="bar">
    <h1 class="brand"><?= brand_logo(28) ?><span><?= h(app_name()) ?></span></h1>
    <span class="stats"><?= h(stats_brief((int)$stats['items'], (int)$stats['qty'])) ?></span>
    <a class="btn btn-ghost btn-round hide-sm" href="/locations" aria-label="Manage locations &amp; categories" title="Locations &amp; categories"><?= icon('sliders') ?></a>
    <?= menu_html() ?>
    <a class="btn btn-primary btn-round" href="<?= h(new_item_href($f)) ?>" aria-label="Add item"><?= icon('plus') ?></a>
  </div>
  <form class="filters" action="/" method="get" role="search" id="filters">
    <label class="search">
      <?= icon('search') ?>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search items…" aria-label="Search names, places, categories, notes" autocomplete="off" autocorrect="off" spellcheck="false" enterkeyhint="search" id="q">
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
          <?php foreach (list_filter_defs() as $k => $d): ?>
          <label class="field"><span><?= h($d['label']) ?></span>
          <select name="<?= $k ?>" id="<?= $k ?>" data-filter>
            <option value=""><?= h($d['all']) ?></option>
            <?php foreach ($d['opts'] as $v => $lbl): ?><option value="<?= h((string)$v) ?>"<?= $f[$k] === (string)$v ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
          </select></label>
          <?php endforeach; ?>
          <label class="field"><span>Sort by</span>
          <select name="sort" id="sort" data-default="<?= h(default_sort()) ?>">
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
    <?php $curQuery = http_build_query($qs); ?>
    <div class="saved-row" id="saved-row">
      <?php $nFav = fav_count(); if ($nFav || $f['fav'] !== ''): $favOn = $f['fav'] === '1'; ?>
      <a class="achip chip-fav<?= $favOn ? ' is-on' : '' ?>" id="fav-chip" href="<?= h('/' . (($fq = http_build_query(list_query($q, ['fav' => $favOn ? '' : '1'] + $f, $sort))) ? '?' . $fq : '')) ?>"<?= $favOn ? ' aria-pressed="true"' : '' ?>><?= icon('star') ?> Favourites <b><?= $nFav ?></b></a>
      <?php endif; ?>
      <div class="saved-chips" id="saved-chips"><?= saved_filters_html(saved_filters(), $curQuery) ?></div>
      <details class="savefilter" id="savefilter"<?= $curQuery === '' ? ' hidden' : '' ?>>
        <summary class="btn btn-sm" id="savefilter-open"><?= icon('star') ?> Save filter</summary>
        <div class="savefilter-form">
          <input type="text" name="name" id="sf-name" form="sf-form" maxlength="60" required autocomplete="off" enterkeyhint="done" placeholder="Name, e.g. Garage tools" aria-label="Name of the saved filter">
          <button class="btn btn-primary" form="sf-form" id="sf-save">Save</button>
        </div>
      </details>
    </div>
  </form>
  <form method="post" action="/filters" id="sf-form" class="vh-form">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php foreach ($qs as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$v) ?>" data-sf><?php endforeach; ?>
  </form>
</header>
<main class="wrap">
  <?= !$select && !$filtered ? recent_html(recent_items()) : '' ?>
  <?= stats_section($stats, $statsOpen) ?>
  <p class="count" id="count"><?= h(count_text($res['total'], $filtered) . search_fixed_note($res['fixed'] ?? [])) ?></p>
  <div class="grid<?= setting('list_view') === 'compact' ? ' is-compact' : '' ?>" id="grid" aria-live="polite"><?= cards_html($res['items'], $select, $all) ?></div>
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
    <?php if (auth_enabled()): ?>
    <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><button class="linkbtn">Sign out</button></form>
    <?php endif; ?>
    <span class="app-version" title="Apotheke version">v<?= h(app_version()) ?></span>
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

function view_item(array $it, array $photos = [], int $cur = 0, array $history = [], bool $historyMore = false, array $attachments = [], ?string $toast = null): void
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
    <?= fav_button_html($id, (string)$it['name'], !empty($it['favourite']), '/item/' . $id) ?>
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
    <?= location_trail_html($it['location_id'] !== null ? (int)$it['location_id'] : null, (string)$it['location']) ?>
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
  <?php $facts = array_filter(['Price' => fmt_money($it['value_cents'] ?? null), 'Purchased' => fmt_date($it['purchase_date'] ?? ''), 'Expires' => fmt_date($it['expires_on'] ?? ''), 'Serial no.' => (string)($it['serial'] ?? ''), 'Barcode' => (string)($it['barcode'] ?? '')], fn($v) => $v !== ''); ?>
  <?php if ($facts): ?>
  <dl class="facts" id="facts">
    <?php foreach ($facts as $k => $v): ?><div><dt><?= h($k) ?></dt><dd<?= $k === 'Serial no.' ? ' class="mono"' : '' ?>><?= h($v) ?><?= $k === 'Expires' ? ' ' . expiry_badge_html($it) : '' ?></dd></div><?php endforeach; ?>
  </dl>
  <?php endif; ?>
  <?php if (trim($it['notes']) !== ''): ?>
  <div class="notes md" id="notes"><?= md_render($it['notes']) ?></div>
  <?php endif; ?>
  <?= attachments_html($id, $attachments) ?>
  <details class="photos-box" id="photos"<?= $n === 0 ? '' : '' ?>>
    <summary><?= icon('image') ?> Photos <span class="muted-n"><?= $n ?>/<?= max_photos() ?></span></summary>
    <?php if ($n): ?>
    <ul class="pm-list" id="pm-list" data-order="/item/<?= $id ?>/photos/order">
      <?php foreach ($photos as $i => $p): $act = '/item/' . $id . '/photos/' . $p['id'] . '/'; ?>
      <li class="pm<?= $i === 0 ? ' is-cover' : '' ?>" data-id="<?= (int)$p['id'] ?>">
        <img src="/media/thumb/<?= h($p['file']) ?>" alt="Photo <?= $i + 1 ?>" loading="lazy">
        <?php if ($i === 0): ?><span class="pm-badge">Cover</span><?php endif; ?>
        <?php if ($n > 1): ?><span class="pm-drag" role="img" aria-label="Drag to reorder photo <?= $i + 1 ?>" title="Drag to reorder"><?= icon('menu') ?></span><?php endif; ?>
        <a class="pbtn pm-edit" href="/item/<?= $id ?>/photos/<?= (int)$p['id'] ?>/edit" id="pm-edit-<?= $i ?>" aria-label="Crop or rotate photo <?= $i + 1 ?>"><?= icon('edit') ?></a>
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
    <?php if ($n < max_photos()): ?>
    <form method="post" action="/item/<?= $id ?>/photos" enctype="multipart/form-data" class="pm-add" id="photoadd">
      <input type="hidden" name="csrf" value="<?= $tok ?>">
      <label class="btn"><input type="file" name="photos[]" accept="image/*" capture="environment" class="file" id="photoadd-cam"><?= icon('camera') ?> Take photo</label>
      <label class="btn"><input type="file" name="photos[]" accept="image/*" multiple class="file" id="photoadd-lib"><?= icon('image') ?> Add from library</label>
      <noscript><button class="btn btn-primary">Upload</button></noscript>
      <p class="hint pm-status" id="photoadd-status" role="status" hidden></p>
    </form>
    <?php endif; ?>
  </details>
  <?= lend_section_html($it) ?>
  <div class="item-links">
    <a class="btn btn-sm" href="/item/<?= $id ?>/label"><?= icon('qr') ?> QR label</a>
    <form method="post" action="/item/<?= $id ?>/duplicate" class="dupform" id="dupform">
      <input type="hidden" name="csrf" value="<?= $tok ?>">
      <?php if ($n): ?><label class="check"><input type="checkbox" name="photos" value="1" id="dup-photos"> with photos</label><?php endif; ?>
      <button class="btn btn-sm" id="dup-btn"><?= icon('copy') ?> Duplicate</button>
    </form>
    <a class="btn btn-sm" href="/item/<?= $id ?>/merge" id="merge-link"><?= icon('merge') ?> Merge…</a>
  </div>
  <?php if ($history): ?>
  <section class="history" id="history" aria-labelledby="hist-title">
    <h2 id="hist-title"><?= icon('clock') ?> History</h2>
    <?= activity_items_html($history, false) ?>
    <?php if ($historyMore): ?><a class="btn btn-sm" href="/activity?item=<?= $id ?>" id="hist-more">Full history</a><?php endif; ?>
  </section>
  <?php endif; ?>
  <p class="times">Added <?= h(fmt_time($it['created_at'])) ?><?php if ($it['updated_at'] !== $it['created_at']): ?> · Updated <?= h(fmt_time($it['updated_at'])) ?><?php endif; ?></p>
  <section class="danger-zone" id="danger-zone" aria-label="Delete">
    <form method="post" action="/item/<?= $id ?>/delete" data-confirm="Delete “<?= h($it['name']) ?>”?<?= undo_enabled() ? ' You can undo this for ' . (int)setting('undo_seconds') . ' seconds.' : ' This cannot be undone.' ?>">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button class="btn btn-danger" aria-label="Delete item"><?= icon('trash') ?> Delete item</button>
    </form>
  </section>
</main>
<?php if ($toast !== null): ?><div class="toast" role="status"><?= h($toast) ?></div><?php endif; ?>
<div class="actionbar">
  <div class="actionbar-in one">
    <a class="btn btn-primary" href="/item/<?= $id ?>/edit" id="item-edit"><?= icon('edit') ?> Edit</a>
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
    $o = '<div class="field picker half" data-kind="' . $kind . '" data-prefix="' . $p . '" data-endpoint="' . $t['path'] . '">'
       . '<span class="field-head"><label for="' . $p . 'sel">' . h($t['One']) . '</label><a href="' . $t['path'] . '" class="field-link">Manage</a></span>'
       . '<div class="locrow"><select name="' . $field . '" id="' . $p . 'sel" class="locsel">'
       . '<option value="">— ' . h($t['none']) . ' —</option>';
    foreach ($list as $l) {
        $o .= '<option value="' . (int)$l['id'] . '"' . ((int)$l['id'] === $selected ? ' selected' : '') . '>' . h(tax_option_label($l)) . ($l['is_default'] ? ' ★' : '') . '</option>';
    }
    $o .= '<option value="__new"' . ($newVal !== '' ? ' selected' : '') . '>＋ New ' . h($t['one']) . '…</option></select>'
       . '<button type="button" class="btn btn-square" id="' . $p . 'add" aria-label="New ' . h($t['one']) . '" title="New ' . h($t['one']) . '">' . icon('plus') . '</button></div>'
       . '<div class="newloc" id="new' . $p . '"' . ($newVal === '' ? ' hidden' : '') . '>'
       . '<input type="text" name="' . $newField . '" id="new' . $p . 'name" value="' . h($newVal) . '" maxlength="120" autocomplete="off" autocorrect="off" enterkeyhint="done" placeholder="New ' . h($t['one']) . ', e.g. ' . h($t['example']) . '" aria-label="New ' . h($t['one']) . ' name">'
       . '<button type="button" class="btn btn-primary" id="new' . $p . 'ok">Add</button></div>'
       . '<noscript><input type="text" name="' . $newField . '_ns" maxlength="120" placeholder="…or type a new ' . h($t['one']) . '" class="ns-newloc"></noscript>'
       . (array_filter($list, fn($l) => !empty($l['is_default'])) ? '<small class="field-help star-help" id="' . $p . 'star">★ marks the default ' . h($t['one']) . ', preselected for new items.</small>' : '')
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
  <form method="post" action="/save" enctype="multipart/form-data" id="itemform" class="itemform" novalidate<?= autofill_enabled() && user_can('create') ? ' data-autofill="1" data-af-timeout="' . (int)setting('autofill_timeout') . '"' . ($isNew && !empty($vals['af_defaults']) ? ' data-af-defaults="' . h(implode(',', $vals['af_defaults'])) . '"' : '') . (($afMs = autofill_expected_ms()) !== null ? ' data-af-latency="' . $afMs . '"' : '') : '' ?>>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int)$it['id'] ?>"><?php endif; ?>
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= max_upload() ?>">

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
    <?php if (autofill_enabled() && user_can('create')): ?><div class="af-feedback" id="af-feedback" hidden><p class="af-status" id="af-status" role="status" aria-live="polite"></p><div class="af-progress" id="af-progress" role="progressbar" aria-label="Photo suggestions" aria-valuemin="0" aria-valuemax="100" hidden><span class="af-track"><span class="af-bar" id="af-bar"></span></span><span class="af-pct" id="af-pct" aria-hidden="true"></span></div></div><?php endif; ?>
    <?php if ($hasImg): ?>
    <label class="check"><input type="checkbox" name="remove_image" value="1" id="rmimg"> <?= icon('trash') ?> Remove photo</label>
    <?php endif; ?>

    <label class="field">
      <span>Name</span>
      <input type="text" name="name" id="name" value="<?= h($vals['name']) ?>" required maxlength="200" autocomplete="off" autocapitalize="sentences" enterkeyhint="next" placeholder="e.g. Cordless drill">
    </label>
    <?= picker_html('location', $locs, $vals['location_id'], $vals['new_location']) ?>
    <?= picker_html('category', $cats, $vals['category_id'], $vals['new_category']) ?>
    <div class="field half">
      <span>Quantity</span>
      <div class="stepper">
        <button type="button" class="qbtn" data-step="-1" aria-label="Decrease"><?= icon('minus') ?></button>
        <input type="number" name="quantity" value="<?= (int)$vals['quantity'] ?>" min="0" max="1000000" step="1" inputmode="numeric" pattern="[0-9]*" aria-label="Quantity">
        <button type="button" class="qbtn" data-step="1" aria-label="Increase"><?= icon('plus') ?></button>
      </div>
    </div>
    <label class="field half">
      <span>Running low at <em>optional – alert when the quantity is this or less</em></span>
      <input type="number" name="min_qty" id="min_qty" value="<?= h((string)($vals['min_qty'] ?? '')) ?>" min="0" max="1000000" step="1" inputmode="numeric" pattern="[0-9]*" placeholder="e.g. 2">
    </label>
    <label class="field">
      <span>Notes <em>optional</em></span>
      <textarea name="notes" rows="4" maxlength="5000" placeholder="Model, where you bought it…" aria-describedby="notes-help"><?= h($vals['notes']) ?></textarea>
      <small class="field-help" id="notes-help">Markdown: **bold**, *italic*, - lists, 1. steps, `code`, [link](https://…)</small>
    </label>
    <label class="field">
      <span>Tags <em>optional, comma-separated</em></span>
      <input type="text" name="tags" id="tags" value="<?= h($vals['tags'] ?? '') ?>" maxlength="2000" autocomplete="off" autocapitalize="none" list="tag-list" placeholder="e.g. garage, warranty">
    </label>
    <datalist id="tag-list"><?php foreach (tags_all() as $t): ?><option value="<?= h($t['name']) ?>"></option><?php endforeach; ?></datalist>
    <details class="more-fields"<?= ($vals['value'] ?? '') !== '' || ($vals['purchase_date'] ?? '') !== '' || ($vals['expires_on'] ?? '') !== '' || ($vals['serial'] ?? '') !== '' || ($vals['barcode'] ?? '') !== '' ? ' open' : '' ?>>
      <summary>Price, dates, serial &amp; barcode</summary>
      <div class="more-grid">
        <label class="field">
          <span>Purchase price <em><?= h(setting('currency')) ?></em></span>
          <input type="text" name="value" id="value" value="<?= h($vals['value'] ?? '') ?>" inputmode="decimal" maxlength="32" autocomplete="off" placeholder="0.00">
        </label>
        <label class="field">
          <span>Purchase date</span>
          <input type="date" name="purchase_date" id="purchase_date" value="<?= h($vals['purchase_date'] ?? '') ?>" max="<?= date('Y-m-d', app_time()) ?>">
        </label>
        <label class="field">
          <span>Expires / warranty until</span>
          <input type="date" name="expires_on" id="expires_on" value="<?= h($vals['expires_on'] ?? '') ?>" min="1900-01-01" max="2999-12-31">
        </label>
        <label class="field">
          <span>Serial number</span>
          <input type="text" name="serial" id="serial" value="<?= h($vals['serial'] ?? '') ?>" maxlength="100" autocomplete="off" autocapitalize="characters" spellcheck="false">
        </label>
        <div class="field">
          <label class="field-l" for="barcode">Barcode</label>
          <div class="scanrow">
            <input type="text" name="barcode" id="barcode" value="<?= h($vals['barcode'] ?? '') ?>" maxlength="<?= BARCODE_MAX ?>" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="done">
            <button type="button" class="btn" data-scan="#barcode" hidden><?= icon('qr') ?> Scan</button>
          </div>
        </div>
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
    echo scan_dialog_html();
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
    <?php if ($kind === 'location' && $list): ?>
    <label class="field addloc-parent"><span>Inside</span><select name="parent" id="addloc-parent"><?= parent_options($list, null, null) ?></select></label>
    <?php endif; ?>
  </form>
  <div class="err" role="alert"<?= $error === null ? ' hidden' : '' ?>><?= h($error) ?></div>
  <?php if ($kind === 'location'): ?><p class="item-links"><a class="btn btn-sm" href="/labels"><?= icon('qr') ?> Print QR labels for all locations</a></p><?php endif; ?>
  <p class="hint">★ The default <?= h($t['one']) ?> is preselected when you add a new item. Tap ★ again to clear it.</p>

  <?php if (!$list): ?>
  <div class="empty"><?= icon($t['icon']) ?><p>No <?= h($t['many']) ?> yet. Add your first one above.</p></div>
  <?php else: ?>
  <ul class="loclist">
    <?php foreach ($list as $l): $id = (int)$l['id']; $n = (int)$l['n']; ?>
    <li class="loc<?= $l['is_default'] ? ' is-default' : '' ?><?= ($d = min((int)($l['depth'] ?? 0), LOCATION_MAX_DEPTH)) > 0 ? ' loc-d' . $d : '' ?>" id="<?= $t['param'] ?>-<?= $id ?>"<?= $kind === 'location' ? ' data-depth="' . (int)$l['depth'] . '"' : '' ?>>
      <div class="loc-main">
        <form method="post" action="<?= $base ?>/<?= $id ?>/default" class="loc-star-form">
          <input type="hidden" name="csrf" value="<?= $tok ?>">
          <input type="hidden" name="on" value="<?= $l['is_default'] ? '0' : '1' ?>">
          <button class="loc-star" aria-label="<?= $l['is_default'] ? 'Unset default' : 'Set as default' ?>" title="<?= $l['is_default'] ? 'Default – tap to unset' : 'Set as default' ?>"><?= icon('star') ?></button>
        </form>
        <a class="loc-name" href="/?<?= $t['param'] ?>=<?= $id ?>"><?= h($l['name']) ?><?php if ($l['is_default']): ?> <span class="badge">Default</span><?php endif; ?></a>
        <?php $tot = (int)($l['total'] ?? $n); ?>
        <a class="loc-count" href="/?<?= $t['param'] ?>=<?= $id ?>" title="<?= $tot !== $n ? h($n . ' here, ' . ($tot - $n) . ' in sub-locations') : '' ?>"><?= $tot ?> item<?= $tot === 1 ? '' : 's' ?></a>
      </div>
      <details class="loc-more">
        <summary><?= icon('edit') ?> Edit</summary>
        <div class="loc-actions">
          <form method="post" action="<?= $base ?>/<?= $id ?>/rename" class="loc-rename">
            <input type="hidden" name="csrf" value="<?= $tok ?>">
            <input type="text" name="name" value="<?= h($l['name']) ?>" required maxlength="120" autocomplete="off" autocorrect="off" aria-label="Rename <?= h($t['one']) ?>">
            <button class="btn">Rename</button>
          </form>
          <?php if ($kind === 'location' && count($list) > 1): ?>
          <form method="post" action="<?= $base ?>/<?= $id ?>/parent" class="loc-parent">
            <input type="hidden" name="csrf" value="<?= $tok ?>">
            <select name="parent" aria-label="Put “<?= h($l['name']) ?>” inside"><?= parent_options($list, $id, $l['parent_id']) ?></select>
            <button class="btn">Move</button>
          </form>
          <?php endif; ?>
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

/** <option>s for a parent picker: "Top level" plus every location except $self and its sub-locations. */
function parent_options(array $list, ?int $self, ?int $cur): string
{
    $o = '<option value="">' . ($self === null ? 'Top level' : '— Top level —') . '</option>';
    $skip = -1;
    foreach ($list as $l) {
        if ($skip >= 0 && $l['depth'] > $skip) continue;   // inside $self: not allowed
        $skip = -1;
        if ($self !== null && $l['id'] === $self) { $skip = $l['depth']; continue; }
        $o .= '<option value="' . $l['id'] . '"' . ($cur === $l['id'] ? ' selected' : '') . '>' . h(tax_option_label($l)) . '</option>';
    }
    return $o;
}

/** Barcode lookup: type (or scan, where the browser supports it) a code and go to its item or a prefilled new item. */
function view_barcode(): void
{
    page_start('Barcode', 'page-barcode');
    ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="/" aria-label="Back"><?= icon('back') ?></a>
    <h1 class="title-sm">Find by barcode</h1>
    <?= menu_html() ?>
  </div>
</header>
<main class="wrap narrow">
  <form method="get" action="/barcode" class="barcode-form" id="barcode-form">
    <div class="field">
      <label class="field-l" for="code">Barcode</label>
      <div class="scanrow">
        <input type="text" name="code" id="code" required maxlength="<?= BARCODE_MAX ?>" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="go" placeholder="e.g. 4006381333931">
        <button type="button" class="btn" data-scan="#code" data-scan-submit hidden><?= icon('qr') ?> Scan</button>
      </div>
    </div>
    <button class="btn btn-primary btn-wide" id="code-go"><?= icon('search') ?> Look up</button>
  </form>
  <p class="hint" id="scan-hint">Type the digits under the barcode. If one item has this barcode it opens; if none does, a new item is started with the code filled in.</p>
</main>
<?= scan_dialog_html() ?>
<?php
    page_end();
}

/** The camera dialog used by the Scan buttons (only shown where BarcodeDetector exists). */
function scan_dialog_html(): string
{
    return '<dialog class="scan" id="scan-dialog" aria-label="Scan a barcode">'
        . '<video id="scan-video" playsinline muted></video>'
        . '<p class="scan-msg" id="scan-msg">Point the camera at a barcode</p>'
        . '<button type="button" class="btn btn-wide" id="scan-cancel">Cancel – type it instead</button></dialog>';
}

/** Chips for the saved filters on the list page; the one matching the current view is highlighted. */
function saved_filters_html(array $list, string $current): string
{
    if (!$list) return '';
    $o = '';
    foreach ($list as $sf) {
        $on = $sf['query'] === $current;
        $o .= '<a class="achip sfchip' . ($on ? ' is-on' : '') . '" href="/?' . h($sf['query']) . '" data-query="' . h($sf['query']) . '"'
            . ($on ? ' aria-current="true"' : '') . '>' . icon('star') . '<span>' . h($sf['name']) . '</span></a>';
    }
    return $o . '<a class="achip sfchip-manage" href="/filters" aria-label="Manage saved filters">' . icon('edit') . '<span>Edit</span></a>';
}

/** Saved filters: open, rename, delete. */
function view_saved_filters(array $list, ?string $error, ?string $toast): void
{
    page_start('Saved filters', 'page-locs');
    $tok = h(csrf_token());
    ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="/" aria-label="Back"><?= icon('back') ?></a>
    <h1 class="title-sm">Saved filters</h1>
    <?= menu_html() ?>
  </div>
</header>
<main class="wrap narrow">
  <div class="err" role="alert"<?= $error === null ? ' hidden' : '' ?>><?= h($error) ?></div>
  <p class="hint">Set up a search, filters and sort on the list, then tap <b>Save filter</b>. Saved filters appear as chips above the list.</p>
  <?php if (!$list): ?>
  <div class="empty"><?= icon('star') ?><p>No saved filters yet.</p><a class="btn" href="/">Go to the list</a></div>
  <?php else: ?>
  <ul class="loclist" id="sflist">
    <?php foreach ($list as $sf): ?>
    <li class="loc" id="sf-<?= $sf['id'] ?>">
      <div class="loc-main sf-main">
        <a class="loc-name" href="/?<?= h($sf['query']) ?>"><?= h($sf['name']) ?></a>
        <span class="loc-count sf-desc"><?= h(saved_filter_describe($sf['query'])) ?></span>
      </div>
      <details class="loc-more">
        <summary><?= icon('edit') ?> Edit</summary>
        <div class="loc-actions">
          <form method="post" action="/filters/<?= $sf['id'] ?>/rename" class="loc-rename">
            <input type="hidden" name="csrf" value="<?= $tok ?>">
            <input type="text" name="name" value="<?= h($sf['name']) ?>" required maxlength="60" autocomplete="off" aria-label="Rename “<?= h($sf['name']) ?>”">
            <button class="btn">Rename</button>
          </form>
          <div class="loc-btns">
            <form method="post" action="/filters/<?= $sf['id'] ?>/delete" data-confirm="Delete the saved filter “<?= h($sf['name']) ?>”?">
              <input type="hidden" name="csrf" value="<?= $tok ?>">
              <button class="btn btn-danger"><?= icon('trash') ?> Delete</button>
            </form>
          </div>
        </div>
      </details>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</main>
<?php if ($toast !== null): ?><div class="toast" role="status"><?= h($toast) ?></div><?php endif; ?>
<?php
    page_end();
}

/** Short human description of a saved query: "“drill” · Garage · Tools · Running low · sorted by Name". */
function saved_filter_describe(string $query): string
{
    parse_str($query, $p);
    $f = filters_from($p);
    $parts = [];
    if (($p['q'] ?? '') !== '') $parts[] = '“' . $p['q'] . '”';
    foreach (['loc' => 'location', 'cat' => 'category'] as $k => $kind) {
        if ($f[$k] === 'none') $parts[] = tax($kind)['none'];
        elseif ($f[$k] !== '') $parts[] = $kind === 'location' ? (location_path((int)$f[$k]) ?: '?') : (string)(tax_get($kind, (int)$f[$k])['name'] ?? '?');
    }
    if ($f['tag'] !== '') foreach (tags_all() as $t) if ((string)$t['id'] === $f['tag']) $parts[] = '#' . $t['name'];
    foreach (list_filter_defs() as $k => $d) if ($f[$k] !== '') $parts[] = $d['opts'][$f[$k]];
    if (isset($p['sort']) && is_string($p['sort']) && isset(SORTS[$p['sort']])) $parts[] = 'sorted by ' . SORTS[$p['sort']];
    return implode(' · ', $parts);
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
  <p class="lead"><?= $n ?> item<?= $n === 1 ? ' is' : 's are' ?> directly in this <?= h($t['one']) ?>. What should happen to <?= $n === 1 ? 'it' : 'them' ?>?</p>
  <?php if (!empty($entry['children'])): ?><p class="hint" id="del-children">Its <?= (int)$entry['children'] ?> sub-location<?= (int)$entry['children'] === 1 ? '' : 's' ?> (and everything in <?= (int)$entry['children'] === 1 ? 'it' : 'them' ?>) move up one level<?= !empty($entry['parent_name']) ? ' into “' . h($entry['parent_name']) . '”' : ' to the top level' ?>.</p><?php endif; ?>
  <form method="post" action="<?= $t['path'] ?>/<?= (int)$entry['id'] ?>/delete" class="itemform" id="locdel">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php if ($others): ?>
    <label class="opt">
      <input type="radio" name="action" value="move" checked>
      <span><strong>Move items to</strong>
        <select name="target" aria-label="Move to <?= h($t['one']) ?>">
          <?php foreach ($others as $o): ?>
          <option value="<?= (int)$o['id'] ?>"<?= $o['is_default'] ? ' selected' : '' ?>><?= h(tax_option_label($o)) ?> (<?= (int)$o['n'] ?>)</option>
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

/** The Apotheke logo tile (cream tile, terracotta jar): legible on light and dark backgrounds; decorative next to the name. */
function brand_logo(int $size): string
{
    return '<img class="brand-logo" src="' . h(brand_logo_url()) . '" width="' . $size . '" height="' . $size . '" alt="" decoding="async">';
}

function view_login(?string $error, string $username = '', ?string $note = null): void
{
    page_start('Sign in', 'page-login');
    ?>
<main class="wrap narrow login">
  <div class="brand brand-lg"><?= brand_logo(56) ?><span><?= h(app_name()) ?></span></div>
  <p class="tagline"><?= h(ucfirst(app_tagline())) ?></p>
  <form method="post" action="/login" class="itemform" id="login-form">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php if ($error): ?><div class="err" role="alert"><?= h($error) ?></div><?php endif; ?>
    <label class="field"><span>User name</span><input type="text" name="username" id="login-user" value="<?= h($username) ?>" autocomplete="username" autocapitalize="off" autocorrect="off" spellcheck="false" maxlength="<?= USERNAME_MAX * 2 ?>" required<?= $username === '' ? ' autofocus' : '' ?>></label>
    <label class="field"><span>Password</span><input type="password" name="password" id="login-pw" autocomplete="current-password" required enterkeyhint="go"<?= $username !== '' ? ' autofocus' : '' ?>></label>
    <button class="btn btn-primary btn-wide" id="login-go">Sign in</button>
  </form>
  <?php if ($note === 'pending'): ?><p class="okbox" role="status" id="login-pending">Account created. You can sign in once an admin has approved it.</p><?php endif; ?>
  <?php if (registration_open()): ?><p class="login-alt"><a class="btn btn-wide" href="/register" id="login-register">Create an account</a></p><?php endif; ?>
  <?php if (guest_browsing()): ?><p class="login-alt"><a class="btn btn-wide" href="/" id="login-guest">Look around as a guest</a></p><?php endif; ?>
</main>
<?php
    page_end();
}

function view_register(?string $error, string $username = ''): void
{
    page_start('Create an account', 'page-login page-register');
    $approval = (bool)setting('auth_register_approval');
    ?>
<main class="wrap narrow login">
  <div class="brand brand-lg"><?= brand_logo(56) ?><span><?= h(app_name()) ?></span></div>
  <h1 class="tagline">Create an account</h1>
  <form method="post" action="/register" class="itemform" id="reg-form">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php if ($error): ?><div class="err" role="alert" id="reg-err"><?= h($error) ?></div><?php endif; ?>
    <label class="field"><span>User name <em>letters, digits, . _ @ -</em></span><input type="text" name="username" id="reg-user" value="<?= h($username) ?>" autocomplete="username" autocapitalize="off" autocorrect="off" spellcheck="false" maxlength="<?= USERNAME_MAX ?>" required></label>
    <label class="field"><span>Password <em>at least <?= PASSWORD_MIN ?> characters</em></span><input type="password" name="new" id="reg-pw" autocomplete="new-password" minlength="<?= PASSWORD_MIN ?>" required></label>
    <label class="field"><span>Repeat password</span><input type="password" name="confirm" id="reg-confirm" autocomplete="new-password" minlength="<?= PASSWORD_MIN ?>" required></label>
    <p class="hint" id="reg-hint">New accounts get the role <b><?= h(role_name((string)setting('auth_register_role'))) ?></b><?= $approval ? ' and can sign in once an admin has approved them' : '' ?>.</p>
    <button class="btn btn-primary btn-wide" id="reg-go">Create account</button>
  </form>
  <p class="login-alt"><a class="btn btn-wide" href="/login" id="reg-login">I have an account – sign in</a></p>
</main>
<?php
    page_end();
}

function view_account(array $me, ?string $error, ?string $toast): void
{
    page_start('Your account', 'page-account');
    echo settings_header('Your account', '/');
    $tok = h(csrf_token());
    ?>
<main class="wrap narrow">
  <section class="set-card" id="acct-card">
    <p class="pw-state"><?= icon('lock') ?> Signed in as <b id="acct-name"><?= h($me['username']) ?></b> · <span class="role role-<?= h($me['role']) ?>" id="acct-role"><?= h(role_name($me['role'])) ?></span></p>
    <p class="hint" id="acct-perms">You may: <?= h(implode(', ', array_map(fn($p) => mb_strtolower(PERMISSIONS[$p][0]), role_get($me['role'])['perms'] ?? []))) ?>.</p>
    <?php if ($error): ?><div class="err" role="alert" id="acct-err"><?= h($error) ?></div><?php endif; ?>
    <form method="post" action="/account" class="setform" id="acct-pw">
      <input type="hidden" name="csrf" value="<?= $tok ?>">
      <h2 class="set-h">Change your password</h2>
      <label class="field"><span>Current password</span><input type="password" name="current" id="acct-current" autocomplete="current-password" required></label>
      <label class="field"><span>New password <em>at least <?= PASSWORD_MIN ?> characters</em></span><input type="password" name="new" id="acct-new" autocomplete="new-password" minlength="<?= PASSWORD_MIN ?>" required></label>
      <label class="field"><span>Repeat new password</span><input type="password" name="confirm" id="acct-confirm" autocomplete="new-password" minlength="<?= PASSWORD_MIN ?>" required></label>
      <button class="btn btn-primary btn-wide" id="acct-save">Change password</button>
    </form>
    <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= $tok ?>"><button class="btn btn-wide" id="acct-logout"><?= icon('logout') ?> Sign out</button></form>
  </section>
</main>
<?php if ($toast): ?><div class="toast" role="status"><?= h($toast) ?></div><?php endif; ?>
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
const ACTIVITY_FIELDS = ['name' => 'Name', 'category' => 'Category', 'notes' => 'Notes', 'value' => 'Purchase price', 'purchase_date' => 'Purchase date', 'expires_on' => 'Expires', 'serial' => 'Serial no.', 'barcode' => 'Barcode', 'tags' => 'Tags', 'min_qty' => 'Running low at'];

/** One activity entry as HTML (already escaped). */
function activity_html(array $e): string
{
    $d = $e['details'];
    $q = fn($v) => ($v === '' || $v === null) ? '<i>empty</i>' : '“' . h((string)$v) . '”';
    $none = fn($v) => (string)$v === '' ? '<i>none</i>' : '<b>' . h((string)$v) . '</b>';
    switch ($e['action']) {
        case 'created':
            $t = 'Created' . (!empty($d['location']) ? ' in <b>' . h($d['location']) . '</b>' : '') . (isset($d['quantity']) ? ' · quantity ' . (int)$d['quantity'] : '') . (isset($d['min_qty']) ? ' · running low at ' . (int)$d['min_qty'] : '')
               . (!empty($d['photo']) ? ' · with photo' : '') . (!empty($d['tags']) ? ' · tags ' . h(implode(', ', $d['tags'])) : '')
               . (!empty($d['copied_from']) ? ' · copied from “' . h((string)$d['copied_from']) . '”' : '');
            break;
        case 'edited':
            $parts = [];
            foreach (($d['changes'] ?? []) as $k => $ch) {
                $label = ACTIVITY_FIELDS[$k] ?? ucfirst((string)$k);
                $val = $k === 'value' ? fn($v) => $v === '' ? '<i>empty</i>' : h($v . ' ' . setting('currency')) : $q;
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
                  'reordered' => 'Reordered photos', 'deleted' => 'Deleted a photo', 'edited' => 'Edited a photo'][$d['change'] ?? ''] ?? 'Changed photos';
            if (($d['change'] ?? '') === 'edited') $t .= (!empty($d['rotate']) ? ' · rotated ' . (int)$d['rotate'] . '°' : '') . (isset($d['size']) ? ' · ' . h((string)$d['size']) . ' px' : '');
            if (isset($d['count'])) $t .= ' · ' . (int)$d['count'] . ' total';
            break;
        case 'lent':
            $t = (!empty($d['before']) ? 'Lending changed: now with ' : 'Lent to ') . '<b>' . h((string)($d['to'] ?? '')) . '</b>'
               . (!empty($d['at']) ? ' on ' . h($d['at']) : '') . (!empty($d['due']) ? ' · due ' . h($d['due']) : '');
            break;
        case 'returned':
            $t = 'Returned by <b>' . h((string)($d['from'] ?? '')) . '</b>' . (isset($d['days']) ? ' after ' . (int)$d['days'] . ' day' . ((int)$d['days'] === 1 ? '' : 's') : '');
            break;
        case 'attachment':
            $t = (($d['change'] ?? '') === 'removed' ? 'Removed attachment ' : 'Attached ') . '“' . h((string)($d['file'] ?? '')) . '”';
            break;
        case 'merged':
            $t = 'Merged <b>“' . h((string)($d['from'] ?? '')) . '”</b> into this item';
            if (isset($d['quantity'][0], $d['quantity'][1]) && $d['quantity'][0] !== $d['quantity'][1]) $t .= ' · quantity <b>' . (int)$d['quantity'][0] . '</b> → <b>' . (int)$d['quantity'][1] . '</b>';
            if (!empty($d['photos'])) $t .= ' · ' . (int)$d['photos'] . ' photo' . ((int)$d['photos'] === 1 ? '' : 's');
            if (!empty($d['attachments'])) $t .= ' · ' . (int)$d['attachments'] . ' attachment' . ((int)$d['attachments'] === 1 ? '' : 's');
            if (!empty($d['filled'])) $t .= ' · filled in ' . h(implode(', ', (array)$d['filled']));
            if (($d['lending'] ?? '') === 'copied') $t .= ' · loan taken over';
            break;
        case 'restored':
            $t = 'Restored (deleting was undone)';
            break;
        case 'deleted':
            $t = 'Deleted' . (!empty($d['location']) ? ' from <b>' . h($d['location']) . '</b>' : '');
            break;
        default:
            $t = h($e['action']);
    }
    return $t;
}

const ACTIVITY_ICONS = ['restored' => 'undo', 'merged' => 'merge', 'attachment' => 'paperclip', 'created' => 'plus', 'edited' => 'edit', 'moved' => 'pin', 'quantity' => 'box', 'photo' => 'camera', 'deleted' => 'trash', 'lent' => 'handoff', 'returned' => 'undo'];

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
        $o .= '<div class="act-text">' . activity_html($e) . '</div><time datetime="' . h(str_replace(' ', 'T', $e['created_at']) . 'Z') . '">' . h(fmt_time($e['created_at'])) . '</time>'
            . (!empty($e['actor']) ? ' <span class="act-by">· ' . h($e['actor']) . '</span>' : '') . '</div></li>';
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
    $labels = ['items' => 'Items', 'locations' => 'Locations', 'categories' => 'Categories', 'tags' => 'Tags', 'item_photos' => 'Photos', 'activity' => 'History entries', 'stocktakes' => 'Stocktakes'];
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
    $cfg = [(int)setting('label_width_mm'), (int)setting('label_height_mm'), (int)setting('label_columns'), (int)setting('label_gap_mm'), (int)setting('label_border'), (int)setting('label_show_sub'), (int)setting('label_show_url')];
    ?>
<link rel="stylesheet" href="/labels.css?v=<?= substr(md5(implode(',', $cfg)), 0, 8) ?>" id="labels-css">
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
    <p class="hint">Scan a label with the phone camera to open <?= $options ? 'the matching list or item' : 'it' ?> in Apotheke. Links point to <b><?= h(public_url('')) ?></b><?= setting('public_url') === '' ? ' (change it in <a href="/settings/general">Settings → General</a>)' : '' ?>.</p>
    <p class="hint labels-size"><?= count($labels) ?> label<?= count($labels) === 1 ? '' : 's' ?> · <?= $cfg[0] ?> × <?= $cfg[1] ?> mm, <?= $cfg[2] ?> per row (<a href="/settings/labels">change</a>)</p>
    <?php if ($options): ?><nav class="label-opts" aria-label="Label sets"><?php foreach ($options as $href => $txt): ?><a class="achip" href="<?= h($href) ?>"><?= h($txt) ?></a><?php endforeach; ?></nav><?php endif; ?>
  </div>
  <?php if (!$labels): ?>
  <div class="empty"><?= icon('qr') ?><p class="empty-title">Nothing to print yet.</p></div>
  <?php else: ?>
  <div class="labels" id="labels">
    <?php foreach ($labels as $l): $url = public_url($l['path']); ?>
    <figure class="label" data-url="<?= h($url) ?>">
      <?= qr_svg($url, 'QR code for ' . $l['title']) ?>
      <figcaption><b><?= h($l['title']) ?></b><?php if ($l['sub'] !== '' && setting('label_show_sub')): ?><span><?= h($l['sub']) ?></span><?php endif; ?><?php if (setting('label_show_url')): ?><small><?= h(preg_replace('#^https?://#', '', $url)) ?></small><?php endif; ?></figcaption>
    </figure>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</main>
<?php
    page_end();
}

/** Attachments card on the item page: list with download/delete, upload form (works without JS). */
function attachments_html(int $id, array $list): string
{
    $tok = h(csrf_token());
    $max = (int)setting('attach_max_per_item');
    $accept = implode(',', array_map(fn($e) => ATTACH_TYPES[$e][0], attach_allowed()));
    $types = implode(', ', array_map(fn($e) => ATTACH_TYPES[$e][1], attach_allowed()));
    $o = '<details class="photos-box att-box" id="attachments"' . ($list ? ' open' : '') . '><summary>' . icon('paperclip') . ' Attachments <span class="muted-n">' . count($list) . '/' . $max . '</span></summary>';
    if ($list) {
        $o .= '<ul class="att-list">';
        foreach ($list as $a) {
            $o .= '<li class="att" data-id="' . (int)$a['id'] . '"><a class="att-dl" href="/attachment/' . (int)$a['id'] . '" download>'
                . icon($a['mime'] === 'application/pdf' ? 'file' : 'image') . '<span class="att-name">' . h($a['name']) . '</span><small>' . h(fmt_bytes((int)$a['size'])) . '</small></a>'
                . '<form method="post" action="/item/' . $id . '/attachments/' . (int)$a['id'] . '/delete" data-confirm="Delete “' . h($a['name']) . '”?"><input type="hidden" name="csrf" value="' . $tok . '">'
                . '<button class="pbtn pbtn-del" aria-label="Delete ' . h($a['name']) . '">' . icon('trash') . '</button></form></li>';
        }
        $o .= '</ul>';
    }
    if (count($list) < $max) {
        $o .= '<form method="post" action="/item/' . $id . '/attachments" enctype="multipart/form-data" class="att-add" id="attadd"><input type="hidden" name="csrf" value="' . $tok . '">'
            . '<label class="btn"><input type="file" name="files[]" multiple accept="' . h($accept) . '" class="file" id="att-file">' . icon('paperclip') . ' Attach files</label>'
            . '<button class="btn btn-primary" id="att-upload">' . icon('check') . ' Upload</button>'
            . '<p class="hint">' . h($types) . ' · max ' . (int)setting('attach_max_mb') . ' MB each</p></form>';
    }
    return $o . '</details>';
}

/** Merge step 1: pick the item to keep (likely duplicates first, or search). */
function view_merge_pick(array $it, array $cands, string $q): void
{
    $id = (int)$it['id'];
    page_start('Merge');
    ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="/item/<?= $id ?>" aria-label="Back"><?= icon('back') ?></a>
    <h1 class="title-sm">Merge duplicates</h1>
    <span class="spacer"></span>
  </div>
</header>
<main class="wrap narrow">
  <h2 class="item-name">Merge “<?= h($it['name']) ?>” into…</h2>
  <p class="lead">Pick the item to keep. Its quantity grows by <?= (int)$it['quantity'] ?>; photos, attachments, tags, history and empty fields come over from “<?= h($it['name']) ?>”, which is then removed. You confirm on the next screen.</p>
  <form class="search merge-search" method="get" action="/item/<?= $id ?>/merge" role="search">
    <?= icon('search') ?>
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search for the item to keep" autocomplete="off" enterkeyhint="search" id="merge-q" aria-label="Search for the item to keep">
  </form>
  <?php if ($cands): ?>
  <ul class="merge-list" id="merge-list">
    <?php foreach ($cands as $c): ?>
    <li><a class="merge-pick" href="/item/<?= $id ?>/merge?into=<?= (int)$c['id'] ?>" data-id="<?= (int)$c['id'] ?>">
      <?= valid_image_name($c['image'] ?? null) ? '<img src="/media/thumb/' . h($c['image']) . '" alt="" loading="lazy">' : '<span class="ph">' . icon('box') . '</span>' ?>
      <span class="merge-name"><b><?= h($c['name']) ?></b><small><?= h($c['location'] !== '' ? $c['location'] : 'No location') ?> · qty <?= (int)$c['quantity'] ?></small></span>
      <?= icon('back') ?></a></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
  <div class="empty"><?= icon('search') ?><p class="empty-title"><?= $q === '' ? 'No similar items found.' : 'Nothing matches “' . h($q) . '”.' ?></p><p class="hint">Search by name, location or barcode.</p></div>
  <?php endif; ?>
</main>
<?php
    page_end();
}

/** Merge step 2: what will happen, then one button. */
function view_merge_confirm(array $p): void
{
    $src = $p['source']; $dst = $p['target']; $in = $p['result'];
    $sid = (int)$src['id']; $tid = (int)$dst['id'];
    page_start('Merge');
    $row = fn(string $label, string $html) => '<li><span class="act-field">' . h($label) . '</span> ' . $html . '</li>';
    $rows = [$row('Quantity', '<b>' . (int)$dst['quantity'] . '</b> + <b>' . (int)$src['quantity'] . '</b> = <b>' . (int)$in['quantity'] . '</b>')];
    if ($p['photos']) $rows[] = $row('Photos', '+' . $p['photos'] . ' (after the current ones)');
    if ($p['attachments']) $rows[] = $row('Attachments', '+' . $p['attachments']);
    $newTags = array_diff($in['tags'], array_column($dst['tags'], 'name'));
    if ($newTags) $rows[] = $row('Tags', '+ ' . h(implode(', ', $newTags)));
    foreach ($p['fills'] as $k => $label) {
        if ($k === 'notes') { $rows[] = $row('Notes', 'the source’s notes are appended'); continue; }
        $v = match ($k) {
            'location_id' => (string)$src['location'], 'category_id' => (string)$src['category'],
            'value_cents' => money_input($in['value_cents']) . ' ' . setting('currency'),
            default => (string)$in[$k],
        };
        $rows[] = $row($label, 'empty → “' . h($v) . '”');
    }
    if ($p['lending'] === 'copied') $rows[] = $row('Lending', 'lent to <b>' . h((string)$src['lent_to']) . '</b> is taken over');
    if ($p['lending'] === 'kept') $rows[] = $row('Lending', 'both are lent: the loan to <b>' . h((string)$dst['lent_to']) . '</b> is kept, the other one is dropped');
    if (!empty($src['favourite']) && empty($dst['favourite'])) $rows[] = $row('Favourite', '★ is kept');
    if ($p['history']) $rows[] = $row('History', $p['history'] . ' entr' . ($p['history'] === 1 ? 'y moves' : 'ies move') . ' over');
    ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="/item/<?= $sid ?>/merge" aria-label="Back"><?= icon('back') ?></a>
    <h1 class="title-sm">Merge duplicates</h1>
    <span class="spacer"></span>
  </div>
</header>
<main class="wrap narrow">
  <h2 class="item-name">Merge into “<?= h($dst['name']) ?>”?</h2>
  <div class="merge-pair" id="merge-pair">
    <div class="merge-card merge-src"><small>Removed</small><b><?= h($src['name']) ?></b><span><?= h($src['location'] !== '' ? $src['location'] : 'No location') ?></span></div>
    <span class="merge-arrow" aria-hidden="true"><?= icon('merge') ?></span>
    <div class="merge-card merge-dst"><small>Kept</small><b><?= h($dst['name']) ?></b><span><?= h($dst['location'] !== '' ? $dst['location'] : 'No location') ?></span></div>
  </div>
  <ul class="act-changes merge-changes" id="merge-changes"><?= implode('', $rows) ?></ul>
  <p class="hint">Fields the kept item already has stay as they are. Everything happens in one step; if anything fails, nothing changes.</p>
  <form method="post" action="/item/<?= $sid ?>/merge" class="itemform" id="mergeform">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="into" value="<?= $tid ?>">
    <input type="hidden" name="confirm" value="1">
    <div class="actionbar actionbar-form">
      <div class="actionbar-in">
        <a class="btn" href="/item/<?= $sid ?>">Cancel</a>
        <button class="btn btn-primary" id="merge-confirm"><?= icon('merge') ?> Merge</button>
      </div>
    </div>
  </form>
</main>
<?php
    page_end();
}
