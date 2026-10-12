<?php
/** Kopf einer Kontaktzeile: Name, Beziehung, Adresse, Telefon. $c = Kontakt */
$lines = [];
?>
<div class="person-main">
  <div class="person-name"><?= e(trim($c['salutation'] . ' ' . Contacts::fullName($c)) ?: t('person.no_name')) ?><?php if ($c['relation']): ?><span class="person-rel"><?= e($c['relation']) ?></span><?php endif; ?></div>
  <div class="person-lines">
    <?php if (Perm::field('address') && ($c['street'] || $c['city'])): ?>
      <div><i class="bi bi-house"></i><?= e($c['street']) ?><?= $c['street'] && $c['city'] ? ', ' : '' ?><?= e(trim($c['zip'] . ' ' . $c['city'])) ?><?= $c['state'] ? ', ' . e($c['state']) : '' ?><?= $c['country'] ? ', ' . e($c['country']) : '' ?></div>
    <?php endif; ?>
    <?php if (Perm::field('email') && $c['email']): ?>
      <div><i class="bi bi-envelope"></i><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></div>
    <?php endif; ?>
  </div>
  <?php
  $tel = [];
  if (Perm::field('phone') && $c['phone']) $tel[] = ['bi-telephone', $c['phone'], t('person.landline')];
  if (Perm::field('mobile') && $c['mobile']) $tel[] = ['bi-phone', $c['mobile'], t('person.mobile')];
  if ($tel): ?>
    <div class="tel-row">
      <?php foreach ($tel as [$ic, $num, $lbl]): ?>
        <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $num)) ?>" title="<?= e(t('person.call', ['type' => $lbl])) ?>"><i class="bi <?= $ic ?> me-1"></i><?= e($num) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
