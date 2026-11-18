<?php
/**
 * Portrait im Rahmen; mehrere Bilder werden weich überblendet (assets/js/portrait.js).
 * Erwartet: $pImages (Bilddatensätze), $pRoute ('img'|'pubimg'), $pAlt, optional $pClass.
 */
?>
<div class="portrait-frame frame-<?= e(Frame::key($m['frame'] ?? ($memorial['frame'] ?? ''))) ?> <?= e($pClass ?? '') ?>"<?= count($pImages) > 1 ? ' data-portrait-fade' : '' ?>>
  <?php if ($pImages): foreach ($pImages as $i => $pi): ?>
    <img src="<?= e(url($pRoute, ['id' => $pi['id'], 's' => 'medium'])) ?>" alt="<?= $i === 0 ? e($pAlt) : '' ?>">
  <?php endforeach; else: ?>
    <div class="portrait-empty w-100 h-100" aria-hidden="true"></div>
  <?php endif; ?>
</div>
