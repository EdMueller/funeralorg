<?php
// Eine Aufgabenzeile (Haupt- oder Unteraufgabe) samt Unteraufgaben.
// Erwartet: $t, $isSub, $children, $canCheck, $canEdit, $today, $show
$overdue = empty($t['done']) && $t['due'] && $t['due'] < $today;
$kids = $show === 'open' ? array_filter($children, fn($c) => empty($c['done'])) : $children;
$kidsDone = count(array_filter($children, fn($c) => !empty($c['done'])));
$cid = 'sub-' . $t['id'];
?>
      <div class="task<?= !empty($t['done']) ? ' done' : '' ?><?= $isSub ? ' subtask' : '' ?>" data-task-id="<?= e($t['id']) ?>">
        <?php if ($canEdit): ?>
          <span class="drag-handle" draggable="true" data-drag-task title="<?= e(t('cl.drag')) ?>" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>
        <?php endif; ?>
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
          <span class="task-arrows">
            <button class="btn btn-sm btn-link text-muted" type="button" data-task-move="-1" aria-label="<?= e(t('cl.task_up', ['title' => $t['title']])) ?>"><i class="bi bi-chevron-up"></i></button>
            <button class="btn btn-sm btn-link text-muted" type="button" data-task-move="1" aria-label="<?= e(t('cl.task_down', ['title' => $t['title']])) ?>"><i class="bi bi-chevron-down"></i></button>
          </span>
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
          <?php foreach ($kids as $k) { view('_checklist_task', ['t' => $k, 'isSub' => true, 'children' => [], 'canCheck' => $canCheck, 'canEdit' => $canEdit, 'today' => $today, 'show' => $show], null); } ?>
        </div>
      <?php endif; ?>
