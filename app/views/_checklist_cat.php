<?php
// Ein Kategorieblock mit Aufgaben. Erwartet: $cat, $byCat, $subs, $show, $canCheck, $canEdit, $today
$items = $byCat[$cat['id']] ?? [];
$all = $items;
foreach ($items as $i) {
    $all = array_merge($all, $subs[$i['id']] ?? []);
}
$done = count(array_filter($all, fn($i) => !empty($i['done'])));
$visible = $show === 'open'
    ? array_filter($items, fn($i) => empty($i['done']) || array_filter($subs[$i['id']] ?? [], fn($c) => empty($c['done'])))
    : $items;
if ($show === 'open' && !$visible) {
    return;
}
?>
  <section class="cat-block" data-cat-id="<?= e($cat['id']) ?>">
    <header>
      <h2><?= e($cat['name']) ?></h2>
      <span class="cat-progress"><?= $done ?> / <?= count($all) ?></span>
      <?php if ($canEdit): ?>
        <div class="dropdown">
          <button class="btn btn-sm btn-link text-muted" data-bs-toggle="dropdown" aria-label="<?= e(t('cl.cat_actions')) ?>"><i class="bi bi-three-dots"></i></button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#taskModal" data-task="<?= e(json_encode(['cat' => $cat['id']])) ?>"><?= e(t('cl.add_task_here')) ?></button></li>
            <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#catModal" data-cat="<?= e(json_encode($cat)) ?>"><?= e(t('cl.rename')) ?></button></li>
            <?php foreach (['up' => t('cl.move_up'), 'down' => t('cl.move_down')] as $dir => $lbl): ?>
              <li><form method="post" action="<?= e(url('checklist', ['a' => 'catmove'])) ?>" data-cl-form><?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= e($cat['id']) ?>"><input type="hidden" name="dir" value="<?= $dir ?>">
                <button class="dropdown-item"><?= e($lbl) ?></button></form></li>
            <?php endforeach; ?>
            <li><hr class="dropdown-divider"></li>
            <li><form method="post" action="<?= e(url('checklist', ['a' => 'catdelete'])) ?>" data-cl-form data-confirm="<?= e(t('cl.cat_delete_confirm', ['name' => $cat['name'], 'n' => count($all)])) ?>"><?= Csrf::field() ?>
              <input type="hidden" name="id" value="<?= e($cat['id']) ?>"><button class="dropdown-item text-danger"><?= e(t('cl.cat_delete')) ?></button></form></li>
          </ul>
        </div>
      <?php endif; ?>
    </header>
    <?php if (!$visible): ?>
      <p class="text-muted small py-2 mb-0"><?= e(t('cl.cat_empty')) ?></p>
    <?php endif; ?>
    <?php foreach ($visible as $t) { view('_checklist_task', ['t' => $t, 'isSub' => false, 'children' => $subs[$t['id']] ?? [], 'canCheck' => $canCheck, 'canEdit' => $canEdit, 'today' => $today, 'show' => $show], null); } ?>
  </section>
