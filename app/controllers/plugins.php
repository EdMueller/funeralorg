<?php
declare(strict_types=1);

/** Route `pluginasset`: liefert Dateien aus `assets/` aktiver Plugins (nur für angemeldete Benutzer). */
function ctrl_plugins(string $r, string $a): void
{
    $file = Plugins::assetFile((string)get('p'), (string)get('f'));
    if ($file === null) {
        abort(404);
    }
    $etag = '"' . md5($file . filemtime($file) . filesize($file)) . '"';
    header('Content-Type: ' . Plugins::ASSET_MIME[strtolower(pathinfo($file, PATHINFO_EXTENSION))]);
    header('Cache-Control: private, max-age=2592000');
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}
