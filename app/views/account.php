<?php $pageTitle = t('account.title'); ?>
<div class="page-head"><div><h1><?= e(t('account.title')) ?></h1><p class="lead-note"><?= e(t('account.signed_in_as', ['user' => $me['username']])) ?></p></div></div>
<form class="surface p-3" style="max-width:28rem" method="post" action="<?= e(url('account')) ?>" autocomplete="off">
  <?= Csrf::field() ?>
  <h2 class="h5"><?= e(t('account.change_pw')) ?></h2>
  <div class="mb-3"><label class="form-label" for="ac-cur"><?= e(t('account.current')) ?></label>
    <input class="form-control" id="ac-cur" type="password" name="current" required autocomplete="current-password"></div>
  <div class="mb-3"><label class="form-label" for="ac-new"><?= e(t('account.new')) ?></label>
    <input class="form-control" id="ac-new" type="password" name="new" required minlength="10" autocomplete="new-password">
    <div class="form-text"><?= e(t('account.min10')) ?></div></div>
  <div class="mb-3"><label class="form-label" for="ac-rep"><?= e(t('account.repeat')) ?></label>
    <input class="form-control" id="ac-rep" type="password" name="repeat" required minlength="10" autocomplete="new-password"></div>
  <button class="btn btn-primary" type="submit"><?= e(t('account.save')) ?></button>
</form>
