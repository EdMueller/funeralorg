<?php $title = t('forgot.title'); ?>
<h1 class="h3 mb-3"><?= e(t('forgot.title')) ?></h1>
<?php foreach ($flashes as $f): ?><div class="alert alert-info py-2" role="status"><?= e($f['msg']) ?></div><?php endforeach; ?>
<?php if ($mode === 'mail'): ?>
  <form class="surface p-3 mb-3" method="post" action="<?= e(url('forgot')) ?>" autocomplete="off">
    <?= Csrf::field() ?>
    <p class="mb-3"><?= e(t('forgot.intro_mail')) ?></p>
    <label class="form-label" for="fg-user"><?= e(t('login.username')) ?></label>
    <input class="form-control mb-3" id="fg-user" name="username" required maxlength="100" autocapitalize="none" spellcheck="false" autofocus>
    <button class="btn btn-primary" type="submit"><?= e(t('forgot.send_link')) ?></button>
  </form>
  <p class="small"><a href="<?= e(url('forgot', ['a' => 'help'])) ?>"><?= e(t('forgot.no_mail')) ?></a></p>
  <?php $kind = 'mail'; require __DIR__ . '/_reset_privacy.php'; ?>
<?php else: ?>
  <form class="surface p-3 mb-3" method="post" action="<?= e(url('forgot', ['a' => 'help'])) ?>" autocomplete="off">
    <?= Csrf::field() ?>
    <p class="mb-3"><?= e(t('forgot.intro_help')) ?></p>
    <label class="form-label" for="fg-who"><?= e(t('forgot.who')) ?></label>
    <input class="form-control mb-3" id="fg-who" name="who" required maxlength="100" autofocus>
    <label class="form-label" for="fg-phone"><?= e(t('forgot.phone')) ?></label>
    <input class="form-control mb-3" id="fg-phone" name="phone" maxlength="40" inputmode="tel">
    <button class="btn btn-primary" type="submit"><?= e(t('forgot.send_request')) ?></button>
  </form>
  <p class="small"><a href="<?= e(url('forgot')) ?>"><?= e(t('forgot.back')) ?></a></p>
  <?php $kind = 'help'; require __DIR__ . '/_reset_privacy.php'; ?>
<?php endif; ?>
<p class="small"><a href="<?= e(url('login')) ?>"><?= e(t('forgot.to_login')) ?></a></p>
