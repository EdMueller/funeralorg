<?php
declare(strict_types=1);

/**
 * Ortssuche (Ortsname → Koordinaten) über Nominatim (OpenStreetMap), nur serverseitig und nur, wenn ein Admin die
 * Plugin-Einstellung „geocode“ eingeschaltet hat. Übermittelt werden der Ortsname und die IP-Adresse des Servers.
 *
 * - Feste Adresse https://nominatim.openstreetmap.org; für Tests lässt sich in configs/config.php → geocode_api eine
 *   andere Basis-Adresse setzen (genau dieser Ursprung ist dann auch ohne HTTPS erlaubt). Die Oberfläche bietet das nicht an.
 * - Treffer und Fehlschläge werden in stationsgeo.json zwischengespeichert (gleiche Eingabe = keine zweite Anfrage).
 * - Höchstens eine Anfrage pro Sekunde (Nutzungsrichtlinie von Nominatim), keine Weiterleitungen, kleine Antwortgrenze.
 * Fehler werden als RuntimeException mit anzeigbarem Text geworfen; kein Treffer ergibt null.
 */
final class Geocoder
{
    public const BASE = 'https://nominatim.openstreetmap.org';
    private const HIT_TTL = 31536000; // 1 Jahr
    private const MISS_TTL = 86400;   // 1 Tag
    private const MAX_ENTRIES = 500;
    private const MAX_BODY = 65536;

    public static function enabled(): bool
    {
        return Plugins::config('stations', 'geocode');
    }

    /** Hostname des Dienstes (für Hinweistexte). */
    public static function host(): string
    {
        return (string)(parse_url(self::base(), PHP_URL_HOST) ?: 'nominatim.openstreetmap.org');
    }

    private static function override(): bool
    {
        $p = parse_url((string)cfg('geocode_api', ''));
        return is_array($p) && isset($p['scheme'], $p['host']);
    }

    private static function base(): string
    {
        return rtrim(self::override() ? (string)cfg('geocode_api') : self::BASE, '/');
    }

    /** @return array{lat: float, lon: float, label: string}|null */
    public static function lookup(string $place): ?array
    {
        if (!self::enabled()) {
            throw new RuntimeException(t('st.geo_off'));
        }
        $place = mb_substr(trim(preg_replace('/\s+/u', ' ', $place) ?? ''), 0, 120);
        if ($place === '') {
            throw new RuntimeException(t('st.geo_empty'));
        }
        $key = sha1(mb_strtolower($place) . '|' . I18n::lang());
        $hit = Store::read('stationsgeo')['e'][$key] ?? null;
        if (is_array($hit) && time() - (int)($hit['t'] ?? 0) < (!empty($hit['miss']) ? self::MISS_TTL : self::HIT_TTL)) {
            return !empty($hit['miss']) ? null : ['lat' => (float)$hit['lat'], 'lon' => (float)$hit['lon'], 'label' => (string)$hit['label']];
        }

        $wait = 0.0;
        Store::update('stationsgeo', function (array &$d) use (&$wait) {
            $now = microtime(true);
            $wait = max(0.0, (float)($d['last'] ?? 0) + 1.1 - $now);
            $d['last'] = $now + $wait; // Termin der eigenen Anfrage reservieren
        });
        if ($wait > 0) {
            usleep((int)(min($wait, 3.0) * 1e6));
        }

        $found = self::request($place);
        Store::update('stationsgeo', function (array &$d) use ($key, $found) {
            $e = $d['e'] ?? [];
            $e[$key] = ($found ? $found + ['t' => time()] : ['miss' => true, 't' => time()]);
            if (count($e) > self::MAX_ENTRIES) {
                uasort($e, fn($a, $b) => ($b['t'] ?? 0) <=> ($a['t'] ?? 0));
                $e = array_slice($e, 0, self::MAX_ENTRIES, true);
            }
            $d['e'] = $e;
        });
        return $found;
    }

    /** @return array{lat: float, lon: float, label: string}|null */
    private static function request(string $place): ?array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException(t('st.geo_err_curl'));
        }
        $url = self::base() . '/search?' . http_build_query([
            'q' => $place, 'format' => 'jsonv2', 'limit' => 1, 'addressdetails' => 0, 'accept-language' => I18n::lang(),
        ]);
        $body = '';
        $big = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
            CURLOPT_PROTOCOLS => self::override() ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['User-Agent: FuneralOrg/' . (app_version() ?: 'dev'), 'Accept: application/json'],
            CURLOPT_WRITEFUNCTION => function ($c, string $chunk) use (&$body, &$big): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BODY) {
                    $big = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($ok === false || $big || $code === 0) {
            throw new RuntimeException(t('st.geo_err_net'));
        }
        if ($code === 429 || $code === 403) {
            throw new RuntimeException(t('st.geo_err_limit'));
        }
        $j = $code === 200 ? json_decode($body, true) : null;
        if (!is_array($j)) {
            throw new RuntimeException(t('st.geo_err_net'));
        }
        $r = $j[0] ?? null;
        if (!is_array($r) || !is_numeric($r['lat'] ?? null) || !is_numeric($r['lon'] ?? null)) {
            return null;
        }
        $lat = (float)$r['lat'];
        $lon = (float)$r['lon'];
        if (abs($lat) > 90 || abs($lon) > 180) {
            return null;
        }
        return ['lat' => round($lat, 4), 'lon' => round($lon, 4), 'label' => mb_substr((string)($r['display_name'] ?? $place), 0, 160)];
    }
}
