<?php $pageTitle = t('backup.title'); ?>
<div class="page-head"><h1><?= e(t('admin.title')) ?></h1></div>
<?php require __DIR__ . '/_adminnav.php'; ?>
<div class="row g-4">
  <div class="col-lg-6">
    <section class="surface surface-pad h-100">
      <h2 class="h4"><?= e(t('backup.download')) ?></h2>
      <p><?= e(t('backup.intro', ['count' => $imgCount, 'size' => fmt_bytes($imgSize)])) ?></p>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="<?= e(url('admin', ['a' => 'download'])) ?>"><i class="bi bi-database-down me-1"></i><?= e(t('backup.data_zip')) ?></a>
        <a class="btn btn-outline-primary" href="<?= e(url('admin', ['a' => 'download', 'full' => 1])) ?>"><i class="bi bi-file-zip me-1"></i><?= e(t('backup.full_zip')) ?></a>
      </div>
      <p class="small text-muted mt-3 mb-0"><?= t('backup.restore', ['dir' => '<code>data/</code>']) ?></p>
    </section>
  </div>
  <div class="col-lg-6">
    <section class="surface surface-pad h-100">
      <h2 class="h4"><?= e(t('backup.snapshots')) ?></h2>
      <p><?= t('backup.snapshots_intro', ['days' => (int)cfg('backup_keep_days', 14), 'dir' => '<code>data/backups/</code>']) ?></p>
      <?php if ($snapshots): ?>
        <p class="small mb-2"><?= e(t('backup.existing', ['list' => implode(', ', array_map('fmt_date', $snapshots))])) ?></p>
      <?php else: ?><p class="small text-muted"><?= e(t('backup.none')) ?></p><?php endif; ?>
      <form method="post" action="<?= e(url('admin', ['a' => 'snapshot'])) ?>"><?= Csrf::field() ?>
        <button class="btn btn-outline-primary btn-sm"><?= e(t('backup.snapshot_now')) ?></button></form>
    </section>
  </div>
</div>

<?php $groups = []; foreach (Transfer::parts() as $k => $p) { $groups[$p['group']][$k] = $p; } ?>
<div class="row g-4 mt-0">
  <div class="col-lg-6">
    <section class="surface surface-pad h-100">
      <h2 class="h4"><?= e(t('transfer.export_h')) ?></h2>
      <p><?= e(t('transfer.intro')) ?></p>
      <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#exportModal"><i class="bi bi-box-arrow-up me-1"></i><?= e(t('transfer.export_btn')) ?></button>
    </section>
  </div>
  <div class="col-lg-6">
    <section class="surface surface-pad h-100">
      <h2 class="h4"><?= e(t('transfer.import_h')) ?></h2>
      <p class="small text-muted"><?= e(t('transfer.import_intro', ['size' => fmt_bytes(upload_limit_bytes())])) ?></p>
      <form method="post" action="<?= e(url('admin', ['a' => 'transferimport'])) ?>" enctype="multipart/form-data" class="row g-2 align-items-end"><?= Csrf::field() ?>
        <div class="col-md-8"><label class="form-label" for="tr-zip"><?= e(t('transfer.import_file')) ?></label>
          <input class="form-control" type="file" id="tr-zip" name="package" accept=".zip,application/zip" required></div>
        <div class="col-md-4"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-box-arrow-in-down me-1"></i><?= e(t('transfer.import_btn')) ?></button></div>
      </form>
    </section>
  </div>
</div>

<div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <form class="modal-content" method="post" action="<?= e(url('admin', ['a' => 'transferexport'])) ?>"><?= Csrf::field() ?>
      <div class="modal-header"><h2 class="modal-title h4" id="exportModalTitle"><?= e(t('transfer.export_modal')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('common.close')) ?>"></button></div>
      <div class="modal-body">
        <?php foreach ($groups as $g => $list): ?>
          <h3 class="h6 text-uppercase text-muted mt-2"><?= e(t('transfer.group.' . $g)) ?></h3>
          <?php foreach ($list as $k => $p): ?>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="parts[]" value="<?= e($k) ?>" id="ex-<?= e($k) ?>"<?= $p['on'] ? ' checked' : '' ?>>
              <label class="form-check-label" for="ex-<?= e($k) ?>"><?= e(t('transfer.part.' . $k)) ?><small class="d-block text-muted"><?= e(t('transfer.hint.' . $k)) ?></small></label>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <p class="small text-muted mb-0"><?= e(t('transfer.sensitive')) ?></p>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary"><i class="bi bi-download me-1"></i><?= e(t('transfer.export_do')) ?></button></div>
    </form>
  </div>
</div>
