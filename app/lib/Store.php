<?php
declare(strict_types=1);

/**
 * Dateibasierter JSON-Speicher.
 * - Lesen mit Shared-Lock, Schreiben mit Exclusive-Lock auf separater .lock-Datei
 * - Atomares Schreiben: temporäre Datei + rename()
 * - Tägliche Snapshots aller JSON-Dateien in data/backups/YYYY-MM-DD/
 */
final class Store
{
    /** Bekannte Datendateien mit Standardinhalt. */
    public const DEFAULTS = [
        'users'      => [],
        'groups'     => [],
        'shares'     => [],
        'contacts'   => [],
        'checklist'  => ['categories' => [], 'items' => []],
        'memorial'   => ['name' => '', 'born' => '', 'died' => '', 'quote' => '', 'text' => '', 'portrait' => ''],
        'gallery'    => ['albums' => [], 'images' => []],
        'selections' => [],
        'ratelimit'  => [],
        'settings'   => [],
        'resets'     => ['tokens' => [], 'requests' => [], 'rl' => []],
    ];

    /** Von Plugins ergänzte Datendateien (Name => Standardinhalt). */
    private static array $extra = [];

    private static array $cache = [];

    /** Meldet eine Plugin-Datendatei an (Standardinhalt, solange die Datei fehlt). */
    public static function extend(string $name, array $default): void
    {
        if (!isset(self::DEFAULTS[$name])) {
            self::$extra[$name] = $default;
        }
    }

    public static function file(string $name): string
    {
        if (!preg_match('/^[a-z_]+$/', $name)) {
            throw new InvalidArgumentException('Ungültiger Store-Name');
        }
        return DATA_DIR . '/' . $name . '.json';
    }

    public static function read(string $name): array
    {
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }
        $lock = self::lock($name, LOCK_SH);
        $data = self::load($name);
        self::unlock($lock);
        return self::$cache[$name] = $data;
    }

    /**
     * Liest, verändert und schreibt eine Datei unter exklusivem Lock.
     * Der Callback bekommt die Daten per Referenz und darf einen Rückgabewert liefern.
     */
    public static function update(string $name, callable $fn): mixed
    {
        self::dailyBackup();
        $lock = self::lock($name, LOCK_EX);
        try {
            $data = self::load($name);
            $result = $fn($data);
            self::write($name, $data);
            self::$cache[$name] = $data;
            return $result;
        } finally {
            self::unlock($lock);
        }
    }

    private static function load(string $name): array
    {
        $f = self::file($name);
        if (!is_file($f)) {
            return self::DEFAULTS[$name] ?? self::$extra[$name] ?? [];
        }
        $raw = file_get_contents($f);
        $data = json_decode($raw === false ? '' : $raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Datei {$name}.json ist beschädigt – bitte aus data/backups wiederherstellen.");
        }
        return $data;
    }

    private static function write(string $name, array $data): void
    {
        $f = self::file($name);
        $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json) === false) {
            throw new RuntimeException("Konnte {$name}.json nicht schreiben");
        }
        @chmod($tmp, 0640);
        if (!rename($tmp, $f)) {
            @unlink($tmp);
            throw new RuntimeException("Konnte {$name}.json nicht ersetzen");
        }
    }

    /** @return resource */
    private static function lock(string $name, int $mode)
    {
        $h = fopen(DATA_DIR . '/' . $name . '.lock', 'c');
        if ($h === false || !flock($h, $mode)) {
            throw new RuntimeException("Lock für {$name} fehlgeschlagen");
        }
        return $h;
    }

    /** @param resource $h */
    private static function unlock($h): void
    {
        flock($h, LOCK_UN);
        fclose($h);
    }

    /** Legt einmal pro Tag einen Snapshot an und rotiert alte Snapshots. */
    public static function dailyBackup(bool $force = false): ?string
    {
        $base = DATA_DIR . '/backups';
        $dir = $base . '/' . date('Y-m-d');
        if (!$force && is_dir($dir)) {
            return null;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return null;
        }
        foreach (glob(DATA_DIR . '/*.json') ?: [] as $f) {
            if (basename($f) !== 'resets.json') { // flüchtig (Tokens, Anfragen): nicht in Snapshots
                @copy($f, $dir . '/' . basename($f));
            }
        }
        $keep = (int)cfg('backup_keep_days', 14);
        $dirs = glob($base . '/????-??-??', GLOB_ONLYDIR) ?: [];
        sort($dirs);
        while (count($dirs) > $keep) {
            $old = array_shift($dirs);
            foreach (glob($old . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($old);
        }
        return $dir;
    }
}
