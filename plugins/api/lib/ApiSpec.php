<?php
declare(strict_types=1);

/**
 * Tabelle aller Operationen. Einzige Quelle für REST-Routen, MCP-Tools und die OpenAPI-Beschreibung.
 *
 * Eintrag: id (z. B. contacts.list), method, path (mit {platzhaltern}), write (ändert Daten), mod (Bereich für die
 * Sichtbarkeit: contacts|rsvp|checklist|gallery|memorial|admin|stations|''), doc, in (Parameter), fn (ApiService-Methode),
 * optional raw (Antwort sendet die Methode selbst, nur REST), destructive.
 * Parameter: Name (Endung ! = Pflicht) => [Typ, Beschreibung, optional Aufzählung].
 */
final class ApiSpec
{
    public const VERSION = '1';

    /** @return list<array> */
    public static function ops(): array
    {
        static $ops = null;
        if ($ops !== null) {
            return $ops;
        }
        $id = ['id!' => ['string', 'ID']];
        $contact = [
            'salutation' => ['string', 'Anrede'], 'first' => ['string', 'Vorname'], 'last' => ['string', 'Nachname'],
            'relation' => ['string', 'Beziehung zur verstorbenen Person'], 'note' => ['string', 'Notiz'],
            'street' => ['string', 'Straße (Feldrecht Adresse)'], 'zip' => ['string', 'PLZ'], 'city' => ['string', 'Ort'],
            'state' => ['string', 'Bundesland'], 'country' => ['string', 'Land'],
            'phone' => ['string', 'Telefon (Feldrecht)'], 'mobile' => ['string', 'Mobil (Feldrecht)'], 'email' => ['string', 'E-Mail (Feldrecht)'],
            'called' => ['boolean', 'Angerufen'], 'letter' => ['boolean', 'Brief versendet'],
            'rsvp' => ['string', 'Rückmeldung: leer = nicht eingeladen, open, yes, no', ['', 'open', 'yes', 'no']],
            'adults' => ['integer', 'Erwachsene (0–99)'], 'children' => ['integer', 'Kinder (0–99)'], 'rsvp_note' => ['string', 'Notiz zur Rückmeldung'],
            'card' => ['boolean', 'Karte'], 'flowers' => ['boolean', 'Blumen'], 'wreath' => ['boolean', 'Kranz'],
            'donation' => ['boolean', 'Spende'], 'amount' => ['number', 'Spendenbetrag'], 'cond_note' => ['string', 'Notiz zur Kondolenz'],
            'thanks' => ['boolean', 'Dankeskarte versendet'],
        ];
        $task = [
            'title' => ['string', 'Titel (max. 300 Zeichen)'], 'due' => ['string', 'Fällig am (JJJJ-MM-TT) oder leer'],
            'who' => ['string', 'Zuständig (max. 100 Zeichen)'], 'note' => ['string', 'Notiz (max. 2000 Zeichen)'],
        ];
        $album = [
            'name' => ['string', 'Name (max. 100 Zeichen)'], 'parent' => ['string', 'ID des übergeordneten Albums oder leer'],
            'cat' => ['string', 'Kategorie für die Stationen-Legende'], 'color' => ['string', 'Farbe (Hex aus der Palette) oder leer'],
            'links' => ['array', 'IDs weiterer Eltern-Alben (Verweise)'],
        ];
        $ops = [
            ['me', 'GET', '/me', false, '', 'Angemeldeter Benutzer, Token-Bereich und Rechte', [], 'me'],
            ['summary', 'GET', '/summary', false, '', 'Kennzahlen wie auf der Startseite (offene Aufgaben, Rückmeldungen, offene Dankeskarten, Anrufe)', [], 'summary'],

            ['memorial.get', 'GET', '/memorial', false, 'memorial', 'Gedenkseite: Name, Lebensdaten, Spruch, Text, Portrait-Bild-ID', [], 'memorialGet'],
            ['memorial.update', 'PATCH', '/memorial', true, 'admin', 'Gedenkseite ändern (nur Administratoren); nur übergebene Felder werden gesetzt', [
                'name' => ['string', 'Name'], 'born' => ['string', 'Geboren (Freitext)'], 'died' => ['string', 'Gestorben (Freitext)'],
                'place' => ['string', 'Ort'], 'quote' => ['string', 'Spruch'], 'quote_by' => ['string', 'Urheber des Spruchs'],
                'text' => ['string', 'Text der Gedenkseite'], 'portrait' => ['string', 'Bild-ID des Portraits oder leer'],
            ], 'memorialUpdate'],

            ['contacts.list', 'GET', '/contacts', false, 'contacts', 'Kontakte auflisten (nur Felder, die der Benutzer sehen darf)', [
                'q' => ['string', 'Suchtext in Name, Beziehung und (mit Adressrecht) Ort'],
                'filter' => ['string', 'Filter: called_open, letter_open, rsvp_yes, rsvp_no, rsvp_open, cond_any, thanks_open', Contacts::FILTERS],
                'relation' => ['string', 'Nur diese Beziehung'], 'limit' => ['integer', 'Anzahl (Standard 100, höchstens 500)'], 'offset' => ['integer', 'Start (Standard 0)'],
            ], 'contactsList'],
            ['contacts.get', 'GET', '/contacts/{id}', false, 'contacts', 'Einen Kontakt lesen', $id, 'contactsGet'],
            ['contacts.create', 'POST', '/contacts', true, 'contacts', 'Kontakt anlegen (Recht „Kontakte bearbeiten“; Vor- oder Nachname nötig)', $contact, 'contactsCreate'],
            ['contacts.update', 'PATCH', '/contacts/{id}', true, 'contacts', 'Kontakt ändern; nur übergebene Felder, soweit die Rechte reichen (Abhaken, Rückmeldung, Kondolenz je nach Bereichsrecht)', $id + $contact, 'contactsUpdate'],
            ['contacts.delete', 'DELETE', '/contacts/{id}', true, 'contacts', 'Kontakt unwiderruflich löschen', $id, 'contactsDelete', 'destructive'],
            ['rsvp.summary', 'GET', '/rsvp/summary', false, 'rsvp', 'Summen der Zu-/Absagen (Zusagen, Absagen, offen, Erwachsene, Kinder)', [], 'rsvpSummary'],

            ['checklist.get', 'GET', '/checklist', false, 'checklist', 'Checkliste: Kategorien und alle Aufgaben samt Unteraufgaben in Anzeigereihenfolge', [
                'show' => ['string', 'open = nur offene Aufgaben', ['all', 'open']],
            ], 'checklistGet'],
            ['checklist.task.get', 'GET', '/checklist/tasks/{id}', false, 'checklist', 'Eine Aufgabe lesen', $id, 'taskGet'],
            ['checklist.task.create', 'POST', '/checklist/tasks', true, 'checklist', 'Aufgabe anlegen: in Kategorie `cat` oder als Unteraufgabe von `parent`', ['title!' => $task['title'], 'cat' => ['string', 'Kategorie-ID'], 'parent' => ['string', 'ID der Hauptaufgabe (dann Unteraufgabe)']] + array_diff_key($task, ['title' => 1]), 'taskCreate'],
            ['checklist.task.update', 'PATCH', '/checklist/tasks/{id}', true, 'checklist', 'Aufgabe ändern. `done` verlangt das Recht „Abhaken“, alles andere „Bearbeiten“', $id + $task + ['cat' => ['string', 'Kategorie-ID (nur Hauptaufgaben)'], 'done' => ['boolean', 'Erledigt']], 'taskUpdate'],
            ['checklist.task.move', 'POST', '/checklist/tasks/{id}/move', true, 'checklist', 'Aufgabe verschieben/umsortieren. Ohne `cat`/`parent` bleibt die Ebene, `position` (0 = ganz oben) setzt die Stelle. Hauptaufgaben mit Unteraufgaben können keine Unteraufgabe werden', $id + [
                'cat' => ['string', 'Ziel-Kategorie-ID'], 'parent' => ['string', 'Ziel-Hauptaufgabe (leer = oberste Ebene)'], 'position' => ['integer', 'Stelle in der Zielliste, ab 0 (Standard: ans Ende)'],
            ], 'taskMove'],
            ['checklist.task.delete', 'DELETE', '/checklist/tasks/{id}', true, 'checklist', 'Aufgabe samt Unteraufgaben löschen', $id, 'taskDelete', 'destructive'],
            ['checklist.category.create', 'POST', '/checklist/categories', true, 'checklist', 'Kategorie anlegen', ['name!' => ['string', 'Name (max. 100 Zeichen)']], 'categoryCreate'],
            ['checklist.category.update', 'PATCH', '/checklist/categories/{id}', true, 'checklist', 'Kategorie umbenennen', $id + ['name!' => ['string', 'Neuer Name']], 'categoryUpdate'],
            ['checklist.category.delete', 'DELETE', '/checklist/categories/{id}', true, 'checklist', 'Kategorie samt allen Aufgaben löschen', $id, 'categoryDelete', 'destructive'],

            ['gallery.albums', 'GET', '/gallery/albums', false, 'gallery', 'Alben mit Bildanzahl (in Anzeigereihenfolge)', [], 'albums'],
            ['gallery.album.create', 'POST', '/gallery/albums', true, 'admin', 'Album anlegen (nur Administratoren)', ['name!' => $album['name']] + array_diff_key($album, ['name' => 1]), 'albumCreate'],
            ['gallery.album.update', 'PATCH', '/gallery/albums/{id}', true, 'admin', 'Album ändern (nur Administratoren); nur übergebene Felder', $id + $album, 'albumUpdate'],
            ['gallery.album.delete', 'DELETE', '/gallery/albums/{id}', true, 'admin', 'Album löschen; Unteralben und Bilder rücken in das übergeordnete Album auf', $id, 'albumDelete', 'destructive'],
            ['gallery.order', 'PUT', '/gallery/albums/{id}/order', true, 'gallery', 'Reihenfolge der Bilder eines Albums setzen (`id` = Album-ID oder `_none`); Recht „Galerie hochladen“', $id + ['ids!' => ['array', 'Bild-IDs in der neuen Reihenfolge']], 'galleryOrder'],
            ['gallery.images', 'GET', '/gallery/images', false, 'gallery', 'Bilder auflisten (Metadaten, ohne Bilddaten)', [
                'album' => ['string', 'Album-ID, `_none` (ohne Album) oder leer (alle)'], 'recursive' => ['boolean', 'Mit Unteralben'],
                'limit' => ['integer', 'Anzahl (Standard 100, höchstens 500)'], 'offset' => ['integer', 'Start (Standard 0)'],
            ], 'images'],
            ['gallery.image.get', 'GET', '/gallery/images/{id}', false, 'gallery', 'Bild-Metadaten lesen', $id, 'imageGet'],
            ['gallery.image.update', 'PATCH', '/gallery/images/{id}', true, 'gallery', 'Bildunterschrift/Album/Verweise ändern (Administratoren oder Hochlader des Bildes); `login` nur Administratoren', $id + [
                'caption' => ['string', 'Unterschrift (max. 300 Zeichen)'], 'album' => ['string', 'Heimatalbum-ID oder leer'],
                'links' => ['array', 'Weitere Album-IDs'], 'login' => ['boolean', 'Auf der Anmeldeseite zeigen (nur Administratoren)'],
            ], 'imageUpdate'],
            ['gallery.image.delete', 'DELETE', '/gallery/images/{id}', true, 'admin', 'Bild samt Dateien löschen (nur Administratoren)', $id, 'imageDelete', 'destructive'],
            ['gallery.image.file', 'GET', '/gallery/images/{id}/file', false, 'gallery', 'Bilddatei ausliefern (binär)', $id + ['size' => ['string', 'thumb, medium oder orig (orig verlangt das Recht „Herunterladen“)', ['thumb', 'medium', 'orig']]], 'imageFile', 'raw'],
            ['gallery.image.upload', 'POST', '/gallery/images', true, 'gallery', 'Bilder hochladen (multipart/form-data: `files[]`, optional `album`)', [], 'imageUpload', 'raw'],

            ['stations.list', 'GET', '/stations', false, 'stations', 'Stationen (Plugin „Stationen“), nur lesend', [], 'stations'],
        ];
        $out = [];
        foreach ($ops as $o) {
            $out[] = [
                'id' => $o[0], 'method' => $o[1], 'path' => $o[2], 'write' => $o[3], 'mod' => $o[4], 'doc' => $o[5],
                'in' => $o[6], 'fn' => $o[7], 'raw' => ($o[8] ?? '') === 'raw', 'destructive' => ($o[8] ?? '') === 'destructive',
            ];
        }
        return $ops = $out;
    }

    /** Operation zu Methode und Pfad; [op, Pfadparameter] oder [null, erlaubte Methoden]. */
    public static function match(string $method, string $path): array
    {
        $allowed = [];
        foreach (self::ops() as $op) {
            $re = '#^' . preg_replace('/\\\\\{(\w+)\\\\\}/', '(?P<$1>[A-Za-z0-9_-]{1,64})', preg_quote($op['path'], '#')) . '$#';
            if (!preg_match($re, $path, $m)) {
                continue;
            }
            if ($op['method'] === $method) {
                return [$op, array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY)];
            }
            $allowed[] = $op['method'];
        }
        return [null, $allowed];
    }

    /** Darf der aktuelle Benutzer die Operation grundsätzlich sehen/nutzen (Bereichsrecht, Token-Bereich)? */
    public static function available(array $op, string $scope): bool
    {
        if ($op['write'] && $scope !== 'write') {
            return false;
        }
        return match ($op['mod']) {
            'contacts' => Perm::can('contacts') || Perm::can('rsvp') || Perm::can('condolences'),
            'rsvp', 'checklist' => Perm::can($op['mod']),
            'gallery' => Perm::gallery(),
            'memorial' => Perm::memorial(),
            'admin' => Auth::isAdmin(),
            'stations' => class_exists('Stations') && Perm::memorial(),
            default => true,
        };
    }

    /** Parameter als JSON-Schema (Objekt). */
    public static function schema(array $in): array
    {
        $props = [];
        $req = [];
        foreach ($in as $name => $def) {
            if (str_ends_with($name, '!')) {
                $name = substr($name, 0, -1);
                $req[] = $name;
            }
            $prop = ['type' => $def[0], 'description' => $def[1]];
            if (isset($def[2])) {
                $prop['enum'] = $def[2];
            }
            if ($def[0] === 'array') {
                $prop['items'] = ['type' => 'string'];
            }
            $props[$name] = $prop;
        }
        $schema = ['type' => 'object', 'properties' => (object)$props];
        if ($req) {
            $schema['required'] = $req;
        }
        return $schema;
    }

    /** OpenAPI-3.0-Beschreibung der REST-Schnittstelle. */
    public static function openapi(string $baseUrl): array
    {
        $paths = [];
        foreach (self::ops() as $op) {
            $schema = self::schema($op['in']);
            $params = [];
            foreach ((array)$schema['properties'] as $name => $prop) {
                if (str_contains($op['path'], '{' . $name . '}')) {
                    $params[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => $prop['description']];
                }
            }
            $entry = ['operationId' => str_replace('.', '_', $op['id']), 'summary' => $op['doc'], 'security' => [['bearer' => []]],
                'responses' => ['200' => ['description' => 'OK: {"ok": true, "data": …}'],
                    '401' => ['description' => 'Token fehlt oder ist ungültig'], '403' => ['description' => 'Recht oder Token-Bereich fehlt'],
                    '404' => ['description' => 'Nicht gefunden'], '422' => ['description' => 'Ungültige Eingabe']]];
            $body = array_diff_key((array)$schema['properties'], array_flip(array_map(fn($p) => $p['name'], $params)));
            if (in_array($op['method'], ['GET', 'DELETE'], true)) {
                foreach ($body as $name => $prop) {
                    $params[] = ['name' => $name, 'in' => 'query', 'required' => in_array($name, $schema['required'] ?? [], true), 'schema' => array_diff_key($prop, ['description' => 1]), 'description' => $prop['description']];
                }
            } elseif ($body && !$op['raw']) {
                $pathNames = array_map(fn($p) => $p['name'], $params);
                $entry['requestBody'] = ['content' => ['application/json' => ['schema' => [
                    'type' => 'object', 'properties' => $body, 'required' => array_values(array_diff($schema['required'] ?? [], $pathNames))]]]];
            }
            if ($op['id'] === 'gallery.image.upload') {
                $entry['requestBody'] = ['content' => ['multipart/form-data' => ['schema' => ['type' => 'object', 'properties' => [
                    'files[]' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'binary']], 'album' => ['type' => 'string']]]]]];
            }
            if ($params) {
                $entry['parameters'] = $params;
            }
            $paths['/' . ltrim($op['path'], '/')][strtolower($op['method'])] = $entry;
        }
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'FuneralOrg Web-API', 'version' => self::VERSION,
                'description' => 'Aufruf: ' . $baseUrl . '?r=api&p=<Pfad>, z. B. ' . $baseUrl . '?r=api&p=/contacts. Authentifizierung: Authorization: Bearer <Token>.'],
            'servers' => [['url' => $baseUrl . '?r=api&p=']],
            'components' => ['securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']]],
            'paths' => $paths,
        ];
    }
}
