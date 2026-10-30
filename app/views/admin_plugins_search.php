<?php
/** @var string $q @var list<array<string,mixed>> $results @var string $err @var ?array $rate @var string $tokenSource */
$pageTitle = t('plugins.title');
?>
<div class="page-head"><h1><?= e(t('admin.title')) ?></h1></div>
<?php require __DIR__ . '/_adminnav.php'; ?>

<div class="alert alert-warning small" role="note"><i class="bi bi-exclamation-triangle me-1"></i><?= e(t('market.warning')) ?></div>
<?php $ptab = 'search'; require __DIR__ . '/_plugins_tabs.php'; ?>

<form method="get" action="<?= e(url('admin')) ?>" class="surface surface-pad mb-3">
  <input type="hidden" name="r" value="admin"><input type="hidden" name="a" value="pluginsearch">
  <label class="form-label" for="pl-q"><?= e(t('market.search')) ?></label>
  <div class="input-group">
    <input class="form-control" type="search" id="pl-q" name="q" value="<?= e($q) ?>" maxlength="60" placeholder="<?= e(t('market.search_ph')) ?>">
    <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i><?= e(t('market.search_btn')) ?></button>
  </div>
  <div class="form-text"><?= e(t('market.search_hint')) ?></div>
</form>

<?php if ($err !== ''): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>
<?php if ($err === '' && !$results): ?>
  <div class="empty surface"><i class="bi bi-search"></i><?= e(t('market.none_found')) ?></div>
<?php endif; ?>
<?php foreach ($results as $r): ?>
  <div class="surface surface-pad mb-2">
    <div class="d-flex flex-wrap justify-content-between gap-2">
      <div class="flex-grow-1" style="min-width:14rem">
        <strong class="serif fs-5"><?= e($r['name']) ?></strong>
        <?php if ($r['installed'] !== ''): ?><span class="badge text-bg-success ms-1"><?= e(t('market.installed_badge')) ?></span><?php endif; ?>
        <?php if ($r['archived']): ?><span class="badge text-bg-warning ms-1"><?= e(t('market.repo_archived')) ?></span><?php endif; ?>
        <?php if ($r['description'] !== ''): ?><div class="mt-1"><?= e($r['description']) ?></div><?php endif; ?>
        <div class="small text-muted mt-1">
          <i class="bi bi-github me-1"></i><a href="https://github.com/<?= e($r['repo']) ?>" target="_blank" rel="noopener noreferrer"><?= e($r['repo']) ?></a>
          · <i class="bi bi-star me-1"></i><?= (int)$r['stars'] ?>
          <?php if ($r['pushed'] !== ''): ?> · <?= e(t('market.active_on', ['date' => fmt_date($r['pushed'])])) ?><?php endif; ?>
        </div>
      </div>
      <div><a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin', ['a' => 'plugindetail', 'repo' => $r['repo']])) ?>"><i class="bi bi-box-seam me-1"></i><?= e(t('market.releases')) ?></a></div>
    </div>
  </div>
<?php endforeach; ?>

<div class="surface surface-pad mt-4">
  <h2 class="h5"><?= e(t('market.token_h')) ?></h2>
  <p class="small text-muted mb-2"><?= e(t('market.token_intro')) ?>
    <?php if ($rate): ?> <?= e(t('market.rate', ['n' => (string)$rate['remaining'], 'max' => (string)$rate['limit']])) ?><?php endif; ?></p>
  <?php if ($tokenSource === 'config'): ?>
    <p class="small mb-0"><i class="bi bi-check-circle text-success me-1"></i><?= e(t('market.token_config')) ?></p>
  <?php else: ?>
    <form method="post" action="<?= e(url('admin', ['a' => 'pluginmarkettoken'])) ?>" class="row g-2 align-items-end">
      <?= Csrf::field() ?>
      <div class="col-md-7"><label class="form-label small" for="pl-token"><?= e(t('market.token')) ?><?= $tokenSource === 'store' ? ' – ' . e(t('market.token_set')) : '' ?></label>
        <input class="form-control" type="password" id="pl-token" name="token" autocomplete="off" maxlength="255" placeholder="<?= e($tokenSource === 'store' ? '••••••••••••' : 'ghp_…') ?>"></div>
      <div class="col-auto"><button class="btn btn-outline-primary" type="submit"><?= e(t('market.token_save')) ?></button></div>
      <?php if ($tokenSource === 'store'): ?><div class="col-auto"><button class="btn btn-outline-danger" type="submit" name="remove" value="1"><?= e(t('market.token_remove')) ?></button></div><?php endif; ?>
    </form>
  <?php endif; ?>
</div>
