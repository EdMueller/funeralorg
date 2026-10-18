<?php
declare(strict_types=1);

/**
 * Plugin-System. Ein Plugin ist ein Ordner `plugins/<slug>/` mit Manifest `plugin.json` und Einstiegspunkt
 * `plugin.php` (siehe PluginApi). Der Zustand (aktiv/inaktiv) steht in `settings.json → plugins.<slug>.active`;
 * fehlt er, gilt `default_active` aus dem Manifest.
 *
 * Aus der Ordnerstruktur ergibt sich automatisch (nur bei aktiven Plugins):
 *   lib/<Klasse>.php        Autoload (nach den Klassen des Kerns)
 *   views/<name>.php        view('@<slug>/<name>')
 *   assets/…                plugin_asset('<slug>', '<pfad>') über die Route `pluginasset`
 *   lang/<sprache>/*.php    Sprachdateien, ergänzen die des Kerns (Kern-Schlüssel haben Vorrang)
 *   Manifest "stores"       Datendateien data/<name>.json mit Standardinhalt
 *
 * Ein Plugin ist beliebiger PHP-Code mit vollem Zugriff – Import und Verwaltung nur für Admins.
 */
final class Plugins
{
    public const SLUG_RE = '/^[a-z][a-z0-9-]{1,39}$/';
    public const MAX_ZIP = 10 * 1024 * 1024;
    public const MAX_UNPACKED = 20 * 1024 * 1024;
    public const MAX_FILES = 300;

    /** Dateitypen, die ein importiertes Plugin enthalten darf. */
    private const FILE_EXT = ['php', 'json', 'js', 'css', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'woff', 'woff2', 'txt', 'md'];
    /** Dateien ohne Endung, die erlaubt sind. */
    private const PLAIN_NAMES = ['LICENSE', 'README', 'NOTICE'];
    /** Auslieferbare Assets (Route `pluginasset`). */
    public const ASSET_MIME = [
        'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
    ];

    /** @var array<string, array{slug:string, dir:string, ok:bool, error:string, m:array}>|null */
    private static ?array $all = null;
    private static bool $booted = false;
    /** @var array<string, true> */
    private static array $loaded = [];
    /** @var array<string, string> Grund, warum ein aktives Plugin nicht geladen wurde */
    private static array $problems = [];
    /** @var array<string, array{slug:string, dir:string, file:string, fn:string}> */
    private static array $routes = [];
    /** @var list<array{slug:string, route:string, label:string, icon:string, visible:?callable, after:string}> */
    private static array $nav = [];
    /** @var array<string, array<string, list<callable>>> */
    private static array $hooks = [];

    public static function dir(): string
    {
        return ROOT_DIR . '/plugins';
    }

    /* ------------------------------------------------------------ Laden */

    /** Lädt alle aktiven Plugins (einmal je Anfrage, vor dem Routing). */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        if (cfg('plugins_safe_mode', false)) {
            return; // Notausgang: kein Plugin-Code wird ausgeführt
        }
        foreach (array_keys(self::all()) as $slug) {
            self::load($slug);
        }
        I18n::reset();
    }

    /** @param list<string> $stack */
    public static function load(string $slug, array $stack = []): bool
    {
        if (isset(self::$loaded[$slug])) {
            return true;
        }
        $p = self::all()[$slug] ?? null;
        if ($p === null || !$p['ok'] || !self::isActive($slug) || in_array($slug, $stack, true)) {
            return false;
        }
        $why = self::requirementProblem($p);
        if ($why === '') {
            foreach ($p['m']['requires'] as $dep) {
                if (!self::load($dep, [...$stack, $slug])) {
                    $why = t('plugins.err_deps_inactive', ['names' => $dep]);
                    break;
                }
            }
        }
        if ($why === '' && !is_file($p['dir'] . '/plugin.php')) {
            $why = t('plugins.err_noentry');
        }
        if ($why !== '') {
            self::$problems[$slug] = $why;
            return false;
        }
        foreach ($p['m']['stores'] as $name => $default) {
            Store::extend($name, $default);
        }
        self::$loaded[$slug] = true;
        try {
            $register = (static fn(string $file) => require $file)($p['dir'] . '/plugin.php');
            if (is_callable($register)) {
                $register(new PluginApi($slug, $p['dir']));
            }
        } catch (Throwable $e) {
            error_log('Plugin ' . $slug . ': ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
            self::forget($slug);
            self::$problems[$slug] = $e->getMessage();
            return false;
        }
        return true;
    }

    private static function forget(string $slug): void
    {
        unset(self::$loaded[$slug], self::$hooks[$slug]);
        self::$routes = array_filter(self::$routes, fn($r) => $r['slug'] !== $slug);
        self::$nav = array_values(array_filter(self::$nav, fn($n) => $n['slug'] !== $slug));
    }

    public static function isLoaded(string $slug): bool
    {
        return isset(self::$loaded[$slug]);
    }

    public static function problem(string $slug): string
    {
        return self::$problems[$slug] ?? '';
    }

    /** Autoload für Klassen aus `lib/` geladener Plugins. */
    public static function autoload(string $class): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class)) {
            return;
        }
        foreach (self::$loaded as $slug => $_) {
            $f = self::dir() . '/' . $slug . '/lib/' . $class . '.php';
            if (is_file($f)) {
                require $f;
                return;
            }
        }
    }

    /** Sprachdateien geladener Plugins für eine Sprache. @return list<string> */
    public static function langFiles(string $lang): array
    {
        $out = [];
        foreach (self::$loaded as $slug => $_) {
            foreach (glob(self::dir() . '/' . $slug . '/lang/' . $lang . '/*.php') ?: [] as $f) {
                $out[] = $f;
            }
        }
        return $out;
    }

    /** Pfad einer Plugin-View: `@slug/name`. */
    public static function viewFile(string $ref): string
    {
        if (!preg_match('~^@([a-z][a-z0-9-]{1,39})/([A-Za-z0-9_]+)$~', $ref, $m) || !isset(self::$loaded[$m[1]])) {
            throw new RuntimeException('Ungültige Plugin-View');
        }
        return self::dir() . '/' . $m[1] . '/views/' . $m[2] . '.php';
    }

    /** Datei unterhalb von `assets/` eines geladenen Plugins oder null (Schutz vor Pfadtricks). */
    public static function assetFile(string $slug, string $rel): ?string
    {
        if (!isset(self::$loaded[$slug]) || !preg_match('~^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*$~', $rel)
            || str_contains($rel, '..')) {
            return null;
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $base = realpath(self::dir() . '/' . $slug . '/assets');
        $file = realpath(self::dir() . '/' . $slug . '/assets/' . $rel);
        if (!isset(self::ASSET_MIME[$ext]) || !$base || !$file || !is_file($file)
            || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $file;
    }

    /* ------------------------------------------------------------ Registrierung (PluginApi) */

    public static function registerRoute(string $slug, string $dir, string $route, string $file, string $fn): void
    {
        if (!preg_match('/^[a-z]{2,24}$/', $route) || !preg_match('~^[A-Za-z0-9_/.-]+\.php$~', $file)
            || str_contains($file, '..') || !preg_match('/^[a-z][a-z0-9_]*$/', $fn)) {
            throw new InvalidArgumentException('Ungültige Route');
        }
        if (isset(self::$routes[$route])) {
            throw new InvalidArgumentException('Route bereits vergeben: ' . $route);
        }
        self::$routes[$route] = ['slug' => $slug, 'dir' => $dir, 'file' => $file, 'fn' => $fn];
    }

    public static function registerNav(string $slug, string $route, string $label, string $icon, ?callable $visible, string $after): void
    {
        if (!preg_match('/^[a-z]{2,24}$/', $route)) {
            throw new InvalidArgumentException('Ungültige Route');
        }
        $icon = preg_match('/^bi-[a-z0-9-]+$/', $icon) ? $icon : 'bi-puzzle';
        self::$nav[] = compact('slug', 'route', 'label', 'icon', 'visible', 'after');
    }

    public static function registerHook(string $slug, string $name, callable $fn): void
    {
        self::$hooks[$slug][$name][] = $fn;
    }

    private static function fire(string $slug, string $name): void
    {
        foreach (self::$hooks[$slug][$name] ?? [] as $fn) {
            $fn();
        }
    }

    /** Eine Route, die ein aktives Plugin bereitstellt (Kernrouten haben Vorrang, das prüft dispatch()). */
    public static function hasRoute(string $r): bool
    {
        return isset(self::$routes[$r]);
    }

    public static function call(string $r, string $a): void
    {
        $rt = self::$routes[$r] ?? null;
        $base = $rt ? realpath($rt['dir']) : false;
        $file = $rt ? realpath($rt['dir'] . '/' . $rt['file']) : false;
        if (!$rt || !$base || !$file || !is_file($file) || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            abort(404);
        }
        require_once $file;
        if (!is_callable($rt['fn'])) {
            abort(404);
        }
        ($rt['fn'])($r, $a);
    }

    /** Fügt die Menüpunkte der Plugins in die Navigation ein. @param array<string, array{0:string,1:string}> $nav */
    public static function applyNav(array $nav): array
    {
        foreach (self::$nav as $n) {
            if (isset($nav[$n['route']]) || ($n['visible'] !== null && !($n['visible'])())) {
                continue;
            }
            $entry = [t($n['label']), $n['icon']];
            if ($n['after'] !== '' && isset($nav[$n['after']])) {
                $out = [];
                foreach ($nav as $k => $v) {
                    $out[$k] = $v;
                    if ($k === $n['after']) {
                        $out[$n['route']] = $entry;
                    }
                }
                $nav = $out;
            } else {
                $nav[$n['route']] = $entry;
            }
        }
        return $nav;
    }

    /* ------------------------------------------------------------ Bestand und Zustand */

    /** @return array<string, array{slug:string, dir:string, ok:bool, error:string, m:array}> */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $out = [];
        foreach (glob(self::dir() . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            $slug = basename($d);
            if (!preg_match(self::SLUG_RE, $slug) || is_link($d)) {
                continue;
            }
            $json = @file_get_contents($d . '/plugin.json');
            [$m, $err] = self::parseManifest($json === false ? '' : $json, $slug);
            $out[$slug] = ['slug' => $slug, 'dir' => $d, 'ok' => $m !== null, 'error' => $err,
                'm' => $m ?? self::blankManifest($slug)];
        }
        ksort($out);
        return self::$all = $out;
    }

    private static function blankManifest(string $slug): array
    {
        return ['slug' => $slug, 'name' => $slug, 'version' => '', 'description' => '', 'author' => '',
            'requires_app' => '', 'requires' => [], 'stores' => [], 'core' => false, 'default_active' => false];
    }

    /** @return array{0:?array, 1:string} Manifest oder Fehlertext */
    public static function parseManifest(string $json, ?string $dirSlug): array
    {
        $d = json_decode($json, true);
        if (!is_array($d)) {
            return [null, t('plugins.err_manifest_json')];
        }
        $slug = (string)($d['slug'] ?? '');
        if (!preg_match(self::SLUG_RE, $slug)) {
            return [null, t('plugins.err_slug')];
        }
        if ($dirSlug !== null && $slug !== $dirSlug) {
            return [null, t('plugins.err_slug_dir', ['slug' => $slug, 'dir' => $dirSlug])];
        }
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            return [null, t('plugins.err_manifest_field', ['field' => 'name'])];
        }
        $version = (string)($d['version'] ?? '');
        if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version)) {
            return [null, t('plugins.err_manifest_field', ['field' => 'version'])];
        }
        $reqApp = (string)($d['requires_app'] ?? '');
        if ($reqApp !== '' && !preg_match('/^\d+\.\d+\.\d+$/', $reqApp)) {
            return [null, t('plugins.err_manifest_field', ['field' => 'requires_app'])];
        }
        $requires = $d['requires'] ?? [];
        if (!is_array($requires)) {
            return [null, t('plugins.err_manifest_field', ['field' => 'requires'])];
        }
        foreach ($requires as $dep) {
            if (!is_string($dep) || !preg_match(self::SLUG_RE, $dep) || $dep === $slug) {
                return [null, t('plugins.err_manifest_field', ['field' => 'requires'])];
            }
        }
        $stores = $d['stores'] ?? [];
        if (!is_array($stores)) {
            return [null, t('plugins.err_manifest_field', ['field' => 'stores'])];
        }
        foreach ($stores as $sn => $default) {
            if (!is_string($sn) || !preg_match('/^[a-z_]{2,40}$/', $sn) || isset(Store::DEFAULTS[$sn]) || !is_array($default)) {
                return [null, t('plugins.err_store', ['store' => (string)$sn])];
            }
        }
        return [[
            'slug' => $slug, 'name' => $name, 'version' => $version,
            'description' => mb_substr(trim((string)($d['description'] ?? '')), 0, 400),
            'author' => mb_substr(trim((string)($d['author'] ?? '')), 0, 80),
            'requires_app' => $reqApp, 'requires' => array_values(array_unique($requires)),
            'stores' => $stores, 'core' => !empty($d['core']), 'default_active' => !empty($d['default_active']),
        ], ''];
    }

    private static function settings(): array
    {
        return is_file(Store::file('settings')) ? Store::read('settings') : [];
    }

    public static function isActive(string $slug): bool
    {
        $st = self::settings()['plugins'][$slug] ?? null;
        if (is_array($st) && array_key_exists('active', $st)) {
            return (bool)$st['active'];
        }
        return (bool)(self::all()[$slug]['m']['default_active'] ?? false);
    }

    private static function saveState(string $slug, bool $active): void
    {
        Store::update('settings', function (array &$s) use ($slug, $active) {
            $s['plugins'][$slug] = ['active' => $active, 'changed' => now()];
        });
    }

    /** Anforderungen an die Anwendungsversion; '' = erfüllt. */
    private static function requirementProblem(array $p): string
    {
        $need = $p['m']['requires_app'];
        $have = app_version();
        if ($need !== '' && $have !== '' && version_compare($have, $need, '<')) {
            return t('plugins.err_requires_app', ['need' => $need, 'have' => $have]);
        }
        return '';
    }

    /** Aktive Plugins, die $slug voraussetzen. @return list<string> */
    public static function dependents(string $slug): array
    {
        $out = [];
        foreach (self::all() as $s => $p) {
            if ($p['ok'] && self::isActive($s) && in_array($slug, $p['m']['requires'], true)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /** Datendateien eines Plugins mit Größe (0 = noch nicht angelegt). @return array<string, int> */
    public static function dataFiles(string $slug): array
    {
        $out = [];
        foreach (array_keys(self::all()[$slug]['m']['stores'] ?? []) as $name) {
            $f = Store::file($name);
            $out[$name] = is_file($f) ? (int)filesize($f) : 0;
        }
        return $out;
    }

    /* ------------------------------------------------------------ Verwaltung (Admin) */

    /** Aktiviert oder deaktiviert ein Plugin. @return string|null Fehlertext oder null */
    public static function setActive(string $slug, bool $on): ?string
    {
        $p = self::all()[$slug] ?? null;
        if ($p === null) {
            return t('plugins.err_unknown');
        }
        if (!$p['ok']) {
            return $p['error'];
        }
        if ($on === self::isActive($slug)) {
            return null;
        }
        if ($on) {
            if (($why = self::requirementProblem($p)) !== '') {
                return $why;
            }
            $off = array_values(array_filter($p['m']['requires'], fn($d) => !(self::all()[$d]['ok'] ?? false) || !self::isActive($d)));
            if ($off) {
                return t('plugins.err_deps_inactive', ['names' => implode(', ', $off)]);
            }
            foreach (self::all() as $s => $o) {
                if ($s !== $slug && $o['ok'] && array_intersect_key($o['m']['stores'], $p['m']['stores'])) {
                    return t('plugins.err_store_clash', ['plugin' => $o['m']['name']]);
                }
            }
            self::saveState($slug, true);
            if (!self::load($slug)) {
                self::saveState($slug, false);
                return t('plugins.err_load', ['err' => self::problem($slug)]);
            }
            try {
                self::fire($slug, 'activate');
            } catch (Throwable $e) {
                error_log('Plugin ' . $slug . ' (activate): ' . $e->getMessage());
                self::saveState($slug, false);
                return t('plugins.err_load', ['err' => $e->getMessage()]);
            }
            return null;
        }
        $deps = self::dependents($slug);
        if ($deps) {
            return t('plugins.err_dependents', ['names' => implode(', ', array_map(fn($d) => self::all()[$d]['m']['name'], $deps))]);
        }
        try {
            self::fire($slug, 'deactivate');
        } catch (Throwable $e) {
            error_log('Plugin ' . $slug . ' (deactivate): ' . $e->getMessage());
        }
        self::saveState($slug, false);
        return null;
    }

    /**
     * Installiert ein Plugin aus einer ZIP-Datei (inaktiv).
     * @return array{0:?string, 1:string} Slug oder Fehlertext
     */
    public static function install(string $zipFile): array
    {
        if (!class_exists('ZipArchive')) {
            return [null, t('plugins.err_zip_open')];
        }
        if (!is_file($zipFile) || filesize($zipFile) > self::MAX_ZIP) {
            return [null, t('plugins.err_zip_size', ['mb' => (string)(self::MAX_ZIP / 1048576)])];
        }
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return [null, t('plugins.err_zip_open')];
        }
        try {
            return self::installFrom($zip);
        } finally {
            $zip->close();
        }
    }

    private static function installFrom(ZipArchive $zip): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_FILES) {
            return [null, t('plugins.err_zip_entries', ['max' => (string)self::MAX_FILES])];
        }
        // Einträge prüfen
        $files = [];   // Index => Name
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') {
                continue;
            }
            if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || $name[0] === '/'
                || preg_match('~(^|/)\.\.?(/|$)~', $name) || preg_match('/^[A-Za-z]:/', $name)) {
                return [null, t('plugins.err_zip_unsafe', ['name' => $name])];
            }
            $opsys = 0;
            $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX
                && ((($attr >> 16) & 0170000) === 0120000)) {
                return [null, t('plugins.err_zip_unsafe', ['name' => $name])];
            }
            if (str_ends_with($name, '/')) {
                continue;
            }
            $total += (int)($zip->statIndex($i)['size'] ?? 0);
            $files[$i] = $name;
        }
        if (!$files || $total > self::MAX_UNPACKED) {
            return [null, t('plugins.err_zip_size', ['mb' => (string)(self::MAX_UNPACKED / 1048576)])];
        }
        // Wurzel bestimmen: plugin.json im Hauptverzeichnis oder in genau einem Unterordner
        $prefix = null;
        if (in_array('plugin.json', $files, true)) {
            $prefix = '';
        } else {
            foreach ($files as $n) {
                if (preg_match('~^([^/]+/)plugin\.json$~', $n, $m)) {
                    if ($prefix !== null) {
                        return [null, t('plugins.err_zip_multiple')];
                    }
                    $prefix = $m[1];
                }
            }
        }
        if ($prefix === null) {
            return [null, t('plugins.err_zip_nomanifest')];
        }
        $rel = [];
        foreach ($files as $i => $n) {
            if ($prefix !== '' && !str_starts_with($n, $prefix)) {
                return [null, t('plugins.err_zip_multiple')];
            }
            $r = substr($n, strlen($prefix));
            foreach (explode('/', $r) as $seg) {
                if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9._ -]*$/', $seg)) {
                    return [null, t('plugins.err_zip_type', ['name' => $r])];
                }
            }
            $ext = strtolower(pathinfo($r, PATHINFO_EXTENSION));
            if (!in_array($ext, self::FILE_EXT, true) && !in_array($r, self::PLAIN_NAMES, true)) {
                return [null, t('plugins.err_zip_type', ['name' => $r])];
            }
            $rel[$i] = $r;
        }
        if (!in_array('plugin.php', $rel, true)) {
            return [null, t('plugins.err_zip_noplugin')];
        }
        [$m, $err] = self::parseManifest((string)$zip->getFromName($prefix . 'plugin.json'), null);
        if ($m === null) {
            return [null, $err];
        }
        $slug = $m['slug'];
        if (is_dir(self::dir() . '/' . $slug)) {
            return [null, t('plugins.err_exists', ['name' => $m['name']])];
        }
        $have = app_version();
        if ($m['requires_app'] !== '' && $have !== '' && version_compare($have, $m['requires_app'], '<')) {
            return [null, t('plugins.err_requires_app', ['need' => $m['requires_app'], 'have' => $have])];
        }
        foreach (self::all() as $o) {
            if ($o['ok'] && array_intersect_key($o['m']['stores'], $m['stores'])) {
                return [null, t('plugins.err_store_clash', ['plugin' => $o['m']['name']])];
            }
        }
        // Inhalte lesen und PHP-Syntax prüfen, bevor etwas auf die Platte geschrieben wird
        $content = [];
        foreach ($rel as $i => $r) {
            $data = $zip->getFromIndex($i);
            if ($data === false) {
                return [null, t('plugins.err_zip_open')];
            }
            if (strtolower(pathinfo($r, PATHINFO_EXTENSION)) === 'php') {
                try {
                    token_get_all($data, TOKEN_PARSE);
                } catch (ParseError $e) {
                    return [null, t('plugins.err_syntax', ['file' => $r, 'err' => $e->getMessage()])];
                }
            }
            $content[$r] = $data;
        }
        // Schreiben in ein verstecktes Nachbarverzeichnis, dann umbenennen (gleiches Dateisystem)
        $root = self::dir();
        if (!is_dir($root) && !@mkdir($root, 0750, true) && !is_dir($root)) {
            return [null, t('plugins.err_write')];
        }
        $tmp = $root . '/.tmp-' . bin2hex(random_bytes(6));
        try {
            foreach ($content as $r => $data) {
                $target = $tmp . '/' . $r;
                $dir = dirname($target);
                if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                    throw new RuntimeException('mkdir');
                }
                if (file_put_contents($target, $data) === false) {
                    throw new RuntimeException('write');
                }
                @chmod($target, 0640);
            }
            if (!@rename($tmp, $root . '/' . $slug)) {
                throw new RuntimeException('rename');
            }
        } catch (RuntimeException) {
            self::rmtree($tmp);
            return [null, t('plugins.err_write')];
        }
        self::saveState($slug, false);
        self::$all = null;
        return [$slug, ''];
    }

    /** Erstellt eine ZIP-Datei des Plugin-Ordners (ohne Nutzdaten). @return array{0:?string, 1:string} Pfad, Dateiname */
    public static function export(string $slug): array
    {
        $p = self::all()[$slug] ?? null;
        if ($p === null) {
            return [null, ''];
        }
        $tmpDir = DATA_DIR . '/tmp';
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0750, true) && !is_dir($tmpDir)) {
            return [null, ''];
        }
        $tmp = $tmpDir . '/plugin-' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return [null, ''];
        }
        $len = strlen($p['dir']) + 1;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p['dir'], FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && !$f->isLink()) {
                $zip->addFile($f->getPathname(), $slug . '/' . str_replace('\\', '/', substr($f->getPathname(), $len)));
            }
        }
        $zip->close();
        return [$tmp, $slug . ($p['m']['version'] !== '' ? '-' . $p['m']['version'] : '') . '.zip'];
    }

    /**
     * Löscht ein inaktives Plugin (Kern-Plugins nie). Mit $withData auch seine Datendateien (vorher Snapshot).
     * @return string|null Fehlertext oder null
     */
    public static function delete(string $slug, bool $withData): ?string
    {
        $p = self::all()[$slug] ?? null;
        if ($p === null) {
            return t('plugins.err_unknown');
        }
        if ($p['m']['core']) {
            return t('plugins.err_core_delete');
        }
        if (self::isActive($slug)) {
            return t('plugins.err_active_delete');
        }
        $stores = array_keys($p['m']['stores']);
        if ($withData && $stores) {
            Store::dailyBackup(true);
            foreach ($stores as $name) {
                @unlink(Store::file($name));
                @unlink(DATA_DIR . '/' . $name . '.lock');
            }
        }
        $base = realpath(self::dir());
        $dir = realpath($p['dir']);
        if (!$base || !$dir || !str_starts_with($dir, $base . DIRECTORY_SEPARATOR)) {
            return t('plugins.err_write');
        }
        self::rmtree($dir);
        Store::update('settings', function (array &$s) use ($slug) {
            unset($s['plugins'][$slug]);
        });
        self::$all = null;
        return is_dir($dir) ? t('plugins.err_write') : null;
    }

    /** Löscht ein Verzeichnis samt Inhalt; Verknüpfungen werden entfernt, nicht verfolgt. */
    private static function rmtree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if ($f->isDir() && !$f->isLink()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($dir);
    }
}
