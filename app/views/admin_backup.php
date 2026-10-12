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
