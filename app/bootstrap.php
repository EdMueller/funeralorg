<?php
declare(strict_types=1);

define('ROOT_DIR', dirname(__DIR__));
define('APP_DIR', __DIR__);

$cfgFile = ROOT_DIR . '/configs/config.php';
if (!is_file($cfgFile) && is_file(ROOT_DIR . '/configs/config.sample.php')) {
    @copy(ROOT_DIR . '/configs/config.sample.php', $cfgFile);
    @chmod($cfgFile, 0640);
}
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit('configs/config.php fehlt und konnte nicht aus configs/config.sample.php erstellt werden – bitte Datei kopieren und Schreibrechte prüfen.');
}
$GLOBALS['CFG'] = require $cfgFile;
define('DATA_DIR', rtrim($GLOBALS['CFG']['data_dir'], '/'));

date_default_timezone_set($GLOBALS['CFG']['timezone'] ?? 'Europe/Berlin');
mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $class): void {
    $f = APP_DIR . '/lib/' . $class . '.php';
    if (is_file($f)) {
        require $f;
        return;
    }
    Plugins::autoload($class);
});

require APP_DIR . '/helpers.php';

/* ------------------------------------------------------------------ Session */
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name(cfg('session_name', 'gdk_session'));
    session_set_cookie_params([
        'lifetime' => 0,
        // Cookie gilt nur für das Installationsverzeichnis – so sind mehrere
        // Anwendungen bzw. Installationen auf derselben Domain unabhängig.
        'path'     => base_path(),
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();

    $idle = (int)cfg('session_idle_minutes', 240) * 60;
    if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > $idle) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = time();
}

/* ------------------------------------------------------------------ Routing */
function dispatch(): void
{
    start_session();
    send_security_headers();
    Plugins::boot();

    $r = preg_replace('/[^a-z]/', '', (string)($_GET['r'] ?? 'home'));
    $routes = [
        'login'       => 'auth',
        'logout'      => 'auth',
        'unimpersonate' => 'auth',    // zurück zum Admin (POST)
        's'           => 'auth',      // Freigabelink
        'account'     => 'auth',      // eigenes Passwort ändern
        'forgot'      => 'auth',      // Passwort vergessen (öffentlich)
        'reset'       => 'auth',      // neues Passwort per Link (öffentlich)
        'lang'        => 'auth',      // Sprache wählen (öffentlich, POST)
        'setup'       => 'setup',     // Ersteinrichtung (nur ohne Benutzer)
        'pubimg'      => 'gallery',   // Login-Bilder (öffentlich)
        'home'        => 'memorial',
        'memorial'    => 'memorial',
        'pluginasset' => 'plugins',   // Assets aktiver Plugins
        'checklist'   => 'checklist',
        'contacts'    => 'contacts',
        'rsvp'        => 'contacts',
        'condolences' => 'contacts',
        'gallery'     => 'gallery',
        'img'         => 'gallery',
        'admin'       => 'admin',
    ];
    $public = ['login', 's', 'pubimg', 'setup', 'forgot', 'reset', 'lang'];

    $pluginRoute = !isset($routes[$r]) && Plugins::hasRoute($r);
    if (!isset($routes[$r]) && !$pluginRoute) {
        abort(404);
    }
    $apiRoute = $pluginRoute && Plugins::isApiRoute($r);
    $needSetup = Setup::needed();
    if ($needSetup && $r !== 'setup') {
        redirect('setup');
    }
    if (!$needSetup && $r === 'setup') {
        abort(404);
    }
    if (!$apiRoute && !in_array($r, $public, true) && !Auth::check()) {
        if ($r === 'home') {
            redirect('login');
        }
        if (is_ajax()) {
            json_out(['ok' => false, 'error' => t('error.not_logged_in')], 401);
        }
        redirect('login');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$apiRoute) {
        Csrf::check();
    }

    if ($pluginRoute) {
        Plugins::call($r, (string)($_GET['a'] ?? 'index'));
        return;
    }
    require APP_DIR . '/controllers/' . $routes[$r] . '.php';
    $fn = 'ctrl_' . $routes[$r];
    $fn($r, (string)($_GET['a'] ?? 'index'));
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
}
