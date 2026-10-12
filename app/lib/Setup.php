<?php
declare(strict_types=1);

/**
 * Ersteinrichtung über die Weboberfläche (Route `setup`).
 * Nur erreichbar, solange noch kein Benutzer existiert.
 */
final class Setup
{
    private const DIRS = ['', '/logs', '/uploads/orig', '/uploads/medium', '/uploads/thumb', '/backups', '/cache', '/tmp'];

    /** Start-Checkliste: Kategorie-Schlüssel => Anzahl Aufgaben (Texte in app/lang, Schlüssel setup.cat.* und setup.task.*). */
    private const CHECKLIST = ['now' => 6, 'funeral' => 10, 'service' => 5, 'admin' => 9, 'contracts' => 10, 'thanks' => 5];

    /** Noch kein Benutzer vorhanden → Einrichtung nötig. */
    public static function needed(): bool
    {
        if (!is_file(Store::file('users'))) {
            return true;
        }
        return !Store::read('users');
    }

    /** Prüft Voraussetzungen und legt das Datenverzeichnis an. @return list<string> Fehlermeldungen */
    public static function prepare(): array
    {
        $errors = [];
        if (PHP_VERSION_ID < 80200) {
            $errors[] = t('setup.err_php', ['v' => PHP_VERSION]);
        }
        $missing = array_filter(['gd', 'zip', 'mbstring', 'fileinfo', 'json'], fn($x) => !extension_loaded($x));
        if ($missing) {
            $errors[] = t('setup.err_ext', ['list' => implode(', ', $missing)]);
        }
        foreach (self::DIRS as $d) {
            if (!is_dir(DATA_DIR . $d) && !@mkdir(DATA_DIR . $d, 0750, true) && !is_dir(DATA_DIR . $d)) {
                $errors[] = t('setup.err_mkdir', ['dir' => 'data' . $d]);
                return $errors;
            }
        }
        if (!is_writable(DATA_DIR)) {
            $errors[] = t('setup.err_writable');
            return $errors;
        }
        if (!is_file(DATA_DIR . '/.htaccess')) {
            @file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\n");
        }
        return $errors;
    }

    /**
     * Legt Standardgruppen, Start-Checkliste, Gedenkseite und den ersten Administrator an.
     * @return string|null Fehlermeldung oder null bei Erfolg
     */
    public static function create(string $username, string $name, string $password, string $deceased): ?string
    {
        $groups = Store::update('groups', function (array &$g): array {
            if (!$g) {
                $all = array_fill_keys(Perm::MODULES, Perm::EDIT);
                $allFields = array_fill_keys(Perm::FIELDS, true);
                $g[] = ['id' => new_id(), 'name' => t('setup.group_family'), 'perms' => $all, 'fields' => $allFields,
                        'gallery' => ['view' => true, 'upload' => true, 'download' => true]];
                $g[] = ['id' => new_id(), 'name' => t('setup.group_helpers'),
                        'perms' => ['checklist' => Perm::CHECK, 'contacts' => Perm::CHECK, 'rsvp' => Perm::CHECK, 'condolences' => Perm::NONE],
                        'fields' => ['address' => false, 'phone' => true, 'mobile' => true, 'email' => false],
                        'gallery' => ['view' => true, 'upload' => true, 'download' => false]];
                $g[] = ['id' => new_id(), 'name' => t('setup.group_caterer'),
                        'perms' => ['checklist' => Perm::NONE, 'contacts' => Perm::NONE, 'rsvp' => Perm::READ, 'condolences' => Perm::NONE],
                        'fields' => ['address' => false, 'phone' => false, 'mobile' => false, 'email' => false],
                        'gallery' => ['view' => false, 'upload' => false, 'download' => false]];
            }
            return $g;
        });

        // Der Administrator wird zuletzt angelegt: Erst damit gilt die Einrichtung als abgeschlossen.
        $err = Store::update('users', function (array &$u) use ($username, $name, $password, $groups): ?string {
            if ($u) {
                return t('setup.err_done');
            }
            $u[] = ['id' => new_id(), 'username' => $username, 'name' => $name, 'role' => 'admin',
                    'group_id' => $groups[0]['id'], 'active' => true,
                    'pass_hash' => password_hash($password, PASSWORD_DEFAULT), 'created' => now(), 'last_login' => ''];
            return null;
        });
        if ($err !== null) {
            return $err;
        }

        if ($deceased !== '') {
            Store::update('memorial', function (array &$m) use ($deceased) {
                if (($m['name'] ?? '') === '') {
                    $m['name'] = $deceased;
                }
            });
        }

        Store::update('checklist', function (array &$d) {
            if ($d['categories']) {
                return;
            }
            $sort = 0;
            foreach (self::CHECKLIST as $cat => $count) {
                $cid = new_id();
                $d['categories'][] = ['id' => $cid, 'name' => t('setup.cat.' . $cat), 'sort' => $sort++];
                for ($n = 1; $n <= $count; $n++) {
                    $d['items'][] = ['id' => new_id(), 'cat' => $cid, 'title' => t('setup.task.' . $cat . '.' . $n), 'done' => false, 'done_by' => '',
                                     'done_at' => '', 'due' => '', 'who' => '', 'note' => '', 'created' => now()];
                }
            }
        });
        return null;
    }
}
