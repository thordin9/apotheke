<?php

declare(strict_types=1);

namespace Apotheke\Taxonomy\Views;

use Apotheke\Layout\Icons;
use Apotheke\Layout\Page;
use Apotheke\Taxonomy\Kind;
use Apotheke\Taxonomy\LocationTree;

/**
 * The Manage pages (legacy view_manage(), view_tax_delete(), manage_tabs()
 * and parent_options()). The markup is copied from legacy so the output is
 * byte-identical; every value is escaped with Page::e().
 *
 * @phpstan-type Entry array{id:int,name:string,is_default:int,n:int,parent_id:?int,depth:int,path:string,total:int}
 */
final class ManageView
{
    /** @param list<Entry> $list */
    public static function manage(Page $page, string $kind, array $list, int $unassigned, ?string $error, ?string $toast): string
    {
        $t = Kind::get($kind);
        $head = $page->start($t['Many'], 'page-locs');
        $tok = Page::e($page->csrf());
        $base = $t['path'];
        ob_start();
        ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="/" aria-label="Back"><?= Icons::svg('back') ?></a>
    <h1 class="title-sm">Manage</h1>
    <?= $page->menu() ?>
  </div>
  <?= self::tabs($kind) ?>
</header>
<main class="wrap narrow">
  <form method="post" action="<?= $base ?>" class="addloc" id="addloc">
    <input type="hidden" name="csrf" value="<?= $tok ?>">
    <input type="text" name="name" required maxlength="120" autocomplete="off" autocorrect="off" enterkeyhint="done" placeholder="New <?= Page::e($t['one']) ?>, e.g. <?= Page::e($t['example']) ?>" aria-label="New <?= Page::e($t['one']) ?> name">
    <button class="btn btn-primary"><?= Icons::svg('plus') ?> Add</button>
    <?php if ($kind === 'location' && $list): ?>
    <label class="field addloc-parent"><span>Inside</span><select name="parent" id="addloc-parent"><?= self::parentOptions($list, null, null) ?></select></label>
    <?php endif; ?>
  </form>
  <div class="err" role="alert"<?= $error === null ? ' hidden' : '' ?>><?= Page::e($error) ?></div>
  <?php if ($kind === 'location'): ?><p class="item-links"><a class="btn btn-sm" href="/labels"><?= Icons::svg('qr') ?> Print QR labels for all locations</a></p><?php endif; ?>
  <p class="hint">★ The default <?= Page::e($t['one']) ?> is preselected when you add a new item. Tap ★ again to clear it.</p>

  <?php if (!$list): ?>
  <div class="empty"><?= Icons::svg($t['icon']) ?><p>No <?= Page::e($t['many']) ?> yet. Add your first one above.</p></div>
  <?php else: ?>
  <ul class="loclist">
    <?php foreach ($list as $l): $id = (int)$l['id']; $n = (int)$l['n']; ?>
    <li class="loc<?= $l['is_default'] ? ' is-default' : '' ?><?= ($d = min($l['depth'], LocationTree::MAX_DEPTH)) > 0 ? ' loc-d' . $d : '' ?>" id="<?= $t['param'] ?>-<?= $id ?>"<?= $kind === 'location' ? ' data-depth="' . (int)$l['depth'] . '"' : '' ?>>
      <div class="loc-main">
        <form method="post" action="<?= $base ?>/<?= $id ?>/default" class="loc-star-form">
          <input type="hidden" name="csrf" value="<?= $tok ?>">
          <input type="hidden" name="on" value="<?= $l['is_default'] ? '0' : '1' ?>">
          <button class="loc-star" aria-label="<?= $l['is_default'] ? 'Unset default' : 'Set as default' ?>" title="<?= $l['is_default'] ? 'Default – tap to unset' : 'Set as default' ?>"><?= Icons::svg('star') ?></button>
        </form>
        <a class="loc-name" href="/?<?= $t['param'] ?>=<?= $id ?>"><?= Page::e($l['name']) ?><?php if ($l['is_default']): ?> <span class="badge">Default</span><?php endif; ?></a>
        <?php $tot = $l['total']; ?>
        <a class="loc-count" href="/?<?= $t['param'] ?>=<?= $id ?>" title="<?= $tot !== $n ? Page::e($n . ' here, ' . ($tot - $n) . ' in sub-locations') : '' ?>"><?= $tot ?> item<?= $tot === 1 ? '' : 's' ?></a>
      </div>
      <details class="loc-more">
        <summary><?= Icons::svg('edit') ?> Edit</summary>
        <div class="loc-actions">
          <form method="post" action="<?= $base ?>/<?= $id ?>/rename" class="loc-rename">
            <input type="hidden" name="csrf" value="<?= $tok ?>">
            <input type="text" name="name" value="<?= Page::e($l['name']) ?>" required maxlength="120" autocomplete="off" autocorrect="off" aria-label="Rename <?= Page::e($t['one']) ?>">
            <button class="btn">Rename</button>
          </form>
          <?php if ($kind === 'location' && count($list) > 1): ?>
          <form method="post" action="<?= $base ?>/<?= $id ?>/parent" class="loc-parent">
            <input type="hidden" name="csrf" value="<?= $tok ?>">
            <select name="parent" aria-label="Put “<?= Page::e($l['name']) ?>” inside"><?= self::parentOptions($list, $id, $l['parent_id']) ?></select>
            <button class="btn">Move</button>
          </form>
          <?php endif; ?>
          <div class="loc-btns">
            <form method="post" action="<?= $base ?>/<?= $id ?>/default">
              <input type="hidden" name="csrf" value="<?= $tok ?>">
              <input type="hidden" name="on" value="<?= $l['is_default'] ? '0' : '1' ?>">
              <button class="btn"><?= Icons::svg('star') ?> <?= $l['is_default'] ? 'Unset default' : 'Set as default' ?></button>
            </form>
            <?php if ($kind === 'location'): ?><a class="btn" href="/labels?loc=<?= $id ?>"><?= Icons::svg('qr') ?> Label</a><?php endif; ?>
            <?php if ($n > 0): ?>
            <a class="btn btn-danger" href="<?= $base ?>/<?= $id ?>/delete"><?= Icons::svg('trash') ?> Delete…</a>
            <?php else: ?>
            <form method="post" action="<?= $base ?>/<?= $id ?>/delete" data-confirm="Delete <?= Page::e($t['one']) ?> “<?= Page::e($l['name']) ?>”?">
              <input type="hidden" name="csrf" value="<?= $tok ?>">
              <input type="hidden" name="action" value="unassign">
              <button class="btn btn-danger"><?= Icons::svg('trash') ?> Delete</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </details>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($unassigned > 0): ?>
  <p class="hint"><a href="/?<?= $t['param'] ?>=none"><?= $unassigned ?> item<?= $unassigned === 1 ? '' : 's' ?> without a <?= Page::e($t['one']) ?> →</a></p>
  <?php endif; ?>
</main>
<?php if ($toast !== null): ?><div class="toast" role="status"><?= Page::e($toast) ?></div><?php endif; ?>
<?php

        return $head . (string)ob_get_clean() . $page->end();
    }

    /**
     * @param array{id:int,name:string,is_default:int,n:int,children?:int,parent_name?:string} $entry
     * @param list<Entry> $others
     */
    public static function confirmDelete(Page $page, string $kind, array $entry, array $others): string
    {
        $t = Kind::get($kind);
        $head = $page->start('Delete ' . $entry['name'], 'page-locs');
        $n = (int)$entry['n'];
        ob_start();
        ?>
<header class="top top-simple">
  <div class="bar">
    <a class="btn btn-ghost btn-round" href="<?= $t['path'] ?>" aria-label="Back"><?= Icons::svg('back') ?></a>
    <h1 class="title-sm">Delete <?= Page::e($t['one']) ?></h1>
    <span class="spacer"></span>
  </div>
</header>
<main class="wrap narrow">
  <h2 class="item-name">“<?= Page::e($entry['name']) ?>”</h2>
  <p class="lead"><?= $n ?> item<?= $n === 1 ? ' is' : 's are' ?> directly in this <?= Page::e($t['one']) ?>. What should happen to <?= $n === 1 ? 'it' : 'them' ?>?</p>
  <?php if (!empty($entry['children'])): ?><p class="hint" id="del-children">Its <?= (int)$entry['children'] ?> sub-location<?= (int)$entry['children'] === 1 ? '' : 's' ?> (and everything in <?= (int)$entry['children'] === 1 ? 'it' : 'them' ?>) move up one level<?= !empty($entry['parent_name']) ? ' into “' . Page::e($entry['parent_name']) . '”' : ' to the top level' ?>.</p><?php endif; ?>
  <form method="post" action="<?= $t['path'] ?>/<?= (int)$entry['id'] ?>/delete" class="itemform" id="locdel">
    <input type="hidden" name="csrf" value="<?= Page::e($page->csrf()) ?>">
    <?php if ($others): ?>
    <label class="opt">
      <input type="radio" name="action" value="move" checked>
      <span><strong>Move items to</strong>
        <select name="target" aria-label="Move to <?= Page::e($t['one']) ?>">
          <?php foreach ($others as $o): ?>
          <option value="<?= (int)$o['id'] ?>"<?= $o['is_default'] ? ' selected' : '' ?>><?= Page::e(LocationTree::optionLabel($o)) ?> (<?= (int)$o['n'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </span>
    </label>
    <?php endif; ?>
    <label class="opt">
      <input type="radio" name="action" value="unassign"<?= $others ? '' : ' checked' ?>>
      <span><strong>Leave items without a <?= Page::e($t['one']) ?></strong><small>The items are kept; only the <?= Page::e($t['one']) ?> is removed.</small></span>
    </label>
    <div class="actionbar actionbar-form">
      <div class="actionbar-in">
        <a class="btn" href="<?= $t['path'] ?>">Cancel</a>
        <button class="btn btn-danger-solid"><?= Icons::svg('trash') ?> Delete</button>
      </div>
    </div>
  </form>
</main>
<?php

        return $head . (string)ob_get_clean() . $page->end();
    }

    public static function tabs(string $kind): string
    {
        $o = '<nav class="tabs" aria-label="Manage">';
        foreach (Kind::KINDS as $k) {
            $t = Kind::get($k);
            $o .= '<a href="' . $t['path'] . '"' . ($k === $kind ? ' class="on" aria-current="page"' : '') . '>' . Icons::svg($t['icon']) . ' ' . Page::e($t['Many']) . '</a>';
        }

        return $o . '</nav>';
    }

    /**
     * <option>s for a parent picker: "Top level" plus every location except
     * $self and its sub-locations.
     *
     * @param list<Entry> $list
     */
    public static function parentOptions(array $list, ?int $self, ?int $cur): string
    {
        $o = '<option value="">' . ($self === null ? 'Top level' : '— Top level —') . '</option>';
        $skip = -1;
        foreach ($list as $l) {
            if ($skip >= 0 && $l['depth'] > $skip) {
                continue; // inside $self: not allowed
            }
            $skip = -1;
            if ($self !== null && $l['id'] === $self) {
                $skip = $l['depth'];
                continue;
            }
            $o .= '<option value="' . $l['id'] . '"' . ($cur === $l['id'] ? ' selected' : '') . '>' . Page::e(LocationTree::optionLabel($l)) . '</option>';
        }

        return $o;
    }
}
