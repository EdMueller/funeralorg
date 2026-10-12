<?php
$pageTitle = t('users.title');
$gNames = array_column($groups, 'name', 'id');
?>
<div class="page-head"><h1><?= e(t('admin.title')) ?></h1>
  <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" data-user="<?= e(json_encode(['active' => true, 'role' => 'user'])) ?>"><i class="bi bi-person-plus me-1"></i><?= e(t('users.add')) ?></button></div>
<?php require __DIR__ . '/_adminnav.php'; ?>
<?php if (!$groups): ?><div class="alert alert-warning"><?= e(t('users.need_group')) ?></div><?php endif; ?>
<div class="surface table-wrap">
  <table class="table mb-0 align-middle">
    <thead><tr><th class="ps-3"><?= e(t('users.col_name')) ?></th><th><?= e(t('users.col_username')) ?></th><th><?= e(t('users.col_role_group')) ?></th><th><?= e(t('users.col_last_login')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr class="<?= empty($u['active']) ? 'text-muted' : '' ?>">
        <td class="ps-3"><?= e($u['name']) ?><?= empty($u['active']) ? ' <span class="badge text-bg-light">' . e(t('users.deactivated')) . '</span>' : '' ?></td>
        <td><?= e($u['username']) ?></td>
        <td><?= $u['role'] === 'admin' ? e(t('users.admin_all')) : e($gNames[$u['group_id']] ?? t('users.no_group')) ?></td>
        <td class="small"><?= $u['last_login'] ? e(fmt_date($u['last_login'], true)) : e(t('common.never')) ?></td>
        <td class="text-end pe-3 text-nowrap">
          <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#userModal"
                  data-user="<?= e(json_encode(array_diff_key($u, ['pass_hash' => 1]))) ?>"><?= e(t('common.edit')) ?></button>
          <?php if ($u['id'] !== $me['id'] && $u['role'] !== 'admin' && !empty($u['active'])): ?>
          <form class="d-inline" method="post" action="<?= e(url('admin', ['a' => 'impersonate'])) ?>">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= e($u['id']) ?>">
            <button class="btn btn-sm btn-outline-secondary" title="<?= e(t('users.impersonate')) ?>" aria-label="<?= e(t('users.impersonate')) ?>"><i class="bi bi-box-arrow-in-right"></i></button></form>
          <?php endif; ?>
          <?php if ($u['id'] !== $me['id']): ?>
          <form class="d-inline" method="post" action="<?= e(url('admin', ['a' => 'userdelete'])) ?>" data-confirm="<?= e(t('users.delete_confirm', ['name' => $u['username']])) ?>">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= e($u['id']) ?>">
            <button class="btn btn-sm btn-outline-danger" aria-label="<?= e(t('common.delete')) ?>"><i class="bi bi-trash"></i></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= e(url('admin', ['a' => 'usersave'])) ?>">
      <?= Csrf::field() ?><input type="hidden" name="id">
      <div class="modal-header"><h2 class="modal-title h4" id="userTitle"><?= e(t('users.modal_title')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('common.close')) ?>"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label" for="u-name"><?= e(t('users.display_name')) ?></label><input class="form-control" id="u-name" name="name" required></div>
        <div class="mb-3"><label class="form-label" for="u-un"><?= e(t('users.username')) ?></label>
          <input class="form-control" id="u-un" name="username" required pattern="[a-z0-9._@\-]{3,60}" autocapitalize="none" autocomplete="off">
          <div class="form-text"><?= e(t('users.username_hint')) ?></div></div>
        <div class="mb-3"><label class="form-label" for="u-pw"><?= e(t('users.password')) ?></label>
          <input class="form-control" id="u-pw" name="password" type="password" minlength="10" autocomplete="new-password">
          <div class="form-text" data-pw-hint><?= e(t('users.password_hint')) ?></div></div>
        <div class="row g-3">
          <div class="col-6"><label class="form-label" for="u-role"><?= e(t('users.role')) ?></label>
            <select class="form-select" id="u-role" name="role"><option value="user"><?= e(t('users.role_user')) ?></option><option value="admin"><?= e(t('users.role_admin')) ?></option></select></div>
          <div class="col-6"><label class="form-label" for="u-group"><?= e(t('users.group')) ?></label>
            <select class="form-select" id="u-group" name="group_id"><option value="">–</option>
              <?php foreach ($groups as $g): ?><option value="<?= e($g['id']) ?>"><?= e($g['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="u-active" name="active" value="1">
          <label class="form-check-label" for="u-active"><?= e(t('users.active')) ?></label></div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary" type="submit"><?= e(t('users.save')) ?></button></div>
    </form>
  </div>
</div>
