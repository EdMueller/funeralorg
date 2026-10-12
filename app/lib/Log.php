<?php
declare(strict_types=1);

/**
 * Ereignisprotokoll als JSON-Lines, eine Datei pro Monat: data/logs/YYYY-MM.jsonl
 */
final class Log
{
    public const ACTIONS = ['login_ok', 'login_fail', 'login_blocked', 'logout', 'pw_reset', 'share_open', 'img_view',
        'img_download', 'zip_download', 'upload', 'export', 'change', 'admin'];

    /** @return array<string, string> Aktion => übersetzte Beschriftung */
    public static function actions(): array
    {
        return array_combine(self::ACTIONS, array_map(fn($a) => t('log.action.' . $a), self::ACTIONS));
    }

    private static function dir(): string
    {
        return DATA_DIR . '/logs';
    }

    public static function add(string $action, string $object = '', array $meta = []): void
    {
        $u = Auth::user();
        $row = [
            't'  => time(),
            'a'  => $action,
            'u'  => $u['username'] ?? '',
            'n'  => $u['name'] ?? '',
            'k'  => $u['kind'] ?? '',
            'o'  => mb_substr($object, 0, 200),
            'ip' => self::ip(client_ip()),
        ];
        if (!empty($u['via'])) {
            $row['v'] = $u['via'];
        }
        if ($meta) {
            $row['m'] = $meta;
        }
        if (!is_dir(self::dir())) {
            @mkdir(self::dir(), 0750, true);
        }
        @file_put_contents(
            self::dir() . '/' . date('Y-m') . '.jsonl',
            json_encode($row, JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /** Protokolliert eine Datenänderung (Änderungsprotokoll). */
    public static function change(string $module, string $what, string $object = ''): void
    {
        self::add('change', $object, ['mod' => $module, 'what' => $what]);
    }

    public static function ip(string $ip): string
    {
        if (cfg('log_full_ip', false)) {
            return $ip;
        }
        if (str_contains($ip, ':')) {
            $bin = @inet_pton($ip);
            if ($bin === false) {
                return '';
            }
            return (string)inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10));
        }
        return preg_replace('/\.\d+$/', '.0', $ip) ?? '';
    }

    /** Liest Ereignisse ab einem Zeitpunkt (neueste zuletzt). */
    public static function since(int $from): array
    {
        $rows = [];
        $files = glob(self::dir() . '/*.jsonl') ?: [];
        sort($files);
        $minMonth = date('Y-m', $from);
        foreach ($files as $f) {
            if (basename($f, '.jsonl') < $minMonth) {
                continue;
            }
            $h = fopen($f, 'r');
            if (!$h) {
                continue;
            }
            while (($line = fgets($h)) !== false) {
                $r = json_decode($line, true);
                if (is_array($r) && ($r['t'] ?? 0) >= $from) {
                    $rows[] = $r;
                }
            }
            fclose($h);
        }
        return $rows;
    }

    /** Aggregierte Statistik mit Datei-Cache (invalidiert bei Log-Änderung). */
    public static function stats(int $days): array
    {
        $files = glob(self::dir() . '/*.jsonl') ?: [];
        $sig = $days . '|' . date('Y-m-d');
        foreach ($files as $f) {
            $sig .= '|' . basename($f) . ':' . filesize($f);
        }
        $cacheDir = DATA_DIR . '/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0750, true);
        }
        $cacheFile = $cacheDir . '/stats-' . $days . '.json';
        if (is_file($cacheFile)) {
            $c = json_decode((string)file_get_contents($cacheFile), true);
            if (($c['sig'] ?? '') === $sig) {
                return $c['data'];
            }
        }

        $from = strtotime('today') - ($days - 1) * 86400;
        $rows = self::since($from);

        $perDay = [];
        for ($i = 0; $i < $days; $i++) {
            $d = date('Y-m-d', $from + $i * 86400);
            $perDay[$d] = ['logins' => 0, 'views' => 0, 'downloads' => 0, 'uploads' => 0, 'fails' => 0];
        }
        $totals = array_fill_keys(self::ACTIONS, 0);
        $users = [];
        $imgViews = [];
        $imgDownloads = [];
        $failIps = [];
        $today = date('Y-m-d');
        $todayLogins = 0;

        foreach ($rows as $r) {
            $a = $r['a'];
            $d = date('Y-m-d', $r['t']);
            $totals[$a] = ($totals[$a] ?? 0) + 1;
            $key = match ($a) {
                'login_ok' => 'logins',
                'img_view' => 'views',
                'img_download', 'zip_download' => 'downloads',
                'upload' => 'uploads',
                'login_fail', 'login_blocked' => 'fails',
                default => null,
            };
            if ($key && isset($perDay[$d])) {
                $perDay[$d][$key]++;
            }
            if ($a === 'login_ok' && $d === $today) {
                $todayLogins++;
            }
            if ($a === 'login_fail') {
                $failIps[$r['ip']] = ($failIps[$r['ip']] ?? 0) + 1;
            }
            if ($r['u'] !== '' && $a !== 'login_fail' && $a !== 'login_blocked') {
                $u = &$users[$r['u']];
                $u ??= ['name' => $r['n'], 'kind' => $r['k'], 'logins' => 0, 'views' => 0, 'downloads' => 0,
                        'uploads' => 0, 'changes' => 0, 'last' => 0];
                $u['last'] = max($u['last'], $r['t']);
                match ($a) {
                    'login_ok', 'share_open' => $u['logins']++,
                    'img_view' => $u['views']++,
                    'img_download', 'zip_download' => $u['downloads']++,
                    'upload' => $u['uploads']++,
                    'change' => $u['changes']++,
                    default => null,
                };
                unset($u);
            }
            if ($a === 'img_view' && $r['o'] !== '') {
                $imgViews[$r['o']] = ($imgViews[$r['o']] ?? 0) + 1;
            }
            if ($a === 'img_download' && $r['o'] !== '') {
                $imgDownloads[$r['o']] = ($imgDownloads[$r['o']] ?? 0) + 1;
            }
        }
        arsort($imgViews);
        arsort($imgDownloads);
        arsort($failIps);
        uasort($users, fn($x, $y) => $y['last'] <=> $x['last']);

        $data = [
            'days'          => $days,
            'per_day'       => $perDay,
            'totals'        => $totals,
            'today_logins'  => $todayLogins,
            'users'         => $users,
            'top_views'     => array_slice($imgViews, 0, 8, true),
            'top_downloads' => array_slice($imgDownloads, 0, 8, true),
            'fail_ips'      => array_slice(array_filter($failIps, fn($c) => $c >= 3), 0, 10, true),
        ];
        @file_put_contents($cacheFile, json_encode(['sig' => $sig, 'data' => $data], JSON_UNESCAPED_UNICODE));
        return $data;
    }

    /** Einmal täglich: alte Logs, Temp-Dateien und Caches aufräumen. */
    public static function maintenance(): void
    {
        $marker = DATA_DIR . '/cache/maintenance-' . date('Y-m-d');
        if (is_file($marker)) {
            return;
        }
        @mkdir(DATA_DIR . '/cache', 0750, true);
        foreach (glob(DATA_DIR . '/cache/maintenance-*') ?: [] as $f) {
            @unlink($f);
        }
        @touch($marker);

        $keepMonths = (int)cfg('log_retention_months', 12);
        $limit = date('Y-m', strtotime('first day of -' . $keepMonths . ' months'));
        foreach (glob(self::dir() . '/*.jsonl') ?: [] as $f) {
            if (basename($f, '.jsonl') < $limit) {
                @unlink($f);
            }
        }
        Reset::purge();
        foreach (glob(DATA_DIR . '/tmp/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 3600) {
                @unlink($f);
            }
        }
    }
}
