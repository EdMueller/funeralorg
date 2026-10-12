<?php
$pageTitle = t('rsvpv.title');
$canCheck = Perm::can('rsvp', Perm::CHECK);
?>
<div class="page-head">
  <div><h1><?= e(t('rsvpv.title')) ?></h1><p class="lead-note"><?= e(t('rsvpv.lead')) ?></p></div>
  <?php if (Perm::can('contacts', Perm::EDIT)): ?>
    <a class="btn btn-sm btn-primary" href="<?= e(url('contacts', ['a' => 'form', 'from' => 'rsvp'])) ?>"><i class="bi bi-plus-lg me-1"></i><?= e(t('rsvpv.add')) ?></a>
  <?php endif; ?>
</div>

<div class="sums" id="rsvpSums">
  <div><strong data-sum="yes"><?= $sums['yes'] ?></strong><small><?= e(t('rsvpv.sum_yes')) ?></small></div>
  <div><strong data-sum="no"><?= $sums['no'] ?></strong><small><?= e(t('rsvpv.sum_no')) ?></small></div>
  <div><strong data-sum="open"><?= $sums['open'] ?></strong><small><?= e(t('rsvpv.sum_open')) ?></small></div>
  <div><strong data-sum="adults"><?= $sums['adults'] ?></strong><small><?= e(t('rsvpv.sum_adults')) ?></small></div>
  <div class="total"><strong data-sum="persons"><?= $sums['adults'] + $sums['children'] ?></strong><small><?= t('rsvpv.sum_total', ['children' => '<span data-sum="children">' . (int)$sums['children'] . '</span>']) ?></small></div>
</div>

<?php require __DIR__ . '/_toolbar.php'; ?>

<?php if (!$list): ?>
  <div class="empty surface"><i class="bi bi-people"></i><?= e(t('rsvpv.empty')) ?>
    <?php if ($canCheck && $filter === ''): ?><br><a href="#" data-bs-toggle="collapse" data-bs-target="#inviteList"><?= e(t('rsvpv.invite_contacts')) ?></a><?php endif; ?></div>
<?php else: ?>
<div class="person-list">
  <?php foreach ($list as $c): ?>
    <div class="person" data-text="<?= e(mb_strtolower(Contacts::fullName($c) . ' ' . $c['relation'] . ' ' . $c['city'])) ?>">
      <div>
        <?php require __DIR__ . '/_person.php'; ?>
        <?php if ($c['rsvp_note']): ?><div class="small text-muted mt-2"><?= e($c['rsvp_note']) ?></div><?php endif; ?>
      </div>
      <form class="rsvp-form d-flex flex-column gap-2 align-items-start" method="post" action="<?= e(url('rsvp', ['a' => 'rsvpset'])) ?>" data-rsvp>
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= e($c['id']) ?>">
        <input type="hidden" name="rsvp" value="<?= e($c['rsvp']) ?>">
        <div class="rsvp-seg" role="group" aria-label="<?= e(t('rsvpv.response')) ?>">
          <?php foreach (['yes' => t('rsvp.yes'), 'no' => t('rsvp.no'), 'open' => t('rsvp.open')] as $v => $lbl): ?>
            <button type="<?= $canCheck ? 'button' : 'button' ?>" data-v="<?= $v ?>" class="<?= $c['rsvp'] === $v ? 'on' : '' ?>" <?= $canCheck ? '' : 'disabled' ?>><?= e($lbl) ?></button>
          <?php endforeach; ?>
        </div>
        <div class="d-flex gap-2 align-items-center small">
          <label class="d-flex align-items-center gap-1"><?= e(t('rsvpv.adults_short')) ?> <input class="form-control form-control-sm count-input" type="number" min="0" max="99" name="adults" value="<?= (int)$c['adults'] ?>" <?= $canCheck ? '' : 'disabled' ?>></label>
          <label class="d-flex align-items-center gap-1"><?= e(t('rsvpv.children_short')) ?> <input class="form-control form-control-sm count-input" type="number" min="0" max="99" name="children" value="<?= (int)$c['children'] ?>" <?= $canCheck ? '' : 'disabled' ?>></label>
        </div>
        <noscript><button class="btn btn-sm btn-outline-primary"><?= e(t('rsvpv.save')) ?></button></noscript>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$notInvited = array_values(array_filter($all, fn($c) => $c['rsvp'] === ''));
if ($canCheck && $notInvited && $filter === ''): ?>
  <div class="mt-4">
    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#inviteList" aria-expanded="false">
      <?= e(tn('rsvpv.show_uninvited', count($notInvited))) ?></button>
    <div class="collapse mt-2" id="inviteList">
      <div class="person-list">
        <?php foreach ($notInvited as $c): ?>
          <div class="person py-2">
            <div class="person-name" style="font-size:1.1rem"><?= e(Contacts::fullName($c)) ?><span class="person-rel"><?= e($c['relation']) ?></span></div>
            <form method="post" action="<?= e(url('rsvp', ['a' => 'rsvpset'])) ?>"><?= Csrf::field() ?>
              <input type="hidden" name="id" value="<?= e($c['id']) ?>"><input type="hidden" name="rsvp" value="open">
              <button class="btn btn-sm btn-outline-primary"><?= e(t('rsvpv.mark_invited')) ?></button></form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>
