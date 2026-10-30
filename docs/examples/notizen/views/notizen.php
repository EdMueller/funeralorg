<?php
/** @var list<array{id:string, text:string, by:string, created:string}> $notes @var bool $isAdmin */
$pageTitle = t('nz.title');
$styles = [plugin_asset('notizen', 'css/notizen.css')];   // eigenes CSS einhängen
?>
<div class="page-head"><div><h1><?= e(t('nz.title')) ?></h1><p class="lead-note"><?= e(tn('nz.lead', count($notes))) ?></p></div></div>

<form method="post" action="<?= e(url('notizen', ['a' => 'add'])) ?>" class="surface surface-pad mb-4">
  <?= Csrf::field() ?>
  <label class="form-label" for="nz-text"><?= e(t('nz.text')) ?></label>
  <textarea class="form-control mb-2" id="nz-text" name="text" rows="3" maxlength="<?= (int)Notizen::MAX_LENGTH ?>" required></textarea>
  <button class="btn btn-primary" type="submit"><?= e(t('nz.add')) ?></button>
</form>

<?php if (!$notes): ?>
  <div class="empty surface"><i class="bi bi-sticky"></i><?= e(t('nz.none')) ?></div>
<?php endif; ?>
<?php foreach ($notes as $n): ?>
  <div class="surface surface-pad mb-2 nz-note">
    <div class="nz-text"><?= nl2br(e($n['text'])) ?></div>
    <div class="small text-muted mt-1"><?= e($n['by']) ?> · <?= e(fmt_date($n['created'], true)) ?></div>
    <?php if ($isAdmin): ?>
      <form method="post" action="<?= e(url('notizen', ['a' => 'delete'])) ?>" class="mt-2" data-confirm="<?= e(t('nz.delete_confirm')) ?>">
        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= e($n['id']) ?>">
        <button class="btn btn-sm btn-outline-danger" type="submit"><?= e(t('nz.delete')) ?></button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
