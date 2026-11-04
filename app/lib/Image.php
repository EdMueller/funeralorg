<?php
declare(strict_types=1);

/**
 * Bildverarbeitung mit GD.
 * - JPEG: EXIF-Orientierung wird angewendet. Ohne Drehbedarf werden EXIF/XMP/IPTC
 *   verlustfrei aus dem Original entfernt (inkl. GPS), sonst wird gedreht neu gespeichert.
 * - GIF: Original bleibt unverändert (Animation erhalten), Vorschaubild = erstes Frame.
 * - Erzeugt Vorschaubild (thumb) und Großansicht (medium) als JPEG; PNG/WebP mit echter
 *   Transparenz (Datensatzfeld 'alpha') werden als PNG erzeugt.
 */
final class Image
{
    public const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    /** Maskierung (Gesichter, Kennzeichen …): erlaubte Formen und Arten, höchstens so viele Bereiche je Bild. */
    public const MASK_SHAPES = ['rect', 'ellipse'];
    public const MASK_MODES = ['pixel', 'blur', 'black'];
    public const MAX_MASKS = 50;

    public static function dir(string $variant): string
    {
        $d = DATA_DIR . '/uploads/' . $variant;
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
        return $d;
    }

    public static function path(array $img, string $variant): string
    {
        if ($variant === 'orig') {
            return self::dir('orig') . '/' . $img['id'] . '.' . $img['ext'];
        }
        // Animierte GIFs: Großansicht ist das Original
        if ($variant === 'medium' && $img['ext'] === 'gif') {
            return self::dir('orig') . '/' . $img['id'] . '.gif';
        }
        return self::dir($variant) . '/' . $img['id'] . (!empty($img['alpha']) ? '.png' : '.jpg');
    }

    /** MIME-Typ der ausgelieferten Variante (thumb/medium). */
    public static function variantMime(array $img, string $variant): string
    {
        if ($variant === 'medium' && $img['ext'] === 'gif') {
            return 'image/gif';
        }
        return !empty($img['alpha']) ? 'image/png' : 'image/jpeg';
    }

    /** Prüft stichprobenartig, ob ein Bild tatsächlich transparente Pixel enthält. */
    private static function hasAlpha(\GdImage $gd): bool
    {
        if (!imageistruecolor($gd)) {
            return false;
        }
        $w = imagesx($gd);
        $h = imagesy($gd);
        $step = max(1, (int)floor(sqrt($w * $h / 40000)));
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                if (((imagecolorat($gd, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Verarbeitet eine hochgeladene Datei.
     * @return array Bild-Datensatz (ohne album/caption)
     */
    public static function import(string $tmpFile, string $origName): array
    {
        @ini_set('memory_limit', (string)cfg('image_memory_limit', '768M'));
        @set_time_limit(120);

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpFile) ?: '';
        if (!isset(self::TYPES[$mime])) {
            throw new RuntimeException(t('img.err_type'));
        }
        $info = @getimagesize($tmpFile);
        if (!$info) {
            throw new RuntimeException(t('img.err_invalid'));
        }
        $maxPixels = (int)cfg('image_max_pixels', 50_000_000);
        if ($maxPixels > 0 && $info[0] * $info[1] > $maxPixels) {
            throw new RuntimeException(t('img.err_pixels', ['mp' => (int)round($maxPixels / 1_000_000)]));
        }
        $ext = self::TYPES[$mime];
        $id = new_id();
        $orig = self::dir('orig') . '/' . $id . '.' . $ext;

        $gd = self::load($tmpFile, $mime);
        if (!$gd) {
            throw new RuntimeException(t('img.err_read'));
        }

        if ($mime === 'image/jpeg') {
            $orientation = self::orientation($tmpFile);
            if ($orientation > 1) {
                $gd = self::orient($gd, $orientation);
                imagejpeg($gd, $orig, 94);
            } else {
                $bin = (string)file_get_contents($tmpFile);
                file_put_contents($orig, self::stripJpegMeta($bin));
            }
        } else {
            if (!move_uploaded_file($tmpFile, $orig) && !copy($tmpFile, $orig)) {
                throw new RuntimeException(t('img.err_save'));
            }
        }
        @chmod($orig, 0640);

        $w = imagesx($gd);
        $h = imagesy($gd);
        $alpha = ($ext === 'png' || $ext === 'webp') && self::hasAlpha($gd);
        $vExt = $alpha ? '.png' : '.jpg';
        self::resizeTo($gd, self::dir('thumb') . '/' . $id . $vExt, (int)cfg('thumb_size', 480), $alpha);
        if ($ext !== 'gif') {
            self::resizeTo($gd, self::dir('medium') . '/' . $id . $vExt, (int)cfg('medium_size', 1800), $alpha);
        }
        imagedestroy($gd);

        return [
            'id'        => $id,
            'ext'       => $ext,
            'mime'      => $mime,
            'w'         => $w,
            'h'         => $h,
            'size'      => (int)filesize($orig),
            'orig_name' => mb_substr(basename($origName), 0, 120),
            'alpha'     => $alpha,
            'animated'  => $ext === 'gif' && self::isAnimatedGif($orig),
        ];
    }

    /* ------------------------------------------------------------ Maskierung */

    /** Lassen sich Bereiche unkenntlich machen? (Nicht bei GIF: dort ist die Großansicht das Original.) */
    public static function maskable(array $img): bool
    {
        return ($img['ext'] ?? '') !== 'gif' && is_file(self::path($img, 'orig'));
    }

    /**
     * Bereinigt Bereiche aus Benutzereingabe (JSON-Text oder Array): relative Koordinaten 0–1 zum (gedrehten) Original,
     * Form und Art nur aus den erlaubten Werten, höchstens MAX_MASKS, zu kleine Bereiche entfallen.
     * @return list<array{x:float,y:float,w:float,h:float,shape:string,mode:string}>
     */
    public static function cleanMasks(mixed $in): array
    {
        if (is_string($in)) {
            $in = json_decode($in, true);
        }
        if (!is_array($in)) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values($in), 0, self::MAX_MASKS) as $m) {
            if (!is_array($m)) {
                continue;
            }
            foreach (['x', 'y', 'w', 'h'] as $k) {
                if (!isset($m[$k]) || !is_numeric($m[$k])) {
                    continue 2;
                }
            }
            $x = min(1.0, max(0.0, (float)$m['x']));
            $y = min(1.0, max(0.0, (float)$m['y']));
            $w = min(1.0 - $x, max(0.0, (float)$m['w']));
            $h = min(1.0 - $y, max(0.0, (float)$m['h']));
            if ($w < 0.002 || $h < 0.002) {
                continue;
            }
            $out[] = [
                'x' => round($x, 5), 'y' => round($y, 5), 'w' => round($w, 5), 'h' => round($h, 5),
                'shape' => in_array($m['shape'] ?? '', self::MASK_SHAPES, true) ? $m['shape'] : 'rect',
                'mode'  => in_array($m['mode'] ?? '', self::MASK_MODES, true) ? $m['mode'] : 'pixel',
            ];
        }
        return $out;
    }

    /**
     * Macht die Bereiche im Bild (Vollauflösung) unkenntlich.
     * - black: deckend schwarz.
     * - pixel: grobe Blöcke (etwa 8 Zellen über die kürzere Seite).
     * - blur:  starkes Weichzeichnen. Der Bereich wird zuerst auf wenige Bildpunkte verkleinert (Information geht
     *          verloren, deshalb nicht rückrechenbar), dann weich vergrößert und geglättet. Ein bloßer Gauß-Filter
     *          reicht dafür nicht, er lässt sich teilweise zurückrechnen.
     * @param list<array> $masks bereinigt (cleanMasks)
     */
    public static function applyMasks(\GdImage $gd, array $masks): void
    {
        $W = imagesx($gd);
        $H = imagesy($gd);
        imagealphablending($gd, false);
        imagesavealpha($gd, true);
        foreach ($masks as $m) {
            $x0 = (int)floor($m['x'] * $W);
            $y0 = (int)floor($m['y'] * $H);
            $rw = max(1, min($W - $x0, (int)ceil($m['w'] * $W)));
            $rh = max(1, min($H - $y0, (int)ceil($m['h'] * $H)));
            $proc = null;
            $black = imagecolorallocatealpha($gd, 0, 0, 0, 0);
            if ($m['mode'] !== 'black') {
                $proc = self::obscure($gd, $x0, $y0, $rw, $rh, $m['mode']);
            }
            // Zeile für Zeile: Rechteck = ganze Breite, Ellipse = Sehne der Ellipse in dieser Zeile
            $ellipse = $m['shape'] === 'ellipse';
            for ($row = 0; $row < $rh; $row++) {
                $a = 0;
                $b = $rw - 1;
                if ($ellipse) {
                    $dy = (($row + 0.5) - $rh / 2) / ($rh / 2);
                    $half = sqrt(max(0.0, 1 - $dy * $dy)) * $rw / 2;
                    if ($half < 0.5) {
                        continue;
                    }
                    $a = (int)max(0, floor($rw / 2 - $half));
                    $b = (int)min($rw - 1, ceil($rw / 2 + $half) - 1);
                }
                if ($proc === null) {
                    imagefilledrectangle($gd, $x0 + $a, $y0 + $row, $x0 + $b, $y0 + $row, $black);
                } else {
                    imagecopy($gd, $proc, $x0 + $a, $y0 + $row, $a, $row, $b - $a + 1, 1);
                }
            }
            if ($proc !== null) {
                imagedestroy($proc);
            }
        }
    }

    /** Unkenntlich gemachte Kopie eines Ausschnitts (pixel|blur). */
    private static function obscure(\GdImage $gd, int $x, int $y, int $w, int $h, string $mode): \GdImage
    {
        $short = max(1, min($w, $h));
        $cells = $mode === 'blur' ? 5 : 8;
        $sw = max(1, (int)round($w * $cells / $short));
        $sh = max(1, (int)round($h * $cells / $short));
        $sw = min($sw, $w);
        $sh = min($sh, $h);
        $small = imagecreatetruecolor($sw, $sh);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $gd, 0, 0, $x, $y, $sw, $sh, $w, $h); // Mittelwerte
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        if ($mode === 'blur') {
            imagecopyresampled($out, $small, 0, 0, 0, 0, $w, $h, $sw, $sh);
            for ($i = 0; $i < 3; $i++) {
                imagefilter($out, IMG_FILTER_GAUSSIAN_BLUR);
            }
        } else {
            imagecopyresized($out, $small, 0, 0, 0, 0, $w, $h, $sw, $sh); // Blöcke, ohne Glättung
        }
        imagedestroy($small);
        return $out;
    }

    /**
     * Erzeugt `thumb` und `medium` neu aus dem unveränderten Original und brennt die Bereiche ein (leer = ohne Maskierung).
     * Das Original wird nie verändert. Geschrieben wird erst, wenn beide Varianten fertig sind.
     * @param list<array> $masks bereinigt (cleanMasks)
     */
    public static function rerender(array $img, array $masks): void
    {
        if (!self::maskable($img)) {
            throw new RuntimeException(t('img.err_mask_type'));
        }
        @ini_set('memory_limit', (string)cfg('image_memory_limit', '768M'));
        @set_time_limit(120);
        $gd = self::load(self::path($img, 'orig'), (string)$img['mime']);
        if (!$gd) {
            throw new RuntimeException(t('img.err_read'));
        }
        if (!imageistruecolor($gd)) {
            imagepalettetotruecolor($gd);
        }
        if ($masks) {
            self::applyMasks($gd, $masks);
        }
        $alpha = !empty($img['alpha']);
        $jobs = ['thumb' => (int)cfg('thumb_size', 480), 'medium' => (int)cfg('medium_size', 1800)];
        $tmp = [];
        try {
            foreach ($jobs as $variant => $max) {
                $dest = self::path($img, $variant);
                $tmp[$dest] = $dest . '.tmp';
                self::resizeTo($gd, $tmp[$dest], $max, $alpha);
                if (!is_file($tmp[$dest]) || filesize($tmp[$dest]) < 1) {
                    throw new RuntimeException(t('img.err_save'));
                }
            }
            foreach ($tmp as $dest => $t) {
                rename($t, $dest);
            }
        } finally {
            foreach ($tmp as $t) {
                if (is_file($t)) {
                    @unlink($t);
                }
            }
            imagedestroy($gd);
        }
    }

    /** Unmaskierte Vorschau für den Editor (Original, höchstens `medium_size`); nur für Administratoren aufrufen. */
    public static function sendRaw(array $img): never
    {
        $orig = self::path($img, 'orig');
        $max = (int)cfg('medium_size', 1800);
        if (!is_file($orig) || ($img['ext'] ?? '') === 'gif') {
            abort(404);
        }
        if (max((int)($img['w'] ?? 0), (int)($img['h'] ?? 0)) <= $max) {
            header('Cache-Control: private, no-store');
            self::send($orig, (string)$img['mime']);
        }
        @ini_set('memory_limit', (string)cfg('image_memory_limit', '768M'));
        $tmp = DATA_DIR . '/tmp/' . bin2hex(random_bytes(8)) . '.img';
        $gd = self::load($orig, (string)$img['mime']);
        if (!$gd) {
            abort(404);
        }
        $alpha = !empty($img['alpha']);
        self::resizeTo($gd, $tmp, $max, $alpha);
        imagedestroy($gd);
        header('Content-Type: ' . ($alpha ? 'image/png' : 'image/jpeg'));
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: private, no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    public static function delete(array $img): void
    {
        foreach (['orig', 'medium', 'thumb'] as $v) {
            $p = self::path($img, $v);
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }

    /** @return \GdImage|false */
    private static function load(string $file, string $mime)
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file),
            'image/png'  => @imagecreatefrompng($file),
            'image/gif'  => @imagecreatefromgif($file),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            default      => false,
        };
    }

    private static function orientation(string $file): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($file);
        return (int)($exif['Orientation'] ?? 1);
    }

    private static function orient(\GdImage $img, int $o): \GdImage
    {
        if (in_array($o, [2, 4, 5, 7], true)) {
            imageflip($img, IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($o) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };
        if ($angle) {
            $r = imagerotate($img, $angle, 0);
            if ($r) {
                imagedestroy($img);
                $img = $r;
            }
        }
        return $img;
    }

    private static function resizeTo(\GdImage $src, string $dest, int $max, bool $alpha = false): void
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $max / max($w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagepng($dst, $dest, 6);
        } else {
            // Transparenz auf hellen Grund legen
            imagefill($dst, 0, 0, imagecolorallocate($dst, 246, 246, 243));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imageinterlace($dst, true);
            imagejpeg($dst, $dest, (int)cfg('jpeg_quality', 88));
        }
        imagedestroy($dst);
        @chmod($dest, 0640);
    }

    /** Entfernt APP1 (EXIF/XMP) und APP13 (IPTC) verlustfrei aus einem JPEG. */
    public static function stripJpegMeta(string $bin): string
    {
        if (substr($bin, 0, 2) !== "\xFF\xD8") {
            return $bin;
        }
        $out = "\xFF\xD8";
        $pos = 2;
        $len = strlen($bin);
        while ($pos + 4 <= $len) {
            if ($bin[$pos] !== "\xFF") {
                return $bin; // unerwartete Struktur – Original behalten
            }
            $marker = ord($bin[$pos + 1]);
            if ($marker === 0xFF) { // Füllbyte
                $pos++;
                continue;
            }
            if ($marker === 0xDA) { // Start of Scan: Rest unverändert übernehmen
                return $out . substr($bin, $pos);
            }
            if ($marker === 0xD9) {
                return $out . "\xFF\xD9";
            }
            $segLen = unpack('n', substr($bin, $pos + 2, 2))[1];
            $seg = substr($bin, $pos, 2 + $segLen);
            if ($marker !== 0xE1 && $marker !== 0xED) {
                $out .= $seg;
            }
            $pos += 2 + $segLen;
        }
        return $bin;
    }

    public static function isAnimatedGif(string $file): bool
    {
        $c = (string)file_get_contents($file);
        return preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $c) > 1;
    }

    /** Liefert eine Bilddatei mit Caching-Headern aus. */
    public static function send(string $path, string $mime, ?string $downloadName = null, bool $revalidate = false): never
    {
        if (!is_file($path)) {
            abort(404);
        }
        $mtime = filemtime($path);
        $etag = '"' . md5($path . $mtime) . '"';
        // Maskierte Bilder werden bei jedem Aufruf neu angefragt (ETag), damit eine neue Maskierung sofort wirkt
        if (!headers_sent() && !in_array('Cache-Control: private, no-store', headers_list(), true)) {
            header($revalidate ? 'Cache-Control: private, no-cache' : 'Cache-Control: private, max-age=86400');
        }
        header('ETag: ' . $etag);
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag && $downloadName === null) {
            http_response_code(304);
            exit;
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        if ($downloadName !== null) {
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
        }
        readfile($path);
        exit;
    }
}
