<?php $pageTitle = t('import.title'); ?>
<div class="page-head"><div><h1><?= e(t('import.title')) ?></h1>
  <p class="lead-note"><?= e(t('import.intro')) ?></p></div></div>

<?php if ($result): ?>
  <div class="surface surface-pad mb-3">
    <p class="mb-2"><?= e(t('import.result', ['added' => count($result['added']), 'found' => $result['found']])) ?><?= $result['skipped'] ? e(t('import.skipped', ['n' => count($result['skipped'])])) : '' ?>.</p>
    <?php if ($result['skipped']): ?>
      <details><summary class="small"><?= e(t('import.show_skipped')) ?></summary><p class="small text-muted mb-0 mt-2"><?= e(implode(', ', $result['skipped'])) ?></p></details>
    <?php endif; ?>
    <a class="btn btn-sm btn-primary mt-3" href="<?= e(url('contacts')) ?>"><?= e(t('import.to_contacts')) ?></a>
  </div>
<?php endif; ?>

<form class="surface surface-pad" method="post" enctype="multipart/form-data" action="<?= e(url('contacts', ['a' => 'import'])) ?>">
  <?= Csrf::field() ?>
  <div class="mb-3"><label class="form-label" for="vcf"><?= e(t('import.file')) ?></label>
    <input class="form-control" type="file" id="vcf" name="vcf" accept=".vcf,text/vcard,text/x-vcard" required></div>
  <div class="mb-3"><label class="form-label" for="rel"><?= e(t('import.relation')) ?></label>
    <input class="form-control" id="rel" name="relation" list="relations">
    <datalist id="relations"><?php foreach ($relations as $r): ?><option><?= e($r) ?></option><?php endforeach; ?></datalist></div>
  <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="dup" name="allow_dup" value="1">
    <label class="form-check-label" for="dup"><?= e(t('import.allow_dup')) ?></label></div>
  <button class="btn btn-primary" type="submit"><?= e(t('import.submit')) ?></button>
</form>
