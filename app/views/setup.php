<?php $title = t('setup.title'); ?>
<div class="surface surface-pad my-4">
  <h1 class="h2 mb-2"><?= e(t('setup.title')) ?></h1>
  <p class="text-muted"><?= e(t('setup.intro')) ?></p>

  <?php foreach ($errors as $err): ?>
    <div class="alert alert-warning py-2" role="alert"><?= e($err) ?></div>
  <?php endforeach; ?>

  <form method="post" action="<?= e(url('setup')) ?>" autocomplete="off">
    <?= Csrf::field() ?>
    <div class="mb-3">
      <label class="form-label" for="username"><?= e(t('setup.username')) ?></label>
      <input class="form-control" id="username" name="username" value="<?= e($form['username']) ?>"
             autocapitalize="none" spellcheck="false" required autofocus>
    </div>
    <div class="mb-3">
      <label class="form-label" for="name"><?= e(t('setup.display_name')) ?></label>
      <input class="form-control" id="name" name="name" value="<?= e($form['name']) ?>" maxlength="100">
    </div>
    <div class="mb-3">
      <label class="form-label" for="password"><?= e(t('setup.password')) ?></label>
      <input class="form-control" id="password" name="password" type="password" minlength="10" autocomplete="new-password" required>
    </div>
    <div class="mb-3">
      <label class="form-label" for="password2"><?= e(t('setup.password2')) ?></label>
      <input class="form-control" id="password2" name="password2" type="password" minlength="10" autocomplete="new-password" required>
    </div>
    <div class="mb-3">
      <label class="form-label" for="base_url"><?= e(t('setup.base_url')) ?></label>
      <input class="form-control" id="base_url" name="base_url" type="url" value="<?= e($form['base_url']) ?>"
             autocapitalize="none" spellcheck="false" maxlength="200">
      <div class="form-text"><?= e(t('setup.base_url_hint')) ?></div>
    </div>
    <div class="mb-4">
      <label class="form-label" for="deceased"><?= e(t('setup.deceased')) ?></label>
      <input class="form-control" id="deceased" name="deceased" value="<?= e($form['deceased']) ?>" maxlength="100">
      <div class="form-text"><?= e(t('setup.deceased_hint')) ?></div>
    </div>
    <button class="btn btn-primary" type="submit"><?= e(t('setup.submit')) ?></button>
  </form>
</div>
