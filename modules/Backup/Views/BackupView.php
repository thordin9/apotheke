<?php

declare(strict_types=1);

namespace Apotheke\Backup\Views;

use Apotheke\Activity\Views\ActivityView;
use Apotheke\Backup\Backups;
use Apotheke\Layout\Icons;
use Apotheke\Layout\Page;

/**
 * The Backup & restore page (legacy view_backup() and backup_table_rows()),
 * byte-identical to legacy. Every value is escaped here.
 */
final class BackupView
{
    private const LABELS = ['items' => 'Items', 'locations' => 'Locations', 'categories' => 'Categories', 'tags' => 'Tags', 'item_photos' => 'Photos', 'activity' => 'History entries', 'stocktakes' => 'Stocktakes'];

    public function __construct(private readonly Page $page, private readonly ActivityView $activity)
    {
    }

    private static function h(?string $s): string
    {
        return Page::e($s);
    }

    /**
     * @param array<string,int> $current
     * @param array<string,int>|null $incoming
     */
    private static function tableRows(array $current, ?array $incoming = null): string
    {
        $o = '<table class="btable"><thead><tr><th scope="col"></th><th scope="col">Now</th>' . ($incoming !== null ? '<th scope="col">In backup</th>' : '') . '</tr></thead><tbody>';
        foreach (self::LABELS as $t => $l) {
            if (!isset($current[$t]) && !isset($incoming[$t])) {
                continue;
            }
            $o .= '<tr><th scope="row">' . $l . '</th><td>' . (int)($current[$t] ?? 0) . '</td>' . ($incoming !== null ? '<td data-t="' . $t . '"><b>' . (int)($incoming[$t] ?? 0) . '</b></td>' : '') . '</tr>';
        }

        return $o . '</tbody></table>';
    }

    /**
     * Legacy view_backup(). $preview (the dry run) shows what a restore
     * would do and asks to confirm.
     *
     * @param array<string,int> $current
     * @param array{token:string,tables:array<string,int>,photos:int,created_at:string,driver:string,warnings:list<string>}|null $preview
     */
    public function page(array $current, ?string $error = null, ?array $preview = null, ?string $toast = null): string
    {
        $head = $this->page->start('Backup & restore', 'page-backup');
        $tok = self::h($this->page->csrf());
        ob_start();
        ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="/" data-back aria-label="Back"><?= Icons::svg('back') ?></a>
    <h1 class="title-sm">Backup &amp; restore</h1>
    <span class="spacer"></span>
  </div>
</header>
<main class="wrap narrow">
  <?php if ($error !== null): ?><div class="err" role="alert" id="backup-err"><?= self::h($error) ?></div><?php endif; ?>
  <?php if ($preview !== null): ?>
  <section class="panel panel-warn" id="restore-preview" aria-labelledby="rp-title">
    <h2 id="rp-title">Restore this backup?</h2>
    <p>Backup from <b><?= self::h($preview['created_at'] !== '' ? $this->activity->time($preview['created_at']) : 'an unknown date') ?></b> (<?= self::h($preview['driver'] === 'pgsql' ? 'PostgreSQL' : 'SQLite') ?>), with <b><?= self::h(Backups::summaryText($preview['tables'], $preview['photos'])) ?></b>.</p>
    <?= self::tableRows($current, $preview['tables']) ?>
    <?php foreach ($preview['warnings'] as $w): ?><p class="hint warn">⚠ <?= self::h($w) ?></p><?php endforeach; ?>
    <p class="hint"><b>Everything currently in the catalog will be replaced.</b> Download a backup first if you might want it back.</p>
    <form method="post" action="/backup/restore/confirm" class="restore-confirm">
      <input type="hidden" name="csrf" value="<?= $tok ?>">
      <input type="hidden" name="token" value="<?= self::h($preview['token']) ?>">
      <label class="check check-ok"><input type="checkbox" name="confirm" value="1" required id="restore-ok"> Yes, replace my catalog with this backup</label>
      <div class="row-btns">
        <a class="btn" href="/backup">Cancel</a>
        <button class="btn btn-danger-solid" id="restore-go">Restore</button>
      </div>
    </form>
  </section>
  <?php endif; ?>
  <section class="panel" aria-labelledby="bk-title">
    <h2 id="bk-title"><?= Icons::svg('download') ?> Download a backup</h2>
    <p class="hint">One zip file with all items, locations, categories, tags<?= isset($current['activity']) ? ', history' : '' ?> and photos. It works with both SQLite and PostgreSQL installs.</p>
    <?= self::tableRows($current) ?>
    <a class="btn btn-primary btn-block" href="/backup.zip" id="backup-dl" download><?= Icons::svg('archive') ?> Download backup (.zip)</a>
  </section>
  <section class="panel" aria-labelledby="rs-title">
    <h2 id="rs-title"><?= Icons::svg('archive') ?> Restore from a backup</h2>
    <p class="hint">Choose an Apotheke backup zip. You'll see what it contains before anything changes.</p>
    <form method="post" action="/backup/restore" enctype="multipart/form-data" id="restore-form" class="restore-form">
      <input type="hidden" name="csrf" value="<?= $tok ?>">
      <label class="btn btn-block filepick"><input type="file" name="backup" accept=".zip,application/zip" required id="restore-file" class="file"> <span id="restore-name">Choose backup file…</span></label>
      <button class="btn btn-block" id="restore-check">Check backup</button>
    </form>
    <p class="hint">Large backups can also be restored on the server: <code>php bin/restore.php backup.zip</code>.</p>
  </section>
</main>
<?php if ($toast !== null): ?><div class="toast" role="status"><?= self::h($toast) ?></div><?php endif; ?>
<?php
        return $head . (string)ob_get_clean() . $this->page->end();
    }
}
