<?php
/** @var string $id @var array $pend @var array $m @var ?array $src @var array $audit @var list<string> $newPerms @var list<string> $data */
$pageTitle = t('plugins.title');
$update = $pend['mode'] === 'update';
?>
<div class="page-head"><h1><?= e(t('admin.title')) ?></h1></div>
<?php require __DIR__ . '/_adminnav.php'; ?>

<div class="surface surface-pad">
  <h2 class="h4"><?= e(t($update ? 'market.confirm_update_h' : 'market.confirm_install_h', ['name' => $m['name']])) ?></h2>
  <div class="alert alert-danger small" role="alert"><i class="bi bi-exclamation-octagon me-1"></i><?= e(t('market.no_sandbox')) ?></div>

  <dl class="row mb-3">
    <dt class="col-sm-3"><?= e(t('market.f_plugin')) ?></dt>
    <dd class="col-sm-9"><strong><?= e($m['name']) ?></strong> <code><?= e($m['slug']) ?></code> <?= $update ? e($src['version'] . ' → ' . $m['version']) : e($m['version']) ?>
      <?php if ($m['description'] !== ''): ?><div><?= e($m['description']) ?></div><?php endif; ?></dd>
    <dt class="col-sm-3"><?= e(t('market.f_source')) ?></dt>
    <dd class="col-sm-9"><i class="bi bi-github me-1"></i><a href="https://github.com/<?= e($pend['repo']) ?>" target="_blank" rel="noopener noreferrer"><?= e($pend['repo']) ?></a>
      <?php if ($m['author'] !== ''): ?> · <?= e(t('plugins.by', ['author' => $m['author']])) ?><?php endif; ?>
      <?php if ($m['license'] !== ''): ?> · <?= e($m['license']) ?><?php endif; ?></dd>
    <dt class="col-sm-3"><?= e(t('market.f_perms')) ?></dt>
    <dd class="col-sm-9">
      <?php if ($m['permissions']): ?>
        <?php foreach ($m['permissions'] as $perm): ?><span class="badge <?= in_array($perm, $newPerms, true) ? 'text-bg-warning' : 'text-bg-light border' ?> me-1"><?= e($perm) ?></span><?php endforeach; ?>
        <?php if ($newPerms): ?><div class="small text-warning-emphasis mt-1"><?= e(t('market.new_perms', ['perms' => implode(', ', $newPerms)])) ?></div><?php endif; ?>
        <div class="small text-muted mt-1"><?= e(t('market.perms_note')) ?></div>
      <?php else: ?><span class="text-muted"><?= e(t('market.perms_none')) ?></span><?php endif; ?>
    </dd>
    <?php if ($m['requires']): ?>
      <dt class="col-sm-3"><?= e(t('market.f_requires')) ?></dt><dd class="col-sm-9"><?= e(implode(', ', $m['requires'])) ?></dd>
    <?php endif; ?>
    <?php if ($data): ?>
      <dt class="col-sm-3"><?= e(t('market.f_data')) ?></dt><dd class="col-sm-9"><?= e(implode(', ', array_map(fn($n) => $n . '.json', $data))) ?></dd>
    <?php endif; ?>
    <dt class="col-sm-3"><?= e(t('market.f_audit')) ?></dt>
    <dd class="col-sm-9">
      <?php if ($audit['status'] === 'match'): ?><span class="badge text-bg-success"><?= e(t('market.audit_match')) ?></span> <code><?= e(substr($audit['audited'], 0, 12)) ?></code>
      <?php elseif ($audit['status'] === 'different'): ?><span class="badge text-bg-warning"><?= e(t('market.audit_different')) ?></span> <code><?= e(substr($audit['audited'], 0, 12)) ?></code> ≠ <code><?= e(substr($audit['tag_commit'], 0, 12)) ?></code>
      <?php elseif ($audit['status'] === 'unknown'): ?><span class="badge text-bg-secondary"><?= e(t('market.audit_unknown')) ?></span>
      <?php else: ?><span class="badge text-bg-secondary"><?= e(t('market.audit_none')) ?></span><?php endif; ?>
      <div class="small text-muted mt-1"><?= e(t('market.audit_note')) ?></div>
    </dd>
    <dt class="col-sm-3"><?= e(t('market.f_hash')) ?></dt>
    <dd class="col-sm-9"><i class="bi bi-check-circle text-success me-1"></i><?= e(t('market.hash_ok')) ?> <code class="small"><?= e(substr($pend['sha'], 0, 16)) ?>…</code></dd>
  </dl>

  <div class="d-flex flex-wrap gap-2">
    <form method="post" action="<?= e(url('admin', ['a' => 'pluginapply'])) ?>" class="m-0">
      <?= Csrf::field() ?><input type="hidden" name="id" value="<?= e($id) ?>">
      <button class="btn btn-primary" type="submit"><i class="bi bi-cloud-arrow-down me-1"></i><?= e(t($update ? 'market.apply_update' : 'market.apply_install')) ?></button>
    </form>
    <form method="post" action="<?= e(url('admin', ['a' => 'plugincancel'])) ?>" class="m-0">
      <?= Csrf::field() ?><input type="hidden" name="id" value="<?= e($id) ?>">
      <button class="btn btn-outline-secondary" type="submit"><?= e(t('market.cancel')) ?></button>
    </form>
  </div>
  <p class="small text-muted mt-3 mb-0"><?= e(t($update ? 'market.after_update' : 'market.after_install')) ?></p>
</div>
