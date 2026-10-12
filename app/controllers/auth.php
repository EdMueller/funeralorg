<?php
declare(strict_types=1);

/** Beendet die Übernahme (Zurück-Button und Logout) und leitet weiter. */
function leave_impersonation(): never
{
    if (Auth::stopImpersonation()) {
        flash(t('imp.returned'), 'info');
        redirect('admin', ['a' => 'users']);
    }
    start_session();
    flash(t('imp.admin_gone'), 'warning');
    redirect('login');
}

function ctrl_auth(string $r, string $a): void
{
    if ($r === 'lang') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            abort(400);
        }
        $l = (string)post('lang');
        if (isset(I18n::LANGS[$l])) {
            I18n::set($l);
            I18n::saveForUser($l);
        }
        redirect_back();
    }

    if ($r === 'logout') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            abort(400);
        }
        if (Auth::isImpersonating()) {
            leave_impersonation();
        }
        Auth::logout();
        start_session();
        flash(t('auth.logged_out'), 'info');
        redirect('login');
    }

    if ($r === 'unimpersonate') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Auth::isImpersonating()) {
            abort(400);
        }
        leave_impersonation();
    }

    if ($r === 's') {
        $token = (string)get('t');
        if (!Auth::openShare($token)) {
            http_response_code(404);
            view('error', ['title' => t('error.link_invalid'), 'code' => 404,
                'msg' => t('error.share_gone')], 'layout_plain');
            return;
        }
        $to = Perm::firstRoute();
        if ($to === null) {
            abort(403, t('error.share_none'));
        }
        redirect($to);
    }

    if ($r === 'forgot' || $r === 'reset') {
        if (Auth::check() && !Auth::isShare()) {
            redirect('home');
        }
        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        $name = Store::read('memorial')['name'] ?: cfg('app_name');
        if ($r === 'forgot') {
            $mode = $a === 'help' ? 'help' : 'mail';
            if ($isPost) {
                // Immer dieselbe Antwort – es wird nie verraten, ob ein Konto oder eine Adresse existiert.
                $job = null;
                if ($mode === 'help') {
                    Reset::help((string)post('who'), (string)post('phone'));
                    flash(t('forgot.done_help'));
                } else {
                    $job = Reset::request((string)post('username'));
                    flash(t('forgot.done_mail'));
                }
                // Antwort zuerst abschließen, dann senden – so verrät die Antwortzeit nichts
                session_write_close();
                header('Location: ' . url('login'));
                http_response_code(302);
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                if ($job) {
                    Reset::sendLink($job);
                }
                exit;
            }
            view('forgot', ['mode' => $mode, 'flashes' => take_flashes()], 'layout_plain');
            return;
        }
        // r=reset
        $token = (string)($_POST['t'] ?? get('t'));
        $user = Reset::user($token);
        if (!$user) {
            http_response_code(410);
            view('error', ['title' => t('error.link_invalid'), 'code' => 410,
                'msg' => t('error.reset_gone')], 'layout_plain');
            return;
        }
        if ($isPost) {
            $new = (string)($_POST['new'] ?? '');
            if (mb_strlen($new) < 10) {
                flash(t('reset.pw_short'), 'warning');
            } elseif ($new !== (string)($_POST['repeat'] ?? '')) {
                flash(t('reset.pw_mismatch'), 'warning');
            } elseif ($done = Reset::complete($token, $new)) {
                flash(t('reset.done'));
                session_write_close();
                header('Location: ' . url('login'));
                http_response_code(302);
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                Reset::sendChanged($done);
                exit;
            }
            redirect('reset', ['t' => $token]);
        }
        view('reset', ['token' => $token, 'user' => $user, 'flashes' => take_flashes()], 'layout_plain');
        return;
    }

    if ($r === 'account') {
        if (Auth::isShare()) {
            abort(403, t('account.no_share'));
        }
        $me = Auth::user();
        if (Auth::isImpersonating()) {
            abort(403, t('account.no_imp'));
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $cur = (string)($_POST['current'] ?? '');
            $new = (string)($_POST['new'] ?? '');
            $rep = (string)($_POST['repeat'] ?? '');
            $row = find_by_id(Store::read('users'), $me['id']);
            if (!$row || !password_verify($cur, $row['pass_hash'])) {
                Log::add('login_fail', $me['username'] . ' (Passwortänderung)');
                sleep(1); // bremst Raten des aktuellen Passworts
                flash(t('account.wrong_current'), 'warning');
            } elseif (mb_strlen($new) < 10) {
                flash(t('account.pw_short'), 'warning');
            } elseif ($new !== $rep) {
                flash(t('account.pw_mismatch'), 'warning');
            } elseif ($new === $cur) {
                flash(t('account.pw_same'), 'warning');
            } else {
                Store::update('users', function (array &$users) use ($me, $new) {
                    foreach ($users as &$u) {
                        if ($u['id'] === $me['id']) {
                            $u['pass_hash'] = password_hash($new, PASSWORD_DEFAULT);
                            $u['pw_changed'] = time();
                        }
                    }
                });
                session_regenerate_id(true);
                $_SESSION['login_t'] = time(); // diese Sitzung bleibt gültig, andere enden
                Log::add('admin', 'Eigenes Passwort geändert: ' . $me['username']);
                flash(t('account.pw_changed'));
            }
            redirect('account');
        }
        view('account', ['me' => $me]);
        return;
    }

    // Login
    if (Auth::check() && !Auth::isShare()) {
        redirect('home');
    }
    $error = null;
    $username = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = mb_substr((string)post('username'), 0, 100);
        $error = Auth::attempt($username, (string)($_POST['password'] ?? ''));
        if ($error === null) {
            I18n::onLogin();
            redirect('home');
        }
    }

    $memorial = Store::read('memorial');
    $gallery = Store::read('gallery');
    $loginImages = array_values(array_filter($gallery['images'],
        fn($i) => !empty($i['login']) && $i['id'] !== ($memorial['portrait'] ?? '')));
    $portrait = null;
    if (!empty($memorial['portrait'])) {
        // Das Portrait ist immer auf der Anmeldeseite sichtbar (siehe Gedenkseite bearbeiten)
        $portrait = find_by_id($gallery['images'], $memorial['portrait']);
    }
    view('login', compact('error', 'username', 'memorial', 'loginImages', 'portrait'), null);
}
