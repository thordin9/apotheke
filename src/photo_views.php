<?php
declare(strict_types=1);

/* Photo editor: /item/{id}/photos/{pid}/edit – canvas crop + rotate (app.js), plain fields without JavaScript. */

function view_photo_edit(array $it, array $photo, int $count, ?string $error): void
{
    $id = (int)$it['id'];
    page_start('Edit photo · ' . $it['name'], 'page-photo-edit');
    echo settings_header('Edit photo', '/item/' . $id . '#photos');
    $tok = h(csrf_token());
    $src = '/media/full/' . $photo['file'];
    [$w, $h] = @getimagesize(IMG_DIR . '/' . $photo['file']) ?: [0, 0];
    ?>
<main class="wrap narrow pe">
  <p class="hint"><?= h($it['name']) ?> · photo <?= $photo['index'] + 1 ?> of <?= $count ?><?= $photo['index'] === 0 ? ' (cover)' : '' ?> · <?= (int)$w ?>×<?= (int)$h ?> px. Drag on the photo to crop; drag the corners to resize.</p>
  <?php if ($error): ?><div class="err" role="alert" id="pe-err"><?= h($error) ?></div><?php endif; ?>
  <div class="pe-stage" id="pe-stage" data-src="<?= h($src) ?>" data-w="<?= (int)$w ?>" data-h="<?= (int)$h ?>">
    <canvas id="pe-canvas" tabindex="0" aria-label="Photo with the crop area. Arrow keys move it, Shift + arrow keys resize it."></canvas>
    <img class="pe-fallback" id="pe-img" src="<?= h($src) ?>" alt="The photo">
  </div>
  <form method="post" action="/item/<?= $id ?>/photos/<?= (int)$photo['id'] ?>/edit" class="setform" id="pe-form">
    <input type="hidden" name="csrf" value="<?= $tok ?>">
    <div class="pe-tools" id="pe-tools" hidden>
      <button type="button" class="btn" id="pe-rl" aria-label="Rotate left"><?= icon('undo') ?> Left</button>
      <button type="button" class="btn" id="pe-rr" aria-label="Rotate right"><?= icon('undo') ?> Right</button>
      <div class="seg" role="radiogroup" aria-label="Crop shape" id="pe-aspect">
        <button type="button" class="seg-btn on" role="radio" aria-checked="true" data-aspect="0">Free</button>
        <button type="button" class="seg-btn" role="radio" aria-checked="false" data-aspect="1">Square</button>
        <button type="button" class="seg-btn" role="radio" aria-checked="false" data-aspect="1.3333">4:3</button>
      </div>
      <button type="button" class="btn btn-ghost" id="pe-reset">Reset</button>
    </div>
    <details class="pe-manual" id="pe-manual">
      <summary>Exact values</summary>
      <label class="field"><span>Rotate (clockwise)</span><select name="rotate" id="pe-rotate">
        <?php foreach (PHOTO_ROTATIONS as $r): ?><option value="<?= $r ?>"><?= $r ?>°</option><?php endforeach; ?></select></label>
      <div class="pe-grid">
        <?php foreach (['x' => 'Left %', 'y' => 'Top %', 'w' => 'Width %', 'h' => 'Height %'] as $k => $l): ?>
        <label class="field"><span><?= $l ?></span><input type="number" name="crop_<?= $k ?>" id="pe-<?= $k ?>" value="<?= in_array($k, ['w', 'h'], true) ? 100 : 0 ?>" min="0" max="100" step="0.01" inputmode="decimal" required></label>
        <?php endforeach; ?>
      </div>
      <p class="hint">Percent of the rotated photo.</p>
    </details>
    <p class="pe-info" id="pe-info" aria-live="polite"></p>
    <div class="actionbar actionbar-form"><div class="actionbar-in">
      <a class="btn" href="/item/<?= $id ?>#photos" id="pe-cancel">Cancel</a>
      <button class="btn btn-primary" id="pe-save"><?= icon('check') ?> Save as new version</button>
    </div></div>
  </form>
</main>
<?php
    page_end();
}
