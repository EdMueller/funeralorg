<?php
declare(strict_types=1);

/**
 * Minimaler QR-Code-Generator (Byte-Modus, Fehlerkorrektur M, Version 1–10, bis ca. 210 Bytes).
 * Gibt SVG aus – ohne Abhängigkeiten, druckscharf. Aufbau nach ISO/IEC 18004.
 */
final class Qr
{
    /** Version => [ECC-Bytes je Block, [[Blockanzahl, Datenbytes je Block], …]] für Stufe M. */
    private const BLOCKS = [
        1  => [10, [[1, 16]]],
        2  => [16, [[1, 28]]],
        3  => [26, [[1, 44]]],
        4  => [18, [[2, 32]]],
        5  => [24, [[2, 43]]],
        6  => [16, [[4, 27]]],
        7  => [18, [[4, 31]]],
        8  => [22, [[2, 38], [2, 39]]],
        9  => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];
    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** @return list<list<bool>> Modulmatrix [Zeile][Spalte] (true = dunkel) */
    public static function matrix(string $text): array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        [$ver, $data] = self::encodeData($bytes);
        $words = self::interleave($ver, $data);
        $size = 17 + 4 * $ver;

        $mod = array_fill(0, $size, array_fill(0, $size, false));
        $fn = array_fill(0, $size, array_fill(0, $size, false));
        self::drawFunctions($mod, $fn, $ver, $size);
        self::drawCodewords($mod, $fn, $words, $size);

        $best = null;
        $bestPenalty = PHP_INT_MAX;
        for ($m = 0; $m < 8; $m++) {
            $try = $mod;
            self::applyMask($try, $fn, $m, $size);
            self::drawFormat($try, $m, $size);
            $p = self::penalty($try, $size);
            if ($p < $bestPenalty) {
                $bestPenalty = $p;
                $best = $try;
            }
        }
        return $best;
    }

    /** SVG mit Ruhezone (4 Module); Größe über CSS/width. */
    public static function svg(string $text, string $label = 'QR-Code'): string
    {
        $m = self::matrix($text);
        $n = count($m);
        $q = 4;
        $path = '';
        for ($y = 0; $y < $n; $y++) {
            $x = 0;
            while ($x < $n) {
                if ($m[$y][$x]) {
                    $s = $x;
                    while ($x < $n && $m[$y][$x]) {
                        $x++;
                    }
                    $path .= 'M' . ($s + $q) . ' ' . ($y + $q) . 'h' . ($x - $s) . 'v1h-' . ($x - $s) . 'z';
                } else {
                    $x++;
                }
            }
        }
        $t = $n + 2 * $q;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $t . ' ' . $t . '" role="img" aria-label="'
            . htmlspecialchars($label, ENT_QUOTES) . '" shape-rendering="crispEdges">'
            . '<rect width="' . $t . '" height="' . $t . '" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    /* ---------------------------------------------------------- Daten */

    /** @return array{0:int,1:list<int>} Version, Datenbytes (auf Kapazität aufgefüllt) */
    private static function encodeData(array $bytes): array
    {
        $len = count($bytes);
        foreach (self::BLOCKS as $ver => [, $groups]) {
            $cap = 0;
            foreach ($groups as [$cnt, $dc]) {
                $cap += $cnt * $dc;
            }
            $countBits = $ver >= 10 ? 16 : 8;
            if (4 + $countBits + 8 * $len <= 8 * $cap) {
                break;
            }
            $ver = 0;
        }
        if (!$ver) {
            throw new InvalidArgumentException('Text für den QR-Code zu lang (' . $len . ' Bytes).');
        }
        $bits = [];
        $put = function (int $v, int $n) use (&$bits): void {
            for ($i = $n - 1; $i >= 0; $i--) {
                $bits[] = ($v >> $i) & 1;
            }
        };
        $put(0b0100, 4);
        $put($len, $countBits);
        foreach ($bytes as $b) {
            $put($b, 8);
        }
        $put(0, min(4, 8 * $cap - count($bits)));
        while (count($bits) % 8) {
            $bits[] = 0;
        }
        $out = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $out[] = bindec(implode('', array_slice($bits, $i, 8)));
        }
        for ($pad = 0xEC; count($out) < $cap; $pad ^= 0xEC ^ 0x11) {
            $out[] = $pad;
        }
        return [$ver, $out];
    }

    private static function interleave(int $ver, array $data): array
    {
        [$eccLen, $groups] = self::BLOCKS[$ver];
        $div = self::rsDivisor($eccLen);
        $blocks = [];
        $pos = 0;
        foreach ($groups as [$cnt, $dc]) {
            for ($i = 0; $i < $cnt; $i++) {
                $d = array_slice($data, $pos, $dc);
                $pos += $dc;
                $blocks[] = [$d, self::rsRemainder($d, $div)];
            }
        }
        $out = [];
        $maxData = max(array_map(fn($b) => count($b[0]), $blocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as [$d]) {
                if ($i < count($d)) {
                    $out[] = $d[$i];
                }
            }
        }
        for ($i = 0; $i < $eccLen; $i++) {
            foreach ($blocks as [, $e]) {
                $out[] = $e[$i];
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------- Reed-Solomon */

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    private static function rsDivisor(int $degree): array
    {
        $r = array_fill(0, $degree, 0);
        $r[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $r[$j] = self::gfMul($r[$j], $root);
                if ($j + 1 < $degree) {
                    $r[$j] ^= $r[$j + 1];
                }
            }
            $root = self::gfMul($root, 0x02);
        }
        return $r;
    }

    private static function rsRemainder(array $data, array $div): array
    {
        $r = array_fill(0, count($div), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($r);
            $r[] = 0;
            foreach ($div as $i => $c) {
                $r[$i] ^= self::gfMul($c, $factor);
            }
        }
        return $r;
    }

    /* ---------------------------------------------------------- Matrix */

    private static function setFn(array &$mod, array &$fn, int $x, int $y, bool $dark): void
    {
        $mod[$y][$x] = $dark;
        $fn[$y][$x] = true;
    }

    private static function drawFunctions(array &$mod, array &$fn, int $ver, int $size): void
    {
        for ($i = 0; $i < $size; $i++) {
            self::setFn($mod, $fn, 6, $i, $i % 2 === 0);
            self::setFn($mod, $fn, $i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                        $d = max(abs($dx), abs($dy));
                        self::setFn($mod, $fn, $x, $y, $d !== 2 && $d !== 4);
                    }
                }
            }
        }
        $pos = self::ALIGN[$ver];
        $n = count($pos);
        foreach ($pos as $i => $px) {
            foreach ($pos as $j => $py) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        self::setFn($mod, $fn, $px + $dx, $py + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        self::drawFormat($mod, 0, $size, $fn);
        if ($ver >= 7) {
            $rem = $ver;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($ver << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                self::setFn($mod, $fn, $a, $b, $bit);
                self::setFn($mod, $fn, $b, $a, $bit);
            }
        }
    }

    /** Formatinformation (Stufe M, Maske $mask); mit $fn als Funktionsbereich markieren. */
    private static function drawFormat(array &$mod, int $mask, int $size, ?array &$fn = null): void
    {
        $data = (0 << 3) | $mask; // Stufe M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $set = function (int $x, int $y, bool $d) use (&$mod, &$fn): void {
            $mod[$y][$x] = $d;
            if ($fn !== null) {
                $fn[$y][$x] = true;
            }
        };
        $bit = fn(int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $set(8, $i, $bit($i));
        }
        $set(8, 7, $bit(6));
        $set(8, 8, $bit(7));
        $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $set($size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $set(8, $size - 15 + $i, $bit($i));
        }
        $set(8, $size - 8, true);
    }

    private static function drawCodewords(array &$mod, array $fn, array $words, int $size): void
    {
        $i = 0;
        $total = count($words) * 8;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $up = (($right + 1) & 2) === 0;
                    $y = $up ? $size - 1 - $vert : $vert;
                    if (!$fn[$y][$x] && $i < $total) {
                        $mod[$y][$x] = (($words[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private static function applyMask(array &$mod, array $fn, int $m, int $size): void
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($fn[$y][$x]) {
                    continue;
                }
                $inv = match ($m) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($inv) {
                    $mod[$y][$x] = !$mod[$y][$x];
                }
            }
        }
    }

    private static function penalty(array $m, int $size): int
    {
        $p = 0;
        // Regel 1 (Reihen/Spalten gleicher Farbe) und 3 (Finder-ähnliche Muster)
        $pat = [true, false, true, true, true, false, true];
        for ($dir = 0; $dir < 2; $dir++) {
            for ($a = 0; $a < $size; $a++) {
                $line = [];
                for ($b = 0; $b < $size; $b++) {
                    $line[] = $dir === 0 ? $m[$a][$b] : $m[$b][$a];
                }
                $run = 1;
                for ($b = 1; $b <= $size; $b++) {
                    if ($b < $size && $line[$b] === $line[$b - 1]) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $p += 3 + $run - 5;
                        }
                        $run = 1;
                    }
                }
                for ($b = 0; $b + 7 <= $size; $b++) {
                    if (array_slice($line, $b, 7) === $pat) {
                        $light = fn(int $s, int $e): bool => $s >= 0 && $e <= $size
                            && count(array_filter(array_slice($line, $s, $e - $s))) === 0;
                        if ($light($b - 4, $b) || $light($b + 7, $b + 11)) {
                            $p += 40;
                        }
                    }
                }
            }
        }
        // Regel 2 (2×2-Blöcke)
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $p += 3;
                }
            }
        }
        // Regel 4 (Anteil dunkler Module)
        $dark = 0;
        foreach ($m as $row) {
            $dark += count(array_filter($row));
        }
        $k = (int)ceil(abs($dark * 20 - $size * $size * 10) / ($size * $size)) - 1;
        return $p + max(0, $k) * 10;
    }
}
