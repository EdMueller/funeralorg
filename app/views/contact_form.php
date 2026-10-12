<?php
$pageTitle = t($c['id'] ? 'cform.edit' : 'cform.new');
$v = fn($k) => e($c[$k]);
?>
<div class="page-head"><h1><?= e($pageTitle) ?></h1></div>
<form method="post" action="<?= e(url('contacts', ['a' => 'save'])) ?>" class="surface surface-pad">
  <?= Csrf::field() ?>
  <input type="hidden" name="id" value="<?= $v('id') ?>">
  <input type="hidden" name="from" value="<?= e($from) ?>">
  <div class="row g-3">
    <div class="col-4 col-md-2"><label class="form-label" for="f-sal"><?= e(t('cform.salutation')) ?></label>
      <input class="form-control" id="f-sal" name="salutation" value="<?= $v('salutation') ?>" list="salutations">
      <datalist id="salutations"><option><?= e(t('cform.sal_ms')) ?></option><option><?= e(t('cform.sal_mr')) ?></option><option><?= e(t('cform.sal_family')) ?></option><option><?= e(t('cform.sal_dr')) ?></option></datalist></div>
    <div class="col-8 col-md-4"><label class="form-label" for="f-first"><?= e(t('cform.first')) ?></label>
      <input class="form-control" id="f-first" name="first" value="<?= $v('first') ?>" autofocus></div>
    <div class="col-md-6"><label class="form-label" for="f-last"><?= e(t('cform.last')) ?></label>
      <input class="form-control" id="f-last" name="last" value="<?= $v('last') ?>"></div>
    <div class="col-md-6"><label class="form-label" for="f-rel"><?= e(t('cform.relation')) ?></label>
      <input class="form-control" id="f-rel" name="relation" value="<?= $v('relation') ?>" list="relations" placeholder="<?= e(t('cform.relation_ph')) ?>">
      <datalist id="relations"><?php foreach ($relations as $r): ?><option><?= e($r) ?></option><?php endforeach; ?></datalist></div>

    <?php if (Perm::field('address')): ?>
    <div class="col-12"><label class="form-label" for="f-street"><?= e(t('cform.street')) ?></label>
      <input class="form-control" id="f-street" name="street" value="<?= $v('street') ?>" autocomplete="off"></div>
    <div class="col-4 col-md-2"><label class="form-label" for="f-zip"><?= e(t('cform.zip')) ?></label>
      <input class="form-control" id="f-zip" name="zip" value="<?= $v('zip') ?>" inputmode="numeric"></div>
    <div class="col-8 col-md-4"><label class="form-label" for="f-city"><?= e(t('cform.city')) ?></label>
      <input class="form-control" id="f-city" name="city" value="<?= $v('city') ?>"></div>
    <div class="col-md-3"><label class="form-label" for="f-state"><?= e(t('cform.state')) ?></label>
      <input class="form-control" id="f-state" name="state" value="<?= $v('state') ?>" list="states" autocomplete="off">
      <datalist id="states"><?php foreach (Contacts::STATES as $s): ?><option value="<?= e($s) ?>"></option><?php endforeach; ?></datalist></div>
    <div class="col-md-3"><label class="form-label" for="f-country"><?= e(t('cform.country')) ?></label>
      <input class="form-control" id="f-country" name="country" value="<?= $v('country') ?>"></div>
    <?php endif; ?>
    <?php if (Perm::field('phone')): ?>
    <div class="col-md-4"><label class="form-label" for="f-phone"><?= e(t('cform.phone')) ?></label>
      <input class="form-control" id="f-phone" name="phone" type="tel" value="<?= $v('phone') ?>"></div>
    <?php endif; ?>
    <?php if (Perm::field('mobile')): ?>
    <div class="col-md-4"><label class="form-label" for="f-mobile"><?= e(t('cform.mobile')) ?></label>
      <input class="form-control" id="f-mobile" name="mobile" type="tel" value="<?= $v('mobile') ?>"></div>
    <?php endif; ?>
    <?php if (Perm::field('email')): ?>
    <div class="col-md-4"><label class="form-label" for="f-email"><?= e(t('cform.email')) ?></label>
      <input class="form-control" id="f-email" name="email" type="email" value="<?= $v('email') ?>"></div>
    <?php endif; ?>
    <div class="col-12"><label class="form-label" for="f-note"><?= e(t('cform.note')) ?></label>
      <textarea class="form-control" id="f-note" name="note" rows="2"><?= $v('note') ?></textarea></div>
  </div>

  <?php if (Perm::can('contacts', Perm::CHECK)): ?>
  <h2 class="h5 mt-4"><?= e(t('cform.notify')) ?></h2>
  <div class="d-flex flex-wrap gap-4">
    <div class="form-check"><input class="form-check-input" type="checkbox" id="f-called" name="called" value="1" <?= $c['called'] ? 'checked' : '' ?>><label class="form-check-label" for="f-called"><?= e(t('cform.called')) ?></label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="f-letter" name="letter" value="1" <?= $c['letter'] ? 'checked' : '' ?>><label class="form-check-label" for="f-letter"><?= e(t('cform.letter')) ?></label></div>
  </div>
  <?php endif; ?>

  <?php if (Perm::can('rsvp', Perm::CHECK)): ?>
  <h2 class="h5 mt-4"><?= e(t('cform.service')) ?></h2>
  <div class="row g-3">
    <div class="col-md-4"><label class="form-label" for="f-rsvp"><?= e(t('cform.rsvp')) ?></label>
      <select class="form-select" id="f-rsvp" name="rsvp">
        <?php foreach (Contacts::rsvpLabels() as $k => $lbl): ?><option value="<?= e($k) ?>"<?= $c['rsvp'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-2"><label class="form-label" for="f-ad"><?= e(t('cform.adults')) ?></label>
      <input class="form-control" id="f-ad" type="number" min="0" max="99" name="adults" value="<?= (int)$c['adults'] ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label" for="f-ch"><?= e(t('cform.children')) ?></label>
      <input class="form-control" id="f-ch" type="number" min="0" max="99" name="children" value="<?= (int)$c['children'] ?>"></div>
    <div class="col-md-4"><label class="form-label" for="f-rn"><?= e(t('cform.note')) ?></label>
      <input class="form-control" id="f-rn" name="rsvp_note" value="<?= $v('rsvp_note') ?>" placeholder="<?= e(t('cform.rsvp_note_ph')) ?>"></div>
  </div>
  <?php endif; ?>

  <?php if (Perm::can('condolences', Perm::CHECK)): ?>
  <h2 class="h5 mt-4"><?= e(t('cform.condolence')) ?></h2>
  <div class="d-flex flex-wrap gap-4 mb-3">
    <?php foreach (['card', 'flowers', 'wreath', 'donation', 'thanks'] as $k): $lbl = t('cond.' . $k); ?>
      <div class="form-check"><input class="form-check-input" type="checkbox" id="f-<?= $k ?>" name="<?= $k ?>" value="1" <?= $c[$k] ? 'checked' : '' ?>><label class="form-check-label" for="f-<?= $k ?>"><?= e($lbl) ?></label></div>
    <?php endforeach; ?>
  </div>
  <div class="row g-3">
    <div class="col-md-3"><label class="form-label" for="f-am"><?= e(t('cform.amount')) ?></label>
      <input class="form-control" id="f-am" name="amount" inputmode="decimal" value="<?= $c['amount'] > 0 ? e(number_format((float)$c['amount'], 2, lang_dec(), '')) : '' ?>"></div>
    <div class="col-md-9"><label class="form-label" for="f-cn"><?= e(t('cform.note')) ?></label>
      <input class="form-control" id="f-cn" name="cond_note" value="<?= $v('cond_note') ?>"></div>
  </div>
  <?php endif; ?>

  <div class="d-flex flex-wrap gap-2 mt-4">
    <button class="btn btn-primary" type="submit"><?= e(t('cform.save')) ?></button>
    <a class="btn btn-outline-secondary" href="<?= e(url($from)) ?>"><?= e(t('common.cancel')) ?></a>
    <?php if ($c['id']): ?>
      <button class="btn btn-outline-danger ms-auto" type="submit" formaction="<?= e(url('contacts', ['a' => 'delete'])) ?>" formnovalidate
              data-confirm-click="<?= e(t('cform.delete_confirm', ['name' => Contacts::fullName($c)])) ?>"><?= e(t('common.delete')) ?></button>
    <?php endif; ?>
  </div>
  <?php if ($c['updated']): ?><p class="small text-muted mt-3 mb-0"><?= e(t('cform.last_changed', ['date' => fmt_date($c['updated'], true), 'by' => $c['updated_by']])) ?></p><?php endif; ?>
</form>
