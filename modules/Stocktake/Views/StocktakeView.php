<?php

declare(strict_types=1);

namespace Apotheke\Stocktake\Views;

use Apotheke\Activity\Views\ActivityView;
use Apotheke\Catalog\Items\ItemRepository;
use Apotheke\Catalog\Views\ItemView;
use Apotheke\Core\Libraries\Text;
use Apotheke\Layout\Icons;
use Apotheke\Layout\Page;
use Apotheke\Media\ImageStore;
use Apotheke\Stocktake\Stocktakes;
use Apotheke\Taxonomy\LocationTree;

/**
 * The stocktake pages (legacy stocktake_views.php): start and history,
 * the checking screen and the report, byte-identical to legacy. Every value
 * is escaped here.
 *
 * @phpstan-import-type Take from Stocktakes
 * @phpstan-import-type Line from Stocktakes
 * @phpstan-import-type Report from Stocktakes
 */
final class StocktakeView
{
    /** @param bool $applyMoves the stocktake_apply_moves setting */
    public function __construct(
        private readonly Page $page,
        private readonly Stocktakes $stocktakes,
        private readonly ItemRepository $items,
        private readonly ActivityView $activity,
        private readonly bool $applyMoves,
    ) {
    }

    private static function h(?string $s): string
    {
        return Page::e($s);
    }

    private static function icon(string $name): string
    {
        return Icons::svg($name);
    }

    private function header(string $title, string $back): string
    {
        return '<header class="top top-simple"><div class="bar"><a class="btn btn-ghost btn-round" href="' . self::h($back) . '" aria-label="Back">' . self::icon('back') . '</a>'
            . '<h1 class="title-sm">' . self::h($title) . '</h1><span class="spacer"></span>' . $this->page->menu() . '</div></header>';
    }

    /** @param array<string,mixed> $sum */
    private static function summary(array $sum): string
    {
        $n = static fn (string $k): int => is_scalar($sum[$k] ?? null) ? (int)$sum[$k] : 0;
        $p = [$n('found') . ' of ' . $n('expected') . ' found'];
        if (!empty($sum['missing'])) {
            $p[] = $n('missing') . ' missing';
        }
        if (!empty($sum['misplaced'])) {
            $p[] = $n('misplaced') . ' misplaced';
        }
        if (!empty($sum['moved'])) {
            $p[] = $n('moved') . ' moved';
        }

        return implode(' · ', $p);
    }

    /**
     * Legacy view_stocktakes(): start a stocktake, and the history.
     *
     * @param list<Take> $list
     * @param list<array<string,mixed>> $locs the locations in tree order
     */
    public function list(array $list, array $locs, ?int $preselect, ?string $toast): string
    {
        $head = $this->page->start('Stocktake', 'page-stocktake');
        $head .= $this->header('Stocktake', '/');
        ob_start();
        ?>
<main class="wrap narrow" id="stocktakes">
  <?php if ($locs): ?>
  <form method="post" action="/stocktake" class="setform stk-start" id="stk-start">
    <input type="hidden" name="csrf" value="<?= self::h($this->page->csrf()) ?>">
    <p class="lead">Check one location – and everything inside it – against the catalog: tick or scan what you find.</p>
    <label class="field"><span>Location</span>
      <select name="location_id" id="stk-loc" required>
        <?php foreach ($locs as $l): ?><option value="<?= (int)$l['id'] ?>"<?= $preselect === (int)$l['id'] ? ' selected' : '' ?>><?= self::h(LocationTree::optionLabel(['name' => (string)$l['name'], 'depth' => (int)($l['depth'] ?? 0)])) ?> (<?= (int)($l['total'] ?? $l['n']) ?>)</option><?php endforeach; ?>
      </select></label>
    <button class="btn btn-primary btn-wide" id="stk-go"><?= self::icon('check') ?> Start stocktake</button>
  </form>
  <?php else: ?>
  <div class="empty"><?= self::icon('pin') ?><p class="empty-title">No locations yet.</p><p class="empty-sub">Add a location first, then check what is in it.</p></div>
  <?php endif; ?>
  <?php if ($list): ?>
  <h2 class="set-h">History</h2>
  <ul class="stk-hist" id="stk-history">
    <?php foreach ($list as $s): ?>
    <li class="stk-h stk-<?= self::h($s['status']) ?>"><a href="/stocktake/<?= $s['id'] ?>">
      <b><?= self::h($s['location_name']) ?></b>
      <span><?= $s['status'] === 'open' ? 'In progress · ' . self::h(self::summary($this->stocktakes->progress($s['id']) + ['missing' => 0])) : ($s['status'] === 'cancelled' ? 'Cancelled' : self::h(self::summary($s['summary']))) ?></span>
      <time datetime="<?= self::h(str_replace(' ', 'T', $s['started_at']) . 'Z') ?>"><?= self::h($this->activity->time($s['finished_at'] ?? $s['started_at'])) ?></time></a></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</main>
<?php

        return $head . (string)ob_get_clean() . $this->page->toast($toast) . $this->page->end();
    }

    /**
     * Legacy stocktake_body_html(): the live part of the checking screen
     * (re-rendered after every tick or scan).
     *
     * @param Take $s
     */
    public function body(array $s): string
    {
        $rep = $this->stocktakes->report($s['id']);
        $tok = self::h($this->page->csrf());
        $lines = array_merge($rep['found'], $rep['missing']);
        usort($lines, static fn (array $a, array $b): int => strcmp(Text::fold($a['item_name']), Text::fold($b['item_name'])) ?: $a['id'] <=> $b['id']);
        $n = count($rep['found']);
        $pct = $rep['expected'] !== 0 ? (int)round(100 * $n / $rep['expected']) : 100;
        $o = '<div class="stk-progress" id="stk-progress"><p><b id="stk-count">' . $n . ' of ' . $rep['expected'] . '</b> found'
            . ($rep['misplaced'] !== [] ? ' · <b class="stk-mis-n">' . count($rep['misplaced']) . ' misplaced</b>' : '') . '</p>'
            . '<div class="stk-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $pct . '" aria-label="Progress"><span class="stk-fill stk-w' . (int)(round($pct / 5) * 5) . '"></span></div></div>';
        $row = static function (array $l, bool $misplaced) use ($s, $tok): string {
            $on = $misplaced || $l['found'];
            $img = ImageStore::validName($l['image']) ? '<img src="/media/thumb/' . self::h($l['image']) . '" alt="" loading="lazy">' : self::icon('box');
            $sub = $misplaced ? 'Belongs in ' . ($l['home_location'] !== '' ? self::h($l['home_location']) : 'no location') : self::h($l['home_location']);

            return '<li><form method="post" action="/stocktake/' . $s['id'] . '/check" class="stk-line' . ($misplaced ? ' is-misplaced' : '') . '">'
                . '<input type="hidden" name="csrf" value="' . $tok . '"><input type="hidden" name="item_id" value="' . (int)$l['item_id'] . '"><input type="hidden" name="found" value="' . ($on ? '0' : '1') . '">'
                . '<button class="stk-tick" aria-pressed="' . ($on ? 'true' : 'false') . '" data-id="' . (int)$l['item_id'] . '" aria-label="' . self::h(($on ? ($misplaced ? 'Remove ' : 'Untick ') : 'Found ') . $l['item_name']) . '">'
                . '<span class="stk-img">' . $img . '</span><span class="stk-text"><b>' . self::h($l['item_name']) . '</b><span>' . $sub . '</span></span>'
                . '<span class="stk-box">' . self::icon($misplaced ? 'x' : 'check') . '</span></button></form></li>';
        };
        if ($rep['misplaced'] !== []) {
            $o .= '<h2 class="set-h">Found here, belongs elsewhere</h2><ul class="stk-list" id="stk-misplaced">';
            foreach ($rep['misplaced'] as $l) {
                if ($l['item_id'] !== null) {
                    $o .= $row($l, true);
                }
            }
            $o .= '</ul>';
        }
        $o .= '<h2 class="set-h">Expected here</h2>';
        if ($lines === []) {
            return $o . '<p class="hint" id="stk-none">No items are recorded in this location yet. Scan anything you find – it will be listed as misplaced – or add what is here as new items.</p>'
                . ($s['location_id'] !== null ? '<p><a class="btn btn-sm stk-addhere" id="stk-addhere" href="/new?loc=' . $s['location_id'] . '">' . self::icon('plus') . ' Add an item here</a></p>' : '');
        }
        $o .= '<ul class="stk-list" id="stk-expected">';
        foreach ($lines as $l) {
            if ($l['item_id'] !== null) {
                $o .= $row($l, false);
            }
        }

        return $o . '</ul>';
    }

    /**
     * Legacy view_stocktake(): the checking screen.
     *
     * @param Take $s
     */
    public function take(array $s, ?string $error = null, ?string $toast = null): string
    {
        $head = $this->page->start('Stocktake: ' . $s['location_name'], 'page-stocktake');
        $head .= $this->header('Stocktake', '/stocktake');
        $tok = self::h($this->page->csrf());
        ob_start();
        ?>
<main class="wrap narrow" id="stocktake" data-id="<?= $s['id'] ?>">
  <h2 class="item-name stk-loc"><?= self::icon('pin') ?> <?= self::h($s['location_name']) ?></h2>
  <form method="post" action="/stocktake/<?= $s['id'] ?>/check" class="stk-scan" id="stk-scan" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $tok ?>"><input type="hidden" name="found" value="1">
    <label class="field-l" for="stk-code">Scan a QR label or barcode</label>
    <div class="scanrow">
      <input type="text" name="code" id="stk-code" maxlength="300" autocapitalize="off" spellcheck="false" enterkeyhint="go" placeholder="Barcode, QR link or #id"<?= $error !== null && $error !== '' ? ' aria-invalid="true" aria-describedby="stk-err"' : '' ?>>
      <button type="button" class="btn" data-scan="#stk-code" data-scan-submit hidden><?= self::icon('qr') ?> Scan</button>
      <button class="btn btn-primary" id="stk-add" aria-label="Mark as found"><?= self::icon('check') ?></button>
    </div>
    <?= $error !== null && $error !== '' ? '<div class="err" role="alert" id="stk-err">' . self::h($error) . '</div>' : '' ?>
  </form>
  <div id="stk-body" aria-live="polite"><?= $this->body($s) ?></div>
</main>
<div class="actionbar">
  <div class="actionbar-in">
    <form method="post" action="/stocktake/<?= $s['id'] ?>/cancel" data-confirm="Cancel this stocktake? Nothing is changed.">
      <input type="hidden" name="csrf" value="<?= $tok ?>"><button class="btn" id="stk-cancel"><?= self::icon('x') ?> Cancel</button></form>
    <a class="btn btn-primary" href="/stocktake/<?= $s['id'] ?>/report" id="stk-finish"><?= self::icon('check') ?> Finish</a>
  </div>
</div>
<?= ItemView::scanDialog() ?>
<?php

        return $head . (string)ob_get_clean() . $this->page->toast($toast) . $this->page->end();
    }

    /**
     * Legacy stocktake_report_html(): missing, misplaced and found.
     *
     * @param Report $rep
     */
    private function sections(array $rep): string
    {
        $sec = function (string $id, string $title, array $lines, string $empty, callable $sub): string {
            $o = '<section class="stk-rep" id="rep-' . $id . '"><h2 class="set-h">' . self::h($title) . ' <span class="muted-n">' . count($lines) . '</span></h2>';
            if ($lines === []) {
                return $o . '<p class="hint">' . self::h($empty) . '</p></section>';
            }
            $o .= '<ul class="stk-replist">';
            foreach ($lines as $l) {
                $name = $l['item_id'] !== null && $this->items->get($l['item_id']) !== null ? '<a href="/item/' . $l['item_id'] . '">' . self::h($l['item_name']) . '</a>' : '<span>' . self::h($l['item_name']) . '</span>';
                $o .= '<li>' . $name . '<small>' . $sub($l) . '</small></li>';
            }

            return $o . '</ul></section>';
        };
        $home = static fn (array $l): string => self::h($l['home_location']);

        return $sec('missing', 'Missing', $rep['missing'], 'Everything expected here was found.', $home)
            . $sec('misplaced', 'Misplaced', $rep['misplaced'], 'Nothing from other locations turned up.', static fn (array $l): string => 'belongs in ' . ($l['home_location'] !== '' ? self::h($l['home_location']) : 'no location') . ($l['moved'] ? ' · <b>moved here</b>' : ''))
            . $sec('found', 'Found', $rep['found'], 'Nothing ticked yet.', $home);
    }

    /**
     * Legacy view_stocktake_report(): finish an open stocktake, or the report of a finished one.
     *
     * @param Take $s
     */
    public function report(array $s, ?string $toast = null): string
    {
        $rep = $this->stocktakes->report($s['id']);
        $open = $s['status'] === 'open';
        $head = $this->page->start(($open ? 'Finish stocktake: ' : 'Stocktake report: ') . $s['location_name'], 'page-stocktake');
        $head .= $this->header($open ? 'Finish stocktake' : 'Stocktake report', $open ? '/stocktake/' . $s['id'] : '/stocktake');
        $sum = $open ? ['expected' => $rep['expected'], 'found' => count($rep['found']), 'missing' => count($rep['missing']), 'misplaced' => count($rep['misplaced'])] : $s['summary'];
        ob_start();
        ?>
<main class="wrap narrow" id="stk-report">
  <h2 class="item-name stk-loc"><?= self::icon('pin') ?> <?= self::h($s['location_name']) ?></h2>
  <p class="lead" id="stk-summary"><?= self::h(self::summary($sum)) ?><?= !$open ? ' · ' . ($s['status'] === 'cancelled' ? 'cancelled' : 'finished') . ' ' . self::h($this->activity->time((string)$s['finished_at'])) : '' ?></p>
  <div class="stk-tiles">
    <div class="stk-tile t-found"><b><?= count($rep['found']) ?></b><span>found</span></div>
    <div class="stk-tile t-missing"><b><?= count($rep['missing']) ?></b><span>missing</span></div>
    <div class="stk-tile t-misplaced"><b><?= count($rep['misplaced']) ?></b><span>misplaced</span></div>
  </div>
  <?= $this->sections($rep) ?>
  <?php if ($open): ?>
  <form method="post" action="/stocktake/<?= $s['id'] ?>/finish" class="setform" id="stk-finish-form">
    <input type="hidden" name="csrf" value="<?= self::h($this->page->csrf()) ?>">
    <?php if ($rep['misplaced']): ?>
    <label class="check"><input type="checkbox" name="moves" value="1" id="stk-moves"<?= $this->applyMoves ? ' checked' : '' ?>>
      <span>Move the <?= count($rep['misplaced']) === 1 ? 'misplaced item' : count($rep['misplaced']) . ' misplaced items' ?> to <b><?= self::h($s['location_name']) ?></b></span></label>
    <?php endif; ?>
    <p class="hint">Missing items stay where the catalog says they are; the report is kept in the stocktake history.</p>
    <button class="btn btn-primary btn-wide" id="stk-done"><?= self::icon('check') ?> Finish stocktake</button>
    <a class="btn btn-wide" href="/stocktake/<?= $s['id'] ?>">Keep checking</a>
  </form>
  <?php endif; ?>
</main>
<?php

        return $head . (string)ob_get_clean() . $this->page->toast($toast) . $this->page->end();
    }
}
