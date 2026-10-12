<?php
declare(strict_types=1);

/**
 * Front-Controller – einziger öffentlicher PHP-Einstiegspunkt.
 *
 * Die Anwendung findet ihr eigenes Verzeichnis selbst und kennt weder Domain
 * noch Installationspfad. Unterstützte Layouts:
 *  A) alles in einem Verzeichnis (Standard): .../funeralorg/{index.php, assets/,
 *                                   app/, bin/, configs/, data/, docs/} = Webroot
 *  C) ISPConfig:                    web/ = index.php + assets/ + .htaccess,
 *                                   Anwendung (app/, configs/, data/, …) in private/gedenkseite/
 *  D) freier Pfad:                  Umgebungsvariable GEDENK_ROOT oder Datei app-path.php
 *                                   neben dieser Datei, die einen Pfad zurückgibt
 */
define('PUBLIC_DIR', __DIR__);

$candidates = [];
if ($env = getenv('GEDENK_ROOT')) {
    $candidates[] = $env;
}
if (is_file(__DIR__ . '/app-path.php')) {
    $candidates[] = (string)require __DIR__ . '/app-path.php';
}
$dir = __DIR__;
for ($i = 0; $i < 5; $i++) {
    $candidates[] = $dir;
    $candidates[] = $dir . '/private/gedenkseite';
    $parent = dirname($dir);
    if ($parent === $dir) {
        break;
    }
    $dir = $parent;
}

$root = null;
foreach ($candidates as $candidate) {
    if ($candidate !== '' && is_file($candidate . '/app/bootstrap.php')) {
        $root = $candidate;
        break;
    }
}
if ($root === null) {
    http_response_code(500);
    exit('Anwendungsverzeichnis nicht gefunden (siehe README, Abschnitt Installation).');
}
require $root . '/app/bootstrap.php';

try {
    dispatch();
} catch (Throwable $e) {
    error_log('[gedenkseite] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>Fehler</title>'
       . '<p style="font-family:sans-serif;max-width:32rem;margin:4rem auto">Es ist ein Fehler aufgetreten. '
       . 'Bitte später erneut versuchen. Details stehen im PHP-Fehlerprotokoll des Servers.</p>';
}
