<!doctype html>
<html lang="<?= e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? cfg('app_name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<div class="lang-switch-plain"><?php require __DIR__ . '/_lang.php'; ?></div>
<main class="app-main" style="max-width:36rem;padding-top:4rem">
<?= $content ?>
</main>
</body>
</html>
