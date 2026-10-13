<?php
declare(strict_types=1);

/* CSV import screens: upload + history (/import), mapping + preview (/import/{id}). */

function view_import_home(?string $error, ?string $toast): void
{
    page_start('Import CSV', 'page-import');
    echo settings_header('Import CSV', '/');
    $tok = h(csrf_token());
    ?>
<main class="wrap narrow">
  <p class="hint">Add or update many items at once from a spreadsheet saved as CSV. You choose which column is what, see a
    preview with every problem, and only then import – all in one go, or nothing. A file from <a href="/export.csv">Export CSV</a>
    imports as it is (its <code>id</code> column updates those items).</p>
  <?php if ($error): ?><div class="err" role="alert" id="imp-err"><?= h($error) ?></div><?php endif; ?>
  <form method="post" action="/import" enctype="multipart/form-data" class="set-card setform" id="imp-upload">
    <input type="hidden" name="csrf" value="<?= $tok ?>">
    <label class="field"><span>CSV file <em>max. 5 MB, <?= number_format(IMPORT_MAX_ROWS) ?> rows; comma, semicolon or tab</em></span>
      <input type="file" name="csv" id="imp-file" accept=".csv,text/csv,text/plain,.tsv" required></label>
    <button class="btn btn-primary btn-wide" id="imp-go"><?= icon('download') ?> Upload and map columns</button>
  </form>
  <?php $recent = imports_recent(); if ($recent): ?>
  <section class="set-card" id="imp-history"><h2 class="set-h">Recent imports</h2><ul class="imp-hist">
    <?php foreach ($recent as $r): $res = $r['result'] ? json_decode((string)$r['result'], true) : null; ?>
    <li><?php if ($r['status'] !== 'done'): ?><a href="/import/<?= (int)$r['id'] ?>"><?= h($r['file_name']) ?></a><?php else: ?><b><?= h($r['file_name']) ?></b><?php endif; ?>
      <small><?= h(fmt_time((string)($r['finished_at'] ?: $r['created_at']))) ?><?= $r['actor'] ? ' · ' . h($r['actor']) : '' ?> · <?= $res ? h(import_summary_text($res)) : 'not imported yet (' . (int)$r['total_rows'] . ' rows)' ?></small></li>
    <?php endforeach; ?>
  </ul></section>
  <?php endif; ?>
</main>
<?php if ($toast): ?><div class="toast" role="status"><?= h($toast) ?></div><?php endif; ?>
<?php
    page_end();
}

function view_import_map(array $imp, array $header, array $rows, ?array $preview, ?string $error): void
{
    page_start('Import ' . $imp['file_name'], 'page-import');
    echo settings_header('Import CSV', '/import');
    $tok = h(csrf_token());
    $fields = import_fields();
    $map = $imp['mapping'];
    $opt = $imp['options'];
    ?>
<main class="wrap narrow">
  <p class="hint"><b><?= h($imp['file_name']) ?></b> · <?= count($rows) ?> <?= count($rows) === 1 ? 'row' : 'rows' ?>, <?= count($header) ?> columns. Pick what each column holds; columns set to “Ignore” are skipped.</p>
  <?php if ($error): ?><div class="err" role="alert" id="imp-err"><?= h($error) ?></div><?php endif; ?>
  <form method="post" action="/import/<?= $imp['id'] ?>/preview" class="setform" id="imp-map">
    <input type="hidden" name="csrf" value="<?= $tok ?>">
    <ol class="imp-cols" id="imp-cols">
    <?php foreach ($header as $i => $hd): $samples = array_slice(array_values(array_filter(array_map(fn($r) => trim((string)($r[$i] ?? '')), array_slice($rows, 0, 20)), fn($v) => $v !== '')), 0, 2); ?>
      <li class="imp-col">
        <label class="field"><span class="imp-head"><b><?= h($hd !== '' ? $hd : 'Column ' . ($i + 1)) ?></b><?php if ($samples): ?><small>e.g. <?= h(implode(' · ', array_map(fn($s) => mb_strimwidth($s, 0, 40, '…'), $samples))) ?></small><?php endif; ?></span>
          <select name="col[<?= $i ?>]" id="imp-col-<?= $i ?>"<?= ($map[$i] ?? '') === '' ? ' class="imp-ignored"' : '' ?>>
            <option value="">Ignore</option>
            <?php foreach ($fields as $k => [$label]): ?><option value="<?= $k ?>"<?= ($map[$i] ?? '') === $k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
          </select></label>
      </li>
    <?php endforeach; ?>
    </ol>
    <label class="check"><input type="checkbox" name="create" value="1" id="imp-create"<?= !empty($opt['create']) ? ' checked' : '' ?>><span>Create missing locations, categories and tags<small>Off: rows naming one that doesn’t exist are errors.</small></span></label>
    <label class="check"><input type="checkbox" name="skip_errors" value="1" id="imp-skip"<?= !empty($opt['skip_errors']) ? ' checked' : '' ?>><span>Skip rows with errors<small>Off: nothing is imported while any row has an error.</small></span></label>
    <button class="btn btn-wide" id="imp-preview"><?= icon('search') ?> Preview (dry run)</button>
  </form>
  <?php if ($preview !== null): $ok = $preview['errors'] === 0 || !empty($opt['skip_errors']); ?>
  <section class="set-card imp-preview" id="imp-result" aria-live="polite">
    <h2 class="set-h">Preview</h2>
    <p class="imp-sum" id="imp-sum"><span class="imp-n imp-new"><b><?= $preview['created'] ?></b> new</span><span class="imp-n imp-upd"><b><?= $preview['updated'] ?></b> updated</span><?php if ($preview['unchanged']): ?><span class="imp-n imp-same"><b><?= $preview['unchanged'] ?></b> unchanged</span><?php endif; ?><span class="imp-n imp-bad"><b><?= $preview['errors'] ?></b> <?= $preview['errors'] === 1 ? 'error' : 'errors' ?></span></p>
    <?php $made = array_filter($preview['made']); if ($made): ?><p class="hint" id="imp-made">Will create <?= h(implode('; ', array_map(fn($k, $l) => ($k === 'category' ? 'categories' : $k . 's') . ': ' . implode(', ', $l), array_keys($made), $made))) ?>.</p><?php endif; ?>
    <?php $bad = array_values(array_filter($preview['rows'], fn($r) => $r['state'] === 'error')); if ($bad): ?>
    <ul class="imp-errors" id="imp-errors"><?php foreach (array_slice($bad, 0, 100) as $r): ?><li><b>Row <?= (int)$r['line'] ?></b><?= $r['name'] !== '' ? ' “' . h($r['name']) . '”' : '' ?>: <?= h($r['error']) ?></li><?php endforeach; ?>
      <?php if (count($bad) > 100): ?><li>… and <?= count($bad) - 100 ?> more</li><?php endif; ?></ul>
    <?php endif; ?>
    <ol class="imp-rows" id="imp-rows"><?php foreach (array_slice($preview['rows'], 0, IMPORT_PREVIEW_ROWS) as $r): ?>
      <li class="imp-row imp-<?= h($r['state']) ?>"><span class="imp-line"><?= (int)$r['line'] ?></span><span class="imp-name"><?= h($r['name'] !== '' ? $r['name'] : '—') ?></span><span class="imp-state"><?= ['new' => 'New', 'update' => 'Update', 'same' => 'Unchanged', 'error' => 'Error'][$r['state']] ?></span></li>
    <?php endforeach; ?></ol>
    <?php if (count($preview['rows']) > IMPORT_PREVIEW_ROWS): ?><p class="hint">First <?= IMPORT_PREVIEW_ROWS ?> of <?= count($preview['rows']) ?> rows shown.</p><?php endif; ?>
    <form method="post" action="/import/<?= $imp['id'] ?>/commit" id="imp-commit-form">
      <input type="hidden" name="csrf" value="<?= $tok ?>">
      <?php foreach ($map as $i => $k): ?><input type="hidden" name="col[<?= (int)$i ?>]" value="<?= h((string)$k) ?>"><?php endforeach; ?>
      <?php if (!empty($opt['create'])): ?><input type="hidden" name="create" value="1"><?php endif; ?>
      <?php if (!empty($opt['skip_errors'])): ?><input type="hidden" name="skip_errors" value="1"><?php endif; ?>
      <button class="btn btn-primary btn-wide" id="imp-commit"<?= $ok && ($preview['created'] + $preview['updated'] + $preview['unchanged']) > 0 ? '' : ' disabled' ?>><?= icon('check') ?> Import <?= $preview['created'] + $preview['updated'] ?> <?= $preview['created'] + $preview['updated'] === 1 ? 'item' : 'items' ?></button>
    </form>
    <?php if (!$ok): ?><p class="hint" id="imp-blocked">Fix the errors in the file and upload it again, or tick “Skip rows with errors” and preview again.</p><?php endif; ?>
  </section>
  <?php endif; ?>
</main>
<?php
    page_end();
}
