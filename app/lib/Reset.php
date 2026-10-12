<?php
declare(strict_types=1);

/**
 * „Passwort vergessen“.
 * Weg 1: Link per E-Mail an die Adresse des verknüpften Kontakts (users.contact_id → contacts.email).
 * Weg 2: Anfrage an den Admin (für Konten ohne E-Mail).
 *
 * Datenschutz: Tokens nur als SHA-256-Hash, 60 Minuten, einmalig. Anfragen nach 30 Tagen gelöscht.
 * Flüchtige Daten liegen in data/resets.json (nicht in Snapshots/Backups). Keine Adresse im Protokoll.
 * Antworten der öffentlichen Seiten sind immer gleich (keine Benutzer-Erkennung).
 */
final class Reset
{
    public const TTL = 3600;
    public const REQUEST_DAYS = 30;
    private const IP_MAX = 5;     // je Stunde
    private const USER_MAX = 3;   // je Stunde

    /** Absender: `mail_from` aus der Konfiguration, sonst noreply@<Domain ohne www./Port>. */
    public static function fromAddress(): string
    {
        $cfg = trim((string)cfg('mail_from', ''));
        if ($cfg !== '' && filter_var($cfg, FILTER_VALIDATE_EMAIL)) {
            return $cfg;
        }
        $host = (string)parse_url(configured_base_url(), PHP_URL_HOST);
        $host = (string)preg_replace('/^www\./i', '', $host);
        return 'noreply@' . ($host !== '' ? $host : 'localhost');
    }

    /** E-Mail-Adresse des Kontos: nur über den verknüpften Kontakt, sonst ''. */
    public static function emailOf(array $user): string
    {
        if (empty($user['contact_id']) || empty($user['active'])) {
            return '';
        }
        $c = find_by_id(Store::read('contacts'), (string)$user['contact_id']);
        $mail = trim((string)($c['email'] ?? ''));
        return ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n]/', $mail)) ? $mail : '';
    }

    /* ---------------------------------------------------------- Weg 1 */

    /**
     * Legt (falls möglich) ein Token an. Rückgabe: Mail-Auftrag oder null – das Ergebnis darf nie nach außen sichtbar werden.
     * @return array{to:string,name:string,username:string,url:string}|null
     */
    public static function request(string $username): ?array
    {
        $username = mb_strtolower(trim($username));
        if ($username === '' || mb_strlen($username) > 100) {
            return null;
        }
        // Der Link darf nie aus dem Host-Header der Anfrage entstehen (Host-Header-Manipulation)
        $base = configured_base_url();
        if ($base === '') {
            Log::add('pw_reset', 'Mailversand deaktiviert: base_url ist nicht gesetzt');
            return null;
        }
        $ipKey = 'i:' . hash('sha256', client_ip());
        $userKey = 'u:' . hash('sha256', $username);
        if (!self::allow($ipKey, self::IP_MAX) || !self::allow($userKey, self::USER_MAX)) {
            return null;
        }
        $user = null;
        foreach (Store::read('users') as $u) {
            if (mb_strtolower($u['username']) === $username) {
                $user = $u;
                break;
            }
        }
        $to = $user ? self::emailOf($user) : '';
        Log::add('pw_reset', $user ? 'angefordert: ' . $user['username'] : 'angefordert (unbekannt)');
        if (!$user || $to === '') {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        Store::update('resets', function (array &$r) use ($user, $token) {
            self::normalize($r);
            $r['tokens'] = array_values(array_filter($r['tokens'], fn($t) => $t['u'] !== $user['id']));
            $r['tokens'][] = ['u' => $user['id'], 'h' => hash('sha256', $token), 'exp' => time() + self::TTL, 't' => time()];
        });
        return ['to' => $to, 'name' => $user['name'] ?: $user['username'], 'username' => $user['username'],
            'url' => $base . '/index.php?r=reset&t=' . $token];
    }

    /** Zählt einen Versuch; false, wenn das Stundenlimit erreicht ist. */
    private static function allow(string $key, int $max): bool
    {
        return Store::update('resets', function (array &$r) use ($key, $max) {
            self::normalize($r);
            $r['rl'][$key] = array_values(array_filter($r['rl'][$key] ?? [], fn($t) => $t > time() - 3600));
            if (count($r['rl'][$key]) >= $max) {
                return false;
            }
            $r['rl'][$key][] = time();
            return true;
        });
    }

    /** Konto zu einem gültigen Token (sonst null). */
    public static function user(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        foreach (Store::read('resets')['tokens'] ?? [] as $t) {
            if (hash_equals($t['h'], $hash) && $t['exp'] > time()) {
                $u = find_by_id(Store::read('users'), $t['u']);
                return ($u && !empty($u['active'])) ? $u : null;
            }
        }
        return null;
    }

    /** Setzt das neue Passwort, verbraucht das Token, beendet alle Sitzungen des Kontos. */
    public static function complete(string $token, string $new): ?array
    {
        $user = self::user($token);
        if (!$user) {
            return null;
        }
        Store::update('users', function (array &$users) use ($user, $new) {
            foreach ($users as &$u) {
                if ($u['id'] === $user['id']) {
                    $u['pass_hash'] = password_hash($new, PASSWORD_DEFAULT);
                    $u['pw_changed'] = time();
                }
            }
        });
        Store::update('resets', function (array &$r) use ($user) {
            self::normalize($r);
            $r['tokens'] = array_values(array_filter($r['tokens'], fn($t) => $t['u'] !== $user['id']));
        });
        Log::add('pw_reset', 'abgeschlossen: ' . $user['username']);
        return $user;
    }

    /* ---------------------------------------------------------- Weg 2 */

    /** Speichert eine Anfrage an den Admin (öffentlich, begrenzt je IP). */
    public static function help(string $who, string $phone): void
    {
        $who = mb_substr(trim($who), 0, 100);
        $phone = mb_substr(trim($phone), 0, 40);
        if ($who === '' || !self::allow('h:' . hash('sha256', client_ip()), self::IP_MAX)) {
            return;
        }
        Store::update('resets', function (array &$r) use ($who, $phone) {
            self::normalize($r);
            if (count($r['requests']) < 200) {
                $r['requests'][] = ['id' => new_id(), 'who' => $who, 'phone' => $phone, 'created' => now()];
            }
        });
        Log::add('pw_reset', 'Hilfe angefordert');
    }

    public static function requests(): array
    {
        return array_reverse(Store::read('resets')['requests'] ?? []);
    }

    public static function resolve(string $id): void
    {
        Store::update('resets', function (array &$r) use ($id) {
            self::normalize($r);
            $r['requests'] = array_values(array_filter($r['requests'], fn($q) => $q['id'] !== $id));
        });
    }

    /** Konto zu einer Anfrage: Benutzername oder „Vorname Nachname“ eines Kontakts mit Zugang. */
    public static function match(string $who): ?array
    {
        $w = mb_strtolower(trim($who));
        $users = Store::read('users');
        foreach ($users as $u) {
            if (mb_strtolower($u['username']) === $w) {
                return $u;
            }
        }
        foreach (Store::read('contacts') as $c) {
            if (mb_strtolower(trim($c['first'] . ' ' . $c['last'])) === $w) {
                foreach ($users as $u) {
                    if (($u['contact_id'] ?? '') === $c['id']) {
                        return $u;
                    }
                }
            }
        }
        return null;
    }

    /* ---------------------------------------------------------- Pflege / Versand */

    private static function normalize(array &$r): void
    {
        $r += ['tokens' => [], 'requests' => [], 'rl' => []];
    }

    /** Entfernt abgelaufene Tokens, alte Anfragen und Zähler (täglich über Log::maintenance). */
    public static function purge(): void
    {
        $limit = date('c', strtotime('-' . self::REQUEST_DAYS . ' days'));
        Store::update('resets', function (array &$r) use ($limit) {
            self::normalize($r);
            $r['tokens'] = array_values(array_filter($r['tokens'], fn($t) => $t['exp'] > time()));
            $r['requests'] = array_values(array_filter($r['requests'], fn($q) => $q['created'] > $limit));
            foreach ($r['rl'] as $k => $list) {
                $r['rl'][$k] = array_values(array_filter($list, fn($t) => $t > time() - 3600));
                if (!$r['rl'][$k]) {
                    unset($r['rl'][$k]);
                }
            }
        });
    }

    /** Konto gelöscht → offene Tokens entfernen. */
    public static function dropUser(string $userId): void
    {
        Store::update('resets', function (array &$r) use ($userId) {
            self::normalize($r);
            $r['tokens'] = array_values(array_filter($r['tokens'], fn($t) => $t['u'] !== $userId));
        });
    }

    /** E-Mail über mail() (UTF-8, Absender noreply@domain). */
    public static function send(string $to, string $subject, string $body): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) {
            return false;
        }
        $headers = implode("\r\n", [
            'From: ' . self::fromAddress(),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Auto-Response-Suppress: All',
        ]);
        return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', str_replace("\n", "\r\n", $body), $headers);
    }

    public static function sendLink(array $job): void
    {
        $app = (string)cfg('app_name', t('mail.default_app'));
        self::send($job['to'], t('mail.reset_subject', ['app' => $app]),
            t('mail.reset_body', ['name' => $job['name'], 'user' => $job['username'], 'url' => $job['url']]));
    }

    public static function sendChanged(array $user): void
    {
        $to = self::emailOf($user);
        if ($to === '') {
            return;
        }
        $app = (string)cfg('app_name', t('mail.default_app'));
        self::send($to, t('mail.changed_subject', ['app' => $app]),
            t('mail.changed_body', ['name' => $user['name'] ?: $user['username'], 'user' => $user['username'],
                'date' => fmt_ts(time(), 'dt')]));
    }
}
