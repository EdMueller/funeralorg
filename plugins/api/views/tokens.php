<?php
/** @var array $tokens @var array $users @var bool $isAdmin @var string $new @var bool $allow @var string $apiUrl @var string $mcpUrl */
$pageTitle = t('api.title');
$names = array_column($users, 'username', 'id');
$tok = $new !== '' ? $new : '<TOKEN>';
$curl = 'curl -H "Authorization: Bearer ' . $tok . '" "' . $apiUrl . '&p=/me"';
$claudeCode = 'claude mcp add --transport http funeralorg "' . $mcpUrl . '" --header "Authorization: Bearer ' . $tok . '"';
$desktop = json_encode(['mcpServers' => ['funeralorg' => [
    'command' => 'php', 'args' => ['/PFAD/ZUR/ANWENDUNG/plugins/api/bin/mcp-stdio.php'],
    'env' => ['FUNERALORG_URL' => $mcpUrl, 'FUNERALORG_TOKEN' => $tok],
]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$when = fn(int $ts) => $ts ? fmt_date(date('c', $ts), true) : '–';
?>
<div class="page-head"><div><h1><?= e(t('api.title')) ?></h1><p class="lead-note"><?= e(t('api.lead')) ?></p></div></div>

<?php if (!$https): ?><div class="alert alert-warning"><?= e(t('api.err_https')) ?></div><?php endif; ?>

<?php if ($new !== ''): ?>
<div class="alert alert-success">
  <strong><?= e(t('api.new_token')) ?></strong>
  <p class="small mb-2"><?= e(t('api.new_hint')) ?></p>
  <div class="input-group">
    <input class="form-control font-monospace" readonly value="<?= e($new) ?>" id="newToken" aria-label="<?= e(t('api.new_token')) ?>">
    <button class="btn btn-outline-dark" type="button" data-copy="<?= e($new) ?>"><i class="bi bi-clipboard me-1"></i><?= e(t('api.copy')) ?></button>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-5">
    <form method="post" action="<?= e(url('apitokens', ['a' => 'create'])) ?>" class="surface surface-pad"><?= Csrf::field() ?>
      <h2 class="h4"><?= e(t('api.create_h')) ?></h2>
      <div class="mb-3"><label class="form-label" for="tk-label"><?= e(t('api.f_label')) ?></label>
        <input class="form-control" id="tk-label" name="label" maxlength="80" required placeholder="<?= e(t('api.f_label_ph')) ?>"></div>
      <div class="mb-3"><label class="form-label" for="tk-scope"><?= e(t('api.f_scope')) ?></label>
        <select class="form-select" id="tk-scope" name="scope">
          <option value="read"><?= e(t('api.scope_read')) ?></option>
          <option value="write"><?= e(t('api.scope_write')) ?></option>
        </select></div>
      <div class="mb-3"><label class="form-label" for="tk-days"><?= e(t('api.f_days')) ?></label>
        <select class="form-select" id="tk-days" name="days">
          <?php foreach ([30 => 'api.days_30', 90 => 'api.days_90', 180 => 'api.days_180', 365 => 'api.days_365', 0 => 'api.days_never'] as $d => $k): ?>
            <option value="<?= $d ?>"<?= $d === 90 ? ' selected' : '' ?>><?= e(t($k)) ?></option>
          <?php endforeach; ?>
        </select></div>
      <?php if ($isAdmin): ?>
        <div class="mb-3"><label class="form-label" for="tk-user"><?= e(t('api.f_user')) ?></label>
          <select class="form-select" id="tk-user" name="user">
            <option value=""><?= e(t('api.user_me')) ?></option>
            <?php foreach ($users as $u): if (!empty($u['active'])): ?><option value="<?= e($u['id']) ?>"><?= e($u['name'] ?: $u['username']) ?> (<?= e($u['username']) ?>)</option><?php endif; endforeach; ?>
          </select></div>
      <?php endif; ?>
      <p class="small text-muted"><?= e(t('api.create_hint')) ?></p>
      <button class="btn btn-primary" type="submit"><i class="bi bi-key me-1"></i><?= e(t('api.create')) ?></button>
    </form>

    <?php if ($isAdmin): ?>
    <form method="post" action="<?= e(url('apitokens', ['a' => 'settings'])) ?>" class="surface surface-pad mt-4"><?= Csrf::field() ?>
      <h2 class="h5"><?= e(t('api.settings_h')) ?></h2>
      <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="user_tokens" id="tk-ut"<?= $allow ? ' checked' : '' ?>>
        <label class="form-check-label" for="tk-ut"><?= e(t('api.user_tokens')) ?><small class="d-block text-muted"><?= e(t('api.user_tokens_hint')) ?></small></label></div>
      <button class="btn btn-outline-primary btn-sm" type="submit"><?= e(t('api.save')) ?></button>
    </form>
    <?php endif; ?>
  </div>

  <div class="col-lg-7">
    <section class="surface surface-pad">
      <h2 class="h4"><?= e(t('api.tokens_h')) ?></h2>
      <?php if (!$tokens): ?><p class="text-muted mb-0"><?= e(t('api.none')) ?></p><?php else: ?>
      <div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th><?= e(t('api.f_label')) ?></th><?php if ($isAdmin): ?><th><?= e(t('api.f_user')) ?></th><?php endif; ?><th><?= e(t('api.f_scope')) ?></th><th><?= e(t('api.expires')) ?></th><th><?= e(t('api.last_used')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($tokens as $t): $expired = $t['expires'] > 0 && $t['expires'] < time(); ?>
          <tr<?= $expired ? ' class="text-muted"' : '' ?>>
            <td><?= e($t['label']) ?><small class="d-block text-muted font-monospace"><?= e($t['hint']) ?></small></td>
            <?php if ($isAdmin): ?><td><?= e($names[$t['user']] ?? '–') ?></td><?php endif; ?>
            <td><span class="badge text-bg-<?= $t['scope'] === 'write' ? 'warning' : 'secondary' ?>"><?= e(t('api.scope_short_' . $t['scope'])) ?></span></td>
            <td><?= $t['expires'] ? e($when((int)$t['expires'])) . ($expired ? ' <span class="badge text-bg-danger">' . e(t('api.expired')) . '</span>' : '') : e(t('api.days_never')) ?></td>
            <td><?= e($when((int)$t['last_used'])) ?></td>
            <td class="text-end"><form method="post" action="<?= e(url('apitokens', ['a' => 'revoke'])) ?>" data-confirm="<?= e(t('api.revoke_confirm', ['name' => $t['label']])) ?>"><?= Csrf::field() ?>
              <input type="hidden" name="id" value="<?= e($t['id']) ?>"><button class="btn btn-sm btn-outline-danger"><?= e(t('api.revoke')) ?></button></form></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
  </div>
</div>

<section class="surface surface-pad mt-4">
  <h2 class="h4"><?= e(t('api.connect_h')) ?></h2>
  <p class="small text-muted"><?= e(t('api.connect_intro')) ?></p>
  <h3 class="h6"><?= e(t('api.rest')) ?></h3>
  <pre class="small border rounded p-2 bg-light"><?= e($curl) ?></pre>
  <p class="small"><?= e(t('api.rest_hint')) ?> <code><?= e($apiUrl . '&p=/openapi.json') ?></code></p>
  <h3 class="h6"><?= e(t('api.mcp_http')) ?></h3>
  <pre class="small border rounded p-2 bg-light"><?= e($claudeCode) ?></pre>
  <h3 class="h6"><?= e(t('api.mcp_stdio')) ?></h3>
  <pre class="small border rounded p-2 bg-light mb-2"><?= e($desktop) ?></pre>
  <p class="small text-muted mb-0"><?= e(t('api.privacy')) ?></p>
</section>
