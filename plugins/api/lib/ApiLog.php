<?php
declare(strict_types=1);

/**
 * Zugriffsprotokoll der API: eine Zeile je REST-Aufruf bzw. MCP-Anfrage (initialize, tools/call) in
 * data/logs/api/JJJJ-MM.jsonl. Protokolliert werden Zeit, Token, Benutzer, Operation, Pfad, Status und die Namen der
 * übergebenen Parameter, nie deren Werte (die enthalten persönliche Daten). Fehlgeschlagene Anmeldungen stehen mit IP und
 * Client-Kennung darin (höchstens eine Zeile je IP und Minute). Aufbewahrung wie das übrige Protokoll (`log_retention_months`).
 */
final class ApiLog
{
    /** @var array<string, mixed>|null laufender REST-Aufruf, wird am Ende der Anfrage geschrieben */
    private static ?array $cur = null;

    private static function dir(): string
    {
        return DATA_DIR . '/logs/api';
    }

    private static function base(string $proto, ?array $auth): array
    {
        $ua = preg_replace('/[^\x20-\x7e]/', '', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return [
            't'     => time(),
            'proto' => $proto,
            'token' => $auth['token']['id'] ?? '',
            'label' => $auth['token']['label'] ?? '',
            'user'  => $auth['user']['username'] ?? '',
            'ip'    => Log::ip(client_ip()),
            'ua'    => mb_substr((string)$ua, 0, 80),
        ];
    }

    private static function write(array $row): void
    {
        if (!is_dir(self::dir())) {
            @mkdir(self::dir(), 0750, true);
        }
        @file_put_contents(self::dir() . '/' . date('Y-m') . '.jsonl', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        self::purge();
    }

    /** Beginnt das Protokoll eines REST-Aufrufs; geschrieben wird am Ende der Anfrage mit dem endgültigen Status. */
    public static function start(array $auth, string $method, string $path, array $argNames): void
    {
        self::$cur = self::base('rest', $auth) + ['method' => $method, 'path' => $path, 'op' => '', 'write' => false, 'args' => $argNames];
        register_shutdown_function([self::class, 'finish']);
    }

    public static function args(array $names): void
    {
        if (self::$cur) {
            self::$cur['args'] = array_values($names);
        }
    }

    public static function op(array $op): void
    {
        if (self::$cur) {
            self::$cur['op'] = $op['id'];
            self::$cur['write'] = $op['write'];
        }
    }

    public static function finish(): void
    {
        if (!self::$cur) {
            return;
        }
        $row = self::$cur;
        self::$cur = null;
        $row['status'] = http_response_code() ?: 200;
        $row['ok'] = $row['status'] < 400;
        self::write($row);
    }

    /** Eine MCP-Anfrage (initialize oder Tool-Aufruf). */
    public static function mcp(array $auth, string $op, bool $write, bool $ok, array $argNames = []): void
    {
        self::write(self::base('mcp', $auth) + ['method' => 'MCP', 'path' => '', 'op' => $op, 'write' => $write, 'args' => $argNames, 'status' => 200, 'ok' => $ok]);
    }

    /** Fehlgeschlagene Anmeldung; höchstens eine Zeile je IP und Minute. */
    public static function fail(string $proto): void
    {
        $marker = DATA_DIR . '/cache/apifail_' . md5(client_ip());
        if (is_file($marker) && filemtime($marker) > time() - 60) {
            return;
        }
        if (!is_dir(dirname($marker))) {
            @mkdir(dirname($marker), 0750, true);
        }
        @touch($marker);
        self::write(self::base($proto, null) + ['method' => $proto === 'mcp' ? 'MCP' : (string)($_SERVER['REQUEST_METHOD'] ?? ''), 'path' => '', 'op' => 'auth', 'write' => false, 'args' => [], 'status' => 401, 'ok' => false]);
    }

    /** Alte Monatsdateien und Marker höchstens einmal am Tag entfernen. */
    private static function purge(): void
    {
        $stamp = DATA_DIR . '/cache/apilog_purge';
        if (is_file($stamp) && filemtime($stamp) > time() - 86400) {
            return;
        }
        if (!is_dir(dirname($stamp))) {
            @mkdir(dirname($stamp), 0750, true);
        }
        @touch($stamp);
        $limit = date('Y-m', strtotime('first day of -' . (int)cfg('log_retention_months', 12) . ' months'));
        foreach (glob(self::dir() . '/*.jsonl') ?: [] as $f) {
            if (basename($f, '.jsonl') < $limit) {
                @unlink($f);
            }
        }
        foreach (glob(DATA_DIR . '/cache/apifail_*') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
    }

    /**
     * Einträge der letzten `$days` Tage, neueste zuerst.
     * @param array{token?: string, user?: string, proto?: string, only?: string} $filter
     */
    public static function read(int $days, array $filter = []): array
    {
        $since = time() - $days * 86400;
        $months = [];
        for ($t = $since; $t <= time() + 86400; $t += 15 * 86400) {
            $months[date('Y-m', $t)] = true;
        }
        $months[date('Y-m')] = true;
        $rows = [];
        foreach (array_keys($months) as $m) {
            $f = self::dir() . '/' . $m . '.jsonl';
            if (!is_file($f)) {
                continue;
            }
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $r = json_decode($line, true);
                if (!is_array($r) || ($r['t'] ?? 0) < $since) {
                    continue;
                }
                if (($filter['token'] ?? '') !== '' && ($r['token'] ?? '') !== $filter['token']) {
                    continue;
                }
                if (($filter['user'] ?? '') !== '' && ($r['user'] ?? '') !== $filter['user']) {
                    continue;
                }
                if (($filter['proto'] ?? '') !== '' && ($r['proto'] ?? '') !== $filter['proto']) {
                    continue;
                }
                $only = $filter['only'] ?? '';
                if (($only === 'write' && empty($r['write'])) || ($only === 'error' && !empty($r['ok']))) {
                    continue;
                }
                $rows[] = $r;
            }
        }
        usort($rows, fn($a, $b) => $b['t'] <=> $a['t']);
        return $rows;
    }

    /** Kennzahlen je Token (Aufrufe, schreibende, fehlerhafte, zuletzt). @return array<string, array> */
    public static function stats(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = ($r['token'] ?? '') ?: '-';
            $out[$k] ??= ['label' => $r['label'] ?: '–', 'user' => $r['user'] ?: '–', 'calls' => 0, 'writes' => 0, 'errors' => 0, 'last' => 0];
            $out[$k]['calls']++;
            $out[$k]['writes'] += !empty($r['write']) ? 1 : 0;
            $out[$k]['errors'] += empty($r['ok']) ? 1 : 0;
            $out[$k]['last'] = max($out[$k]['last'], (int)$r['t']);
        }
        uasort($out, fn($a, $b) => $b['last'] <=> $a['last']);
        return $out;
    }
}
