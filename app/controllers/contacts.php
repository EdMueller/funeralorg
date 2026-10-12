<?php
declare(strict_types=1);

function ctrl_contacts(string $r, string $a): void
{
    // $r ist der Bereich: contacts | rsvp | condolences
    Perm::need($r, Perm::READ);
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $who = Auth::user()['name'];

    switch ($a) {
        case 'toggle':
            if (!$isPost) {
                abort(400);
            }
            $field = (string)post('field');
            $module = match ($field) {
                'called', 'letter' => 'contacts',
                'card', 'flowers', 'wreath', 'donation', 'thanks' => 'condolences',
                default => abort(400),
            };
            Perm::need($module, Perm::CHECK);
            $id = (string)post('id');
            $res = contacts_update($id, function (array &$c) use ($field) {
                $c[$field] = empty($c[$field]);
                return $c[$field];
            });
            $labels = ['called' => 'angerufen', 'letter' => 'Brief versendet', 'card' => 'Karte', 'flowers' => 'Blumen',
                       'wreath' => 'Kranz', 'donation' => 'Spende', 'thanks' => 'Dankeskarte versendet'];
            Log::change($module, $labels[$field] . ': ' . ($res['value'] ? 'ja' : 'nein'), $res['name']);
            if (is_ajax()) {
                json_out(['ok' => true, 'value' => $res['value']]);
            }
            redirect_back($r);

        case 'rsvpset':
            if (!$isPost) {
                abort(400);
            }
            Perm::need('rsvp', Perm::CHECK);
            $id = (string)post('id');
            $status = (string)post('rsvp');
            if (!in_array($status, Contacts::RSVP, true)) {
                abort(400);
            }
            $res = contacts_update($id, function (array &$c) use ($status) {
                $c['rsvp'] = $status;
                $c['adults'] = max(0, min(99, (int)post('adults', 0)));
                $c['children'] = max(0, min(99, (int)post('children', 0)));
                if (isset($_POST['rsvp_note'])) {
                    $c['rsvp_note'] = mb_substr((string)post('rsvp_note'), 0, 500);
                }
                if ($status === 'yes' && $c['adults'] + $c['children'] === 0) {
                    $c['adults'] = 1;
                }
                return ['rsvp' => $c['rsvp'], 'adults' => $c['adults'], 'children' => $c['children']];
            });
            Log::change('rsvp', 'Rückmeldung: ' . I18n::in('de', fn() => Contacts::rsvpLabels()[$status]) . ' (' . $res['value']['adults'] . ' E / '
                . $res['value']['children'] . ' K)', $res['name']);
            if (is_ajax()) {
                json_out(['ok' => true, 'sums' => Contacts::rsvpSums(Contacts::all())] + $res['value']);
            }
            redirect_back('rsvp');

        case 'condset':
            if (!$isPost) {
                abort(400);
            }
            Perm::need('condolences', Perm::CHECK);
            $id = (string)post('id');
            $res = contacts_update($id, function (array &$c) {
                $c['amount'] = max(0, (float)str_replace(',', '.', (string)post('amount', '0')));
                $c['cond_note'] = mb_substr((string)post('cond_note'), 0, 500);
                return true;
            });
            Log::change('condolences', 'Kondolenz-Details bearbeitet', $res['name']);
            flash(t('contacts.saved_short'));
            redirect_back('condolences');

        case 'cellset':
            if (!$isPost) {
                abort(400);
            }
            Perm::need('contacts', Perm::EDIT);
            $field = (string)post('field');
            $spec = Contacts::TEXT_FIELDS[$field] ?? null;
            if ($spec === null || ($spec[0] !== null && !Perm::field($spec[0]))) {
                abort(403, t('contacts.err_field'));
            }
            $value = mb_substr(trim((string)($_POST['value'] ?? '')), 0, $spec[1]);
            $res = contacts_update((string)post('id'), function (array &$c) use ($field, $value) {
                $c[$field] = $value;
                return $value;
            });
            Log::change('contacts', I18n::in('de', fn() => t('cf.' . $field)) . ' bearbeitet (Tabelle)', $res['name']);
            json_out(['ok' => true, 'value' => $res['value']]);

        case 'bulkdelete':
            if (!$isPost) {
                abort(400);
            }
            Perm::need('contacts', Perm::EDIT);
            $ids = contacts_selected();
            $names = Store::update('contacts', function (array &$list) use ($ids) {
                $names = [];
                foreach ($list as $k => $c) {
                    if (in_array($c['id'], $ids, true)) {
                        $names[] = Contacts::fullName(array_merge(Contacts::blank(), $c));
                        unset($list[$k]);
                    }
                }
                $list = array_values($list);
                return $names;
            });
            foreach ($names as $n) {
                Log::change('contacts', 'Kontakt gelöscht (Tabelle)', $n);
            }
            flash(tn('contacts.bulk_deleted', count($names)), $names ? 'success' : 'warning');
            redirect('contacts');

        case 'bulkset':
            if (!$isPost) {
                abort(400);
            }
            $what = (string)post('what');
            $ids = contacts_selected();
            if (in_array($what, ['called_1', 'called_0', 'letter_1', 'letter_0'], true)) {
                Perm::need('contacts', Perm::CHECK);
                [$field, $val] = explode('_', $what);
                $apply = function (array &$c) use ($field, $val) { $c[$field] = $val === '1'; };
                $label = t('contacts.bulk_' . $field . '_' . $val);
                $logLabel = I18n::in('de', fn() => t('contacts.bulk_' . $field . '_' . $val));
            } elseif ($what === 'relation') {
                Perm::need('contacts', Perm::EDIT);
                $rel = mb_substr((string)post('value'), 0, 500);
                $apply = function (array &$c) use ($rel) { $c['relation'] = $rel; };
                $label = t('contacts.bulk_relation');
                $logLabel = I18n::in('de', fn() => t('contacts.bulk_relation'));
            } else {
                abort(400);
            }
            $n = Store::update('contacts', function (array &$list) use ($ids, $apply, $who) {
                $n = 0;
                foreach ($list as &$c) {
                    if (in_array($c['id'], $ids, true)) {
                        $c = array_merge(Contacts::blank(), $c);
                        $apply($c);
                        $c['updated'] = now();
                        $c['updated_by'] = $who;
                        $n++;
                    }
                }
                return $n;
            });
            Log::change('contacts', 'Sammeländerung: ' . $logLabel, $n . ' Kontakte');
            flash(tn('contacts.bulk_done', $n, ['label' => $label]));
            redirect('contacts');

        case 'form':
            Perm::need('contacts', Perm::EDIT);
            $id = (string)get('id');
            $c = $id !== '' ? find_by_id(Contacts::all(), $id) : Contacts::blank();
            if (!$c) {
                abort(404);
            }
            $from = in_array(get('from'), ['contacts', 'rsvp', 'condolences'], true) ? (string)get('from') : $r;
            view('contact_form', ['c' => $c, 'relations' => Contacts::relations(Contacts::all()), 'from' => $from]);
            return;

        case 'save':
            if (!$isPost) {
                abort(400);
            }
            Perm::need('contacts', Perm::EDIT);
            $id = (string)post('id');
            $name = '';
            Store::update('contacts', function (array &$list) use ($id, $who, &$name) {
                if ($id !== '') {
                    foreach ($list as &$c) {
                        if ($c['id'] === $id) {
                            $c = Contacts::fromForm(array_merge(Contacts::blank(), $c), $_POST);
                            $c['updated'] = now();
                            $c['updated_by'] = $who;
                            $name = Contacts::fullName($c);
                            return;
                        }
                    }
                }
                $c = Contacts::fromForm(Contacts::blank(), $_POST);
                $c['id'] = new_id();
                $c['created'] = $c['updated'] = now();
                $c['updated_by'] = $who;
                $name = Contacts::fullName($c);
                $list[] = $c;
            });
            if ($name === '') {
                flash(t('contacts.saved_noname'), 'warning');
            } else {
                flash(t('contacts.saved', ['name' => $name]));
            }
            Log::change('contacts', $id ? 'Kontakt bearbeitet' : 'Kontakt angelegt', $name);
            $from = in_array(post('from'), ['contacts', 'rsvp', 'condolences'], true) ? post('from') : 'contacts';
            redirect($from);

        case 'delete':
            if (!$isPost) {
                abort(400);
            }
            Perm::need('contacts', Perm::EDIT);
            $id = (string)post('id');
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
            if ($name !== null) {
                Log::change('contacts', 'Kontakt gelöscht', $name);
                flash(t('contacts.deleted', ['name' => $name]));
            }
            redirect('contacts');

        case 'import':
            Perm::need('contacts', Perm::EDIT);
            $result = null;
            if ($isPost) {
                $f = $_FILES['vcf'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 10 * 1024 * 1024) {
                    flash(t('contacts.import_err'), 'warning');
                    redirect('contacts', ['a' => 'import']);
                }
                $cards = VCard::parse((string)file_get_contents($f['tmp_name']));
                $allowDup = !empty($_POST['allow_dup']);
                $relation = mb_substr((string)post('relation'), 0, 100);
                $result = Store::update('contacts', function (array &$list) use ($cards, $allowDup, $relation, $who) {
                    $res = ['added' => [], 'skipped' => []];
                    foreach ($cards as $card) {
                        $c = array_merge(Contacts::blank(), $card);
                        $c['relation'] = $relation;
                        $existing = array_map(fn($x) => array_merge(Contacts::blank(), $x), $list);
                        if (!$allowDup && Contacts::isDuplicate($existing, $c)) {
                            $res['skipped'][] = Contacts::fullName($c);
                            continue;
                        }
                        $c['id'] = new_id();
                        $c['created'] = $c['updated'] = now();
                        $c['updated_by'] = $who;
                        $list[] = $c;
                        $res['added'][] = Contacts::fullName($c);
                    }
                    return $res;
                });
                $result['found'] = count($cards);
                Log::change('contacts', 'vCard-Import: ' . count($result['added']) . ' neu, '
                    . count($result['skipped']) . ' übersprungen', $f['name']);
            }
            view('contacts_import', ['result' => $result, 'relations' => Contacts::relations(Contacts::all())]);
            return;

        case 'export':
            $fmt = get('fmt') === 'xlsx' ? 'xlsx' : 'csv';
            $list = Contacts::filter(Contacts::all(), (string)get('filter'), (string)get('rel'));
            if ($ids = contacts_selected()) {
                $list = array_values(array_filter($list, fn($c) => in_array($c['id'], $ids, true)));
            }
            if ($r === 'rsvp') {
                $list = array_values(array_filter($list, fn($c) => $c['rsvp'] !== ''));
            }
            if ($r === 'condolences' && get('filter') === '') {
                $list = Contacts::filter($list, 'cond_any');
            }
            [$head, $rows] = Contacts::exportTable($list, $r);
            $names = ['contacts' => t('contacts.file.contacts'), 'rsvp' => t('contacts.file.rsvp'), 'condolences' => t('contacts.file.condolences')];
            $logNames = ['contacts' => 'Kontakte', 'rsvp' => 'Rueckmeldungen', 'condolences' => 'Kondolenzen'];
            $file = $names[$r] . '_' . date('Y-m-d');
            Log::add('export', $logNames[$r] . ' (' . strtoupper($fmt) . ', ' . count($rows) . ' Einträge)');
            if ($fmt === 'xlsx') {
                Xlsx::send($file . '.xlsx', $names[$r], $head, $rows);
            }
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $file . '.csv"');
            header('Cache-Control: no-store');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $head, ';', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, csv_safe(array_map(fn($v) => is_float($v) ? number_format($v, 2, ',', '') : $v, $row)), ';', '"', '');
            }
            fclose($out);
            exit;

        case 'access':
            // Zugänge anlegen / Passwörter neu setzen und als Klebestreifen drucken (Klartext nur in dieser Antwort)
            if (!$isPost) {
                abort(400);
            }
            Perm::needAdmin();
            $mode = get('mode') === 'reset' ? 'reset' : 'new';
            $ids = contacts_selected();
            $groupId = (string)post('group_id');
            if (!$ids) {
                flash(t('contacts.pick_first'), 'warning');
                redirect_back($r);
            }
            if ($mode === 'new' && !find_by_id(Store::read('groups'), $groupId)) {
                flash(t('contacts.pick_group'), 'warning');
                redirect_back($r);
            }
            $selected = array_values(array_filter(Contacts::all(), fn($c) => in_array($c['id'], $ids, true)));
            $res = Access::issue($selected, $groupId, $mode);
            if (!$res['rows']) {
                flash(t('contacts.nothing_print', ['why' => implode('; ', $res['skipped'])]), 'warning');
                redirect_back($r);
            }
            Log::add('admin', ($mode === 'new' ? 'Zugänge angelegt: ' : 'Passwörter neu gesetzt: ') . count($res['rows']));
            $formats = Access::formats();
            $fk = array_key_exists((string)post('format'), $formats) ? (string)post('format') : (string)array_key_first($formats);
            header('Cache-Control: no-store');
            view('access_sheet', ['rows' => $res['rows'], 'skipped' => $res['skipped'], 'fmt' => $formats[$fk],
                'start' => max(1, (int)post('start', 1)), 'mode' => $mode, 'addr' => site_base_url()], null);
            return;

        case 'labels':
            if (!Perm::field('address')) {
                abort(403, t('contacts.labels_denied'));
            }
            $formats = cfg('label_formats', []);
            $in = $_POST + $_GET;
            $fk = array_key_exists((string)($in['format'] ?? ''), $formats) ? (string)$in['format'] : (string)array_key_first($formats);
            $list = Contacts::filter(Contacts::all(), (string)($in['filter'] ?? ''), (string)($in['rel'] ?? ''));
            if ($ids = contacts_selected()) {
                $list = array_values(array_filter($list, fn($c) => in_array($c['id'], $ids, true)));
            }
            $list = array_values(array_filter($list, fn($c) => $c['street'] !== '' && $c['city'] !== ''));
            if ($r === 'condolences' && ($in['filter'] ?? '') === '') {
                $list = Contacts::filter($list, 'cond_any');
            }
            $start = max(1, (int)($in['start'] ?? 1));
            Log::add('export', 'Etiketten ' . $fk . ' (' . count($list) . ' Adressen)');
            view('labels', ['list' => $list, 'fmt' => $formats[$fk], 'start' => $start], null);
            return;
    }

    // Übersicht
    $all = Contacts::all();
    $filter = (string)get('filter');
    $rel = (string)get('rel');
    $list = Contacts::filter($all, $filter, $rel);
    if ($r === 'rsvp' && $filter === '') {
        $list = array_values(array_filter($list, fn($c) => $c['rsvp'] !== ''));
    }
    if (Auth::isAdmin()) { // Spalte „Zugang“: Benutzername des verknüpften Zugangs
        $acc = Access::byContact();
        foreach ($list as &$c) {
            $c['access'] = $acc[$c['id']] ?? '';
        }
        unset($c);
    }
    $mode = in_array(get('view'), ['cards', 'table'], true) ? (string)get('view')
        : (($_COOKIE['gdk_cview'] ?? '') === 'table' ? 'table' : 'cards');
    view($r === 'contacts' ? 'contacts' : $r, [
        'mode'      => $r === 'contacts' ? $mode : 'cards',
        'list'      => $list,
        'all'       => $all,
        'filter'    => $filter,
        'rel'       => $rel,
        'relations' => Contacts::relations($all),
        'sums'      => Contacts::rsvpSums($all),
        'section'   => $r,
        'formats'   => cfg('label_formats', []),
    ]);
}

/** Per POST übergebene Kontakt-IDs (Auswahl in der Tabellenansicht), nur gültige ID-Formate. */
function contacts_selected(): array
{
    $ids = $_POST['ids'] ?? [];
    return is_array($ids)
        ? array_values(array_filter($ids, fn($i) => is_string($i) && preg_match('/^[0-9a-f]{16}$/', $i) === 1))
        : [];
}

/** Aktualisiert einen Kontakt unter Lock. */
function contacts_update(string $id, callable $fn): array
{
    $who = Auth::user()['name'];
    $res = Store::update('contacts', function (array &$list) use ($id, $fn, $who) {
        foreach ($list as &$c) {
            if ($c['id'] === $id) {
                $c = array_merge(Contacts::blank(), $c);
                $value = $fn($c);
                $c['updated'] = now();
                $c['updated_by'] = $who;
                return ['value' => $value, 'name' => Contacts::fullName($c)];
            }
        }
        return null;
    });
    if ($res === null) {
        abort(404);
    }
    return $res;
}
