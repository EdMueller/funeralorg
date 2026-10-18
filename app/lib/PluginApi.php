<?php
declare(strict_types=1);

/**
 * Schnittstelle, die ein Plugin in plugin.php erhält:
 *
 *   return function (PluginApi $p): void {
 *       $p->route('beispiel', 'controllers/beispiel.php', 'ctrl_beispiel');
 *       $p->nav('beispiel', 'bsp.nav', 'bi-star', fn() => Perm::memorial(), 'home');
 *   };
 *
 * Alles Weitere (Datendateien, Sprachdateien, Klassen, Views, Assets) ergibt sich aus Manifest und Ordnerstruktur,
 * siehe docs/plugin-development.md.
 */
final class PluginApi
{
    public function __construct(private string $slug, private string $dir)
    {
    }

    public function slug(): string
    {
        return $this->slug;
    }

    /**
     * Route `index.php?r=<route>`. Sie verlangt immer eine Anmeldung; Rechte prüft der Controller selbst.
     * @param string $file Pfad der Controller-Datei relativ zum Plugin-Ordner
     * @param string $fn   Funktion `fn(string $r, string $a): void`
     */
    public function route(string $route, string $file, string $fn): void
    {
        Plugins::registerRoute($this->slug, $this->dir, $route, $file, $fn);
    }

    /**
     * Menüpunkt in der Hauptnavigation.
     * @param string        $label   Sprachschlüssel
     * @param callable|null $visible liefert true, wenn der Punkt für den aktuellen Benutzer erscheint (Standard: immer)
     * @param string        $after   Route, hinter der der Punkt eingefügt wird (Standard: am Ende)
     */
    public function nav(string $route, string $label, string $icon, ?callable $visible = null, string $after = ''): void
    {
        Plugins::registerNav($this->slug, $route, $label, $icon, $visible, $after);
    }

    /** Wird einmal ausgeführt, wenn ein Admin das Plugin aktiviert (z. B. Daten vorbereiten). */
    public function onActivate(callable $fn): void
    {
        Plugins::registerHook($this->slug, 'activate', $fn);
    }

    /** Wird einmal ausgeführt, bevor ein Admin das Plugin deaktiviert. */
    public function onDeactivate(callable $fn): void
    {
        Plugins::registerHook($this->slug, 'deactivate', $fn);
    }
}
