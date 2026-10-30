<?php
declare(strict_types=1);

/**
 * Plugin-Marktplatz in der Verwaltung (Konzept: docs/plugin-marketplace.md). Wird von ctrl_admin() aufgerufen, das die
 * Admin-Prüfung schon erledigt hat; CSRF prüft dispatch() für jedes POST.
 *
 * GET:  plugins (Installiert), pluginsearch, plugindetail, pluginconfirm
 * POST: pluginscheck, pluginupdate, pluginsupdateall, pluginprepare, pluginapply, plugincancel, pluginrollback, pluginmarkettoken, pluginselftest
 *
 * @return bool true, wenn die Aktion hier behandelt wurde
 */
function admin_market(string $a, bool $isPost): bool
{
    $postOnly = ['pluginscheck', 'pluginupdate', 'pluginsupdateall', 'pluginprepare', 'pluginapply', 'plugincancel', 'pluginrollback', 'pluginmarkettoken', 'pluginselftest'];
    if (in_array($a, $postOnly, true) && !$isPost) {
        abort(400);
    }
    switch ($a) {
        case 'plugins':
            $updates = PluginMarket::updates();
            $rows = [];
            foreach (Plugins::all() as $slug => $p) {
                $active = $p['ok'] && Plugins::isActive($slug);
                $src = Plugins::source($slug);
                $rows[$slug] = $p + [
                    'active'      => $active,
                    'loaded'      => Plugins::isLoaded($slug),
                    'problem'     => $active ? Plugins::problem($slug) : '',
                    'dependents'  => Plugins::dependents($slug),
                    'data'        => Plugins::dataFiles($slug),
                    'source'      => $src,
                    'update'      => $updates[$slug] ?? null,
                    'can_rollback' => $src !== null && ($src['previous'] ?? '') !== '' && ($src['previous_sha'] ?? '') !== '',
                ];
            }
            view('admin_plugins', [
                'plugins' => $rows, 'maxMb' => Plugins::MAX_ZIP / 1048576, 'updates' => $updates,
                'checked' => (string)(Store::read('market')['checked'] ?? ''), 'rate' => PluginMarket::rate(),
                'exposure' => PluginExposure::last(),
            ]);
            return true;

        case 'pluginsearch':
            $q = (string)get('q');
            $results = [];
            $err = '';
            try {
                $results = PluginMarket::search($q);
            } catch (RuntimeException $e) {
                $err = $e->getMessage();
            }
            view('admin_plugins_search', [
                'q' => $q, 'results' => $results, 'err' => $err, 'rate' => PluginMarket::rate(),
                'tokenSource' => PluginMarket::tokenSource(),
            ]);
            return true;

        case 'plugindetail':
            $repo = (string)get('repo');
            if (!preg_match(Plugins::REPO_RE, $repo)) {
                abort(404);
            }
            $releases = [];
            $err = '';
            try {
                $releases = PluginMarket::releases($repo);
            } catch (RuntimeException $e) {
                $err = $e->getMessage();
            }
            $installed = '';
            foreach (Plugins::sources() as $slug => $s) {
                if (strcasecmp((string)($s['repo'] ?? ''), $repo) === 0) {
                    $installed = (string)$slug;
                }
            }
            view('admin_plugin_detail', [
                'repo' => $repo, 'releases' => $releases, 'err' => $err, 'installed' => $installed,
                'installedVersion' => $installed !== '' ? (string)Plugins::source($installed)['version'] : '',
            ]);
            return true;

        case 'pluginconfirm':
            $pend = market_pending((string)get('id'));
            if ($pend === null) {
                flash(t('market.err_pending'), 'warning');
                redirect('admin', ['a' => 'plugins']);
            }
            [$m, $err] = Plugins::peek($pend['zip']);
            if ($m === null) {
                market_pending_drop((string)get('id'));
                flash($err, 'warning');
                redirect('admin', ['a' => 'plugins']);
            }
            $src = Plugins::source($m['slug']);
            view('admin_plugin_confirm', [
                'id' => (string)get('id'), 'pend' => $pend, 'm' => $m, 'src' => $src,
                'audit' => PluginMarket::audit($pend['repo'], 'v' . $pend['version'], $m),
                'newPerms' => $src ? array_values(array_diff($m['permissions'], (array)($src['manifest']['permissions'] ?? []))) : [],
                'data' => array_keys($m['stores']),
            ]);
            return true;

        /* ---------------------------------------------------------- Aktionen */
        case 'pluginscheck':
            if (!Plugins::sources()) {
                flash(t('market.no_market_plugins'), 'info');
                redirect('admin', ['a' => 'plugins']);
            }
            @set_time_limit(120);
            $res = PluginMarket::checkUpdates();
            $n = count($res['updates']);
            Log::add('admin', 'Plugin-Aktualisierungen geprüft: ' . $n);
            flash($n ? tn('market.updates_found', $n) : t('market.all_current'), $n ? 'success' : 'info');
            if ($res['errors']) {
                flash(t('market.check_errors', ['list' => market_list($res['errors'])]), 'warning');
            }
            redirect('admin', ['a' => 'plugins']);

        case 'pluginupdate':
            $slug = (string)post('slug');
            $u = PluginMarket::updates()[$slug] ?? null;
            $src = Plugins::source($slug);
            if ($u === null || $src === null) {
                flash(t('market.err_no_update'), 'warning');
                redirect('admin', ['a' => 'plugins']);
            }
            if ($u['confirm']) { // neue Berechtigungen: erst die Bestätigungsseite
                market_prepare((string)$src['repo'], (string)$u['version']);
            }
            @set_time_limit(120);
            $err = market_update_one($slug, (string)$u['version']);
            $name = Plugins::all()[$slug]['m']['name'] ?? $slug;
            $err === null ? flash(t('market.updated', ['name' => $name, 'version' => (string)$u['version']])) : flash($err, 'warning');
            redirect('admin', ['a' => 'plugins']);

        case 'pluginsupdateall':
            @set_time_limit(300);
            $done = [];
            $skipped = [];
            $errors = [];
            foreach (PluginMarket::updates() as $slug => $u) {
                $name = Plugins::all()[$slug]['m']['name'] ?? $slug;
                if ($u['confirm']) {
                    $skipped[$name] = t('market.needs_confirm', ['perms' => implode(', ', $u['confirm'])]);
                    continue;
                }
                $from = (string)(Plugins::source($slug)['version'] ?? '');
                $err = market_update_one($slug, (string)$u['version']);
                if ($err === null) {
                    $done[] = $name . ' ' . $from . ' → ' . $u['version'];
                } else {
                    $errors[$name] = $err;
                }
            }
            if (!$done && !$skipped && !$errors) {
                flash(t('market.all_current'), 'info');
            }
            $done && flash(tn('market.updated_all', count($done), ['list' => implode(', ', $done)]));
            $skipped && flash(t('market.skipped', ['list' => market_list($skipped)]), 'warning');
            $errors && flash(t('market.failed', ['list' => market_list($errors)]), 'warning');
            redirect('admin', ['a' => 'plugins']);

        case 'pluginprepare':
            market_prepare((string)post('repo'), (string)post('version'));

        case 'pluginapply':
            $id = (string)post('id');
            $pend = market_pending($id);
            if ($pend === null) {
                flash(t('market.err_pending'), 'warning');
                redirect('admin', ['a' => 'plugins']);
            }
            @set_time_limit(120);
            $expect = ['slug' => $pend['slug'], 'version' => $pend['version']];
            [$slug, $err] = $pend['mode'] === 'install'
                ? Plugins::archive($pend['zip'], $pend['repo'], $pend['sha'], $expect)
                : Plugins::update($pend['zip'], $pend['repo'], $pend['sha'], $expect);
            market_pending_drop($id);
            if ($slug === null) {
                flash($err, 'warning');
                redirect('admin', ['a' => 'plugins']);
            }
            if ($pend['mode'] === 'install') {
                PluginExposure::runIfStale(); // erste Installation: gleich prüfen, ob das Plugin-Verzeichnis geschützt ist
            }
            $m = Plugins::all()[$slug]['m'];
            Log::add('admin', 'Plugin aus GitHub ' . ($pend['mode'] === 'install' ? 'installiert' : 'aktualisiert') . ': ' . $m['name'] . ' ' . $m['version'] . ' (' . $pend['repo'] . ')');
            flash(t($pend['mode'] === 'install' ? 'market.installed' : 'market.updated', ['name' => $m['name'], 'version' => $m['version']]));
            redirect('admin', ['a' => 'plugins']);

        case 'plugincancel':
            market_pending_drop((string)post('id'));
            redirect('admin', ['a' => 'plugins']);

        case 'pluginrollback':
            $slug = (string)post('slug');
            $name = Plugins::all()[$slug]['m']['name'] ?? $slug;
            $err = Plugins::rollback($slug);
            if ($err !== null) {
                flash($err, 'warning');
            } else {
                $v = (string)Plugins::source($slug)['version'];
                Log::add('admin', 'Plugin zurückgesetzt: ' . $name . ' ' . $v);
                flash(t('market.rolled_back', ['name' => $name, 'version' => $v]));
            }
            redirect('admin', ['a' => 'plugins']);

        case 'pluginselftest':
            @set_time_limit(60);
            $res = PluginExposure::run();
            Log::add('admin', 'Plugin-Verzeichnis geprüft: ' . $res['status']);
            flash(t('market.selftest_' . $res['status']), $res['status'] === 'protected' ? 'success' : 'warning');
            redirect('admin', ['a' => 'plugins']);

        case 'pluginmarkettoken':
            $token = post('remove') !== '' ? '' : (string)post('token');
            if (PluginMarket::tokenSource() === 'config') {
                flash(t('market.token_config'), 'warning');
            } elseif (!PluginMarket::setToken($token)) {
                flash(t('market.token_invalid'), 'warning');
            } else {
                Log::add('admin', $token === '' ? 'GitHub-Token entfernt' : 'GitHub-Token gespeichert');
                flash(t($token === '' ? 'market.token_removed' : 'market.token_saved'));
            }
            redirect('admin', ['a' => 'pluginsearch']);
    }
    return false;
}

/** "Name: Grund; Name: Grund" für Meldungen. @param array<string,string> $items */
function market_list(array $items): string
{
    $out = [];
    foreach ($items as $k => $v) {
        $out[] = $k . ': ' . $v;
    }
    return implode('; ', $out);
}

/** Lädt ein Release und zeigt die Bestätigungsseite (Redirect); bei Fehlern zurück zur Übersicht. */
function market_prepare(string $repo, string $version): never
{
    market_cleanup();
    try {
        $f = PluginMarket::fetch($repo, $version);
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'warning');
        redirect('admin', ['a' => preg_match(Plugins::REPO_RE, $repo) ? 'plugindetail' : 'pluginsearch'] + (preg_match(Plugins::REPO_RE, $repo) ? ['repo' => $repo] : []));
    }
    $slug = $f['manifest']['slug'];
    $src = Plugins::source($slug);
    $err = '';
    if ($src === null && isset(Plugins::all()[$slug])) {
        $err = t('plugins.err_exists', ['name' => $f['manifest']['name']]);
    } elseif ($src !== null && strcasecmp((string)$src['repo'], $repo) !== 0) {
        $err = t('plugins.err_repo_other', ['repo' => (string)$src['repo']]);
    } elseif ($src !== null && version_compare($f['release']['version'], (string)$src['version'], '<=')) {
        $err = t('plugins.err_not_newer');
    }
    if ($err !== '') {
        PluginMarket::discard($f['zip']);
        flash($err, 'warning');
        redirect('admin', ['a' => 'plugindetail', 'repo' => $repo]);
    }
    $id = new_id();
    $_SESSION['plugin_pending'][$id] = [
        'zip' => $f['zip'], 'sha' => $f['sha'], 'repo' => $repo, 'version' => $f['release']['version'], 'slug' => $slug,
        'mode' => $src === null ? 'install' : 'update', 'created' => time(),
    ];
    while (count($_SESSION['plugin_pending']) > 3) { // ältere Vorgänge verwerfen
        market_pending_drop((string)array_key_first($_SESSION['plugin_pending']));
    }
    redirect('admin', ['a' => 'pluginconfirm', 'id' => $id]);
}

/** Vorgemerkter Download dieser Sitzung (Datei muss noch im Temp-Verzeichnis liegen) oder null. */
function market_pending(string $id): ?array
{
    $p = $_SESSION['plugin_pending'][$id] ?? null;
    if (!is_array($p) || !str_starts_with((string)$p['zip'], DATA_DIR . '/tmp/market-') || !is_file($p['zip'])
        || time() - (int)$p['created'] > 3600) {
        return null;
    }
    return $p;
}

function market_pending_drop(string $id): void
{
    if (isset($_SESSION['plugin_pending'][$id])) {
        PluginMarket::discard((string)$_SESSION['plugin_pending'][$id]['zip']);
        unset($_SESSION['plugin_pending'][$id]);
    }
}

/** Räumt liegengebliebene Downloads (älter als 1 h) im Temp-Verzeichnis weg. */
function market_cleanup(): void
{
    foreach (glob(DATA_DIR . '/tmp/market-*.zip') ?: [] as $f) {
        if (filemtime($f) < time() - 3600) {
            @unlink($f);
        }
    }
}

/** Lädt und installiert eine neue Version eines Marktplatz-Plugins. @return ?string Fehlertext */
function market_update_one(string $slug, string $version): ?string
{
    $src = Plugins::source($slug);
    if ($src === null) {
        return t('plugins.err_not_market');
    }
    $name = Plugins::all()[$slug]['m']['name'] ?? $slug;
    $from = (string)$src['version'];
    try {
        $f = PluginMarket::fetch((string)$src['repo'], $version);
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    [$s, $err] = Plugins::update($f['zip'], (string)$src['repo'], $f['sha'], ['slug' => $slug, 'version' => $version]);
    PluginMarket::discard($f['zip']);
    if ($s === null) {
        return $err;
    }
    Log::add('admin', 'Plugin aktualisiert: ' . $name . ' ' . $from . ' → ' . $version);
    return null;
}
