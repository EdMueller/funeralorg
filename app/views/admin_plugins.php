<?php
/** @var array $plugins @var int|float $maxMb @var array $updates @var string $checked @var ?array $rate @var ?array $exposure */
$pageTitle = t('plugins.title');
?>
<div class="page-head"><h1><?= e(t('admin.title')) ?></h1></div>
<?php require __DIR__ . '/_adminnav.php'; ?>

<div class="alert alert-warning small" role="note"><i class="bi bi-exclamation-triangle me-1"></i><?= e(t('plugins.warning')) ?></div>

<?php $ptab = 'installed'; require __DIR__ . '/_plugins_tabs.php'; ?>

<?php if ($exposure && $exposure['status'] === 'exposed'): ?>
  <div class="alert alert-danger" role="alert"><i class="bi bi-shield-exclamation me-1"></i><strong><?= e(t('market.exposed_h')) ?></strong>
    <?= e(t('market.exposed')) ?>
    <ul class="mb-0 mt-1 small"><?php foreach ($exposure['urls'] as $u): ?><li><code><?= e($u) ?></code></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="surface surface-pad mb-3">
  <div class="d-flex flex-wrap align-items-center gap-2">
    <form method="post" action="<?= e(url('admin', ['a' => 'pluginscheck'])) ?>" class="m-0">
      <?= Csrf::field() ?>
      <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-arrow-repeat me-1"></i><?= e(t('market.check')) ?></button>
    </form>
    <?php if ($updates): ?>
      <form method="post" action="<?= e(url('admin', ['a' => 'pluginsupdateall'])) ?>" class="m-0"
            data-confirm="<?= e(t('market.update_all_confirm', ['list' => implode(', ', array_map(fn($s, $u) => ($plugins[$s]['m']['name'] ?? $s) . ' ' . ($plugins[$s]['m']['version'] ?? '') . ' → ' . $u['version'], array_keys($updates), $updates))])) ?>">
        <?= Csrf::field() ?>
        <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-cloud-arrow-down me-1"></i><?= e(tn('market.update_all', count($updates))) ?></button>
      </form>
    <?php endif; ?>
    <span class="small text-muted ms-md-2">
      <?= e($checked !== '' ? t('market.checked', ['when' => fmt_date($checked, true)]) : t('market.never_checked')) ?>
      <?php if ($rate): ?> · <?= e(t('market.rate', ['n' => (string)$rate['remaining'], 'max' => (string)$rate['limit']])) ?><?php endif; ?>
    </span>
  </div>
  <div class="d-flex flex-wrap align-items-center gap-2 mt-2 pt-2 border-top">
    <form method="post" action="<?= e(url('admin', ['a' => 'pluginselftest'])) ?>" class="m-0">
      <?= Csrf::field() ?>
      <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-shield-check me-1"></i><?= e(t('market.selftest')) ?></button>
    </form>
    <span class="small text-muted">
      <?php if (!$exposure): ?><?= e(t('market.selftest_never')) ?>
      <?php else: ?><?= e(t('market.selftest_last', ['when' => fmt_ts((int)$exposure['time'], 'dt'), 'result' => t('market.selftest_r_' . $exposure['status'])])) ?><?php endif; ?>
    </span>
  </div>
</div>

<form method="post" action="<?= e(url('admin', ['a' => 'pluginimport'])) ?>" enctype="multipart/form-data" class="surface surface-pad mb-4">
  <?= Csrf::field() ?>
  <h2 class="h4"><?= e(t('plugins.import_h')) ?></h2>
  <p class="text-muted small"><?= e(t('plugins.import_intro', ['mb' => (string)$maxMb])) ?></p>
  <div class="row g-3 align-items-end">
    <div class="col-md-9"><label class="form-label" for="pl-zip"><?= e(t('plugins.import_file')) ?></label>
      <input class="form-control" type="file" id="pl-zip" name="plugin_zip" accept=".zip,application/zip" required></div>
    <div class="col-md-3"><button class="btn btn-primary w-100" type="submit"><?= e(t('plugins.import')) ?></button></div>
  </div>
</form>

<?php if (!$plugins): ?>
  <div class="empty surface"><i class="bi bi-puzzle"></i><?= e(t('plugins.none')) ?></div>
<?php endif; ?>
<?php foreach ($plugins as $slug => $p):
    $m = $p['m'];
?>
  <div class="surface surface-pad mb-2<?= !$p['active'] ? ' opacity-75' : '' ?>">
    <div class="d-flex flex-wrap justify-content-between gap-2">
      <div class="flex-grow-1" style="min-width:14rem">
        <strong class="serif fs-5"><?= e($m['name']) ?></strong>
        <?php if ($m['version'] !== ''): ?><span class="text-muted small ms-1"><?= e($m['version']) ?></span><?php endif; ?>
        <?php if (!$p['ok']): ?>
          <span class="badge text-bg-danger ms-1"><?= e(t('plugins.state_broken')) ?></span>
        <?php elseif ($p['active'] && !$p['loaded']): ?>
          <span class="badge text-bg-danger ms-1"><?= e(t('plugins.state_error')) ?></span>
        <?php else: ?>
          <span class="badge <?= $p['active'] ? 'text-bg-success' : 'text-bg-secondary' ?> ms-1"><?= e(t($p['active'] ? 'plugins.state_active' : 'plugins.state_inactive')) ?></span>
        <?php endif; ?>
        <?php if ($m['core']): ?><span class="badge text-bg-light border ms-1"><?= e(t('plugins.core')) ?></span><?php endif; ?>
        <?php if ($m['description'] !== ''): ?><div class="mt-1"><?= e($m['description']) ?></div><?php endif; ?>
        <div class="small text-muted mt-1">
          <code><?= e($slug) ?></code>
          <?php if ($m['author'] !== ''): ?> · <?= e(t('plugins.by', ['author' => $m['author']])) ?><?php endif; ?>
          <?php if ($m['requires']): ?> · <?= e(t('plugins.requires', ['names' => implode(', ', $m['requires'])])) ?><?php endif; ?>
          <?php if ($p['data']): ?> · <?= e(t('plugins.data', ['files' => implode(', ', array_map(fn($n, $sz) => $n . '.json (' . fmt_bytes($sz) . ')', array_keys($p['data']), $p['data']))])) ?><?php endif; ?>
        </div>
        <?php if ($p['source']): ?>
          <div class="small text-muted mt-1"><i class="bi bi-github me-1"></i><a href="https://github.com/<?= e($p['source']['repo']) ?>" target="_blank" rel="noopener noreferrer"><?= e($p['source']['repo']) ?></a>
            <?php if ($p['archived']): ?> · <?= e(t('market.archived')) ?><?php endif; ?></div>
        <?php endif; ?>
        <?php if ($p['update']): ?>
          <div class="small mt-1"><span class="badge text-bg-info"><?= e(t('market.update_available', ['version' => $p['update']['version']])) ?></span>
            <?php if ($p['update']['confirm']): ?><span class="text-warning-emphasis"><?= e(t('market.new_perms', ['perms' => implode(', ', $p['update']['confirm'])])) ?></span><?php endif; ?></div>
        <?php endif; ?>
        <?php if (!$p['ok']): ?><div class="small text-danger mt-1"><?= e($p['error']) ?></div><?php endif; ?>
        <?php if ($p['problem'] !== ''): ?><div class="small text-danger mt-1"><?= e($p['problem']) ?></div><?php endif; ?>
      </div>
      <div class="d-flex flex-wrap gap-2 align-items-start">
        <?php if ($p['ok']): ?>
          <form method="post" action="<?= e(url('admin', ['a' => 'pluginset'])) ?>" class="m-0">
            <?= Csrf::field() ?><input type="hidden" name="slug" value="<?= e($slug) ?>"><input type="hidden" name="state" value="<?= $p['active'] ? 'off' : 'on' ?>">
            <?php if ($p['active']): ?>
              <button class="btn btn-sm btn-outline-secondary" type="submit"<?= $p['dependents'] ? ' disabled title="' . e(t('plugins.has_dependents')) . '"' : '' ?>><i class="bi bi-toggle-off me-1"></i><?= e(t('plugins.deactivate')) ?></button>
            <?php else: ?>
              <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-toggle-on me-1"></i><?= e(t('plugins.activate')) ?></button>
            <?php endif; ?>
          </form>
        <?php endif; ?>
        <?php if ($p['update']): ?>
          <form method="post" action="<?= e(url('admin', ['a' => 'pluginupdate'])) ?>" class="m-0">
            <?= Csrf::field() ?><input type="hidden" name="slug" value="<?= e($slug) ?>">
            <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-cloud-arrow-down me-1"></i><?= e(t($p['update']['confirm'] ? 'market.review_update' : 'market.update')) ?></button>
          </form>
        <?php endif; ?>
        <?php if ($p['can_rollback']): ?>
          <form method="post" action="<?= e(url('admin', ['a' => 'pluginrollback'])) ?>" class="m-0"
                data-confirm="<?= e(t('market.rollback_confirm', ['name' => $m['name'], 'version' => (string)$p['source']['previous']])) ?>">
            <?= Csrf::field() ?><input type="hidden" name="slug" value="<?= e($slug) ?>">
            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-arrow-counterclockwise me-1"></i><?= e(t('market.rollback', ['version' => (string)$p['source']['previous']])) ?></button>
          </form>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin', ['a' => 'pluginexport', 'p' => $slug])) ?>"><i class="bi bi-download me-1"></i><?= e(t('plugins.export')) ?></a>
      </div>
    </div>
    <?php if (!$m['core'] && !$p['active']): ?>
      <form method="post" action="<?= e(url('admin', ['a' => 'plugindelete'])) ?>" class="d-flex flex-wrap align-items-center gap-3 mt-2 pt-2 border-top"
            data-confirm="<?= e(t('plugins.delete_confirm', ['name' => $m['name']])) ?>">
        <?= Csrf::field() ?><input type="hidden" name="slug" value="<?= e($slug) ?>">
        <?php if ($p['data']): ?>
          <label class="form-check m-0 small"><input class="form-check-input" type="checkbox" name="delete_data" value="1"> <?= e(t('plugins.delete_data')) ?></label>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash me-1"></i><?= e(t('plugins.delete')) ?></button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
