<?php
declare(strict_types=1);

/**
 * Prüft die Sprachdateien: gleiche Schlüssel in allen Sprachen, gleiche Platzhalter,
 * Pluralformen vollständig, und jeder im Code verwendete feste Schlüssel existiert.
 * Aufruf: php bin/check-lang.php   (Exit-Code 1 bei Fehlern)
 */
$root = dirname(__DIR__);
$langs = array_map('basename', glob($root . '/app/lang/*', GLOB_ONLYDIR) ?: []);
$msgs = [];
foreach ($langs as $l) {
    $msgs[$l] = [];
    foreach (glob($root . '/app/lang/' . $l . '/*.php') ?: [] as $f) {
        foreach (require $f as $k => $v) {
            if (isset($msgs[$l][$k])) {
                echo "doppelt in $l: $k\n";
            }
            $msgs[$l][$k] = $v;
        }
    }
}
$errors = 0;
$base = $msgs['de'];
foreach ($langs as $l) {
    foreach (array_diff_key($base, $msgs[$l]) as $k => $_) {
        echo "fehlt in $l: $k\n";
        $errors++;
    }
    foreach (array_diff_key($msgs[$l], $base) as $k => $_) {
        echo "nur in $l: $k\n";
        $errors++;
    }
}
$ph = fn($v) => (function () use ($v) {
    $out = [];
    foreach ((array)$v as $s) {
        preg_match_all('/\{(\w+)\}/', (string)$s, $m);
        $out = array_merge($out, $m[1]);
    }
    $out = array_unique($out);
    sort($out);
    return $out;
})();
foreach ($base as $k => $v) {
    foreach ($langs as $l) {
        if (!isset($msgs[$l][$k])) {
            continue;
        }
        if (is_array($v) !== is_array($msgs[$l][$k])) {
            echo "Plural-Typ unterschiedlich ($l): $k\n";
            $errors++;
        } elseif (is_array($v) && (!isset($msgs[$l][$k]['one'], $msgs[$l][$k]['other']))) {
            echo "Pluralform one/other fehlt ($l): $k\n";
            $errors++;
        }
        $a = array_diff($ph($v), ['n']);
        $b = array_diff($ph($msgs[$l][$k]), ['n']);
        if ($a !== $b && !is_array($v)) {
            echo "Platzhalter unterschiedlich ($l): $k [" . implode(',', $a) . "] vs [" . implode(',', $b) . "]\n";
            $errors++;
        }
    }
}
// feste Schlüssel im Code
$used = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php' || str_contains($f->getPathname(), '/app/lang/')) {
        continue;
    }
    $src = file_get_contents($f->getPathname());
    if (preg_match_all('/\b(?:t|tn)\(\s*\'([a-z0-9_.]+)\'/', $src, $m)) {
        foreach ($m[1] as $k) {
            $used[$k] = $f->getPathname();
        }
    }
}
foreach (glob($root . '/assets/js/*.js') ?: [] as $f) {
    if (preg_match_all('/\btr\(\s*\'([a-z0-9_]+)\'/', file_get_contents($f), $m)) {
        foreach ($m[1] as $k) {
            $used['js.' . $k] = $f;
        }
    }
}
$used['js.confirm_delete_pics'] = 'gallery.php';
// dynamisch zusammengesetzte Schlüssel (nur Präfix im Code): mindestens eine Variante muss existieren
foreach ($used as $k => $where) {
    if (!isset($base[$k]) && array_filter(array_keys($base), fn($b) => str_starts_with($b, $k))) {
        continue;
    }
    if (!isset($base[$k])) {
        echo "unbekannter Schlüssel $k (" . str_replace($root . '/', '', $where) . ")\n";
        $errors++;
    }
}
echo $errors ? "$errors Fehler\n" : "OK: " . count($base) . " Schlüssel, " . count($langs) . " Sprachen\n";
exit($errors ? 1 : 0);
