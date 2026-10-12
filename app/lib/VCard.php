<?php
declare(strict_types=1);

/**
 * vCard-Import (Versionen 2.1, 3.0, 4.0). Liefert Kontakte im internen Format.
 */
final class VCard
{
    public static function parse(string $raw): array
    {
        // BOM entfernen, Zeilenenden vereinheitlichen
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        // Zeilen entfalten: Folding (Leerzeichen/Tab) und QP-Softbreaks (Zeile endet mit '=')
        $lines = [];
        foreach (explode("\n", $raw) as $l) {
            $last = count($lines) - 1;
            if ($last >= 0 && ($l !== '' && ($l[0] === ' ' || $l[0] === "\t"))) {
                $lines[$last] .= substr($l, 1);
            } elseif ($last >= 0 && stripos($lines[$last], 'QUOTED-PRINTABLE') !== false
                && str_ends_with($lines[$last], '=')) {
                $lines[$last] = substr($lines[$last], 0, -1) . $l;
            } else {
                $lines[] = $l;
            }
        }

        $cards = [];
        $cur = null;
        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            if (strcasecmp($line, 'BEGIN:VCARD') === 0) {
                $cur = self::blank();
                continue;
            }
            if (strcasecmp($line, 'END:VCARD') === 0) {
                if ($cur !== null && ($cur['first'] !== '' || $cur['last'] !== '')) {
                    $cards[] = $cur;
                }
                $cur = null;
                continue;
            }
            if ($cur === null || !str_contains($line, ':')) {
                continue;
            }
            [$left, $value] = explode(':', $line, 2);
            $parts = explode(';', $left);
            $name = strtoupper(preg_replace('/^item\d+\./i', '', array_shift($parts)) ?? '');
            $params = strtoupper(implode(';', $parts));
            if (str_contains($params, 'QUOTED-PRINTABLE')) {
                $value = quoted_printable_decode($value);
                if (preg_match('/CHARSET=([A-Z0-9-]+)/', $params, $m) && $m[1] !== 'UTF-8') {
                    $value = mb_convert_encoding($value, 'UTF-8', $m[1]);
                }
            }
            switch ($name) {
                case 'N':
                    $n = self::split($value);
                    $cur['last'] = $n[0] ?? '';
                    $cur['first'] = trim(($n[1] ?? '') . ' ' . ($n[2] ?? ''));
                    if (!empty($n[3])) {
                        $cur['salutation'] = $n[3];
                    }
                    break;
                case 'FN':
                    $cur['_fn'] = self::unescape($value);
                    break;
                case 'ADR':
                    if ($cur['street'] !== '') {
                        break; // erste Adresse gewinnt
                    }
                    $a = self::split($value);
                    $cur['street'] = trim(($a[2] ?? '') . (!empty($a[1]) ? ', ' . $a[1] : ''));
                    $cur['city'] = $a[3] ?? '';
                    $cur['state'] = $a[4] ?? '';
                    $cur['zip'] = $a[5] ?? '';
                    $cur['country'] = $a[6] ?? '';
                    break;
                case 'TEL':
                    $num = trim(self::unescape(preg_replace('/^tel:/i', '', $value) ?? ''));
                    if (str_contains($params, 'CELL')) {
                        $cur['mobile'] = $cur['mobile'] ?: $num;
                    } elseif (str_contains($params, 'FAX')) {
                        break;
                    } else {
                        $cur['phone'] = $cur['phone'] ?: $num;
                    }
                    break;
                case 'EMAIL':
                    $cur['email'] = $cur['email'] ?: trim(self::unescape($value));
                    break;
                case 'NOTE':
                    $cur['note'] = self::unescape($value);
                    break;
            }
            // Fallback: FN verwenden, wenn N leer ist
            if ($name === 'FN' && $cur['first'] === '' && $cur['last'] === '') {
                $p = preg_split('/\s+/', $cur['_fn']) ?: [];
                $cur['last'] = count($p) > 1 ? array_pop($p) : ($p[0] ?? '');
                $cur['first'] = implode(' ', $p);
            }
        }
        foreach ($cards as &$c) {
            unset($c['_fn']);
            // Mobilnummer ohne TYPE erkennen (deutsche Mobilvorwahlen)
            if ($c['mobile'] === '' && preg_match('/^(\+49\s?|0049\s?|0)1[5-7]/', preg_replace('/[\s\/()-]/', '', $c['phone']) ?? '')) {
                $c['mobile'] = $c['phone'];
                $c['phone'] = '';
            }
        }
        return $cards;
    }

    private static function blank(): array
    {
        return ['salutation' => '', 'first' => '', 'last' => '', 'street' => '', 'zip' => '', 'city' => '', 'state' => '',
                'country' => '', 'phone' => '', 'mobile' => '', 'email' => '', 'note' => '', '_fn' => ''];
    }

    private static function split(string $v): array
    {
        $parts = preg_split('/(?<!\\\\);/', $v) ?: [];
        return array_map(fn($p) => trim(self::unescape($p)), $parts);
    }

    private static function unescape(string $v): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $v);
    }
}
