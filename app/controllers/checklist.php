<?php
declare(strict_types=1);

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
                                'at' => fmt_date($i['done_at'], true)];
                    }
                }
                return null;
            });
            if (!$state) {
                abort(404);
            }
            Log::change('checklist', $state['done'] ? 'erledigt' : 'wieder offen', $state['title']);
            if (is_ajax()) {
                json_out(['ok' => true] + $state);
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
            $ok = Store::update('checklist', function (array &$d) use ($id, $data, $parent) {
                if ($id !== '') {
                    foreach ($d['items'] as &$i) {
                        if ($i['id'] === $id) {
                            if (!empty($i['parent'])) {
                                // Unteraufgabe: Kategorie bleibt die der Hauptaufgabe
                                unset($data['cat']);
                            }
                            $i = array_merge($i, $data);
                            if (empty($i['parent']) && isset($data['cat'])) {
                                foreach ($d['items'] as &$sub) {
                                    if (($sub['parent'] ?? '') === $id) {
                                        $sub['cat'] = $data['cat'];
                                    }
                                }
                            }
                            return true;
                        }
                    }
                    return false;
                }
                if ($parent !== '') {
                    foreach ($d['items'] as $p) {
                        if ($p['id'] === $parent && empty($p['parent'])) {
                            $data['cat'] = $p['cat'];
                            $d['items'][] = $data + ['id' => new_id(), 'parent' => $parent, 'done' => false, 'done_by' => '', 'done_at' => '', 'created' => now()];
                            return true;
                        }
                    }
                    return false;
                }
                $d['items'][] = $data + ['id' => new_id(), 'parent' => '', 'done' => false, 'done_by' => '', 'done_at' => '', 'created' => now()];
                return true;
            });
            if (!$ok) {
                flash(t('cl.err_notfound'), 'warning');
                redirect_back('checklist');
            }
            Log::change('checklist', $id ? 'Aufgabe bearbeitet' : 'Aufgabe angelegt', $title);
            flash(t($id ? 'cl.saved' : 'cl.created'));
            redirect_back('checklist');

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
                        return ['title' => $i['title'], 'subs' => $before - count($d['items']) - 1];
                    }
                }
                return null;
            });
            if ($res) {
                Log::change('checklist', $res['subs'] ? 'Aufgabe samt ' . $res['subs'] . ' Unteraufgaben gelöscht' : 'Aufgabe gelöscht', $res['title']);
                flash($res['subs'] ? tn('cl.deleted_subs', (int)$res['subs']) : t('cl.deleted'));
            }
            redirect_back('checklist');

        case 'catsave':
            if (!$isPost) {
                abort(400);
            }
            Perm::need('checklist', Perm::EDIT);
            $id = (string)post('id');
            $name = mb_substr((string)post('name'), 0, 100);
            if ($name === '') {
                redirect_back('checklist');
            }
            Store::update('checklist', function (array &$d) use ($id, $name) {
                foreach ($d['categories'] as &$c) {
                    if ($c['id'] === $id) {
                        $c['name'] = $name;
                        return;
                    }
                }
                $d['categories'][] = ['id' => new_id(), 'name' => $name, 'sort' => count($d['categories'])];
            });
            Log::change('checklist', $id ? 'Kategorie umbenannt' : 'Kategorie angelegt', $name);
            redirect_back('checklist');

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
            redirect_back('checklist');

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
                flash(t('cl.cat_deleted', ['name' => $name]));
            }
            redirect_back('checklist');
    }

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
    $show = get('show', 'all');
    view('checklist', [
        'categories' => $d['categories'],
        'byCat'      => $byCat,
        'subs'       => $subs,
        'show'       => $show,
        'canCheck'   => Perm::can('checklist', Perm::CHECK),
        'canEdit'    => Perm::can('checklist', Perm::EDIT),
    ]);
}
