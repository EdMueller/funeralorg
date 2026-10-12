<?php
declare(strict_types=1);

function ctrl_stations(string $r, string $a): void
{
    if (!Perm::memorial()) {
        abort(403, t('st.denied'));
    }
    $isAdmin = Auth::isAdmin();
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

    if ($a === 'save' || $a === 'delete') {
        if (!$isPost) {
            abort(400);
        }
        Perm::needAdmin();
        $id = (string)post('id');
        $gallery = Store::read('gallery');

        if ($a === 'delete') {
            $title = Store::update('stations', function (array &$list) use ($id) {
                foreach ($list as $k => $s) {
                    if ($s['id'] === $id && ($s['type'] ?? '') === '') {
                        unset($list[$k]);
                        $list = array_values($list);
                        return $s['title'];
                    }
                }
                return null;
            });
            if ($title !== null) {
                Log::change('stations', 'Station gelöscht', $title);
                flash(t('st.deleted'));
            }
            redirect('stations');
        }

        $type = in_array($id, ['birth', 'death'], true) ? $id : '';
        $title = $type === 'birth' ? t('st.birth') : ($type === 'death' ? t('st.death') : mb_substr(trim((string)post('title')), 0, 120));
        $date = $type === '' ? Stations::parseDate((string)post('date')) : '';
        if ($title === '') {
            flash(t('st.err_title'), 'warning');
            redirect('stations');
        }
        if ($type === '' && $date === '') {
            flash(t('st.err_date'), 'warning');
            redirect('stations');
        }
        $lat = trim((string)post('lat'));
        $lon = trim((string)post('lon'));
        $hasPos = $lat !== '' && $lon !== ''
            && is_numeric(str_replace(',', '.', $lat)) && is_numeric(str_replace(',', '.', $lon));
        $latN = $hasPos ? (float)str_replace(',', '.', $lat) : null;
        $lonN = $hasPos ? (float)str_replace(',', '.', $lon) : null;
        if ($hasPos && (abs($latN) > 90 || abs($lonN) > 180)) {
            flash(t('st.err_pos'), 'warning');
            redirect('stations');
        }
        $album = (string)post('album');
        $album = find_by_id($gallery['albums'], $album) ? $album : '';
        $image = (string)post('image');
        $image = find_by_id($gallery['images'], $image) ? $image : '';
        $data = [
            'title' => $title,
            'date'  => $date,
            'place' => mb_substr(trim((string)post('place')), 0, 120),
            'lat'   => $hasPos ? round($latN, 4) : null,
            'lon'   => $hasPos ? round($lonN, 4) : null,
            'text'  => mb_substr(trim((string)post('text')), 0, 600),
            'album' => $album,
            'image' => $image,
            'type'  => $type,
        ];
        $new = Store::update('stations', function (array &$list) use ($id, $data, $type) {
            foreach ($list as &$s) {
                if ($s['id'] === $id) {
                    $s = array_merge($s, $data);
                    return false;
                }
            }
            $list[] = $data + ['id' => $type !== '' ? $type : new_id(), 'created' => now()];
            return true;
        });
        Log::change('stations', $new ? 'Station angelegt' : 'Station bearbeitet', $title);
        flash(t($new ? 'st.created' : 'st.saved'));
        redirect('stations');
    }

    $gallery = Store::read('gallery');
    $albums = $gallery['albums'];
    $images = $gallery['images'];
    $canGallery = Perm::gallery();
    $all = Stations::all();

    // Daten für Zeitstrahl, Karte und Popup. Bild- und Albumbezug nur mit Galerie-Recht.
    $items = [];
    foreach ($all as $s) {
        $look = Stations::look($s, $albums);
        $albumImages = $s['album'] !== '' ? Albums::images($images, Albums::subtree($albums, $s['album'])) : [];
        $imgId = '';
        if ($canGallery) {
            $imgId = ($s['image'] !== '' && find_by_id($images, $s['image'])) ? $s['image'] : ($albumImages[0]['id'] ?? '');
        }
        $link = '';
        if ($canGallery) {
            if ($s['album'] !== '' && $albumImages) {
                $link = url('gallery', ['album' => $s['album'], 'play' => '1']);
            } elseif ($s['image'] !== '' && find_by_id($images, $s['image'])) {
                $link = url('gallery', ['open' => $s['image']]);
            }
        }
        $items[] = [
            'id'    => $s['id'],
            'type'  => $s['type'],
            'title' => $s['title'],
            'date'  => $s['date'],
            'when'  => Stations::fmt($s['date']),
            'ts'    => Stations::ts($s['date']),
            'place' => $s['place'],
            'lat'   => $s['lat'],
            'lon'   => $s['lon'],
            'text'  => $s['text'],
            'color' => $look['color'],
            'cat'   => $look['cat'],
            'img'   => $imgId !== '' ? url('img', ['id' => $imgId, 's' => 'thumb']) : '',
            'link'  => $link,
            'count' => $canGallery ? count($albumImages) : 0,
            'raw'   => $isAdmin ? [
                'id' => $s['id'], 'title' => $s['title'], 'type' => $s['type'],
                'date' => $s['type'] !== '' ? '' : Stations::fmt($s['date']),
                'place' => $s['place'], 'lat' => $s['lat'], 'lon' => $s['lon'],
                'text' => $s['text'], 'album' => $s['album'], 'image' => $s['image'],
            ] : null,
        ];
    }
    view('stations', [
        'items'   => $items,
        'albums'  => $albums,
        'images'  => $isAdmin ? $images : [],
        'isAdmin' => $isAdmin,
    ]);
}
