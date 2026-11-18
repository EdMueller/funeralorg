<?php
declare(strict_types=1);

/**
 * Portraitbilder der Gedenkseite: geordnete Liste `memorial.portraits` (Anzeigereihenfolge).
 * `memorial.portrait` bleibt als erstes Bild der Liste erhalten (Kompatibilität: Web-API, Beispieldaten, Backups);
 * fehlt `portraits` (älterer Stand), gilt `portrait` als einziges Bild.
 */
final class Portraits
{
    public const MAX = 12;

    /** Gespeicherte IDs in Reihenfolge, ohne Prüfung gegen die Galerie. */
    public static function rawIds(array $m): array
    {
        $ids = isset($m['portraits']) && is_array($m['portraits']) ? $m['portraits'] : [(string)($m['portrait'] ?? '')];
        return array_slice(array_values(array_unique(array_filter($ids, fn($i) => is_string($i) && $i !== ''))), 0, self::MAX);
    }

    /** Bilddatensätze in Reihenfolge; nicht (mehr) vorhandene Bilder fallen weg. */
    public static function images(array $m, array $galleryImages): array
    {
        $out = [];
        foreach (self::rawIds($m) as $id) {
            if ($img = find_by_id($galleryImages, $id)) {
                $out[] = $img;
            }
        }
        return $out;
    }

    /** Übernimmt eine (Roh-)Liste von IDs: nur vorhandene Bilder, ohne Doppelte, höchstens MAX; setzt auch `portrait`. */
    public static function set(array &$m, mixed $ids, array $galleryImages): void
    {
        $valid = [];
        foreach (is_array($ids) ? $ids : [] as $id) {
            if (is_string($id) && $id !== '' && !in_array($id, $valid, true) && find_by_id($galleryImages, $id)) {
                $valid[] = $id;
            }
        }
        $m['portraits'] = array_slice($valid, 0, self::MAX);
        $m['portrait'] = $m['portraits'][0] ?? '';
    }

    /** Entfernt gelöschte Bilder aus der Liste. */
    public static function remove(array &$m, array $goneIds): void
    {
        $left = array_values(array_diff(self::rawIds($m), $goneIds));
        $m['portraits'] = $left;
        $m['portrait'] = $left[0] ?? '';
    }

    /** Ist die Bild-ID ein Portrait (dann ohne Anmeldung sichtbar)? */
    public static function has(array $m, string $id): bool
    {
        return in_array($id, self::rawIds($m), true);
    }
}
