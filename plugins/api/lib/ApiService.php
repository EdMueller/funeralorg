<?php
declare(strict_types=1);

/**
 * Fachlogik der Web-API. Jede Methode bekommt die zusammengeführten Argumente (Pfad, Query, Body) und liefert Daten
 * oder wirft ApiError. Rechte, Feldrechte und Protokoll wie in der Oberfläche; die Anfrage läuft als Token-Besitzer.
 * REST und MCP rufen dieselben Methoden auf (siehe ApiSpec).
 */
final class ApiService
{
    /** Bereich des verwendeten Tokens (read|write), vom Controller gesetzt. */
    public static string $scope = 'read';

    /* ------------------------------------------------------------ Helfer */

    private static function deny(string $msg = ''): never
    {
        throw new ApiError(403, $msg ?: t('perm.denied'));
    }

    private static function needAdmin(): void
    {
        if (!Auth::isAdmin()) {
            self::deny(t('perm.admin_only'));
        }
    }

    private static function invalid(string $msg): never
    {
        throw new ApiError(422, $msg);
    }

    private static function missing(string $what = ''): never
    {
        throw new ApiError(404, $what ?: t('error.404'));
    }

    /** Textargument (gekürzt) oder null, wenn nicht übergeben. */
    private static function s(array $a, string $k, int $max): ?string
    {
        return array_key_exists($k, $a) && !is_array($a[$k]) ? mb_substr(trim((string)$a[$k]), 0, $max) : null;
    }

    private static function bool(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on';
    }

    private static function page(array $a): array
    {
        return [max(1, min(500, (int)($a['limit'] ?? 100))), max(0, (int)($a['offset'] ?? 0))];
    }

    private static function uid(): string
    {
        return (string)Auth::user()['id'];
    }

    private static function checklistLib(): void
    {
        require_once APP_DIR . '/controllers/checklist.php';
    }

    private static function galleryLib(): void
    {
        require_once APP_DIR . '/controllers/gallery.php';
    }

    /* ------------------------------------------------------------ Allgemein */

    public static function me(array $a): array
    {
        $u = Auth::user();
        $levels = [];
        foreach (Perm::MODULES as $m) {
            $levels[$m] = Perm::level($m);
        }
        $fields = [];
        foreach (Perm::FIELDS as $f) {
            $fields[$f] = Perm::field($f);
        }
        return [
            'id' => $u['id'], 'name' => $u['name'], 'username' => $u['username'], 'role' => $u['role'],
            'scope' => self::$scope, 'app_version' => app_version(),
            'permissions' => [
                'modules' => $levels, // 0 keine, 1 lesen, 2 abhaken, 3 bearbeiten
                'fields' => $fields,
                'gallery' => ['view' => Perm::gallery(), 'upload' => Perm::gallery('upload'), 'download' => Perm::gallery('download')],
                'memorial' => Perm::memorial(),
            ],
        ];
    }

    public static function summary(array $a): array
    {
        $out = [];
        if (Perm::can('checklist')) {
            $items = Store::read('checklist')['items'];
            $out['tasks_open'] = count(array_filter($items, fn($i) => empty($i['done'])));
            $out['tasks_total'] = count($items);
        }
        $list = null;
        if (Perm::can('rsvp')) {
            $list = Contacts::all();
            $out['rsvp'] = Contacts::rsvpSums($list);
        }
        if (Perm::can('condolences')) {
            $list ??= Contacts::all();
            $out['thanks_open'] = count(Contacts::filter($list, 'thanks_open'));
        }
        if (Perm::can('contacts')) {
            $list ??= Contacts::all();
            $out['contacts_total'] = count($list);
            $out['called_open'] = count(Contacts::filter($list, 'called_open'));
        }
        if (Perm::gallery()) {
            $out['images'] = count(Store::read('gallery')['images']);
        }
        return $out;
    }

    /* ------------------------------------------------------------ Gedenkseite */

    public static function memorialGet(array $a): array
    {
        if (!Perm::memorial()) {
            self::deny();
        }
        $m = Store::read('memorial') + ['place' => '', 'quote_by' => ''];
        $m['frame'] = Frame::key($m['frame'] ?? '');
        return array_intersect_key($m, array_flip(['name', 'born', 'died', 'place', 'quote', 'quote_by', 'text', 'portrait', 'frame']));
    }

    public static function memorialUpdate(array $a): array
    {
        self::needAdmin();
        $limits = ['name' => 120, 'born' => 40, 'died' => 40, 'place' => 120, 'quote' => 400, 'quote_by' => 120, 'text' => 8000];
        $in = [];
        foreach ($limits as $k => $max) {
            if (($v = self::s($a, $k, $max)) !== null) {
                $in[$k] = $v;
            }
        }
        if (($portrait = self::s($a, 'portrait', 40)) !== null) {
            $in['portrait'] = ($portrait !== '' && find_by_id(Store::read('gallery')['images'], $portrait)) ? $portrait : '';
        }
        if (($frame = self::s($a, 'frame', 20)) !== null) {
            $in['frame'] = Frame::key($frame);
        }
        Store::update('memorial', function (array &$m) use ($in) {
            $m = $in + $m;
        });
        Log::add('admin', 'Gedenkseite bearbeitet');
        return self::memorialGet([]);
    }

    /* ------------------------------------------------------------ Kontakte */

    private static function needContacts(): void
    {
        if (!(Perm::can('contacts') || Perm::can('rsvp') || Perm::can('condolences'))) {
            self::deny();
        }
    }

    /** Kontakt für die Ausgabe: nur Felder, die Bereichs- und Feldrechte erlauben (Regel 6). */
    private static function contactOut(array $c): array
    {
        $c = array_merge(Contacts::blank(), $c);
        $out = array_intersect_key($c, array_flip(['id', 'salutation', 'first', 'last', 'relation', 'created', 'updated', 'updated_by']));
        $fieldMap = ['address' => ['street', 'zip', 'city', 'state', 'country'], 'phone' => ['phone'], 'mobile' => ['mobile'], 'email' => ['email']];
        foreach ($fieldMap as $perm => $keys) {
            if (Perm::field($perm)) {
                $out += array_intersect_key($c, array_flip($keys));
            }
        }
        if (Perm::can('contacts')) {
            $out += array_intersect_key($c, array_flip(['note', 'called', 'letter']));
        }
        if (Perm::can('rsvp')) {
            $out += array_intersect_key($c, array_flip(['rsvp', 'adults', 'children', 'rsvp_note']));
        }
        if (Perm::can('condolences')) {
            $out += array_intersect_key($c, array_flip(['card', 'flowers', 'wreath', 'donation', 'amount', 'cond_note', 'thanks']));
        }
        return $out;
    }

    public static function contactsList(array $a): array
    {
        self::needContacts();
        $filter = (string)($a['filter'] ?? '');
        if (!in_array($filter, Contacts::FILTERS, true)) {
            self::invalid('filter');
        }
        $need = match ($filter) {
            'called_open', 'letter_open' => 'contacts',
            'rsvp_yes', 'rsvp_no', 'rsvp_open' => 'rsvp',
            'cond_any', 'thanks_open' => 'condolences',
            default => null,
        };
        if ($need !== null && !Perm::can($need)) {
            self::deny();
        }
        $list = Contacts::filter(Contacts::all(), $filter, (string)($a['relation'] ?? ''));
        $q = mb_strtolower(trim((string)($a['q'] ?? '')));
        if ($q !== '') {
            $withCity = Perm::field('address');
            $list = array_values(array_filter($list, fn($c) => str_contains(mb_strtolower($c['first'] . ' ' . $c['last'] . ' ' . $c['relation'] . ($withCity ? ' ' . $c['city'] : '')), $q)));
        }
        [$limit, $offset] = self::page($a);
        return [
            'total' => count($list), 'limit' => $limit, 'offset' => $offset,
            'items' => array_map([self::class, 'contactOut'], array_slice($list, $offset, $limit)),
        ];
    }

    public static function contactsGet(array $a): array
    {
        self::needContacts();
        foreach (Contacts::all() as $c) {
            if ($c['id'] === (string)$a['id']) {
                return self::contactOut($c);
            }
        }
        self::missing();
    }

    public static function contactsCreate(array $a): array
    {
        if (!Perm::can('contacts', Perm::EDIT)) {
            self::deny();
        }
        $who = Auth::user()['name'];
        $c = Contacts::fromForm(Contacts::blank(), $a);
        if (Contacts::fullName($c) === '') {
            self::invalid('first/last');
        }
        $c['id'] = new_id();
        $c['created'] = $c['updated'] = now();
        $c['updated_by'] = $who;
        Store::update('contacts', function (array &$list) use ($c) {
            $list[] = $c;
        });
        Log::change('contacts', 'Kontakt angelegt', Contacts::fullName($c));
        return self::contactOut($c);
    }

    public static function contactsUpdate(array $a): array
    {
        self::needContacts();
        $canIdentity = Perm::can('contacts', Perm::EDIT);
        if (!($canIdentity || Perm::can('contacts', Perm::CHECK) || Perm::can('rsvp', Perm::CHECK) || Perm::can('condolences', Perm::CHECK))) {
            self::deny();
        }
        if (isset($a['rsvp']) && !in_array((string)$a['rsvp'], Contacts::RSVP, true)) {
            self::invalid('rsvp');
        }
        $id = (string)$a['id'];
        $who = Auth::user()['name'];
        $res = Store::update('contacts', function (array &$list) use ($id, $a, $canIdentity, $who) {
            foreach ($list as &$c) {
                if ($c['id'] !== $id) {
                    continue;
                }
                $old = array_merge(Contacts::blank(), $c);
                $new = Contacts::fromForm($old, array_merge($old, array_intersect_key($a, $old)));
                if (!$canIdentity) { // ohne Bearbeitungsrecht nur Abhaken, Rückmeldung und Kondolenz
                    foreach (['salutation', 'first', 'last', 'relation', 'note', 'street', 'zip', 'city', 'state', 'country', 'phone', 'mobile', 'email'] as $k) {
                        $new[$k] = $old[$k];
                    }
                }
                $new['id'] = $old['id'];
                $new['created'] = $old['created'];
                $new['updated'] = now();
                $new['updated_by'] = $who;
                $c = $new;
                return $new;
            }
            return null;
        });
        if (!$res) {
            self::missing();
        }
        Log::change('contacts', 'Kontakt bearbeitet', Contacts::fullName($res));
        return self::contactOut($res);
    }

    public static function contactsDelete(array $a): array
    {
        if (!Perm::can('contacts', Perm::EDIT)) {
            self::deny();
        }
        $id = (string)$a['id'];
        $name = Store::update('contacts', function (array &$list) use ($id) {
            foreach ($list as $k => $c) {
                if ($c['id'] === $id) {
                    unset($list[$k]);
                    $list = array_values($list);
                    return Contacts::fullName(array_merge(Contacts::blank(), $c));
                }
            }
            return null;
        });
        if ($name === null) {
            self::missing();
        }
        Log::change('contacts', 'Kontakt gelöscht', $name);
        return ['deleted' => $id];
    }

    public static function rsvpSummary(array $a): array
    {
        if (!Perm::can('rsvp')) {
            self::deny();
        }
        return Contacts::rsvpSums(Contacts::all());
    }

    /* ------------------------------------------------------------ Checkliste */

    private static function needChecklist(int $level = Perm::READ): void
    {
        if (!Perm::can('checklist', $level)) {
            self::deny();
        }
    }

    private static function taskOut(array $t): array
    {
        return [
            'id' => $t['id'], 'cat' => $t['cat'], 'parent' => $t['parent'] ?? '', 'title' => $t['title'], 'done' => !empty($t['done']),
            'done_by' => $t['done_by'] ?? '', 'done_at' => $t['done_at'] ?? '', 'due' => $t['due'] ?? '', 'who' => $t['who'] ?? '',
            'note' => $t['note'] ?? '', 'created' => $t['created'] ?? '',
        ];
    }

    private static function validDue(string $due): string
    {
        if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            self::invalid('due (JJJJ-MM-TT)');
        }
        return $due;
    }

    public static function checklistGet(array $a): array
    {
        self::needChecklist();
        self::checklistLib();
        $show = ($a['show'] ?? 'all') === 'open' ? 'open' : 'all';
        $d = cl_data();
        $tasks = [];
        foreach ($d['categories'] as $cat) {
            foreach ($d['byCat'][$cat['id']] ?? [] as $t) {
                $kids = $d['subs'][$t['id']] ?? [];
                if ($show === 'open' && !empty($t['done']) && !array_filter($kids, fn($k) => empty($k['done']))) {
                    continue;
                }
                $tasks[] = self::taskOut($t);
                foreach ($kids as $k) {
                    if ($show !== 'open' || empty($k['done'])) {
                        $tasks[] = self::taskOut($k);
                    }
                }
            }
        }
        [$open, $total] = cl_totals($d['items']);
        return [
            'categories' => array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name']], $d['categories']),
            'tasks' => $tasks, 'open' => $open, 'total' => $total,
        ];
    }

    public static function taskGet(array $a): array
    {
        self::needChecklist();
        $t = find_by_id(Store::read('checklist')['items'], (string)$a['id']);
        $t || self::missing();
        return self::taskOut($t);
    }

    public static function taskCreate(array $a): array
    {
        self::needChecklist(Perm::EDIT);
        $title = self::s($a, 'title', 300) ?? '';
        if ($title === '') {
            self::invalid('title');
        }
        $data = [
            'title' => $title, 'due' => self::validDue(self::s($a, 'due', 10) ?? ''),
            'who' => self::s($a, 'who', 100) ?? '', 'note' => self::s($a, 'note', 2000) ?? '',
        ];
        $parent = self::s($a, 'parent', 40) ?? '';
        $cat = self::s($a, 'cat', 40) ?? '';
        $res = Store::update('checklist', function (array &$d) use ($data, $parent, $cat) {
            $base = ['id' => new_id(), 'parent' => $parent, 'done' => false, 'done_by' => '', 'done_at' => '', 'created' => now()];
            if ($parent !== '') {
                $p = find_by_id($d['items'], $parent);
                if (!$p || !empty($p['parent'])) {
                    return null;
                }
                $cat = $p['cat'];
            } elseif (!find_by_id($d['categories'], $cat)) {
                return null;
            }
            $item = $data + ['cat' => $cat] + $base;
            $d['items'][] = $item;
            return $item;
        });
        if (!$res) {
            self::invalid('cat/parent');
        }
        Log::change('checklist', 'Aufgabe angelegt', $title);
        return self::taskOut($res);
    }

    public static function taskUpdate(array $a): array
    {
        $fields = array_intersect_key($a, ['title' => 1, 'due' => 1, 'who' => 1, 'note' => 1, 'cat' => 1]);
        $hasDone = array_key_exists('done', $a);
        if (!$fields && !$hasDone) {
            self::invalid('nothing to change');
        }
        if ($fields) {
            self::needChecklist(Perm::EDIT);
        }
        if ($hasDone) {
            self::needChecklist(Perm::CHECK);
        }
        $id = (string)$a['id'];
        $who = Auth::user()['name'];
        $res = Store::update('checklist', function (array &$d) use ($id, $a, $fields, $hasDone, $who) {
            $k = array_search($id, array_column($d['items'], 'id'), true);
            if ($k === false) {
                return ['err' => [404, t('cl.err_notfound')]];
            }
            $it = $d['items'][$k];
            if (isset($fields['title'])) {
                $title = self::s($a, 'title', 300) ?? '';
                if ($title === '') {
                    return ['err' => [422, 'title']];
                }
                $it['title'] = $title;
            }
            if (isset($fields['due'])) {
                $due = self::s($a, 'due', 10) ?? '';
                if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
                    return ['err' => [422, 'due (JJJJ-MM-TT)']];
                }
                $it['due'] = $due;
            }
            foreach (['who' => 100, 'note' => 2000] as $f => $max) {
                if (isset($fields[$f])) {
                    $it[$f] = self::s($a, $f, $max) ?? '';
                }
            }
            if (isset($fields['cat']) && empty($it['parent'])) {
                $cat = self::s($a, 'cat', 40) ?? '';
                if (!find_by_id($d['categories'], $cat)) {
                    return ['err' => [422, 'cat']];
                }
                $it['cat'] = $cat;
                foreach ($d['items'] as &$sub) {
                    if (($sub['parent'] ?? '') === $id) {
                        $sub['cat'] = $cat;
                    }
                }
                unset($sub);
            }
            if ($hasDone) {
                $done = self::bool($a['done']);
                if ($done !== !empty($it['done'])) {
                    $it['done'] = $done;
                    $it['done_by'] = $done ? $who : '';
                    $it['done_at'] = $done ? now() : '';
                }
            }
            $d['items'][$k] = $it;
            return $it;
        });
        if (isset($res['err'])) {
            throw new ApiError($res['err'][0], $res['err'][1]);
        }
        Log::change('checklist', $hasDone && !$fields ? (!empty($res['done']) ? 'erledigt' : 'wieder offen') : 'Aufgabe bearbeitet', $res['title']);
        return self::taskOut($res);
    }

    public static function taskMove(array $a): array
    {
        self::needChecklist(Perm::EDIT);
        self::checklistLib();
        $id = (string)$a['id'];
        $items = Store::read('checklist')['items'];
        $it = find_by_id($items, $id);
        $it || self::missing();
        $parent = array_key_exists('parent', $a) ? (self::s($a, 'parent', 40) ?? '') : (string)($it['parent'] ?? '');
        $cat = array_key_exists('cat', $a) && $parent === '' ? (self::s($a, 'cat', 40) ?? '') : (string)$it['cat'];
        $list = array_values(array_filter($items, fn($x) => $x['id'] !== $id
            && ($parent !== '' ? ($x['parent'] ?? '') === $parent : empty($x['parent']) && $x['cat'] === $cat)));
        usort($list, 'cl_cmp');
        $ids = array_column($list, 'id');
        $pos = isset($a['position']) ? max(0, min(count($ids), (int)$a['position'])) : count($ids);
        array_splice($ids, $pos, 0, [$id]);
        $res = cl_move($id, $cat, $parent, $ids);
        if (isset($res['err'])) {
            throw new ApiError($res['err'] === 'cl.err_notfound' ? 404 : 422, t($res['err']));
        }
        Log::change('checklist', $res['moved'] ? 'Aufgabe verschoben' : 'Aufgabenreihenfolge geändert', $res['title']);
        return self::taskOut(find_by_id(Store::read('checklist')['items'], $id));
    }

    public static function taskDelete(array $a): array
    {
        self::needChecklist(Perm::EDIT);
        $id = (string)$a['id'];
        $res = Store::update('checklist', function (array &$d) use ($id) {
            foreach ($d['items'] as $i) {
                if ($i['id'] === $id) {
                    $d['items'] = array_values(array_filter($d['items'], fn($x) => $x['id'] !== $id && ($x['parent'] ?? '') !== $id));
                    return $i['title'];
                }
            }
            return null;
        });
        if ($res === null) {
            self::missing();
        }
        Log::change('checklist', 'Aufgabe gelöscht', $res);
        return ['deleted' => $id];
    }

    public static function categoryCreate(array $a): array
    {
        self::needChecklist(Perm::EDIT);
        $name = self::s($a, 'name', 100) ?? '';
        if ($name === '') {
            self::invalid('name');
        }
        $cat = Store::update('checklist', function (array &$d) use ($name) {
            $c = ['id' => new_id(), 'name' => $name, 'sort' => count($d['categories'])];
            $d['categories'][] = $c;
            return $c;
        });
        Log::change('checklist', 'Kategorie angelegt', $name);
        return ['id' => $cat['id'], 'name' => $cat['name']];
    }

    public static function categoryUpdate(array $a): array
    {
        self::needChecklist(Perm::EDIT);
        $name = self::s($a, 'name', 100) ?? '';
        if ($name === '') {
            self::invalid('name');
        }
        $id = (string)$a['id'];
        $ok = Store::update('checklist', function (array &$d) use ($id, $name) {
            foreach ($d['categories'] as &$c) {
                if ($c['id'] === $id) {
                    $c['name'] = $name;
                    return true;
                }
            }
            return false;
        });
        $ok || self::missing();
        Log::change('checklist', 'Kategorie umbenannt', $name);
        return ['id' => $id, 'name' => $name];
    }

    public static function categoryDelete(array $a): array
    {
        self::needChecklist(Perm::EDIT);
        $id = (string)$a['id'];
        $name = Store::update('checklist', function (array &$d) use ($id) {
            foreach ($d['categories'] as $k => $c) {
                if ($c['id'] === $id) {
                    unset($d['categories'][$k]);
                    $d['categories'] = array_values($d['categories']);
                    $d['items'] = array_values(array_filter($d['items'], fn($i) => $i['cat'] !== $id));
                    return $c['name'];
                }
            }
            return null;
        });
        if ($name === null) {
            self::missing();
        }
        Log::change('checklist', 'Kategorie samt Aufgaben gelöscht', $name);
        return ['deleted' => $id];
    }

    /* ------------------------------------------------------------ Galerie */

    private static function needGallery(string $what = 'view'): void
    {
        if (!Perm::gallery($what)) {
            self::deny(t('gal.denied'));
        }
    }

    private static function imageOut(array $i): array
    {
        $out = [
            'id' => $i['id'], 'caption' => $i['caption'] ?? '', 'orig_name' => $i['orig_name'] ?? '', 'album' => $i['album'] ?? '',
            'links' => array_values($i['links'] ?? []), 'width' => $i['w'] ?? 0, 'height' => $i['h'] ?? 0, 'size' => $i['size'] ?? 0,
            'mime' => $i['mime'] ?? '', 'animated' => !empty($i['animated']), 'uploaded_by' => $i['uploaded_by'] ?? '',
            'uploaded_at' => $i['uploaded_at'] ?? '',
            'file' => url('api', ['p' => '/gallery/images/' . $i['id'] . '/file']) . '&size=',
        ];
        if (Auth::isAdmin()) {
            $out['login'] = !empty($i['login']);
        }
        return $out;
    }

    private static function albumOut(array $al, array $g): array
    {
        return [
            'id' => $al['id'], 'name' => $al['name'], 'parent' => $al['parent'] ?? '', 'links' => array_values($al['links'] ?? []),
            'cat' => $al['cat'] ?? '', 'color' => $al['color'] ?? '', 'sort' => $al['sort'] ?? 0,
            'hidden' => !empty($al['hidden']),
            'images' => count(Albums::images($g['images'], Albums::subtree($g['albums'], $al['id']))),
        ];
    }

    public static function albums(array $a): array
    {
        self::needGallery();
        $g = Albums::forViewer(Store::read('gallery'));
        usort($g['albums'], fn($x, $y) => ($x['sort'] ?? 0) <=> ($y['sort'] ?? 0));
        return ['items' => array_map(fn($al) => self::albumOut($al, $g), $g['albums']),
                'unassigned' => count(array_filter($g['images'], fn($i) => $i['album'] === ''))];
    }

    private static function albumFields(array $a, array $g, string $selfId, array $cur): array
    {
        $name = self::s($a, 'name', 100) ?? $cur['name'];
        if ($name === '') {
            self::invalid('name');
        }
        $color = array_key_exists('color', $a) ? (in_array((string)$a['color'], Albums::COLORS, true) ? (string)$a['color'] : '') : ($cur['color'] ?? '');
        $own = $selfId !== '' ? Albums::subtree($g['albums'], $selfId) : [];
        $parent = array_key_exists('parent', $a) ? (string)$a['parent'] : (string)($cur['parent'] ?? '');
        $parent = ($parent !== '' && find_by_id($g['albums'], $parent) && !in_array($parent, $own, true)) ? $parent : '';
        $links = Albums::cleanLinks($g['albums'], array_key_exists('links', $a) ? $a['links'] : ($cur['links'] ?? []), array_merge($own, [$parent]));
        return ['name' => $name, 'cat' => self::s($a, 'cat', 40) ?? ($cur['cat'] ?? ''), 'color' => $color, 'parent' => $parent, 'links' => $links,
                'hidden' => array_key_exists('hidden', $a) ? self::bool($a['hidden']) : !empty($cur['hidden'])];
    }

    public static function albumCreate(array $a): array
    {
        self::needAdmin();
        $res = Store::update('gallery', function (array &$g) use ($a) {
            $f = self::albumFields($a, $g, '', ['name' => '']);
            $al = ['id' => new_id()] + $f + ['sort' => count($g['albums']), 'created' => now()];
            $g['albums'][] = $al;
            return self::albumOut($al, $g);
        });
        Log::add('admin', 'Album gespeichert: ' . $res['name']);
        return $res;
    }

    public static function albumUpdate(array $a): array
    {
        self::needAdmin();
        $id = (string)$a['id'];
        $res = Store::update('gallery', function (array &$g) use ($a, $id) {
            foreach ($g['albums'] as &$al) {
                if ($al['id'] === $id) {
                    $al = self::albumFields($a, $g, $id, $al) + $al;
                    return self::albumOut($al, $g);
                }
            }
            return null;
        });
        $res || self::missing();
        Log::add('admin', 'Album gespeichert: ' . $res['name']);
        return $res;
    }

    public static function albumDelete(array $a): array
    {
        self::needAdmin();
        $id = (string)$a['id'];
        $found = Store::update('gallery', function (array &$g) use ($id) {
            $gone = find_by_id($g['albums'], $id);
            if (!$gone) {
                return false;
            }
            $up = (string)($gone['parent'] ?? '');
            $g['albums'] = array_values(array_filter($g['albums'], fn($al) => $al['id'] !== $id));
            foreach ($g['albums'] as &$al) {
                if (($al['parent'] ?? '') === $id) {
                    $al['parent'] = $up;
                }
                $al['links'] = array_values(array_diff($al['links'] ?? [], [$id]));
            }
            unset($al);
            foreach ($g['images'] as &$i) {
                if ($i['album'] === $id) {
                    $i['album'] = $up;
                }
                $i['links'] = array_values(array_diff($i['links'] ?? [], [$id, $i['album']]));
            }
            return true;
        });
        $found || self::missing();
        Log::add('admin', 'Album gelöscht (Unteralben und Bilder bleiben erhalten)');
        return ['deleted' => $id];
    }

    public static function galleryOrder(array $a): array
    {
        self::needGallery('upload');
        self::galleryLib();
        $key = (string)$a['id'];
        $ids = array_map('strval', (array)($a['ids'] ?? []));
        $order = Store::update('gallery', function (array &$g) use ($key, $ids) {
            if ($key !== '_none' && !find_by_id($g['albums'], $key)) {
                return null;
            }
            gallery_apply_order($g, $key, $ids);
            return array_column(gallery_order_scope($g, $key), 'id');
        });
        $order === null && self::missing();
        Log::change('gallery', 'Bildreihenfolge geändert', $key);
        return ['album' => $key, 'ids' => $order];
    }

    public static function images(array $a): array
    {
        self::needGallery();
        $g = Albums::forViewer(Store::read('gallery'));
        $album = (string)($a['album'] ?? '');
        if ($album === '_none') {
            $list = Albums::sortIn(array_values(array_filter($g['images'], fn($i) => $i['album'] === '')), '_none');
        } elseif ($album !== '') {
            find_by_id($g['albums'], $album) || self::missing();
            $rec = self::bool($a['recursive'] ?? false);
            $list = Albums::images($g['images'], $rec ? Albums::subtree($g['albums'], $album) : [$album]);
            $list = $rec ? $list : Albums::sortIn($list, $album);
        } else {
            $list = $g['images'];
        }
        [$limit, $offset] = self::page($a);
        return ['total' => count($list), 'limit' => $limit, 'offset' => $offset,
                'items' => array_map([self::class, 'imageOut'], array_slice($list, $offset, $limit))];
    }

    public static function imageGet(array $a): array
    {
        self::needGallery();
        $i = find_by_id(Albums::forViewer(Store::read('gallery'))['images'], (string)$a['id']);
        $i || self::missing();
        return self::imageOut($i);
    }

    public static function imageUpdate(array $a): array
    {
        self::needGallery();
        $id = (string)$a['id'];
        $img = find_by_id(Albums::forViewer(Store::read('gallery'))['images'], $id);
        $img || self::missing();
        if (!Auth::isAdmin() && ($img['uploaded_by_id'] ?? '') !== self::uid()) {
            self::deny(t('gal.denied_edit'));
        }
        $res = Store::update('gallery', function (array &$g) use ($id, $a) {
            foreach ($g['images'] as &$i) {
                if ($i['id'] !== $id) {
                    continue;
                }
                if (($c = self::s($a, 'caption', 300)) !== null) {
                    $i['caption'] = $c;
                }
                if (array_key_exists('album', $a)) {
                    $alb = (string)$a['album'];
                    $i['album'] = in_array($alb, array_column($g['albums'], 'id'), true) ? $alb : '';
                }
                if (array_key_exists('links', $a) || array_key_exists('album', $a)) {
                    $i['links'] = Albums::cleanLinks($g['albums'], array_key_exists('links', $a) ? $a['links'] : ($i['links'] ?? []), [$i['album']]);
                }
                if (Auth::isAdmin() && array_key_exists('login', $a)) {
                    $i['login'] = self::bool($a['login']);
                }
                return $i;
            }
            return null;
        });
        Log::change('gallery', 'Bild bearbeitet', $id);
        return self::imageOut($res);
    }

    public static function imageDelete(array $a): array
    {
        self::needAdmin();
        $id = (string)$a['id'];
        $img = Store::update('gallery', function (array &$g) use ($id) {
            foreach ($g['images'] as $k => $i) {
                if ($i['id'] === $id) {
                    unset($g['images'][$k]);
                    $g['images'] = array_values($g['images']);
                    return $i;
                }
            }
            return null;
        });
        $img || self::missing();
        Image::delete($img);
        Store::update('memorial', function (array &$m) use ($id) {
            if (($m['portrait'] ?? '') === $id) {
                $m['portrait'] = '';
            }
        });
        Log::add('admin', 'Bild gelöscht: ' . ($img['caption'] ?: $img['orig_name']));
        return ['deleted' => $id];
    }

    /** Sendet die Bilddatei (REST). */
    public static function imageFile(array $a): never
    {
        self::needGallery();
        self::galleryLib();
        $_GET['id'] = (string)$a['id'];
        $_GET['s'] = (string)($a['size'] ?? 'medium');
        gallery_serve();
    }

    /** Nimmt Bilder per multipart entgegen (REST); antwortet wie die Oberfläche. */
    public static function imageUpload(array $a): never
    {
        self::needGallery('upload');
        self::galleryLib();
        gallery_upload();
    }

    /** Bilddaten für MCP (Vorschau/Großansicht, base64). @return array{0: string, 1: string} */
    public static function imageBinary(string $id, string $size): array
    {
        self::needGallery();
        $img = find_by_id(Albums::forViewer(Store::read('gallery'))['images'], $id);
        $img || self::missing();
        $size = $size === 'thumb' ? 'thumb' : 'medium';
        $f = Image::path($img, $size);
        is_file($f) || self::missing();
        return [Image::variantMime($img, $size), base64_encode((string)file_get_contents($f))];
    }

    /* ------------------------------------------------------------ Stationen (Plugin) */

    public static function stations(array $a): array
    {
        if (!Perm::memorial() || !class_exists('Stations')) {
            self::deny();
        }
        $keep = array_flip(['id', 'type', 'title', 'date', 'place', 'lat', 'lon', 'text', 'album', 'image']);
        return ['items' => array_map(fn($s) => array_intersect_key($s, $keep), Stations::all())];
    }
}
