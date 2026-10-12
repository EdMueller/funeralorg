<?php
$pageTitle = t('admin.title');
$withChart = true;
$t = $stats['totals'];
$chart = [
    'labels'    => array_map(fn($d) => fmt_ts(strtotime($d), 'dm'), array_keys($stats['per_day'])),
    'logins'    => array_column(array_values($stats['per_day']), 'logins'),
    'views'     => array_column(array_values($stats['per_day']), 'views'),
    'downloads' => array_column(array_values($stats['per_day']), 'downloads'),
    'uploads'   => array_column(array_values($stats['per_day']), 'uploads'),
    'fails'     => array_column(array_values($stats['per_day']), 'fails'),
    'names'     => ['logins' => t('dash.chart_logins'), 'views' => t('dash.chart_views'), 'downloads' => t('dash.chart_downloads'),
                    'uploads' => t('dash.chart_uploads'), 'fails' => t('dash.chart_fails')],
];
$fails = ($t['login_fail'] ?? 0) + ($t['login_blocked'] ?? 0);
?>
<div class="page-head">
  <h1><?= e(t('admin.title')) ?></h1>
  <div class="btn-group" role="group" aria-label="<?= e(t('dash.period')) ?>">
    <?php foreach ([7, 30, 90, 365] as $d): $lbl = t('dash.days' . $d); ?>
      <a class="btn btn-sm btn-outline-primary<?= $days === $d ? ' active' : '' ?>" href="<?= e(url('admin', ['days' => $d])) ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php require __DIR__ . '/_adminnav.php'; ?>

<div class="kpis">
  <div><strong><?= $stats['today_logins'] ?></strong><small><?= e(t('dash.kpi_logins_today')) ?></small></div>
  <div><strong><?= $t['login_ok'] ?? 0 ?></strong><small><?= e(t('dash.kpi_logins')) ?></small></div>
  <div><strong><?= $t['share_open'] ?? 0 ?></strong><small><?= e(t('dash.kpi_links')) ?></small></div>
  <div><strong><?= $t['img_view'] ?? 0 ?></strong><small><?= e(t('dash.kpi_views')) ?></small></div>
  <div><strong><?= ($t['img_download'] ?? 0) + ($t['zip_download'] ?? 0) ?></strong><small><?= e(t('dash.kpi_downloads', ['zip' => $t['zip_download'] ?? 0])) ?></small></div>
  <div><strong><?= $t['upload'] ?? 0 ?></strong><small><?= e(t('dash.kpi_uploads')) ?></small></div>
  <div class="<?= $fails ? 'warn' : '' ?>"><strong><?= $fails ?></strong><small><?= e(t('dash.kpi_fails')) ?></small></div>
</div>

<?php if ($stats['fail_ips']): ?>
  <div class="alert alert-warning">
    <strong><?= e(t('dash.suspicious')) ?></strong>
    <?php foreach ($stats['fail_ips'] as $ip => $n): ?><span class="me-3"><?= e($ip ?: '?') ?> (<?= $n ?>×)</span><?php endforeach; ?>
    <a href="<?= e(url('admin', ['a' => 'log', 'act' => 'login_fail', 'days' => $days])) ?>"><?= e(t('dash.details')) ?></a>
  </div>
<?php endif; ?>

<section class="surface surface-pad mb-4">
  <h2 class="h4"><?= e(t('dash.activity')) ?></h2>
  <div class="chart-box"><canvas id="activityChart" aria-label="<?= e(t('dash.activity')) ?>" role="img"></canvas></div>
  <script type="application/json" id="chartData"><?= json_encode($chart, JSON_HEX_TAG) ?></script>
</section>

<div class="row g-4">
  <div class="col-lg-7">
    <section class="surface surface-pad h-100">
      <h2 class="h4"><?= e(t('dash.users_links')) ?></h2>
      <?php if (!$stats['users']): ?><p class="text-muted mb-0"><?= e(t('dash.no_activity')) ?></p><?php else: ?>
      <div class="table-wrap"><table class="table table-sm mb-0">
        <thead><tr><th><?= e(t('dash.col_name')) ?></th><th class="text-end"><?= e(t('dash.col_logins')) ?></th><th class="text-end"><?= e(t('dash.col_views')) ?></th><th class="text-end"><?= e(t('dash.col_downloads')) ?></th><th class="text-end"><?= e(t('dash.col_uploads')) ?></th><th class="text-end"><?= e(t('dash.col_changes')) ?></th><th><?= e(t('dash.col_last')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($stats['users'] as $un => $u): ?>
          <tr>
            <td><a href="<?= e(url('admin', ['a' => 'log', 'usr' => $un, 'days' => $days])) ?>"><?= e($u['name'] ?: $un) ?></a><?= $u['kind'] === 'share' ? ' <span class="badge text-bg-light">' . e(t('dash.link_badge')) . '</span>' : '' ?></td>
            <td class="text-end"><?= $u['logins'] ?></td><td class="text-end"><?= $u['views'] ?></td><td class="text-end"><?= $u['downloads'] ?></td>
            <td class="text-end"><?= $u['uploads'] ?></td><td class="text-end"><?= $u['changes'] ?></td>
            <td class="text-nowrap small"><?= e(fmt_ts((int)$u['last'], 'dmt')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </section>
  </div>
  <div class="col-lg-5">
    <section class="surface surface-pad mb-4">
      <h2 class="h4"><?= e(t('dash.top_viewed')) ?></h2>
      <?php if (!$stats['top_views']): ?><p class="text-muted mb-0"><?= e(t('dash.no_views')) ?></p><?php endif; ?>
      <?php foreach ($stats['top_views'] as $id => $n): $img = $images[$id] ?? null; ?>
        <div class="top-img">
          <?php if ($img): ?><img src="<?= e(url('img', ['id' => $id, 's' => 'thumb'])) ?>" alt=""><?php endif; ?>
          <span><?= e($img ? ($img['caption'] ?: $img['orig_name']) : t('img.deleted')) ?></span><strong><?= $n ?>×</strong>
        </div>
      <?php endforeach; ?>
    </section>
    <section class="surface surface-pad">
      <h2 class="h4"><?= e(t('dash.top_downloaded')) ?></h2>
      <?php if (!$stats['top_downloads']): ?><p class="text-muted mb-0"><?= e(t('dash.no_downloads')) ?></p><?php endif; ?>
      <?php foreach ($stats['top_downloads'] as $id => $n): $img = $images[$id] ?? null; ?>
        <div class="top-img">
          <?php if ($img): ?><img src="<?= e(url('img', ['id' => $id, 's' => 'thumb'])) ?>" alt=""><?php endif; ?>
          <span><?= e($img ? ($img['caption'] ?: $img['orig_name']) : t('img.deleted')) ?></span><strong><?= $n ?>×</strong>
        </div>
      <?php endforeach; ?>
    </section>
  </div>
</div>

<section class="surface surface-pad mt-4">
  <h2 class="h4"><?= e(t('dash.last_login_per_user')) ?></h2>
  <div class="table-wrap"><table class="table table-sm mb-0">
    <thead><tr><th><?= e(t('dash.col_user')) ?></th><th><?= e(t('dash.col_role')) ?></th><th><?= e(t('dash.col_last_login')) ?></th></tr></thead>
    <tbody><?php foreach ($users as $u): ?>
      <tr><td><?= e($u['name'] ?: $u['username']) ?> <span class="text-muted small"><?= e($u['username']) ?></span></td>
          <td><?= e($u['role'] === 'admin' ? t('dash.role_admin') : t('dash.role_user')) ?><?= empty($u['active']) ? ' ' . e(t('dash.deactivated_paren')) : '' ?></td>
          <td><?= $u['last_login'] ? e(fmt_date($u['last_login'], true)) : '<span class="text-muted">' . e(t('common.never')) . '</span>' ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
</section>
<?php if (app_version() !== ''): ?>
<p class="small text-muted mt-3 mb-0"><?= e(t('dash.version', ['v' => app_version()])) ?></p>
<?php endif; ?>
