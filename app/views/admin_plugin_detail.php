<?php
/** @var string $repo @var list<array<string,mixed>> $releases @var string $err @var string $installed @var string $installedVersion */
$pageTitle = t('plugins.title');
?>
<div class="page-head"><h1><?= e(t('admin.title')) ?></h1></div>
<?php require __DIR__ . '/_adminnav.php'; ?>

<div class="alert alert-warning small" role="note"><i class="bi bi-exclamation-triangle me-1"></i><?= e(t('market.warning')) ?></div>
<?php $ptab = 'search'; require __DIR__ . '/_plugins_tabs.php'; ?>

<p><a href="<?= e(url('admin', ['a' => 'pluginsearch'])) ?>"><i class="bi bi-arrow-left me-1"></i><?= e(t('market.back')) ?></a></p>
<h2 class="h4"><i class="bi bi-github me-1"></i><a href="https://github.com/<?= e($repo) ?>" target="_blank" rel="noopener noreferrer"><?= e($repo) ?></a></h2>

<?php if ($err !== ''): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>
<?php if ($err === '' && !$releases): ?>
  <div class="empty surface"><i class="bi bi-box-seam"></i><?= e(t('market.no_releases')) ?></div>
<?php endif; ?>
<?php foreach ($releases as $r):
    $same = $installed !== '' && $r['version'] === $installedVersion;
    $older = $installed !== '' && version_compare($r['version'], $installedVersion, '<');
?>
  <div class="surface surface-pad mb-2<?= !$r['compatible'] ? ' opacity-75' : '' ?>">
    <div class="d-flex flex-wrap justify-content-between gap-2">
      <div class="flex-grow-1" style="min-width:14rem">
        <strong class="serif fs-5"><?= e($r['manifest']['name'] ?? $r['slug']) ?></strong>
        <span class="text-muted small ms-1"><?= e($r['version']) ?></span>
        <?php if ($same): ?><span class="badge text-bg-success ms-1"><?= e(t('market.installed_badge')) ?></span><?php endif; ?>
        <?php if (!$r['compatible']): ?><span class="badge text-bg-danger ms-1"><?= e(t('market.incompatible')) ?></span><?php endif; ?>
        <?php if (!empty($r['manifest']['description'])): ?><div class="mt-1"><?= e($r['manifest']['description']) ?></div><?php endif; ?>
        <div class="small text-muted mt-1">
          <code><?= e($r['slug']) ?></code>
          <?php if ($r['published'] !== ''): ?> · <?= e(fmt_date($r['published'])) ?><?php endif; ?>
          <?php if ($r['size']): ?> · <?= e(fmt_bytes((int)$r['size'])) ?><?php endif; ?>
          <?php if (!empty($r['manifest']['license'])): ?> · <?= e($r['manifest']['license']) ?><?php endif; ?>
          <?php if (!empty($r['manifest']['permissions'])): ?> · <?= e(t('market.perms', ['perms' => implode(', ', $r['manifest']['permissions'])])) ?><?php endif; ?>
        </div>
        <?php if (!$r['compatible']): ?><div class="small text-danger mt-1"><?= e($r['reason']) ?></div><?php endif; ?>
        <?php if ($r['notes'] !== ''): ?>
          <details class="mt-2"><summary class="small"><?= e(t('market.notes')) ?></summary>
            <div class="small mt-1" style="white-space:pre-wrap"><?= e($r['notes']) ?></div></details>
        <?php endif; ?>
      </div>
      <div>
        <?php if ($r['compatible'] && !$same && !$older): ?>
          <form method="post" action="<?= e(url('admin', ['a' => 'pluginprepare'])) ?>" class="m-0">
            <?= Csrf::field() ?><input type="hidden" name="repo" value="<?= e($repo) ?>"><input type="hidden" name="version" value="<?= e($r['version']) ?>">
            <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-shield-check me-1"></i><?= e(t($installed !== '' ? 'market.review_update' : 'market.review_install')) ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
