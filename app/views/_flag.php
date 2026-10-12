<?php /** Umschaltknopf für ein Ja/Nein-Feld. $c, $field, $label, $icon, $can */ ?>
<button type="button" class="flag<?= !empty($c[$field]) ? ' on' : '' ?>" data-flag="<?= e($field) ?>" data-id="<?= e($c['id']) ?>"
        aria-pressed="<?= !empty($c[$field]) ? 'true' : 'false' ?>" <?= $can ? '' : 'disabled' ?>>
  <i class="bi <?= !empty($c[$field]) ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i><?= e($label) ?>
</button>
