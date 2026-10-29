<?php

declare(strict_types=1);

namespace Apotheke\Activity\Views;

use Apotheke\Layout\Icons;
use Apotheke\Layout\Page;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The item history as HTML (legacy activity_html(), activity_items_html()
 * and view_activity()), byte-identical to legacy. Every value is escaped here.
 */
final class ActivityView
{
    private const FIELDS = ['name' => 'Name', 'category' => 'Category', 'notes' => 'Notes', 'value' => 'Purchase price', 'purchase_date' => 'Purchase date', 'expires_on' => 'Expires', 'serial' => 'Serial no.', 'barcode' => 'Barcode', 'tags' => 'Tags', 'min_qty' => 'Running low at'];

    private const ICONS = ['restored' => 'undo', 'merged' => 'merge', 'attachment' => 'paperclip', 'created' => 'plus', 'edited' => 'edit', 'moved' => 'pin', 'quantity' => 'box', 'photo' => 'camera', 'deleted' => 'trash', 'lent' => 'handoff', 'returned' => 'undo'];

    /**
     * @param string $currency the currency setting
     * @param string $timeFormat the time_format setting
     * @param string $timezone the timezone setting
     */
    public function __construct(
        private readonly Page $page,
        private readonly string $currency,
        private readonly string $timeFormat,
        private readonly string $timezone,
    ) {
    }

    private static function h(?string $s): string
    {
        return Page::e($s);
    }

    /** Legacy fmt_time(): a stored UTC time in the app's zone. */
    public function time(string $utc): string
    {
        try {
            try {
                $tz = new DateTimeZone($this->timezone);
            } catch (Throwable) {
                $tz = new DateTimeZone(date_default_timezone_get());
            }

            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('j M Y, ' . $this->timeFormat);
        } catch (Throwable) {
            return $utc;
        }
    }

    /** @param array{action:string,details:array<string,mixed>} $e one entry, already escaped */
    public function text(array $e): string
    {
        $d = $e['details'];
        $h = static fn (mixed $v): string => self::h(is_scalar($v) ? (string)$v : '');
        $q = static fn (mixed $v): string => ($v === '' || $v === null) ? '<i>empty</i>' : '“' . $h($v) . '”';
        $none = static fn (mixed $v): string => (is_scalar($v) ? (string)$v : '') === '' ? '<i>none</i>' : '<b>' . $h($v) . '</b>';
        $int = static fn (mixed $v): int => is_scalar($v) ? (int)$v : (is_array($v) && $v !== [] ? 1 : 0);
        switch ($e['action']) {
            case 'created':
                $t = 'Created' . (!empty($d['location']) ? ' in <b>' . $h($d['location']) . '</b>' : '') . (isset($d['quantity']) ? ' · quantity ' . $int($d['quantity']) : '') . (isset($d['min_qty']) ? ' · running low at ' . $int($d['min_qty']) : '')
                    . (!empty($d['photo']) ? ' · with photo' : '') . (!empty($d['tags']) ? ' · tags ' . self::h(implode(', ', (array)$d['tags'])) : '')
                    . (!empty($d['copied_from']) ? ' · copied from “' . $h($d['copied_from']) . '”' : '');
                break;
            case 'edited':
                $parts = [];
                foreach ((array)($d['changes'] ?? []) as $k => $ch) {
                    $label = self::FIELDS[$k] ?? ucfirst((string)$k);
                    $val = $k === 'value' ? fn (mixed $v): string => $v === '' ? '<i>empty</i>' : self::h((is_scalar($v) ? (string)$v : '') . ' ' . $this->currency) : $q;
                    $ch = (array)$ch;
                    $parts[] = '<li><span class="act-field">' . self::h($label) . '</span> ' . $val($ch[0] ?? '') . ' → ' . $val($ch[1] ?? '') . '</li>';
                }
                $t = 'Edited' . (!empty($d['reason']) ? ' (' . $h($d['reason']) . ')' : '') . '<ul class="act-changes">' . implode('', $parts) . '</ul>';
                break;
            case 'moved':
                $t = 'Moved from ' . $none($d['from'] ?? '') . ' to ' . $none($d['to'] ?? '') . (!empty($d['reason']) ? ' (' . $h($d['reason']) . ')' : '');
                break;
            case 'quantity':
                $t = 'Quantity <b>' . $int($d['from'] ?? 0) . '</b> → <b>' . $int($d['to'] ?? 0) . '</b>';
                break;
            case 'photo':
                $change = is_scalar($d['change'] ?? null) ? (string)$d['change'] : '';
                $t = ['added' => 'Added a photo', 'removed' => 'Removed the cover photo', 'replaced' => 'Replaced the cover photo', 'cover' => 'Chose a new cover photo',
                    'reordered' => 'Reordered photos', 'deleted' => 'Deleted a photo', 'edited' => 'Edited a photo'][$change] ?? 'Changed photos';
                if ($change === 'edited') {
                    $t .= (!empty($d['rotate']) ? ' · rotated ' . $int($d['rotate']) . '°' : '') . (isset($d['size']) ? ' · ' . $h($d['size']) . ' px' : '');
                }
                if (isset($d['count'])) {
                    $t .= ' · ' . $int($d['count']) . ' total';
                }
                break;
            case 'lent':
                $t = (!empty($d['before']) ? 'Lending changed: now with ' : 'Lent to ') . '<b>' . $h($d['to'] ?? '') . '</b>'
                    . (!empty($d['at']) ? ' on ' . $h($d['at']) : '') . (!empty($d['due']) ? ' · due ' . $h($d['due']) : '');
                break;
            case 'returned':
                $t = 'Returned by <b>' . $h($d['from'] ?? '') . '</b>' . (isset($d['days']) ? ' after ' . $int($d['days']) . ' day' . ($int($d['days']) === 1 ? '' : 's') : '');
                break;
            case 'attachment':
                $t = (($d['change'] ?? '') === 'removed' ? 'Removed attachment ' : 'Attached ') . '“' . $h($d['file'] ?? '') . '”';
                break;
            case 'merged':
                $t = 'Merged <b>“' . $h($d['from'] ?? '') . '”</b> into this item';
                $qty = (array)($d['quantity'] ?? []);
                if (isset($qty[0], $qty[1]) && $qty[0] !== $qty[1]) {
                    $t .= ' · quantity <b>' . $int($qty[0]) . '</b> → <b>' . $int($qty[1]) . '</b>';
                }
                if (!empty($d['photos'])) {
                    $t .= ' · ' . $int($d['photos']) . ' photo' . ($int($d['photos']) === 1 ? '' : 's');
                }
                if (!empty($d['attachments'])) {
                    $t .= ' · ' . $int($d['attachments']) . ' attachment' . ($int($d['attachments']) === 1 ? '' : 's');
                }
                if (!empty($d['filled'])) {
                    $t .= ' · filled in ' . self::h(implode(', ', (array)$d['filled']));
                }
                if (($d['lending'] ?? '') === 'copied') {
                    $t .= ' · loan taken over';
                }
                break;
            case 'restored':
                $t = 'Restored (deleting was undone)';
                break;
            case 'deleted':
                $t = 'Deleted' . (!empty($d['location']) ? ' from <b>' . $h($d['location']) . '</b>' : '');
                break;
            default:
                $t = self::h($e['action']);
        }

        return $t;
    }

    /** @param list<array{id:int,item_id:?int,item_name:string,action:string,details:array<string,mixed>,created_at:string,actor:?string,exists:bool}> $entries */
    public function items(array $entries, bool $withItem): string
    {
        $o = '<ol class="activity">';
        foreach ($entries as $e) {
            $o .= '<li class="act act-' . self::h($e['action']) . '"><span class="act-ic">' . Icons::svg(self::ICONS[$e['action']] ?? 'clock') . '</span><div class="act-body">';
            if ($withItem) {
                $o .= $e['exists'] && $e['item_id'] !== null
                    ? '<a class="act-item" href="/item/' . $e['item_id'] . '">' . self::h($e['item_name']) . '</a>'
                    : '<span class="act-item act-gone">' . self::h($e['item_name']) . '</span>';
            }
            $o .= '<div class="act-text">' . $this->text($e) . '</div><time datetime="' . self::h(str_replace(' ', 'T', $e['created_at']) . 'Z') . '">' . self::h($this->time($e['created_at'])) . '</time>'
                . (!empty($e['actor']) ? ' <span class="act-by">· ' . self::h($e['actor']) . '</span>' : '') . '</div></li>';
        }

        return $o . '</ol>';
    }

    /**
     * Legacy view_activity(): all recent activity, or one item's history.
     *
     * @param list<array{id:int,item_id:?int,item_name:string,action:string,details:array<string,mixed>,created_at:string,actor:?string,exists:bool}> $entries
     * @param array{id:int,name:string}|null $item
     */
    public function page(array $entries, int $page, bool $more, ?array $item = null): string
    {
        $head = $this->page->start($item !== null ? 'History: ' . $item['name'] : 'Recent activity', 'page-activity');
        $qs = static fn (int $p): string => ($q = http_build_query(array_filter(['item' => $item !== null ? $item['id'] : null, 'page' => $p > 1 ? $p : null]))) !== '' ? '?' . $q : '';
        ob_start();
        ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="<?= $item !== null ? '/item/' . $item['id'] : '/' ?>" data-back aria-label="Back"><?= Icons::svg('back') ?></a>
    <h1 class="title-sm"><?= $item !== null ? 'History' : 'Recent activity' ?></h1>
    <span class="spacer"></span>
  </div>
</header>
<main class="wrap narrow" id="activity">
  <?php if ($item !== null): ?><h2 class="item-name"><?= self::h($item['name']) ?></h2><?php endif; ?>
  <?php if ($entries === []): ?>
  <div class="empty"><?= Icons::svg('clock') ?><p class="empty-title">No activity yet.</p><p class="empty-sub">Changes to your items show up here.</p></div>
  <?php else: ?>
  <?= $this->items($entries, $item === null) ?>
  <nav class="pager" aria-label="Pages">
    <?php if ($page > 1): ?><a class="btn" href="/activity<?= self::h($qs($page - 1)) ?>" rel="prev">Newer</a><?php endif; ?>
    <?php if ($more): ?><a class="btn" href="/activity<?= self::h($qs($page + 1)) ?>" rel="next" id="act-older">Older</a><?php endif; ?>
  </nav>
  <?php endif; ?>
</main>
<?php

        return $head . (string)ob_get_clean() . $this->page->end();
    }
}
