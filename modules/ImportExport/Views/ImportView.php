<?php

declare(strict_types=1);

namespace Apotheke\ImportExport\Views;

use Apotheke\Activity\Views\ActivityView;
use Apotheke\ImportExport\CsvCells;
use Apotheke\ImportExport\Imports;
use Apotheke\Layout\Icons;
use Apotheke\Layout\Page;

/**
 * The CSV import screens (legacy import_views.php): upload and history
 * (/import), mapping and preview (/import/{id}), byte-identical to legacy.
 * Every value is escaped here.
 *
 * @phpstan-import-type Import from Imports
 * @phpstan-import-type Run from Imports
 */
final class ImportView
{
    public function __construct(private readonly Page $page, private readonly ActivityView $activity)
    {
    }

    private static function h(?string $s): string
    {
        return Page::e($s);
    }

    private static function s(mixed $v): string
    {
        return is_scalar($v) ? (string)$v : '';
    }

    private static function icon(string $name): string
    {
        return Icons::svg($name);
    }

    /**
     * Legacy view_import_home().
     *
     * @param list<array<string,mixed>> $recent
     */
    public function home(?string $error, ?string $toast, array $recent): string
    {
        $head = $this->page->start('Import CSV', 'page-import') . $this->page->simpleHeader('Import CSV', '/');
        $tok = self::h($this->page->csrf());
        ob_start();
        ?>
<main class="wrap narrow">
  <p class="hint">Add or update many items at once from a spreadsheet saved as CSV. You choose which column is what, see a
    preview with every problem, and only then import – all in one go, or nothing. A file from <a href="/export.csv">Export CSV</a>
    imports as it is (its <code>id</code> column updates those items).</p>
  <?php if ($error): ?><div class="err" role="alert" id="imp-err"><?= self::h($error) ?></div><?php endif; ?>
  <form method="post" action="/import" enctype="multipart/form-data" class="set-card setform" id="imp-upload">
    <input type="hidden" name="csrf" value="<?= $tok ?>">
    <label class="field"><span>CSV file <em>max. 5 MB, <?= number_format(CsvCells::MAX_ROWS) ?> rows; comma, semicolon or tab</em></span>
      <input type="file" name="csv" id="imp-file" accept=".csv,text/csv,text/plain,.tsv" required></label>
    <button class="btn btn-primary btn-wide" id="imp-go"><?= self::icon('download') ?> Upload and map columns</button>
  </form>
  <?php if ($recent): ?>
  <section class="set-card" id="imp-history"><h2 class="set-h">Recent imports</h2><ul class="imp-hist">
    <?php foreach ($recent as $r): $res = $r['result'] ? json_decode(self::s($r['result']), true) : null; ?>
    <li><?php if ($r['status'] !== 'done'): ?><a href="/import/<?= (int)$r['id'] ?>"><?= self::h(self::s($r['file_name'])) ?></a><?php else: ?><b><?= self::h(self::s($r['file_name'])) ?></b><?php endif; ?>
      <small><?= self::h($this->activity->time(self::s($r['finished_at'] ?: $r['created_at']))) ?><?= $r['actor'] ? ' · ' . self::h(self::s($r['actor'])) : '' ?> · <?= $res ? self::h(Imports::summaryText(is_array($res) ? $res : [])) : 'not imported yet (' . (int)$r['total_rows'] . ' rows)' ?></small></li>
    <?php endforeach; ?>
  </ul></section>
  <?php endif; ?>
</main>
<?php if ($toast): ?><div class="toast" role="status"><?= self::h($toast) ?></div><?php endif; ?>
<?php
        return $head . (string)ob_get_clean() . $this->page->end();
    }

    /**
     * Legacy view_import_map().
     *
     * @param Import $imp
     * @param list<string> $header
     * @param list<list<string>> $rows
     * @param Run|null $preview
     */
    public function map(array $imp, array $header, array $rows, ?array $preview, ?string $error): string
    {
        $head = $this->page->start('Import ' . $imp['file_name'], 'page-import') . $this->page->simpleHeader('Import CSV', '/import');
        $tok = self::h($this->page->csrf());
        $fields = Imports::fields();
        $map = $imp['mapping'];
        $opt = $imp['options'];
        ob_start();
        ?>
<main class="wrap narrow">
  <p class="hint"><b><?= self::h($imp['file_name']) ?></b> · <?= count($rows) ?> <?= count($rows) === 1 ? 'row' : 'rows' ?>, <?= count($header) ?> columns. Pick what each column holds; columns set to “Ignore” are skipped.</p>
  <?php if ($error): ?><div class="err" role="alert" id="imp-err"><?= self::h($error) ?></div><?php endif; ?>
  <form method="post" action="/import/<?= $imp['id'] ?>/preview" class="setform" id="imp-map">
    <input type="hidden" name="csrf" value="<?= $tok ?>">
    <ol class="imp-cols" id="imp-cols">
    <?php foreach ($header as $i => $hd): $samples = array_slice(array_values(array_filter(array_map(static fn (array $r): string => trim($r[$i] ?? ''), array_slice($rows, 0, 20)), static fn (string $v): bool => $v !== '')), 0, 2); $sel = self::s($map[$i] ?? ''); ?>
      <li class="imp-col">
        <label class="field"><span class="imp-head"><b><?= self::h($hd !== '' ? $hd : 'Column ' . ($i + 1)) ?></b><?php if ($samples): ?><small>e.g. <?= self::h(implode(' · ', array_map(static fn (string $s): string => mb_strimwidth($s, 0, 40, '…'), $samples))) ?></small><?php endif; ?></span>
          <select name="col[<?= $i ?>]" id="imp-col-<?= $i ?>"<?= $sel === '' ? ' class="imp-ignored"' : '' ?>>
            <option value="">Ignore</option>
            <?php foreach ($fields as $k => [$label]): ?><option value="<?= $k ?>"<?= $sel === $k ? ' selected' : '' ?>><?= self::h($label) ?></option><?php endforeach; ?>
          </select></label>
      </li>
    <?php endforeach; ?>
    </ol>
    <label class="check"><input type="checkbox" name="create" value="1" id="imp-create"<?= !empty($opt['create']) ? ' checked' : '' ?>><span>Create missing locations, categories and tags<small>Off: rows naming one that doesn’t exist are errors.</small></span></label>
    <label class="check"><input type="checkbox" name="skip_errors" value="1" id="imp-skip"<?= !empty($opt['skip_errors']) ? ' checked' : '' ?>><span>Skip rows with errors<small>Off: nothing is imported while any row has an error.</small></span></label>
    <button class="btn btn-wide" id="imp-preview"><?= self::icon('search') ?> Preview (dry run)</button>
  </form>
  <?php if ($preview !== null): $ok = $preview['errors'] === 0 || !empty($opt['skip_errors']); $n = $preview['created'] + $preview['updated']; ?>
  <section class="set-card imp-preview" id="imp-result" aria-live="polite">
    <h2 class="set-h">Preview</h2>
    <p class="imp-sum" id="imp-sum"><span class="imp-n imp-new"><b><?= $preview['created'] ?></b> new</span><span class="imp-n imp-upd"><b><?= $preview['updated'] ?></b> updated</span><?php if ($preview['unchanged']): ?><span class="imp-n imp-same"><b><?= $preview['unchanged'] ?></b> unchanged</span><?php endif; ?><span class="imp-n imp-bad"><b><?= $preview['errors'] ?></b> <?= $preview['errors'] === 1 ? 'error' : 'errors' ?></span></p>
    <?php $made = array_filter($preview['made']); if ($made): ?><p class="hint" id="imp-made">Will create <?= self::h(implode('; ', array_map(static fn (string $k, array $l): string => ($k === 'category' ? 'categories' : $k . 's') . ': ' . implode(', ', $l), array_keys($made), $made))) ?>.</p><?php endif; ?>
    <?php $bad = array_values(array_filter($preview['rows'], static fn (array $r): bool => $r['state'] === 'error')); if ($bad): ?>
    <ul class="imp-errors" id="imp-errors"><?php foreach (array_slice($bad, 0, 100) as $r): ?><li><b>Row <?= $r['line'] ?></b><?= $r['name'] !== '' ? ' “' . self::h($r['name']) . '”' : '' ?>: <?= self::h($r['error']) ?></li><?php endforeach; ?>
      <?php if (count($bad) > 100): ?><li>… and <?= count($bad) - 100 ?> more</li><?php endif; ?></ul>
    <?php endif; ?>
    <ol class="imp-rows" id="imp-rows"><?php foreach (array_slice($preview['rows'], 0, Imports::PREVIEW_ROWS) as $r): ?>
      <li class="imp-row imp-<?= self::h($r['state']) ?>"><span class="imp-line"><?= $r['line'] ?></span><span class="imp-name"><?= self::h($r['name'] !== '' ? $r['name'] : '—') ?></span><span class="imp-state"><?= ['new' => 'New', 'update' => 'Update', 'same' => 'Unchanged', 'error' => 'Error'][$r['state']] ?? '' ?></span></li>
    <?php endforeach; ?></ol>
    <?php if (count($preview['rows']) > Imports::PREVIEW_ROWS): ?><p class="hint">First <?= Imports::PREVIEW_ROWS ?> of <?= count($preview['rows']) ?> rows shown.</p><?php endif; ?>
    <form method="post" action="/import/<?= $imp['id'] ?>/commit" id="imp-commit-form">
      <input type="hidden" name="csrf" value="<?= $tok ?>">
      <?php foreach ($map as $i => $k): ?><input type="hidden" name="col[<?= (int)$i ?>]" value="<?= self::h(self::s($k)) ?>"><?php endforeach; ?>
      <?php if (!empty($opt['create'])): ?><input type="hidden" name="create" value="1"><?php endif; ?>
      <?php if (!empty($opt['skip_errors'])): ?><input type="hidden" name="skip_errors" value="1"><?php endif; ?>
      <button class="btn btn-primary btn-wide" id="imp-commit"<?= $ok && ($n + $preview['unchanged']) > 0 ? '' : ' disabled' ?>><?= self::icon('check') ?> Import <?= $n ?> <?= $n === 1 ? 'item' : 'items' ?></button>
    </form>
    <?php if (!$ok): ?><p class="hint" id="imp-blocked">Fix the errors in the file and upload it again, or tick “Skip rows with errors” and preview again.</p><?php endif; ?>
  </section>
  <?php endif; ?>
</main>
<?php
        return $head . (string)ob_get_clean() . $this->page->end();
    }
}
