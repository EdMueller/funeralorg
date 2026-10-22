<?php
declare(strict_types=1);

/** Wählbare Portraitrahmen der Gedenkseite (Schlüssel = CSS-Klasse `frame-<schlüssel>`, Text `memorial.frame.<schlüssel>`). */
final class Frame
{
    public const DEFAULT = 'oval';
    public const KEYS = ['oval', 'round', 'rect', 'passepartout', 'wood', 'antique', 'baroque', 'mourning'];

    /** Gibt einen gültigen Schlüssel zurück; unbekannte oder fehlende Werte fallen auf den Standard zurück. */
    public static function key(mixed $v): string
    {
        return is_string($v) && in_array($v, self::KEYS, true) ? $v : self::DEFAULT;
    }
}
