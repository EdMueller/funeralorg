<?php
$perPage = $fmt['cols'] * $fmt['rows'];
$cells = array_merge(array_fill(0, max(0, min($perPage - 1, $start - 1)), null), $list);
$pages = array_chunk($cells, $perPage) ?: [[]];
$fmtMm = fn($v) => number_format((float)$v, 2, '.', '') . 'mm';
?><!doctype html>
<html lang="<?= e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<title><?= e(t('labels.title')) ?></title>
<style>
  @page { size: A4; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: "Source Sans 3", Arial, sans-serif; font-size: 10.5pt; color: #000; background: #e8e8e4; }
  .hint { font: 14px/1.4 system-ui, sans-serif; padding: 12px 16px; background: #2e3438; color: #fff; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
  .hint button { font: inherit; padding: 6px 14px; border: 1px solid #9c8558; background: #9c8558; color: #1d2225; border-radius: 4px; cursor: pointer; }
  .sheet { width: 210mm; height: 297mm; margin: 10mm auto; background: #fff; position: relative; overflow: hidden;
           padding: <?= $fmtMm($fmt['top']) ?> 0 0 <?= $fmtMm($fmt['left']) ?>;
           display: grid; grid-template-columns: repeat(<?= (int)$fmt['cols'] ?>, <?= $fmtMm($fmt['w']) ?>);
           grid-auto-rows: <?= $fmtMm($fmt['h']) ?>; column-gap: <?= $fmtMm($fmt['gap_x']) ?>; row-gap: <?= $fmtMm($fmt['gap_y']) ?>;
           align-content: start; page-break-after: always; }
  .label { padding: 4mm 6mm; display: flex; flex-direction: column; justify-content: center; line-height: 1.3; overflow: hidden; outline: 1px dashed #ddd; }
  .label .name { font-weight: 600; }
  @media print { body { background: #fff; } .hint { display: none; } .sheet { margin: 0; } .label { outline: none; } }
</style>
</head>
<body>
<div class="hint">
  <span><?= e(tn('labels.count', count($list), ['sheets' => count($pages)])) ?> <?= e(t('print.hint')) ?></span>
  <button type="button" id="printBtn"><?= e(t('print.print')) ?></button>
</div>
<?php foreach ($pages as $page): ?>
  <div class="sheet">
    <?php foreach ($page as $c): ?>
      <div class="label">
        <?php if ($c): ?>
          <?php if ($c['salutation']): ?><div><?= e($c['salutation']) ?></div><?php endif; ?>
          <div class="name"><?= e(Contacts::fullName($c)) ?></div>
          <div><?= e($c['street']) ?></div>
          <div><?= e(trim($c['zip'] . ' ' . $c['city'])) ?></div>
          <?php if ($c['country']): ?><div><?= e(mb_strtoupper($c['country'])) ?></div><?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<script src="<?= e(asset('js/print.js')) ?>"></script>
</body>
</html>
