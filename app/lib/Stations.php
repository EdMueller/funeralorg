<?php
declare(strict_types=1);

/**
 * Lebensstationen: Zeitstrahl + Weltkarte.
 * Daten: stations.json (Liste). Geburt und Tod sind feste Stationen (type birth|death); ihr Datum
 * wird bei jeder Anzeige aus den Freitexten der Gedenkseite (born/died) gelesen.
 * Kategorie und Farbe kommen vom verknüpften Album (cat, color).
 */
final class Stations
{
    /** Auswählbare Farben für Alben (Hex-Wert; Name: t('color.<hex ohne #>')). */
    public const COLORS = ['#4a5d4f', '#9c8558', '#2f6f9f', '#b0443a', '#7a4f9a', '#c27a2c', '#1f8a8a', '#6b7174'];

    public static function colorName(string $hex): string
    {
        return t('color.' . ltrim($hex, '#'));
    }
    public const NEUTRAL = '#6b7174';

    /**
     * Liest ein Datum aus Freitext und liefert ISO-Teilformat (JJJJ-MM-TT, JJJJ-MM oder JJJJ) oder ''.
     * Erkannt: 12.03.1941, 12. März 1941, 3/1941, 03.1941, 1941-03-12, März 1941, 1941.
     */
    public static function parseDate(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        if (preg_match('/(?<!\d)(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)/', $s, $m)) {
            return self::iso((int)$m[1], (int)$m[2], (int)$m[3]);
        }
        if (preg_match('/(?<!\d)(\d{1,2})\s*\.\s*(\d{1,2})\s*\.\s*(\d{4})(?!\d)/', $s, $m)) {
            return self::iso((int)$m[3], (int)$m[2], (int)$m[1]);
        }
        if (preg_match('/(?<!\d)(\d{1,2})\s*[.\/]\s*(\d{4})(?!\d)/', $s, $m)) {
            return self::iso((int)$m[2], (int)$m[1]);
        }
        $mon = self::monthIn($s);
        if ($mon > 0 && preg_match('/(?<!\d)(\d{4})(?!\d)/', $s, $y)) {
            $day = preg_match('/(?<!\d)(\d{1,2})\s*\.?\s*[\p{L}]+\.?\s+\d{4}/u', $s, $d) ? (int)$d[1] : 0;
            return self::iso((int)$y[1], $mon, $day);
        }
        if (preg_match('/(?<!\d)(1[5-9]\d\d|2[01]\d\d)(?!\d)/', $s, $y)) {
            return self::iso((int)$y[1]);
        }
        return '';
    }

    private static function monthIn(string $s): int
    {
        $t = mb_strtolower($s);
        $names = [
            1 => ['januar', 'jan', 'january'], 2 => ['februar', 'feb', 'february'], 3 => ['märz', 'maerz', 'mär', 'mrz', 'march', 'mar'],
            4 => ['april', 'apr'], 5 => ['mai', 'may'], 6 => ['juni', 'jun', 'june'], 7 => ['juli', 'jul', 'july'],
            8 => ['august', 'aug'], 9 => ['september', 'sept', 'sep'], 10 => ['oktober', 'okt', 'october', 'oct'],
            11 => ['november', 'nov'], 12 => ['dezember', 'dez', 'december', 'dec'],
        ];
        foreach ($names as $n => $list) {
            foreach ($list as $word) {
                if (preg_match('/(?<![\p{L}])' . preg_quote($word, '/') . '(?![\p{L}])/u', $t)) {
                    return $n;
                }
            }
        }
        return 0;
    }

    private static function iso(int $y, int $m = 0, int $d = 0): string
    {
        if ($y < 1000 || $y > 2200) {
            return '';
        }
        if ($m < 1 || $m > 12) {
            return sprintf('%04d', $y);
        }
        if ($d < 1 || $d > cal_days_in_month(CAL_GREGORIAN, $m, $y)) {
            return sprintf('%04d-%02d', $y, $m);
        }
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /** ISO-Teilformat → Anzeige in der aktiven Sprache ("12.03.1941" / "1941-03-12", "März 1941", "1941"). */
    public static function fmt(string $iso): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return t('st.fmt_dmy', ['d' => $m[3], 'm' => $m[2], 'y' => $m[1]]);
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $iso, $m)) {
            return t('st.fmt_my', ['month' => t('st.month.' . (int)$m[2]), 'year' => $m[1]]);
        }
        return $iso;
    }

    /** Zeitpunkt (Unix) für die Position im Zeitstrahl; ungenaue Angaben liegen in der Mitte ihres Zeitraums. */
    public static function ts(string $iso): ?int
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return gmmktime(12, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $iso, $m)) {
            return gmmktime(12, 0, 0, (int)$m[2], 15, (int)$m[1]);
        }
        if (preg_match('/^(\d{4})$/', $iso, $m)) {
            return gmmktime(12, 0, 0, 7, 1, (int)$m[1]);
        }
        return null;
    }

    /**
     * Alle Stationen inklusive der festen Stationen Geburt und Tod (falls noch nicht gespeichert, leer).
     * Das Datum von Geburt/Tod stammt immer aus der Gedenkseite.
     */
    public static function all(): array
    {
        $list = Store::read('stations');
        $m = Store::read('memorial');
        $blank = ['title' => '', 'date' => '', 'place' => '', 'lat' => null, 'lon' => null, 'text' => '',
            'image' => '', 'album' => ''];
        $have = array_column($list, 'type');
        foreach (['birth', 'death'] as $type) {
            if (!in_array($type, $have, true)) {
                $list[] = ['id' => $type, 'type' => $type] + $blank;
            }
        }
        foreach ($list as &$s) {
            $s += ['type' => '', 'lat' => null, 'lon' => null, 'album' => '', 'image' => '', 'text' => '', 'place' => ''];
            if ($s['type'] === 'birth' || $s['type'] === 'death') {
                $s['title'] = t($s['type'] === 'birth' ? 'st.birth' : 'st.death');
                $s['date'] = self::parseDate((string)($m[$s['type'] === 'birth' ? 'born' : 'died'] ?? ''));
            }
        }
        unset($s);
        usort($list, function ($a, $b) {
            return [self::ts($a['date']) ?? ($a['type'] === 'death' ? PHP_INT_MAX : PHP_INT_MIN), $a['title']]
                <=> [self::ts($b['date']) ?? ($b['type'] === 'death' ? PHP_INT_MAX : PHP_INT_MIN), $b['title']];
        });
        return $list;
    }

    /** Farbe und Kategorie einer Station aus ihrem Album (leere Werte erbt es vom übergeordneten Album). */
    public static function look(array $s, array $albums): array
    {
        $al = $s['album'] !== '' ? find_by_id($albums, $s['album']) : null;
        $color = $al ? Albums::inherit($albums, $al['id'], 'color') : '';
        if (!in_array($color, self::COLORS, true)) {
            $color = self::NEUTRAL;
        }
        $cat = $al ? (Albums::inherit($albums, $al['id'], 'cat') ?: $al['name']) : t('gal.no_album');
        return ['color' => $color, 'cat' => $cat];
    }
}
