<?php $title = t('reset.title'); ?>
<h1 class="h3 mb-3"><?= e(t('reset.heading')) ?></h1>
<?php foreach ($flashes as $f): ?><div class="alert alert-<?= e($f['type'] === 'warning' ? 'warning' : 'info') ?> py-2" role="alert"><?= e($f['msg']) ?></div><?php endforeach; ?>
<form class="surface p-3" method="post" action="<?= e(url('reset')) ?>" autocomplete="off">
  <?= Csrf::field() ?>
  <input type="hidden" name="t" value="<?= e($token) ?>">
  <p class="mb-3"><?= t('reset.account', ['user' => '<strong>' . e($user['username']) . '</strong>']) ?></p>
  <div class="mb-3"><label class="form-label" for="rs-new"><?= e(t('account.new')) ?></label>
    <input class="form-control" id="rs-new" type="password" name="new" required minlength="10" autocomplete="new-password" autofocus>
    <div class="form-text"><?= e(t('account.min10')) ?></div></div>
  <div class="mb-3"><label class="form-label" for="rs-rep"><?= e(t('account.repeat')) ?></label>
    <input class="form-control" id="rs-rep" type="password" name="repeat" required minlength="10" autocomplete="new-password"></div>
  <button class="btn btn-primary" type="submit"><?= e(t('account.save')) ?></button>
</form>
<?php $kind = 'reset'; require __DIR__ . '/_reset_privacy.php'; ?>
