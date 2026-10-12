<?php $pageTitle = t('groups.title'); ?>
<div class="page-head"><h1><?= e(t('admin.title')) ?></h1>
  <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#g-new"><i class="bi bi-plus-lg me-1"></i><?= e(t('groups.add')) ?></button></div>
<?php require __DIR__ . '/_adminnav.php'; ?>
<p class="text-muted"><?= e(t('groups.intro')) ?></p>

<?php
$blank = ['id' => '', 'name' => '', 'perms' => [], 'fields' => [], 'gallery' => []];
foreach (array_merge([$blank], $groups) as $g):
    $isNew = $g['id'] === '';
?>
<form method="post" action="<?= e(url('admin', ['a' => 'groupsave'])) ?>" class="surface surface-pad mb-3<?= $isNew ? ' collapse' : '' ?>" <?= $isNew ? 'id="g-new"' : '' ?>>
  <?= Csrf::field() ?><input type="hidden" name="id" value="<?= e($g['id']) ?>">
  <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
    <div class="flex-grow-1" style="max-width:22rem"><label class="form-label" for="gn-<?= e($g['id']) ?>"><?= e(t($isNew ? 'groups.new' : 'groups.group')) ?></label>
      <input class="form-control serif fs-5" id="gn-<?= e($g['id']) ?>" name="name" value="<?= e($g['name']) ?>" required placeholder="<?= e(t('groups.name_ph')) ?>"></div>
    <?php if (!$isNew): ?><span class="text-muted small pb-2"><?= e(t('groups.usage', ['n' => $usage[$g['id']] ?? 0])) ?></span><?php endif; ?>
  </div>
  <div class="row g-4">
    <div class="col-lg-7">
      <div class="table-wrap"><table class="table table-sm perm-table mb-0">
        <thead><tr><th><?= e(t('groups.area')) ?></th><?php foreach (Perm::levels() as $lbl): ?><th class="text-center small"><?= e($lbl) ?></th><?php endforeach; ?></tr></thead>
        <tbody><?php foreach (Perm::modules() as $m => $mlbl): $cur = (int)($g['perms'][$m] ?? 0); ?>
          <tr><td><?= e($mlbl) ?></td>
            <?php foreach (Perm::levels() as $lv => $lbl): ?>
              <td class="text-center"><input class="form-check-input" type="radio" name="perm[<?= $m ?>]" value="<?= $lv ?>" <?= $cur === $lv ? 'checked' : '' ?> aria-label="<?= e($mlbl . ': ' . $lbl) ?>"></td>
            <?php endforeach; ?></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </div>
    <div class="col-sm-6 col-lg-2">
      <p class="fw-semibold small mb-2"><?= e(t('groups.visible_fields')) ?></p>
      <?php foreach (Perm::fields() as $f => $lbl): ?>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="f-<?= e($g['id'] . $f) ?>" name="field[<?= $f ?>]" value="1" <?= !empty($g['fields'][$f]) ? 'checked' : '' ?>>
          <label class="form-check-label" for="f-<?= e($g['id'] . $f) ?>"><?= e($lbl) ?></label></div>
      <?php endforeach; ?>
    </div>
    <div class="col-sm-6 col-lg-3">
      <p class="fw-semibold small mb-2"><?= e(t('groups.gallery_title')) ?> <span class="fw-normal text-muted"><?= e(t('groups.login_only')) ?></span></p>
      <?php foreach (['view' => t('groups.gal_view'), 'upload' => t('groups.gal_upload'), 'download' => t('groups.gal_download')] as $k => $lbl): ?>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="gal-<?= e($g['id'] . $k) ?>" name="gallery[<?= $k ?>]" value="1" <?= !empty($g['gallery'][$k]) ? 'checked' : '' ?>>
          <label class="form-check-label" for="gal-<?= e($g['id'] . $k) ?>"><?= e($lbl) ?></label></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="d-flex gap-2 mt-3">
    <button class="btn btn-primary btn-sm" type="submit"><?= e(t('groups.save')) ?></button>
    <?php if (!$isNew && empty($usage[$g['id']])): ?>
      <button class="btn btn-outline-danger btn-sm" type="submit" formaction="<?= e(url('admin', ['a' => 'groupdelete'])) ?>" formnovalidate
              data-confirm-click="<?= e(t('groups.delete_confirm', ['name' => $g['name']])) ?>"><?= e(t('common.delete')) ?></button>
    <?php endif; ?>
  </div>
</form>
<?php endforeach; ?>
