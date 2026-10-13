<?php $pageTitle = t('sender.title'); ?>
<div class="page-head"><h1><?= e(t('admin.title')) ?></h1></div>
<?php require __DIR__ . '/_adminnav.php'; ?>
<form method="post" action="<?= e(url('admin', ['a' => 'sendersave'])) ?>" class="surface surface-pad" style="max-width:36rem">
  <?= Csrf::field() ?>
  <h2 class="h4"><?= e(t('sender.h')) ?></h2>
  <p class="text-muted small"><?= e(t('sender.intro')) ?></p>
  <label class="form-label" for="sd-text"><?= e(t('sender.label')) ?></label>
  <textarea class="form-control mb-3" id="sd-text" name="sender" rows="5" maxlength="400" placeholder="<?= e(t('sender.ph')) ?>"><?= e(implode("\n", $sender)) ?></textarea>
  <button class="btn btn-primary" type="submit"><?= e(t('sender.save')) ?></button>
</form>
