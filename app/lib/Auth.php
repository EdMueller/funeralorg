<?php
declare(strict_types=1);

final class Auth
{
    private static ?array $ctx = null;
    private static bool $resolved = false;

    /** Aktueller Kontext: angemeldeter Benutzer oder Freigabelink. */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$ctx;
        }
        self::$resolved = true;

        if (!empty($_SESSION['uid'])) {
            $u = find_by_id(Store::read('users'), (string)$_SESSION['uid']);
            // Passwort seit Anmeldung geändert (Reset, Admin, Konto) → Sitzung ungültig
            if ($u && !empty($u['pw_changed']) && (int)($_SESSION['login_t'] ?? 0) < (int)$u['pw_changed']) {
                $u = null;
            }
            if ($u && !empty($u['active'])) {
                $ctx = [
                    'kind'     => 'user',
                    'id'       => $u['id'],
                    'name'     => $u['name'] ?: $u['username'],
                    'username' => $u['username'],
                    'role'     => $u['role'],
                    'group_id' => $u['group_id'] ?? '',
                ];
                if (!empty($_SESSION['imp']['admin'])) {
                    $adm = find_by_id(Store::read('users'), (string)$_SESSION['imp']['admin']);
                    $ctx['via'] = $adm['username'] ?? '';
                }
                return self::$ctx = $ctx;
            }
            unset($_SESSION['uid'], $_SESSION['imp']);
        }
        if (!empty($_SESSION['share_id'])) {
            $s = self::validShare(find_by_id(Store::read('shares'), (string)$_SESSION['share_id']));
            if ($s) {
                return self::$ctx = [
                    'kind'     => 'share',
                    'id'       => $s['id'],
                    'name'     => $s['label'],
                    'username' => 'link:' . $s['label'],
                    'role'     => 'share',
                    'group_id' => $s['group_id'],
                ];
            }
            unset($_SESSION['share_id']);
        }
        return self::$ctx = null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    public static function isShare(): bool
    {
        return (self::user()['kind'] ?? '') === 'share';
    }

    /** Admin ist per „Als Benutzer anmelden“ in einem fremden Konto. */
    public static function isImpersonating(): bool
    {
        return !empty($_SESSION['imp']['admin']) && !empty($_SESSION['uid']);
    }

    /**
     * Admin übernimmt das Konto eines normalen Benutzers.
     * `login_t` wird neu gesetzt, damit ein jüngeres `pw_changed` des Ziels die Sitzung nicht beendet.
     */
    public static function impersonate(string $targetId): ?array
    {
        $me = self::user();
        if (!$me || $me['kind'] !== 'user' || $me['role'] !== 'admin' || self::isImpersonating() || $targetId === $me['id']) {
            return null;
        }
        $target = find_by_id(Store::read('users'), $targetId);
        if (!$target || empty($target['active']) || $target['role'] === 'admin') {
            return null;
        }
        $imp = ['admin' => $me['id'], 'login_t' => (int)($_SESSION['login_t'] ?? 0)];
        session_regenerate_id(true);
        $_SESSION['imp'] = $imp;
        $_SESSION['uid'] = $target['id'];
        $_SESSION['login_t'] = time();
        self::reset();
        Log::add('admin', 'Als Benutzer angemeldet: ' . $target['username']);
        return $target;
    }

    /**
     * Beendet die Übernahme und kehrt zum Admin zurück.
     *
     * @return bool false, wenn der Admin nicht mehr gültig ist (Sitzung wurde dann komplett beendet)
     */
    public static function stopImpersonation(): bool
    {
        $imp = $_SESSION['imp'] ?? null;
        if (!$imp) {
            return false;
        }
        $adm = find_by_id(Store::read('users'), (string)$imp['admin']);
        $valid = $adm && !empty($adm['active']) && $adm['role'] === 'admin'
            && !(!empty($adm['pw_changed']) && (int)$imp['login_t'] < (int)$adm['pw_changed']);
        $target = self::user()['username'] ?? '';
        if (!$valid) {
            unset($_SESSION['imp']);
            self::reset();
            self::logout();
            return false;
        }
        Log::add('admin', 'Übernahme beendet: ' . $target);
        session_regenerate_id(true);
        unset($_SESSION['imp']);
        $_SESSION['uid'] = $adm['id'];
        $_SESSION['login_t'] = (int)$imp['login_t'];
        self::reset();
        return true;
    }

    /**
     * Führt die Anfrage als dieser Benutzer aus (API-Zugriff per Token, ohne Sitzung). Die Anmeldung hat der Aufrufer
     * bereits geprüft; Rechte ergeben sich wie bei einer Sitzung aus Rolle und Gruppe.
     */
    public static function actAs(array $u): void
    {
        self::$resolved = true;
        self::$ctx = [
            'kind'     => 'user',
            'id'       => $u['id'],
            'name'     => $u['name'] ?: $u['username'],
            'username' => $u['username'],
            'role'     => $u['role'],
            'group_id' => $u['group_id'] ?? '',
            'via'      => 'API',
        ];
    }

    public static function reset(): void
    {
        self::$ctx = null;
        self::$resolved = false;
    }

    /* -------------------------------------------------------------- Login */

    /** @return string|null Fehlermeldung oder null bei Erfolg */
    public static function attempt(string $username, string $password): ?string
    {
        $ipKey = hash('sha256', client_ip());
        $lockedFor = self::lockedSeconds($ipKey);
        if ($lockedFor > 0) {
            Log::add('login_blocked', $username);
            return t('auth.locked_ip', ['min' => (int)ceil($lockedFor / 60)]);
        }

        $userKey = 'u:' . hash('sha256', mb_strtolower(trim($username)));
        $coolFor = self::cooldownSeconds($userKey);
        if ($coolFor > 0) {
            Log::add('login_blocked', $username);
            return tn('auth.locked_user', $coolFor);
        }

        $user = null;
        foreach (Store::read('users') as $u) {
            if (mb_strtolower($u['username']) === mb_strtolower($username)) {
                $user = $u;
                break;
            }
        }
        // Gleiche Laufzeit auch bei unbekanntem Benutzer: Vergleich mit dem Hash eines vorhandenen Kontos
        // (gleiche Kosten wie echte Hashes; das Ergebnis zählt wegen `&& $user` nie)
        $hash = $user['pass_hash'] ?? (Store::read('users')[0]['pass_hash']
            ?? '$2y$10$BMW72kwLtV8Oql3gooRhguWEq9Dm3qZNwFZpCIoXeibvU8.bQa04K');
        $ok = password_verify($password, $hash) && $user && !empty($user['active']);

        if (!$ok) {
            $fails = self::registerFail($ipKey, $userKey);
            Log::add('login_fail', $username);
            if ($fails >= 3) {
                sleep(min(3, $fails - 2)); // bremst Wiederholungen, ohne das Konto zu sperren
            }
            return t('auth.bad_login');
        }

        self::clearFails($ipKey);
        self::clearFails($userKey);
        session_regenerate_id(true);
        unset($_SESSION['share_id']);
        $_SESSION['uid'] = $user['id'];
        $_SESSION['login_t'] = time();
        self::reset();

        Store::update('users', function (array &$users) use ($user, $password) {
            foreach ($users as &$u) {
                if ($u['id'] === $user['id']) {
                    $u['last_login'] = now();
                    if (password_needs_rehash($u['pass_hash'], PASSWORD_DEFAULT)) {
                        $u['pass_hash'] = password_hash($password, PASSWORD_DEFAULT);
                    }
                }
            }
        });
        Log::add('login_ok');
        Log::maintenance();
        return null;
    }

    public static function logout(): void
    {
        if (self::check()) {
            Log::add('logout');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::reset();
    }

    /* -------------------------------------------------------------- Freigabelinks */

    public static function validShare(?array $s): ?array
    {
        if (!$s || !empty($s['revoked'])) {
            return null;
        }
        if (!empty($s['expires']) && strtotime($s['expires'] . ' 23:59:59') < time()) {
            return null;
        }
        return $s;
    }

    public static function openShare(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return false;
        }
        $found = null;
        foreach (Store::read('shares') as $s) {
            if (hash_equals($s['token'], $token)) {
                $found = self::validShare($s);
                break;
            }
        }
        if (!$found) {
            return false;
        }
        // Ein angemeldeter Benutzer behält seine Sitzung
        if (!empty($_SESSION['uid'])) {
            return true;
        }
        session_regenerate_id(true);
        $_SESSION['share_id'] = $found['id'];
        self::reset();
        Store::update('shares', function (array &$shares) use ($found) {
            foreach ($shares as &$s) {
                if ($s['id'] === $found['id']) {
                    $s['last_used'] = now();
                    $s['uses'] = ($s['uses'] ?? 0) + 1;
                }
            }
        });
        Log::add('share_open', $found['label']);
        return true;
    }

    /* -------------------------------------------------------------- Rate-Limit */

    private static function lockedSeconds(string $key): int
    {
        $row = Store::read('ratelimit')[$key] ?? null;
        if (!$row || empty($row['locked_until'])) {
            return 0;
        }
        return max(0, (int)$row['locked_until'] - time());
    }

    /** Restliche Wartezeit (Sekunden), wenn für diesen Benutzernamen zu viele Fehlversuche kamen. */
    private static function cooldownSeconds(string $userKey): int
    {
        $row = Store::read('ratelimit')[$userKey] ?? null;
        return $row ? max(0, (int)($row['next_ok'] ?? 0) - time()) : 0;
    }

    /**
     * Zählt einen Fehlversuch je IP (Sperre mit wachsender Dauer) und je Benutzername
     * (kurze, wachsende Wartezeit, keine Dauersperre – verhindert verteilte Angriffe,
     * ohne dass Fremde ein Konto aussperren können).
     *
     * @return int Fehlversuche dieser IP in Folge (für die Antwortverzögerung)
     */
    private static function registerFail(string $ipKey, string $userKey): int
    {
        $max = (int)cfg('login_max_attempts', 5);
        $win = (int)cfg('login_window_minutes', 15) * 60;
        $lock = (int)cfg('login_lock_minutes', 15) * 60;
        $lockMax = (int)cfg('login_lock_max_hours', 24) * 3600;
        $userMax = (int)cfg('login_max_attempts_user', 10);
        $userCool = (int)cfg('login_user_cooldown_max_seconds', 60);
        return Store::update('ratelimit', function (array &$rl) use ($ipKey, $userKey, $max, $win, $lock, $lockMax, $userMax, $userCool) {
            $now = time();
            // Aufräumen
            foreach ($rl as $k => $r) {
                $last = max($r['first'] ?? 0, $r['last'] ?? 0);
                if ($last + $win < $now && ($r['locked_until'] ?? 0) < $now && ($r['strike_at'] ?? 0) + $lockMax < $now) {
                    unset($rl[$k]);
                }
            }

            // IP
            $r = $rl[$ipKey] ?? ['count' => 0, 'first' => $now, 'locked_until' => 0, 'strikes' => 0, 'strike_at' => 0];
            if ($r['strikes'] && $r['strike_at'] + $lockMax < $now) {
                $r['strikes'] = 0; // lange unauffällig: Eskalation zurücksetzen
            }
            if ($r['first'] + $win < $now) {
                $r['count'] = 0;
                $r['first'] = $now;
            }
            $r['count']++;
            $ipFails = $r['count'];
            if ($r['count'] >= $max) {
                $r['strikes']++;
                $r['strike_at'] = $now;
                $r['locked_until'] = $now + min($lockMax, $lock * (2 ** ($r['strikes'] - 1)));
                $r['count'] = 0;
                $r['first'] = $now;
            }
            $rl[$ipKey] = $r;

            // Benutzername (alle IPs zusammen)
            $u = $rl[$userKey] ?? ['count' => 0, 'last' => $now, 'next_ok' => 0];
            if ($u['last'] + $win < $now) {
                $u['count'] = 0;
            }
            $u['count']++;
            $u['last'] = $now;
            if ($u['count'] >= $userMax) {
                $u['next_ok'] = $now + min($userCool, 2 ** min(16, $u['count'] - $userMax));
            }
            $rl[$userKey] = $u;
            return $ipFails;
        });
    }

    private static function clearFails(string $key): void
    {
        Store::update('ratelimit', function (array &$rl) use ($key) {
            unset($rl[$key]);
        });
    }
}
