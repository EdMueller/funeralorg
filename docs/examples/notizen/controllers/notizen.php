<?php
declare(strict_types=1);

/** Controller: eine Funktion je Route, `$a` ist die Aktion (`index.php?r=notizen&a=…`). */
function ctrl_notizen(string $r, string $a): void
{
    // Routen verlangen eine Anmeldung; die Rechte prüft der Controller selbst. Freigabelinks dürfen hier nichts sehen.
    if (!Perm::memorial()) {
        abort(403, t('nz.denied'));
    }
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

    if ($a === 'add' || $a === 'delete') {
        if (!$isPost) {
            abort(400); // schreibende Aktionen nur per POST (die CSRF-Prüfung läuft schon in dispatch())
        }
        if ($a === 'add') {
            $text = Notizen::clean((string)post('text'));
            if ($text === '') {
                flash(t('nz.err_empty'), 'warning');
                redirect('notizen');
            }
            Store::update('notizen', function (array &$list) use ($text) {
                $list[] = ['id' => new_id(), 'text' => $text, 'by' => Auth::user()['name'] ?? '', 'created' => now()];
            });
            Log::change('notizen', 'Notiz angelegt');
            flash(t('nz.added'));
            redirect('notizen');
        }
        Perm::needAdmin();
        $id = (string)post('id');
        $gone = Store::update('notizen', function (array &$list) use ($id) {
            $before = count($list);
            $list = array_values(array_filter($list, fn($n) => $n['id'] !== $id));
            return count($list) < $before;
        });
        if ($gone) {
            Log::change('notizen', 'Notiz gelöscht');
            flash(t('nz.deleted'));
        }
        redirect('notizen');
    }

    view('@notizen/notizen', ['notes' => Notizen::all(), 'isAdmin' => Auth::isAdmin()]);
}
