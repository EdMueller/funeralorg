<?php
declare(strict_types=1);

function ctrl_setup(string $r, string $a): void
{
    $errors = Setup::prepare();
    $form = ['username' => '', 'name' => '', 'deceased' => '', 'base_url' => request_base_url()];

    if (!$errors && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $form['username'] = mb_strtolower(mb_substr((string)post('username'), 0, 60));
        $form['name'] = mb_substr((string)post('name'), 0, 100);
        $form['deceased'] = mb_substr((string)post('deceased'), 0, 100);
        $form['base_url'] = mb_substr((string)post('base_url'), 0, 200);
        $baseUrl = normalize_base_url($form['base_url']);
        $pw = (string)($_POST['password'] ?? '');

        if (!preg_match('/^[a-z0-9._@-]{3,60}$/', $form['username'])) {
            $errors[] = t('setup.err_username');
        }
        if (mb_strlen($pw) < 10) {
            $errors[] = t('setup.err_pw_short');
        } elseif ($pw !== (string)($_POST['password2'] ?? '')) {
            $errors[] = t('setup.err_pw_mismatch');
        }
        if ($baseUrl === null) {
            $errors[] = t('setup.err_base_url');
        }
        if ($form['name'] === '') {
            $form['name'] = ucfirst($form['username']);
        }

        if (!$errors) {
            $err = Setup::create($form['username'], $form['name'], $pw, $form['deceased']);
            if ($err === null) {
                Store::update('settings', function (array &$s) use ($baseUrl) {
                    $s['base_url'] = $baseUrl;
                });
                Log::add('admin', 'Ersteinrichtung: Administrator angelegt', ['what' => $form['username']]);
                flash(t('setup.done'), 'success');
                redirect('login');
            }
            $errors[] = $err;
        }
    }

    view('setup', compact('errors', 'form'), 'layout_plain');
}
