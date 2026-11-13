<?php
declare(strict_types=1);

/**
 * Beispielbilder für die Ersteinrichtung (Option „Beispieldaten anlegen“, siehe app/lib/Demo.php).
 *
 * Neue Bilder: Datei nach app/demo/images/ legen (JPEG/PNG/WebP, empfohlen höchstens 1600 px lange Seite),
 * hier eintragen und die Beschriftung unter `demo.img.<name>` in app/lang/{de,en}/demo.php ergänzen.
 * Die Bilder werden in der angegebenen Reihenfolge importiert.
 *
 * album:    Schlüssel aus Demo::ALBUMS (nature | home | travel)
 * portrait: genau ein Bild dient als Porträt der Beispiel-Gedenkseite
 * login:    true = Bild läuft in der Hintergrund-Diashow der Anmeldeseite (ohne Anmeldung sichtbar)
 */
return [
    ['name' => 'wald',        'file' => 'wald.jpg',        'album' => 'nature', 'login' => true],
    ['name' => 'steg',        'file' => 'steg.jpg',        'album' => 'nature', 'login' => true],
    ['name' => 'baum',        'file' => 'baum.jpg',        'album' => 'nature', 'login' => true],
    ['name' => 'boot',        'file' => 'boot.jpg',        'album' => 'nature'],
    ['name' => 'garten',      'file' => 'garten.jpg',      'album' => 'home'],
    ['name' => 'rosen',       'file' => 'rosen.jpg',       'album' => 'home'],
    ['name' => 'gartentisch', 'file' => 'gartentisch.jpg', 'album' => 'home'],
    ['name' => 'fotoalbum',   'file' => 'fotoalbum.jpg',   'album' => 'home'],
    ['name' => 'fenster',     'file' => 'fenster.jpg',     'album' => 'home', 'portrait' => true],
    ['name' => 'hafen',       'file' => 'hafen.jpg',       'album' => 'travel'],
    ['name' => 'kueste',      'file' => 'kueste.jpg',      'album' => 'travel', 'login' => true],
];
