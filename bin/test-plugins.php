<?php
declare(strict_types=1);

/**
 * Tests für Plugin-Kern (Archiv, Aktivieren, Update, Rollback, Löschen) und GitHub-Client (Suche, Releases, Hash- und
 * Host-Prüfung, Token) gegen einen lokalen GitHub-Mock (bin/github-mock.php).
 * Das Skript arbeitet in einer temporären Kopie des Projekts; data/ und plugins/ des Projekts bleiben unberührt.
 * Aufruf: php bin/test-plugins.php        (Port wählbar mit MOCK_PORT=18091; Exit-Code 1 bei Fehlern)
 */
if (($argv[1] ?? '') === '--run') {
    exit(run_suite($argv[2], $argv[3], (int)$argv[4], $argv[5]));
}

$root = dirname(__DIR__);
$port = (int)(getenv('MOCK_PORT') ?: 18091);
$tmp = sys_get_temp_dir() . '/funeralorg-test-' . bin2hex(random_bytes(4));
$mockDir = $tmp . '/mock';
mkdir($mockDir, 0777, true);

$mock = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", $root . '/bin/github-mock.php'],
    [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
    $pipes,
    null,
    ['MOCK_DIR' => $mockDir, 'MOCK_PORT' => (string)$port, 'PATH' => (string)getenv('PATH')]
);
$fails = 0;
try {
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    // jede Suite bekommt eine frische Kopie (eigener Zustand, eigene Konstanten)
    foreach (['core', 'market'] as $suite) {
        $app = "$tmp/$suite";
        mkdir("$app/data", 0777, true);
        foreach (['app', 'plugins', 'VERSION'] as $item) {
            exec('cp -r ' . escapeshellarg("$root/$item") . ' ' . escapeshellarg($app . '/'));
        }
        mkdir("$app/configs");
        copy("$root/configs/config.sample.php", "$app/configs/config.sample.php"); // nie die echte config.php kopieren
        exec('rm -rf ' . escapeshellarg("$app/plugins/_archive"));
        echo "Plugin-Tests: $suite\n";
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --run ' . escapeshellarg($app) . ' ' . escapeshellarg($suite)
            . " $port " . escapeshellarg($mockDir), $code);
        $fails += $code === 0 ? 0 : 1;
    }
} finally {
    if (is_resource($mock)) {
        proc_terminate($mock);
        proc_close($mock);
    }
    exec('rm -rf ' . escapeshellarg($tmp));
}
echo $fails === 0 ? "\nAlle Plugin-Tests bestanden.\n" : "\n$fails Suite(s) mit Fehlern.\n";
exit($fails === 0 ? 0 : 1);

/* ---------------------------------------------------------------- Hilfen */

function run_suite(string $app, string $suite, int $port, string $mockDir): int
{
    define('ROOT_DIR', $app);
    define('APP_DIR', $app . '/app');
    define('DATA_DIR', $app . '/data');
    $GLOBALS['CFG'] = (require $app . '/configs/config.sample.php') + ['market_api' => "http://127.0.0.1:$port"];
    mb_internal_encoding('UTF-8');
    date_default_timezone_set('Europe/Berlin');
    spl_autoload_register(function (string $c): void {
        $f = APP_DIR . '/lib/' . $c . '.php';
        is_file($f) ? require $f : Plugins::autoload($c);
    });
    require APP_DIR . '/helpers.php';

    $GLOBALS['ok'] = $GLOBALS['bad'] = 0;
    $GLOBALS['mockDir'] = $mockDir;
    $GLOBALS['port'] = $port;
    match ($suite) {
        'core' => suite_core(),
        'hook' => suite_hook(),
        'market' => suite_market(),
    };
    echo "  {$GLOBALS['ok']} ok, {$GLOBALS['bad']} Fehler\n";
    return $GLOBALS['bad'] === 0 ? 0 : 1;
}

function chk(string $what, bool $cond, string $extra = ''): void
{
    $GLOBALS[$cond ? 'ok' : 'bad']++;
    echo $cond ? "  ok   $what\n" : "  FAIL $what $extra\n";
}

function thrown(callable $fn): ?RuntimeException
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        return $e;
    }
    return null;
}

/** Testpaket mit Manifest und einem Hook, der den Update-Aufruf in data/ vermerkt. */
function make_zip(string $path, string $slug, string $ver): void
{
    $z = new ZipArchive();
    $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString("$slug/plugin.json", json_encode(['slug' => $slug, 'name' => "Test $slug", 'version' => $ver,
        'stores' => ['tp_' . str_replace('-', '_', $slug) => ['x' => 1]]]));
    $z->addFromString("$slug/plugin.php", "<?php\nreturn function (PluginApi \$p): void {\n"
        . " \$p->onUpdate(function (string \$from): void { file_put_contents(DATA_DIR . '/upd.txt', \$from . '>$ver'); });\n};\n");
    $z->close();
}

/* ---------------------------------------------------------------- Kern */

function suite_core(): void
{
    $arch = ROOT_DIR . '/plugins/_archive';
    $tmp = sys_get_temp_dir() . '/pt-' . bin2hex(random_bytes(3));
    mkdir($tmp);
    $sha = fn(string $f): string => (string)hash_file('sha256', $f);
    $repo = 'me/funeral-org-demo-plug';

    make_zip("$tmp/a1.zip", 'demo-plug', '1.0.0');
    [$slug, $err] = Plugins::archive("$tmp/a1.zip", $repo, 'deadbeef');
    chk('falscher Hash wird abgelehnt', $slug === null && $err !== '');
    [$slug] = Plugins::archive("$tmp/a1.zip", 'kaputt', $sha("$tmp/a1.zip"));
    chk('ungültiges Repo wird abgelehnt', $slug === null);
    [$slug, $err] = Plugins::archive("$tmp/a1.zip", $repo, $sha("$tmp/a1.zip"));
    chk('archivieren', $slug === 'demo-plug', $err);
    chk('ZIP im Archiv, kein Ordner', is_file("$arch/demo-plug-1.0.0.zip") && !is_dir(ROOT_DIR . '/plugins/demo-plug'));
    chk('all() kennt archiviertes Plugin', (Plugins::all()['demo-plug']['archived'] ?? false) === true);
    chk('archiviert = nicht aktiv', !Plugins::isActive('demo-plug'));
    chk('doppelt archivieren abgelehnt', Plugins::archive("$tmp/a1.zip", $repo, $sha("$tmp/a1.zip"))[0] === null);

    chk('aktivieren aus dem Archiv', Plugins::setActive('demo-plug', true) === null);
    chk('Ordner entpackt, Plugin geladen', is_file(ROOT_DIR . '/plugins/demo-plug/plugin.php') && Plugins::isLoaded('demo-plug'));

    make_zip("$tmp/a2.zip", 'demo-plug', '1.1.0');
    chk('Update aus anderem Repo abgelehnt', Plugins::update("$tmp/a2.zip", 'other/repo')[0] === null);
    chk('gleiche Version ist kein Update', Plugins::update("$tmp/a1.zip", $repo)[0] === null);
    [$u, $err] = Plugins::update("$tmp/a2.zip", $repo, $sha("$tmp/a2.zip"));
    chk('Update des aktiven Plugins', $u === 'demo-plug', $err);
    chk('Ordner hat neue Version', str_contains((string)file_get_contents(ROOT_DIR . '/plugins/demo-plug/plugin.json'), '1.1.0'));
    $src = Plugins::source('demo-plug');
    chk('Herkunft aktualisiert, Update-Hook vorgemerkt', $src['version'] === '1.1.0' && $src['pending_from'] === '1.0.0' && $src['previous'] === '1.0.0');
    chk('alte ZIP bleibt als Rückfall', is_file("$arch/demo-plug-1.0.0.zip") && is_file("$arch/demo-plug-1.1.0.zip"));
    chk('keine .old/.tmp-Reste', !glob(ROOT_DIR . '/plugins/.old-*') && !glob(ROOT_DIR . '/plugins/.tmp-*'));

    make_zip("$tmp/m.zip", 'manual-x', '1.0.0');
    [$ms] = Plugins::install("$tmp/m.zip");
    chk('manueller Import bleibt ein Ordner', $ms === 'manual-x' && is_dir(ROOT_DIR . '/plugins/manual-x') && !Plugins::all()['manual-x']['archived']);
    chk('manuell importiert: kein Marktplatz-Update', Plugins::update("$tmp/m.zip", 'me/x')[0] === null);
    chk('manuell: Aktivieren, Deaktivieren, Löschen wie bisher', Plugins::setActive('manual-x', true) === null && Plugins::setActive('manual-x', false) === null
        && Plugins::delete('manual-x', false) === null && !is_dir(ROOT_DIR . '/plugins/manual-x'));

    echo "  --- neue Anfrage (Update-Hook)\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --run ' . escapeshellarg(ROOT_DIR) . ' hook '
        . ($GLOBALS['port']) . ' ' . escapeshellarg($GLOBALS['mockDir']), $code);
    $GLOBALS['bad'] += $code === 0 ? 0 : 1;
}

function suite_hook(): void
{
    $arch = ROOT_DIR . '/plugins/_archive';
    Plugins::boot();
    chk('Plugin lädt nach Update', Plugins::isLoaded('demo-plug'));
    chk('onUpdate lief mit der alten Version', @file_get_contents(DATA_DIR . '/upd.txt') === '1.0.0>1.1.0');
    chk('pending_from gelöscht', !isset(Plugins::source('demo-plug')['pending_from']));

    chk('Rollback auf 1.0.0', Plugins::rollback('demo-plug') === null && Plugins::source('demo-plug')['version'] === '1.0.0');
    chk('Rollback: Ordner zurückgesetzt, neuere ZIP entfernt', str_contains((string)file_get_contents(ROOT_DIR . '/plugins/demo-plug/plugin.json'), '1.0.0')
        && !is_file("$arch/demo-plug-1.1.0.zip"));
    chk('Rollback nur einmal möglich', Plugins::rollback('demo-plug') !== null);

    chk('deaktivieren löscht den Ordner', Plugins::setActive('demo-plug', false) === null && !is_dir(ROOT_DIR . '/plugins/demo-plug'));
    chk('ZIP bleibt, Eintrag wieder archiviert', is_file("$arch/demo-plug-1.0.0.zip") && (Plugins::all()['demo-plug']['archived'] ?? false));
    $orig = (string)file_get_contents("$arch/demo-plug-1.0.0.zip");
    file_put_contents("$arch/demo-plug-1.0.0.zip", $orig . 'x');
    chk('manipuliertes Archiv wird nicht aktiviert', Plugins::setActive('demo-plug', true) !== null && !is_dir(ROOT_DIR . '/plugins/demo-plug'));
    file_put_contents("$arch/demo-plug-1.0.0.zip", $orig);
    chk('intaktes Archiv aktiviert wieder', Plugins::setActive('demo-plug', true) === null && Plugins::isLoaded('demo-plug'));
    Plugins::setActive('demo-plug', false);
    [$tmp] = Plugins::export('demo-plug');
    chk('Export archivierter Plugins = ZIP-Kopie', $tmp !== null && hash_file('sha256', $tmp) === hash_file('sha256', "$arch/demo-plug-1.0.0.zip"));
    $tmp !== null && @unlink($tmp);
    chk('löschen mit Daten', Plugins::delete('demo-plug', true) === null);
    chk('Archiv und Herkunft weg', !glob("$arch/demo-plug-*.zip") && Plugins::source('demo-plug') === null && !isset(Plugins::all()['demo-plug']));
}

/* ---------------------------------------------------------------- GitHub-Client */

function suite_market(): void
{
    $md = $GLOBALS['mockDir'];
    $log = fn(): string => (string)@file_get_contents("$md/log.txt");
    $repo = 'me/funeral-org-demo-plug';

    $res = PluginMarket::search('demo');
    chk('Suche liefert 3 Repos', count($res) === 3 && $res[0]['repo'] === $repo && $res[0]['installed'] === '');

    $rel = PluginMarket::releases($repo);
    chk('Releases: Draft, Pre-Release und unvollständige fehlen', array_column($rel, 'version') === ['9.0.0', '1.1.0', '1.0.0']);
    $by = array_column($rel, null, 'version');
    chk('9.0.0 ist inkompatibel (mit Grund)', !$by['9.0.0']['compatible'] && $by['9.0.0']['reason'] !== '');
    chk('1.1.0 kompatibel, Kennung aus dem ZIP-Namen', $by['1.1.0']['compatible'] && $by['1.1.0']['slug'] === 'demo-plug');
    chk('Release-Notizen bleiben Rohtext (Ausgabe nur über e())', str_contains($by['1.1.0']['notes'], '<script>'));
    chk('latest() ohne Installiertes = 1.1.0', PluginMarket::latest($repo)['version'] === '1.1.0');
    chk('latest() mit 1.1.0 installiert = null', PluginMarket::latest($repo, '1.1.0') === null);

    // Cache altern lassen: dann muss bedingt (If-None-Match) angefragt werden
    foreach (glob(DATA_DIR . '/cache/market/*.json') as $f) {
        if (!str_starts_with(basename($f), 'm-') && basename($f) !== 'rate.json') {
            $c = json_decode((string)file_get_contents($f), true);
            $c['time'] = time() - 7200;
            file_put_contents($f, json_encode($c));
        }
    }
    file_put_contents("$md/log.txt", '');
    PluginMarket::releases($repo);
    chk('abgelaufener Cache: If-None-Match wird gesendet', str_contains($log(), '/releases - "rel-1"'), $log());
    file_put_contents("$md/log.txt", '');
    PluginMarket::releases($repo);
    chk('frischer Cache, Manifeste dauerhaft: keine Anfrage', trim($log()) === '', $log());
    chk('API-Kontingent wird gemerkt', (PluginMarket::rate()['remaining'] ?? -1) === 59);

    $f = PluginMarket::fetch($repo, '1.0.0');
    chk('fetch folgt relativer Weiterleitung, Hash stimmt', is_file($f['zip']) && $f['manifest']['slug'] === 'demo-plug');
    [$slug, $err] = Plugins::archive($f['zip'], $repo, $f['sha'], ['slug' => $f['release']['slug'], 'version' => '1.0.0']);
    chk('archive() mit Release-Prüfsumme', $slug === 'demo-plug', $err);
    chk('Version im Paket != Tag wird abgelehnt', Plugins::archive($f['zip'], $repo, $f['sha'], ['slug' => 'demo-plug', 'version' => '9.9.9'])[0] === null);
    PluginMarket::discard($f['zip']);
    chk('discard löscht die Temp-ZIP', !is_file($f['zip']));
    $keep = DATA_DIR . '/keep.txt';
    file_put_contents($keep, 'x');
    PluginMarket::discard($keep);
    chk('discard fasst fremde Dateien nicht an', is_file($keep));

    $cu = PluginMarket::checkUpdates();
    chk('checkUpdates findet 1.1.0 (nicht 9.0.0)', ($cu['updates']['demo-plug']['version'] ?? '') === '1.1.0' && !$cu['errors'], json_encode($cu));
    chk('neue Berechtigung verlangt Rückfrage', ($cu['updates']['demo-plug']['confirm'] ?? []) === ['mail']);
    chk('updates() merkt das Ergebnis', isset(PluginMarket::updates()['demo-plug']));
    chk('Audit: annotiertes Tag aufgelöst, anderer Stand', PluginMarket::audit($repo, 'v1.0.0', Plugins::source('demo-plug')['manifest'])['status'] === 'different');
    $f2 = PluginMarket::fetch($repo, '1.1.0');
    chk('Audit: passt', PluginMarket::audit($repo, 'v1.1.0', $f2['manifest'])['status'] === 'match');
    chk('Audit: keins angegeben', PluginMarket::audit('x/y', 'v1', ['audit' => ['commit' => '']])['status'] === 'none');
    [$u, $err] = Plugins::update($f2['zip'], $repo, $f2['sha'], ['slug' => 'demo-plug', 'version' => '1.1.0']);
    PluginMarket::discard($f2['zip']);
    chk('update() aus dem Release', $u === 'demo-plug', $err);
    chk('Update-Merker gelöscht', PluginMarket::updates() === []);
    Plugins::rollback('demo-plug');
    PluginMarket::checkUpdates();
    chk('nach Rollback wird 1.1.0 nicht erneut angeboten', PluginMarket::updates() === []);

    $e = thrown(fn() => PluginMarket::fetch('me/funeral-org-bad', '1.0.0'));
    chk('falsche Prüfsumme bricht ab, keine Temp-Datei bleibt', $e !== null && !glob(DATA_DIR . '/tmp/market-*.zip'), (string)$e?->getMessage());
    chk('Asset auf fremdem Host: Release wird ignoriert', array_column(PluginMarket::releases('me/funeral-org-bad'), 'version') === ['1.0.0']);
    $e = thrown(fn() => PluginMarket::fetch('me/funeral-org-redir', '1.0.0'));
    chk('Weiterleitung auf fremden Host wird nicht verfolgt', $e !== null && $e->getMessage() === t('market.err_host'), (string)$e?->getMessage());
    $e = thrown(fn() => PluginMarket::releases('me/funeral-org-limited'));
    chk('erschöpftes Kontingent = Code 429', $e !== null && $e->getCode() === 429, (string)$e?->getMessage());
    chk('unbekanntes Repository = nicht gefunden', thrown(fn() => PluginMarket::releases('me/gibtsnicht')) !== null);

    foreach (['../x', 'a/..', 'a/.', '-a/b', 'a.b/c', 'a/b/c', 'a b/c', '/x', 'a/'] as $x) {
        chk("Repo '$x' abgelehnt", !preg_match(Plugins::REPO_RE, $x) && thrown(fn() => PluginMarket::releases($x)) !== null);
    }
    foreach (['me/funeral-org-x', 'A1/b_c.d', 'a/.github'] as $x) {
        chk("Repo '$x' erlaubt", (bool)preg_match(Plugins::REPO_RE, $x));
    }

    // Selbsttest des Plugin-Verzeichnisses: der Mock liefert für /plugins/… 404 (= geschützt); ein toter Port ist „nicht prüfbar“
    $GLOBALS['CFG']['base_url'] = 'http://127.0.0.1:' . $GLOBALS['port'];
    chk('Selbsttest: 404 = geschützt', PluginExposure::run()['status'] === 'protected' && PluginExposure::last()['status'] === 'protected');
    chk('Selbsttest: Testdateien werden wieder gelöscht', !glob(ROOT_DIR . '/plugins/selftest-*.txt') && !glob(ROOT_DIR . '/plugins/_archive/selftest-*.txt'));
    $GLOBALS['CFG']['base_url'] = 'http://127.0.0.1:9';
    chk('Selbsttest: nicht erreichbar = nicht prüfbar', PluginExposure::run()['status'] === 'unknown');
    unset($GLOBALS['CFG']['base_url']);

    chk('ungültiges Token wird abgelehnt', !PluginMarket::setToken('kurz'));
    chk('Token speichern', PluginMarket::setToken('ghp_abcdefghijklmnopqrstuvwxyz0123456789') && PluginMarket::tokenSource() === 'store');
    file_put_contents("$md/log.txt", '');
    PluginMarket::search('token');
    $api = array_filter(explode("\n", $log()), fn($l) => str_starts_with($l, '/search/'));
    chk('Token geht an die API', $api && !array_filter($api, fn($l) => !str_contains($l, 'Bearer ghp_')));
    // Die Mock-Umlenkung gilt für den Test; die Produktivregeln prüfen wir ohne sie
    unset($GLOBALS['CFG']['market_api']);
    $isApi = new ReflectionMethod(PluginMarket::class, 'isApiHost');
    $allowed = new ReflectionMethod(PluginMarket::class, 'allowed');
    chk('Token nur an api.github.com', $isApi->invoke(null, 'https://api.github.com/repos/a/b') && !$isApi->invoke(null, 'https://github.com/a/b/releases/download/v1/x.zip')
        && !$isApi->invoke(null, 'https://objects.githubusercontent.com/x') && !$isApi->invoke(null, 'https://release-assets.githubusercontent.com/x'));
    chk('Whitelist: erlaubte Hosts', $allowed->invoke(null, 'https://github.com/a') && $allowed->invoke(null, 'https://objects.githubusercontent.com/a')
        && $allowed->invoke(null, 'https://release-assets.githubusercontent.com/a'));
    chk('Whitelist: fremd, HTTP, Port, Userinfo und Lookalikes abgelehnt', !$allowed->invoke(null, 'https://evil.example/a') && !$allowed->invoke(null, 'http://github.com/a')
        && !$allowed->invoke(null, 'https://github.com:8443/a') && !$allowed->invoke(null, 'https://user@github.com/a')
        && !$allowed->invoke(null, 'https://github.com.evil.example/a') && !$allowed->invoke(null, 'https://evilgithub.com/a')
        && !$allowed->invoke(null, 'https://127.0.0.1:18777/a'));
    chk('Token entfernen', PluginMarket::setToken('') && PluginMarket::tokenSource() === '');
}
