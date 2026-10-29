<?php

declare(strict_types=1);

namespace Apotheke\Labels\Views;

use Apotheke\Labels\QrJit;
use Apotheke\Layout\Icons;
use Apotheke\Layout\Page;

/**
 * The QR labels page (legacy view_labels()), byte-identical to legacy. Every
 * value is escaped here, the QR code's aria-label in QrCode::svg().
 */
final class LabelsView
{
    /**
     * @param array{label_width_mm:int,label_height_mm:int,label_columns:int,label_gap_mm:int,label_border:int,label_show_sub:int,label_show_url:int} $cfg
     * @param string $publicUrl the public_url setting ('' = the address in use)
     * @param string $base legacy public_url(''): the setting, else this request's scheme and allowed Host
     */
    public function __construct(
        private readonly Page $page,
        private readonly array $cfg,
        private readonly string $publicUrl,
        private readonly string $base,
    ) {
    }

    private static function h(?string $s): string
    {
        return Page::e($s);
    }

    /** Legacy public_url(). */
    public function url(string $path): string
    {
        return $this->base . $path;
    }

    /**
     * @param list<array{title:string,sub:string,path:string}> $labels
     * @param array<string,string> $options href => label of the other label sets
     */
    public function labels(string $heading, array $labels, string $back, array $options = []): string
    {
        $head = $this->page->start('Labels', 'page-labels');
        $cfg = array_values($this->cfg);
        ob_start();
        ?>
<link rel="stylesheet" href="/labels.css?v=<?= substr(md5(implode(',', $cfg)), 0, 8) ?>" id="labels-css">
<header class="top top-simple no-print">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="<?= self::h($back) ?>" data-back aria-label="Back"><?= Icons::svg('back') ?></a>
    <h1 class="title-sm">QR labels</h1>
    <button type="button" class="btn btn-primary" id="print" data-print><?= Icons::svg('printer') ?><span class="hide-xs">Print</span></button>
  </div>
</header>
<main class="wrap labels-wrap">
  <div class="no-print labels-intro">
    <h2 class="item-name"><?= self::h($heading) ?></h2>
    <p class="hint">Scan a label with the phone camera to open <?= $options !== [] ? 'the matching list or item' : 'it' ?> in Apotheke. Links point to <b><?= self::h($this->url('')) ?></b><?= $this->publicUrl === '' ? ' (change it in <a href="/settings/general">Settings → General</a>)' : '' ?>.</p>
    <p class="hint labels-size"><?= count($labels) ?> label<?= count($labels) === 1 ? '' : 's' ?> · <?= $cfg[0] ?> × <?= $cfg[1] ?> mm, <?= $cfg[2] ?> per row (<a href="/settings/labels">change</a>)</p>
    <?php if ($options !== []): ?><nav class="label-opts" aria-label="Label sets"><?php foreach ($options as $href => $txt): ?><a class="achip" href="<?= self::h((string)$href) ?>"><?= self::h($txt) ?></a><?php endforeach; ?></nav><?php endif; ?>
  </div>
  <?php if ($labels === []): ?>
  <div class="empty"><?= Icons::svg('qr') ?><p class="empty-title">Nothing to print yet.</p></div>
  <?php else: ?>
  <div class="labels" id="labels">
    <?php foreach ($labels as $l): $url = $this->url($l['path']); ?>
    <figure class="label" data-url="<?= self::h($url) ?>">
      <?= QrJit::svg($url, 'QR code for ' . $l['title']) ?>
      <figcaption><b><?= self::h($l['title']) ?></b><?php if ($l['sub'] !== '' && $this->cfg['label_show_sub'] !== 0): ?><span><?= self::h($l['sub']) ?></span><?php endif; ?><?php if ($this->cfg['label_show_url'] !== 0): ?><small><?= self::h((string)preg_replace('#^https?://#', '', $url)) ?></small><?php endif; ?></figcaption>
    </figure>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</main>
<?php

        return $head . (string)ob_get_clean() . $this->page->end();
    }
}
