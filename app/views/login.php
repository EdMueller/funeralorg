<?php
$name = $memorial['name'] ?: cfg('app_name');
$slides = array_map(fn($i) => url('pubimg', ['id' => $i['id'], 's' => 'medium']), $loginImages);
shuffle($slides);
$flashes = take_flashes();
?><!doctype html>
<html lang="<?= e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#1d2225">
<title><?= e($name) ?></title>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="login-body">
  <div class="lang-switch-plain"><?php require __DIR__ . '/_lang.php'; ?></div>
  <div class="login-stage" id="loginStage" data-slides="<?= e(json_encode($slides)) ?>" aria-hidden="true"></div>

  <div class="login-wrap">
    <div class="login-hero">
      <div class="portrait-frame frame-<?= e(Frame::key($memorial['frame'] ?? '')) ?> mx-auto">
        <?php if ($portrait): ?>
          <img src="<?= e(url('pubimg', ['id' => $portrait['id'], 's' => 'medium'])) ?>" alt="<?= e(t('login.portrait_alt', ['name' => $name])) ?>">
        <?php else: ?>
          <div class="portrait-empty w-100 h-100" aria-hidden="true"></div>
        <?php endif; ?>
      </div>

      <h1 class="login-name"><?= e($name) ?></h1>
      <?php if ($memorial['born'] || $memorial['died']): ?>
        <p class="life-dates mb-0">
          <?php if ($memorial['born']): ?><span>* <?= e($memorial['born']) ?></span><?php endif; ?>
          <?php if ($memorial['died']): ?><span>† <?= e($memorial['died']) ?></span><?php endif; ?>
        </p>
      <?php endif; ?>
      <?php if (!empty($memorial['quote'])): ?>
        <blockquote class="login-quote">
          <?= e($memorial['quote']) ?>
          <?php if (!empty($memorial['quote_by'])): ?><cite><?= e($memorial['quote_by']) ?></cite><?php endif; ?>
        </blockquote>
      <?php endif; ?>
    </div>

    <form class="login-form" method="post" action="<?= e(url('login')) ?>" autocomplete="on">
      <?= Csrf::field() ?>
      <?php foreach ($flashes as $f): ?>
        <div class="alert py-2" role="status"><?= e($f['msg']) ?></div>
      <?php endforeach; ?>
      <?php if ($error): ?>
        <div class="alert py-2" role="alert"><?= e($error) ?></div>
      <?php endif; ?>
      <label class="visually-hidden" for="username"><?= e(t('login.username')) ?></label>
      <input class="form-control mb-2" id="username" name="username" placeholder="<?= e(t('login.username')) ?>"
             value="<?= e($username) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required <?= $username === '' ? 'autofocus' : '' ?>>
      <label class="visually-hidden" for="password"><?= e(t('login.password')) ?></label>
      <input class="form-control" id="password" name="password" type="password" placeholder="<?= e(t('login.password')) ?>"
             autocomplete="current-password" required <?= $username !== '' ? 'autofocus' : '' ?>>
      <button class="btn" type="submit"><?= e(t('login.submit')) ?></button>
      <a class="login-forgot" href="<?= e(url('forgot')) ?>"><?= e(t('login.forgot')) ?></a>
    </form>
  </div>
  <p class="login-foot"><?= e(t('login.foot')) ?></p>
<script src="<?= e(asset('js/login.js')) ?>"></script>
</body>
</html>
