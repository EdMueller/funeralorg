<?php $pageTitle = t('admin.tab.log'); $qs = array_filter(['days' => $days, 'act' => $act, 'usr' => $usr, 'q' => $q]); $actions = Log::actions(); ?>
<div class="page-head"><h1><?= e(t('admin.title')) ?></h1>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin', ['a' => 'log', 'fmt' => 'xlsx'] + $qs)) ?>"><?= e(t('logv.export_excel')) ?></a>
    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin', ['a' => 'log', 'fmt' => 'csv'] + $qs)) ?>"><?= e(t('logv.export_csv')) ?></a>
  </div></div>
<?php require __DIR__ . '/_adminnav.php'; ?>
<form class="toolbar" method="get" action="index.php">
  <input type="hidden" name="r" value="admin"><input type="hidden" name="a" value="log">
  <select class="form-select form-select-sm" name="days" data-autosubmit aria-label="<?= e(t('logv.period')) ?>">
    <?php foreach ([1 => t('dash.today'), 7 => t('dash.days7'), 30 => t('dash.days30'), 90 => t('dash.days90'), 365 => t('dash.days365')] as $d => $lbl): ?><option value="<?= $d ?>"<?= $days === $d ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
  </select>
  <select class="form-select form-select-sm" name="act" data-autosubmit aria-label="<?= e(t('logv.action')) ?>">
    <option value=""><?= e(t('logv.all_actions')) ?></option>
    <?php foreach ($actions as $k => $lbl): ?><option value="<?= e($k) ?>"<?= $act === $k ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
  </select>
  <select class="form-select form-select-sm" name="usr" data-autosubmit aria-label="<?= e(t('logv.user')) ?>">
    <option value=""><?= e(t('logv.all_users')) ?></option>
    <?php foreach ($users as $un): ?><option<?= $usr === $un ? ' selected' : '' ?>><?= e($un) ?></option><?php endforeach; ?>
  </select>
  <input class="form-control form-control-sm" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('logv.search_ph')) ?>">
  <button class="btn btn-sm btn-outline-primary"><?= e(t('logv.filter')) ?></button>
</form>
<p class="small text-muted"><?= e(tn('logv.count', $total)) ?><?= $total > 500 ? e(t('logv.truncated')) : '' ?>.</p>
<div class="surface table-wrap">
  <table class="table table-sm mb-0" data-sortable>
    <thead><tr><th class="ps-3"><?= e(t('logv.col_time')) ?></th><th><?= e(t('logv.col_action')) ?></th><th><?= e(t('logv.col_user')) ?></th><th><?= e(t('logv.col_details')) ?></th><th><?= e(t('logv.col_ip')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="ps-3 text-nowrap small" data-v="<?= (int)$r['t'] ?>"><?= e(fmt_ts((int)$r['t'], 'dts')) ?></td>
        <td class="text-nowrap<?= in_array($r['a'], ['login_fail', 'login_blocked'], true) ? ' text-danger' : '' ?>"><?= e($actions[$r['a']] ?? $r['a']) ?></td>
        <td class="text-nowrap"><?= e($r['n'] ?: $r['u']) ?><?= !empty($r['v']) ? ' <span class="badge text-bg-light">' . e(t('logv.via', ['user' => $r['v']])) . '</span>' : '' ?><?= $r['k'] === 'share' ? ' <span class="badge text-bg-light">' . e(t('logv.kind_link')) . '</span>' : '' ?></td>
        <td class="small"><?php $d = log_details($r); echo e(in_array($r['a'], ['img_view', 'img_download', 'upload'], true) ? $d : trim($r['o'] . ($d ? ' – ' . $d : ''))); ?></td>
        <td class="small text-muted text-nowrap"><?= e($r['ip']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4"><?= e(t('logv.empty')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
