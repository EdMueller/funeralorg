<?php
declare(strict_types=1);

/**
 * Album-Hierarchie. Jedes Album hat ein Heimat-Elternalbum (`parent`, leer = Wurzel) und beliebig viele
 * Verweise (`links`) als zusätzliche Eltern. Bilder liegen im Heimatalbum (`album`) und können in weiteren
 * Alben (`links`) erscheinen. Alle Durchläufe sind schleifensicher (besuchte IDs).
 */
final class Albums
{
    /** Auswählbare Farben für Alben (Hex-Wert; Name: t('color.<hex ohne #>')). */
    public const COLORS = ['#4a5d4f', '#9c8558', '#2f6f9f', '#b0443a', '#7a4f9a', '#c27a2c', '#1f8a8a', '#6b7174'];

    public static function colorName(string $hex): string
    {
        return t('color.' . ltrim($hex, '#'));
    }

    /** Direkte Unteralben (Heimat und Verweise), nach `sort`. */
    public static function children(array $albums, string $id): array
    {
        $out = [];
        foreach ($albums as $al) {
            if ($al['id'] !== $id && (($al['parent'] ?? '') === $id || in_array($id, $al['links'] ?? [], true))) {
                $out[] = $al;
            }
        }
        usort($out, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));
        return $out;
    }

    /** ID des Albums und aller Nachfahren. */
    public static function subtree(array $albums, string $id): array
    {
        $seen = [$id => true];
        $queue = [$id];
        while ($queue) {
            foreach (self::children($albums, array_shift($queue)) as $c) {
                if (!isset($seen[$c['id']])) {
                    $seen[$c['id']] = true;
                    $queue[] = $c['id'];
                }
            }
        }
        return array_map('strval', array_keys($seen));
    }

    /** Wurzelalben: ohne Heimat-Elternalbum oder mit nicht mehr vorhandenem. */
    public static function roots(array $albums): array
    {
        $ids = array_column($albums, 'id');
        $out = array_values(array_filter($albums,
            fn($al) => ($al['parent'] ?? '') === '' || !in_array($al['parent'], $ids, true)));
        usort($out, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));
        return $out;
    }

    /** Tiefensuche ab `$rootId` (leer = ganzer Baum): [album, depth, path (Namen inkl. des Albums selbst)]. */
    public static function walk(array $albums, string $rootId = ''): array
    {
        $out = [];
        $seen = [];
        $visit = function (array $al, int $depth, array $path) use (&$visit, &$out, &$seen, $albums): void {
            if (isset($seen[$al['id']])) {
                return;
            }
            $seen[$al['id']] = true;
            $path[] = $al['name'];
            $out[] = ['album' => $al, 'depth' => $depth, 'path' => $path];
            foreach (self::children($albums, $al['id']) as $c) {
                $visit($c, $depth + 1, $path);
            }
        };
        if ($rootId === '') {
            foreach (self::roots($albums) as $al) {
                $visit($al, 0, []);
            }
            $rest = $albums;
            usort($rest, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));
            foreach ($rest as $al) { // Reste (defekte Daten mit Schleife) nicht verlieren
                $visit($al, 0, []);
            }
        } elseif ($al = find_by_id($albums, $rootId)) {
            $visit($al, 0, []);
        }
        return $out;
    }

    /** Pfad über die Heimat-Elternalben, Wurzel zuerst, Album selbst zuletzt. */
    public static function trail(array $albums, string $id): array
    {
        $out = [];
        $seen = [];
        while ($id !== '' && !isset($seen[$id]) && ($al = find_by_id($albums, $id))) {
            $seen[$id] = true;
            array_unshift($out, $al);
            $id = (string)($al['parent'] ?? '');
        }
        return $out;
    }

    /** Wert (`cat`/`color`) des Albums oder, wenn leer, des nächsten Heimat-Vorfahren mit Wert. */
    public static function inherit(array $albums, string $id, string $key): string
    {
        foreach (array_reverse(self::trail($albums, $id)) as $al) {
            if (($al[$key] ?? '') !== '') {
                return (string)$al[$key];
            }
        }
        return '';
    }

    /** IDs der versteckten Alben: selbst versteckt oder mit verstecktem Heimat-Vorfahren. */
    public static function hiddenIds(array $albums): array
    {
        $out = [];
        foreach ($albums as $al) {
            foreach (self::trail($albums, $al['id']) as $t) {
                if (!empty($t['hidden'])) {
                    $out[] = (string)$al['id'];
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Galerie-Daten aus Sicht des angemeldeten Benutzers: Admins sehen alles, alle anderen weder versteckte Alben
     * noch Bilder, die nur in versteckten Alben erscheinen. Ein Bild mit sichtbarem Verweis bleibt sichtbar;
     * sein Heimatalbum ist dann das erste sichtbare.
     */
    public static function forViewer(array $g): array
    {
        if (Auth::isAdmin()) {
            return $g;
        }
        $hidden = self::hiddenIds($g['albums']);
        if (!$hidden) {
            return $g;
        }
        $g['albums'] = array_values(array_filter($g['albums'], fn($al) => !in_array($al['id'], $hidden, true)));
        $images = [];
        foreach ($g['images'] as $i) {
            $all = self::ofImage($i);
            $vis = array_values(array_diff($all, $hidden));
            if ($all && !$vis) {
                continue;
            }
            if ($all) {
                $i['album'] = $vis[0];
                $i['links'] = array_slice($vis, 1);
            }
            $images[] = $i;
        }
        $g['images'] = $images;
        return $g;
    }

    /** Alben, in denen das Bild erscheint: Heimat zuerst, dann Verweise. */
    public static function ofImage(array $img): array
    {
        return array_values(array_unique(array_filter(
            array_merge([(string)($img['album'] ?? '')], $img['links'] ?? []),
            fn($x) => $x !== ''
        )));
    }

    /** Bilder, die in einem der Alben `$ids` erscheinen (Reihenfolge bleibt). */
    public static function images(array $images, array $ids): array
    {
        return array_values(array_filter($images, fn($i) => array_intersect(self::ofImage($i), $ids) !== []));
    }

    /**
     * Bilder in der Reihenfolge dieses Albums (`$key` = Album-ID oder `_none`): erst die manuell geordneten
     * (`ord[$key]`), danach die übrigen in bisheriger Reihenfolge (neue Uploads landen am Ende).
     */
    public static function sortIn(array $images, string $key): array
    {
        usort($images, fn($a, $b) => ($a['ord'][$key] ?? PHP_INT_MAX) <=> ($b['ord'][$key] ?? PHP_INT_MAX));
        return $images;
    }

    /** Verweisliste bereinigen: nur vorhandene Alben, ohne Duplikate, ohne `$exclude`. */
    public static function cleanLinks(array $albums, mixed $raw, array $exclude = []): array
    {
        $valid = array_column($albums, 'id');
        $out = [];
        foreach ((array)$raw as $x) {
            $x = (string)$x;
            if (in_array($x, $valid, true) && !in_array($x, $exclude, true) && !in_array($x, $out, true)) {
                $out[] = $x;
            }
        }
        return $out;
    }
}
