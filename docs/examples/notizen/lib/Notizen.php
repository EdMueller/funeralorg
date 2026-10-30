<?php
declare(strict_types=1);

/** Fachlogik des Plugins: Klassenname = Dateiname, Präfix verhindert Kollisionen mit Kern und anderen Plugins. */
final class Notizen
{
    public const MAX_LENGTH = 500;

    /** Eingabe säubern: Leerraum kürzen, Länge begrenzen. Leer = ungültig. */
    public static function clean(string $text): string
    {
        return mb_substr(trim($text), 0, self::MAX_LENGTH);
    }

    /** Neueste zuerst. @return list<array{id:string, text:string, by:string, created:string}> */
    public static function all(): array
    {
        $list = Store::read('notizen');
        usort($list, fn($a, $b) => strcmp($b['created'], $a['created']));
        return $list;
    }
}
