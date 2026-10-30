# Plugins entwickeln

Ein Plugin ist ein Ordner `plugins/<slug>/`. Verwaltet wird es unter **Verwaltung → Plugins** (aktivieren,
deaktivieren, importieren, exportieren, löschen). Als Vorlage dient `plugins/stations/`.

> Ein Plugin ist beliebiger PHP-Code mit vollem Zugriff auf Anwendung und Daten. Es gibt keine Sandbox.
> Importieren dürfen nur Admins; der Import warnt entsprechend.

## Aufbau

```
plugins/<slug>/
├── plugin.json        Manifest (Pflicht)
├── plugin.php         Einstiegspunkt (Pflicht), registriert Routen und Menüpunkte
├── controllers/       beliebige Controller-Dateien (über $p->route() eingebunden)
├── lib/<Klasse>.php   Autoload: Klassenname = Dateiname (nur wenn aktiv; Kernklassen haben Vorrang)
├── views/<name>.php   view('@<slug>/<name>', $vars)
├── assets/…           css, js, svg, png, jpg, gif, webp, woff, woff2 → plugin_asset('<slug>', 'js/x.js')
└── lang/{de,en}/*.php Sprachdateien, werden zu denen des Kerns ergänzt (Kern-Schlüssel haben Vorrang)
```

## plugin.json

```json
{
    "slug": "beispiel",
    "name": "Beispiel",
    "version": "1.0.0",
    "description": "Ein Satz, was das Plugin tut.",
    "author": "Name",
    "requires_app": "0.0.4",
    "requires": ["anderes-plugin"],
    "stores": { "beispiel_daten": [] },
    "core": false,
    "default_active": false
}
```

| Feld | Bedeutung |
|---|---|
| `slug` | Kennung = Ordnername, `[a-z][a-z0-9-]`, 2–40 Zeichen |
| `name`, `version` | Anzeigename, `x.y.z` |
| `requires_app` | Mindestversion der Anwendung (`VERSION`); sonst lässt sich das Plugin nicht aktivieren |
| `requires` | Slugs anderer Plugins, die aktiv sein müssen; solange ein aktives Plugin ein anderes braucht, lässt dieses sich nicht deaktivieren |
| `stores` | Datendateien `data/<name>.json` mit Standardinhalt (`[a-z_]`, nicht die des Kerns). Sie gehören zu den Snapshots und Backups. Löschen mit „Daten ebenfalls löschen“ entfernt genau diese Dateien |
| `core` | Teil der Anwendung: lässt sich deaktivieren, aber nicht löschen |
| `default_active` | Zustand, solange ein Admin nichts entschieden hat (importierte Plugins starten immer inaktiv) |

## plugin.php

```php
<?php
declare(strict_types=1);

return function (PluginApi $p): void {
    $p->route('beispiel', 'controllers/beispiel.php', 'ctrl_beispiel');
    $p->nav('beispiel', 'bsp.nav', 'bi-star', fn() => Perm::memorial(), 'home');
    $p->onActivate(fn() => Log::add('admin', 'Beispiel aktiviert'));
};
```

| Aufruf | Wirkung |
|---|---|
| `route($route, $datei, $funktion)` | `index.php?r=<route>` (`[a-z]{2,24}`). Die Datei wird erst bei Aufruf geladen und enthält `function ctrl_xyz(string $r, string $a): void`. Routen verlangen immer eine Anmeldung; **Rechte prüft der Controller** (`Perm::…`). Kernrouten haben Vorrang, doppelte Plugin-Routen lassen das später geladene Plugin scheitern |
| `apiRoute($route, $datei, $funktion)` | Wie `route()`, aber **ohne** Sitzungsanmeldung und CSRF-Prüfung, für Maschinenzugriff (Web-API, MCP). Der Controller muss selbst authentifizieren (z. B. Token) und danach `Auth::actAs($benutzer)` aufrufen; Sitzungs-Cookies dürfen dort nie als Anmeldung gelten. Vorbild: `plugins/api/` |
| `nav($route, $sprachschluessel, $icon, ?$sichtbar, $nach)` | Menüpunkt (Bootstrap-Icon `bi-…`); `$sichtbar` ist ein Callback `fn(): bool`, `$nach` eine Route, hinter der der Punkt erscheint |
| `onActivate($fn)` / `onDeactivate($fn)` | einmalig beim Aktivieren bzw. vor dem Deaktivieren durch einen Admin |
| `onUpdate($fn)` | einmalig nach einer Aktualisierung über den Marktplatz, beim ersten Aufruf mit dem neuen Code; `$fn(string $von)` erhält die vorherige Version (z. B. für Datenmigration). Das Plugin bleibt während des Updates aktiv |

Wirft `plugin.php` eine Ausnahme, wird das Plugin nicht geladen (Meldung in Verwaltung → Plugins und im Server-Log).

## Regeln (wie im Kern)

- Daten nur über `Store::read()` / `Store::update()`, schreibende Aktionen nur per POST (CSRF läuft in `dispatch()`),
  Rechteprüfung im Controller, Protokolleintrag (`Log::change()` / `Log::add()`).
- Ausgabe in Views nur über `e()`; keine Inline-Skripte (CSP) – Daten an JS über `data-*` oder
  `<script type="application/json">`; Texte über `t()` aus den Sprachdateien (beide Sprachen, `php bin/check-lang.php` prüft auch Plugins).
- Keine absoluten URLs: `url()`, `plugin_asset()`. Eigene CSS-Dateien hängt die View über `$styles = [plugin_asset(…)]` ein;
  Skripte mit `<script src="<?= e(plugin_asset('slug', 'js/x.js')) ?>" defer>`.
- Eigene Sprachschlüssel mit eigenem Präfix (`bsp.…`, JS-Texte `js.bsp_…`).
- Plugin-Assets werden nur bei aktivem Plugin und nur für angemeldete Benutzer ausgeliefert (Route `pluginasset`).

## ZIP für den Import

Die ZIP-Datei enthält genau ein Plugin, entweder direkt (`plugin.json` im Hauptverzeichnis) oder in einem Ordner.
Der Import prüft: Größe (ZIP ≤ 10 MB, entpackt ≤ 20 MB, ≤ 300 Dateien), keine Pfadtricks oder Verknüpfungen, nur die oben
genannten Dateitypen (keine Dateien, die mit einem Punkt beginnen), PHP-Syntax, Manifest, Mindestversion und
Datenkollisionen. Ein vorhandenes Plugin wird nicht überschrieben: zuerst löschen (Daten behalten), dann importieren.
Der Export einer Plugin-ZIP liefert dieselbe Struktur (ohne Nutzdaten).

## Notausgang

Verhindert ein Plugin den Start der Anwendung, in `configs/config.php` `'plugins_safe_mode' => true` setzen: Es wird dann
kein Plugin-Code ausgeführt. Danach das Plugin deaktivieren oder löschen und den Schalter wieder entfernen.
