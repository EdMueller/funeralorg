<?php
$pageTitle = t('cl.title');
?>
<div class="page-head">
  <div>
    <h1><?= e(t('cl.title')) ?></h1>
    <p class="lead-note" data-cl-lead><?= e(cl_lead($items)) ?></p>
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

<div class="empty surface" data-cl-empty<?= $categories ? ' hidden' : '' ?>><i class="bi bi-list-check"></i><?= e(t('cl.no_cats')) ?> <?= $canEdit ? e(t('cl.no_cats_hint')) : '' ?></div>

<div id="clRoot" data-show="<?= e($show) ?>">
<?php foreach ($categories as $cat) { view('_checklist_cat', compact('cat', 'byCat', 'subs', 'show', 'canCheck', 'canEdit', 'today'), null); } ?>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="taskModal" tabindex="-1" aria-labelledby="taskModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= e(url('checklist', ['a' => 'save'])) ?>" data-cl-form data-cl-modal>
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
    <form class="modal-content" method="post" action="<?= e(url('checklist', ['a' => 'catsave'])) ?>" data-cl-form data-cl-modal>
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
