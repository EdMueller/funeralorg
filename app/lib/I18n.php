<?php
declare(strict_types=1);

/**
 * Mehrsprachigkeit. Sprachdateien: app/lang/<sprache>/*.php, jede liefert ein Array
 * 'bereich.schlüssel' => 'Text' oder ['one' => '…', 'other' => '…'] (Plural).
 * Platzhalter: {name}; bei Plural zusätzlich {n}. Deutsch ist Basis und Rückfall.
 */
final class I18n
{
    public const LANGS = ['de' => 'Deutsch', 'en' => 'English'];
    public const DEFAULT = 'de';
    public const COOKIE = 'gdk_lang';

    private static ?string $lang = null;
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /** Aktive Sprache: Cookie, sonst Accept-Language, sonst Deutsch. */
    public static function lang(): string
    {
        if (self::$lang !== null) {
            return self::$lang;
        }
        $c = (string)($_COOKIE[self::COOKIE] ?? '');
        if (isset(self::LANGS[$c])) {
            return self::$lang = $c;
        }
        $best = self::DEFAULT;
        $bestQ = -1.0;
        foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
            $bits = explode(';', trim($part));
            $code = strtolower(substr(trim($bits[0]), 0, 2));
            $q = 1.0;
            if (isset($bits[1]) && preg_match('/q=([0-9.]+)/', $bits[1], $m)) {
                $q = (float)$m[1];
            }
            if (isset(self::LANGS[$code]) && $q > $bestQ) {
                $best = $code;
                $bestQ = $q;
            }
        }
        return self::$lang = $best;
    }

    /** Sprache setzen und im Browser merken (Cookie, 1 Jahr). */
    public static function set(string $lang): void
    {
        if (!isset(self::LANGS[$lang])) {
            return;
        }
        self::$lang = $lang;
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie(self::COOKIE, $lang, [
            'expires'  => time() + 365 * 86400,
            'path'     => base_path(),
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $lang;
    }

    /** Sprache im Benutzerkonto speichern (nicht bei Freigabelinks). */
    public static function saveForUser(string $lang): void
    {
        $me = Auth::user();
        if (!$me || $me['kind'] !== 'user' || !isset(self::LANGS[$lang])) {
            return;
        }
        Store::update('users', function (array &$users) use ($me, $lang) {
            foreach ($users as &$u) {
                if ($u['id'] === $me['id']) {
                    $u['lang'] = $lang;
                }
            }
        });
    }

    /** Nach der Anmeldung: gespeicherte Kontosprache übernehmen, sonst die aktuelle ins Konto schreiben. */
    public static function onLogin(): void
    {
        $me = Auth::user();
        if (!$me || $me['kind'] !== 'user') {
            return;
        }
        $row = find_by_id(Store::read('users'), $me['id']);
        $saved = (string)($row['lang'] ?? '');
        if (isset(self::LANGS[$saved])) {
            self::set($saved);
        } else {
            self::saveForUser(self::lang());
        }
    }

    /** Führt $fn mit einer anderen Sprache aus (z. B. Protokolltexte immer auf Deutsch). */
    public static function in(string $lang, callable $fn): mixed
    {
        $old = self::$lang;
        self::$lang = $lang;
        try {
            return $fn();
        } finally {
            self::$lang = $old;
        }
    }

    public static function plural(string $lang, int $n): string
    {
        return $n === 1 ? 'one' : 'other'; // de, en
    }

    /** Übersetzt einen Schlüssel; $n wählt die Pluralform. */
    public static function t(string $key, array $params = [], ?int $n = null): string
    {
        $lang = self::lang();
        $v = self::messages($lang)[$key] ?? self::messages(self::DEFAULT)[$key] ?? null;
        if ($v === null) {
            return $key;
        }
        if (is_array($v)) {
            $form = self::plural($lang, (int)$n);
            $v = $v[$form] ?? $v['other'] ?? reset($v);
        }
        if ($n !== null) {
            $params += ['n' => number_format($n, 0, lang_dec(), lang_thou())];
        }
        $repl = [];
        foreach ($params as $k => $val) {
            $repl['{' . $k . '}'] = (string)$val;
        }
        return $repl ? strtr((string)$v, $repl) : (string)$v;
    }

    /** Alle Texte mit Präfix (für JS), in der aktiven Sprache mit Rückfall. */
    public static function export(string $prefix): array
    {
        $out = [];
        foreach (self::messages(self::DEFAULT) as $k => $v) {
            if (str_starts_with($k, $prefix)) {
                $out[substr($k, strlen($prefix))] = self::messages(self::lang())[$k] ?? $v;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function messages(string $lang): array
    {
        if (!isset(self::$cache[$lang])) {
            $all = [];
            foreach (glob(APP_DIR . '/lang/' . $lang . '/*.php') ?: [] as $f) {
                $all += require $f;
            }
            self::$cache[$lang] = $all;
        }
        return self::$cache[$lang];
    }
}
