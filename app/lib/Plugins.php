<?php
declare(strict_types=1);

/**
 * Plugin-System. Ein Plugin ist ein Ordner `plugins/<slug>/` mit Manifest `plugin.json` und Einstiegspunkt
 * `plugin.php` (siehe PluginApi). Der Zustand (aktiv/inaktiv) steht in `settings.json → plugins.<slug>.active`;
 * fehlt er, gilt `default_active` aus dem Manifest.
 *
 * Marktplatz-Plugins (Eintrag in `market.json → sources.<slug>`) liegen inaktiv nur als ZIP in `plugins/_archive/`.
 * Aktivieren prüft den SHA-256-Hash, entpackt nach `plugins/.tmp-*` und benennt per rename() um; Deaktivieren löscht
 * den Ordner, die ZIP bleibt. Alles andere (mitgelieferte und manuell importierte Plugins) bleibt ein Ordner.
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
    public const VERSION_RE = '/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/';
    /** Erlaubte Einträge in `permissions` (rein informativ, kein Schutz – Plugins laufen ohne Sandbox). */
    public const PERMISSION_RE = '/^(network:[a-z0-9]([a-z0-9.-]*[a-z0-9])?|mail|files|data:(users|contacts|gallery|memorial|checklist))$/';
    /** GitHub-Repository `besitzer/name`: Besitzer nur Buchstaben, Ziffern, Bindestrich; Name nie `.` oder `..` (kein Pfadtrick in API-Adressen). */
    public const REPO_RE = '~^[A-Za-z0-9][A-Za-z0-9-]{0,38}/(?!\.{1,2}$)[A-Za-z0-9_.-]{1,100}$~';

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
        self::runPendingUpdate($slug);
        return true;
    }

    /** Nach einem Update einmalig die Update-Hooks des neuen Codes mit der alten Version ausführen. */
    private static function runPendingUpdate(string $slug): void
    {
        $from = (string)(self::source($slug)['pending_from'] ?? '');
        if ($from === '') {
            return;
        }
        try {
            self::fire($slug, 'update', $from);
        } catch (Throwable $e) {
            error_log('Plugin ' . $slug . ' (update): ' . $e->getMessage());
        }
        Store::update('market', function (array &$d) use ($slug) {
            unset($d['sources'][$slug]['pending_from']);
        });
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

    public static function registerRoute(string $slug, string $dir, string $route, string $file, string $fn, bool $api = false): void
    {
        if (!preg_match('/^[a-z]{2,24}$/', $route) || !preg_match('~^[A-Za-z0-9_/.-]+\.php$~', $file)
            || str_contains($file, '..') || !preg_match('/^[a-z][a-z0-9_]*$/', $fn)) {
            throw new InvalidArgumentException('Ungültige Route');
        }
        if (isset(self::$routes[$route])) {
            throw new InvalidArgumentException('Route bereits vergeben: ' . $route);
        }
        self::$routes[$route] = ['slug' => $slug, 'dir' => $dir, 'file' => $file, 'fn' => $fn, 'api' => $api];
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

    private static function fire(string $slug, string $name, mixed ...$args): void
    {
        foreach (self::$hooks[$slug][$name] ?? [] as $fn) {
            $fn(...$args);
        }
    }

    /** Eine Route, die ein aktives Plugin bereitstellt (Kernrouten haben Vorrang, das prüft dispatch()). */
    public static function hasRoute(string $r): bool
    {
        return isset(self::$routes[$r]);
    }

    /** API-Route: ohne Sitzung und CSRF-Token; der Controller prüft die Anmeldung selbst (siehe PluginApi::apiRoute). */
    public static function isApiRoute(string $r): bool
    {
        return !empty(self::$routes[$r]['api']);
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

    /** @return array<string, array{slug:string, dir:string, ok:bool, error:string, m:array, archived:bool}> */
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
                'm' => $m ?? self::blankManifest($slug), 'archived' => false];
        }
        // Marktplatz-Plugins, die inaktiv nur als ZIP im Archiv liegen (Manifest aus der Herkunft, ohne die ZIP zu öffnen)
        foreach (self::sources() as $slug => $src) {
            $slug = (string)$slug;
            if (isset($out[$slug]) || !preg_match(self::SLUG_RE, $slug) || !preg_match(self::VERSION_RE, (string)($src['version'] ?? ''))
                || !is_file(self::archiveFile($slug, (string)$src['version']))) {
                continue;
            }
            $m = is_array($src['manifest'] ?? null) ? array_replace(self::blankManifest($slug), $src['manifest']) : null;
            $out[$slug] = ['slug' => $slug, 'dir' => '', 'ok' => $m !== null, 'error' => $m === null ? t('plugins.err_manifest_json') : '',
                'm' => $m ?? self::blankManifest($slug), 'archived' => true];
        }
        ksort($out);
        return self::$all = $out;
    }

    private static function blankManifest(string $slug): array
    {
        return ['slug' => $slug, 'name' => $slug, 'version' => '', 'description' => '', 'author' => '',
            'requires_app' => '', 'requires' => [], 'stores' => [], 'core' => false, 'default_active' => false,
            'requires_php' => '', 'permissions' => [], 'repository' => '', 'license' => '', 'audit' => ['commit' => '', 'report' => '']];
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
        if (!preg_match(self::VERSION_RE, $version)) {
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
        $reqPhp = (string)($d['requires_php'] ?? '');
        if ($reqPhp !== '' && !preg_match('/^\d+\.\d+(\.\d+)?$/', $reqPhp)) {
            return [null, t('plugins.err_manifest_field', ['field' => 'requires_php'])];
        }
        $perms = $d['permissions'] ?? [];
        if (!is_array($perms) || array_filter($perms, fn($x) => !is_string($x) || !preg_match(self::PERMISSION_RE, $x))) {
            return [null, t('plugins.err_manifest_field', ['field' => 'permissions'])];
        }
        $repo = (string)($d['repository'] ?? '');
        if ($repo !== '' && !preg_match(self::REPO_RE, $repo)) {
            return [null, t('plugins.err_manifest_field', ['field' => 'repository'])];
        }
        $audit = $d['audit'] ?? [];
        $commit = is_array($audit) ? (string)($audit['commit'] ?? '') : '-';
        $report = is_array($audit) ? (string)($audit['report'] ?? '') : '-';
        if (!is_array($audit) || ($commit !== '' && !preg_match('/^[0-9a-f]{40}$/', $commit))
            || ($report !== '' && !preg_match('~^[A-Za-z0-9_][A-Za-z0-9_./-]{0,98}$~', $report))) {
            return [null, t('plugins.err_manifest_field', ['field' => 'audit'])];
        }
        return [[
            'requires_php' => $reqPhp, 'permissions' => array_values(array_unique($perms)), 'repository' => $repo,
            'license' => mb_substr(trim((string)($d['license'] ?? '')), 0, 40), 'audit' => ['commit' => $commit, 'report' => $report],
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
        if (self::all()[$slug]['archived'] ?? false) {
            return false; // ohne entpackten Ordner kann es nicht laufen
        }
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

    /** Anforderungen an die Anwendungs- und PHP-Version; '' = erfüllt. */
    public static function compatProblem(array $m): string
    {
        $need = $m['requires_app'] ?? '';
        $have = app_version();
        if ($need !== '' && $have !== '' && version_compare($have, $need, '<')) {
            return t('plugins.err_requires_app', ['need' => $need, 'have' => $have]);
        }
        // ohne PHP_VERSION: Debian-Zusätze („8.2.12-1+deb12u1“) würden sonst als Vorabversion gelten
        $php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
        $needPhp = $m['requires_php'] ?? '';
        if ($needPhp !== '' && version_compare($php, $needPhp, '<')) {
            return t('plugins.err_requires_php', ['need' => $needPhp, 'have' => $php]);
        }
        return '';
    }

    private static function requirementProblem(array $p): string
    {
        return self::compatProblem($p['m']);
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
            if ($p['archived'] && ($err = self::unpack($slug)) !== null) {
                return $err;
            }
            self::saveState($slug, true);
            if (!self::load($slug)) {
                self::saveState($slug, false);
                self::retire($slug);
                return t('plugins.err_load', ['err' => self::problem($slug)]);
            }
            try {
                self::fire($slug, 'activate');
            } catch (Throwable $e) {
                error_log('Plugin ' . $slug . ' (activate): ' . $e->getMessage());
                self::saveState($slug, false);
                self::retire($slug);
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
        self::retire($slug);
        return null;
    }

    /**
     * Installiert ein Plugin aus einer ZIP-Datei als Ordner (inaktiv).
     * @return array{0:?string, 1:string} Slug oder Fehlertext
     */
    public static function install(string $zipFile): array
    {
        [$r, $err] = self::readZip($zipFile);
        if ($r === null) {
            return [null, $err];
        }
        $m = $r['m'];
        if (($why = self::installProblem($m)) !== '') {
            return [null, $why];
        }
        $tmp = self::stage($r['content']);
        if ($tmp === null) {
            return [null, t('plugins.err_write')];
        }
        if (!@rename($tmp, self::dir() . '/' . $m['slug'])) {
            self::rmtree($tmp);
            return [null, t('plugins.err_write')];
        }
        self::saveState($m['slug'], false);
        self::$all = null;
        return [$m['slug'], ''];
    }

    /** Prüft eine ZIP-Datei, ohne etwas zu installieren (für die Bestätigungsseite). @return array{0:?array, 1:string} Manifest oder Fehlertext */
    public static function peek(string $zipFile): array
    {
        [$r, $err] = self::readZip($zipFile);
        return [$r['m'] ?? null, $err];
    }

    /** Stimmen Kennung und Version im Paket mit dem Erwarteten (Release) überein? @param array{slug?:string, version?:string} $expect */
    private static function expectProblem(array $m, array $expect): string
    {
        if (($expect['slug'] ?? $m['slug']) !== $m['slug'] || ($expect['version'] ?? $m['version']) !== $m['version']) {
            return t('plugins.err_version_tag', ['slug' => $m['slug'], 'version' => $m['version']]);
        }
        return '';
    }

    /** Öffnet und prüft eine ZIP-Datei. @return array{0:?array{m:array, content:array<string,string>}, 1:string} */
    private static function readZip(string $zipFile): array
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
            return self::inspect($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * Prüft Einträge, Dateitypen, Manifest und PHP-Syntax vollständig im Speicher, bevor etwas geschrieben wird.
     * @return array{0:?array{m:array, content:array<string,string>}, 1:string}
     */
    private static function inspect(ZipArchive $zip): array
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
        return [['m' => $m, 'content' => $content], ''];
    }

    /** Regeln für neue Plugins/Updates; '' = in Ordnung. */
    private static function installProblem(array $m, bool $update = false): string
    {
        $slug = $m['slug'];
        if (!$update && (is_dir(self::dir() . '/' . $slug) || isset(self::all()[$slug]))) {
            return t('plugins.err_exists', ['name' => $m['name']]);
        }
        if (($why = self::compatProblem($m)) !== '') {
            return $why;
        }
        foreach (self::all() as $s => $o) {
            if ($s !== $slug && $o['ok'] && array_intersect_key($o['m']['stores'], $m['stores'])) {
                return t('plugins.err_store_clash', ['plugin' => $o['m']['name']]);
            }
        }
        return '';
    }

    /** Schreibt die Dateien in ein verstecktes Nachbarverzeichnis (gleiches Dateisystem, danach rename()). @return ?string Pfad */
    private static function stage(array $content): ?string
    {
        $root = self::dir();
        if (!is_dir($root) && !@mkdir($root, 0750, true) && !is_dir($root)) {
            return null;
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
        } catch (RuntimeException) {
            self::rmtree($tmp);
            return null;
        }
        return $tmp;
    }

    /* ------------------------------------------------------------ Archiv (Marktplatz-Plugins) */

    public static function archiveDir(): string
    {
        return self::dir() . '/_archive';
    }

    private static function archiveFile(string $slug, string $version): string
    {
        return self::archiveDir() . '/' . $slug . '-' . $version . '.zip';
    }

    /** Herkunft aller Marktplatz-Plugins: slug => {repo, version, sha256, installed, manifest, previous?, pending_from?}. */
    public static function sources(): array
    {
        $s = Store::read('market')['sources'] ?? [];
        return is_array($s) ? $s : [];
    }

    public static function source(string $slug): ?array
    {
        $s = self::sources()[$slug] ?? null;
        return is_array($s) ? $s : null;
    }

    /** Legt die ZIP geprüft im Archiv ab. @return ?string Fehlertext */
    private static function storeArchive(string $zipFile, array $m, string $sha): ?string
    {
        $dir = self::archiveDir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return t('plugins.err_write');
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        $target = self::archiveFile($m['slug'], $m['version']);
        $part = $target . '.' . bin2hex(random_bytes(4)) . '.part';
        if (!@copy($zipFile, $part) || !hash_equals($sha, (string)hash_file('sha256', $part)) || !@rename($part, $target)) {
            @unlink($part);
            return t('plugins.err_write');
        }
        @chmod($target, 0640);
        return null;
    }

    /** Löscht Archiv-ZIPs eines Plugins, deren Version nicht in $keep steht (leer = alle). */
    private static function pruneArchive(string $slug, array $keep = []): void
    {
        $re = '/^' . preg_quote($slug, '/') . '-(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)\.zip$/';
        foreach (glob(self::archiveDir() . '/*.zip') ?: [] as $f) {
            if (preg_match($re, basename($f), $mm) && !in_array($mm[1], $keep, true)) {
                @unlink($f);
            }
        }
    }

    /**
     * Übernimmt ein geprüftes Paket (z. B. aus einem GitHub-Release) als inaktives Marktplatz-Plugin ins Archiv.
     * @param string $expectedSha SHA-256 aus dem Release (leer = nicht prüfen)
     * @param array{slug?:string, version?:string} $expect Kennung/Version, die das Manifest im Paket haben muss (Release-Tag)
     * @return array{0:?string, 1:string} Slug oder Fehlertext
     */
    public static function archive(string $zipFile, string $repo, string $expectedSha = '', array $expect = []): array
    {
        if (!preg_match(self::REPO_RE, $repo)) {
            return [null, t('plugins.err_repo')];
        }
        $sha = is_file($zipFile) ? (string)hash_file('sha256', $zipFile) : '';
        if ($expectedSha !== '' && !hash_equals(strtolower($expectedSha), $sha)) {
            return [null, t('plugins.err_hash')];
        }
        [$r, $err] = self::readZip($zipFile);
        if ($r === null) {
            return [null, $err];
        }
        $m = $r['m'];
        if (($why = self::expectProblem($m, $expect) ?: self::installProblem($m)) !== '') {
            return [null, $why];
        }
        if (($e = self::storeArchive($zipFile, $m, $sha)) !== null) {
            return [null, $e];
        }
        Store::update('market', function (array &$d) use ($m, $repo, $sha) {
            $d['sources'][$m['slug']] = ['repo' => $repo, 'version' => $m['version'], 'sha256' => $sha, 'installed' => now(), 'manifest' => $m];
        });
        self::saveState($m['slug'], false);
        self::$all = null;
        return [$m['slug'], ''];
    }

    /**
     * Aktualisiert ein Marktplatz-Plugin auf eine neuere Version desselben Repositorys. Ist es aktiv, wird der Ordner
     * ausgetauscht (ohne Deaktivieren; Abhängige bleiben aktiv) und beim nächsten Aufruf `onUpdate` ausgeführt. Die
     * vorherige ZIP bleibt als Rückfall im Archiv.
     * @return array{0:?string, 1:string} Slug oder Fehlertext
     */
    public static function update(string $zipFile, string $repo, string $expectedSha = '', array $expect = []): array
    {
        $sha = is_file($zipFile) ? (string)hash_file('sha256', $zipFile) : '';
        if ($expectedSha !== '' && !hash_equals(strtolower($expectedSha), $sha)) {
            return [null, t('plugins.err_hash')];
        }
        [$r, $err] = self::readZip($zipFile);
        if ($r === null) {
            return [null, $err];
        }
        $m = $r['m'];
        $slug = $m['slug'];
        $src = self::source($slug);
        if ($src === null) {
            return [null, t('plugins.err_not_market')];
        }
        if (($src['repo'] ?? '') !== $repo) {
            return [null, t('plugins.err_repo_other', ['repo' => (string)$src['repo']])];
        }
        $old = (string)$src['version'];
        if (version_compare($m['version'], $old, '<=')) {
            return [null, t('plugins.err_not_newer')];
        }
        if (($why = self::expectProblem($m, $expect) ?: self::installProblem($m, true)) !== '') {
            return [null, $why];
        }
        $dir = self::dir() . '/' . $slug;
        $swap = is_dir($dir);
        if ($swap) {
            foreach ($m['requires'] as $dep) {
                if (!(self::all()[$dep]['ok'] ?? false) || !self::isActive($dep)) {
                    return [null, t('plugins.err_deps_inactive', ['names' => $dep])];
                }
            }
        }
        if (($e = self::storeArchive($zipFile, $m, $sha)) !== null) {
            return [null, $e];
        }
        if ($swap && ($e = self::swapFolder($slug, $r['content'])) !== null) {
            @unlink(self::archiveFile($slug, $m['version']));
            return [null, $e];
        }
        $pending = $swap ? (string)($src['pending_from'] ?? $old) : '';
        Store::update('market', function (array &$d) use ($slug, $m, $repo, $sha, $old, $pending, $src) {
            $d['sources'][$slug] = ['repo' => $repo, 'version' => $m['version'], 'sha256' => $sha, 'installed' => now(),
                'manifest' => $m, 'previous' => $old, 'previous_sha' => (string)$src['sha256']] + ($pending !== '' ? ['pending_from' => $pending] : []);
            unset($d['updates'][$slug]);
        });
        self::pruneArchive($slug, [$m['version'], $old]);
        self::$all = null;
        return [$slug, ''];
    }

    /** Ersetzt den Ordner eines Plugins durch neue Dateien (zwei rename()-Schritte, bei Fehler wird zurückgesetzt). @return ?string Fehlertext */
    private static function swapFolder(string $slug, array $content): ?string
    {
        $dir = self::dir() . '/' . $slug;
        $tmp = self::stage($content);
        $bak = self::dir() . '/.old-' . bin2hex(random_bytes(6));
        if ($tmp === null || !@rename($dir, $bak)) {
            $tmp !== null && self::rmtree($tmp);
            return t('plugins.err_write');
        }
        if (!@rename($tmp, $dir)) {
            @rename($bak, $dir);
            self::rmtree($tmp);
            return t('plugins.err_write');
        }
        self::rmtree($bak);
        return null;
    }

    /**
     * Setzt ein Marktplatz-Plugin auf die vorherige Version zurück (ZIP aus dem Archiv, Hash wird geprüft). Die neuere
     * Version wird bei der Aktualisierungsprüfung übergangen (`skip_version`), bis eine noch neuere erscheint.
     * @return ?string Fehlertext
     */
    public static function rollback(string $slug): ?string
    {
        $src = self::source($slug);
        $prev = (string)($src['previous'] ?? '');
        $sha = (string)($src['previous_sha'] ?? '');
        if ($src === null || $prev === '' || $sha === '') {
            return t('plugins.err_no_previous');
        }
        $file = self::archiveFile($slug, $prev);
        if (!is_file($file) || !hash_equals($sha, (string)hash_file('sha256', $file))) {
            return t('plugins.err_hash');
        }
        [$r, $err] = self::readZip($file);
        if ($r === null) {
            return $err;
        }
        $m = $r['m'];
        if ($m['slug'] !== $slug || $m['version'] !== $prev) {
            return t('plugins.err_hash');
        }
        if (($why = self::installProblem($m, true)) !== '') {
            return $why;
        }
        if (is_dir(self::dir() . '/' . $slug)) {
            foreach ($m['requires'] as $dep) {
                if (!(self::all()[$dep]['ok'] ?? false) || !self::isActive($dep)) {
                    return t('plugins.err_deps_inactive', ['names' => $dep]);
                }
            }
            if (($e = self::swapFolder($slug, $r['content'])) !== null) {
                return $e;
            }
        }
        $cur = (string)$src['version'];
        $skip = (string)($src['skip_version'] ?? '');
        Store::update('market', function (array &$d) use ($slug, $src, $prev, $sha, $m, $cur, $skip) {
            $d['sources'][$slug] = ['repo' => $src['repo'], 'version' => $prev, 'sha256' => $sha, 'installed' => now(), 'manifest' => $m,
                'skip_version' => $skip !== '' && version_compare($skip, $cur, '>') ? $skip : $cur];
            unset($d['updates'][$slug]);
        });
        self::pruneArchive($slug, [$prev]);
        self::$all = null;
        return null;
    }

    /** Entpackt ein archiviertes Plugin nach `plugins/<slug>/` (Hash- und Inhaltsprüfung bei jedem Mal). @return ?string Fehlertext */
    private static function unpack(string $slug): ?string
    {
        $src = self::source($slug);
        if ($src === null || !(self::all()[$slug]['archived'] ?? false)) {
            return t('plugins.err_unknown');
        }
        $file = self::archiveFile($slug, (string)$src['version']);
        if (!is_file($file) || !hash_equals((string)$src['sha256'], (string)hash_file('sha256', $file))) {
            return t('plugins.err_hash');
        }
        [$r, $err] = self::readZip($file);
        if ($r === null) {
            return $err;
        }
        if ($r['m']['slug'] !== $slug || $r['m']['version'] !== $src['version']) {
            return t('plugins.err_hash');
        }
        $dir = self::dir() . '/' . $slug;
        if (is_dir($dir)) {
            return t('plugins.err_exists', ['name' => $r['m']['name']]);
        }
        $tmp = self::stage($r['content']);
        if ($tmp === null) {
            return t('plugins.err_write');
        }
        if (!@rename($tmp, $dir)) {
            self::rmtree($tmp);
            return t('plugins.err_write');
        }
        self::$all = null;
        return null;
    }

    /** Entfernt den Ordner eines Marktplatz-Plugins, sofern seine ZIP im Archiv liegt (sonst bleibt er als Sicherung). */
    private static function retire(string $slug): void
    {
        $src = self::source($slug);
        if ($src === null || !is_file(self::archiveFile($slug, (string)($src['version'] ?? '')))) {
            return;
        }
        $base = realpath(self::dir());
        $dir = realpath(self::dir() . '/' . $slug);
        if ($base && $dir && str_starts_with($dir, $base . DIRECTORY_SEPARATOR)) {
            self::rmtree($dir);
            self::$all = null;
        }
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
        if ($p['archived']) {
            $src = self::archiveFile($slug, $p['m']['version']);
            return is_file($src) && @copy($src, $tmp) ? [$tmp, basename($src)] : [null, ''];
        }
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
        // Ordner (auch ein nach Fehlern übrig gebliebener bei Marktplatz-Plugins), dann Archiv und Herkunft
        $folder = self::dir() . '/' . $slug;
        if (is_dir($folder)) {
            $base = realpath(self::dir());
            $dir = realpath($folder);
            if (!$base || !$dir || !str_starts_with($dir, $base . DIRECTORY_SEPARATOR)) {
                return t('plugins.err_write');
            }
            self::rmtree($dir);
        }
        if (self::source($slug) !== null) {
            self::pruneArchive($slug);
            Store::update('market', function (array &$d) use ($slug) {
                unset($d['sources'][$slug]);
            });
        }
        Store::update('settings', function (array &$s) use ($slug) {
            unset($s['plugins'][$slug]);
        });
        self::$all = null;
        return is_dir($folder) ? t('plugins.err_write') : null;
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
