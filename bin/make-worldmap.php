<?php
declare(strict_types=1);

/**
 * Erzeugt assets/vendor/worldmap/world.svg aus Natural Earth (Admin 0 – Countries, GeoJSON).
 * Projektion: Winkel Tripel (φ1 = arccos(2/π)), Ausschnitt 84°N bis 58°S, ohne Antarktis.
 *
 * Aufruf: php bin/make-worldmap.php <ne_50m_admin_0_countries.geojson> [ausgabe.svg]
 * Quelle: https://github.com/nvkelso/natural-earth-vector (geojson/ne_50m_admin_0_countries.geojson)
 *
 * Die Ausgabe nennt die Konstanten, die plugins/stations/assets/js/stations.js (WT) und
 * plugins/stations/assets/css/stations.css (aspect-ratio) übernehmen müssen.
 */
const LAT_N = 84.0;
const LAT_S = -58.0;
const WIDTH = 400.0;       // viewBox-Breite
const TOLERANCE = 0.18;    // Douglas-Peucker in viewBox-Einheiten
const SKIP = ['ATA'];      // Antarktis liegt außerhalb des Ausschnitts

/** @return array{0: float, 1: float} Winkel Tripel in Einheitskugel-Koordinaten (y nach oben) */
function wt(float $latDeg, float $lonDeg): array
{
    $phi = deg2rad($latDeg);
    $lam = deg2rad($lonDeg);
    $cos1 = 2 / M_PI;
    $a = acos(cos($phi) * cos($lam / 2));
    $sinc = $a < 1e-9 ? 1.0 : sin($a) / $a;
    return [
        0.5 * ($lam * $cos1 + 2 * cos($phi) * sin($lam / 2) / $sinc),
        0.5 * ($phi + sin($phi) / $sinc),
    ];
}

/** Punkte entlang einer Kante dichter setzen, damit Kurven der Projektion erhalten bleiben. */
function densify(array $ring, float $step = 1.5): array
{
    $out = [];
    $n = count($ring);
    for ($i = 0; $i < $n - 1; $i++) {
        [$x0, $y0] = $ring[$i];
        [$x1, $y1] = $ring[$i + 1];
        $parts = max(1, (int)ceil(max(abs($x1 - $x0), abs($y1 - $y0)) / $step));
        for ($k = 0; $k < $parts; $k++) {
            $out[] = [$x0 + ($x1 - $x0) * $k / $parts, $y0 + ($y1 - $y0) * $k / $parts];
        }
    }
    $out[] = $ring[$n - 1];
    return $out;
}

function dp(array $p, float $tol): array
{
    $n = count($p);
    if ($n < 3) {
        return $p;
    }
    $keep = array_fill(0, $n, false);
    $keep[0] = $keep[$n - 1] = true;
    $stack = [[0, $n - 1]];
    while ($stack) {
        [$a, $b] = array_pop($stack);
        $dmax = 0.0;
        $idx = -1;
        [$ax, $ay] = $p[$a];
        [$bx, $by] = $p[$b];
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $len = sqrt($dx * $dx + $dy * $dy);
        for ($i = $a + 1; $i < $b; $i++) {
            $d = $len < 1e-12
                ? hypot($p[$i][0] - $ax, $p[$i][1] - $ay)
                : abs($dy * $p[$i][0] - $dx * $p[$i][1] + $bx * $ay - $by * $ax) / $len;
            if ($d > $dmax) {
                $dmax = $d;
                $idx = $i;
            }
        }
        if ($dmax > $tol && $idx > 0) {
            $keep[$idx] = true;
            $stack[] = [$a, $idx];
            $stack[] = [$idx, $b];
        }
    }
    $r = [];
    foreach ($p as $i => $pt) {
        if ($keep[$i]) {
            $r[] = $pt;
        }
    }
    return $r;
}

function num(float $v): string
{
    $s = rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    return $s === '-0' || $s === '' ? '0' : $s;
}

function pathOf(array $pts, bool $close): string
{
    $d = '';
    foreach ($pts as $i => [$x, $y]) {
        $d .= ($i === 0 ? 'M' : 'L') . num($x) . ' ' . num($y);
    }
    return $d . ($close ? 'Z' : '');
}

$in = $argv[1] ?? '';
$outFile = $argv[2] ?? dirname(__DIR__) . '/assets/vendor/worldmap/world.svg';
if (!is_file($in)) {
    fwrite(STDERR, "Aufruf: php bin/make-worldmap.php <ne_50m_admin_0_countries.geojson> [ausgabe.svg]\n");
    exit(1);
}
$geo = json_decode((string)file_get_contents($in), true, 512, JSON_THROW_ON_ERROR);

// Umriss der Karte (Meridiane ±180°, Parallelen 84°N / 58°S) und Wertebereich
$outline = [];
for ($lat = LAT_N; $lat >= LAT_S; $lat -= 0.5) {
    $outline[] = [180.0, $lat];
}
for ($lon = 180.0; $lon >= -180; $lon -= 1.0) {
    $outline[] = [$lon, LAT_S];
}
for ($lat = LAT_S; $lat <= LAT_N; $lat += 0.5) {
    $outline[] = [-180.0, $lat];
}
for ($lon = -180.0; $lon <= 180; $lon += 1.0) {
    $outline[] = [$lon, LAT_N];
}
$proj = [];
foreach ($outline as [$lon, $lat]) {
    $proj[] = wt($lat, $lon);
}
$xs = array_column($proj, 0);
$ys = array_column($proj, 1);
$x0 = min($xs);
$y0 = max($ys);
$xs_ = max($xs) - $x0;
$ys_ = $y0 - min($ys);
$scale = WIDTH / $xs_;
$height = round($ys_ * $scale, 3);
$toSvg = fn(array $p): array => [($p[0] - $x0) * $scale, ($y0 - $p[1]) * $scale];

$sea = pathOf(dp(array_map($toSvg, $proj), 0.05), true);

// Gradnetz alle 30°
$grid = '';
for ($lon = -150; $lon <= 150; $lon += 30) {
    $pts = [];
    for ($lat = LAT_S; $lat <= LAT_N; $lat += 2) {
        $pts[] = $toSvg(wt($lat, (float)$lon));
    }
    $grid .= pathOf(dp($pts, 0.05), false);
}
for ($lat = -30; $lat <= 60; $lat += 30) {
    $pts = [];
    for ($lon = -180; $lon <= 180; $lon += 5) {
        $pts[] = $toSvg(wt((float)$lat, (float)$lon));
    }
    $grid .= pathOf(dp($pts, 0.05), false);
}

// Länder: nur Außenringe
$land = '';
$rings = 0;
foreach ($geo['features'] as $f) {
    $code = $f['properties']['ADM0_A3'] ?? ($f['properties']['ISO_A3'] ?? '');
    if (in_array($code, SKIP, true) || empty($f['geometry'])) {
        continue;
    }
    $g = $f['geometry'];
    $polys = $g['type'] === 'Polygon' ? [$g['coordinates']] : ($g['type'] === 'MultiPolygon' ? $g['coordinates'] : []);
    foreach ($polys as $poly) {
        $pts = [];
        foreach (densify($poly[0]) as [$lon, $lat]) {
            $pts[] = $toSvg(wt(max(LAT_S, min(LAT_N, (float)$lat)), (float)$lon));
        }
        $pts = dp($pts, TOLERANCE);
        if (count($pts) < 4) {
            continue;
        }
        $land .= pathOf($pts, true);
        $rings++;
    }
}

$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . num(WIDTH) . ' ' . $height . '" preserveAspectRatio="none">'
    . '<path d="' . $sea . '" fill="#dde6e8"/>'
    . '<path d="' . $grid . '" fill="none" stroke="#fff" stroke-opacity=".55" stroke-width=".25"/>'
    . '<path d="' . $land . '" fill="#e3e5dd" stroke="#fbfbf9" stroke-width=".3" stroke-linejoin="round"/>'
    . '</svg>';
file_put_contents($outFile, $svg);

printf("%s: %d Ringe, %.1f KB\n", $outFile, $rings, strlen($svg) / 1024);
printf("viewBox: 0 0 %s %s  (aspect-ratio: %s / %s)\n", num(WIDTH), $height, num(WIDTH), $height);
printf("JS: X0 = %.6f, Y0 = %.6f, XS = %.6f, YS = %.6f\n", $x0, $y0, $xs_, $ys_);
