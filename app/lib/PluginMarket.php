<?php
declare(strict_types=1);

/**
 * Plugin-Marktplatz über GitHub (Konzept: docs/plugin-marketplace.md). Findet Repositories mit dem Topic
 * `funeral-org-plugin`, liest deren Releases, lädt Pakete samt SHA-256-Prüfsumme und prüft Kompatibilität.
 * Installiert wird über `Plugins::archive()` / `Plugins::update()`.
 *
 * Release-Regeln: Semver-Tag `vX.Y.Z` (= `version` im Manifest), veröffentlicht (kein Draft, kein Pre-Release) und mit drei
 * Assets `<slug>-X.Y.Z.zip`, `<slug>-X.Y.Z.zip.sha256` (`<hash>  <datei>`) und `plugin.json`. Unvollständige Releases
 * werden ignoriert.
 *
 * Netzwerk: nur HTTPS zu `HOSTS` (auch nach jeder Weiterleitung geprüft), cURL ohne automatisches Folgen, Größenlimits,
 * das GitHub-Token geht nur an api.github.com. Antworten der API werden 1 h mit ETag zwischengespeichert, Manifeste aus
 * Releases dauerhaft (Releases sind unveränderlich). Alle Inhalte von GitHub sind Fremdinhalt: nur über `e()` ausgeben.
 *
 * Fehler werden als RuntimeException mit anzeigbarem (übersetztem) Text geworfen (Code 429 = API-Kontingent erschöpft).
 * Für Tests lässt sich die API-Adresse mit `configs/config.php → market_api` umlenken (dann ist genau dieser Ursprung,
 * auch ohne HTTPS, zusätzlich erlaubt).
 */
final class PluginMarket
{
    public const TOPIC = 'funeral-org-plugin';
    public const API = 'https://api.github.com';
    /** Erlaubte Hosts: API, Release-Downloads und deren CDN-Weiterleitungen. */
    public const HOSTS = ['github.com', 'api.github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'];
    private const TTL = 3600;
    private const MAX_JSON = 2 * 1048576;
    private const MAX_SMALL = 65536;
    private const MAX_REDIRECTS = 5;

    /* ------------------------------------------------------------ Token und Kontingent */

    /** Quelle des Tokens: 'config', 'store' oder ''. Das Token selbst wird nie angezeigt. */
    public static function tokenSource(): string
    {
        if ((string)cfg('github_token', '') !== '') {
            return 'config';
        }
        return (string)(Store::read('market')['token'] ?? '') !== '' ? 'store' : '';
    }

    private static function token(): string
    {
        $t = (string)cfg('github_token', '');
        return $t !== '' ? $t : (string)(Store::read('market')['token'] ?? '');
    }

    /** Speichert (oder entfernt, wenn leer) das GitHub-Token. @return bool false bei ungültigem Format */
    public static function setToken(string $token): bool
    {
        $token = trim($token);
        if ($token !== '' && !preg_match('/^[A-Za-z0-9_]{20,255}$/', $token)) {
            return false;
        }
        Store::update('market', function (array &$d) use ($token) {
            $d['token'] = $token;
        });
        return true;
    }

    /** Zuletzt gemeldetes API-Kontingent: {limit, remaining, reset (Unix), time} oder null. */
    public static function rate(): ?array
    {
        $j = @file_get_contents(self::cacheDir() . '/rate.json');
        $d = $j === false ? null : json_decode($j, true);
        return is_array($d) ? $d : null;
    }

    /* ------------------------------------------------------------ Suche und Releases */

    /** Repositories mit dem Plugin-Topic. @return list<array{repo:string,name:string,description:string,stars:int,pushed:string,archived:bool,installed:string}> */
    public static function search(string $q = ''): array
    {
        $q = trim(preg_replace('/[^\p{L}\p{N} _.-]+/u', ' ', mb_substr($q, 0, 60)) ?? '');
        $query = 'topic:' . self::TOPIC . ($q !== '' ? ' ' . $q . ' in:name,description' : '');
        $d = self::getJson(self::apiBase() . '/search/repositories?q=' . rawurlencode($query) . '&sort=stars&order=desc&per_page=30');
        $byRepo = [];
        foreach (Plugins::sources() as $slug => $s) {
            $byRepo[strtolower((string)($s['repo'] ?? ''))] = (string)$slug;
        }
        $out = [];
        foreach ((array)($d['items'] ?? []) as $it) {
            $repo = is_array($it) ? (string)($it['full_name'] ?? '') : '';
            if (!preg_match(Plugins::REPO_RE, $repo) || !empty($it['disabled'])) {
                continue;
            }
            $out[] = [
                'repo' => $repo, 'name' => mb_substr((string)($it['name'] ?? ''), 0, 100),
                'description' => mb_substr((string)($it['description'] ?? ''), 0, 300),
                'stars' => (int)($it['stargazers_count'] ?? 0), 'pushed' => substr((string)($it['pushed_at'] ?? ''), 0, 10),
                'archived' => !empty($it['archived']), 'installed' => $byRepo[strtolower($repo)] ?? '',
            ];
        }
        return $out;
    }

    /**
     * Gültige Releases eines Repositorys, neueste zuerst. Je Release: tag, version, slug, published, notes (Klartext),
     * zip/sha/manifest_url (Download-Adressen), size, manifest (Array oder null), compatible, reason.
     * @return list<array<string,mixed>>
     */
    public static function releases(string $repo, int $max = 10): array
    {
        self::needRepo($repo);
        $d = self::getJson(self::apiBase() . '/repos/' . $repo . '/releases?per_page=20');
        $out = [];
        foreach ((array)$d as $rel) {
            if (!is_array($rel) || !empty($rel['draft']) || !empty($rel['prerelease'])
                || !preg_match('/^v(\d+\.\d+\.\d+)$/', (string)($rel['tag_name'] ?? ''), $mm)) {
                continue;
            }
            $ver = $mm[1];
            $assets = [];
            foreach ((array)($rel['assets'] ?? []) as $a) {
                if (is_array($a) && is_string($a['name'] ?? null) && self::allowed((string)($a['browser_download_url'] ?? ''))
                    && (($a['state'] ?? 'uploaded') === 'uploaded')) {
                    $assets[$a['name']] = ['url' => (string)$a['browser_download_url'], 'size' => (int)($a['size'] ?? 0)];
                }
            }
            $zipName = '';
            foreach (array_keys($assets) as $n) {
                if (preg_match('/^[a-z][a-z0-9-]{1,39}-' . preg_quote($ver, '/') . '\.zip$/', $n)) {
                    $zipName = $n;
                    break;
                }
            }
            if ($zipName === '' || !isset($assets[$zipName . '.sha256'], $assets['plugin.json'])) {
                continue; // unvollständig
            }
            $out[] = [
                'tag' => 'v' . $ver, 'version' => $ver, 'slug' => substr($zipName, 0, -strlen('-' . $ver . '.zip')),
                'published' => substr((string)($rel['published_at'] ?? ''), 0, 10),
                'notes' => mb_substr((string)($rel['body'] ?? ''), 0, 4000),
                'zip_name' => $zipName, 'zip' => $assets[$zipName]['url'], 'size' => $assets[$zipName]['size'],
                'sha' => $assets[$zipName . '.sha256']['url'], 'manifest_url' => $assets['plugin.json']['url'],
            ];
        }
        usort($out, fn($a, $b) => version_compare($b['version'], $a['version']));
        $out = array_slice($out, 0, $max);
        foreach ($out as &$r) {
            [$r['manifest'], $r['reason']] = self::judge($r);
            $r['compatible'] = $r['reason'] === '';
        }
        return $out;
    }

    /** Neuestes kompatibles Release, das neuer als `$installed` ist (leer = jedes), sonst null. */
    public static function latest(string $repo, string $installed = ''): ?array
    {
        foreach (self::releases($repo) as $r) {
            if ($r['compatible'] && ($installed === '' || version_compare($r['version'], $installed, '>'))) {
                return $r;
            }
        }
        return null;
    }

    /** Manifest aus dem Release-Asset und Kompatibilität. @return array{0:?array, 1:string} Manifest, Grund ('' = kompatibel) */
    private static function judge(array $rel): array
    {
        try {
            $json = self::cachedForever($rel['manifest_url']);
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }
        [$m, $err] = Plugins::parseManifest($json, null);
        if ($m === null) {
            return [null, $err];
        }
        if ($m['slug'] !== $rel['slug'] || $m['version'] !== $rel['version']) {
            return [$m, t('plugins.err_version_tag', ['slug' => $m['slug'], 'version' => $m['version']])];
        }
        return [$m, Plugins::compatProblem($m)];
    }

    /* ------------------------------------------------------------ Aktualisierungen */

    /**
     * Prüft alle Marktplatz-Plugins auf neuere kompatible Releases und merkt das Ergebnis in `market.json`.
     * `confirm` = das neue Release fordert zusätzliche Berechtigungen an (nicht ohne Rückfrage übernehmen).
     * @return array{updates:array<string,array{version:string,tag:string,confirm:list<string>}>, errors:array<string,string>}
     */
    public static function checkUpdates(): array
    {
        $updates = [];
        $errors = [];
        foreach (Plugins::sources() as $slug => $src) {
            $slug = (string)$slug;
            try {
                $rel = self::latest((string)($src['repo'] ?? ''), self::floor($src));
            } catch (RuntimeException $e) {
                $errors[$slug] = $e->getMessage();
                if ($e->getCode() === 429) {
                    break; // Kontingent erschöpft: die übrigen würden ebenfalls scheitern
                }
                continue;
            }
            if ($rel !== null) {
                $new = array_values(array_diff($rel['manifest']['permissions'] ?? [], (array)($src['manifest']['permissions'] ?? [])));
                $updates[$slug] = ['version' => $rel['version'], 'tag' => $rel['tag'], 'confirm' => $new];
            }
        }
        Store::update('market', function (array &$d) use ($updates, $errors) {
            // bei Fehlern den bisherigen Stand dieses Plugins behalten
            $d['updates'] = $updates + array_intersect_key((array)($d['updates'] ?? []), $errors);
            $d['checked'] = now();
        });
        return ['updates' => $updates, 'errors' => $errors];
    }

    /** Version, über der ein Update liegen muss: installierte oder – nach einem Rollback – die zurückgesetzte Version. */
    private static function floor(array $src): string
    {
        $v = (string)($src['version'] ?? '0.0.0');
        $skip = (string)($src['skip_version'] ?? '');
        return $skip !== '' && version_compare($skip, $v, '>') ? $skip : $v;
    }

    /** Gemerkte Aktualisierungen (nur solche, die noch neuer als die installierte Version sind). */
    public static function updates(): array
    {
        $out = [];
        foreach ((array)(Store::read('market')['updates'] ?? []) as $slug => $u) {
            $src = Plugins::source((string)$slug);
            if ($src !== null && is_array($u) && version_compare((string)($u['version'] ?? '0'), self::floor($src), '>')) {
                $out[(string)$slug] = $u;
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------ Paket laden */

    /**
     * Lädt ZIP und Prüfsumme eines Releases nach `data/tmp/` und vergleicht den SHA-256. Der Aufrufer übergibt die Datei
     * an `Plugins::archive()`/`update()` (mit `$sha` und `['slug' => …, 'version' => …]`) und löscht sie danach mit `discard()`.
     * @return array{zip:string, sha:string, release:array<string,mixed>, manifest:array}
     */
    public static function fetch(string $repo, string $version): array
    {
        $rel = null;
        foreach (self::releases($repo, 20) as $r) {
            if ($r['version'] === $version) {
                $rel = $r;
                break;
            }
        }
        if ($rel === null) {
            throw new RuntimeException(t('market.err_release'));
        }
        if (!$rel['compatible']) {
            throw new RuntimeException($rel['reason']);
        }
        $line = self::request($rel['sha'], [], self::MAX_SMALL)['body'];
        if (!preg_match('/^([a-f0-9]{64})[ \t]+\*?(\S+)[ \t]*$/mi', $line, $mm) || $mm[2] !== $rel['zip_name']) {
            throw new RuntimeException(t('market.err_sha'));
        }
        $sha = strtolower($mm[1]);
        $body = self::request($rel['zip'], [], Plugins::MAX_ZIP, 120)['body'];
        $dir = DATA_DIR . '/tmp';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException(t('plugins.err_write'));
        }
        $zip = $dir . '/market-' . bin2hex(random_bytes(6)) . '.zip';
        if (file_put_contents($zip, $body) === false) {
            throw new RuntimeException(t('plugins.err_write'));
        }
        @chmod($zip, 0640);
        if (!hash_equals($sha, (string)hash_file('sha256', $zip))) {
            @unlink($zip);
            throw new RuntimeException(t('plugins.err_hash'));
        }
        [$m, $err] = Plugins::peek($zip);
        if ($m === null) {
            @unlink($zip);
            throw new RuntimeException($err);
        }
        return ['zip' => $zip, 'sha' => $sha, 'release' => $rel, 'manifest' => $m];
    }

    /** Löscht eine mit `fetch()` geladene Datei (nur im eigenen Temp-Verzeichnis). */
    public static function discard(string $zip): void
    {
        if (str_starts_with($zip, DATA_DIR . '/tmp/market-') && !str_contains($zip, '..')) {
            @unlink($zip);
        }
    }

    /**
     * Vergleicht den Audit-Commit im Manifest mit dem Commit des Release-Tags.
     * status: none (kein Audit angegeben) | match | different | unknown (GitHub nicht erreichbar)
     * @return array{status:string, audited:string, tag_commit:string}
     */
    public static function audit(string $repo, string $tag, array $manifest): array
    {
        $audited = (string)($manifest['audit']['commit'] ?? '');
        if ($audited === '') {
            return ['status' => 'none', 'audited' => '', 'tag_commit' => ''];
        }
        try {
            self::needRepo($repo);
            $ref = self::getJson(self::apiBase() . '/repos/' . $repo . '/git/ref/tags/' . rawurlencode($tag));
            $obj = (array)($ref['object'] ?? []);
            if (($obj['type'] ?? '') === 'tag') { // annotiertes Tag → auf den Commit auflösen
                $obj = (array)(self::getJson(self::apiBase() . '/repos/' . $repo . '/git/tags/' . rawurlencode((string)$obj['sha']))['object'] ?? []);
            }
            $commit = strtolower((string)($obj['sha'] ?? ''));
        } catch (RuntimeException) {
            return ['status' => 'unknown', 'audited' => $audited, 'tag_commit' => ''];
        }
        if (!preg_match('/^[0-9a-f]{40}$/', $commit)) {
            return ['status' => 'unknown', 'audited' => $audited, 'tag_commit' => ''];
        }
        return ['status' => $commit === $audited ? 'match' : 'different', 'audited' => $audited, 'tag_commit' => $commit];
    }

    /* ------------------------------------------------------------ HTTP */

    private static function needRepo(string $repo): void
    {
        if (!preg_match(Plugins::REPO_RE, $repo)) {
            throw new RuntimeException(t('plugins.err_repo'));
        }
    }

    private static function override(): ?array
    {
        $u = (string)cfg('market_api', '');
        $p = $u !== '' ? parse_url($u) : false;
        return is_array($p) && isset($p['scheme'], $p['host']) ? $p : null;
    }

    private static function apiBase(): string
    {
        return rtrim((string)(self::override() ? cfg('market_api') : self::API), '/');
    }

    /** Erlaubt sind nur HTTPS-Adressen der Whitelist (und, wenn gesetzt, der Ursprung von `market_api`). */
    private static function allowed(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        $scheme = strtolower($p['scheme']);
        $host = strtolower($p['host']);
        $port = $p['port'] ?? null;
        if ($scheme === 'https' && in_array($host, self::HOSTS, true) && ($port === null || $port === 443)) {
            return true;
        }
        $o = self::override();
        return $o !== null && $scheme === strtolower($o['scheme']) && $host === strtolower($o['host']) && $port === ($o['port'] ?? null);
    }

    private static function isApiHost(string $url): bool
    {
        $o = self::override();
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        return $o !== null ? $host === strtolower($o['host']) : $host === 'api.github.com';
    }

    /**
     * Einzelne Anfrage (GET), Weiterleitungen werden von Hand verfolgt und jedes Ziel geprüft.
     * @return array{code:int, headers:array<string,string>, body:string}
     */
    private static function request(string $url, array $headers = [], int $max = self::MAX_JSON, int $timeout = 30): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException(t('market.err_curl'));
        }
        $token = self::token();
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (!self::allowed($url)) {
                throw new RuntimeException(t('market.err_host'));
            }
            $h = array_merge(['User-Agent: FuneralOrg/' . (app_version() ?: 'dev'), 'X-GitHub-Api-Version: 2022-11-28'], $headers);
            if ($token !== '' && self::isApiHost($url)) {
                $h[] = 'Authorization: Bearer ' . $token; // nie an CDN-/Download-Hosts
            }
            $body = '';
            $big = false;
            $resp = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => $timeout,
                CURLOPT_PROTOCOLS => self::override() ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => function ($c, string $line) use (&$resp): int {
                    if (str_contains($line, ':')) {
                        [$k, $v] = explode(':', $line, 2);
                        $resp[strtolower(trim($k))] = trim($v);
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($c, string $chunk) use (&$body, &$big, $max): int {
                    if (strlen($body) + strlen($chunk) > $max) {
                        $big = true;
                        return 0; // Abbruch
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($big) {
                throw new RuntimeException(t('market.err_big'));
            }
            if ($ok === false) {
                throw new RuntimeException(t('market.err_net'));
            }
            if (self::isApiHost($url) && isset($resp['x-ratelimit-remaining'])) {
                self::saveRate($resp);
            }
            if (in_array($code, [301, 302, 303, 307, 308], true) && isset($resp['location'])) {
                $loc = $resp['location'];
                if ($loc !== '' && $loc[0] === '/') {
                    $pu = parse_url($url);
                    $loc = $pu['scheme'] . '://' . $pu['host'] . (isset($pu['port']) ? ':' . $pu['port'] : '') . $loc;
                }
                $url = $loc;
                continue;
            }
            if (($code === 403 || $code === 429) && ($resp['x-ratelimit-remaining'] ?? '') === '0') {
                throw new RuntimeException(t('market.err_rate', ['time' => date('H:i', (int)($resp['x-ratelimit-reset'] ?? time() + 3600))]), 429);
            }
            return ['code' => $code, 'headers' => $resp, 'body' => $body];
        }
        throw new RuntimeException(t('market.err_redirects'));
    }

    private static function saveRate(array $h): void
    {
        $dir = self::cacheDir();
        if (is_dir($dir) || @mkdir($dir, 0750, true)) {
            @file_put_contents($dir . '/rate.json', json_encode([
                'limit' => (int)($h['x-ratelimit-limit'] ?? 0), 'remaining' => (int)$h['x-ratelimit-remaining'],
                'reset' => (int)($h['x-ratelimit-reset'] ?? 0), 'time' => time(),
            ]));
        }
    }

    /* ------------------------------------------------------------ Cache */

    private static function cacheDir(): string
    {
        return DATA_DIR . '/cache/market';
    }

    private static function cacheWrite(string $file, array $data): void
    {
        $dir = self::cacheDir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($tmp, $file);
        }
    }

    /** JSON-GET mit 1 h Cache und ETag; bei Netzfehlern dient ein abgelaufener Cache als Rückfall. */
    private static function getJson(string $url): array
    {
        $file = self::cacheDir() . '/' . sha1($url) . '.json';
        $c = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
        $c = is_array($c) && isset($c['body'], $c['time']) ? $c : null;
        if ($c !== null && time() - (int)$c['time'] < self::TTL) {
            return (array)json_decode((string)$c['body'], true);
        }
        try {
            $r = self::request($url, array_filter(['Accept: application/vnd.github+json', $c && !empty($c['etag']) ? 'If-None-Match: ' . $c['etag'] : '']));
        } catch (RuntimeException $e) {
            if ($c !== null) {
                return (array)json_decode((string)$c['body'], true);
            }
            throw $e;
        }
        if ($r['code'] === 304 && $c !== null) {
            self::cacheWrite($file, ['etag' => $c['etag'] ?? '', 'time' => time(), 'body' => $c['body']]);
            return (array)json_decode((string)$c['body'], true);
        }
        if ($r['code'] === 404) {
            throw new RuntimeException(t('market.err_notfound'));
        }
        $d = json_decode($r['body'], true);
        if ($r['code'] !== 200 || !is_array($d)) {
            throw new RuntimeException(t('market.err_http', ['code' => (string)$r['code']]));
        }
        self::cacheWrite($file, ['etag' => $r['headers']['etag'] ?? '', 'time' => time(), 'body' => $r['body']]);
        return $d;
    }

    /** Kleine Datei (Release-Manifest) holen und dauerhaft merken – veröffentlichte Releases ändern sich nicht. */
    private static function cachedForever(string $url): string
    {
        $file = self::cacheDir() . '/m-' . sha1($url) . '.json';
        $c = is_file($file) ? @file_get_contents($file) : false;
        if (is_string($c) && $c !== '') {
            return $c;
        }
        $r = self::request($url, [], self::MAX_SMALL);
        if ($r['code'] !== 200) {
            throw new RuntimeException(t('market.err_http', ['code' => (string)$r['code']]));
        }
        $dir = self::cacheDir();
        if (is_dir($dir) || @mkdir($dir, 0750, true)) {
            @file_put_contents($file, $r['body']);
        }
        return $r['body'];
    }
}
