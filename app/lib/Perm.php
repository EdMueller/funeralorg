<?php
declare(strict_types=1);

/**
 * Rechte hängen an Gruppen. Benutzer und Freigabelinks erben die Rechte ihrer Gruppe.
 * Admins dürfen alles. Freigabelinks sehen nie Gedenkseite und Galerie.
 */
final class Perm
{
    public const NONE = 0;
    public const READ = 1;
    public const CHECK = 2;   // abhaken / Status ändern
    public const EDIT = 3;    // anlegen, bearbeiten, löschen

    public const LEVELS = [0, 1, 2, 3];

    public const MODULES = ['checklist', 'contacts', 'rsvp', 'condolences'];

    public const FIELDS = ['address', 'phone', 'mobile', 'email'];

    /** @return array<int, string> Stufe => übersetzte Beschriftung */
    public static function levels(): array
    {
        return array_combine(self::LEVELS, array_map(fn($l) => t('perm.level.' . $l), self::LEVELS));
    }

    /** @return array<string, string> Modul => übersetzte Beschriftung */
    public static function modules(): array
    {
        return array_combine(self::MODULES, array_map(fn($m) => t('perm.module.' . $m), self::MODULES));
    }

    /** @return array<string, string> Feld => übersetzte Beschriftung */
    public static function fields(): array
    {
        return array_combine(self::FIELDS, array_map(fn($f) => t('perm.field.' . $f), self::FIELDS));
    }

    private static ?array $group = null;

    public static function group(): array
    {
        if (self::$group !== null) {
            return self::$group;
        }
        $u = Auth::user();
        $g = $u ? find_by_id(Store::read('groups'), (string)$u['group_id']) : null;
        return self::$group = $g ?? ['perms' => [], 'gallery' => [], 'fields' => []];
    }

    public static function level(string $module): int
    {
        if (Auth::isAdmin()) {
            return self::EDIT;
        }
        return (int)(self::group()['perms'][$module] ?? 0);
    }

    public static function can(string $module, int $min = self::READ): bool
    {
        return self::level($module) >= $min;
    }

    public static function need(string $module, int $min = self::READ): void
    {
        if (!self::can($module, $min)) {
            abort(403, t('perm.denied'));
        }
    }

    public static function field(string $field): bool
    {
        if (Auth::isAdmin()) {
            return true;
        }
        return !empty(self::group()['fields'][$field]);
    }

    public static function memorial(): bool
    {
        return Auth::check() && !Auth::isShare();
    }

    /**
     * Galerierechte einer Gruppe (`group.gallery`). `recent` und `all` sind Einschränkungen: Fehlt der Schlüssel
     * (Gruppe vor ihrer Einführung gespeichert), gilt das Recht als erteilt, bestehende Gruppen ändern sich nicht.
     */
    public const GALLERY_RIGHTS = ['view', 'upload', 'download', 'recent', 'all'];
    public const GALLERY_DEFAULT_ON = ['recent', 'all'];

    /** @return array<string, string> Recht => übersetzte Beschriftung */
    public static function galleryRights(): array
    {
        return array_combine(self::GALLERY_RIGHTS, array_map(fn($k) => t('groups.gal_' . $k), self::GALLERY_RIGHTS));
    }

    /**
     * Galerie: `view`, `upload`, `download`, `recent` (Block „Zuletzt hinzugefügte Bilder“),
     * `all` (Gesamtübersicht aller Bilder; ohne das Recht nur Alben).
     */
    public static function gallery(string $what = 'view'): bool
    {
        if (!Auth::check() || Auth::isShare()) {
            return false;
        }
        if (Auth::isAdmin()) {
            return true;
        }
        $g = self::group()['gallery'] ?? [];
        if (empty($g['view'])) {
            return false;
        }
        if ($what === 'view') {
            return true;
        }
        if (in_array($what, self::GALLERY_DEFAULT_ON, true)) {
            return (bool)($g[$what] ?? true);
        }
        return !empty($g[$what]);
    }

    /**
     * Darf die Gruppe des Benutzers das Plugin nutzen? Fehlt der Eintrag (Gruppe vor dem Plugin gespeichert), gilt
     * das Plugin als freigegeben. Admins dürfen immer.
     */
    public static function plugin(string $slug): bool
    {
        if (!Auth::check()) {
            return false;
        }
        if (Auth::isAdmin()) {
            return true;
        }
        return (bool)(self::group()['plugins'][$slug] ?? true);
    }

    public static function needAdmin(): void
    {
        if (!Auth::isAdmin()) {
            abort(403, t('perm.admin_only'));
        }
    }

    /** Erste erlaubte Seite (für Freigabelinks ohne Gedenkseite). */
    public static function firstRoute(): ?string
    {
        if (self::memorial()) {
            return 'home';
        }
        foreach (self::MODULES as $m) {
            if (self::can($m)) {
                return $m;
            }
        }
        return null;
    }
}
