<?php
/** @var string $content */
$u = Auth::user();
$m = Store::read('memorial');
$title = ($pageTitle ?? '') !== '' ? $pageTitle . ' – ' : '';
$nav = [];
if (Perm::memorial()) {
    $nav['home'] = [t('nav.memorial'), 'bi-flower1'];
    $nav['stations'] = [t('nav.stations'), 'bi-geo-alt'];
}
if (Perm::gallery()) {
    $nav['gallery'] = [t('nav.gallery'), 'bi-images'];
}
if (Perm::can('checklist')) {
    $nav['checklist'] = [t('nav.checklist'), 'bi-check2-square'];
}
if (Perm::can('contacts')) {
    $nav['contacts'] = [t('nav.contacts'), 'bi-person-lines-fill'];
}
if (Perm::can('rsvp')) {
    $nav['rsvp'] = [t('nav.rsvp'), 'bi-people'];
}
if (Perm::can('condolences')) {
    $nav['condolences'] = [t('nav.condolences'), 'bi-envelope-heart'];
}
?><!doctype html>
<html lang="<?= e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf" content="<?= e(Csrf::token()) ?>">
<title><?= e($title . ($m['name'] ?: cfg('app_name'))) ?></title>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<?php if (Auth::isShare()): ?>
  <div class="share-banner"><?= t('layout.share_banner', ['name' => '<strong>' . e($u['name']) . '</strong>']) ?></div>
<?php endif; ?>
<?php if (Auth::isImpersonating()): ?>
  <form class="imp-banner" method="post" action="<?= e(url('unimpersonate')) ?>">
    <?= Csrf::field() ?>
    <span><?= t('layout.imp_banner', ['name' => '<strong>' . e($u['name']) . '</strong>', 'admin' => e($u['via'] ?? '')]) ?></span>
    <button class="btn btn-sm btn-outline-dark" type="submit"><?= e(t('layout.imp_back')) ?></button>
  </form>
<?php endif; ?>
<nav class="navbar navbar-expand-lg app-nav" data-bs-theme="dark">
  <div class="container-fluid" style="max-width:1120px">
    <a class="navbar-brand" href="<?= e(url(Perm::firstRoute() ?? 'home')) ?>"><?= e($m['name'] ?: cfg('app_name')) ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainnav" aria-controls="mainnav" aria-expanded="false" aria-label="<?= e(t('layout.menu_open')) ?>">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainnav">
      <ul class="navbar-nav me-auto">
        <?php foreach ($nav as $r => [$label, $icon]): ?>
          <li class="nav-item"><a class="nav-link<?= nav_active($r) ?>" href="<?= e(url($r)) ?>"><i class="bi <?= $icon ?> me-1 d-lg-none"></i><?= e($label) ?></a></li>
        <?php endforeach; ?>
        <?php if (Auth::isAdmin()): ?>
          <li class="nav-item"><a class="nav-link<?= nav_active('admin') ?>" href="<?= e(url('admin')) ?>"><i class="bi bi-sliders me-1 d-lg-none"></i><?= e(t('nav.admin')) ?></a></li>
        <?php endif; ?>
      </ul>
      <div class="d-flex align-items-center gap-2 py-2 py-lg-0">
      <?php require __DIR__ . '/_lang.php'; ?>
      <?php if (!Auth::isShare()): ?>
      <form method="post" action="<?= e(url('logout')) ?>" class="d-flex align-items-center gap-2 m-0">
        <?= Csrf::field() ?>
        <a class="text-white-50 small text-decoration-none" href="<?= e(url('account')) ?>" title="<?= e(t('layout.account_title')) ?>"><?= e($u['name']) ?></a>
        <button class="btn btn-sm btn-outline-light" type="submit"><?= e(t('layout.logout')) ?></button>
      </form>
      <?php endif; ?>
      </div>
    </div>
  </div>
</nav>
<main class="app-main<?= !empty($wide) ? ' app-main-wide' : '' ?>">
  <?php foreach (take_flashes() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="status">
      <?= e($f['msg']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= e(t('common.close')) ?>"></button>
    </div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<?php if (!empty($withChart)): ?>
<script src="<?= e(asset('vendor/chartjs/chart.umd.min.js')) ?>"></script>
<?php endif; ?>
<script type="application/json" id="i18n"><?= json_encode(I18n::export('js.') + ['_lang' => I18n::lang()], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
