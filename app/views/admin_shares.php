<?php
$pageTitle = t('shares.title');
$gNames = array_column($groups, 'name', 'id');
?>
<div class="page-head"><h1><?= e(t('admin.title')) ?></h1></div>
<?php require __DIR__ . '/_adminnav.php'; ?>
<form method="post" action="<?= e(url('admin', ['a' => 'sharesave'])) ?>" class="surface surface-pad mb-4">
  <?= Csrf::field() ?>
  <h2 class="h4"><?= e(t('shares.create_h')) ?></h2>
  <p class="text-muted small"><?= e(t('shares.create_intro')) ?></p>
  <div class="row g-3 align-items-end">
    <div class="col-md-5"><label class="form-label" for="s-label"><?= e(t('shares.label')) ?></label>
      <input class="form-control" id="s-label" name="label" required placeholder="<?= e(t('shares.label_ph')) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label" for="s-group"><?= e(t('shares.group')) ?></label>
      <select class="form-select" id="s-group" name="group_id" required><?php foreach ($groups as $g): ?><option value="<?= e($g['id']) ?>"><?= e($g['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label" for="s-exp"><?= e(t('shares.valid_until')) ?></label>
      <input class="form-control" type="date" id="s-exp" name="expires"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><?= e(t('shares.create')) ?></button></div>
  </div>
</form>

<?php if (!$shares): ?>
  <div class="empty surface"><i class="bi bi-link-45deg"></i><?= e(t('shares.none')) ?></div>
<?php endif; ?>
<?php foreach (array_reverse($shares) as $s):
    $expired = $s['expires'] && strtotime($s['expires'] . ' 23:59:59') < time();
    $state = !empty($s['revoked']) ? 'revoked' : ($expired ? 'expired' : 'active');
    $link = $base . $s['token'];
?>
  <div class="surface surface-pad mb-2<?= $state !== 'active' ? ' opacity-75' : '' ?>">
    <div class="d-flex flex-wrap justify-content-between gap-2">
      <div>
        <strong class="serif fs-5"><?= e($s['label']) ?></strong>
        <span class="badge <?= $state === 'active' ? 'text-bg-success' : 'text-bg-secondary' ?> ms-1"><?= e(t('shares.state_' . $state)) ?></span>
        <div class="small text-muted"><?= e(t('shares.group_line', ['group' => $gNames[$s['group_id']] ?? '?'])) ?> · <?= $s['expires'] ? e(t('shares.valid_to', ['date' => fmt_date($s['expires'])])) : e(t('shares.unlimited')) ?>
          · <?= e(tn('shares.visits', (int)($s['uses'] ?? 0))) ?><?= !empty($s['last_used']) ? e(t('shares.last_used', ['date' => fmt_date($s['last_used'], true)])) : '' ?></div>
      </div>
      <div class="d-flex gap-2 align-items-start">
        <button class="btn btn-sm btn-outline-primary" type="button" data-copy="<?= e($link) ?>"><i class="bi bi-clipboard me-1"></i><?= e(t('shares.copy')) ?></button>
        <form method="post" action="<?= e(url('admin', ['a' => 'sharerevoke'])) ?>"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= e($s['id']) ?>">
          <button class="btn btn-sm btn-outline-secondary"><?= e(t(!empty($s['revoked']) ? 'shares.unblock' : 'shares.block')) ?></button></form>
        <form method="post" action="<?= e(url('admin', ['a' => 'sharedelete'])) ?>" data-confirm="<?= e(t('shares.delete_confirm', ['label' => $s['label']])) ?>"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= e($s['id']) ?>">
          <button class="btn btn-sm btn-outline-danger" aria-label="<?= e(t('common.delete')) ?>"><i class="bi bi-trash"></i></button></form>
      </div>
    </div>
    <div class="share-url mt-2 text-muted"><?= e($link) ?></div>
  </div>
<?php endforeach; ?>
