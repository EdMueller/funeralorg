<?php
$pageTitle = t('transfer.preview_h');
$m = $info['manifest'];
$count = static function (string $k, array $c): string {
    return match ($k) {
        'gallery'   => t('transfer.c_gallery', ['albums' => $c['albums'], 'images' => $c['images'], 'files' => $c['files']]),
        'checklist' => t('transfer.c_checklist', ['cats' => $c['cats'], 'items' => $c['items']]),
        'plugindata' => tn('transfer.c_files', (int)$c['n']),
        'logs'      => tn('transfer.c_months', (int)$c['n']),
        'contacts', 'users', 'shares', 'groups' => tn('transfer.c_entries', (int)$c['n']),
        default     => t('transfer.c_present'),
    };
};
$show = static fn($v) => is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<div class="page-head"><h1><?= e(t('admin.title')) ?></h1></div>
<?php require __DIR__ . '/_adminnav.php'; ?>
<section class="surface surface-pad mb-4">
  <h2 class="h4"><?= e(t('transfer.preview_h')) ?></h2>
  <p class="text-muted small mb-2"><?= e($m ? t('transfer.preview_info', ['date' => fmt_date((string)($m['created'] ?? ''), true), 'app' => (string)($m['app'] ?? '?')]) : t('transfer.legacy')) ?></p>
  <p class="small"><?= t('transfer.preview_hint', ['dir' => '<code>data/backups/</code>']) ?></p>
  <?php if ($info['skipped']): ?><div class="alert alert-warning py-2"><?= e(t('transfer.skipped', ['n' => $info['skipped']])) ?></div><?php endif; ?>
  <?php if ($info['noAdmin']): ?><div class="alert alert-danger py-2"><?= e(t('transfer.err_noadmin')) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url('admin', ['a' => 'transferapply'])) ?>"><?= Csrf::field() ?>
    <input type="hidden" name="token" value="<?= e($info['token']) ?>">
    <div class="table-responsive"><table class="table align-middle">
      <thead><tr><th></th><th><?= e(t('transfer.col_part')) ?></th><th><?= e(t('transfer.col_content')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($info['parts'] as $k => $c): if ($k === 'configphp') { continue; } ?>
        <tr>
          <td><input class="form-check-input" type="checkbox" name="parts[]" value="<?= e($k) ?>" id="im-<?= e($k) ?>" aria-label="<?= e(t('transfer.part.' . $k)) ?>"<?= $k === 'users' && $info['noAdmin'] ? ' disabled' : ' checked' ?>></td>
          <td><label for="im-<?= e($k) ?>"><?= e(t('transfer.part.' . $k)) ?></label>
            <?php if ($k === 'users'): ?><small class="d-block text-warning-emphasis"><?= e(t('transfer.users_warn')) ?></small><?php endif; ?></td>
          <td><?= e($count($k, $c)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="d-flex flex-wrap gap-2">
      <button class="btn btn-primary" type="submit" data-confirm-click="<?= e(t('transfer.apply_confirm')) ?>"><i class="bi bi-box-arrow-in-down me-1"></i><?= e(t('transfer.apply')) ?></button>
      <button class="btn btn-outline-secondary" type="submit" formaction="<?= e(url('admin', ['a' => 'transferdrop'])) ?>" formnovalidate><?= e(t('transfer.discard')) ?></button>
    </div>
  </form>
</section>
<?php if ($info['config']): ?>
<section class="surface surface-pad">
  <h2 class="h4"><?= e(t('transfer.config_h')) ?></h2>
  <p class="small text-muted"><?= e(t('transfer.config_intro')) ?></p>
  <?php if (!$info['config']['snippet']): ?>
    <p class="mb-0"><?= e(t('transfer.config_same')) ?></p>
  <?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle">
      <thead><tr><th><?= e(t('transfer.config_key')) ?></th><th><?= e(t('transfer.config_here')) ?></th><th><?= e(t('transfer.config_pkg')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($info['config']['rows'] as $row): if (!$row['differs']) { continue; } ?>
        <tr><td><code><?= e($row['key']) ?></code></td><td><code><?= e($show($row['here'])) ?></code></td><td><code><?= e($show($row['pkg'])) ?></code></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <pre class="small border rounded p-2 bg-light mb-0"><?= e($info['config']['snippet']) ?></pre>
  <?php endif; ?>
</section>
<?php endif; ?>
