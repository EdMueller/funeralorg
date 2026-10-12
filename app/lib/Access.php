<?php
declare(strict_types=1);

/**
 * Zugänge für Kontakte: Benutzernamen und Passwörter erzeugen, Benutzer verknüpfen (users.json → contact_id).
 * Klartext-Passwörter existieren nur im Rückgabewert von issue() und werden nirgends gespeichert.
 */
final class Access
{
    /** Passwortalphabet ohne verwechselbare Zeichen (kein i, l, o, 0, 1), nur Kleinbuchstaben und Ziffern. */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** Druckformate für Klebestreifen (mm), überschreibbar über configs/config.php → access_formats. */
    public const FORMATS = [
        '2x10' => ['name' => '2 × 10 (105 × 28 mm)', 'cols' => 2, 'rows' => 10, 'w' => 105.0, 'h' => 28.0,
                   'top' => 8.5, 'left' => 0.0, 'gap_x' => 0.0, 'gap_y' => 0.0],
        '2x9'  => ['name' => '2 × 9 (105 × 32 mm)',  'cols' => 2, 'rows' => 9,  'w' => 105.0, 'h' => 32.0,
                   'top' => 4.5, 'left' => 0.0, 'gap_x' => 0.0, 'gap_y' => 0.0],
        // 12 Etiketten je Bogen, 42 mm hoch, mit seitlichem Rand und ohne Abstand. Breite und Ränder sind
        // symmetrisch gewählt (2 × 99,1 + 2 × 5,9 = 210 mm; oben/unten je 22,5 mm) – am Datenblatt prüfen
        // und bei Abweichung über configs/config.php → access_formats anpassen.
        '2x6'  => ['name' => '2 × 6 (99,1 × 42 mm)', 'cols' => 2, 'rows' => 6, 'w' => 99.1, 'h' => 42.0,
                   'top' => 22.5, 'left' => 5.9, 'gap_x' => 0.0, 'gap_y' => 0.0],
    ];

    public static function formats(): array
    {
        $f = cfg('access_formats', []);
        return is_array($f) && $f ? $f : self::FORMATS;
    }

    /** Zufallspasswort, z. B. „hk7mp-3x9wd“ (10 Zeichen in zwei Fünfergruppen). */
    public static function password(): string
    {
        $n = strlen(self::ALPHABET);
        $s = '';
        for ($i = 0; $i < 10; $i++) {
            $s .= self::ALPHABET[random_int(0, $n - 1)];
            if ($i === 4) {
                $s .= '-';
            }
        }
        return $s;
    }

    /** Benutzername „vorname.nachname“ (ASCII, klein), eindeutig gegenüber $taken (wird ergänzt). */
    public static function username(array $contact, array &$taken): string
    {
        $part = function (string $s): string {
            $s = mb_strtolower(strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss']));
            $s = (string)iconv('UTF-8', 'ASCII//TRANSLIT', $s);
            return preg_replace('/[^a-z0-9]+/', '', strtolower($s)) ?? '';
        };
        $first = $part((string)preg_replace('/\s.*$/u', '', trim((string)$contact['first'])));
        $last = $part((string)$contact['last']);
        $base = trim($first . '.' . $last, '.');
        $base = substr($base !== '' ? $base : 'gast', 0, 50);
        $name = strlen($base) < 3 ? $base . 'xx' : $base;
        for ($i = 2; isset($taken[$name]); $i++) {
            $name = $base . $i;
        }
        $taken[$name] = true;
        return $name;
    }

    /**
     * Legt Zugänge an ('new') oder setzt Passwörter zurück ('reset').
     * @return array{rows:list<array{contact:array,username:string,password:string}>,skipped:list<string>}
     */
    public static function issue(array $contacts, string $groupId, string $mode): array
    {
        @set_time_limit(600);
        $rows = [];
        $skipped = [];
        Store::update('users', function (array &$users) use ($contacts, $groupId, $mode, &$rows, &$skipped) {
            $taken = [];
            foreach ($users as $u) {
                $taken[mb_strtolower($u['username'])] = true;
            }
            foreach ($contacts as $c) {
                $label = Contacts::fullName($c) ?: t('access.no_name');
                $idx = null;
                foreach ($users as $k => $u) {
                    if (($u['contact_id'] ?? '') === $c['id']) {
                        $idx = $k;
                        break;
                    }
                }
                if ($mode === 'new' && $idx !== null) {
                    $skipped[] = t('access.has_account', ['name' => $label]);
                    continue;
                }
                if ($mode === 'reset' && $idx === null) {
                    $skipped[] = t('access.no_account', ['name' => $label]);
                    continue;
                }
                $pass = self::password();
                if ($idx === null) {
                    $name = self::username($c, $taken);
                    $users[] = ['id' => new_id(), 'username' => $name, 'name' => $label, 'role' => 'user',
                        'group_id' => $groupId, 'active' => true, 'contact_id' => $c['id'],
                        'pass_hash' => password_hash($pass, PASSWORD_DEFAULT), 'created' => now(), 'last_login' => ''];
                } else {
                    $name = $users[$idx]['username'];
                    $users[$idx]['pass_hash'] = password_hash($pass, PASSWORD_DEFAULT);
                    $users[$idx]['pw_changed'] = time();
                }
                $rows[] = ['contact' => $c, 'username' => $name, 'password' => $pass];
            }
        });
        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /** Kontakt-ID => Benutzername aller verknüpften Zugänge. */
    public static function byContact(): array
    {
        $out = [];
        foreach (Store::read('users') as $u) {
            if (!empty($u['contact_id'])) {
                $out[$u['contact_id']] = $u['username'];
            }
        }
        return $out;
    }
}
