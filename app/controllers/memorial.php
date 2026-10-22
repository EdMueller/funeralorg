<?php
declare(strict_types=1);

function ctrl_memorial(string $r, string $a): void
{
    if (!Perm::memorial()) {
        $to = Perm::firstRoute();
        $to && $to !== 'home' ? redirect($to) : abort(403);
    }

    if ($a === 'edit') {
        Perm::needAdmin();
        $gallery = Store::read('gallery');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $portrait = (string)post('portrait');
            if ($portrait !== '' && !find_by_id($gallery['images'], $portrait)) {
                $portrait = '';
            }
            $frame = Frame::key(post('frame'));
            Store::update('memorial', function (array &$m) use ($portrait, $frame) {
                $m['name'] = mb_substr((string)post('name'), 0, 120);
                $m['born'] = mb_substr((string)post('born'), 0, 40);
                $m['died'] = mb_substr((string)post('died'), 0, 40);
                $m['place'] = mb_substr((string)post('place'), 0, 120);
                $m['quote'] = mb_substr((string)post('quote'), 0, 400);
                $m['quote_by'] = mb_substr((string)post('quote_by'), 0, 120);
                $m['text'] = mb_substr((string)post('text'), 0, 8000);
                $m['portrait'] = $portrait;
                $m['frame'] = $frame;
            });
            Log::add('admin', 'Gedenkseite bearbeitet');
            flash(t('memorial.saved'));
            redirect('home');
        }
        view('memorial_edit', [
            'm'      => Store::read('memorial'),
            'images' => array_reverse($gallery['images']),
        ]);
        return;
    }

    $m = Store::read('memorial');
    $gallery = Store::read('gallery');
    $portrait = !empty($m['portrait']) ? find_by_id($gallery['images'], $m['portrait']) : null;
    $recent = Perm::gallery() ? array_slice(array_reverse(Albums::forViewer($gallery)['images']), 0, 8) : [];

    $summary = [];
    if (Perm::can('checklist')) {
        $items = Store::read('checklist')['items'];
        $summary['tasks_open'] = count(array_filter($items, fn($i) => empty($i['done'])));
        $summary['tasks_total'] = count($items);
    }
    $contacts = null;
    if (Perm::can('rsvp')) {
        $contacts = Contacts::all();
        $summary['rsvp'] = Contacts::rsvpSums($contacts);
    }
    if (Perm::can('condolences')) {
        $contacts ??= Contacts::all();
        $summary['thanks_open'] = count(Contacts::filter($contacts, 'thanks_open'));
    }
    if (Perm::can('contacts')) {
        $contacts ??= Contacts::all();
        $summary['called_open'] = count(Contacts::filter($contacts, 'called_open'));
    }
    view('home', compact('m', 'portrait', 'recent', 'summary'));
}
