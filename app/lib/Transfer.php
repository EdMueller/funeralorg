<?php
declare(strict_types=1);

/**
 * Export und Import ausgewählter Bereiche (Konfiguration, Inhalte, Zugänge) als ZIP-Paket.
 *
 * Paket: `manifest.json` (Format, Version, Anwendung, Datum), `data/<store>.json`, `data/uploads/<variante>/<datei>`,
 * `data/logs/JJJJ-MM.jsonl`, `config/config.json` (Werte aus configs/config.php, nur zur Ansicht beim Import).
 * Auch die Backup-Downloads (ohne manifest.json) lassen sich importieren.
 *
 * Import in zwei Schritten: `stage()` entpackt und prüft das Paket nach `data/tmp/import-<token>/`, `apply()` ersetzt
 * die gewählten Bereiche (nach einer Sicherung der JSON-Dateien). configs/config.php wird nie verändert.
 */
final class Transfer
{
    public const FORMAT = 'funeralorg-export';
    public const VERSION = 1;
    public const MAX_FILES = 50000;
    public const MAX_BYTES = 8 * 1024 ** 3;
    public const MAX_JSON = 50 * 1024 * 1024;
    public const KEEP_SNAPSHOTS = 5;

    /** Werte aus configs/config.php, die im Paket stehen dürfen (keine Pfade, keine Basis-URL). */
    public const CONFIG_KEYS = ['app_name', 'timezone', 'session_idle_minutes', 'login_max_attempts', 'login_window_minutes',
        'login_lock_minutes', 'login_lock_max_hours', 'login_max_attempts_user', 'login_user_cooldown_max_seconds',
        'log_full_ip', 'log_retention_months', 'backup_keep_days', 'upload_max_mb', 'thumb_size', 'medium_size',
        'jpeg_quality', 'image_max_pixels', 'image_memory_limit', 'label_formats', 'access_formats', 'privacy_url',
        'mail_from', 'plugins_safe_mode'];

    private const VARIANTS = ['orig', 'medium', 'thumb'];
    private const IMG_MIME = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    /**
     * Bereiche: Schlüssel => Gruppe, Stores, Bilddateien, Protokolle, Standardauswahl.
     * Beschriftung: t('transfer.part.<key>'), Hinweis: t('transfer.hint.<key>').
     */
    public static function parts(): array
    {
        return [
            'settings'   => ['group' => 'config',  'stores' => ['settings'], 'on' => true],
            'groups'     => ['group' => 'config',  'stores' => ['groups'], 'on' => true],
            'configphp'  => ['group' => 'config',  'stores' => [], 'on' => true],
            'memorial'   => ['group' => 'content', 'stores' => ['memorial'], 'on' => true],
            'gallery'    => ['group' => 'content', 'stores' => ['gallery'], 'images' => true, 'on' => true],
            'contacts'   => ['group' => 'content', 'stores' => ['contacts'], 'on' => true],
            'checklist'  => ['group' => 'content', 'stores' => ['checklist'], 'on' => true],
            'plugindata' => ['group' => 'content', 'stores' => self::pluginStores(), 'on' => true],
            'users'      => ['group' => 'access',  'stores' => ['users'], 'on' => false],
            'shares'     => ['group' => 'access',  'stores' => ['shares'], 'on' => false],
            'logs'       => ['group' => 'access',  'stores' => [], 'logs' => true, 'on' => false],
        ];
    }

    /** Datendateien der installierten Plugins. */
    private static function pluginStores(): array
    {
        $out = [];
        foreach (Plugins::all() as $p) {
            foreach (array_keys($p['m']['stores'] ?? []) as $name) {
                $out[] = (string)$name;
            }
        }
        return array_values(array_unique($out));
    }

    /** Alle Store-Namen, die ein Paket enthalten darf. */
    private static function storeNames(): array
    {
        $names = [];
        foreach (self::parts() as $p) {
            $names = array_merge($names, $p['stores']);
        }
        return $names;
    }

    /* ------------------------------------------------------------ Export */

    /** Erstellt das Paket und liefert den Pfad der temporären ZIP-Datei. */
    public static function export(array $keys): string
    {
        @set_time_limit(900);
        $parts = self::parts();
        $keys = array_values(array_intersect(array_keys($parts), $keys));
        $tmpDir = DATA_DIR . '/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0750, true);
        }
        $tmp = $tmpDir . '/' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP konnte nicht erstellt werden');
        }
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        foreach ($keys as $k) {
            $p = $parts[$k];
            foreach ($p['stores'] as $name) {
                $zip->addFromString('data/' . $name . '.json', json_encode(Store::read($name), $flags));
            }
            if (!empty($p['images'])) {
                foreach (Store::read('gallery')['images'] as $img) {
                    foreach (self::VARIANTS as $v) {
                        $f = Image::path($img, $v);
                        $e = 'data/uploads/' . $v . '/' . basename($f);
                        if (is_file($f) && $zip->locateName($e) === false) {
                            $zip->addFile($f, $e);
                            $zip->setCompressionName($e, ZipArchive::CM_STORE);
                        }
                    }
                }
            }
            if (!empty($p['logs'])) {
                foreach (glob(DATA_DIR . '/logs/*.jsonl') ?: [] as $f) {
                    $zip->addFile($f, 'data/logs/' . basename($f));
                }
            }
            if ($k === 'configphp') {
                $cfg = array_intersect_key($GLOBALS['CFG'], array_flip(self::CONFIG_KEYS));
                $zip->addFromString('config/config.json', json_encode($cfg, $flags));
            }
        }
        $zip->addFromString('manifest.json', json_encode([
            'format'  => self::FORMAT,
            'version' => self::VERSION,
            'app'     => app_version(),
            'created' => now(),
            'parts'   => $keys,
        ], $flags));
        $zip->close();
        return $tmp;
    }

    /* ------------------------------------------------------------ Import: Paket prüfen */

    /**
     * Entpackt das hochgeladene Paket nach data/tmp/import-<token>/ und prüft es.
     * @return array{token?: string, error?: string, name?: string, v?: string}
     */
    public static function stage(string $zipFile): array
    {
        self::purgeStages();
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return ['error' => 'transfer.err_zip'];
        }
        if ($zip->numFiles > self::MAX_FILES) {
            return ['error' => 'transfer.err_limits'];
        }
        $token = bin2hex(random_bytes(16));
        $dir = DATA_DIR . '/tmp/import-' . $token;
        if (!@mkdir($dir, 0750, true)) {
            return ['error' => 'transfer.err_zip'];
        }
        $fail = function (string $err, array $extra = []) use ($dir, $zip): array {
            $zip->close();
            self::rmtree($dir);
            return ['error' => $err] + $extra;
        };
        $stores = self::storeNames();
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $name = (string)($st['name'] ?? '');
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            $zip->getExternalAttributesIndex($i, $os, $attr);
            if ($os === ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000)) {
                continue; // Verknüpfungen nie entpacken
            }
            $rel = self::entry($name, $stores);
            if ($rel === null) {
                continue;
            }
            $in = $zip->getStream($name);
            if ($in === false) {
                return $fail('transfer.err_zip');
            }
            $target = $dir . '/' . $rel;
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0750, true);
            }
            $out = fopen($target, 'wb');
            $size = 0;
            while (!feof($in)) {
                $chunk = fread($in, 1 << 20);
                if ($chunk === false) {
                    break;
                }
                $size += strlen($chunk);
                $total += strlen($chunk);
                if ($total > self::MAX_BYTES || (str_ends_with($rel, '.json') && $size > self::MAX_JSON)) {
                    fclose($in);
                    fclose($out);
                    return $fail('transfer.err_limits');
                }
                fwrite($out, $chunk);
            }
            fclose($in);
            fclose($out);
        }
        $zip->close();

        $manifest = null;
        if (is_file($dir . '/manifest.json')) {
            $manifest = json_decode((string)file_get_contents($dir . '/manifest.json'), true);
            if (!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT) {
                return $fail('transfer.err_format');
            }
            if ((int)($manifest['version'] ?? 0) > self::VERSION) {
                return $fail('transfer.err_version', ['v' => (string)($manifest['version'] ?? '?')]);
            }
        }
        foreach (glob($dir . '/data/*.json') ?: [] as $f) {
            if (!self::validStore(basename($f, '.json'), json_decode((string)file_get_contents($f), true))) {
                return $fail('transfer.err_json', ['name' => basename($f)]);
            }
        }
        if (is_file($dir . '/config/config.json') && !is_array(json_decode((string)file_get_contents($dir . '/config/config.json'), true))) {
            return $fail('transfer.err_json', ['name' => 'config.json']);
        }
        $skipped = 0;
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        foreach (glob($dir . '/data/uploads/*/*') ?: [] as $f) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (($finfo->file($f) ?: '') !== (self::IMG_MIME[$ext] ?? '-')) {
                @unlink($f);
                $skipped++;
            }
        }
        $info = self::inspect($token);
        if (!$info || !$info['parts']) {
            return $fail('transfer.err_empty');
        }
        file_put_contents($dir . '/.meta', json_encode(['skipped' => $skipped, 'legacy' => $manifest === null]));
        return ['token' => $token];
    }

    /** Erlaubter Pfad im Paket (sonst null). */
    private static function entry(string $n, array $stores): ?string
    {
        if ($n[0] === '/' || str_contains($n, "\0") || str_contains($n, '\\') || str_contains($n, '..')) {
            return null;
        }
        if ($n === 'manifest.json' || $n === 'config/config.json') {
            return $n;
        }
        if (preg_match('#^data/([a-z_]+)\.json$#', $n, $m)) {
            return in_array($m[1], $stores, true) ? $n : null;
        }
        if (preg_match('#^data/uploads/(orig|medium|thumb)/[A-Za-z0-9_-]+\.(jpe?g|png|gif|webp)$#i', $n)
            || preg_match('#^data/logs/\d{4}-\d{2}\.jsonl$#', $n)) {
            return $n;
        }
        return null;
    }

    /** Grobe Formprüfung der Datendatei. */
    private static function validStore(string $name, mixed $d): bool
    {
        if (!is_array($d)) {
            return false;
        }
        return match ($name) {
            'checklist' => is_array($d['categories'] ?? null) && is_array($d['items'] ?? null),
            'gallery'   => is_array($d['albums'] ?? null) && is_array($d['images'] ?? null),
            'users'     => !array_filter($d, fn($u) => !is_array($u) || !is_string($u['id'] ?? null) || !is_string($u['username'] ?? null)),
            default     => true,
        };
    }

    /**
     * Inhalt eines entpackten Pakets: gefundene Bereiche mit Kennzahlen, Paketinfo, Konfigurationsvergleich.
     * @return array|null null, wenn das Paket nicht (mehr) existiert
     */
    public static function inspect(string $token): ?array
    {
        $dir = self::stageDir($token);
        if ($dir === null) {
            return null;
        }
        $read = fn(string $name) => json_decode((string)@file_get_contents($dir . '/data/' . $name . '.json'), true);
        $images = count(glob($dir . '/data/uploads/*/*') ?: []);
        $logs = count(glob($dir . '/data/logs/*.jsonl') ?: []);
        $found = [];
        foreach (self::parts() as $k => $p) {
            $have = array_filter($p['stores'], fn($s) => is_file($dir . '/data/' . $s . '.json'));
            $ok = $have || (!empty($p['logs']) && $logs) || ($k === 'configphp' && is_file($dir . '/config/config.json'));
            if (!$ok) {
                continue;
            }
            $c = [];
            if ($k === 'gallery') {
                $g = $read('gallery');
                $c = ['albums' => count($g['albums']), 'images' => count($g['images']), 'files' => $images];
            } elseif ($k === 'checklist') {
                $d = $read('checklist');
                $c = ['cats' => count($d['categories']), 'items' => count($d['items'])];
            } elseif ($k === 'plugindata') {
                $c = ['n' => count($have)];
            } elseif (!empty($p['logs'])) {
                $c = ['n' => $logs];
            } elseif (in_array($k, ['contacts', 'users', 'shares', 'groups'], true)) {
                $c = ['n' => count($read($k))];
            }
            $found[$k] = $c;
        }
        $manifest = json_decode((string)@file_get_contents($dir . '/manifest.json'), true);
        $meta = json_decode((string)@file_get_contents($dir . '/.meta'), true) ?: [];
        $users = is_file($dir . '/data/users.json') ? $read('users') : null;
        return [
            'token'    => $token,
            'parts'    => $found,
            'manifest' => is_array($manifest) ? $manifest : null,
            'legacy'   => !is_array($manifest),
            'skipped'  => (int)($meta['skipped'] ?? 0),
            'noAdmin'  => $users !== null && !array_filter($users, fn($u) => ($u['role'] ?? '') === 'admin' && ($u['active'] ?? true)),
            'config'   => is_file($dir . '/config/config.json') ? self::configRows((array)json_decode((string)file_get_contents($dir . '/config/config.json'), true)) : null,
        ];
    }

    /** Vergleich der Konfigurationswerte: [rows (key, here, pkg, differs), PHP-Auszug der abweichenden Werte]. */
    private static function configRows(array $pkg): array
    {
        $rows = [];
        $snippet = '';
        foreach (self::CONFIG_KEYS as $k) {
            if (!array_key_exists($k, $pkg)) {
                continue;
            }
            $here = $GLOBALS['CFG'][$k] ?? null;
            $differs = $here !== $pkg[$k];
            $rows[] = ['key' => $k, 'here' => $here, 'pkg' => $pkg[$k], 'differs' => $differs];
            if ($differs) {
                $snippet .= "    '" . $k . "' => " . var_export($pkg[$k], true) . ",\n";
            }
        }
        return ['rows' => $rows, 'snippet' => $snippet];
    }

    /* ------------------------------------------------------------ Import: anwenden */

    /**
     * Ersetzt die gewählten Bereiche. Vorher: Sicherung der JSON-Dateien.
     * @return array{error?: string, parts?: array, snapshot?: string, users?: bool}
     */
    public static function apply(string $token, array $selected): array
    {
        $dir = self::stageDir($token);
        $info = $dir ? self::inspect($token) : null;
        if (!$dir || !$info) {
            return ['error' => 'transfer.err_token'];
        }
        $sel = array_values(array_intersect(array_keys($info['parts']), $selected));
        $sel = array_values(array_diff($sel, ['configphp'])); // nur Ansicht
        if (!$sel) {
            return ['error' => 'transfer.err_nopart'];
        }
        if (in_array('users', $sel, true) && $info['noAdmin']) {
            return ['error' => 'transfer.err_noadmin'];
        }
        @set_time_limit(900);
        $snapshot = self::snapshot();
        $parts = self::parts();
        foreach ($sel as $k) {
            foreach ($parts[$k]['stores'] as $name) {
                $f = $dir . '/data/' . $name . '.json';
                if (!is_file($f)) {
                    continue;
                }
                $new = json_decode((string)file_get_contents($f), true);
                Store::update($name, function (array &$d) use ($new, $name) {
                    $keep = $name === 'settings' ? (string)($d['base_url'] ?? '') : '';
                    $d = $new;
                    if ($keep !== '') { // die Adresse gehört zu dieser Installation
                        $d['base_url'] = $keep;
                    }
                });
            }
            if (!empty($parts[$k]['images'])) {
                foreach (self::VARIANTS as $v) {
                    foreach (glob($dir . '/data/uploads/' . $v . '/*') ?: [] as $f) {
                        self::place($f, DATA_DIR . '/uploads/' . $v . '/' . basename($f));
                    }
                }
            }
            if (!empty($parts[$k]['logs'])) {
                foreach (glob($dir . '/data/logs/*.jsonl') ?: [] as $f) {
                    self::place($f, DATA_DIR . '/logs/' . basename($f));
                }
            }
        }
        self::rmtree($dir);
        return ['parts' => $sel, 'snapshot' => basename($snapshot), 'users' => in_array('users', $sel, true)];
    }

    /** Verwirft ein entpacktes Paket. */
    public static function drop(string $token): void
    {
        $dir = self::stageDir($token);
        if ($dir !== null) {
            self::rmtree($dir);
        }
    }

    private static function place(string $from, string $to): void
    {
        if (!is_dir(dirname($to))) {
            @mkdir(dirname($to), 0750, true);
        }
        $tmp = $to . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@copy($from, $tmp)) {
            @chmod($tmp, 0640);
            rename($tmp, $to);
        }
    }

    /** Kopie aller JSON-Dateien in data/backups/vor-import-<Zeitstempel>/ (die letzten Sicherungen bleiben). */
    private static function snapshot(): string
    {
        $base = DATA_DIR . '/backups';
        $dir = $base . '/vor-import-' . date('Y-m-d_His');
        @mkdir($dir, 0750, true);
        foreach (glob(DATA_DIR . '/*.json') ?: [] as $f) {
            if (basename($f) !== 'resets.json') {
                @copy($f, $dir . '/' . basename($f));
            }
        }
        $old = glob($base . '/vor-import-*', GLOB_ONLYDIR) ?: [];
        sort($old);
        while (count($old) > self::KEEP_SNAPSHOTS) {
            self::rmtree((string)array_shift($old));
        }
        return $dir;
    }

    /* ------------------------------------------------------------ Hilfen */

    private static function stageDir(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $dir = DATA_DIR . '/tmp/import-' . $token;
        return is_dir($dir) && !is_link($dir) ? $dir : null;
    }

    /** Entpackte Pakete, die älter als eine Stunde sind, entfernen. */
    private static function purgeStages(): void
    {
        foreach (glob(DATA_DIR . '/tmp/import-*', GLOB_ONLYDIR) ?: [] as $d) {
            if (filemtime($d) < time() - 3600) {
                self::rmtree($d);
            }
        }
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
