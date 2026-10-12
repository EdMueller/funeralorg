<?php
/** Klebestreifen mit Zugangsdaten (A4). Erwartet: $rows, $skipped, $fmt, $start, $mode, $addr */
$perPage = $fmt['cols'] * $fmt['rows'];
$cells = array_merge(array_fill(0, max(0, min($perPage - 1, $start - 1)), null), $rows);
$pages = array_chunk($cells, $perPage) ?: [[]];
$fmtMm = fn($v) => number_format((float)$v, 2, '.', '') . 'mm';
$shown = preg_replace('#^https?://#', '', rtrim($addr, '/'));
$qr = Qr::svg($addr, t('access.qr_alt'));
// Ab dieser Länge passt der Benutzername in Normalschrift nicht mehr neben Beschriftung und QR-Code
$maxChars = (int)floor(((float)$fmt['w'] - 59) / 2.65);
?><!doctype html>
<html lang="<?= e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<title><?= e(t('access.title')) ?></title>
<style>
  @page { size: A4; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: "Source Sans 3", Arial, sans-serif; color: #000; background: #e8e8e4; }
  .hint { font: 14px/1.4 system-ui, sans-serif; padding: 12px 16px; background: #2e3438; color: #fff; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
  .hint button { font: inherit; padding: 6px 14px; border: 1px solid #9c8558; background: #9c8558; color: #1d2225; border-radius: 4px; cursor: pointer; }
  .hint .warn { flex-basis: 100%; color: #ffd98a; }
  .sheet { width: 210mm; height: 297mm; margin: 10mm auto; background: #fff; position: relative; overflow: hidden;
           padding: <?= $fmtMm($fmt['top']) ?> 0 0 <?= $fmtMm($fmt['left']) ?>;
           display: grid; grid-template-columns: repeat(<?= (int)$fmt['cols'] ?>, <?= $fmtMm($fmt['w']) ?>);
           grid-auto-rows: <?= $fmtMm($fmt['h']) ?>; column-gap: <?= $fmtMm($fmt['gap_x']) ?>; row-gap: <?= $fmtMm($fmt['gap_y']) ?>;
           align-content: start; page-break-after: always; }
  .cell { display: flex; align-items: center; gap: 2mm; padding: 2mm 5mm 2mm 6mm; overflow: hidden; outline: 1px dashed #ccc; }
  .txt { flex: 1 1 auto; min-width: 0; line-height: 1.25; }
  .ln { display: flex; align-items: baseline; gap: 2mm; }
  .k { font-size: 8pt; color: #444; width: 21mm; flex: none; }
  .v { font: 700 12.5pt/1.25 "DejaVu Sans Mono", Consolas, monospace; overflow-wrap: anywhere; }
  .v.long { font-size: 10pt; }
  .url { font-size: 8pt; color: #444; margin-top: .6mm; }
  .qr { flex: none; width: 23mm; height: 23mm; }
  .qr svg { display: block; width: 100%; height: 100%; }
  @media print { body { background: #fff; } .hint { display: none; } .sheet { margin: 0; } .cell { outline: none; } }
</style>
</head>
<body>
<div class="hint">
  <span><?= e(tn('access.count', count($rows), ['sheets' => count($pages)])) ?> <?= e(t('print.hint')) ?></span>
  <button type="button" id="printBtn"><?= e(t('print.print')) ?></button>
  <span class="warn"><?= e(t($mode === 'new' ? 'access.warn_new' : 'access.warn_reset')) ?>
    <?= e(t('access.warn_common')) ?></span>
  <?php if ($skipped): ?><span class="warn"><?= e(t('access.skipped', ['list' => implode('; ', $skipped)])) ?></span><?php endif; ?>
</div>
<?php foreach ($pages as $page): ?>
  <div class="sheet">
    <?php foreach ($page as $r): ?>
      <div class="cell">
        <?php if ($r): ?>
          <div class="txt">
            <div class="ln"><span class="k"><?= e(t('access.username')) ?></span><span class="v<?= strlen($r['username']) > $maxChars ? ' long' : '' ?>"><?= e($r['username']) ?></span></div>
            <div class="ln"><span class="k"><?= e(t('access.password')) ?></span><span class="v"><?= e($r['password']) ?></span></div>
            <div class="url"><?= e($shown) ?></div>
          </div>
          <div class="qr"><?= $qr ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<script src="<?= e(asset('js/print.js')) ?>"></script>
</body>
</html>
