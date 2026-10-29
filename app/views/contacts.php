<?php
$pageTitle = t('contacts.title');
$canCheck = Perm::can('contacts', Perm::CHECK);
$canEdit = Perm::can('contacts', Perm::EDIT);
$tableMode = $mode === 'table';
$wide = $tableMode;
$rsvpLabels = Contacts::rsvpLabels();
$searchAttr = $tableMode ? 'data-ct-search' : 'data-search=".person"';
?>
<div class="page-head">
  <div><h1><?= e(t('contacts.title')) ?></h1><p class="lead-note"><?= e(tn('contacts.count', count($all), ['shown' => count($list)])) ?></p></div>
  <div class="d-flex flex-wrap gap-2 align-items-start">
    <div class="btn-group btn-group-sm" role="group" aria-label="<?= e(t('contacts.view')) ?>">
      <a class="btn btn-outline-primary<?= $tableMode ? '' : ' active' ?>" href="<?= e(url('contacts', ['view' => 'cards'] + array_filter(['filter' => $filter, 'rel' => $rel]))) ?>" data-view="cards"><i class="bi bi-card-text me-1"></i><?= e(t('contacts.view_cards')) ?></a>
      <a class="btn btn-outline-primary<?= $tableMode ? ' active' : '' ?>" href="<?= e(url('contacts', ['view' => 'table'] + array_filter(['filter' => $filter, 'rel' => $rel]))) ?>" data-view="table"><i class="bi bi-table me-1"></i><?= e(t('contacts.view_table')) ?></a>
    </div>
  <?php if ($canEdit): ?>
    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('contacts', ['a' => 'import'])) ?>"><i class="bi bi-person-vcard me-1"></i><?= e(t('contacts.vcard_import')) ?></a>
    <a class="btn btn-sm btn-primary" href="<?= e(url('contacts', ['a' => 'form'])) ?>"><i class="bi bi-plus-lg me-1"></i><?= e(t('contacts.add')) ?></a>
  <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/_toolbar.php'; ?>

<?php if (!$list): ?>
  <div class="empty surface"><i class="bi bi-person-lines-fill"></i><?= e(t($all ? 'contacts.none_filter' : 'contacts.none')) ?>
    <?php if (!$all && $canEdit): ?><br><a class="btn btn-sm btn-primary mt-3" href="<?= e(url('contacts', ['a' => 'form'])) ?>"><i class="bi bi-plus-lg me-1"></i><?= e(t('contacts.add_first')) ?></a><?php endif; ?></div>
<?php elseif ($tableMode): ?>
<?php require __DIR__ . '/_contacts_table.php'; ?>
<?php else: ?>
<div class="person-list">
  <?php foreach ($list as $c): ?>
    <div class="person" data-text="<?= e(mb_strtolower(Contacts::fullName($c) . ' ' . $c['relation'] . ' ' . $c['city'] . ' ' . $c['note'])) ?>">
      <div>
        <?php require __DIR__ . '/_person.php'; ?>
        <div class="d-flex flex-wrap gap-1 mt-2">
          <?php if ($c['rsvp'] !== ''): ?><span class="badge text-bg-light"><?= e($rsvpLabels[$c['rsvp']]) ?><?= $c['rsvp'] === 'yes' ? ' · ' . ($c['adults'] + $c['children']) . ' ' . e(t('contacts.persons_short')) : '' ?></span><?php endif; ?>
          <?php if (Contacts::hasCondolence($c)): ?><span class="badge text-bg-light"><?= e(t('contacts.condolence_badge')) ?></span><?php endif; ?>
        </div>
        <?php if ($c['note']): ?><div class="small text-muted mt-2"><?= nl2br(e($c['note'])) ?></div><?php endif; ?>
      </div>
      <div class="flags">
        <?php $can = $canCheck;
          $field = 'called'; $label = t('cf.called'); include __DIR__ . '/_flag.php';
          $field = 'letter'; $label = t('cf.letter'); include __DIR__ . '/_flag.php'; ?>
        <?php if ($canEdit): ?>
          <a class="btn btn-sm btn-link text-muted text-start px-2" href="<?= e(url('contacts', ['a' => 'form', 'id' => $c['id']])) ?>"><i class="bi bi-pencil me-1"></i><?= e(t('contacts.edit')) ?></a>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
