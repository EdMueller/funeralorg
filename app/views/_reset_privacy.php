<?php
/** Datenschutzhinweis der Seiten „Passwort vergessen“. Erwartet: $kind = mail | help | reset */
$months = (int)cfg('log_retention_months', 12);
$privacyUrl = trim((string)cfg('privacy_url', ''));
?>
<div class="small text-muted mt-3" role="note">
  <strong class="d-block"><?= e(t('privacy.title')) ?></strong>
  <?php if ($kind === 'mail'): ?>
    <p class="mb-1"><?= e(t('privacy.mail')) ?></p>
  <?php elseif ($kind === 'help'): ?>
    <p class="mb-1"><?= e(t('privacy.help')) ?></p>
  <?php else: ?>
    <p class="mb-1"><?= e(t('privacy.reset')) ?></p>
  <?php endif; ?>
  <?php if ($kind !== 'reset'): ?>
    <p class="mb-1"><?= e(t($kind === 'mail' ? 'privacy.log_mail' : 'privacy.log_help', ['ip' => t(cfg('log_full_ip', false) ? 'privacy.ip_full' : 'privacy.ip_short'), 'months' => $months])) ?></p>
  <?php endif; ?>
  <?php if ($privacyUrl !== '' && preg_match('#^https?://#i', $privacyUrl)): ?>
    <p class="mb-0"><a href="<?= e($privacyUrl) ?>" rel="noopener"><?= e(t('privacy.more')) ?></a></p>
  <?php endif; ?>
</div>
