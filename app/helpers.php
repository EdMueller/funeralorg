<?php
declare(strict_types=1);

function cfg(string $key, mixed $default = null): mixed
{
    return $GLOBALS['CFG'][$key] ?? $default;
}

/** PHP-Größenangabe (z. B. "2M", "512K") in Bytes; 0 oder -1 = unbegrenzt (gibt 0 zurück). */
function ini_bytes(string $v): int
{
    $v = trim($v);
    $n = (int)$v;
    $n *= match (strtolower(substr($v, -1))) {
        'g' => 1024 ** 3,
        'm' => 1024 ** 2,
        'k' => 1024,
        default => 1,
    };
    return max(0, $n);
}

/** Tatsächlich wirksames Upload-Limit pro Datei in Bytes: Minimum aus Konfiguration und PHP-Limits. */
function upload_limit_bytes(): int
{
    $limits = [(int)cfg('upload_max_mb', 40) * 1024 * 1024];
    foreach (['upload_max_filesize', 'post_max_size'] as $key) {
        $b = ini_bytes((string)ini_get($key));
        if ($b > 0) {
            $limits[] = $b;
        }
    }
    return min($limits);
}

/** Version der Anwendung aus der Datei VERSION im Anwendungsverzeichnis; '' wenn sie fehlt oder ungültig ist. */
function app_version(): string
{
    static $v = null;
    if ($v === null) {
        $raw = trim((string)@file_get_contents(ROOT_DIR . '/VERSION'));
        $v = preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $raw) ? $raw : '';
    }
    return $v;
}

function e(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Übersetzter Text (nicht escaped – Ausgabe weiterhin über e()). */
function t(string $key, array $params = []): string
{
    return I18n::t($key, $params);
}

/** Übersetzter Text mit Pluralform nach $n; {n} wird mit der formatierten Zahl ersetzt. */
function tn(string $key, int $n, array $params = []): string
{
    return I18n::t($key, $params, $n);
}

function lang_dec(): string
{
    return I18n::lang() === 'en' ? '.' : ',';
}

function lang_thou(): string
{
    return I18n::lang() === 'en' ? ',' : '.';
}

/**
 * Zeitstempel formatieren. $kind: d (Datum), dt (Datum+Uhrzeit), dts (mit Sekunden),
 * dm (Tag.Monat), dmt (Tag.Monat+Uhrzeit). Formate stehen in den Sprachdateien (fmt.*).
 */
function fmt_ts(int $ts, string $kind = 'd'): string
{
    return date(t('fmt.' . $kind), $ts);
}

function url(string $r, array $params = []): string
{
    $q = http_build_query(array_merge(['r' => $r], $params));
    return 'index.php?' . $q;
}

/** URL-Pfad des Installationsverzeichnisses, immer mit Schrägstrich am Ende (z. B. /beerdigung/). */
function base_path(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return rtrim(dirname($script), '/') . '/';
}

function asset(string $path): string
{
    $f = (defined('PUBLIC_DIR') ? PUBLIC_DIR : ROOT_DIR . '/public') . '/assets/' . $path;
    $v = is_file($f) ? substr((string)filemtime($f), -6) : '0';
    return 'assets/' . $path . '?v=' . $v;
}

function redirect(string $r, array $params = []): never
{
    header('Location: ' . url($r, $params));
    exit;
}

function redirect_back(string $fallbackR = 'home'): never
{
    $ref = $_POST['_back'] ?? '';
    if (is_string($ref) && str_starts_with($ref, 'index.php?')) {
        header('Location: ' . $ref);
        exit;
    }
    redirect($fallbackR);
}

function current_url(): string
{
    return 'index.php?' . http_build_query($_GET);
}

function abort(int $code, string $msg = ''): never
{
    http_response_code($code);
    $titles = [403 => t('error.403'), 404 => t('error.404'), 400 => t('error.400'), 419 => t('error.419')];
    if (is_ajax()) {
        json_out(['ok' => false, 'error' => $msg ?: ($titles[$code] ?? t('error.generic'))], $code);
    }
    view('error', ['title' => $titles[$code] ?? t('error.generic'), 'code' => $code, 'msg' => $msg],
        Auth::check() ? 'layout' : 'layout_plain');
    exit;
}

function is_ajax(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Rendert eine View innerhalb eines Layouts. */
function view(string $tpl, array $vars = [], ?string $layout = 'layout'): void
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require APP_DIR . '/views/' . $tpl . '.php';
    $content = ob_get_clean();
    if ($layout === null) {
        echo $content;
        return;
    }
    require APP_DIR . '/views/' . $layout . '.php';
}

function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function post(string $k, mixed $d = ''): mixed
{
    $v = $_POST[$k] ?? $d;
    return is_string($v) ? trim($v) : $v;
}

function get(string $k, mixed $d = ''): mixed
{
    $v = $_GET[$k] ?? $d;
    return is_string($v) ? trim($v) : $v;
}

function new_id(): string
{
    return bin2hex(random_bytes(8));
}

function now(): string
{
    return date('c');
}

function fmt_date(?string $iso, bool $time = false): string
{
    if (!$iso) {
        return '';
    }
    $ts = strtotime($iso);
    return $ts ? fmt_ts($ts, $time ? 'dt' : 'd') : '';
}

function fmt_bytes(int $b): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($b >= 1024 && $i < 3) {
        $b /= 1024;
        $i++;
    }
    return number_format($b, $i ? 1 : 0, lang_dec(), lang_thou()) . ' ' . $u[$i];
}

function fmt_money(float $v): string
{
    return number_format($v, 2, lang_dec(), lang_thou()) . ' €';
}

/** Schützt CSV-Zellen vor Formel-Ausführung in Tabellenkalkulationen (führendes = + - @ Tab CR). */
function csv_safe(array $row): array
{
    return array_map(fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v, $row);
}

/** Findet einen Datensatz per id in einer Liste. */
function find_by_id(array $list, string $id): ?array
{
    foreach ($list as $row) {
        if (($row['id'] ?? null) === $id) {
            return $row;
        }
    }
    return null;
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function slug(string $s): string
{
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
    $s = preg_replace('/[^A-Za-z0-9._-]+/', '-', $s) ?? '';
    return trim($s, '-') ?: 'datei';
}

/**
 * Prüft eine Basis-URL (http/https, Host, optional Port und Pfad; ohne Zugangsdaten, Query, Fragment).
 * @return string|null Normalisiert ohne abschließenden Schrägstrich, '' für leere Eingabe, null bei ungültiger Eingabe
 */
function normalize_base_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $p = parse_url($url);
    if (!$p || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true)
        || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])
        || !preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9]([a-z0-9.-]*[a-z0-9])?)$/i', $p['host'])
        || preg_match('/[^A-Za-z0-9._~\/-]/', (string)($p['path'] ?? ''))) {
        return null;
    }
    return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . (int)$p['port'] : '')
        . rtrim((string)($p['path'] ?? ''), '/');
}

/**
 * Konfigurierte öffentliche Basis-URL (ohne abschließenden Schrägstrich) oder ''.
 * `configs/config.php → base_url` hat Vorrang vor dem bei der Einrichtung gespeicherten Wert (`settings.json`).
 */
function configured_base_url(): string
{
    foreach ([(string)cfg('base_url', ''), (string)(Store::read('settings')['base_url'] ?? '')] as $v) {
        $n = normalize_base_url($v);
        if ($n !== null && $n !== '') {
            return $n;
        }
    }
    return '';
}

/** Adresse der Anwendung, wie sie diese Anfrage sieht (Vorschlag bei der Einrichtung; nie für Mails verwenden). */
function request_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(base_path(), '/');
}

/**
 * Absolute Adresse der Anwendung mit abschließendem „/“ (nur für Ausdrucke und Freigabelinks, die ein Admin
 * selbst erzeugt; die konfigurierte Basis-URL hat Vorrang, sonst gilt die aktuelle Anfrage).
 * Für Mails an Dritte ist diese Funktion ungeeignet – dort gilt nur `configured_base_url()`.
 */
function site_base_url(): string
{
    $configured = configured_base_url();
    if ($configured !== '') {
        return $configured . '/';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_path();
}

function nav_active(string $r): string
{
    return (($_GET['r'] ?? 'home') === $r) ? ' active' : '';
}

/** Mehrzeiliger Tooltip-Text mit den wichtigsten Metadaten eines Bildes. */
function image_tip(array $img, array $albumNames): string
{
    $lines = [$img['caption'] ?: $img['orig_name']];
    if ($img['caption'] && $img['orig_name']) {
        $lines[] = t('img.tip_file', ['name' => $img['orig_name']]);
    }
    $type = strtoupper($img['ext'] === 'jpeg' ? 'jpg' : $img['ext']) . (!empty($img['animated']) ? t('img.tip_animated') : '');
    $lines[] = $img['w'] . ' × ' . $img['h'] . ' px, ' . fmt_bytes((int)$img['size']) . ', ' . $type;
    $names = array_values(array_filter(array_map(fn($id) => $albumNames[$id] ?? '', Albums::ofImage($img))));
    $lines[] = t('img.tip_album', ['names' => $names ? implode(', ', $names) : t('gal.no_album')]);
    if (!empty($img['uploaded_by'])) {
        $lines[] = t('img.tip_uploaded', ['by' => $img['uploaded_by'], 'date' => fmt_date($img['uploaded_at'] ?? '')]);
    }
    return implode("\n", $lines);
}

/** Absenderadresse für Briefumschläge: bis zu 5 Zeilen aus den Einstellungen (Admin → Absender). */
function sender_lines(): array
{
    $l = Store::read('settings')['sender'] ?? [];
    return is_array($l) ? array_values(array_filter(array_map('strval', $l), fn($x) => $x !== '')) : [];
}
