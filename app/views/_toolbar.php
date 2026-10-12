<?php
/** Filter-, Such- und Exportleiste für Kontakte, Zu-/Absagen, Kondolenzen. */
$qs = array_filter(['filter' => $filter, 'rel' => $rel]);
$filters = Contacts::filterLabels();
if ($section === 'rsvp') {
    $filters = array_intersect_key($filters, array_flip(['', 'rsvp_yes', 'rsvp_no', 'rsvp_open']));
} elseif ($section === 'condolences') {
    $filters = ['' => t('filter.cond_all'), 'thanks_open' => t('filter.thanks_open')];
}
?>
<form class="toolbar" method="get" action="index.php">
  <input type="hidden" name="r" value="<?= e($section) ?>">
  <?php if (!empty($tableMode)): ?><input type="hidden" name="view" value="table"><?php endif; ?>
  <input class="form-control form-control-sm" type="search" placeholder="<?= e(t('tb.search_ph')) ?>" <?= $searchAttr ?? 'data-search=".person"' ?> aria-label="<?= e(t('tb.search_aria')) ?>">
  <select class="form-select form-select-sm" name="filter" data-autosubmit aria-label="<?= e(t('tb.filter')) ?>">
    <?php foreach ($filters as $k => $lbl): ?><option value="<?= e($k) ?>"<?= $k === $filter ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
  </select>
  <?php if ($relations): ?>
  <select class="form-select form-select-sm" name="rel" data-autosubmit aria-label="<?= e(t('tb.relation')) ?>">
    <option value=""><?= e(t('tb.all_relations')) ?></option>
    <?php foreach ($relations as $r): ?><option<?= $r === $rel ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <noscript><button class="btn btn-sm btn-outline-primary"><?= e(t('tb.apply')) ?></button></noscript>
  <div class="dropdown ms-auto">
    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-download me-1"></i><?= e(t('tb.export')) ?></button>
    <ul class="dropdown-menu dropdown-menu-end">
      <li><a class="dropdown-item" href="<?= e(url($section, ['a' => 'export', 'fmt' => 'xlsx'] + $qs)) ?>"><?= e(t('ct.excel')) ?></a></li>
      <li><a class="dropdown-item" href="<?= e(url($section, ['a' => 'export', 'fmt' => 'csv'] + $qs)) ?>"><?= e(t('ct.csv_merge')) ?></a></li>
      <?php if (Perm::field('address')): ?>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#labelModal"><?= e(t('tb.labels_menu')) ?></button></li>
      <?php endif; ?>
    </ul>
  </div>
</form>
<?php if (Perm::field('address')): ?>
<div class="modal fade" id="labelModal" tabindex="-1" aria-labelledby="labelTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <form class="modal-content" method="post" action="<?= e(url($section, ['a' => 'labels'])) ?>" target="_blank" data-ct-ids>
      <?= Csrf::field() ?>
      <input type="hidden" name="filter" value="<?= e($filter) ?>"><input type="hidden" name="rel" value="<?= e($rel) ?>">
      <div class="modal-header"><h2 class="modal-title h5" id="labelTitle"><?= e(t('tb.labels_title')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('common.close')) ?>"></button></div>
      <div class="modal-body">
        <p class="small text-muted" data-label-scope data-all="<?= e(t('tb.labels_scope')) ?>"><?= e(t('tb.labels_scope')) ?></p>
        <label class="form-label" for="lb-f"><?= e(t('tb.labels_sheet')) ?></label>
        <select class="form-select mb-3" id="lb-f" name="format">
          <?php foreach ($formats as $k => $f): ?><option value="<?= e($k) ?>"><?= e($f['name']) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label" for="lb-s"><?= e(t('tb.labels_start')) ?></label>
        <input class="form-control" id="lb-s" type="number" name="start" value="1" min="1" max="40">
        <div class="form-text"><?= e(t('tb.labels_hint')) ?></div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary" type="submit"><?= e(t('tb.labels_open')) ?></button></div>
    </form>
  </div>
</div>
<?php endif; ?>
