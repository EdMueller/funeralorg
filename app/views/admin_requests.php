<?php $pageTitle = t('req.title'); ?>
<div class="page-head"><h1><?= e(t('admin.title')) ?></h1></div>
<?php require __DIR__ . '/_adminnav.php'; ?>
<p class="text-muted small"><?= e(t('req.intro')) ?></p>
<?php if (!$list): ?>
  <div class="empty surface"><i class="bi bi-key"></i><?= e(t('req.none')) ?></div>
<?php else: ?>
<div class="surface table-wrap">
  <table class="table mb-0 align-middle">
    <thead><tr><th class="ps-3"><?= e(t('req.col_from')) ?></th><th><?= e(t('req.col_callback')) ?></th><th><?= e(t('req.col_account')) ?></th><th><?= e(t('req.col_received')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $q): ?>
      <tr>
        <td class="ps-3"><?= e($q['who']) ?></td>
        <td><?= $q['phone'] !== '' ? '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $q['phone'])) . '">' . e($q['phone']) . '</a>' : '–' ?></td>
        <td><?= $q['user'] ? e($q['user']['username']) : '<span class="text-muted">' . e(t('req.not_found')) . '</span>' ?></td>
        <td class="small"><?= e(fmt_date($q['created'], true)) ?></td>
        <td class="text-end pe-3 text-nowrap">
          <?php if ($q['has_contact']): ?>
            <form class="d-inline" method="post" action="<?= e(url('contacts', ['a' => 'access', 'mode' => 'reset'])) ?>" target="_blank">
              <?= Csrf::field() ?><input type="hidden" name="ids[]" value="<?= e($q['user']['contact_id']) ?>">
              <button class="btn btn-sm btn-outline-primary" type="submit" data-confirm-click="<?= e(t('req.reset_confirm')) ?>"><?= e(t('req.reset_print')) ?></button>
            </form>
          <?php elseif ($q['user']): ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin', ['a' => 'users'])) ?>"><?= e(t('req.edit_user')) ?></a>
          <?php endif; ?>
          <form class="d-inline" method="post" action="<?= e(url('admin', ['a' => 'requestdone'])) ?>">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= e($q['id']) ?>">
            <button class="btn btn-sm btn-outline-secondary" type="submit"><?= e(t('req.done')) ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
