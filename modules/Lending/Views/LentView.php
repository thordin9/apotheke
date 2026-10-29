<?php

declare(strict_types=1);

namespace Apotheke\Lending\Views;

use Apotheke\Catalog\Calendar;
use Apotheke\Catalog\Views\ItemView;
use Apotheke\Catalog\Views\ListView;
use Apotheke\Layout\Icons;
use Apotheke\Layout\Page;
use Apotheke\Media\ImageStore;

/** The "Lent out" page (legacy view_lent()), byte-identical to legacy. Every value is escaped here. */
final class LentView
{
    public function __construct(
        private readonly Page $page,
        private readonly Calendar $calendar,
        private readonly ItemView $items,
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

    /** @param list<array<string,mixed>> $items */
    public function lent(array $items): string
    {
        $head = $this->page->start('Lent out', 'page-lent');
        $late = count(array_filter($items, fn (array $it): bool => $this->calendar->isOverdue($it)));
        ob_start();
        ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="/" aria-label="Back"><?= self::icon('back') ?></a>
    <h1 class="title-sm">Lent out</h1>
    <span class="spacer"></span>
    <?= $this->page->menu() ?>
  </div>
</header>
<main class="wrap narrow">
  <p class="lead" id="lent-summary"><?= $items !== [] ? ListView::itemNoun(count($items)) . ' out' . ($late !== 0 ? ' · <b class="late">' . $late . ' overdue</b>' : '') : 'Nothing is lent out right now.' ?></p>
  <?php if ($items !== []): ?>
  <ul class="lentlist" id="lentlist">
    <?php foreach ($items as $it): $o = $this->calendar->isOverdue($it); ?>
    <li class="lent<?= $o ? ' is-overdue' : '' ?>">
      <a class="lent-main" href="/item/<?= (int)$it['id'] ?>">
        <span class="lent-img"><?= ImageStore::validName(is_string($it['image'] ?? null) ? $it['image'] : null) ? '<img src="/media/thumb/' . self::h((string)$it['image']) . '" alt="" loading="lazy">' : self::icon('box') ?></span>
        <span class="lent-text"><b><?= self::h((string)$it['name']) ?></b>
          <span><?= self::h((string)$it['lent_to']) ?> · since <?= self::h($this->items->date((string)$it['lent_at'])) ?></span>
          <span class="lent-due"><?= $it['lent_due'] !== null ? ($o ? 'Overdue · was due ' : 'Due ') . self::h($this->items->date((string)$it['lent_due'])) : 'No due date' ?></span></span>
      </a>
      <form method="post" action="/item/<?= (int)$it['id'] ?>/return"><input type="hidden" name="csrf" value="<?= self::h($this->page->csrf()) ?>"><input type="hidden" name="back" value="/lent">
        <button class="btn btn-sm" aria-label="Mark <?= self::h((string)$it['name']) ?> as returned"><?= self::icon('undo') ?> Returned</button></form>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</main>
<?php

        return $head . (string)ob_get_clean() . $this->page->end();
    }
}
