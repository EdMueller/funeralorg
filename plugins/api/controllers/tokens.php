<?php
declare(strict_types=1);

/** Verwaltung der Zugriffstoken (Sitzung + CSRF wie jede Seite). Admins sehen und widerrufen alle, Benutzer ihre eigenen. */
function ctrl_apitokens(string $r, string $a): void
{
    if (!ApiTokens::mayManage()) {
        abort(403, t('perm.denied'));
    }
    $isAdmin = Auth::isAdmin();
    $me = Auth::user();
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $users = Store::read('users');

    switch ($a) {
        case 'create':
            if (!$isPost) {
                abort(400);
            }
            $label = mb_substr((string)post('label'), 0, 80);
            $owner = $isAdmin && post('user') !== '' ? (string)post('user') : $me['id'];
            $target = find_by_id($users, $owner);
            if ($label === '' || !$target || empty($target['active'])) {
                flash(t('api.err_create'), 'warning');
                redirect('apitokens');
            }
            $days = (int)post('days', 90);
            $days = in_array($days, [0, 30, 90, 180, 365], true) ? $days : 90;
            $raw = ApiTokens::create($owner, $label, (string)post('scope'), $days);
            $_SESSION['api_new_token'] = $raw;
            Log::add('admin', 'API-Token erstellt: ' . $label . ' (' . $target['username'] . ')');
            redirect('apitokens');

        case 'revoke':
            if (!$isPost) {
                abort(400);
            }
            $t = ApiTokens::revoke((string)post('id'), $isAdmin ? '' : $me['id']);
            if ($t) {
                Log::add('admin', 'API-Token widerrufen: ' . $t['label']);
                flash(t('api.revoked'));
            }
            redirect('apitokens');

        case 'settings':
            if (!$isPost) {
                abort(400);
            }
            Perm::needAdmin();
            ApiTokens::setUserTokens(!empty($_POST['user_tokens']));
            Log::add('admin', 'API-Einstellung geändert');
            flash(t('api.saved'));
            redirect('apitokens');
    }

    $new = (string)($_SESSION['api_new_token'] ?? '');
    unset($_SESSION['api_new_token']);
    $mine = array_values(array_filter(ApiTokens::all(), fn($t) => $isAdmin || $t['user'] === $me['id']));
    usort($mine, fn($x, $y) => $y['created'] <=> $x['created']);
    view('@api/tokens', [
        'tokens'  => $mine,
        'users'   => $users,
        'isAdmin' => $isAdmin,
        'new'     => $new,
        'allow'   => ApiTokens::userTokensAllowed(),
        'apiUrl'  => site_base_url() . url('api'),
        'mcpUrl'  => site_base_url() . url('mcp'),
        'https'   => ApiTokens::secureTransport(),
    ]);
}
