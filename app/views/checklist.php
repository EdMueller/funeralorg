<?php
$pageTitle = t('cl.title');
$today = date('Y-m-d');
$totalOpen = 0;
$total = 0;
foreach (array_merge(array_merge(...array_values($byCat ?: [[]])), array_merge(...array_values($subs ?: [[]]))) as $i) {
    $total++;
    $totalOpen += empty($i['done']) ? 1 : 0;
}

// Eine Aufgabenzeile (Haupt- oder Unteraufgabe)
$renderTask = function (array $t, bool $isSub, array $children = []) use (&$renderTask, $canCheck, $canEdit, $today, $show) {
    $overdue = empty($t['done']) && $t['due'] && $t['due'] < $today;
    $kids = $show === 'open' ? array_filter($children, fn($c) => empty($c['done'])) : $children;
    $kidsDone = count(array_filter($children, fn($c) => !empty($c['done'])));
    $cid = 'sub-' . $t['id'];
    ?>
      <div class="task<?= !empty($t['done']) ? ' done' : '' ?><?= $isSub ? ' subtask' : '' ?>" data-task-id="<?= e($t['id']) ?>">
        <?php if (!$isSub): ?>
          <?php if ($children): ?>
            <button class="btn btn-sm btn-link text-muted sub-toggle" type="button" data-sub-toggle="<?= e($cid) ?>"
                    aria-expanded="<?= $show === 'open' ? 'true' : 'false' ?>" aria-controls="<?= e($cid) ?>" aria-label="<?= e(t('cl.toggle_subs')) ?>"><i class="bi bi-chevron-right"></i></button>
          <?php else: ?>
            <span class="sub-toggle-spacer"></span>
          <?php endif; ?>
        <?php endif; ?>
        <button class="check" type="button" data-toggle-task="<?= e($t['id']) ?>" <?= $canCheck ? '' : 'disabled' ?>
                aria-pressed="<?= !empty($t['done']) ? 'true' : 'false' ?>" aria-label="<?= e(t('cl.done_aria', ['title' => $t['title']])) ?>"><i class="bi bi-check-lg"></i></button>
        <div class="task-body">
          <div class="task-title"><?= e($t['title']) ?><?php if ($children): ?> <span class="sub-count"><?= $kidsDone ?> / <?= count($children) ?></span><?php endif; ?></div>
          <div class="task-meta">
            <?php if ($t['due']): ?><span class="<?= $overdue ? 'overdue' : '' ?>"><i class="bi bi-calendar-event me-1"></i><?= e(t('cl.due', ['date' => fmt_date($t['due'])])) ?></span><?php endif; ?>
            <?php if ($t['who']): ?><span><i class="bi bi-person me-1"></i><?= e($t['who']) ?></span><?php endif; ?>
            <span class="done-info"><?php if (!empty($t['done'])): ?><?= e(t('cl.done_by', ['by' => $t['done_by'], 'date' => fmt_date($t['done_at'], true)])) ?><?php endif; ?></span>
          </div>
          <?php if ($t['note']): ?><div class="task-note"><?= e($t['note']) ?></div><?php endif; ?>
        </div>
        <?php if ($canEdit): ?>
          <?php if (!$isSub): ?>
            <button class="btn btn-sm btn-link text-muted" data-bs-toggle="modal" data-bs-target="#taskModal"
                    data-task="<?= e(json_encode(['parent' => $t['id'], 'cat' => $t['cat']])) ?>" aria-label="<?= e(t('cl.add_sub')) ?>"><i class="bi bi-node-plus"></i></button>
          <?php endif; ?>
          <button class="btn btn-sm btn-link text-muted" data-bs-toggle="modal" data-bs-target="#taskModal"
                  data-task="<?= e(json_encode($t)) ?>" data-subs="<?= count($children) ?>" aria-label="<?= e(t('cl.edit')) ?>"><i class="bi bi-pencil"></i></button>
        <?php endif; ?>
      </div>
      <?php if ($kids): ?>
        <div class="subtasks<?= $show === 'open' ? '' : ' d-none' ?>" id="<?= e($cid) ?>" data-sub-id="<?= e($t['id']) ?>">
          <?php foreach ($kids as $k): $renderTask($k, true); endforeach; ?>
        </div>
      <?php endif; ?>
    <?php
};
?>
<div class="page-head">
  <div>
    <h1><?= e(t('cl.title')) ?></h1>
    <p class="lead-note"><?= e(t('cl.lead', ['open' => $totalOpen, 'done' => $total - $totalOpen])) ?></p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <div class="btn-group" role="group" aria-label="<?= e(t('cl.view')) ?>">
      <a class="btn btn-sm btn-outline-primary<?= $show !== 'open' ? ' active' : '' ?>" href="<?= e(url('checklist')) ?>"><?= e(t('cl.all')) ?></a>
      <a class="btn btn-sm btn-outline-primary<?= $show === 'open' ? ' active' : '' ?>" href="<?= e(url('checklist', ['show' => 'open'])) ?>"><?= e(t('cl.only_open')) ?></a>
    </div>
    <?php if ($canEdit): ?>
      <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#taskModal" data-task="{}"><i class="bi bi-plus-lg me-1"></i><?= e(t('cl.add_task')) ?></button>
      <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#catModal" data-cat="{}"><i class="bi bi-folder-plus me-1"></i><?= e(t('cl.add_cat')) ?></button>
    <?php endif; ?>
  </div>
</div>

<?php if (!$categories): ?>
  <div class="empty surface"><i class="bi bi-list-check"></i><?= e(t('cl.no_cats')) ?> <?= $canEdit ? e(t('cl.no_cats_hint')) : '' ?></div>
<?php endif; ?>

<?php foreach ($categories as $ci => $cat):
    $items = $byCat[$cat['id']] ?? [];
    $all = $items;
    foreach ($items as $i) {
        $all = array_merge($all, $subs[$i['id']] ?? []);
    }
    $done = count(array_filter($all, fn($i) => !empty($i['done'])));
    $visible = $show === 'open'
        ? array_filter($items, fn($i) => empty($i['done']) || array_filter($subs[$i['id']] ?? [], fn($c) => empty($c['done'])))
        : $items;
    if ($show === 'open' && !$visible) continue;
?>
  <section class="cat-block">
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
              <li><form method="post" action="<?= e(url('checklist', ['a' => 'catmove'])) ?>"><?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= e($cat['id']) ?>"><input type="hidden" name="dir" value="<?= $dir ?>">
                <button class="dropdown-item"><?= e($lbl) ?></button></form></li>
            <?php endforeach; ?>
            <li><hr class="dropdown-divider"></li>
            <li><form method="post" action="<?= e(url('checklist', ['a' => 'catdelete'])) ?>" data-confirm="<?= e(t('cl.cat_delete_confirm', ['name' => $cat['name'], 'n' => count($all)])) ?>"><?= Csrf::field() ?>
              <input type="hidden" name="id" value="<?= e($cat['id']) ?>"><button class="dropdown-item text-danger"><?= e(t('cl.cat_delete')) ?></button></form></li>
          </ul>
        </div>
      <?php endif; ?>
    </header>
    <?php if (!$visible): ?>
      <p class="text-muted small py-2 mb-0"><?= e(t('cl.cat_empty')) ?></p>
    <?php endif; ?>
    <?php foreach ($visible as $t) { $renderTask($t, false, $subs[$t['id']] ?? []); } ?>
  </section>
<?php endforeach; ?>

<?php if ($canEdit): ?>
<div class="modal fade" id="taskModal" tabindex="-1" aria-labelledby="taskModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= e(url('checklist', ['a' => 'save'])) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="id">
      <input type="hidden" name="parent">
      <button type="submit" class="visually-hidden" tabindex="-1" aria-hidden="true"><?= e(t('cl.save_task')) ?></button><!-- Enter speichert, nie „Löschen“ -->
      <div class="modal-header"><h2 class="modal-title h4" id="taskModalTitle"><?= e(t('cl.modal_task')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('common.close')) ?>"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label" for="t-title"><?= e(t('cl.f_title')) ?></label>
          <input class="form-control" id="t-title" name="title" required maxlength="300"></div>
        <div class="mb-3" data-task-cat><label class="form-label" for="t-cat"><?= e(t('cl.f_cat')) ?></label>
          <select class="form-select" id="t-cat" name="cat">
            <?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="row g-3">
          <div class="col-6"><label class="form-label" for="t-due"><?= e(t('cl.f_due')) ?></label>
            <input class="form-control" type="date" id="t-due" name="due"></div>
          <div class="col-6"><label class="form-label" for="t-who"><?= e(t('cl.f_who')) ?></label>
            <input class="form-control" id="t-who" name="who" maxlength="100"></div>
        </div>
        <div class="mt-3"><label class="form-label" for="t-note"><?= e(t('cl.f_note')) ?></label>
          <textarea class="form-control" id="t-note" name="note" rows="3"></textarea></div>
      </div>
      <div class="modal-footer justify-content-between">
        <button type="submit" class="btn btn-outline-danger btn-sm" data-delete-task formaction="<?= e(url('checklist', ['a' => 'delete'])) ?>" formnovalidate data-confirm-click="<?= e(t('cl.delete_confirm')) ?>"><?= e(t('common.delete')) ?></button>
        <button type="submit" class="btn btn-primary"><?= e(t('cl.save_task')) ?></button>
      </div>
    </form>
  </div>
</div>
<div class="modal fade" id="catModal" tabindex="-1" aria-labelledby="catModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <form class="modal-content" method="post" action="<?= e(url('checklist', ['a' => 'catsave'])) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="id">
      <div class="modal-header"><h2 class="modal-title h4" id="catModalTitle"><?= e(t('cl.modal_cat')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('common.close')) ?>"></button></div>
      <div class="modal-body"><label class="form-label" for="c-name"><?= e(t('cl.cat_name')) ?></label>
        <input class="form-control" id="c-name" name="name" required maxlength="100"></div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= e(t('cl.save_cat')) ?></button></div>
    </form>
  </div>
</div>
<?php endif; ?>
