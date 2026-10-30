<?php
declare(strict_types=1);

function ctrl_admin(string $r, string $a): void
{
    Perm::needAdmin();
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    if (in_array($a, ['usersave', 'userdelete', 'groupsave', 'groupdelete', 'sharesave', 'sharerevoke',
        'sharedelete', 'snapshot', 'requestdone', 'impersonate', 'sendersave', 'pluginset', 'pluginimport',
        'plugindelete', 'transferexport', 'transferimport', 'transferapply', 'transferdrop'], true) && !$isPost) {
        abort(400);
    }
    $me = Auth::user();

    require_once APP_DIR . '/controllers/admin_market.php';
    if (admin_market($a, $isPost)) {
        return;
    }

    switch ($a) {
        /* ---------------------------------------------------------- Benutzer */
        case 'users':
            view('admin_users', ['users' => Store::read('users'), 'groups' => Store::read('groups'), 'me' => $me]);
            return;

        case 'requests':
            $list = array_map(function ($q) {
                $u = Reset::match($q['who']);
                return $q + ['user' => $u, 'has_contact' => $u && !empty($u['contact_id'])];
            }, Reset::requests());
            view('admin_requests', ['list' => $list]);
            return;

        case 'requestdone':
            Reset::resolve((string)post('id'));
            Log::add('admin', 'Passwortanfrage erledigt');
            redirect('admin', ['a' => 'requests']);

        case 'usersave':
            $id = (string)post('id');
            $username = mb_strtolower(mb_substr((string)post('username'), 0, 60));
            $pass = (string)($_POST['password'] ?? '');
            $role = post('role') === 'admin' ? 'admin' : 'user';
            $groupId = (string)post('group_id');
            $active = !empty($_POST['active']);
            if (!preg_match('/^[a-z0-9._@-]{3,60}$/', $username)) {
                flash(t('users.err_username'), 'warning');
                redirect('admin', ['a' => 'users']);
            }
            if ($pass !== '' && mb_strlen($pass) < 10) {
                flash(t('users.err_pw_short'), 'warning');
                redirect('admin', ['a' => 'users']);
            }
            if ($id === '' && $pass === '') {
                flash(t('users.err_pw_needed'), 'warning');
                redirect('admin', ['a' => 'users']);
            }
            if ($id === $me['id'] && ($role !== 'admin' || !$active)) {
                flash(t('users.err_self_admin'), 'warning');
                redirect('admin', ['a' => 'users']);
            }
            $err = Store::update('users', function (array &$users) use ($id, $username, $pass, $role, $groupId, $active) {
                foreach ($users as $u) {
                    if ($u['username'] === $username && $u['id'] !== $id) {
                        return t('users.err_taken');
                    }
                }
                $data = ['username' => $username, 'name' => mb_substr((string)post('name'), 0, 100),
                         'role' => $role, 'group_id' => $groupId, 'active' => $active];
                if ($pass !== '') {
                    $data['pass_hash'] = password_hash($pass, PASSWORD_DEFAULT);
                    $data['pw_changed'] = time();
                }
                foreach ($users as &$u) {
                    if ($u['id'] === $id) {
                        $u = array_merge($u, $data);
                        return null;
                    }
                }
                $users[] = $data + ['id' => new_id(), 'created' => now(), 'last_login' => ''];
                return null;
            });
            if ($err) {
                flash($err, 'warning');
            } else {
                if ($id === $me['id'] && $pass !== '') {
                    $_SESSION['login_t'] = time(); // eigene Sitzung bleibt gültig
                }
                Log::add('admin', ($id ? 'Benutzer bearbeitet: ' : 'Benutzer angelegt: ') . $username);
                flash(t('users.saved', ['name' => $username]));
            }
            redirect('admin', ['a' => 'users']);

        case 'impersonate':
            $target = Auth::impersonate((string)post('id'));
            if (!$target) {
                flash(t('users.imp_failed'), 'warning');
                redirect('admin', ['a' => 'users']);
            }
            $to = Perm::firstRoute();
            if ($to === null) { // Ziel hat keinen Zugriff auf irgendeinen Bereich
                Auth::stopImpersonation();
                flash(t('users.imp_noaccess'), 'warning');
                redirect('admin', ['a' => 'users']);
            }
            flash(t('users.impersonated', ['name' => $target['name'] ?: $target['username']]), 'info');
            redirect($to);

        case 'userdelete':
            $id = (string)post('id');
            if ($id === $me['id']) {
                flash(t('users.err_self_delete'), 'warning');
                redirect('admin', ['a' => 'users']);
            }
            $name = Store::update('users', function (array &$users) use ($id) {
                foreach ($users as $k => $u) {
                    if ($u['id'] === $id) {
                        unset($users[$k]);
                        $users = array_values($users);
                        return $u['username'];
                    }
                }
                return null;
            });
            if ($name) {
                Store::update('selections', function (array &$s) use ($id) {
                    unset($s[$id]);
                });
                Reset::dropUser($id);
                Log::add('admin', 'Benutzer gelöscht: ' . $name);
                flash(t('users.deleted', ['name' => $name]));
            }
            redirect('admin', ['a' => 'users']);

        /* ---------------------------------------------------------- Gruppen */
        case 'groups':
            $users = Store::read('users');
            $shares = Store::read('shares');
            $usage = [];
            foreach (array_merge($users, $shares) as $x) {
                $usage[$x['group_id'] ?? ''] = ($usage[$x['group_id'] ?? ''] ?? 0) + 1;
            }
            view('admin_groups', ['groups' => Store::read('groups'), 'usage' => $usage]);
            return;

        case 'groupsave':
            $id = (string)post('id');
            $name = mb_substr((string)post('name'), 0, 60);
            if ($name === '') {
                flash(t('groups.need_name'), 'warning');
                redirect('admin', ['a' => 'groups']);
            }
            $perms = [];
            foreach (Perm::MODULES as $m) {
                $perms[$m] = max(0, min(3, (int)($_POST['perm'][$m] ?? 0)));
            }
            $fields = [];
            foreach (Perm::FIELDS as $f) {
                $fields[$f] = !empty($_POST['field'][$f]);
            }
            $gallery = [
                'view'     => !empty($_POST['gallery']['view']),
                'upload'   => !empty($_POST['gallery']['upload']),
                'download' => !empty($_POST['gallery']['download']),
            ];
            $data = compact('name', 'perms', 'fields', 'gallery');
            Store::update('groups', function (array &$groups) use ($id, $data) {
                foreach ($groups as &$g) {
                    if ($g['id'] === $id) {
                        $g = array_merge($g, $data);
                        return;
                    }
                }
                $groups[] = $data + ['id' => new_id()];
            });
            Log::add('admin', 'Gruppe gespeichert: ' . $name);
            flash(t('groups.saved', ['name' => $name]));
            redirect('admin', ['a' => 'groups']);

        case 'groupdelete':
            $id = (string)post('id');
            foreach (array_merge(Store::read('users'), Store::read('shares')) as $x) {
                if (($x['group_id'] ?? '') === $id) {
                    flash(t('groups.in_use'), 'warning');
                    redirect('admin', ['a' => 'groups']);
                }
            }
            Store::update('groups', function (array &$groups) use ($id) {
                $groups = array_values(array_filter($groups, fn($g) => $g['id'] !== $id));
            });
            Log::add('admin', 'Gruppe gelöscht');
            flash(t('groups.deleted'));
            redirect('admin', ['a' => 'groups']);

        /* ---------------------------------------------------------- Freigabelinks */
        case 'shares':
            view('admin_shares', ['shares' => Store::read('shares'), 'groups' => Store::read('groups'),
                                  'base' => share_base_url()]);
            return;

        case 'sharesave':
            $label = mb_substr((string)post('label'), 0, 80);
            $groupId = (string)post('group_id');
            $expires = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)post('expires')) ? (string)post('expires') : '';
            if ($label === '' || !find_by_id(Store::read('groups'), $groupId)) {
                flash(t('shares.need_input'), 'warning');
                redirect('admin', ['a' => 'shares']);
            }
            Store::update('shares', function (array &$shares) use ($label, $groupId, $expires) {
                $shares[] = ['id' => new_id(), 'token' => bin2hex(random_bytes(24)), 'label' => $label,
                             'group_id' => $groupId, 'expires' => $expires, 'revoked' => false,
                             'created' => now(), 'uses' => 0, 'last_used' => ''];
            });
            Log::add('admin', 'Freigabelink erstellt: ' . $label);
            flash(t('shares.created', ['label' => $label]));
            redirect('admin', ['a' => 'shares']);

        case 'sharerevoke':
            $id = (string)post('id');
            Store::update('shares', function (array &$shares) use ($id) {
                foreach ($shares as &$s) {
                    if ($s['id'] === $id) {
                        $s['revoked'] = empty($s['revoked']);
                    }
                }
            });
            Log::add('admin', 'Freigabelink gesperrt/entsperrt');
            redirect('admin', ['a' => 'shares']);

        case 'sharedelete':
            $id = (string)post('id');
            Store::update('shares', function (array &$shares) use ($id) {
                $shares = array_values(array_filter($shares, fn($s) => $s['id'] !== $id));
            });
            Log::add('admin', 'Freigabelink gelöscht');
            flash(t('shares.deleted'));
            redirect('admin', ['a' => 'shares']);

        /* ---------------------------------------------------------- Protokoll */
        case 'log':
            $days = in_array((int)get('days', 30), [1, 7, 30, 90, 365], true) ? (int)get('days', 30) : 30;
            $rows = array_reverse(Log::since(strtotime('today') - ($days - 1) * 86400));
            $act = (string)get('act');
            $usr = (string)get('usr');
            $q = mb_strtolower((string)get('q'));
            $users = array_values(array_unique(array_filter(array_column($rows, 'u'))));
            sort($users);
            $rows = array_values(array_filter($rows, function ($r) use ($act, $usr, $q) {
                return ($act === '' || $r['a'] === $act)
                    && ($usr === '' || $r['u'] === $usr)
                    && ($q === '' || str_contains(mb_strtolower($r['o'] . ' ' . json_encode($r['m'] ?? [], JSON_UNESCAPED_UNICODE)), $q));
            }));
            $fmt = (string)get('fmt');
            if ($fmt === 'csv' || $fmt === 'xlsx') {
                $head = [t('logv.col_time'), t('logv.col_action'), t('logv.col_user'), t('logv.col_kind'), t('logv.col_object'), 'Details', t('logv.col_ip')];
                $actions = Log::actions();
                $out = array_map(fn($r) => [fmt_ts((int)$r['t'], 'dts'), $actions[$r['a']] ?? $r['a'], $r['u'] . (!empty($r['v']) ? ' (via ' . $r['v'] . ')' : ''),
                    $r['k'] === 'share' ? t('logv.kind_link') : ($r['k'] ? t('logv.kind_user') : ''), $r['o'], log_details($r), $r['ip']], $rows);
                if ($fmt === 'xlsx') {
                    Xlsx::send(t('logv.file') . '_' . date('Y-m-d') . '.xlsx', t('logv.file'), $head, $out);
                }
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . t('logv.file') . '_' . date('Y-m-d') . '.csv"');
                $h = fopen('php://output', 'w');
                fwrite($h, "\xEF\xBB\xBF");
                fputcsv($h, $head, ';', '"', '');
                foreach ($out as $row) {
                    fputcsv($h, csv_safe($row), ';', '"', '');
                }
                exit;
            }
            $total = count($rows);
            $rows = array_slice($rows, 0, 500);
            view('admin_log', compact('rows', 'total', 'days', 'act', 'usr', 'q', 'users'));
            return;

        /* ---------------------------------------------------------- Absender */
        case 'sender':
            view('admin_sender', ['sender' => sender_lines()]);
            return;

        case 'sendersave':
            $lines = array_slice(array_values(array_filter(
                array_map(fn($l) => mb_substr(trim($l), 0, 60), preg_split('/\R/u', (string)post('sender')) ?: []),
                fn($l) => $l !== ''
            )), 0, 5);
            Store::update('settings', function (array &$s) use ($lines) {
                $s['sender'] = $lines;
            });
            Log::add('admin', 'Absenderadresse ' . ($lines ? 'gespeichert' : 'entfernt'));
            flash(t($lines ? 'sender.saved' : 'sender.removed'));
            redirect('admin', ['a' => 'sender']);

        /* ---------------------------------------------------------- Plugins (Liste, Marktplatz: admin_market.php) */
        case 'pluginset':
            $slug = (string)post('slug');
            $on = post('state') === 'on';
            $err = Plugins::setActive($slug, $on);
            if ($err !== null) {
                flash($err, 'warning');
            } else {
                $name = Plugins::all()[$slug]['m']['name'] ?? $slug;
                Log::add('admin', ($on ? 'Plugin aktiviert: ' : 'Plugin deaktiviert: ') . $name);
                flash(t($on ? 'plugins.activated' : 'plugins.deactivated', ['name' => $name]));
            }
            redirect('admin', ['a' => 'plugins']);

        case 'pluginimport':
            $up = $_FILES['plugin_zip'] ?? null;
            if (!is_array($up) || ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$up['tmp_name'])) {
                flash(t('plugins.err_upload'), 'warning');
                redirect('admin', ['a' => 'plugins']);
            }
            [$slug, $err] = Plugins::install((string)$up['tmp_name']);
            if ($slug === null) {
                flash($err, 'warning');
            } else {
                $m = Plugins::all()[$slug]['m'];
                Log::add('admin', 'Plugin importiert: ' . $m['name'] . ' ' . $m['version']);
                flash(t('plugins.imported', ['name' => $m['name']]));
            }
            redirect('admin', ['a' => 'plugins']);

        case 'pluginexport':
            $slug = (string)get('p');
            [$tmp, $fileName] = Plugins::export($slug);
            if ($tmp === null) {
                abort(404);
            }
            Log::add('export', 'Plugin ' . $slug);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . slug($fileName) . '"');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: no-store');
            while (ob_get_level()) {
                ob_end_clean();
            }
            readfile($tmp);
            @unlink($tmp);
            exit;

        case 'plugindelete':
            $slug = (string)post('slug');
            $withData = !empty($_POST['delete_data']);
            $name = Plugins::all()[$slug]['m']['name'] ?? $slug;
            $err = Plugins::delete($slug, $withData);
            if ($err !== null) {
                flash($err, 'warning');
            } else {
                Log::add('admin', 'Plugin gelöscht: ' . $name . ($withData ? ' (mit Daten)' : ''));
                flash(t($withData ? 'plugins.deleted_data' : 'plugins.deleted', ['name' => $name]));
            }
            redirect('admin', ['a' => 'plugins']);

        /* ---------------------------------------------------------- Backup */
        case 'backup':
            $snapshots = [];
            foreach (glob(DATA_DIR . '/backups/????-??-??', GLOB_ONLYDIR) ?: [] as $d) {
                $snapshots[] = basename($d);
            }
            rsort($snapshots);
            $size = 0;
            foreach (glob(DATA_DIR . '/uploads/orig/*') ?: [] as $f) {
                $size += filesize($f);
            }
            view('admin_backup', ['snapshots' => $snapshots, 'imgSize' => $size,
                                  'imgCount' => count(Store::read('gallery')['images'])]);
            return;

        case 'snapshot':
            Store::dailyBackup(true);
            Log::add('admin', 'Snapshot erstellt');
            flash(t('backup.snapshot_done'));
            redirect('admin', ['a' => 'backup']);

        case 'download':
            backup_download(get('full') === '1');

        /* ---------------------------------------------------------- Export und Import */
        case 'transferexport':
            $keys = array_map('strval', (array)($_POST['parts'] ?? []));
            $keys = array_values(array_intersect(array_keys(Transfer::parts()), $keys));
            if (!$keys) {
                flash(t('transfer.err_nopart'), 'warning');
                redirect('admin', ['a' => 'backup']);
            }
            $tmp = Transfer::export($keys);
            Log::add('admin', 'Export erstellt: ' . implode(', ', $keys));
            admin_send_zip($tmp, t('transfer.file') . '_' . date('Y-m-d_His') . '.zip');

        case 'transferimport':
            $f = $_FILES['package'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                flash(t('transfer.err_upload'), 'warning');
                redirect('admin', ['a' => 'backup']);
            }
            $res = Transfer::stage($f['tmp_name']);
            if (isset($res['error'])) {
                flash(t($res['error'], ['name' => $res['name'] ?? '', 'v' => $res['v'] ?? '']), 'warning');
                redirect('admin', ['a' => 'backup']);
            }
            redirect('admin', ['a' => 'transferpreview', 'token' => $res['token']]);

        case 'transferpreview':
            $info = Transfer::inspect((string)get('token'));
            if (!$info) {
                flash(t('transfer.err_token'), 'warning');
                redirect('admin', ['a' => 'backup']);
            }
            view('admin_import', ['info' => $info]);
            return;

        case 'transferapply':
            $res = Transfer::apply((string)post('token'), array_map('strval', (array)($_POST['parts'] ?? [])));
            if (isset($res['error'])) {
                flash(t($res['error']), 'warning');
                redirect('admin', ['a' => $res['error'] === 'transfer.err_token' ? 'backup' : 'transferpreview', 'token' => (string)post('token')]);
            }
            Log::add('admin', 'Import ausgeführt: ' . implode(', ', $res['parts']));
            if ($res['users']) { // Konten wurden ersetzt: neu anmelden
                Auth::logout();
                redirect('login');
            }
            flash(t('transfer.done', ['parts' => implode(', ', array_map(fn($k) => t('transfer.part.' . $k), $res['parts'])), 'dir' => $res['snapshot']]));
            redirect('admin', ['a' => 'backup']);

        case 'transferdrop':
            Transfer::drop((string)post('token'));
            redirect('admin', ['a' => 'backup']);
    }

    // Dashboard
    $days = in_array((int)get('days', 30), [7, 30, 90, 365], true) ? (int)get('days', 30) : 30;
    $stats = Log::stats($days);
    $images = array_column(Store::read('gallery')['images'], null, 'id');
    view('admin_dashboard', [
        'stats'  => $stats,
        'days'   => $days,
        'images' => $images,
        'users'  => Store::read('users'),
    ]);
}

function log_details(array $r): string
{
    $m = $r['m'] ?? [];
    if (isset($m['what'])) {
        return ($m['mod'] ?? '') . ': ' . $m['what'];
    }
    return $m['c'] ?? '';
}

function share_base_url(): string
{
    return site_base_url() . 'index.php?r=s&t=';
}

function backup_download(bool $full): never
{
    @set_time_limit(900);
    $tmpDir = DATA_DIR . '/tmp';
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0750, true);
    }
    Store::dailyBackup(true);
    $tmp = $tmpDir . '/' . bin2hex(random_bytes(8)) . '.zip';
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach (glob(DATA_DIR . '/*.json') ?: [] as $f) {
        if (basename($f) !== 'resets.json') { // flüchtig (Reset-Tokens, Anfragen): nicht ins Backup
            $zip->addFile($f, 'data/' . basename($f));
        }
    }
    foreach (glob(DATA_DIR . '/logs/*.jsonl') ?: [] as $f) {
        $zip->addFile($f, 'data/logs/' . basename($f));
    }
    if ($full) {
        foreach (['orig', 'medium', 'thumb'] as $v) {
            foreach (glob(DATA_DIR . '/uploads/' . $v . '/*') ?: [] as $f) {
                $e = 'data/uploads/' . $v . '/' . basename($f);
                $zip->addFile($f, $e);
                $zip->setCompressionName($e, ZipArchive::CM_STORE);
            }
        }
    }
    $zip->close();
    Log::add('admin', $full ? 'Komplett-Backup heruntergeladen' : 'Daten-Backup heruntergeladen');
    admin_send_zip($tmp, t($full ? 'backup.file_full' : 'backup.file_data') . '_' . date('Y-m-d_His') . '.zip');
}

/** Sendet die temporäre ZIP-Datei als Download und löscht sie danach. */
function admin_send_zip(string $tmp, string $filename): never
{
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    while (ob_get_level()) {
        ob_end_clean();
    }
    readfile($tmp);
    @unlink($tmp);
    exit;
}
