<?php
declare(strict_types=1);

/**
 * Persönliche Zugriffstoken. Gespeichert wird nur der SHA-256-Hash (Token mit 256 Bit Zufall, daher ohne Salt/Stretching
 * sicher). Ein Token handelt immer als sein Besitzer (Rolle, Gruppenrechte, Feldrechte) und ist zusätzlich auf
 * `read` oder `write` begrenzt. Es wird ungültig bei Ablauf, Widerruf, deaktiviertem Besitzer oder Passwortwechsel.
 */
final class ApiTokens
{
    public const SCOPES = ['read', 'write'];
    public const PREFIX = 'fo_';

    private static function store(): array
    {
        return Store::read('api_tokens') + ['tokens' => [], 'user_tokens' => false];
    }

    /** @return list<array> */
    public static function all(): array
    {
        return array_values(self::store()['tokens']);
    }

    public static function userTokensAllowed(): bool
    {
        return !empty(self::store()['user_tokens']);
    }

    public static function setUserTokens(bool $on): void
    {
        Store::update('api_tokens', function (array &$d) use ($on) {
            $d += ['tokens' => []];
            $d['user_tokens'] = $on;
        });
    }

    /** Darf der aktuelle Benutzer Token verwalten? Admins immer, andere nur wenn freigegeben; Freigabelinks nie. */
    public static function mayManage(): bool
    {
        $u = Auth::user();
        if (!$u || $u['kind'] !== 'user') {
            return false;
        }
        return Auth::isAdmin() || self::userTokensAllowed();
    }

    /** Legt ein Token an und liefert den Klartext (nur dieses eine Mal). */
    public static function create(string $userId, string $label, string $scope, int $days): string
    {
        $raw = self::PREFIX . bin2hex(random_bytes(32));
        $rec = [
            'id'        => new_id(),
            'user'      => $userId,
            'label'     => mb_substr($label, 0, 80),
            'scope'     => in_array($scope, self::SCOPES, true) ? $scope : 'read',
            'hash'      => hash('sha256', $raw),
            'hint'      => substr($raw, 0, 7) . '…' . substr($raw, -4),
            'created'   => time(),
            'expires'   => $days > 0 ? time() + $days * 86400 : 0,
            'last_used' => 0,
        ];
        Store::update('api_tokens', function (array &$d) use ($rec) {
            $d += ['tokens' => []];
            $d['tokens'][] = $rec;
        });
        return $raw;
    }

    /** Widerruft ein Token; `$onlyUser` beschränkt auf Token dieses Benutzers (leer = beliebig, für Admins). */
    public static function revoke(string $id, string $onlyUser = ''): ?array
    {
        return Store::update('api_tokens', function (array &$d) use ($id, $onlyUser) {
            foreach ($d['tokens'] ?? [] as $k => $t) {
                if ($t['id'] === $id && ($onlyUser === '' || $t['user'] === $onlyUser)) {
                    unset($d['tokens'][$k]);
                    $d['tokens'] = array_values($d['tokens']);
                    return $t;
                }
            }
            return null;
        });
    }

    /** Klartext-Token aus dem Authorization-Header (Bearer) bzw. X-Api-Token. */
    public static function bearer(): string
    {
        $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($h === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strtolower((string)$k) === 'authorization') {
                    $h = (string)$v;
                }
            }
        }
        if (preg_match('/^Bearer\s+(\S+)\s*$/i', $h, $m)) {
            return $m[1];
        }
        return trim((string)($_SERVER['HTTP_X_API_TOKEN'] ?? ''));
    }

    /**
     * Prüft ein Klartext-Token.
     * @return array{user: array, token: array}|null
     */
    public static function authenticate(string $raw): ?array
    {
        if ($raw === '' || !str_starts_with($raw, self::PREFIX) || strlen($raw) > 200) {
            return null;
        }
        $hash = hash('sha256', $raw);
        $found = null;
        foreach (self::all() as $t) {
            if (hash_equals((string)$t['hash'], $hash)) {
                $found = $t;
            }
        }
        if (!$found || ($found['expires'] > 0 && $found['expires'] < time())) {
            return null;
        }
        $u = find_by_id(Store::read('users'), (string)$found['user']);
        if (!$u || empty($u['active']) || (int)($u['pw_changed'] ?? 0) > (int)$found['created']) {
            return null;
        }
        if (time() - (int)$found['last_used'] > 600) { // Nutzung höchstens alle 10 Minuten vermerken
            $id = $found['id'];
            Store::update('api_tokens', function (array &$d) use ($id) {
                foreach ($d['tokens'] as &$t) {
                    if ($t['id'] === $id) {
                        $t['last_used'] = time();
                    }
                }
            });
        }
        return ['user' => $u, 'token' => $found];
    }

    /** Tokens nur über HTTPS (hinter Proxy: X-Forwarded-Proto), Loopback oder mit `api_allow_http` in configs/config.php. */
    public static function secureTransport(): bool
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $ip = client_ip();
        return $https || $ip === '127.0.0.1' || $ip === '::1' || (bool)cfg('api_allow_http', false);
    }

    /** Authentifiziert die Anfrage; bei Erfolg handelt die Anwendung als Token-Besitzer. */
    public static function login(): ?array
    {
        $auth = self::authenticate(self::bearer());
        if (!$auth) {
            usleep(250000); // bremst das Durchprobieren
            return null;
        }
        Auth::actAs($auth['user']);
        return $auth;
    }
}
