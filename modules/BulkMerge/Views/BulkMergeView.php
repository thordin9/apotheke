<?php

declare(strict_types=1);

namespace Apotheke\BulkMerge\Views;

use Apotheke\Catalog\Items\ItemInput;
use Apotheke\Layout\Icons;
use Apotheke\Layout\Page;
use Apotheke\Media\ImageStore;

/**
 * The bulk delete confirmation and the two merge pages (legacy views.php:
 * view_bulk_delete(), view_merge_pick(), view_merge_confirm()),
 * byte-identical to legacy. Every value is escaped here.
 */
final class BulkMergeView
{
    public function __construct(
        private readonly Page $page,
        private readonly int $undoSeconds,
        private readonly string $currency,
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

    /**
     * Legacy view_bulk_delete().
     *
     * @param list<array<string,mixed>> $items
     */
    public function bulkDelete(array $items, string $back): string
    {
        $head = $this->page->start('Delete items');
        $n = count($items);
        ob_start();
        ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="<?= self::h($back) ?>" aria-label="Back"><?= self::icon('back') ?></a>
    <h1 class="title-sm">Delete items</h1>
    <span class="spacer"></span>
  </div>
</header>
<main class="wrap narrow">
  <h2 class="item-name">Delete <?= $n ?> item<?= $n === 1 ? '' : 's' ?>?</h2>
  <p class="lead">Their photos are deleted too. The history keeps a record. <?= $this->undoSeconds > 0 ? 'You can undo this for ' . $this->undoSeconds . ' seconds afterwards.' : 'This can’t be undone.' ?></p>
  <ul class="bulklist" id="bulklist">
    <?php foreach ($items as $it): ?><li><?= self::h((string)$it['name']) ?><?= $it['location'] !== '' ? ' <span class="hint">· ' . self::h((string)$it['location']) . '</span>' : '' ?></li><?php endforeach; ?>
  </ul>
  <form method="post" action="/bulk" class="itemform" id="bulkdel">
    <input type="hidden" name="csrf" value="<?= self::h($this->page->csrf()) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="confirm" value="1">
    <input type="hidden" name="back" value="<?= self::h($back) ?>">
    <?php foreach ($items as $it): ?><input type="hidden" name="ids[]" value="<?= (int)$it['id'] ?>"><?php endforeach; ?>
    <div class="actionbar actionbar-form">
      <div class="actionbar-in">
        <a class="btn" href="<?= self::h($back) ?>">Cancel</a>
        <button class="btn btn-danger-solid" id="bulk-confirm"><?= self::icon('trash') ?> Delete <?= $n ?></button>
      </div>
    </div>
  </form>
</main>
<?php

        return $head . (string)ob_get_clean() . $this->page->end();
    }

    /**
     * Legacy view_merge_pick(): pick the item to keep.
     *
     * @param array<string,mixed> $it
     * @param list<array<string,mixed>> $cands
     */
    public function mergePick(array $it, array $cands, string $q): string
    {
        $id = (int)$it['id'];
        $head = $this->page->start('Merge');
        ob_start();
        ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="/item/<?= $id ?>" aria-label="Back"><?= self::icon('back') ?></a>
    <h1 class="title-sm">Merge duplicates</h1>
    <span class="spacer"></span>
  </div>
</header>
<main class="wrap narrow">
  <h2 class="item-name">Merge “<?= self::h((string)$it['name']) ?>” into…</h2>
  <p class="lead">Pick the item to keep. Its quantity grows by <?= (int)$it['quantity'] ?>; photos, attachments, tags, history and empty fields come over from “<?= self::h((string)$it['name']) ?>”, which is then removed. You confirm on the next screen.</p>
  <form class="search merge-search" method="get" action="/item/<?= $id ?>/merge" role="search">
    <?= self::icon('search') ?>
    <input type="search" name="q" value="<?= self::h($q) ?>" placeholder="Search for the item to keep" autocomplete="off" enterkeyhint="search" id="merge-q" aria-label="Search for the item to keep">
  </form>
  <?php if ($cands): ?>
  <ul class="merge-list" id="merge-list">
    <?php foreach ($cands as $c): ?>
    <li><a class="merge-pick" href="/item/<?= $id ?>/merge?into=<?= (int)$c['id'] ?>" data-id="<?= (int)$c['id'] ?>">
      <?= ImageStore::validName(is_string($c['image'] ?? null) ? $c['image'] : null) ? '<img src="/media/thumb/' . self::h((string)$c['image']) . '" alt="" loading="lazy">' : '<span class="ph">' . self::icon('box') . '</span>' ?>
      <span class="merge-name"><b><?= self::h((string)$c['name']) ?></b><small><?= self::h((string)$c['location'] !== '' ? (string)$c['location'] : 'No location') ?> · qty <?= (int)$c['quantity'] ?></small></span>
      <?= self::icon('back') ?></a></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
  <div class="empty"><?= self::icon('search') ?><p class="empty-title"><?= $q === '' ? 'No similar items found.' : 'Nothing matches “' . self::h($q) . '”.' ?></p><p class="hint">Search by name, location or barcode.</p></div>
  <?php endif; ?>
</main>
<?php

        return $head . (string)ob_get_clean() . $this->page->end();
    }

    /**
     * Legacy view_merge_confirm(): what will happen, then one button.
     *
     * @param array{target:array<string,mixed>,source:array<string,mixed>,result:array<string,mixed>,fills:array<string,string>,photos:int,attachments:int,history:int,lending:string} $p
     */
    public function mergeConfirm(array $p): string
    {
        $src = $p['source'];
        $dst = $p['target'];
        $in = $p['result'];
        $sid = (int)$src['id'];
        $tid = (int)$dst['id'];
        $head = $this->page->start('Merge');
        $row = static fn (string $label, string $html): string => '<li><span class="act-field">' . self::h($label) . '</span> ' . $html . '</li>';
        $rows = [$row('Quantity', '<b>' . (int)$dst['quantity'] . '</b> + <b>' . (int)$src['quantity'] . '</b> = <b>' . (int)$in['quantity'] . '</b>')];
        if ($p['photos'] !== 0) {
            $rows[] = $row('Photos', '+' . $p['photos'] . ' (after the current ones)');
        }
        if ($p['attachments'] !== 0) {
            $rows[] = $row('Attachments', '+' . $p['attachments']);
        }
        $newTags = array_diff($in['tags'], array_column($dst['tags'], 'name'));
        if ($newTags !== []) {
            $rows[] = $row('Tags', '+ ' . self::h(implode(', ', $newTags)));
        }
        foreach ($p['fills'] as $k => $label) {
            if ($k === 'notes') {
                $rows[] = $row('Notes', 'the source’s notes are appended');
                continue;
            }
            $v = match ($k) {
                'location_id' => (string)$src['location'], 'category_id' => (string)$src['category'],
                'value_cents' => ItemInput::moneyInput($in['value_cents'] === null ? null : (int)$in['value_cents']) . ' ' . $this->currency,
                default => (string)$in[$k],
            };
            $rows[] = $row($label, 'empty → “' . self::h($v) . '”');
        }
        if ($p['lending'] === 'copied') {
            $rows[] = $row('Lending', 'lent to <b>' . self::h((string)$src['lent_to']) . '</b> is taken over');
        }
        if ($p['lending'] === 'kept') {
            $rows[] = $row('Lending', 'both are lent: the loan to <b>' . self::h((string)$dst['lent_to']) . '</b> is kept, the other one is dropped');
        }
        if (!empty($src['favourite']) && empty($dst['favourite'])) {
            $rows[] = $row('Favourite', '★ is kept');
        }
        if ($p['history'] !== 0) {
            $rows[] = $row('History', $p['history'] . ' entr' . ($p['history'] === 1 ? 'y moves' : 'ies move') . ' over');
        }
        ob_start();
        ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="/item/<?= $sid ?>/merge" aria-label="Back"><?= self::icon('back') ?></a>
    <h1 class="title-sm">Merge duplicates</h1>
    <span class="spacer"></span>
  </div>
</header>
<main class="wrap narrow">
  <h2 class="item-name">Merge into “<?= self::h((string)$dst['name']) ?>”?</h2>
  <div class="merge-pair" id="merge-pair">
    <div class="merge-card merge-src"><small>Removed</small><b><?= self::h((string)$src['name']) ?></b><span><?= self::h($src['location'] !== '' ? (string)$src['location'] : 'No location') ?></span></div>
    <span class="merge-arrow" aria-hidden="true"><?= self::icon('merge') ?></span>
    <div class="merge-card merge-dst"><small>Kept</small><b><?= self::h((string)$dst['name']) ?></b><span><?= self::h($dst['location'] !== '' ? (string)$dst['location'] : 'No location') ?></span></div>
  </div>
  <ul class="act-changes merge-changes" id="merge-changes"><?= implode('', $rows) ?></ul>
  <p class="hint">Fields the kept item already has stay as they are. Everything happens in one step; if anything fails, nothing changes.</p>
  <form method="post" action="/item/<?= $sid ?>/merge" class="itemform" id="mergeform">
    <input type="hidden" name="csrf" value="<?= self::h($this->page->csrf()) ?>">
    <input type="hidden" name="into" value="<?= $tid ?>">
    <input type="hidden" name="confirm" value="1">
    <div class="actionbar actionbar-form">
      <div class="actionbar-in">
        <a class="btn" href="/item/<?= $sid ?>">Cancel</a>
        <button class="btn btn-primary" id="merge-confirm"><?= self::icon('merge') ?> Merge</button>
      </div>
    </div>
  </form>
</main>
<?php

        return $head . (string)ob_get_clean() . $this->page->end();
    }
}
