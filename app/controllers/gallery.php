<?php
declare(strict_types=1);

function ctrl_gallery(string $r, string $a): void
{
    // Öffentliche Login-Bilder
    if ($r === 'pubimg') {
        $g = Store::read('gallery');
        $img = find_by_id($g['images'], (string)get('id'));
        $portrait = Store::read('memorial')['portrait'] ?? '';
        if (!$img || (empty($img['login']) && $img['id'] !== $portrait)) {
            abort(404);
        }
        $v = get('s') === 'thumb' ? 'thumb' : 'medium';
        Image::send(Image::path($img, $v), Image::variantMime($img, $v));
    }

    if (!Perm::gallery()) {
        abort(403, t('gal.denied'));
    }

    if ($r === 'img') {
        gallery_serve();
    }

    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $uid = Auth::user()['id'];

    switch ($a) {
        case 'seen':
            if (!$isPost) {
                abort(400);
            }
            $img = find_by_id(Store::read('gallery')['images'], (string)post('id'));
            if ($img) {
                Log::add('img_view', $img['id'], ['c' => mb_substr($img['caption'] ?: $img['orig_name'], 0, 80)]);
            }
            json_out(['ok' => true]);

        case 'upload':
            if (!$isPost) {
                abort(400);
            }
            if (!Perm::gallery('upload')) {
                abort(403, t('gal.denied_upload'));
            }
            gallery_upload();

        case 'select':
            if (!$isPost) {
                abort(400);
            }
            if (!Perm::gallery('download')) {
                abort(403);
            }
            $id = (string)post('id');
            $mode = (string)post('mode', 'toggle'); // toggle | add | remove
            $valid = array_column(Store::read('gallery')['images'], 'id');
            $res = Store::update('selections', function (array &$s) use ($uid, $id, $mode, $valid) {
                $sel = array_values(array_intersect($s[$uid] ?? [], $valid));
                $ids = $id === '*' ? $valid : [$id];
                foreach ($ids as $i) {
                    if (!in_array($i, $valid, true)) {
                        continue;
                    }
                    $has = in_array($i, $sel, true);
                    if (($mode === 'toggle' && $has) || $mode === 'remove') {
                        $sel = array_values(array_diff($sel, [$i]));
                    } elseif (!$has) {
                        $sel[] = $i;
                    }
                }
                if ($mode === 'clear') {
                    $sel = [];
                }
                $s[$uid] = $sel;
                return ['count' => count($sel), 'selected' => in_array($id, $sel, true)];
            });
            if (is_ajax()) {
                json_out(['ok' => true] + $res);
            }
            redirect_back('gallery');

        case 'cart':
            if (!Perm::gallery('download')) {
                abort(403);
            }
            $g = Store::read('gallery');
            $sel = Store::read('selections')[$uid] ?? [];
            $images = array_values(array_filter($g['images'], fn($i) => in_array($i['id'], $sel, true)));
            view('cart', ['images' => $images, 'albums' => $g['albums']]);
            return;

        case 'move':
            if (!$isPost) {
                abort(400);
            }
            if (!Perm::gallery('download')) {
                abort(403);
            }
            $target = (string)post('album');
            $isAdmin = Auth::isAdmin();
            $sel = Store::read('selections')[$uid] ?? [];
            $res = Store::update('gallery', function (array &$g) use ($target, $sel, $uid, $isAdmin) {
                if ($target !== '' && !find_by_id($g['albums'], $target)) {
                    return null;
                }
                $moved = $skipped = 0;
                foreach ($g['images'] as &$i) {
                    if (!in_array($i['id'], $sel, true)) {
                        continue;
                    }
                    if (!$isAdmin && ($i['uploaded_by_id'] ?? '') !== $uid) {
                        $skipped++;
                        continue;
                    }
                    $i['album'] = $target;
                    $i['links'] = array_values(array_diff($i['links'] ?? [], [$target]));
                    $moved++;
                }
                $name = $target === '' ? t('gal.no_album') : find_by_id($g['albums'], $target)['name'];
                return ['moved' => $moved, 'skipped' => $skipped, 'name' => $name];
            });
            if (!$res) {
                flash(t('gal.no_target'), 'warning');
                redirect_back('gallery');
            }
            Log::change('gallery', 'Bilder verschoben', $res['moved'] . ' nach „' . $res['name'] . '“');
            flash(tn('gal.moved', (int)$res['moved'], ['name' => $res['name']])
                . ($res['skipped'] ? t('gal.moved_skipped', ['n' => $res['skipped']]) : ''),
                $res['moved'] ? 'success' : 'warning');
            redirect_back('gallery');

        case 'bulkdelete':
            if (!$isPost) {
                abort(400);
            }
            Perm::needAdmin();
            $sel = Store::read('selections')[$uid] ?? [];
            $removed = Store::update('gallery', function (array &$g) use ($sel) {
                $gone = array_values(array_filter($g['images'], fn($i) => in_array($i['id'], $sel, true)));
                $g['images'] = array_values(array_filter($g['images'], fn($i) => !in_array($i['id'], $sel, true)));
                return $gone;
            });
            foreach ($removed as $img) {
                Image::delete($img);
            }
            if ($removed) {
                $ids = array_column($removed, 'id');
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
            }
            Log::add('admin', count($removed) . ' Bilder gelöscht (Auswahl)');
            flash(tn('gal.removed', count($removed)));
            redirect_back('gallery');

        case 'zip':
            if (!Perm::gallery('download')) {
                abort(403);
            }
            gallery_zip((string)get('what'), (string)get('album'), $uid);

        case 'albumsave':
            if (!$isPost) {
                abort(400);
            }
            Perm::needAdmin();
            $id = (string)post('id');
            $name = mb_substr((string)post('name'), 0, 100);
            $cat = mb_substr((string)post('cat'), 0, 40);
            $color = in_array((string)post('color'), Stations::COLORS, true) ? (string)post('color') : '';
            if ($name !== '') {
                $parent = (string)post('parent');
                $links = $_POST['links'] ?? [];
                Store::update('gallery', function (array &$g) use ($id, $name, $cat, $color, $parent, $links) {
                    // Schleifenschutz: Eltern und Verweise dürfen nicht im eigenen Teilbaum liegen
                    $own = $id !== '' ? Albums::subtree($g['albums'], $id) : [];
                    $parent = ($parent !== '' && find_by_id($g['albums'], $parent) && !in_array($parent, $own, true)) ? $parent : '';
                    $links = Albums::cleanLinks($g['albums'], $links, array_merge($own, [$parent]));
                    foreach ($g['albums'] as &$al) {
                        if ($al['id'] === $id) {
                            $al['name'] = $name;
                            $al['cat'] = $cat;
                            $al['color'] = $color;
                            $al['parent'] = $parent;
                            $al['links'] = $links;
                            return;
                        }
                    }
                    $g['albums'][] = ['id' => new_id(), 'name' => $name, 'cat' => $cat, 'color' => $color,
                        'parent' => $parent, 'links' => $links,
                        'sort' => count($g['albums']), 'created' => now()];
                });
                Log::add('admin', 'Album gespeichert: ' . $name);
            }
            redirect_back('gallery');

        case 'albummove':
            if (!$isPost) {
                abort(400);
            }
            Perm::needAdmin();
            $id = (string)post('id');
            $dir = get('dir') === 'up' ? -1 : 1;
            $name = Store::update('gallery', function (array &$g) use ($id, $dir) {
                $me = find_by_id($g['albums'], $id);
                if (!$me) {
                    return null;
                }
                // Geschwister (gleiches Elternalbum) in aktueller Reihenfolge, dann tauschen und neu nummerieren
                $keys = [];
                foreach ($g['albums'] as $k => $al) {
                    if (($al['parent'] ?? '') === ($me['parent'] ?? '')) {
                        $keys[] = $k;
                    }
                }
                usort($keys, fn($a, $b) => [$g['albums'][$a]['sort'] ?? 0, $a] <=> [$g['albums'][$b]['sort'] ?? 0, $b]);
                $pos = array_search(array_search($id, array_column($g['albums'], 'id', null)), $keys, true);
                $to = $pos + $dir;
                if ($to >= 0 && $to < count($keys)) {
                    [$keys[$pos], $keys[$to]] = [$keys[$to], $keys[$pos]];
                }
                foreach ($keys as $n => $k) {
                    $g['albums'][$k]['sort'] = $n;
                }
                return $me['name'];
            });
            if ($name !== null) {
                Log::add('admin', 'Album verschoben (Reihenfolge): ' . $name);
            }
            redirect_back('gallery');

        case 'albumdelete':
            if (!$isPost) {
                abort(400);
            }
            Perm::needAdmin();
            $id = (string)post('id');
            $up = Store::update('gallery', function (array &$g) use ($id) {
                // Unteralben und Bilder rücken in das Elternalbum des gelöschten Albums auf
                $gone = find_by_id($g['albums'], $id);
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
                return $up;
            });
            Log::add('admin', 'Album gelöscht (Unteralben und Bilder bleiben erhalten)');
            flash(t($up === '' ? 'gal.album_deleted_top' : 'gal.album_deleted'));
            redirect('gallery');

        case 'edit':
            if (!$isPost) {
                abort(400);
            }
            $id = (string)post('id');
            $img = find_by_id(Store::read('gallery')['images'], $id);
            if (!$img) {
                abort(404);
            }
            if (!Auth::isAdmin() && ($img['uploaded_by_id'] ?? '') !== $uid) {
                abort(403, t('gal.denied_edit'));
            }
            Store::update('gallery', function (array &$g) use ($id) {
                $albumIds = array_column($g['albums'], 'id');
                foreach ($g['images'] as &$i) {
                    if ($i['id'] === $id) {
                        $i['caption'] = mb_substr((string)post('caption'), 0, 300);
                        $alb = (string)post('album');
                        $i['album'] = in_array($alb, $albumIds, true) ? $alb : '';
                        $i['links'] = Albums::cleanLinks($g['albums'], $_POST['links'] ?? [], [$i['album']]);
                        if (Auth::isAdmin()) {
                            $i['login'] = !empty($_POST['login']);
                        }
                    }
                }
            });
            Log::change('gallery', 'Bild bearbeitet', $id);
            flash(t('gal.pic_saved'));
            redirect_back('gallery');

        case 'delete':
            if (!$isPost) {
                abort(400);
            }
            Perm::needAdmin();
            $id = (string)post('id');
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
            if ($img) {
                Image::delete($img);
                Store::update('memorial', function (array &$m) use ($id) {
                    if (($m['portrait'] ?? '') === $id) {
                        $m['portrait'] = '';
                    }
                });
                Log::add('admin', 'Bild gelöscht: ' . ($img['caption'] ?: $img['orig_name']));
                flash(t('gal.pic_deleted'));
            }
            redirect_back('gallery');
    }

    // Übersicht
    $g = Store::read('gallery');
    usort($g['albums'], fn($x, $y) => $x['sort'] <=> $y['sort']);
    $album = (string)get('album', '');
    if ($album !== '_none' && $album !== '' && !find_by_id($g['albums'], $album)) {
        $album = '';
    }
    // Raster: Bilder direkt in diesem Album; Diashow/Leuchtkasten: zusätzlich alle Unteralben (rekursiv)
    $images = $slides = $g['images'];
    if ($album === '_none') {
        $images = $slides = array_values(array_filter($g['images'], fn($i) => $i['album'] === ''));
    } elseif ($album !== '') {
        $images = Albums::images($g['images'], [$album]);
        $slides = array_merge($images, array_values(array_filter(
            Albums::images($g['images'], Albums::subtree($g['albums'], $album)),
            fn($i) => !in_array($i['id'], array_column($images, 'id'), true))));
    }
    $counts = ['_none' => count(array_filter($g['images'], fn($i) => $i['album'] === ''))];
    foreach ($g['albums'] as $al) {
        $counts[$al['id']] = count(Albums::images($g['images'], Albums::subtree($g['albums'], $al['id'])));
    }
    $sel = Store::read('selections')[$uid] ?? [];
    view('gallery', [
        'albums'    => $g['albums'],
        'flat'      => Albums::walk($g['albums']),
        'trail'     => $album !== '' && $album !== '_none' ? Albums::trail($g['albums'], $album) : [],
        'subs'      => $album === '' ? Albums::roots($g['albums']) : ($album === '_none' ? [] : Albums::children($g['albums'], $album)),
        'subtree'   => $album !== '' && $album !== '_none' ? Albums::subtree($g['albums'], $album) : [],
        'images'    => $images,
        'slides'    => $slides,
        'album'     => $album,
        'counts'    => $counts,
        'total'     => count($g['images']),
        'selected'  => array_values(array_intersect($sel, array_column($g['images'], 'id'))),
        'canUpload' => Perm::gallery('upload'),
        'canDl'     => Perm::gallery('download'),
        'uid'       => $uid,
        'portrait'  => Store::read('memorial')['portrait'] ?? '',
        'play'      => get('play') === '1' && $album !== '',
    ]);
}

function gallery_serve(): never
{
    $img = find_by_id(Store::read('gallery')['images'], (string)get('id'));
    if (!$img) {
        abort(404);
    }
    $v = (string)get('s', 'thumb');
    if (!in_array($v, ['thumb', 'medium', 'orig'], true)) {
        abort(400);
    }
    if ($v === 'orig') {
        if (!Perm::gallery('download')) {
            abort(403);
        }
        $name = slug(pathinfo($img['caption'] ?: $img['orig_name'], PATHINFO_FILENAME)) . '.' . $img['ext'];
        Log::add('img_download', $img['id'], ['c' => mb_substr($img['caption'] ?: $img['orig_name'], 0, 80)]);
        Image::send(Image::path($img, 'orig'), $img['mime'], $name);
    }
    Image::send(Image::path($img, $v), Image::variantMime($img, $v));
}

function gallery_upload(): never
{
    $files = $_FILES['files'] ?? null;
    $album = (string)post('album');
    $g = Store::read('gallery');
    if ($album !== '' && !find_by_id($g['albums'], $album)) {
        $album = '';
    }
    $max = (int)cfg('upload_max_mb', 40) * 1024 * 1024;
    $u = Auth::user();
    $done = [];
    $errors = [];

    if (!$files || !is_array($files['name'])) {
        json_out(['ok' => false, 'error' => t('gal.err_nofiles')], 400);
    }
    foreach ($files['name'] as $k => $name) {
        $err = $files['error'][$k];
        if ($err !== UPLOAD_ERR_OK) {
            $errors[] = $name . ': ' . match ($err) {
                UPLOAD_ERR_INI_SIZE   => t('gal.err_ini', ['limit' => ini_get('upload_max_filesize')]),
                UPLOAD_ERR_FORM_SIZE  => t('gal.err_form'),
                UPLOAD_ERR_PARTIAL    => t('gal.err_partial'),
                UPLOAD_ERR_NO_FILE    => t('gal.err_nofile'),
                UPLOAD_ERR_NO_TMP_DIR => t('gal.err_tmp'),
                UPLOAD_ERR_CANT_WRITE => t('gal.err_write'),
                UPLOAD_ERR_EXTENSION  => t('gal.err_ext'),
                default               => t('gal.err_other', ['code' => $err]),
            };
            continue;
        }
        if ($files['size'][$k] > $max) {
            $errors[] = $name . ': ' . t('gal.err_toobig', ['mb' => cfg('upload_max_mb', 40)]);
            continue;
        }
        try {
            $rec = Image::import($files['tmp_name'][$k], $name);
            $rec += [
                'album'          => $album,
                'caption'        => '',
                'login'          => false,
                'uploaded_by'    => $u['name'],
                'uploaded_by_id' => $u['id'],
                'uploaded_at'    => now(),
            ];
            Store::update('gallery', function (array &$gal) use ($rec) {
                $gal['images'][] = $rec;
            });
            Log::add('upload', $rec['id'], ['c' => $rec['orig_name']]);
            $done[] = $rec['id'];
        } catch (Throwable $e) {
            $errors[] = $name . ': ' . $e->getMessage();
        }
    }
    json_out(['ok' => !$errors || $done, 'uploaded' => count($done), 'errors' => $errors]);
}

function gallery_zip(string $what, string $albumId, string $uid): never
{
    @set_time_limit(600);
    $g = Store::read('gallery');
    $albums = array_column($g['albums'], 'name', 'id');
    // Ordner je Bild: erstes Album im Baum (Tiefensuche), in dem es erscheint; 'album' rekursiv, relativ zum Album
    $folders = [];
    $images = match ($what) {
        'selection' => array_values(array_filter($g['images'],
            fn($i) => in_array($i['id'], Store::read('selections')[$uid] ?? [], true))),
        'album' => $albumId === '_none'
            ? array_values(array_filter($g['images'], fn($i) => $i['album'] === ''))
            : Albums::images($g['images'], Albums::subtree($g['albums'], $albumId)),
        'all' => $g['images'],
        default => abort(400),
    };
    if (($what === 'album' && $albumId !== '_none') || $what === 'all') {
        $nodes = Albums::walk($g['albums'], $what === 'album' ? $albumId : '');
        foreach ([true, false] as $homeOnly) { // erst Heimatalbum, Verweise nur als Rückfall
            foreach ($nodes as $node) {
                $parts = $what === 'album' ? array_slice($node['path'], 1) : $node['path'];
                $dir = $parts ? implode('/', array_map('slug', $parts)) . '/' : '';
                foreach ($images as $i) {
                    $in = $homeOnly ? [$i['album']] : Albums::ofImage($i);
                    if (in_array($node['album']['id'], $in, true)) {
                        $folders[$i['id']] ??= $dir;
                    }
                }
            }
        }
    }
    if (!$images) {
        flash(t('gal.none_selected'), 'warning');
        redirect('gallery');
    }

    $tmpDir = DATA_DIR . '/tmp';
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0750, true);
    }
    $tmp = $tmpDir . '/' . bin2hex(random_bytes(8)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        abort(500);
    }
    $used = [];
    $n = 0;
    foreach ($images as $img) {
        $path = Image::path($img, 'orig');
        if (!is_file($path)) {
            continue;
        }
        $folder = $folders[$img['id']] ?? ($what === 'all' ? 'Ohne-Album/' : '');
        $base = sprintf('%03d_', ++$n) . slug(pathinfo($img['caption'] ?: $img['orig_name'], PATHINFO_FILENAME));
        $entry = $folder . mb_substr($base, 0, 80) . '.' . $img['ext'];
        while (isset($used[$entry])) {
            $entry = $folder . $base . '-' . $n . '.' . $img['ext'];
        }
        $used[$entry] = true;
        $zip->addFile($path, $entry);
        $zip->setCompressionName($entry, ZipArchive::CM_STORE);
    }
    $zip->close();

    $label = match ($what) {
        'selection' => t('gal.zip_selection'),
        'album' => slug($albums[$albumId] ?? t('gal.zip_no_album')),
        default => t('gal.zip_all_pics'),
    };
    Log::add('zip_download', $label . ' (' . count($used) . ' Bilder)');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . t('gal.zip_file') . '_' . $label . '_' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    while (ob_get_level()) {
        ob_end_clean();
    }
    readfile($tmp);
    @unlink($tmp);
    exit;
}
