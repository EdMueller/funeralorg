<?php
/** @var array $rows @var int $total @var array $stats @var int $days @var array $filter @var int $page @var int $pages */
$pageTitle = t('api.log_h');
$when = fn(int $ts) => $ts ? fmt_date(date('c', $ts), true) : '–';
$q = fn(array $over = []) => url('apilog', array_filter(array_merge(['days' => $days, 'token' => $filter['token'], 'proto' => $filter['proto'], 'only' => $filter['only']], $over), fn($v) => $v !== '' && $v !== null));
?>
<div class="page-head">
  <div><h1><?= e(t('api.log_h')) ?></h1><p class="lead-note"><?= e(t('api.log_lead')) ?></p></div>
  <a class="btn btn-sm btn-outline-primary align-self-start" href="<?= e(url('apitokens')) ?>"><i class="bi bi-key me-1"></i><?= e(t('api.title')) ?></a>
</div>

<section class="surface surface-pad mb-4">
  <h2 class="h5"><?= e(t('api.log_stats')) ?></h2>
  <?php if (!$stats): ?><p class="text-muted mb-0"><?= e(t('api.log_none')) ?></p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th><?= e(t('api.f_label')) ?></th><th><?= e(t('api.f_user')) ?></th><th class="text-end"><?= e(t('api.log_calls')) ?></th><th class="text-end"><?= e(t('api.log_writes')) ?></th><th class="text-end"><?= e(t('api.log_errors')) ?></th><th><?= e(t('api.last_used')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($stats as $tid => $s): ?>
      <tr><td><?= $tid !== '-' ? '<a href="' . e($q(['token' => $tid, 'page' => null])) . '">' . e($s['label']) . '</a>' : e(t('api.log_no_token')) ?></td><td><?= e($s['user']) ?></td>
        <td class="text-end"><?= (int)$s['calls'] ?></td><td class="text-end"><?= (int)$s['writes'] ?></td><td class="text-end"><?= (int)$s['errors'] ?></td><td><?= e($when((int)$s['last'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<form method="get" action="index.php" class="surface surface-pad mb-4 row g-2 align-items-end">
  <input type="hidden" name="r" value="apilog">
  <div class="col-sm-6 col-md-3"><label class="form-label" for="al-days"><?= e(t('api.log_period')) ?></label>
    <select class="form-select" id="al-days" name="days">
      <?php foreach ([1, 7, 30, 90, 365] as $d): ?><option value="<?= $d ?>"<?= $d === $days ? ' selected' : '' ?>><?= e(tn('api.log_days', $d)) ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-sm-6 col-md-3"><label class="form-label" for="al-token"><?= e(t('api.f_label')) ?></label>
    <select class="form-select" id="al-token" name="token">
      <option value=""><?= e(t('api.log_all_tokens')) ?></option>
      <?php foreach ($stats as $tid => $s): if ($tid === '-') { continue; } ?><option value="<?= e($tid) ?>"<?= $tid === $filter['token'] ? ' selected' : '' ?>><?= e($s['label'] . ' (' . $s['user'] . ')') ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-sm-6 col-md-2"><label class="form-label" for="al-proto"><?= e(t('api.log_proto')) ?></label>
    <select class="form-select" id="al-proto" name="proto">
      <option value=""><?= e(t('api.log_all')) ?></option>
      <option value="rest"<?= $filter['proto'] === 'rest' ? ' selected' : '' ?>>REST</option>
      <option value="mcp"<?= $filter['proto'] === 'mcp' ? ' selected' : '' ?>>MCP</option>
    </select></div>
  <div class="col-sm-6 col-md-2"><label class="form-label" for="al-only"><?= e(t('api.log_show')) ?></label>
    <select class="form-select" id="al-only" name="only">
      <option value=""><?= e(t('api.log_all')) ?></option>
      <option value="write"<?= $filter['only'] === 'write' ? ' selected' : '' ?>><?= e(t('api.log_only_writes')) ?></option>
      <option value="error"<?= $filter['only'] === 'error' ? ' selected' : '' ?>><?= e(t('api.log_only_errors')) ?></option>
    </select></div>
  <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><?= e(t('api.log_apply')) ?></button></div>
</form>

<section class="surface surface-pad">
  <h2 class="h5"><?= e(tn('api.log_entries', $total)) ?></h2>
  <?php if (!$rows): ?><p class="text-muted mb-0"><?= e(t('api.log_none')) ?></p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th><?= e(t('api.log_time')) ?></th><th><?= e(t('api.log_proto')) ?></th><th><?= e(t('api.f_label')) ?></th><th><?= e(t('api.f_user')) ?></th><th><?= e(t('api.log_op')) ?></th><th><?= e(t('api.log_args')) ?></th><th><?= e(t('api.log_status')) ?></th><th><?= e(t('api.log_ip')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="text-nowrap"><?= e($when((int)$r['t'])) ?></td>
        <td><span class="badge text-bg-light border"><?= e(strtoupper((string)$r['proto'])) ?></span></td>
        <td><?= e($r['label'] ?: '–') ?></td>
        <td><?= e($r['user'] ?: '–') ?></td>
        <td><code><?= e($r['op'] ?: '–') ?></code><?= !empty($r['write']) ? ' <span class="badge text-bg-warning">' . e(t('api.scope_short_write')) . '</span>' : '' ?>
          <?php if (($r['path'] ?? '') !== ''): ?><small class="d-block text-muted"><?= e(($r['method'] ?? '') . ' ' . $r['path']) ?></small><?php endif; ?></td>
        <td class="small"><?= e(implode(', ', $r['args'] ?? [])) ?></td>
        <td><span class="badge text-bg-<?= !empty($r['ok']) ? 'success' : 'danger' ?>"><?= (int)($r['status'] ?? 0) ?></span></td>
        <td class="small text-nowrap" title="<?= e($r['ua'] ?? '') ?>"><?= e($r['ip'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?>
  <nav class="d-flex gap-2 align-items-center" aria-label="<?= e(t('api.log_pages')) ?>">
    <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-primary" href="<?= e($q(['page' => $page - 1])) ?>"><?= e(t('api.log_newer')) ?></a><?php endif; ?>
    <span class="small text-muted"><?= e(t('api.log_page', ['page' => $page, 'pages' => $pages])) ?></span>
    <?php if ($page < $pages): ?><a class="btn btn-sm btn-outline-primary" href="<?= e($q(['page' => $page + 1])) ?>"><?= e(t('api.log_older')) ?></a><?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
  <p class="small text-muted mt-3 mb-0"><?= e(t('api.log_privacy')) ?></p>
</section>
