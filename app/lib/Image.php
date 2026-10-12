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
    public static function send(string $path, string $mime, ?string $downloadName = null): never
    {
        if (!is_file($path)) {
            abort(404);
        }
        $mtime = filemtime($path);
        $etag = '"' . md5($path . $mtime) . '"';
        header('Cache-Control: private, max-age=86400');
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
