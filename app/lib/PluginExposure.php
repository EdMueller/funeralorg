<?php
declare(strict_types=1);

/**
 * Selbsttest: Ist das Plugin-Verzeichnis (und das ZIP-Archiv darin) von außen erreichbar? Dann könnte jeder Plugin-Code und
 * Pakete herunterladen. Der Test legt kurz je eine Textdatei mit zufälligem Namen in `plugins/` und `plugins/_archive/` an,
 * ruft sie über die öffentliche Adresse der Anwendung ab (Basis-URL, sonst aktuelle Anfrage) und löscht sie wieder.
 *
 * Ergebnis `status`: protected (403/404/Weiterleitung o. Ä., Inhalt nicht lesbar) | exposed (Inhalt lesbar) | unknown (nicht prüfbar).
 * Das Ergebnis steht in `market.json → selftest`.
 */
final class PluginExposure
{
    /** Ältester Stand eines Ergebnisses, bis zu dem es nicht erneut automatisch geprüft wird. */
    private const STALE = 7 * 86400;

    /** @return array{status:string, time:int, urls:list<string>}|null */
    public static function last(): ?array
    {
        $r = Store::read('market')['selftest'] ?? null;
        return is_array($r) && isset($r['status'], $r['time']) ? $r : null;
    }

    /** Läuft nur, wenn es noch kein Ergebnis gibt oder es älter als eine Woche ist (z. B. nach einer Installation). */
    public static function runIfStale(): void
    {
        $l = self::last();
        if ($l === null || time() - (int)$l['time'] > self::STALE) {
            self::run();
        }
    }

    /** @return array{status:string, time:int, urls:list<string>} */
    public static function run(): array
    {
        $base = rtrim(configured_base_url() ?: request_base_url(), '/');
        $status = 'unknown';
        $exposed = [];
        $dirs = [Plugins::dir(), Plugins::archiveDir()];
        $made = [];
        try {
            $seen = 0;
            foreach ($dirs as $dir) {
                if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                    continue;
                }
                $name = 'selftest-' . bin2hex(random_bytes(8)) . '.txt';
                $marker = 'funeralorg-' . bin2hex(random_bytes(8));
                if (@file_put_contents($dir . '/' . $name, $marker) === false) {
                    continue;
                }
                $made[] = $dir . '/' . $name;
                $rel = ltrim(substr($dir, strlen(ROOT_DIR)), '/');
                $url = $base . '/' . $rel . '/' . $name;
                $got = self::fetch($url);
                if ($got === null) {
                    continue; // nicht erreichbar → dieses Verzeichnis bleibt „unbekannt“
                }
                $seen++;
                if ($got['code'] === 200 && str_contains($got['body'], $marker)) {
                    $exposed[] = $url;
                }
            }
            $status = $exposed ? 'exposed' : ($seen === count($dirs) ? 'protected' : 'unknown');
        } finally {
            foreach ($made as $f) {
                @unlink($f);
            }
        }
        $res = ['status' => $status, 'time' => time(), 'urls' => $exposed];
        Store::update('market', function (array &$d) use ($res) {
            $d['selftest'] = $res;
        });
        return $res;
    }

    /** Einfacher GET auf die eigene Anwendung (kein Folgen von Weiterleitungen, kurze Zeitlimits). @return array{code:int, body:string}|null */
    private static function fetch(string $url): ?array
    {
        if (!function_exists('curl_init') || !preg_match('~^https?://~i', $url)) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'FuneralOrg-selftest',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $body === false ? null : ['code' => $code, 'body' => substr((string)$body, 0, 512)];
    }
}
