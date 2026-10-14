<?php
declare(strict_types=1);

/* Stocktake pages: start + history, the checking screen, the report. */

function stocktake_header(string $title, string $back): string
{
    return '<header class="top top-simple"><div class="bar"><a class="btn btn-ghost btn-round" href="' . h($back) . '" aria-label="Back">' . icon('back') . '</a>'
        . '<h1 class="title-sm">' . h($title) . '</h1><span class="spacer"></span>' . menu_html() . '</div></header>';
}

function stocktake_summary_text(array $sum): string
{
    $p = [(int)($sum['found'] ?? 0) . ' of ' . (int)($sum['expected'] ?? 0) . ' found'];
    if (!empty($sum['missing'])) $p[] = (int)$sum['missing'] . ' missing';
    if (!empty($sum['misplaced'])) $p[] = (int)$sum['misplaced'] . ' misplaced';
    if (!empty($sum['moved'])) $p[] = (int)$sum['moved'] . ' moved';
    return implode(' · ', $p);
}

function view_stocktakes(array $list, ?int $preselect, ?string $toast): void
{
    page_start('Stocktake', 'page-stocktake');
    $locs = locations();
    echo stocktake_header('Stocktake', '/');
    ?>
<main class="wrap narrow" id="stocktakes">
  <?php if ($locs): ?>
  <form method="post" action="/stocktake" class="setform stk-start" id="stk-start">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <p class="lead">Check one location – and everything inside it – against the catalog: tick or scan what you find.</p>
    <label class="field"><span>Location</span>
      <select name="location_id" id="stk-loc" required>
        <?php foreach ($locs as $l): ?><option value="<?= (int)$l['id'] ?>"<?= $preselect === (int)$l['id'] ? ' selected' : '' ?>><?= h(tax_option_label($l)) ?> (<?= (int)($l['total'] ?? $l['n']) ?>)</option><?php endforeach; ?>
      </select></label>
    <button class="btn btn-primary btn-wide" id="stk-go"><?= icon('check') ?> Start stocktake</button>
  </form>
  <?php else: ?>
  <div class="empty"><?= icon('pin') ?><p class="empty-title">No locations yet.</p><p class="empty-sub">Add a location first, then check what is in it.</p></div>
  <?php endif; ?>
  <?php if ($list): ?>
  <h2 class="set-h">History</h2>
  <ul class="stk-hist" id="stk-history">
    <?php foreach ($list as $s): ?>
    <li class="stk-h stk-<?= h($s['status']) ?>"><a href="/stocktake/<?= $s['id'] ?>">
      <b><?= h($s['location_name']) ?></b>
      <span><?= $s['status'] === 'open' ? 'In progress · ' . h(stocktake_summary_text(stocktake_progress($s['id']) + ['missing' => 0])) : ($s['status'] === 'cancelled' ? 'Cancelled' : h(stocktake_summary_text($s['summary']))) ?></span>
      <time datetime="<?= h(str_replace(' ', 'T', $s['started_at']) . 'Z') ?>"><?= h(fmt_time($s['finished_at'] ?? $s['started_at'])) ?></time></a></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</main>
<?php
    if ($toast) echo '<div class="toast" role="status">' . h($toast) . '</div>';
    page_end();
}

/** The live part of the checking screen (re-rendered after every tick or scan). */
function stocktake_body_html(array $s): string
{
    $rep = stocktake_report($s['id']);
    $tok = h(csrf_token());
    $lines = array_merge($rep['found'], $rep['missing']);
    usort($lines, fn($a, $b) => strcmp(fold($a['item_name']), fold($b['item_name'])) ?: $a['id'] <=> $b['id']);
    $n = count($rep['found']);
    $pct = $rep['expected'] ? (int)round(100 * $n / $rep['expected']) : 100;
    $o = '<div class="stk-progress" id="stk-progress"><p><b id="stk-count">' . $n . ' of ' . $rep['expected'] . '</b> found'
        . ($rep['misplaced'] ? ' · <b class="stk-mis-n">' . count($rep['misplaced']) . ' misplaced</b>' : '') . '</p>'
        . '<div class="stk-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $pct . '" aria-label="Progress"><span class="stk-fill stk-w' . (int)(round($pct / 5) * 5) . '"></span></div></div>';
    $row = function (array $l, bool $misplaced) use ($s, $tok): string {
        $on = $misplaced || $l['found'];
        $img = valid_image_name($l['image']) ? '<img src="/media/thumb/' . h($l['image']) . '" alt="" loading="lazy">' : icon('box');
        $sub = $misplaced ? 'Belongs in ' . ($l['home_location'] !== '' ? h($l['home_location']) : 'no location') : h($l['home_location']);
        return '<li><form method="post" action="/stocktake/' . $s['id'] . '/check" class="stk-line' . ($misplaced ? ' is-misplaced' : '') . '">'
            . '<input type="hidden" name="csrf" value="' . $tok . '"><input type="hidden" name="item_id" value="' . (int)$l['item_id'] . '"><input type="hidden" name="found" value="' . ($on ? '0' : '1') . '">'
            . '<button class="stk-tick" aria-pressed="' . ($on ? 'true' : 'false') . '" data-id="' . (int)$l['item_id'] . '" aria-label="' . h(($on ? ($misplaced ? 'Remove ' : 'Untick ') : 'Found ') . $l['item_name']) . '">'
            . '<span class="stk-img">' . $img . '</span><span class="stk-text"><b>' . h($l['item_name']) . '</b><span>' . $sub . '</span></span>'
            . '<span class="stk-box">' . icon($misplaced ? 'x' : 'check') . '</span></button></form></li>';
    };
    if ($rep['misplaced']) {
        $o .= '<h2 class="set-h">Found here, belongs elsewhere</h2><ul class="stk-list" id="stk-misplaced">';
        foreach ($rep['misplaced'] as $l) if ($l['item_id'] !== null) $o .= $row($l, true);
        $o .= '</ul>';
    }
    $o .= '<h2 class="set-h">Expected here</h2>';
    if (!$lines) return $o . '<p class="hint" id="stk-none">No items are recorded in this location yet. Scan anything you find – it will be listed as misplaced – or add what is here as new items.</p>'
        . ($s['location_id'] !== null ? '<p><a class="btn btn-sm stk-addhere" id="stk-addhere" href="/new?loc=' . (int)$s['location_id'] . '">' . icon('plus') . ' Add an item here</a></p>' : '');
    $o .= '<ul class="stk-list" id="stk-expected">';
    foreach ($lines as $l) if ($l['item_id'] !== null) $o .= $row($l, false);
    return $o . '</ul>';
}

function view_stocktake(array $s, ?string $error = null, ?string $toast = null): void
{
    page_start('Stocktake: ' . $s['location_name'], 'page-stocktake');
    echo stocktake_header('Stocktake', '/stocktake');
    $tok = h(csrf_token());
    ?>
<main class="wrap narrow" id="stocktake" data-id="<?= $s['id'] ?>">
  <h2 class="item-name stk-loc"><?= icon('pin') ?> <?= h($s['location_name']) ?></h2>
  <form method="post" action="/stocktake/<?= $s['id'] ?>/check" class="stk-scan" id="stk-scan" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $tok ?>"><input type="hidden" name="found" value="1">
    <label class="field-l" for="stk-code">Scan a QR label or barcode</label>
    <div class="scanrow">
      <input type="text" name="code" id="stk-code" maxlength="300" autocapitalize="off" spellcheck="false" enterkeyhint="go" placeholder="Barcode, QR link or #id"<?= $error ? ' aria-invalid="true" aria-describedby="stk-err"' : '' ?>>
      <button type="button" class="btn" data-scan="#stk-code" data-scan-submit hidden><?= icon('qr') ?> Scan</button>
      <button class="btn btn-primary" id="stk-add" aria-label="Mark as found"><?= icon('check') ?></button>
    </div>
    <?= $error ? '<div class="err" role="alert" id="stk-err">' . h($error) . '</div>' : '' ?>
  </form>
  <div id="stk-body" aria-live="polite"><?= stocktake_body_html($s) ?></div>
</main>
<div class="actionbar">
  <div class="actionbar-in">
    <form method="post" action="/stocktake/<?= $s['id'] ?>/cancel" data-confirm="Cancel this stocktake? Nothing is changed.">
      <input type="hidden" name="csrf" value="<?= $tok ?>"><button class="btn" id="stk-cancel"><?= icon('x') ?> Cancel</button></form>
    <a class="btn btn-primary" href="/stocktake/<?= $s['id'] ?>/report" id="stk-finish"><?= icon('check') ?> Finish</a>
  </div>
</div>
<?= scan_dialog_html() ?>
<?php
    if ($toast) echo '<div class="toast" role="status">' . h($toast) . '</div>';
    page_end();
}

function stocktake_report_html(array $s, array $rep): string
{
    $sec = function (string $id, string $title, array $lines, string $empty, callable $sub): string {
        $o = '<section class="stk-rep" id="rep-' . $id . '"><h2 class="set-h">' . h($title) . ' <span class="muted-n">' . count($lines) . '</span></h2>';
        if (!$lines) return $o . '<p class="hint">' . h($empty) . '</p></section>';
        $o .= '<ul class="stk-replist">';
        foreach ($lines as $l) {
            $name = $l['item_id'] !== null && item_get($l['item_id']) ? '<a href="/item/' . (int)$l['item_id'] . '">' . h($l['item_name']) . '</a>' : '<span>' . h($l['item_name']) . '</span>';
            $o .= '<li>' . $name . '<small>' . $sub($l) . '</small></li>';
        }
        return $o . '</ul></section>';
    };
    return $sec('missing', 'Missing', $rep['missing'], 'Everything expected here was found.', fn($l) => h($l['home_location']))
        . $sec('misplaced', 'Misplaced', $rep['misplaced'], 'Nothing from other locations turned up.', fn($l) => 'belongs in ' . ($l['home_location'] !== '' ? h($l['home_location']) : 'no location') . ($l['moved'] ? ' · <b>moved here</b>' : ''))
        . $sec('found', 'Found', $rep['found'], 'Nothing ticked yet.', fn($l) => h($l['home_location']));
}

function view_stocktake_report(array $s, ?string $toast = null): void
{
    $rep = stocktake_report($s['id']);
    $open = $s['status'] === 'open';
    page_start(($open ? 'Finish stocktake: ' : 'Stocktake report: ') . $s['location_name'], 'page-stocktake');
    echo stocktake_header($open ? 'Finish stocktake' : 'Stocktake report', $open ? '/stocktake/' . $s['id'] : '/stocktake');
    $sum = $open ? ['expected' => $rep['expected'], 'found' => count($rep['found']), 'missing' => count($rep['missing']), 'misplaced' => count($rep['misplaced'])] : $s['summary'];
    ?>
<main class="wrap narrow" id="stk-report">
  <h2 class="item-name stk-loc"><?= icon('pin') ?> <?= h($s['location_name']) ?></h2>
  <p class="lead" id="stk-summary"><?= h(stocktake_summary_text($sum)) ?><?= !$open ? ' · ' . ($s['status'] === 'cancelled' ? 'cancelled' : 'finished') . ' ' . h(fmt_time((string)$s['finished_at'])) : '' ?></p>
  <div class="stk-tiles">
    <div class="stk-tile t-found"><b><?= count($rep['found']) ?></b><span>found</span></div>
    <div class="stk-tile t-missing"><b><?= count($rep['missing']) ?></b><span>missing</span></div>
    <div class="stk-tile t-misplaced"><b><?= count($rep['misplaced']) ?></b><span>misplaced</span></div>
  </div>
  <?= stocktake_report_html($s, $rep) ?>
  <?php if ($open): ?>
  <form method="post" action="/stocktake/<?= $s['id'] ?>/finish" class="setform" id="stk-finish-form">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php if ($rep['misplaced']): ?>
    <label class="check"><input type="checkbox" name="moves" value="1" id="stk-moves"<?= setting('stocktake_apply_moves') ? ' checked' : '' ?>>
      <span>Move the <?= count($rep['misplaced']) === 1 ? 'misplaced item' : count($rep['misplaced']) . ' misplaced items' ?> to <b><?= h($s['location_name']) ?></b></span></label>
    <?php endif; ?>
    <p class="hint">Missing items stay where the catalog says they are; the report is kept in the stocktake history.</p>
    <button class="btn btn-primary btn-wide" id="stk-done"><?= icon('check') ?> Finish stocktake</button>
    <a class="btn btn-wide" href="/stocktake/<?= $s['id'] ?>">Keep checking</a>
  </form>
  <?php endif; ?>
</main>
<?php
    if ($toast) echo '<div class="toast" role="status">' . h($toast) . '</div>';
    page_end();
}
