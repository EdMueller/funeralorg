<?php $pageTitle = $title; ?>
<div class="surface surface-pad text-center my-4">
  <h1 class="h2"><?= e($title) ?></h1>
  <?php if ($msg !== ''): ?><p class="mb-3"><?= e($msg) ?></p><?php endif; ?>
  <?php if (Auth::check()): ?>
    <a class="btn btn-outline-primary" href="<?= e(url(Perm::firstRoute() ?? 'home')) ?>"><?= e(t('error.home')) ?></a>
  <?php else: ?>
    <a class="btn btn-outline-primary" href="<?= e(url('login')) ?>"><?= e(t('error.login')) ?></a>
  <?php endif; ?>
</div>
