<?php
declare(strict_types=1);

/**
 * Beispieldaten für die Ersteinrichtung und ihr späteres Entfernen.
 *
 * Beim Anlegen wird von jedem Beispieldatensatz die ID samt Prüfsumme seiner inhaltlichen Felder in
 * data/demo.json vermerkt. Beim Entfernen werden nur Datensätze gelöscht, die noch existieren und deren
 * Prüfsumme unverändert ist; bearbeitete Datensätze gelten als eigene Daten und bleiben erhalten.
 * Die übrigen Datendateien enthalten dafür kein Zusatzfeld.
 */
final class Demo
{
    /** Alben: Schlüssel => Reihenfolge. Namen unter demo.album.<schlüssel>. */
    public const ALBUMS = ['nature' => 0, 'home' => 1, 'travel' => 2];

    /** Datensatztypen (Reihenfolge der Anzeige). */
    public const TYPES = ['contacts', 'users', 'groups', 'albums', 'images', 'items', 'cats'];

    /** Inhaltliche Felder je Typ: nur sie bestimmen, ob ein Datensatz „unverändert“ ist. */
    private const FIELDS = [
        'users'    => ['username', 'name', 'role', 'group_id', 'active'],
        'groups'   => ['name', 'perms', 'fields', 'gallery'],
        'albums'   => ['name', 'cat', 'color', 'parent', 'links', 'hidden'],
        'images'   => ['caption', 'album', 'links', 'login', 'masks'],
        'items'    => ['cat', 'parent', 'title', 'done', 'due', 'who', 'note'],
        'cats'     => ['name'],
        'memorial' => ['born', 'died', 'place', 'quote', 'quote_by', 'text', 'portrait'],
    ];

    /** Gibt es noch vermerkte Beispieldatensätze? */
    public static function active(): bool
    {
        $d = Store::read('demo');
        return !empty($d['items']) || !empty($d['memorial']);
    }

    /** Prüfsumme der inhaltlichen Felder eines Datensatzes. */
    private static function sig(string $type, array $rec): string
    {
        if ($type === 'contacts') {
            $rec = array_diff_key(array_merge(Contacts::blank(), $rec), array_flip(['id', 'created', 'updated', 'updated_by']));
            $rec['amount'] = (float)$rec['amount'];
        } else {
            $rec = array_intersect_key($rec, array_flip(self::FIELDS[$type]));
            foreach (self::FIELDS[$type] as $k) {
                // fehlend, leer und false sind gleichwertig (manche Speicherwege lassen leere Felder weg)
                $rec[$k] = empty($rec[$k]) ? '' : $rec[$k];
                if (is_array($rec[$k])) {
                    sort($rec[$k]);
                }
            }
        }
        ksort($rec);
        return md5((string)json_encode($rec, JSON_UNESCAPED_UNICODE));
    }

    /* ------------------------------------------------------------ Anlegen */

    /**
     * Legt die Beispieldaten an. Wird nach dem ersten Administrator aufgerufen.
     * @param list<array> $groups Standardgruppen (Familie, Helfer, …) in dieser Reihenfolge
     * @param array{id:string,name:string} $admin
     */
    public static function install(array $groups, array $admin): void
    {
        $reg = array_fill_keys(self::TYPES, []);

        // Kontakte (einige nur mit Namen: persönliche Übergabe von Karten und Dankeschöns)
        $contacts = [];
        foreach (self::contactRows() as $row) {
            $c = array_merge(Contacts::blank(), $row, ['id' => new_id(), 'created' => now(), 'updated' => now(), 'updated_by' => $admin['name']]);
            $contacts[] = $c;
            $reg['contacts'][$c['id']] = self::sig('contacts', $c);
        }
        Store::update('contacts', function (array &$list) use ($contacts) {
            array_push($list, ...$contacts);
        });

        // Gruppe (zusätzlich zu den Standardgruppen) und Benutzer
        $neighbours = ['id' => new_id(), 'name' => t('demo.group_neighbours'),
            'perms' => ['checklist' => Perm::NONE, 'contacts' => Perm::NONE, 'rsvp' => Perm::NONE, 'condolences' => Perm::NONE],
            'fields' => array_fill_keys(Perm::FIELDS, false),
            'gallery' => ['view' => true, 'upload' => false, 'download' => false, 'recent' => true, 'all' => true],
            'plugins' => ['stations' => false]];
        Store::update('groups', function (array &$g) use ($neighbours) {
            $g[] = $neighbours;
        });
        $reg['groups'][$neighbours['id']] = self::sig('groups', $neighbours);

        $users = [];
        foreach ([['demo.family', 'demo.user_family', $groups[0]['id'] ?? ''],
                  ['demo.helper', 'demo.user_helper', $groups[1]['id'] ?? ''],
                  ['demo.neighbour', 'demo.user_neighbour', $neighbours['id']]] as [$username, $key, $gid]) {
            if ($gid === '') {
                continue;
            }
            // Zufallspasswort, das niemand kennt: Anmeldung nur über „Als Benutzer anmelden“ in der Verwaltung
            $users[] = ['id' => new_id(), 'username' => $username, 'name' => t($key), 'role' => 'user', 'group_id' => $gid,
                'active' => true, 'pass_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'created' => now(), 'last_login' => '', 'lang' => I18n::lang()];
        }
        Store::update('users', function (array &$u) use ($users) {
            array_push($u, ...$users);
        });
        foreach ($users as $u) {
            $reg['users'][$u['id']] = self::sig('users', $u);
        }

        // Alben und Bilder
        $albums = [];
        foreach (self::ALBUMS as $key => $sort) {
            $albums[$key] = ['id' => new_id(), 'name' => t('demo.album.' . $key), 'cat' => '', 'color' => '',
                'hidden' => false, 'parent' => '', 'links' => [], 'sort' => $sort, 'created' => now()];
            $reg['albums'][$albums[$key]['id']] = self::sig('albums', $albums[$key]);
        }
        Store::update('gallery', function (array &$g) use ($albums) {
            foreach ($albums as $a) {
                $g['albums'][] = $a;
            }
        });
        $portrait = '';
        foreach ((array)require APP_DIR . '/demo/images.php' as $def) {
            $file = APP_DIR . '/demo/images/' . $def['file'];
            if (!is_file($file) || !isset($albums[$def['album']])) {
                continue;
            }
            try {
                $rec = Image::import($file, $def['file']);
            } catch (Throwable $e) {
                continue; // fehlende GD-Funktion o. Ä.: Bild überspringen, Einrichtung nicht abbrechen
            }
            $rec += ['album' => $albums[$def['album']]['id'], 'caption' => t('demo.img.' . $def['name']), 'login' => false,
                'links' => [], 'uploaded_by' => $admin['name'], 'uploaded_by_id' => $admin['id'], 'uploaded_at' => now()];
            Store::update('gallery', function (array &$g) use ($rec) {
                $g['images'][] = $rec;
            });
            $reg['images'][$rec['id']] = self::sig('images', $rec);
            if (!empty($def['portrait'])) {
                $portrait = $rec['id'];
            }
        }

        // Checkliste: eigene Kategorie mit einigen Aufgaben (die Start-Checkliste bleibt Standard)
        $cat = ['id' => new_id(), 'name' => t('demo.cat'), 'sort' => 99];
        $reg['cats'][$cat['id']] = self::sig('cats', $cat);
        $items = [];
        $main = null;
        foreach ([1 => [false, ''], 2 => [true, ''], 3 => [false, 'main'], 4 => [false, 'sub'], 5 => [false, 'sub']] as $n => [$done, $kind]) {
            $it = ['id' => new_id(), 'cat' => $cat['id'], 'title' => t('demo.task.' . $n), 'done' => $done,
                'done_by' => $done ? $admin['name'] : '', 'done_at' => $done ? now() : '',
                'due' => $n === 1 ? date('Y-m-d', strtotime('+7 days')) : '', 'who' => '', 'note' => '', 'created' => now(),
                'parent' => $kind === 'sub' && $main ? $main : '', 'sort' => $n];
            if ($kind === 'main') {
                $main = $it['id'];
            }
            $items[] = $it;
            $reg['items'][$it['id']] = self::sig('items', $it);
        }
        Store::update('checklist', function (array &$d) use ($cat, $items) {
            $d['categories'][] = $cat;
            array_push($d['items'], ...$items);
        });

        // Gedenkseite: nur leere Felder füllen
        $memorialSet = [];
        $nameSet = false;
        Store::update('memorial', function (array &$m) use ($portrait, &$memorialSet, &$nameSet) {
            $fill = ['born' => t('demo.born'), 'died' => t('demo.died'), 'place' => t('demo.place'), 'quote' => t('demo.quote'),
                'quote_by' => t('demo.quote_by'), 'text' => t('demo.text'), 'portrait' => $portrait];
            foreach ($fill as $k => $v) {
                if ($v !== '' && ($m[$k] ?? '') === '') {
                    $m[$k] = $v;
                    $memorialSet[$k] = $v;
                }
            }
            if (($m['name'] ?? '') === '') {
                $m['name'] = t('demo.name');
                $nameSet = true;
            }
        });

        Store::update('demo', function (array &$d) use ($reg, $memorialSet, $nameSet) {
            $d['items'] = $reg;
            $d['memorial'] = $memorialSet ? ['sig' => md5((string)json_encode($memorialSet, JSON_UNESCAPED_UNICODE)),
                'fields' => array_keys($memorialSet), 'name' => $nameSet] : null;
            $d['created'] = now();
        });
    }

    /** Beispielkontakte (Texte über t()). */
    private static function contactRows(): array
    {
        $f = t('demo.sal_f');
        $m = t('demo.sal_m');
        $city = ['zip' => '12345', 'city' => t('demo.city')];
        return [
            ['salutation' => $f, 'first' => 'Anna', 'last' => 'Beispiel', 'relation' => t('demo.rel_family'), 'street' => t('demo.street1')] + $city
                + ['phone' => '01234 567890', 'email' => 'anna.beispiel@example.org', 'called' => true, 'letter' => true,
                   'rsvp' => 'yes', 'adults' => 2, 'children' => 1, 'card' => true, 'cond_note' => t('demo.note_card'), 'thanks' => true],
            ['salutation' => $m, 'first' => 'Karl', 'last' => 'Muster', 'relation' => t('demo.rel_friends'), 'street' => t('demo.street2')] + $city
                + ['mobile' => '0170 1234567', 'called' => true, 'letter' => true, 'rsvp' => 'yes', 'adults' => 1,
                   'flowers' => true, 'wreath' => true, 'cond_note' => t('demo.note_wreath')],
            ['salutation' => $f, 'first' => 'Erika', 'last' => 'Mustermann', 'relation' => t('demo.rel_neighbours'), 'street' => t('demo.street3')] + $city
                + ['letter' => true, 'rsvp' => 'no', 'rsvp_note' => t('demo.note_no'), 'donation' => true, 'amount' => 50.0, 'thanks' => false],
            ['salutation' => $m, 'first' => 'Hans', 'last' => 'Probst', 'relation' => t('demo.rel_colleagues'), 'street' => t('demo.street4')] + $city
                + ['email' => 'hans.probst@example.org', 'rsvp' => 'open'],
            // nur Name bekannt (persönliche Übergabe)
            ['salutation' => $f, 'first' => 'Lisa', 'last' => 'Demo', 'relation' => t('demo.rel_acquaintances'), 'note' => t('demo.note_hand')],
            ['salutation' => $m, 'first' => 'Paul', 'last' => 'Test', 'relation' => t('demo.rel_club'), 'mobile' => '0171 7654321', 'note' => t('demo.note_hand')],
            ['salutation' => $f, 'first' => 'Sabine', 'last' => 'Vorlage', 'relation' => t('demo.rel_care'), 'phone' => '01234 111222'],
        ];
    }

    /* ------------------------------------------------------------ Übersicht */

    /**
     * Anzahl vorhandener Beispieldatensätze je Typ.
     * @return array{rows: array<string, array{total:int, unchanged:int}>, memorial: bool, memorial_unchanged: bool, total:int, unchanged:int}
     */
    public static function status(): array
    {
        $demo = Store::read('demo');
        $items = $demo['items'] ?? [];
        $src = [
            'contacts' => Store::read('contacts'), 'users' => Store::read('users'), 'groups' => Store::read('groups'),
            'albums' => Store::read('gallery')['albums'], 'images' => Store::read('gallery')['images'],
            'items' => Store::read('checklist')['items'], 'cats' => Store::read('checklist')['categories'],
        ];
        $rows = [];
        $total = $unchanged = 0;
        foreach (self::TYPES as $type) {
            $t = $u = 0;
            foreach ($src[$type] as $rec) {
                $sig = $items[$type][$rec['id'] ?? ''] ?? null;
                if ($sig === null) {
                    continue;
                }
                $t++;
                if ($sig === self::sig($type, $rec)) {
                    $u++;
                }
            }
            $rows[$type] = ['total' => $t, 'unchanged' => $u];
            $total += $t;
            $unchanged += $u;
        }
        $mu = self::memorialUnchanged();
        return ['rows' => $rows, 'memorial' => $mu !== null, 'memorial_unchanged' => $mu === true,
            'total' => $total + ($mu !== null ? 1 : 0), 'unchanged' => $unchanged + ($mu === true ? 1 : 0)];
    }

    /** null = keine Beispiel-Gedenkseite vermerkt, true = unverändert, false = bearbeitet. */
    private static function memorialUnchanged(): ?bool
    {
        $mm = Store::read('demo')['memorial'] ?? null;
        if (!$mm) {
            return null;
        }
        $m = Store::read('memorial');
        $cur = [];
        foreach ($mm['fields'] as $k) {
            $cur[$k] = $m[$k] ?? '';
        }
        return md5((string)json_encode($cur, JSON_UNESCAPED_UNICODE)) === $mm['sig'];
    }

    /* ------------------------------------------------------------ Entfernen */

    /**
     * Löscht alle unveränderten Beispieldatensätze. Bearbeitete bleiben erhalten und gelten danach als eigene Daten.
     * @return array{deleted:int, kept:int}
     */
    public static function remove(): array
    {
        $reg = Store::read('demo')['items'] ?? [];
        $deleted = 0;
        $isOurs = fn(string $type, array $rec): bool => isset($reg[$type][$rec['id'] ?? '']) && $reg[$type][$rec['id']] === self::sig($type, $rec);
        $mm = Store::read('demo')['memorial'] ?? null;
        $memorialOk = self::memorialUnchanged() === true; // vor dem Löschen der Bilder (Porträt-Verweis)

        // Benutzer
        $goneUsers = Store::update('users', function (array &$users) use ($isOurs) {
            $gone = array_values(array_filter($users, fn($u) => ($u['role'] ?? '') !== 'admin' && $isOurs('users', $u)));
            $users = array_values(array_filter($users, fn($u) => !in_array($u['id'], array_column($gone, 'id'), true)));
            return $gone;
        });
        if ($goneUsers) {
            $ids = array_column($goneUsers, 'id');
            Store::update('selections', function (array &$s) use ($ids) {
                $s = array_diff_key($s, array_flip($ids));
            });
            foreach ($ids as $id) {
                Reset::dropUser($id);
            }
            $deleted += count($ids);
        }

        // Gruppen (nur wenn niemand sie mehr nutzt)
        $used = array_filter(array_map(fn($x) => $x['group_id'] ?? '', array_merge(Store::read('users'), Store::read('shares'))));
        $deleted += Store::update('groups', function (array &$groups) use ($isOurs, $used) {
            $before = count($groups);
            $groups = array_values(array_filter($groups, fn($g) => !($isOurs('groups', $g) && !in_array($g['id'], $used, true))));
            return $before - count($groups);
        });

        // Kontakte
        $deleted += Store::update('contacts', function (array &$list) use ($isOurs) {
            $before = count($list);
            $list = array_values(array_filter($list, fn($c) => !$isOurs('contacts', $c)));
            return $before - count($list);
        });

        // Bilder, danach Alben (tiefste zuerst; Unteralben und fremde Bilder rücken wie beim normalen Löschen auf)
        $goneImages = Store::update('gallery', function (array &$g) use ($isOurs) {
            $gone = array_values(array_filter($g['images'], fn($i) => $isOurs('images', $i)));
            $ids = array_column($gone, 'id');
            $g['images'] = array_values(array_filter($g['images'], fn($i) => !in_array($i['id'], $ids, true)));
            return $gone;
        });
        foreach ($goneImages as $img) {
            Image::delete($img);
        }
        if ($goneImages) {
            $ids = array_column($goneImages, 'id');
            Store::update('memorial', function (array &$m) use ($ids) {
                if (in_array($m['portrait'] ?? '', $ids, true)) {
                    $m['portrait'] = '';
                }
            });
            Store::update('selections', function (array &$all) use ($ids) {
                foreach ($all as $u => $list) {
                    $all[$u] = array_values(array_diff($list, $ids));
                }
            });
            $deleted += count($ids);
        }
        $deleted += Store::update('gallery', function (array &$g) use ($isOurs) {
            $n = 0;
            do {
                $found = false;
                foreach ($g['albums'] as $al) {
                    if (!$isOurs('albums', $al)) {
                        continue;
                    }
                    $id = $al['id'];
                    $up = (string)($al['parent'] ?? '');
                    $g['albums'] = array_values(array_filter($g['albums'], fn($x) => $x['id'] !== $id));
                    foreach ($g['albums'] as &$x) {
                        if (($x['parent'] ?? '') === $id) {
                            $x['parent'] = $up;
                        }
                        $x['links'] = array_values(array_diff($x['links'] ?? [], [$id]));
                    }
                    unset($x);
                    foreach ($g['images'] as &$i) {
                        if ($i['album'] === $id) {
                            $i['album'] = $up;
                        }
                        $i['links'] = array_values(array_diff($i['links'] ?? [], [$id, $i['album']]));
                    }
                    unset($i);
                    $n++;
                    $found = true;
                    break;
                }
            } while ($found);
            return $n;
        });

        // Checkliste: Aufgaben, dann Kategorien ohne verbliebene Aufgaben
        $deleted += Store::update('checklist', function (array &$d) use ($isOurs) {
            $n = 0;
            $items = array_values(array_filter($d['items'], fn($i) => !$isOurs('items', $i)));
            $n += count($d['items']) - count($items);
            $d['items'] = $items;
            $inUse = array_column($items, 'cat');
            $cats = array_values(array_filter($d['categories'], fn($c) => !($isOurs('cats', $c) && !in_array($c['id'], $inUse, true))));
            $n += count($d['categories']) - count($cats);
            $d['categories'] = $cats;
            return $n;
        });

        // Gedenkseite
        if ($mm && $memorialOk) {
            Store::update('memorial', function (array &$m) use ($mm) {
                foreach ($mm['fields'] as $k) {
                    $m[$k] = '';
                }
                if (!empty($mm['name'])) {
                    $m['name'] = '';
                }
            });
            $deleted++;
        }

        $left = array_sum(array_column(self::status()['rows'], 'total')) + ($mm && !$memorialOk ? 1 : 0);
        Store::update('demo', function (array &$d) {
            $d = ['items' => [], 'memorial' => null];
        });
        return ['deleted' => $deleted, 'kept' => max(0, $left)];
    }
}
