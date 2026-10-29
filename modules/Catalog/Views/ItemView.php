<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Views;

use Apotheke\Activity\Views\ActivityView;
use Apotheke\Catalog\Calendar;
use Apotheke\Catalog\Items\ItemInput;
use Apotheke\Catalog\Items\PhotoRepository;
use Apotheke\Layout\Icons;
use Apotheke\Layout\Page;
use Apotheke\Media\ImageStore;
use Apotheke\Notes\MarkdownRenderer;
use Apotheke\Taxonomy\Kind;
use Apotheke\Taxonomy\LocationTree;
use DateTimeImmutable;

/**
 * The item page and the add/edit form (legacy views.php: view_item(),
 * view_form(), picker_html(), autofill_analyze_html(), scan_dialog_html()
 * and the partials they use), byte-identical to legacy. Every value is
 * escaped here.
 */
final class ItemView
{
    private const ATTACH_TYPES = ['pdf' => ['application/pdf', 'PDF'], 'jpg' => ['image/jpeg', 'JPEG'], 'png' => ['image/png', 'PNG'], 'webp' => ['image/webp', 'WebP']];

    /**
     * @param array{currency:string,date_format:string,max_photos:int,max_upload:int,undo_seconds:int,attach_max_per_item:int,attach_types:string,attach_max_mb:int,lend_due_days:?int} $settings
     */
    public function __construct(
        private readonly Page $page,
        private readonly ListView $list,
        private readonly Calendar $calendar,
        private readonly ActivityView $activity,
        private readonly MarkdownRenderer $markdown,
        private readonly array $settings,
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
     * Legacy view_item().
     *
     * @param array<string,mixed> $it the item (ItemRepository::get())
     * @param list<array{id:int,file:string,position:int}> $photos
     * @param list<array<string,mixed>> $history up to 10 entries
     * @param list<array{id:int,name:string,mime:string,size:int,file:string}> $attachments
     * @param array<int,array<string,mixed>> $locationsById for the location trail
     */
    public function item(array $it, array $photos, int $cur, array $history, bool $historyMore, array $attachments, ?string $toast, array $locationsById): string
    {
        $head = $this->page->start((string)$it['name'], 'page-item');
        $id = (int)$it['id'];
        $n = count($photos);
        $cur = $n > 0 ? min($cur, $n - 1) : 0;
        $tok = self::h($this->page->csrf());
        $maxPhotos = $this->settings['max_photos'];
        ob_start();
        ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="/" data-back aria-label="Back"><?= self::icon('back') ?></a>
    <h1 class="title-sm">Item</h1>
    <?= $this->list->favButton($id, (string)$it['name'], !empty($it['favourite']), '/item/' . $id) ?>
  </div>
</header>
<main class="wrap narrow">
  <?php if ($n > 0): $ph = $photos[$cur]; ?>
  <div class="gallery" id="gallery" data-count="<?= $n ?>" data-index="<?= $cur ?>">
    <a class="hero" id="hero" href="/media/full/<?= self::h($ph['file']) ?>" target="_blank" rel="noopener">
      <img id="hero-img" src="/media/full/<?= self::h($ph['file']) ?>" alt="<?= self::h((string)$it['name']) ?> – photo <?= $cur + 1 ?> of <?= $n ?>" decoding="async">
    </a>
    <?php if ($n > 1): ?>
    <a class="gal-nav gal-prev" id="gal-prev" href="/item/<?= $id ?>?p=<?= ($cur - 1 + $n) % $n ?>" aria-label="Previous photo"><?= self::icon('back') ?></a>
    <a class="gal-nav gal-next" id="gal-next" href="/item/<?= $id ?>?p=<?= ($cur + 1) % $n ?>" aria-label="Next photo"><?= self::icon('back') ?></a>
    <span class="gal-count" id="gal-count" aria-live="polite"><?= $cur + 1 ?> / <?= $n ?></span>
    <?php endif; ?>
  </div>
  <?php if ($n > 1): ?>
  <nav class="thumbs" aria-label="Photos">
    <?php foreach ($photos as $i => $p): ?>
    <a href="/item/<?= $id ?>?p=<?= $i ?>" data-i="<?= $i ?>" data-full="/media/full/<?= self::h($p['file']) ?>"<?= $i === $cur ? ' aria-current="true"' : '' ?> aria-label="Photo <?= $i + 1 ?><?= $i === 0 ? ' (cover)' : '' ?>"><img src="/media/thumb/<?= self::h($p['file']) ?>" alt="" loading="lazy"></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <?php else: ?>
  <a class="hero hero-empty" href="/item/<?= $id ?>/edit"><?= self::icon('camera') ?><span>Add a photo</span></a>
  <?php endif; ?>
  <h2 class="item-name"><?= self::h((string)$it['name']) ?></h2>
  <div class="item-meta">
    <?= $this->trail($it['location_id'] !== null ? (int)$it['location_id'] : null, (string)$it['location'], $locationsById) ?>
    <?= $this->chipLarge('category', $it['category_id'] !== null ? (int)$it['category_id'] : null, (string)$it['category']) ?>
  </div>
  <?php if (($it['min_qty'] ?? null) !== null): ?>
  <div class="lowbox<?= (int)$it['quantity'] <= (int)$it['min_qty'] ? ' is-low' : '' ?>" id="lowbox" data-min="<?= (int)$it['min_qty'] ?>"><?= self::lowBadge() ?><span class="hint">Alert at <?= (int)$it['min_qty'] ?> or fewer</span></div>
  <?php endif; ?>
  <div class="qty qty-lg" data-id="<?= $id ?>">
    <button type="button" class="qbtn" data-delta="-1" aria-label="Decrease"<?= (int)$it['quantity'] <= 0 ? ' disabled' : '' ?>><?= self::icon('minus') ?></button>
    <output class="qval"><?= (int)$it['quantity'] ?></output>
    <button type="button" class="qbtn" data-delta="1" aria-label="Increase"><?= self::icon('plus') ?></button>
  </div>
  <?php if (!empty($it['tags'])): ?>
  <ul class="tags" aria-label="Tags">
    <?php foreach ($it['tags'] as $t): ?><li><a class="tagchip" href="/?tag=<?= (int)$t['id'] ?>">#<?= self::h($t['name']) ?></a></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php $facts = array_filter(['Price' => $this->list->money($it['value_cents'] ?? null), 'Purchased' => $this->date($it['purchase_date'] ?? ''), 'Expires' => $this->date($it['expires_on'] ?? ''), 'Serial no.' => (string)($it['serial'] ?? ''), 'Barcode' => (string)($it['barcode'] ?? '')], static fn (string $v): bool => $v !== ''); ?>
  <?php if ($facts !== []): ?>
  <dl class="facts" id="facts">
    <?php foreach ($facts as $k => $v): ?><div><dt><?= self::h($k) ?></dt><dd<?= $k === 'Serial no.' ? ' class="mono"' : '' ?>><?= self::h($v) ?><?= $k === 'Expires' ? ' ' . $this->expiryBadge($it) : '' ?></dd></div><?php endforeach; ?>
  </dl>
  <?php endif; ?>
  <?php if (trim((string)$it['notes']) !== ''): ?>
  <div class="notes md" id="notes"><?= $this->markdown->render((string)$it['notes']) ?></div>
  <?php endif; ?>
  <?= $this->attachmentsBox($id, $attachments) ?>
  <details class="photos-box" id="photos">
    <summary><?= self::icon('image') ?> Photos <span class="muted-n"><?= $n ?>/<?= $maxPhotos ?></span></summary>
    <?php if ($n > 0): ?>
    <ul class="pm-list" id="pm-list" data-order="/item/<?= $id ?>/photos/order">
      <?php foreach ($photos as $i => $p): $act = '/item/' . $id . '/photos/' . $p['id'] . '/'; ?>
      <li class="pm<?= $i === 0 ? ' is-cover' : '' ?>" data-id="<?= $p['id'] ?>">
        <img src="/media/thumb/<?= self::h($p['file']) ?>" alt="Photo <?= $i + 1 ?>" loading="lazy">
        <?php if ($i === 0): ?><span class="pm-badge">Cover</span><?php endif; ?>
        <?php if ($n > 1): ?><span class="pm-drag" role="img" aria-label="Drag to reorder photo <?= $i + 1 ?>" title="Drag to reorder"><?= self::icon('menu') ?></span><?php endif; ?>
        <a class="pbtn pm-edit" href="/item/<?= $id ?>/photos/<?= $p['id'] ?>/edit" id="pm-edit-<?= $i ?>" aria-label="Crop or rotate photo <?= $i + 1 ?>"><?= self::icon('edit') ?></a>
        <div class="pm-btns">
          <form method="post" action="<?= $act ?>left"><input type="hidden" name="csrf" value="<?= $tok ?>"><button class="pbtn" aria-label="Move photo <?= $i + 1 ?> left"<?= $i === 0 ? ' disabled' : '' ?>><?= self::icon('back') ?></button></form>
          <form method="post" action="<?= $act ?>cover"><input type="hidden" name="csrf" value="<?= $tok ?>"><button class="pbtn" aria-label="Make photo <?= $i + 1 ?> the cover"<?= $i === 0 ? ' disabled' : '' ?>><?= self::icon('star') ?></button></form>
          <form method="post" action="<?= $act ?>right"><input type="hidden" name="csrf" value="<?= $tok ?>"><button class="pbtn pbtn-r" aria-label="Move photo <?= $i + 1 ?> right"<?= $i === $n - 1 ? ' disabled' : '' ?>><?= self::icon('back') ?></button></form>
          <form method="post" action="<?= $act ?>delete" data-confirm="Delete this photo?"><input type="hidden" name="csrf" value="<?= $tok ?>"><button class="pbtn pbtn-del" aria-label="Delete photo <?= $i + 1 ?>"><?= self::icon('trash') ?></button></form>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($n < $maxPhotos): ?>
    <form method="post" action="/item/<?= $id ?>/photos" enctype="multipart/form-data" class="pm-add" id="photoadd">
      <input type="hidden" name="csrf" value="<?= $tok ?>">
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= $this->settings['max_upload'] ?>">
      <label class="btn"><input type="file" name="photos[]" accept="image/*" capture="environment" class="file" id="photoadd-cam"><?= self::icon('camera') ?> Take photo</label>
      <label class="btn"><input type="file" name="photos[]" accept="image/*" multiple class="file" id="photoadd-lib"><?= self::icon('image') ?> Add from library</label>
      <noscript><button class="btn btn-primary">Upload</button></noscript>
      <p class="hint pm-status" id="photoadd-status" role="status" hidden></p>
    </form>
    <?php endif; ?>
  </details>
  <?= $this->lendSection($it) ?>
  <div class="item-links">
    <a class="btn btn-sm" href="/item/<?= $id ?>/label"><?= self::icon('qr') ?> QR label</a>
    <form method="post" action="/item/<?= $id ?>/duplicate" class="dupform" id="dupform">
      <input type="hidden" name="csrf" value="<?= $tok ?>">
      <?php if ($n > 0): ?><label class="check"><input type="checkbox" name="photos" value="1" id="dup-photos"> with photos</label><?php endif; ?>
      <button class="btn btn-sm" id="dup-btn"><?= self::icon('copy') ?> Duplicate</button>
    </form>
    <a class="btn btn-sm" href="/item/<?= $id ?>/merge" id="merge-link"><?= self::icon('merge') ?> Merge…</a>
  </div>
  <?php if ($history !== []): ?>
  <section class="history" id="history" aria-labelledby="hist-title">
    <h2 id="hist-title"><?= self::icon('clock') ?> History</h2>
    <?= $this->activity->items($history, false) ?>
    <?php if ($historyMore): ?><a class="btn btn-sm" href="/activity?item=<?= $id ?>" id="hist-more">Full history</a><?php endif; ?>
  </section>
  <?php endif; ?>
  <p class="times">Added <?= self::h($this->activity->time((string)$it['created_at'])) ?><?php if ($it['updated_at'] !== $it['created_at']): ?> · Updated <?= self::h($this->activity->time((string)$it['updated_at'])) ?><?php endif; ?></p>
  <section class="danger-zone" id="danger-zone" aria-label="Delete">
    <form method="post" action="/item/<?= $id ?>/delete" data-confirm="Delete “<?= self::h((string)$it['name']) ?>”?<?= $this->settings['undo_seconds'] > 0 ? ' You can undo this for ' . $this->settings['undo_seconds'] . ' seconds.' : ' This cannot be undone.' ?>">
      <input type="hidden" name="csrf" value="<?= $tok ?>">
      <button class="btn btn-danger" aria-label="Delete item"><?= self::icon('trash') ?> Delete item</button>
    </form>
  </section>
</main>
<?php if ($toast !== null): ?><div class="toast" role="status"><?= self::h($toast) ?></div><?php endif; ?>
<div class="actionbar">
  <div class="actionbar-in one">
    <a class="btn btn-primary" href="/item/<?= $id ?>/edit" id="item-edit"><?= self::icon('edit') ?> Edit</a>
  </div>
</div>
<?php

        return $head . (string)ob_get_clean() . $this->page->end();
    }

    /**
     * Legacy view_form(): add ($it null) or edit.
     *
     * @param array<string,mixed>|null $it
     * @param array<string,mixed> $vals
     * @param list<array<string,mixed>> $locs
     * @param list<array<string,mixed>> $cats
     * @param list<array{id:int,name:string,n:int}> $tags the tags in use, for the datalist
     * @param array{timeout:int,side:int,auto:bool,latency:?int,photos:list<array{id:int,file:string,position:int}>}|null $autofill null when AI autofill is off or not allowed
     */
    public function form(?array $it, array $vals, array $locs, array $cats, array $tags, ?array $autofill, ?string $error, ?string $toast = null): string
    {
        $isNew = $it === null;
        $head = $this->page->start($isNew ? 'Add item' : 'Edit ' . $it['name'], 'page-form');
        $hasImg = !$isNew && ImageStore::validName(is_string($it['image'] ?? null) ? $it['image'] : null);
        $afOn = $autofill !== null;
        ob_start();
        ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="<?= $isNew ? '/' : '/item/' . (int)$it['id'] ?>" data-back aria-label="Cancel"><?= self::icon('x') ?></a>
    <h1 class="title-sm"><?= $isNew ? 'New item' : 'Edit item' ?></h1>
    <span class="spacer"></span>
  </div>
</header>
<main class="wrap narrow">
  <form method="post" action="/save" enctype="multipart/form-data" id="itemform" class="itemform" novalidate<?= $autofill !== null ? ' data-autofill="1" data-af-timeout="' . $autofill['timeout'] . '" data-af-side="' . $autofill['side'] . '"' . ($autofill['auto'] ? ' data-af-auto="1"' : '') . ($isNew && !empty($vals['af_defaults']) ? ' data-af-defaults="' . self::h(implode(',', (array)$vals['af_defaults'])) . '"' : '') . ($autofill['latency'] !== null ? ' data-af-latency="' . $autofill['latency'] . '"' : '') : '' ?>>
    <input type="hidden" name="csrf" value="<?= self::h($this->page->csrf()) ?>">
    <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int)$it['id'] ?>"><?php endif; ?>
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= $this->settings['max_upload'] ?>">

    <div class="err" id="err" role="alert"<?= $error === null ? ' hidden' : '' ?>><?= self::h($error) ?></div>

    <div class="photo<?= $hasImg ? ' has-img' : '' ?>" id="photo">
      <img id="preview" alt=""<?= $hasImg ? ' src="/media/thumb/' . self::h((string)$it['image']) . '"' : ' hidden' ?>>
      <label class="photo-take">
        <input type="file" name="photo" accept="image/*" capture="environment" class="file">
        <?= self::icon('camera') ?><span><?= $hasImg ? 'Retake photo' : 'Take photo' ?></span>
      </label>
      <label class="photo-lib">
        <input type="file" name="photo2" accept="image/*" class="file">
        <?= self::icon('image') ?><span>Library</span>
      </label>
      <span class="photo-status" id="pstatus" hidden></span>
    </div>
    <?php if ($autofill !== null) echo self::analyze($autofill['photos']); ?>
    <?php if ($afOn): ?><div class="af-feedback" id="af-feedback" hidden><p class="af-status" id="af-status" role="status" aria-live="polite"></p><div class="af-progress" id="af-progress" role="progressbar" aria-label="Photo suggestions" aria-valuemin="0" aria-valuemax="100" hidden><span class="af-track"><span class="af-bar" id="af-bar"></span></span><span class="af-pct" id="af-pct" aria-hidden="true"></span></div></div><?php endif; ?>
    <?php if ($hasImg): ?>
    <label class="check"><input type="checkbox" name="remove_image" value="1" id="rmimg"> <?= self::icon('trash') ?> Remove photo</label>
    <?php endif; ?>

    <label class="field">
      <span>Name</span>
      <input type="text" name="name" id="name" value="<?= self::h((string)$vals['name']) ?>" required maxlength="200" autocomplete="off" autocapitalize="sentences" enterkeyhint="next" placeholder="e.g. Cordless drill">
    </label>
    <?= $this->picker('location', $locs, $vals['location_id'], (string)$vals['new_location']) ?>
    <?= $this->picker('category', $cats, $vals['category_id'], (string)$vals['new_category']) ?>
    <div class="field half">
      <span>Quantity</span>
      <div class="stepper">
        <button type="button" class="qbtn" data-step="-1" aria-label="Decrease"><?= self::icon('minus') ?></button>
        <input type="number" name="quantity" value="<?= (int)$vals['quantity'] ?>" min="0" max="1000000" step="1" inputmode="numeric" pattern="[0-9]*" aria-label="Quantity">
        <button type="button" class="qbtn" data-step="1" aria-label="Increase"><?= self::icon('plus') ?></button>
      </div>
    </div>
    <label class="field half">
      <span>Running low at <em>optional – alert when the quantity is this or less</em></span>
      <input type="number" name="min_qty" id="min_qty" value="<?= self::h((string)($vals['min_qty'] ?? '')) ?>" min="0" max="1000000" step="1" inputmode="numeric" pattern="[0-9]*" placeholder="e.g. 2">
    </label>
    <label class="field">
      <span>Notes <em>optional</em></span>
      <textarea name="notes" rows="4" maxlength="5000" placeholder="Model, where you bought it…" aria-describedby="notes-help"><?= self::h((string)$vals['notes']) ?></textarea>
      <small class="field-help" id="notes-help">Markdown: **bold**, *italic*, - lists, 1. steps, `code`, [link](https://…)</small>
    </label>
    <label class="field">
      <span>Tags <em>optional, comma-separated</em></span>
      <input type="text" name="tags" id="tags" value="<?= self::h((string)($vals['tags'] ?? '')) ?>" maxlength="2000" autocomplete="off" autocapitalize="none" list="tag-list" placeholder="e.g. garage, warranty">
    </label>
    <datalist id="tag-list"><?php foreach ($tags as $t): ?><option value="<?= self::h($t['name']) ?>"></option><?php endforeach; ?></datalist>
    <details class="more-fields"<?= ($vals['value'] ?? '') !== '' || ($vals['purchase_date'] ?? '') !== '' || ($vals['expires_on'] ?? '') !== '' || ($vals['serial'] ?? '') !== '' || ($vals['barcode'] ?? '') !== '' ? ' open' : '' ?>>
      <summary>Price, dates, serial &amp; barcode</summary>
      <div class="more-grid">
        <label class="field">
          <span>Purchase price <em><?= self::h($this->settings['currency']) ?></em></span>
          <input type="text" name="value" id="value" value="<?= self::h((string)($vals['value'] ?? '')) ?>" inputmode="decimal" maxlength="32" autocomplete="off" placeholder="0.00">
        </label>
        <label class="field">
          <span>Purchase date</span>
          <input type="date" name="purchase_date" id="purchase_date" value="<?= self::h((string)($vals['purchase_date'] ?? '')) ?>" max="<?= $this->calendar->today() ?>">
        </label>
        <label class="field">
          <span>Expires / warranty until</span>
          <input type="date" name="expires_on" id="expires_on" value="<?= self::h((string)($vals['expires_on'] ?? '')) ?>" min="1900-01-01" max="2999-12-31">
        </label>
        <label class="field">
          <span>Serial number</span>
          <input type="text" name="serial" id="serial" value="<?= self::h((string)($vals['serial'] ?? '')) ?>" maxlength="100" autocomplete="off" autocapitalize="characters" spellcheck="false">
        </label>
        <div class="field">
          <label class="field-l" for="barcode">Barcode</label>
          <div class="scanrow">
            <input type="text" name="barcode" id="barcode" value="<?= self::h((string)($vals['barcode'] ?? '')) ?>" maxlength="<?= ItemInput::BARCODE_MAX ?>" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="done">
            <button type="button" class="btn" data-scan="#barcode" hidden><?= self::icon('qr') ?> Scan</button>
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
<?php if ($toast !== null): ?><div class="toast" role="status"><?= self::h($toast) ?></div><?php endif; ?>
<?php

        return $head . (string)ob_get_clean() . self::scanDialog() . $this->page->end();
    }

    /** Legacy scan_dialog_html(): the camera dialog of the Scan buttons (app.js). */
    public static function scanDialog(): string
    {
        return '<dialog class="scan" id="scan-dialog" aria-label="Scan a barcode">'
            . '<video id="scan-video" playsinline muted></video>'
            . '<p class="scan-msg" id="scan-msg">Point the camera at a barcode</p>'
            . '<button type="button" class="btn btn-wide" id="scan-cancel">Cancel – type it instead</button></dialog>';
    }

    /** Legacy view_barcode(): type (or scan) a code and go to its item or a new item with the code. */
    public function barcode(): string
    {
        $head = $this->page->start('Barcode', 'page-barcode');
        ob_start();
        ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="/" aria-label="Back"><?= self::icon('back') ?></a>
    <h1 class="title-sm">Find by barcode</h1>
    <?= $this->page->menu() ?>
  </div>
</header>
<main class="wrap narrow">
  <form method="get" action="/barcode" class="barcode-form" id="barcode-form">
    <div class="field">
      <label class="field-l" for="code">Barcode</label>
      <div class="scanrow">
        <input type="text" name="code" id="code" required maxlength="<?= ItemInput::BARCODE_MAX ?>" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="go" placeholder="e.g. 4006381333931">
        <button type="button" class="btn" data-scan="#code" data-scan-submit hidden><?= self::icon('qr') ?> Scan</button>
      </div>
    </div>
    <button class="btn btn-primary btn-wide" id="code-go"><?= self::icon('search') ?> Look up</button>
  </form>
  <p class="hint" id="scan-hint">Type the digits under the barcode. If one item has this barcode it opens; if none does, a new item is started with the code filled in.</p>
</main>
<?= self::scanDialog() ?>
<?php

        return $head . (string)ob_get_clean() . $this->page->end();
    }

    /**
     * Legacy view_photo_edit(): the crop and rotate page (canvas in app.js,
     * plain fields without JavaScript).
     *
     * @param array<string,mixed> $it
     * @param array{id:int,file:string,position:int,index:int} $photo
     * @param array{0:int,1:int} $size the served photo's width and height
     */
    public function photoEdit(array $it, array $photo, int $count, array $size, ?string $error): string
    {
        $id = (int)$it['id'];
        $head = $this->page->start('Edit photo · ' . $it['name'], 'page-photo-edit') . $this->page->simpleHeader('Edit photo', '/item/' . $id . '#photos');
        $tok = self::h($this->page->csrf());
        $src = '/media/full/' . $photo['file'];
        [$w, $h] = $size;
        ob_start();
        ?>
<main class="wrap narrow pe">
  <p class="hint"><?= self::h((string)$it['name']) ?> · photo <?= $photo['index'] + 1 ?> of <?= $count ?><?= $photo['index'] === 0 ? ' (cover)' : '' ?> · <?= $w ?>×<?= $h ?> px. Drag on the photo to crop; drag the corners to resize.</p>
  <?php if ($error): ?><div class="err" role="alert" id="pe-err"><?= self::h($error) ?></div><?php endif; ?>
  <div class="pe-stage" id="pe-stage" data-src="<?= self::h($src) ?>" data-w="<?= $w ?>" data-h="<?= $h ?>">
    <canvas id="pe-canvas" tabindex="0" aria-label="Photo with the crop area. Arrow keys move it, Shift + arrow keys resize it."></canvas>
    <img class="pe-fallback" id="pe-img" src="<?= self::h($src) ?>" alt="The photo">
  </div>
  <form method="post" action="/item/<?= $id ?>/photos/<?= $photo['id'] ?>/edit" class="setform" id="pe-form">
    <input type="hidden" name="csrf" value="<?= $tok ?>">
    <div class="pe-tools" id="pe-tools" hidden>
      <button type="button" class="btn" id="pe-rl" aria-label="Rotate left"><?= self::icon('undo') ?> Left</button>
      <button type="button" class="btn" id="pe-rr" aria-label="Rotate right"><?= self::icon('undo') ?> Right</button>
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
        <?php foreach (PhotoRepository::ROTATIONS as $r): ?><option value="<?= $r ?>"><?= $r ?>°</option><?php endforeach; ?></select></label>
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
      <button class="btn btn-primary" id="pe-save"><?= self::icon('check') ?> Save</button>
    </div></div>
  </form>
</main>
<?php

        return $head . (string)ob_get_clean() . $this->page->end();
    }

    /** Legacy fmt_date(): a Y-m-d date in the date_format setting. */
    public function date(?string $ymd): string
    {
        $ymd = (string)$ymd;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $ymd) !== 1) {
            return $ymd;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        return $d !== false ? $d->format($this->settings['date_format']) : $ymd;
    }

    /** Legacy default_lend_due(): today + lend_due_days, or ''. */
    private function lendDue(): string
    {
        $d = $this->settings['lend_due_days'];

        return $d === null ? '' : (new DateTimeImmutable($this->calendar->today()))->modify('+' . $d . ' days')->format('Y-m-d');
    }

    private static function lowBadge(): string
    {
        return '<span class="lowbadge" role="status">' . self::icon('alert') . ' Running low</span>';
    }

    /** Legacy chip_html(..., true). */
    private function chipLarge(string $kind, ?int $id, string $name): string
    {
        $t = Kind::get($kind);
        $cls = 'chip chip-' . $kind . ' chip-lg';
        if ($id === null) {
            return '<span class="' . $cls . ' chip-muted">' . self::h($t['none']) . '</span>';
        }

        return '<a class="' . $cls . '" href="/?' . $t['param'] . '=' . $id . '" data-' . $t['param'] . '="' . $id . '">' . self::icon($t['icon']) . ' ' . self::h($name) . '</a>';
    }

    /**
     * Legacy location_trail_html(): the location chip after its parents.
     *
     * @param array<int,array<string,mixed>> $byId
     */
    private function trail(?int $id, string $name, array $byId): string
    {
        $chip = $this->chipLarge('location', $id, $name);
        if ($id === null) {
            return $chip;
        }
        $up = [];
        $p = $byId[$id]['parent_id'] ?? null;
        while ($p !== null && isset($byId[$p]) && count($up) <= 7) {
            array_unshift($up, ['id' => (int)$p, 'name' => (string)$byId[$p]['name']]);
            $p = $byId[$p]['parent_id'];
        }
        if ($up === []) {
            return $chip;
        }
        $o = '<span class="loctrail" id="loctrail">';
        foreach ($up as $a) {
            $o .= '<a class="chip chip-location chip-lg chip-parent" href="/?loc=' . $a['id'] . '">' . self::h($a['name']) . '</a><span class="trail-sep" aria-hidden="true">›</span>';
        }

        return $o . $chip . '</span>';
    }

    /** @param array<string,mixed> $it */
    private function expiryBadge(array $it): string
    {
        $st = $this->calendar->expiryState($it);
        if ($st === '') {
            return '';
        }
        $n = $this->calendar->days((string)$it['expires_on']);
        $txt = $st === 'expired' ? 'Expired' : ($n === 0 ? 'Expires today' : 'Expires in ' . $n . ' day' . ($n === 1 ? '' : 's'));

        return '<span class="expbadge is-' . $st . '">' . self::icon($st === 'expired' ? 'alert' : 'clock') . ' ' . $txt . '</span>';
    }

    /**
     * Legacy attachments_html(): the list and the upload form (uploads come with PLAN 11b).
     *
     * @param list<array{id:int,name:string,mime:string,size:int,file:string}> $list
     */
    private function attachmentsBox(int $id, array $list): string
    {
        $tok = self::h($this->page->csrf());
        $max = $this->settings['attach_max_per_item'];
        $allowed = match ($this->settings['attach_types']) {
            'pdf' => ['pdf'],
            'images' => ['jpg', 'png', 'webp'],
            default => array_keys(self::ATTACH_TYPES),
        };
        $accept = implode(',', array_map(static fn (string $e): string => self::ATTACH_TYPES[$e][0], $allowed));
        $types = implode(', ', array_map(static fn (string $e): string => self::ATTACH_TYPES[$e][1], $allowed));
        $o = '<details class="photos-box att-box" id="attachments"' . ($list !== [] ? ' open' : '') . '><summary>' . self::icon('paperclip') . ' Attachments <span class="muted-n">' . count($list) . '/' . $max . '</span></summary>';
        if ($list !== []) {
            $o .= '<ul class="att-list">';
            foreach ($list as $a) {
                $o .= '<li class="att" data-id="' . $a['id'] . '"><a class="att-dl" href="/attachment/' . $a['id'] . '" download>'
                    . self::icon($a['mime'] === 'application/pdf' ? 'file' : 'image') . '<span class="att-name">' . self::h($a['name']) . '</span><small>' . self::h(self::bytes($a['size'])) . '</small></a>'
                    . '<form method="post" action="/item/' . $id . '/attachments/' . $a['id'] . '/delete" data-confirm="Delete “' . self::h($a['name']) . '”?"><input type="hidden" name="csrf" value="' . $tok . '">'
                    . '<button class="pbtn pbtn-del" aria-label="Delete ' . self::h($a['name']) . '">' . self::icon('trash') . '</button></form></li>';
            }
            $o .= '</ul>';
        }
        if (count($list) < $max) {
            $o .= '<form method="post" action="/item/' . $id . '/attachments" enctype="multipart/form-data" class="att-add" id="attadd"><input type="hidden" name="csrf" value="' . $tok . '">'
                . '<label class="btn"><input type="file" name="files[]" multiple accept="' . self::h($accept) . '" class="file" id="att-file">' . self::icon('paperclip') . ' Attach files</label>'
                . '<button class="btn btn-primary" id="att-upload">' . self::icon('check') . ' Upload</button>'
                . '<p class="hint">' . self::h($types) . ' · max ' . $this->settings['attach_max_mb'] . ' MB each</p></form>';
        }

        return $o . '</details>';
    }

    private static function bytes(int $n): string
    {
        if ($n < 1024) {
            return $n . ' B';
        }
        if ($n < 1024 * 1024) {
            return round($n / 1024) . ' KB';
        }

        return number_format($n / 1048576, 1) . ' MB';
    }

    /** @param array<string,mixed> $it legacy lend_section_html(): status and Return, or the form to lend it */
    private function lendSection(array $it): string
    {
        $id = (int)$it['id'];
        $tok = self::h($this->page->csrf());
        $today = $this->calendar->today();
        $lentTo = $it['lent_to'] ?? null;
        $form = fn (string $btn): string => '<form method="post" action="/item/' . $id . '/lend" class="lendform" id="lendform">'
            . '<input type="hidden" name="csrf" value="' . $tok . '">'
            . '<label class="field"><span>Lent to</span><input type="text" name="lent_to" id="lent_to" required maxlength="100" autocomplete="name" value="' . self::h((string)($lentTo ?? '')) . '" placeholder="Name"></label>'
            . '<div class="lendrow"><label class="field"><span>On</span><input type="date" name="lent_at" id="lent_at" max="' . $today . '" value="' . self::h((string)($it['lent_at'] ?? $today)) . '"></label>'
            . '<label class="field"><span>Due back <em>optional</em></span><input type="date" name="lent_due" id="lent_due" value="' . self::h((string)($lentTo !== null ? ($it['lent_due'] ?? '') : $this->lendDue())) . '"></label></div>'
            . '<button class="btn btn-primary btn-wide" id="lend-save">' . self::icon('handoff') . ' ' . $btn . '</button></form>';
        if ($lentTo === null) {
            return '<details class="lendbox" id="lending"><summary>' . self::icon('handoff') . ' Lend this item</summary>' . $form('Mark as lent') . '</details>';
        }
        $late = $this->calendar->isOverdue($it);

        return '<section class="lendbox is-out' . ($late ? ' is-overdue' : '') . '" id="lending" aria-labelledby="lend-title">'
            . '<h2 id="lend-title">' . self::icon($late ? 'alert' : 'handoff') . ' ' . ($late ? 'Overdue' : 'Lent out') . '</h2>'
            . '<p class="lendinfo">With <b>' . self::h((string)$lentTo) . '</b> since ' . self::h($this->date((string)$it['lent_at']))
            . ($it['lent_due'] !== null ? ' · due back <b>' . self::h($this->date((string)$it['lent_due'])) . '</b>' : '') . '</p>'
            . '<form method="post" action="/item/' . $id . '/return"><input type="hidden" name="csrf" value="' . $tok . '">'
            . '<button class="btn btn-primary btn-wide" id="lend-return">' . self::icon('undo') . ' Mark as returned</button></form>'
            . '<details class="lendedit"><summary>Change</summary>' . $form('Save') . '</details></section>';
    }

    /**
     * Legacy picker_html(): the dropdown and the inline "＋ New …" for a kind.
     *
     * @param list<array<string,mixed>> $list
     */
    private function picker(string $kind, array $list, mixed $selected, string $newVal): string
    {
        $t = Kind::get($kind);
        $p = $t['param'];
        $sel = $selected === null ? null : (int)$selected;
        $o = '<div class="field picker half" data-kind="' . $kind . '" data-prefix="' . $p . '" data-endpoint="' . $t['path'] . '">'
            . '<span class="field-head"><label for="' . $p . 'sel">' . self::h($t['One']) . '</label><a href="' . $t['path'] . '" class="field-link">Manage</a></span>'
            . '<div class="locrow"><select name="' . $t['col'] . '" id="' . $p . 'sel" class="locsel">'
            . '<option value="">— ' . self::h($t['none']) . ' —</option>';
        foreach ($list as $l) {
            $o .= '<option value="' . (int)$l['id'] . '"' . ((int)$l['id'] === $sel ? ' selected' : '') . '>' . self::h(LocationTree::optionLabel($l)) . (!empty($l['is_default']) ? ' ★' : '') . '</option>';
        }
        $o .= '<option value="__new"' . ($newVal !== '' ? ' selected' : '') . '>＋ New ' . self::h($t['one']) . '…</option></select>'
            . '<button type="button" class="btn btn-square" id="' . $p . 'add" aria-label="New ' . self::h($t['one']) . '" title="New ' . self::h($t['one']) . '">' . self::icon('plus') . '</button></div>'
            . '<div class="newloc" id="new' . $p . '"' . ($newVal === '' ? ' hidden' : '') . '>'
            . '<input type="text" name="new_' . $kind . '" id="new' . $p . 'name" value="' . self::h($newVal) . '" maxlength="120" autocomplete="off" autocorrect="off" enterkeyhint="done" placeholder="New ' . self::h($t['one']) . ', e.g. ' . self::h($t['example']) . '" aria-label="New ' . self::h($t['one']) . ' name">'
            . '<button type="button" class="btn btn-primary" id="new' . $p . 'ok">Add</button></div>'
            . '<noscript><input type="text" name="new_' . $kind . '_ns" maxlength="120" placeholder="…or type a new ' . self::h($t['one']) . '" class="ns-newloc"></noscript>'
            . (array_filter($list, static fn (array $l): bool => !empty($l['is_default'])) !== [] ? '<small class="field-help star-help" id="' . $p . 'star">★ marks the default ' . self::h($t['one']) . ', preselected for new items.</small>' : '')
            . '</div>';

        return $o;
    }

    /**
     * Legacy autofill_analyze_html(): Analyze for the picked photo and, on the edit form, one per stored photo.
     *
     * @param list<array{id:int,file:string,position:int}> $photos
     */
    private static function analyze(array $photos): string
    {
        $o = '<div class="af-actions" id="af-actions" hidden>'
            . '<button type="button" class="btn btn-sm af-analyze" id="af-analyze" data-af-src="picked" hidden>' . self::icon('sparkle') . ' <span>Analyze photo</span></button>';
        if ($photos !== []) {
            $o .= '<div class="af-photos" id="af-photos"><p class="af-photos-h">Suggest details from a photo of this item</p><ul>';
            foreach ($photos as $i => $p) {
                $o .= '<li class="af-photo"><img src="/media/thumb/' . self::h($p['file']) . '" alt="" width="44" height="44" loading="lazy">'
                    . '<button type="button" class="btn btn-sm af-analyze" id="af-analyze-' . $p['id'] . '" data-af-photo="' . $p['id'] . '" aria-label="Analyze photo ' . ($i + 1) . '">'
                    . self::icon('sparkle') . ' <span>Analyze</span></button></li>';
            }
            $o .= '</ul></div>';
        }

        return $o . '</div>';
    }
}
