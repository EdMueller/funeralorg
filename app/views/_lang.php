<?php /** Sprachumschalter (POST an r=lang, merkt Cookie und Benutzerkonto). */ ?>
<form class="lang-switch" method="post" action="<?= e(url('lang')) ?>" aria-label="<?= e(t('lang.label')) ?>">
  <?= Csrf::field() ?>
  <?php foreach (I18n::LANGS as $code => $label): ?>
    <button type="submit" name="lang" value="<?= e($code) ?>" lang="<?= e($code) ?>" title="<?= e($label) ?>"<?= I18n::lang() === $code ? ' class="active" aria-current="true"' : '' ?>><?= e(strtoupper($code)) ?></button>
  <?php endforeach; ?>
</form>
