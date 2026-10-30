# Plugin-Marktplatz über GitHub – Konzept (Entwurf)

Stand: 30.10.2026. Ergebnis eines Planungschats mit Claude. **Umsetzungsstand: Schritte 1 (Kern), 2 (GitHub-Client) und 3 (Verwaltungsoberfläche) fertig, Schritt 4 offen** (siehe Abschnitt 9).
Bestehendes Plugin-System: `docs/plugin-development.md`, `app/lib/Plugins.php`, `app/lib/PluginApi.php`.

> Der ursprüngliche Entwurf (Claude Doc „Funeral Org – Plugin-System“) ging von MySQL, Namespaces und einem
> `public/`-Ordner aus und kannte das vorhandene Plugin-System nicht. **Verbindlich ist dieses Dokument**, das den
> Entwurf an die Regeln aus `CLAUDE.md` anpasst (kein DB, kein Composer, `Store`, `t()`, `e()`, `url()`).
> Code aus dem Entwurf nur als Ideenquelle verwenden, nicht übernehmen.

## 1. Ziel

Weitere Plugins direkt aus der Verwaltung finden, prüfen, installieren, aktualisieren – Quelle sind GitHub-Repositories.
Der vorhandene ZIP-Import bleibt und wird für GitHub-Pakete wiederverwendet.

## 2. Getroffene Entscheidungen

| Thema | Entscheidung |
|---|---|
| Fundort | Öffentliche GitHub-Repos mit Topic `funeral-org-plugin`; Repo-Name soll `funeral-org-<slug>` lauten |
| Paket | GitHub-Release mit Semver-Tag (`v1.2.0` = `version` im Manifest) und drei Assets: `<slug>-<version>.zip`, `<slug>-<version>.zip.sha256` (`<hash>  <datei>`), `plugin.json` (Kopie des Manifests, für Kompatibilitätsprüfung ohne ZIP-Download) |
| Releases | Veröffentlichte Releases sind unveränderlich; Korrektur = neue Version. Drafts, Pre-Releases und unvollständige Releases werden nicht angezeigt |
| Ablageort | Plugins bleiben im Webroot (ISPConfig-Mounts, außerhalb nicht praktikabel) |
| Inaktive Plugins | Liegen **nur als ZIP** in `plugins/_archive/`. Aktivieren = Hash prüfen, nach `plugins/_tmp/<zufall>` entpacken, per `rename()` nach `plugins/<slug>/`. Deaktivieren = Ordner löschen, ZIP bleibt (Backup/Rollback). Nutzdaten (`stores`) bleiben unberührt |
| Temp-Verzeichnis | `plugins/_tmp/`, **nicht** `/tmp` – `rename()` ist nur auf demselben Dateisystem atomar |
| Webserver-Schutz | Bereits vorhanden: `plugins/.htaccess` mit `Require all denied` (deckt `_archive/` und `_tmp/` mit ab). Weitere Regeln nur als eigene `.htaccess` im Unterverzeichnis, **nie** in der globalen `.htaccess`. Ziel ist Apache; Nginx-Admins sichern selbst ab (`docs/nginx-ispconfig.conf` ergänzen) |
| Assets | Kein Kopieren nach `public/` nötig: Route `pluginasset` liefert schon nur bei aktivem Plugin und nur angemeldet aus |
| Prüfsumme | SHA-256 beim Download gegen `.sha256`-Asset und bei **jeder Aktivierung** erneut gegen den gespeicherten Wert |
| GitHub-Token | Optional hinterlegbar (60 → 5.000 API-Anfragen/h). Anzeige nur als „hinterlegt“, nie im Klartext |
| Cache | API-Antworten 1 h mit ETag/`If-None-Match`; Manifeste aus Releases dauerhaft (Releases sind unveränderlich) |
| Downloads | Nur HTTPS, nur `github.com`, `api.github.com`, `objects.githubusercontent.com` (auch nach Redirects geprüft), Größenlimit = bestehende Import-Grenze |
| Audit | Optional `audit.commit` (40-stelliger Git-SHA) im Manifest + `AUDIT.md` im Paket. Installer vergleicht mit dem Commit des Release-Tags. Ein ZIP-Hash im Manifest ist unmöglich (Manifest liegt in der ZIP). Audit ≠ Garantie, gilt nur für diesen Stand |
| Telemetrie | Keine. Beliebtheit über GitHub-Sterne und Release-Downloadzähler |
| Ehrliche Grenze | Plugins laufen ohne Sandbox im selben PHP-Prozess. Berechtigungsangaben sind Transparenz, kein Schutz – Schutz bieten nur Audit und vertrauenswürdige Quellen |

## 3. Manifest-Erweiterung

Vorhandene Felder bleiben (`slug`, `name`, `version`, `description`, `author`, `requires_app`, `requires`, `stores`,
`core`, `default_active`). Neu und optional:

```json
{
    "repository": "eduard/funeral-org-beispiel",
    "license": "MIT",
    "requires_php": "8.2",
    "permissions": ["network:tile.openstreetmap.org"],
    "audit": { "commit": "<40-stelliger Git-SHA>", "report": "AUDIT.md" }
}
```

- `permissions` nur aus fester Liste, im Bestätigungsdialog angezeigt (rein informativ).
- PHP-Versionsvergleich mit `PHP_MAJOR_VERSION.PHP_MINOR_VERSION.PHP_RELEASE_VERSION`, nicht `PHP_VERSION`
  (Debian: `8.2.12-1+deb12u1` würde sonst als Pre-Release gewertet).
- **Offen:** `requires_app` bleibt Mindestversion oder wird Semver-Bereich (`>=0.1 <1.0`, `^0.4`)? Bei Bereich eigene
  kleine `SemverRange`-Klasse ohne Composer (Hinweis: `^0.x` wie Composer behandeln; obere Grenze intern mit `-dev`,
  sonst lässt `version_compare` `2.0.0-beta` durch).

## 4. Abläufe

**Installieren (zwei Schritte, Plugin danach inaktiv)**
1. *Prüfen:* Release laden → vollständig? → ZIP + `.sha256` nach `plugins/_tmp/` → Hash vergleichen →
   bestehende Import-Prüfungen (Größe, Dateizahl, Pfade, Symlinks, Dateitypen, PHP-Syntax, Manifest, Mindestversion,
   Datenkollision) → Version im Manifest = Release-Tag → Audit-Commit abgleichen.
2. *Bestätigungsseite:* Berechtigungen, Abhängigkeiten, Audit-Status (passt / anderer Stand / keins), deutlicher Hinweis
   „kein Sandbox“.
3. *Übernehmen:* Hash erneut prüfen, nur Dateien aus `plugins/_tmp/` akzeptieren (`realpath`-Prüfung) → nach
   `plugins/_archive/<slug>-<version>.zip` → Herkunft (Repo, Version, Hash) speichern. Gleicher Slug aus anderem Repo → Abbruch.

**Aktivieren:** Hash prüfen → entpacken nach `_tmp` → Slug im Manifest = erwarteter Slug → `rename()` → `onActivate`
→ erst dann Status aktiv. Fehler → entpackten Ordner wieder entfernen.

**Deaktivieren:** Status zuerst inaktiv (Loader lädt nicht mehr) → `onDeactivate` → Ordner löschen. Fehler im Hook nur
protokollieren.

**Update:** neue Version prüfen (muss neuer sein, gleicher Slug) → falls aktiv deaktivieren → übernehmen → aktivieren
(+ Update-Hook mit alter Version, falls eingeführt). Fehler → alte Version/Hash/Status zurück, alte ZIP aktivieren.
Alte ZIP bleibt als Rollback liegen.

**Löschen:** bestehende Semantik („Daten ebenfalls löschen“) beibehalten; zusätzlich alle `_archive/<slug>-*.zip` entfernen.

## 5. Verwaltungsoberfläche

Reiter „Installiert“ / „Suchen“ in Verwaltung → Plugins (`app/views/admin_plugins.php`).
- Suche: Name, Beschreibung, Sterne, letzte Aktivität, Hinweis „bereits installiert“.
- Details: Releases, inkompatible ausgegraut mit Grund.
- Update-Hinweise je Plugin (neueste kompatible Version); bei API-Limit entfällt der Hinweis still.
- Kopf: verbleibende API-Anfragen; rote Warnung, wenn der Selbsttest fehlschlägt.
- Alles von GitHub ist Fremdinhalt: Ausgabe nur über `e()`, Release-Notes nur als Klartext.
- Nur Admins, POST + CSRF (läuft über `dispatch()`), Protokolleintrag `Log::add('admin', …)`, alle Texte über `t()` (de + en).

## 6. Selbsttest

Verwaltung ruft per HTTP eine Testdatei unter `plugins/` (und `plugins/_archive/`) auf. Erwartet 403/404; bei 200
deutliche Warnung („Plugin-Verzeichnis von außen erreichbar – .htaccess/AllowOverride bzw. Nginx prüfen“).
URL über `url()`/Basis ermitteln (Unterverzeichnis-Installationen!).

## 7. Ergänzungen für `docs/plugin-development.md` (Richtlinie)

- Repo/Release-Regeln aus Abschnitt 2, Checkliste vor Release.
- Verboten: `eval`, `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`, dynamische Includes mit
  Benutzereingaben, `unserialize()` auf Fremddaten.
- Netzwerkzugriffe nur zu Hosts aus `permissions`.
- Datenschutz: keine Telemetrie/Weitergabe ohne Opt-in; README nennt verarbeitete Daten und externe Ressourcen.
- Release per GitHub Action bauen (Hash automatisch).

## 8. Entscheidungen zu den offenen Punkten (30.10.2026, nach Lesen von `Plugins.php`)

- **Temp-Verzeichnis:** vorhandenes `plugins/.tmp-*` weiterverwenden (nicht `plugins/_tmp/`); neu ist nur `plugins/_archive/`.
- **Herkunft und Token:** eigener Store `market` (`data/market.json`), **nicht** `settings.json` – `Transfer` exportiert `settings`, `saveState()` überschreibt den Plugin-Eintrag komplett, und ein Import brächte Status ohne ZIP-Archive mit. Token optional per `configs/config.php → github_token` (hat Vorrang). Hinweis: `market.json` landet in den täglichen Snapshots (`data/backups/`), wie alle anderen Datendateien.
- **`requires_app`:** bleibt Mindestversion, kein Semver-Bereich (Version < 1.0.0, „anything may change“).
- **Hooks:** `onUpdate` ist eingeführt (läuft einmal beim ersten Laden mit neuem Code, `pending_from` in `market`). Kein Uninstall-Hook – „Daten ebenfalls löschen“ erledigt die `stores`.
- **Update ohne Deaktivieren:** ein aktives Plugin wird per `rename()` ausgetauscht; so bleiben abhängige Plugins aktiv, und „Alle aktualisieren“ scheitert nicht an Abhängigkeiten.
- **`core`/mitgelieferte Plugins:** Archivmodell nur für Plugins mit Eintrag in `market.sources`. Stationen, API und manuelle ZIP-Importe bleiben Ordner.
- **Deaktivieren-Reihenfolge:** wie im Code – erst Hook `deactivate` (Plugin noch geladen), dann Status, dann Ordner löschen.
- **Erlaubte Hosts:** `github.com`, `api.github.com`, `objects.githubusercontent.com` und zusätzlich `release-assets.githubusercontent.com` (GitHub leitet Release-Downloads inzwischen darauf um; kein offizielles GitHub-Dokument gefunden, Stand aus Community-Berichten – bei Download-Fehlern „Adresse nicht erlaubt“ prüfen).
- **Manifest-Berechtigungen:** feste Liste `network:<host>`, `mail`, `files`, `data:users|contacts|gallery|memorial|checklist`; unbekannte Einträge machen das Manifest ungültig. Erweiterung nur in `Plugins::PERMISSION_RE`.
- **Nginx:** im empfohlenen Layout liegt `plugins/` außerhalb des Webroots; das Snippet ist nur für das Einzelverzeichnis-Layout nötig.

## 9. Umsetzungsplan

1. **Kern** (`Plugins.php`, `PluginApi.php`, `Store.php`) – **fertig:** `inspect()`/`stage()`, Archiv, `archive()`, `update()`, Aktivieren/Deaktivieren/Löschen/Export für archivierte Plugins, `onUpdate`, Hash-Prüfung bei jeder Aktivierung.
2. **GitHub-Client** `PluginMarket.php` – **fertig** (getestet gegen lokalen Mock): Suche nach Topic, Releases und Assets, Host-Whitelist auch nach Redirects, ETag-Cache in `data/cache/`, Token, Basis-URL per Config überschreibbar (Rauchtest mit lokalem Mock).
3. **Verwaltungsoberfläche** – **fertig** (Ende-zu-Ende gegen den Mock getestet; Rollback-Rückfall und Anleitung `docs/plugin-guide.md` inklusive): Reiter „Installiert“ / „Suchen“, Bestätigungsseite, Installieren, Aktualisieren.
   - **Aktualisierungen prüfen:** Schaltfläche „Nach Aktualisierungen suchen“ vergleicht alle Marktplatz-Plugins mit dem neuesten kompatiblen Release (Ergebnis wird gemerkt, Anzeige je Plugin „Version x → y“, Hinweis am Reiter).
   - **Alle aktualisieren:** Schaltfläche „Alle aktualisieren (n)“ aktualisiert nacheinander alle Plugins mit verfügbarem Update (je Plugin: Download, Hash, `Plugins::update()`), mit Ergebnisliste (aktualisiert / übersprungen mit Grund / Fehler); ein Fehler bricht die übrigen nicht ab. Vorher Bestätigung mit Liste der Plugins und Versionen. Bei Plugins, die neue `permissions` anfordern, wird **nicht** automatisch aktualisiert, sondern die Bestätigungsseite verlangt (wie im Play Store bei geänderten Berechtigungen).
   - Einzelne Plugins lassen sich weiter einzeln aktualisieren; Rückfall auf die vorherige Version über die behaltene ZIP (`previous`).
4. **Selbsttest, Doku, Rauchtest:** Selbsttest für `plugins/`, `docs/plugin-development.md` (Richtlinie), Nginx-Snippet, ECKDATEN.md, Erweiterung von `bin/smoke-test.sh`.
