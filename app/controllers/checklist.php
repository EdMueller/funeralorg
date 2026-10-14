<?php
declare(strict_types=1);

/** Kategorien, Hauptaufgaben je Kategorie und Unteraufgaben je Hauptaufgabe (sortiert). */
function cl_data(): array
{
    $d = Store::read('checklist');
    usort($d['categories'], fn($x, $y) => $x['sort'] <=> $y['sort']);
    $cmp = fn($x, $y) => [(int)$x['done'], $x['due'] ?: '9999', $x['created']]
        <=> [(int)$y['done'], $y['due'] ?: '9999', $y['created']];
    $byCat = [];
    $subs = [];
    foreach ($d['items'] as $i) {
        if (!empty($i['parent'])) {
            $subs[$i['parent']][] = $i;
        } else {
            $byCat[$i['cat']][] = $i;
        }
    }
    foreach ($byCat as &$list) {
        usort($list, $cmp);
    }
    unset($list);
    foreach ($subs as &$list) {
        usort($list, $cmp);
    }
    unset($list);
    return ['categories' => $d['categories'], 'byCat' => $byCat, 'subs' => $subs, 'items' => $d['items']];
}

function cl_vars(string $show): array
{
    return cl_data() + [
        'show'     => $show,
        'canCheck' => Perm::can('checklist', Perm::CHECK),
        'canEdit'  => Perm::can('checklist', Perm::EDIT),
        'today'    => date('Y-m-d'),
    ];
}

/** [offen, gesamt] über alle Aufgaben und Unteraufgaben. */
function cl_totals(array $items): array
{
    $open = count(array_filter($items, fn($i) => empty($i['done'])));
    return [$open, count($items)];
}

function cl_lead(array $items): string
{
    [$open, $total] = cl_totals($items);
    return t('cl.lead', ['open' => $open, 'done' => $total - $open]);
}

/** HTML eines Kategorieblocks (leer, wenn die Kategorie nicht existiert oder im Filter ausgeblendet ist). */
function cl_cat_html(array $v, string $catId): string
{
    foreach ($v['categories'] as $cat) {
        if ($cat['id'] === $catId) {
            ob_start();
            view('_checklist_cat', $v + ['cat' => $cat], null);
            return ob_get_clean();
        }
    }
    return '';
}

/** Daten für die dynamische Aktualisierung der Seite: betroffene Kategorieblöcke, Reihenfolge, Zähler. */
function cl_refresh(array $catIds): array
{
    $v = cl_vars(post('show') === 'open' ? 'open' : 'all');
    $cats = [];
    foreach (array_unique(array_filter($catIds)) as $id) {
        $cats[] = ['id' => $id, 'html' => cl_cat_html($v, $id)];
    }
    return [
        'cats'    => $cats,
        'order'   => array_column($v['categories'], 'id'),
        'options' => array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name']], $v['categories']),
        'empty'   => !$v['categories'],
        'lead'    => cl_lead($v['items']),
    ];
}

/** AJAX: JSON mit Aktualisierungsdaten; sonst Meldung und Weiterleitung. */
function cl_respond(string $msg, array $catIds): never
{
    if (is_ajax()) {
        json_out(['ok' => true, 'msg' => $msg] + cl_refresh($catIds));
    }
    if ($msg !== '') {
        flash($msg);
    }
    redirect_back('checklist');
}

function ctrl_checklist(string $r, string $a): void
{
    Perm::need('checklist', Perm::READ);
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $who = Auth::user()['name'];

    switch ($a) {
        case 'toggle':
            if (!$isPost) {
                abort(400);
            }
            Perm::need('checklist', Perm::CHECK);
            $id = (string)post('id');
            $state = Store::update('checklist', function (array &$d) use ($id, $who) {
                foreach ($d['items'] as &$i) {
                    if ($i['id'] === $id) {
                        $i['done'] = empty($i['done']);
                        $i['done_by'] = $i['done'] ? $who : '';
                        $i['done_at'] = $i['done'] ? now() : '';
                        return ['done' => $i['done'], 'title' => $i['title'], 'by' => $i['done_by'],
                                'at' => fmt_date($i['done_at'], true), 'cat' => $i['cat'], 'parent' => $i['parent'] ?? ''];
                    }
                }
                return null;
            });
            if (!$state) {
                abort(404);
            }
            Log::change('checklist', $state['done'] ? 'erledigt' : 'wieder offen', $state['title']);
            if (is_ajax()) {
                $items = Store::read('checklist')['items'];
                $count = function (array $list): string {
                    return count(array_filter($list, fn($i) => !empty($i['done']))) . ' / ' . count($list);
                };
                $out = ['cat' => $state['cat'], 'cat_progress' => $count(array_filter($items, fn($i) => $i['cat'] === $state['cat'])),
                        'lead' => cl_lead($items)];
                if ($state['parent'] !== '') {
                    $out['parent'] = $state['parent'];
                    $out['parent_progress'] = $count(array_filter($items, fn($i) => ($i['parent'] ?? '') === $state['parent']));
                }
                json_out(['ok' => true] + $state + $out);
            }
            redirect_back('checklist');

        case 'save':
            if (!$isPost) {
                abort(400);
            }
            Perm::need('checklist', Perm::EDIT);
            $id = (string)post('id');
            $title = mb_substr((string)post('title'), 0, 300);
            if ($title === '') {
                if (is_ajax()) {
                    json_out(['ok' => false, 'error' => t('cl.err_title')], 422);
                }
                flash(t('cl.err_title'), 'warning');
                redirect_back('checklist');
            }
            $data = [
                'title' => $title,
                'cat'   => (string)post('cat'),
                'due'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)post('due')) ? (string)post('due') : '',
                'who'   => mb_substr((string)post('who'), 0, 100),
                'note'  => mb_substr((string)post('note'), 0, 2000),
            ];
            $parent = (string)post('parent');
            $res = Store::update('checklist', function (array &$d) use ($id, $data, $parent) {
                if ($id !== '') {
                    foreach ($d['items'] as &$i) {
                        if ($i['id'] === $id) {
                            $oldCat = $i['cat'];
                            if (!empty($i['parent'])) {
                                // Unteraufgabe: Kategorie bleibt die der Hauptaufgabe
                                unset($data['cat']);
                            }
                            $i = array_merge($i, $data);
                            $newCat = $i['cat'];
                            if (empty($i['parent']) && isset($data['cat'])) {
                                foreach ($d['items'] as &$sub) {
                                    if (($sub['parent'] ?? '') === $id) {
                                        $sub['cat'] = $data['cat'];
                                    }
                                }
                            }
                            return ['cats' => [$oldCat, $newCat]];
                        }
                    }
                    return null;
                }
                if ($parent !== '') {
                    foreach ($d['items'] as $p) {
                        if ($p['id'] === $parent && empty($p['parent'])) {
                            $data['cat'] = $p['cat'];
                            $d['items'][] = $data + ['id' => new_id(), 'parent' => $parent, 'done' => false, 'done_by' => '', 'done_at' => '', 'created' => now()];
                            return ['cats' => [$p['cat']]];
                        }
                    }
                    return null;
                }
                $d['items'][] = $data + ['id' => new_id(), 'parent' => '', 'done' => false, 'done_by' => '', 'done_at' => '', 'created' => now()];
                return ['cats' => [$data['cat']]];
            });
            if (!$res) {
                if (is_ajax()) {
                    json_out(['ok' => false, 'error' => t('cl.err_notfound')], 404);
                }
                flash(t('cl.err_notfound'), 'warning');
                redirect_back('checklist');
            }
            Log::change('checklist', $id ? 'Aufgabe bearbeitet' : 'Aufgabe angelegt', $title);
            cl_respond(t($id ? 'cl.saved' : 'cl.created'), $res['cats']);

        case 'delete':
            if (!$isPost) {
                abort(400);
            }
            Perm::need('checklist', Perm::EDIT);
            $id = (string)post('id');
            $res = Store::update('checklist', function (array &$d) use ($id) {
                foreach ($d['items'] as $i) {
                    if ($i['id'] === $id) {
                        $before = count($d['items']);
                        $d['items'] = array_values(array_filter($d['items'],
                            fn($x) => $x['id'] !== $id && ($x['parent'] ?? '') !== $id));
                        return ['title' => $i['title'], 'cat' => $i['cat'], 'subs' => $before - count($d['items']) - 1];
                    }
                }
                return null;
            });
            if (!$res) {
                cl_respond('', []);
            }
            Log::change('checklist', $res['subs'] ? 'Aufgabe samt ' . $res['subs'] . ' Unteraufgaben gelöscht' : 'Aufgabe gelöscht', $res['title']);
            cl_respond($res['subs'] ? tn('cl.deleted_subs', (int)$res['subs']) : t('cl.deleted'), [$res['cat']]);

        case 'catsave':
            if (!$isPost) {
                abort(400);
            }
            Perm::need('checklist', Perm::EDIT);
            $id = (string)post('id');
            $name = mb_substr((string)post('name'), 0, 100);
            if ($name === '') {
                if (is_ajax()) {
                    json_out(['ok' => false, 'error' => t('cl.err_cat_name')], 422);
                }
                redirect_back('checklist');
            }
            $catId = Store::update('checklist', function (array &$d) use ($id, $name) {
                foreach ($d['categories'] as &$c) {
                    if ($c['id'] === $id) {
                        $c['name'] = $name;
                        return $id;
                    }
                }
                $new = new_id();
                $d['categories'][] = ['id' => $new, 'name' => $name, 'sort' => count($d['categories'])];
                return $new;
            });
            Log::change('checklist', $id ? 'Kategorie umbenannt' : 'Kategorie angelegt', $name);
            cl_respond('', [$catId]);

        case 'catmove':
            if (!$isPost) {
                abort(400);
            }
            Perm::need('checklist', Perm::EDIT);
            $id = (string)post('id');
            $dir = post('dir') === 'up' ? -1 : 1;
            Store::update('checklist', function (array &$d) use ($id, $dir) {
                usort($d['categories'], fn($x, $y) => $x['sort'] <=> $y['sort']);
                foreach ($d['categories'] as $k => $c) {
                    if ($c['id'] === $id && isset($d['categories'][$k + $dir])) {
                        [$d['categories'][$k], $d['categories'][$k + $dir]] = [$d['categories'][$k + $dir], $d['categories'][$k]];
                        break;
                    }
                }
                foreach ($d['categories'] as $k => &$c) {
                    $c['sort'] = $k;
                }
            });
            cl_respond('', []);

        case 'catdelete':
            if (!$isPost) {
                abort(400);
            }
            Perm::need('checklist', Perm::EDIT);
            $id = (string)post('id');
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
            if ($name) {
                Log::change('checklist', 'Kategorie samt Aufgaben gelöscht', $name);
            }
            cl_respond($name ? t('cl.cat_deleted', ['name' => $name]) : '', [$id]);
    }

    view('checklist', cl_vars(get('show', 'all')));
}
