<?php
/**
 * Tabellenansicht der Kontakte (Verhalten: app.js, Abschnitt „Kontakt-Tabelle“).
 * Erwartet: $list, $relations, $canCheck, $canEdit
 */
$cols = Contacts::tableColumns();
$rsvpLabels = Contacts::rsvpLabels();
$cellValue = function (array $c, array $col) use ($rsvpLabels): array {
    // [Anzeige, Filterwert (data-v), Sortierwert (data-s)]
    switch ($col['type']) {
        case 'flag':
            return ['', $c[$col['key']] ? '1' : '0', $c[$col['key']] ? '1' : '0'];
        case 'rsvp':
            $lbl = $c['rsvp'] === '' ? '' : $rsvpLabels[$c['rsvp']];
            return [$lbl, $c['rsvp'], $lbl];
        case 'num':
            $n = $c['rsvp'] === 'yes' ? (int)$c['adults'] + (int)$c['children'] : '';
            return [(string)$n, (string)$n, (string)$n];
        default:
            $v = (string)$c[$col['key']];
            return [$v, $v, $v];
    }
};
$colsJson = array_map(fn($c) => ['key' => $c['key'], 'label' => $c['label'], 'type' => $c['type'],
    'edit' => $c['edit'], 'on' => $c['on'], 'w' => $c['w']], $cols);
$n = 0;
?>
<script type="application/json" id="ctCols"><?= json_encode($colsJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<datalist id="ctRelations"><?php foreach ($relations as $r): ?><option value="<?= e($r) ?>"></option><?php endforeach; ?></datalist>
<datalist id="ctStates"><?php foreach (Contacts::STATES as $s): ?><option value="<?= e($s) ?>"></option><?php endforeach; ?></datalist>

<div class="ct" id="ctable" data-ct data-edit-url="<?= e(url('contacts', ['a' => 'cellset'])) ?>">
  <div class="ct-tools">
    <button type="button" class="btn btn-sm btn-outline-primary" id="ctColsBtn"><i class="bi bi-layout-three-columns me-1"></i><?= e(t('ct.columns')) ?></button>
    <button type="button" class="btn btn-sm btn-outline-primary" id="ctFilterBtn" aria-pressed="false"><i class="bi bi-funnel me-1"></i><?= e(t('ct.col_filter')) ?></button>
    <button type="button" class="btn btn-sm btn-outline-primary" id="ctUndo" disabled><i class="bi bi-arrow-counterclockwise me-1"></i><?= e(t('ct.undo')) ?></button>
    <button type="button" class="btn btn-sm btn-outline-primary" id="ctPrint"><i class="bi bi-printer me-1"></i><?= e(t('ct.print')) ?></button>
    <?php if ($canEdit || $canCheck): ?><span class="ct-hint small text-muted ms-auto d-none d-md-inline"><?= e(t('ct.hint')) ?></span><?php endif; ?>
  </div>

  <div class="ct-scroll surface">
    <table class="ctable">
      <thead>
        <tr class="ct-head">
          <th class="ct-sel"><input type="checkbox" id="ctAll" class="form-check-input" aria-label="<?= e(t('ct.select_all')) ?>"></th>
          <th class="ct-num" title="<?= e(t('ct.nr_title')) ?>"><?= e(t('ct.nr')) ?></th>
          <?php foreach ($cols as $col): ?>
            <th scope="col" data-col="<?= e($col['key']) ?>" data-type="<?= e($col['type']) ?>"<?= $col['on'] ? '' : ' hidden' ?> aria-sort="none">
              <button type="button" class="ct-th" data-sort="<?= e($col['key']) ?>"><?= e($col['label']) ?><i class="bi ct-arrow"></i></button>
              <span class="ct-grip" data-grip="<?= e($col['key']) ?>" role="separator" aria-label="<?= e(t('ct.resize')) ?>"></span>
            </th>
          <?php endforeach; ?>
          <th class="ct-act"></th>
        </tr>
        <tr class="ct-filters" hidden>
          <th class="ct-sel"></th><th class="ct-num"></th>
          <?php foreach ($cols as $col): ?>
            <th data-col="<?= e($col['key']) ?>"<?= $col['on'] ? '' : ' hidden' ?>>
              <?php if ($col['type'] === 'flag'): ?>
                <select class="form-select form-select-sm" data-filter="<?= e($col['key']) ?>" aria-label="<?= e(t('ct.filter_aria', ['col' => $col['label']])) ?>"><option value="*"><?= e(t('ct.filter_all')) ?></option><option value="1"><?= e(t('ct.filter_yes')) ?></option><option value="0"><?= e(t('ct.filter_no')) ?></option></select>
              <?php elseif ($col['type'] === 'rsvp'): ?>
                <select class="form-select form-select-sm" data-filter="rsvp" aria-label="<?= e(t('ct.filter_aria', ['col' => $col['label']])) ?>"><option value="*"><?= e(t('ct.filter_all')) ?></option>
                  <?php foreach ($rsvpLabels as $k => $lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?></select>
              <?php else: ?>
                <input class="form-control form-control-sm" type="search" data-filter="<?= e($col['key']) ?>" placeholder="<?= e(t('ct.filter_ph')) ?>" aria-label="<?= e(t('ct.filter_aria', ['col' => $col['label']])) ?>">
              <?php endif; ?>
            </th>
          <?php endforeach; ?>
          <th class="ct-act"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($list as $c): $n++; ?>
          <?php
            $text = [];
            foreach ($cols as $col) {
                if ($col['type'] === 'text' || $col['type'] === 'rsvp') {
                    $text[] = $cellValue($c, $col)[0];
                }
            }
          ?>
          <tr data-id="<?= e($c['id']) ?>" data-adults="<?= (int)$c['adults'] ?>" data-children="<?= (int)$c['children'] ?>"
              data-text="<?= e(mb_strtolower(implode(' ', $text))) ?>">
            <td class="ct-sel"><input type="checkbox" class="form-check-input ct-pick" aria-label="<?= e(t('ct.select_row', ['name' => Contacts::fullName($c) ?: t('ct.contact')])) ?>"></td>
            <td class="ct-num"><?= $n ?></td>
            <?php foreach ($cols as $col): [$disp, $v, $s] = $cellValue($c, $col); ?>
              <?php if ($col['type'] === 'flag'): ?>
                <td data-col="<?= e($col['key']) ?>" data-v="<?= e($v) ?>" data-s="<?= e($s) ?>"<?= $col['on'] ? '' : ' hidden' ?>>
                  <button type="button" class="flag ct-flag<?= $c[$col['key']] ? ' on' : '' ?>" data-flag="<?= e($col['key']) ?>" data-id="<?= e($c['id']) ?>"
                          aria-pressed="<?= $c[$col['key']] ? 'true' : 'false' ?>" aria-label="<?= e($col['label']) ?>" <?= $col['edit'] ? '' : 'disabled' ?>>
                    <i class="bi <?= $c[$col['key']] ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i>
                  </button>
                </td>
              <?php else: ?>
                <td data-col="<?= e($col['key']) ?>" data-v="<?= e($v) ?>" data-s="<?= e($s) ?>"<?= $col['edit'] ? ' data-edit="' . e($col['type']) . '" title="' . e(t('ct.dblclick_edit')) . '"' : '' ?><?= $col['on'] ? '' : ' hidden' ?>><?= e($disp) ?></td>
              <?php endif; ?>
            <?php endforeach; ?>
            <td class="ct-act"><?php if ($canEdit): ?><a class="btn btn-sm btn-link text-muted p-0" href="<?= e(url('contacts', ['a' => 'form', 'id' => $c['id']])) ?>" title="<?= e(t('ct.edit_in_form')) ?>" aria-label="<?= e(t('ct.edit_in_form')) ?>"><i class="bi bi-pencil"></i></a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="ct-summary small text-muted" id="ctSummary" aria-live="polite"></p>

  <form class="ct-actions" id="ctActions" method="post" action="<?= e(url('contacts')) ?>" data-ct-ids hidden>
    <?= Csrf::field() ?>
    <span class="ct-count"><strong id="ctCount">0</strong> <?= e(t('ct.selected')) ?></span>
    <button type="button" class="btn btn-sm btn-link" id="ctClear"><?= e(t('ct.clear')) ?></button>
    <div class="dropdown dropup">
      <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-download me-1"></i><?= e(t('ct.export')) ?></button>
      <ul class="dropdown-menu">
        <li><button class="dropdown-item" type="submit" formaction="<?= e(url('contacts', ['a' => 'export', 'fmt' => 'xlsx'])) ?>"><?= e(t('ct.excel')) ?></button></li>
        <li><button class="dropdown-item" type="submit" formaction="<?= e(url('contacts', ['a' => 'export', 'fmt' => 'csv'])) ?>"><?= e(t('ct.csv_merge')) ?></button></li>
      </ul>
    </div>
    <button type="button" class="btn btn-sm btn-outline-light" id="ctPrintSel"><i class="bi bi-printer me-1"></i><?= e(t('ct.print')) ?></button>
    <?php if (Perm::field('address')): ?>
      <button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#labelModal"><i class="bi bi-tag me-1"></i><?= e(t('ct.labels')) ?></button>
    <?php endif; ?>
    <?php if (Auth::isAdmin()): ?>
      <button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#accessModal"><i class="bi bi-key me-1"></i><?= e(t('ct.access')) ?></button>
    <?php endif; ?>
    <?php if ($canCheck || $canEdit): ?>
    <div class="dropdown dropup">
      <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside"><i class="bi bi-check2-square me-1"></i><?= e(t('ct.set_status')) ?></button>
      <ul class="dropdown-menu">
        <?php if ($canCheck): foreach (['called_1', 'called_0', 'letter_1', 'letter_0'] as $k): $lbl = t('contacts.bulk_' . $k); ?>
          <li><button class="dropdown-item" type="submit" name="what" value="<?= e($k) ?>" formaction="<?= e(url('contacts', ['a' => 'bulkset'])) ?>"><?= e($lbl) ?></button></li>
        <?php endforeach; endif; ?>
        <?php if ($canEdit): ?>
          <li><hr class="dropdown-divider"></li>
          <li class="px-3 py-1" style="min-width:15rem">
            <label class="form-label small mb-1" for="ctRelValue"><?= e(t('ct.set_relation')) ?></label>
            <div class="input-group input-group-sm">
              <input class="form-control" id="ctRelValue" name="value" list="ctRelations" autocomplete="off" maxlength="500">
              <button class="btn btn-outline-primary" type="submit" id="ctRelBtn" name="what" value="relation" formaction="<?= e(url('contacts', ['a' => 'bulkset'])) ?>"><?= e(t('ct.set')) ?></button>
            </div>
          </li>
        <?php endif; ?>
      </ul>
    </div>
    <?php endif; ?>
    <?php if ($canEdit): ?>
      <button class="btn btn-sm btn-danger ms-auto" id="ctDelete" type="submit" formaction="<?= e(url('contacts', ['a' => 'bulkdelete'])) ?>"
              data-confirm-click="<?= e(t('ct.delete_confirm')) ?>"><i class="bi bi-trash me-1"></i><?= e(t('ct.delete')) ?></button>
    <?php endif; ?>
  </form>
</div>
<?php if (Auth::isAdmin()): $accessGroups = Store::read('groups'); ?>
<div class="modal fade" id="accessModal" tabindex="-1" aria-labelledby="accessTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= e(url('contacts', ['a' => 'access'])) ?>" target="_blank" data-ct-ids>
      <?= Csrf::field() ?>
      <div class="modal-header"><h2 class="modal-title h5" id="accessTitle"><?= e(t('ct.access_title')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('common.close')) ?>"></button></div>
      <div class="modal-body">
        <p class="small text-muted" data-access-scope><?= e(t('ct.access_scope')) ?></p>
        <label class="form-label" for="ac-group"><?= e(t('ct.access_group')) ?></label>
        <select class="form-select mb-3" id="ac-group" name="group_id">
          <?php foreach ($accessGroups as $g): ?><option value="<?= e($g['id']) ?>"><?= e($g['name']) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label" for="ac-f"><?= e(t('ct.access_paper')) ?></label>
        <select class="form-select mb-3" id="ac-f" name="format">
          <?php foreach (Access::formats() as $k => $f): ?><option value="<?= e($k) ?>"><?= e($f['name']) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label" for="ac-s"><?= e(t('ct.access_start')) ?></label>
        <input class="form-control" id="ac-s" type="number" name="start" value="1" min="1" max="40">
        <div class="form-text"><?= e(t('ct.access_hint')) ?></div>
      </div>
      <div class="modal-footer justify-content-between flex-wrap">
        <button class="btn btn-sm btn-outline-secondary" type="submit" formaction="<?= e(url('contacts', ['a' => 'access', 'mode' => 'reset'])) ?>"
                data-confirm-click="<?= e(t('ct.access_reset_confirm')) ?>"><?= e(t('ct.access_reset')) ?></button>
        <button class="btn btn-primary" type="submit"><?= e(t('ct.access_create')) ?></button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
