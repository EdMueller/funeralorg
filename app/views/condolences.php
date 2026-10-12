<?php
$pageTitle = t('condv.title');
$canCheck = Perm::can('condolences', Perm::CHECK);
if ($filter === '') {
    $list = Contacts::filter($list, 'cond_any');
}
$sumAmount = array_sum(array_map(fn($c) => (float)$c['amount'], $list));
$thanksOpen = count(Contacts::filter($list, 'thanks_open'));
?>
<div class="page-head">
  <div><h1><?= e(t('condv.heading')) ?></h1>
    <p class="lead-note"><?= e(tn('condv.lead', count($list), ['open' => $thanksOpen])) ?><?= $sumAmount > 0 ? e(t('condv.lead_sum', ['sum' => fmt_money($sumAmount)])) : '' ?></p></div>
  <?php if ($canCheck): ?>
    <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addCond"><i class="bi bi-plus-lg me-1"></i><?= e(t('condv.add')) ?></button>
  <?php endif; ?>
</div>

<?php if ($canCheck): ?>
<div class="collapse mb-3" id="addCond">
  <div class="surface surface-pad">
    <label class="form-label" for="condPick"><?= e(t('condv.from_whom')) ?></label>
    <input class="form-control mb-2" id="condPick" type="search" placeholder="<?= e(t('condv.name_ph')) ?>" data-search="#condCandidates .person">
    <div class="person-list" id="condCandidates" style="max-height:320px;overflow:auto">
      <?php foreach (array_filter($all, fn($c) => !Contacts::hasCondolence($c)) as $c): ?>
        <div class="person py-2" data-text="<?= e(mb_strtolower(Contacts::fullName($c) . ' ' . $c['city'])) ?>">
          <div class="person-name" style="font-size:1.1rem"><?= e(Contacts::fullName($c)) ?><span class="person-rel"><?= e($c['relation']) ?></span></div>
          <form method="post" action="<?= e(url('condolences', ['a' => 'toggle'])) ?>"><?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= e($c['id']) ?>"><input type="hidden" name="field" value="card">
            <button class="btn btn-sm btn-outline-primary"><?= e(t('condv.card_received')) ?></button></form>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (Perm::can('contacts', Perm::EDIT)): ?>
      <p class="small mt-2 mb-0"><?= t('condv.not_listed', ['link' => '<a href="' . e(url('contacts', ['a' => 'form', 'from' => 'condolences'])) . '">' . e(t('condv.new_contact')) . '</a>']) ?></p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_toolbar.php'; ?>

<?php if (!$list): ?>
  <div class="empty surface"><i class="bi bi-envelope-heart"></i><?= e(t('condv.empty')) ?></div>
<?php else: ?>
<div class="person-list">
  <?php foreach ($list as $c): ?>
    <div class="person" data-text="<?= e(mb_strtolower(Contacts::fullName($c) . ' ' . $c['relation'] . ' ' . $c['city'] . ' ' . $c['cond_note'])) ?>">
      <div>
        <?php require __DIR__ . '/_person.php'; ?>
        <?php if ($c['amount'] > 0 || $c['cond_note']): ?>
          <div class="small mt-2"><?= $c['amount'] > 0 ? e(t('condv.donation_line', ['sum' => fmt_money((float)$c['amount'])])) . ($c['cond_note'] ? ' · ' : '') : '' ?><span class="text-muted"><?= e($c['cond_note']) ?></span></div>
        <?php endif; ?>
        <?php if ($canCheck): ?>
          <button class="btn btn-sm btn-link px-0 mt-1" data-bs-toggle="collapse" data-bs-target="#cd-<?= e($c['id']) ?>"><?= e(t('condv.amount_note')) ?></button>
          <form class="collapse mt-2" id="cd-<?= e($c['id']) ?>" method="post" action="<?= e(url('condolences', ['a' => 'condset'])) ?>">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>">
            <div class="d-flex flex-wrap gap-2">
              <input class="form-control form-control-sm" style="max-width:8rem" name="amount" inputmode="decimal" placeholder="<?= e(t('condv.amount_ph')) ?>" value="<?= $c['amount'] > 0 ? e(number_format((float)$c['amount'], 2, lang_dec(), '')) : '' ?>" aria-label="<?= e(t('condv.amount_aria')) ?>">
              <input class="form-control form-control-sm" style="max-width:20rem" name="cond_note" placeholder="<?= e(t('condv.note_ph')) ?>" value="<?= e($c['cond_note']) ?>" aria-label="<?= e(t('condv.note_aria')) ?>">
              <button class="btn btn-sm btn-outline-primary"><?= e(t('condv.save')) ?></button>
            </div>
          </form>
        <?php endif; ?>
      </div>
      <div class="flags">
        <?php $can = $canCheck;
        foreach (['card', 'flowers', 'wreath', 'donation', 'thanks'] as $field) {
            $label = t('cond.' . $field);
            include __DIR__ . '/_flag.php';
        } ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
