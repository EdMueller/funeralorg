# Plugin-Leitfaden

Anleitung zum Programmieren eigener Plugins: Aufbau, ein Beispiel Schritt für Schritt, die verfügbaren Funktionen,
Best Practices und die Veröffentlichung über den Marktplatz. Die knappe Referenz zu Manifest und `PluginApi` steht in
[`plugin-development.md`](plugin-development.md), das Marktplatz-Konzept in [`plugin-marketplace.md`](plugin-marketplace.md),
die technische Gesamtreferenz in `ECKDATEN.md`.

> **Plugins laufen ohne Sandbox.** Ein Plugin ist PHP-Code im selben Prozess wie die Anwendung, mit Zugriff auf alle
> Daten. Wer eines installiert, vertraut dem Autor. Schreibe deshalb so, dass sich dein Code prüfen lässt: klein, ohne
> Überraschungen, ohne versteckte Netzwerkzugriffe.

## 1. Was ein Plugin kann – und was nicht

Ein Plugin kann:

- **eigene Seiten** (Routen) mit eigenem Menüpunkt bereitstellen,
- **eigene Datendateien** (`data/<name>.json`) führen, die in Snapshots und Backups landen,
- eigene **Klassen, Views, Styles, Skripte, Bilder und Übersetzungen** mitbringen,
- Kernfunktionen nutzen (Anmeldung, Rechte, Protokoll, Übersetzung, Speicher),
- beim Aktivieren, Deaktivieren und nach einem Update eigenen Code ausführen,
- über `apiRoute()` Maschinenzugriff (z. B. Web-API) anbieten.

Ein Plugin kann **nicht** (bewusst, „erst bei Bedarf“):

- neue Rechtebereiche in der Gruppenverwaltung anlegen (`Perm::MODULES` ist fest),
- Seiten der Verwaltung ändern oder Kernseiten erweitern,
- Kernrouten, Kernklassen oder Kernfunktionen ersetzen (Kernrouten haben Vorrang),
- Skripte von Fremdservern laden – die Content-Security-Policy erlaubt nur `'self'`.

## 2. Aufbau

```
plugins/<slug>/
├── plugin.json        Manifest (Pflicht)
├── plugin.php         Einstiegspunkt (Pflicht): registriert Routen, Menüpunkte, Hooks
├── controllers/       Controller (über $p->route() eingebunden)
├── lib/<Klasse>.php   Klassen, Autoload nach Dateiname (nur bei aktivem Plugin)
├── views/<name>.php   view('@<slug>/<name>', $vars)
├── assets/…           css, js, svg, png, jpg, gif, webp, woff, woff2
└── lang/{de,en}/*.php Übersetzungen, ergänzen die des Kerns
```

Zeitpunkt der Ausführung: `plugin.php` läuft **bei jeder Anfrage** (bei aktivem Plugin), Controller-Dateien erst, wenn
ihre Route aufgerufen wird. Halte `plugin.php` deshalb klein: nur registrieren, keine Arbeit.

Erlaubte Dateien im Paket: `php json js css svg png jpg jpeg gif webp woff woff2 txt md` sowie `LICENSE`, `README`,
`NOTICE` (ohne Endung). Dateinamen müssen mit Buchstabe, Ziffer oder `_` beginnen – **keine Punktdateien**
(`.gitignore`, `.github/` gehören nicht ins Paket). Grenzen: ZIP ≤ 10 MB, entpackt ≤ 20 MB, ≤ 300 Dateien.

## 3. Schnellstart: das Beispiel „Notizen“

Ein vollständiges, getestetes Plugin liegt in [`docs/examples/notizen/`](examples/notizen/). Es zeigt Route, Menüpunkt,
Datendatei, Klasse, View, CSS, Übersetzungen und einen Update-Hook. Zum Ausprobieren den Ordner `notizen` als ZIP packen
und unter **Verwaltung → Plugins → Plugin importieren** hochladen, dann aktivieren.

Die wichtigsten Teile:

**`plugin.json`** – Kennung, Version, Voraussetzungen, Datendateien:

```json
{
    "slug": "notizen",
    "name": "Notizen",
    "version": "1.0.0",
    "description": "Gemeinsame Notizen für alle angemeldeten Benutzer (Beispiel-Plugin).",
    "author": "Beispiel",
    "requires_app": "0.0.17",
    "requires_php": "8.2",
    "license": "MIT",
    "permissions": [],
    "stores": { "notizen": [] }
}
```

**`plugin.php`** – registriert nur:

```php
return function (PluginApi $p): void {
    $p->route('notizen', 'controllers/notizen.php', 'ctrl_notizen');
    $p->nav('notizen', 'nz.nav', 'bi-sticky', fn() => Perm::memorial(), 'checklist');
};
```

**Controller** – prüft Rechte, validiert, schreibt über `Store`, protokolliert, leitet um:

```php
function ctrl_notizen(string $r, string $a): void
{
    if (!Perm::memorial()) {
        abort(403, t('nz.denied'));
    }
    if ($a === 'add') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            abort(400);
        }
        $text = Notizen::clean((string)post('text'));
        if ($text === '') {
            flash(t('nz.err_empty'), 'warning');
            redirect('notizen');
        }
        Store::update('notizen', function (array &$list) use ($text) {
            $list[] = ['id' => new_id(), 'text' => $text, 'by' => Auth::user()['name'] ?? '', 'created' => now()];
        });
        Log::change('notizen', 'Notiz angelegt');
        flash(t('nz.added'));
        redirect('notizen');
    }
    view('@notizen/notizen', ['notes' => Notizen::all()]);
}
```

**View** – jede Ausgabe über `e()`, Formulare mit `Csrf::field()`, Texte über `t()`:

```php
<form method="post" action="<?= e(url('notizen', ['a' => 'add'])) ?>">
  <?= Csrf::field() ?>
  <textarea class="form-control" name="text" maxlength="<?= (int)Notizen::MAX_LENGTH ?>" required></textarea>
  <button class="btn btn-primary" type="submit"><?= e(t('nz.add')) ?></button>
</form>
```

## 4. Referenz: was dir zur Verfügung steht

Diese Funktionen und Klassen sind die vorgesehene Schnittstelle für Plugins. Alles andere im Kern kann sich ohne
Ankündigung ändern (siehe 4.9).

### 4.1 Hilfsfunktionen (`app/helpers.php`)

| Funktion | Zweck |
|---|---|
| `e($s)` | HTML-Ausgabe maskieren. **Jede** Ausgabe in Views |
| `t($key, $params = [])` / `tn($key, $n, $params = [])` | Text aus den Sprachdateien; `tn` wählt die Pluralform (`one`/`other`), `{n}` ist die Zahl |
| `url($route, $params = [])` | Link `index.php?r=…`; funktioniert an der Domainwurzel und im Unterverzeichnis |
| `asset($pfad)` / `plugin_asset($slug, $pfad)` | URL zu Kern- bzw. Plugin-Dateien |
| `base_path()` | Verzeichnisbezug der Installation (nie Pfade fest eintragen) |
| `view($vorlage, $vars = [], $layout = 'layout')` | View rendern; `'@slug/name'` = View des Plugins; Layout `null` = ohne Rahmen |
| `redirect($route, $params = [])` / `redirect_back($fallback)` | Weiterleitung (beendet die Anfrage) |
| `flash($text, $typ = 'success')` | Meldung für die nächste Seite; Typen: `success`, `warning`, `danger`, `info` |
| `post($k, $d = '')` / `get($k, $d = '')` | Eingabe lesen (Zeichenketten werden gekürzt um Leerraum, **nicht** validiert) |
| `abort($code, $text = '')` | Anfrage mit Fehlerseite beenden (400, 403, 404 …) |
| `json_out($daten, $code = 200)` / `is_ajax()` | JSON-Antwort bzw. Erkennung von `fetch`-Aufrufen |
| `new_id()` / `now()` | zufällige ID (16 Hex-Zeichen) / aktueller Zeitpunkt (ISO 8601) |
| `find_by_id($liste, $id)` | Eintrag mit `id` in einer Liste oder `null` |
| `fmt_date($iso, $mitUhrzeit = false)` / `fmt_ts($ts, $art)` / `fmt_bytes($n)` / `fmt_money($v)` | Formatierung nach Sprache des Benutzers |
| `slug($text)` | Dateiname-tauglich machen |
| `cfg($schluessel, $standard)` | Wert aus `configs/config.php` |
| `app_version()` | Version der Anwendung (`VERSION`) |
| `client_ip()` | IP-Adresse der Anfrage |

### 4.2 Datenspeicher `Store`

```php
$liste = Store::read('notizen');                 // lesen (Shared-Lock, pro Anfrage zwischengespeichert)
Store::update('notizen', function (array &$d) {  // ändern: Exklusiv-Lock, atomar geschrieben
    $d[] = [...];
    return $irgendwas;                           // Rückgabewert von update()
});
```

- Datendateien müssen im Manifest unter `stores` stehen (Name `[a-z_]`, 2–40 Zeichen, nicht die des Kerns). Der Wert ist
  der Standardinhalt, solange die Datei fehlt.
- **Immer `Store::update()` für Änderungen** – nie lesen, ändern und mit `file_put_contents` zurückschreiben. Parallele
  Anfragen würden sich sonst gegenseitig Einträge löschen.
- Innerhalb von `update()` nichts Langsames tun (kein Netzwerk, keine Bildverarbeitung): die Datei ist solange gesperrt.
- Dateien sind JSON; speichere nur JSON-taugliche Werte. Eine kaputte Datei lässt `Store::read()` mit einer Meldung
  abbrechen, der Snapshot in `data/backups/` hilft.
- Kernstores (`gallery`, `contacts`, `users` …) darfst du lesen. Schreibe nur in sie, wenn es dein Plugin ausdrücklich
  tut und du die Struktur aus `ECKDATEN.md` einhältst; besser ist eine eigene Datei mit Verweis per ID.

### 4.3 Anmeldung `Auth`

| Aufruf | Bedeutung |
|---|---|
| `Auth::user()` | `null` oder Array mit `kind` (`user`/`share`), `id`, `name`, `username`, `role` (`admin`/`user`/`share`), `group_id` |
| `Auth::check()` / `Auth::isAdmin()` / `Auth::isShare()` | angemeldet / Administrator / Zugang über Freigabelink |
| `Auth::actAs($benutzer)` | nur für `apiRoute()`: nach eigener Token-Prüfung als dieser Benutzer handeln |

Freigabelinks sind Anmeldungen mit eingeschränkten Rechten. **Sie dürfen nie Gedenkseite, Galerie oder Bilder sehen** –
wer solche Inhalte zeigt, prüft `Perm::memorial()` bzw. `Perm::gallery()` (beide sind für Freigabelinks `false`).

### 4.4 Rechte `Perm`

Stufen: `Perm::NONE` (0), `READ` (1), `CHECK` (2, abhaken/Status), `EDIT` (3, anlegen/ändern/löschen).

| Aufruf | Bedeutung |
|---|---|
| `Perm::memorial()` | angemeldeter Benutzer, kein Freigabelink – die übliche Bedingung für eigene Seiten |
| `Perm::can($modul, $min = READ)` / `Perm::need($modul, $min)` | Recht auf einen Kernbereich (`checklist`, `contacts`, `rsvp`, `condolences`); `need` bricht mit 403 ab |
| `Perm::field($feld)` | Feldrecht (`address`, `phone`, `mobile`, `email`) – gilt für Anzeige, Export **und** Speichern |
| `Perm::gallery($was = 'view')` | Galerierechte (`view`, `upload`, `download`) |
| `Perm::needAdmin()` | 403, wenn kein Administrator |

Richtlinie für eigene Seiten: lesen darf, wer `Perm::memorial()` erfüllt (oder ein passender Kernbereich); verändern nur
Administratoren oder wer das passende Kernrecht auf `EDIT` hat. Zeigt dein Plugin Kontaktdaten, **müssen** die Feldrechte
gelten.

### 4.5 Sicherheit: CSRF und Protokoll

- `Csrf::field()` in jedes `<form method="post">`. Die Prüfung läuft für **jedes** POST automatisch in `dispatch()`.
  Für `fetch`: Header `X-CSRF-Token` mit dem Wert aus `<meta name="csrf">`.
- `Log::change($modul, $was, $objekt)` für Datenänderungen, `Log::add('admin', $text)` für Verwaltungsaktionen. Jede
  schreibende Aktion wird protokolliert (Wer, wann, was – nie Inhalte oder Passwörter).

### 4.6 Sprache

- Schlüssel in `lang/de/*.php` **und** `lang/en/*.php`, gleiche Schlüssel und Platzhalter (`php bin/check-lang.php`
  prüft auch Plugins). Eigenes Präfix: `nz.…`; Texte für JavaScript mit Präfix `js.` (`js.nz_…`).
- Plural: `'nz.lead' => ['one' => '{n} Notiz', 'other' => '{n} Notizen']`, aufgerufen mit `tn('nz.lead', $n)`.
- Deutsch: Du-Form, Satzschreibung, Buttons nennen die Aktion („Notiz speichern“, nicht „OK“).
- Kern-Schlüssel haben Vorrang vor gleichnamigen Plugin-Schlüsseln – das Präfix verhindert Kollisionen.
- Protokolltexte bleiben Deutsch (sie sind Audit-Daten); Oberflächentexte nie fest im Code.

### 4.7 `PluginApi` (Parameter von `plugin.php`)

| Aufruf | Wirkung |
|---|---|
| `route($route, $datei, $funktion)` | Seite `index.php?r=<route>`; Route `[a-z]{2,24}`, **Anmeldung Pflicht**, Rechte prüft dein Controller |
| `apiRoute($route, $datei, $funktion)` | Maschinenzugriff ohne Sitzung/CSRF; Controller authentifiziert selbst (Token) und ruft `Auth::actAs()`; Sitzungs-Cookies nie als Anmeldung akzeptieren |
| `nav($route, $schluessel, $icon, $sichtbar, $nach)` | Menüpunkt; `$sichtbar` ist `fn(): bool`; `$nach` eine Route, hinter der der Punkt erscheint |
| `onActivate($fn)` | einmal, wenn ein Admin das Plugin aktiviert (Daten vorbereiten) |
| `onDeactivate($fn)` | einmal, bevor ein Admin es deaktiviert (aufräumen; Daten **nicht** löschen) |
| `onUpdate($fn)` | einmal nach einem Marktplatz-Update, beim ersten Aufruf mit dem neuen Code; `$fn(string $vonVersion)` |
| `slug()` | Kennung des Plugins |

Hooks laufen nur bei Aktionen in der Verwaltung bzw. beim Update; sie ersetzen keine Prüfung beim normalen Betrieb.
Fehler in `onDeactivate`/`onUpdate` werden nur ins Server-Log geschrieben, ein Fehler in `onActivate` macht die
Aktivierung rückgängig.

### 4.8 Andere Plugins

Setze `requires: ["anderes-plugin"]` im Manifest, wenn dein Plugin ein anderes braucht (es lässt sich dann nicht ohne
das andere aktivieren). Für optionale Abhängigkeiten: `Plugins::isLoaded('anderes-plugin')`.

### 4.9 Kernklassen mit Vorsicht

`Albums`, `Contacts`, `Image`, `Frame`, `Qr`, `Xlsx`, `VCard`, `Access` und weitere sind für den Kern geschrieben und im
Plugin-Kontext erreichbar, aber **keine stabile Schnittstelle**. Wenn du sie nutzt: dokumentiere es, setze
`requires_app` auf die Version, gegen die du getestet hast, und teste nach jedem Anwendungs-Update. Bildzugriffe laufen
immer über die Rechteprüfung der Galerie (`Albums::forViewer()` filtert versteckte Alben) – umgehe sie nicht.

## 5. Oberfläche

- **Layout:** `view()` rendert in das Standardlayout. In deiner View darfst du setzen: `$pageTitle` (Titel), `$wide = true`
  (volle Breite), `$styles = [plugin_asset('slug', 'css/x.css')]` (eigene Stylesheets).
- **Gestaltung:** Bootstrap 5 und Bootstrap Icons sind eingebunden. Nutze die Klassen des Kerns: `page-head`, `surface`,
  `surface-pad`, `empty` (Leerzustand), `btn btn-primary`, `form-control`, `badge`. Farben und Abstände aus den
  CSS-Variablen in `assets/css/app.css → :root` (z. B. `var(--messing)`), keine festen Farbwerte.
- **Eigene CSS-Klassen mit Präfix** (`.nz-…`), keine Regeln auf bloße Elemente (`h1`, `a`), die den Rest der Seite verändern.
- **Skripte:** keine Inline-Skripte (CSP `script-src 'self'`). Eigene Datei mit `<script src="<?= e(plugin_asset(...)) ?>" defer>`.
  Daten an JavaScript über `data-*`-Attribute oder `<script type="application/json" id="…">` mit
  `json_encode(…, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)`.
- **Texte in JavaScript:** Schlüssel mit `js.`-Präfix stehen in `<script type="application/json" id="i18n">`; lies sie
  dort aus (Vorbild: `plugins/stations/assets/js/stations.js`).
- **Bestätigungen** ohne JavaScript-Code: `data-confirm="<?= e(t('…')) ?>"` an einem `<form>` (wird vom Kern behandelt).
- **Anfragen aus JavaScript:** `fetch` mit `method: 'POST'`, Header `X-CSRF-Token` (aus `<meta name="csrf">`) und
  `X-Requested-With: fetch`; der Controller antwortet mit `json_out(['ok' => true, …])`.
- **Mobil** (ca. 390 px) prüfen, Elemente mit `flex-wrap`, keine festen Breiten. **Tastatur und Screenreader:** echte
  `<button>`/`<a>`, Beschriftungen (`<label for>` oder `aria-label`), sichtbarer Fokus.
- Externe Ressourcen (Bilder, Schriften, Karten-Kacheln) lassen sich wegen der CSP nicht von Fremdservern laden. Bringe
  Daten mit (`assets/`) oder liefere sie über eine eigene Route aus.

## 6. Best Practices

**Aufbau und Stil**

- Eine Aufgabe pro Plugin, wenig Code. Bevor du etwas baust: Gibt es das im Kern?
- Eindeutige Namen: Controller-Funktion `ctrl_<route>`, Klassen und Funktionen mit Präfix, Sprachschlüssel `<präfix>.`,
  Datendateien und CSS-Klassen ebenso. Funktionen und Klassen teilen sich den globalen Namensraum mit dem Kern und allen
  anderen Plugins.
- `declare(strict_types=1);` in jeder PHP-Datei; Typen an Parametern und Rückgaben.
- Fachlogik in `lib/`, Controller dünn, Views ohne Logik außer Darstellung.
- Schreibe wie der Kern: gleiche Kommentardichte, gleiche Namen, Einrückung mit 4 Leerzeichen.

**Sicherheit (verbindlich)**

1. Schreibende Aktionen nur per POST; bei GET `abort(400)`.
2. Rechte im Controller prüfen – **jede** Aktion, nicht nur die Seite. Die Route allein schützt nur vor Abgemeldeten.
3. Alle Eingaben validieren: Typ, Länge, erlaubte Werte. IDs gegen vorhandene Einträge prüfen (`find_by_id`).
4. Ausgabe immer mit `e()`; nie `echo $variable` ungefiltert, auch nicht in Attributen oder JSON in `<script>`.
5. Kein `eval`, `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`, kein `unserialize()` auf Fremddaten,
   keine dynamischen `include`/`require` mit Benutzereingaben.
6. Dateizugriffe nur in `data/` über `Store` bzw. in deinem Plugin-Ordner. Pfade nie aus Eingaben bauen; nach
   `realpath()` auf das erwartete Verzeichnis prüfen. Uploads: Typ und Größe prüfen, nie unter dem Originalnamen speichern.
7. Netzwerkzugriff nur, wenn nötig: feste Hosts, HTTPS, Zeitlimit, Größenlimit, Zertifikate prüfen, Fehler abfangen. Jeden
   Host im Manifest unter `permissions` als `network:<host>` nennen.
8. Keine Geheimnisse im Code oder Repository; Zugangsdaten gehören in `configs/config.php` oder einen eigenen Store, nie in
   Antworten, Logs oder Fehlermeldungen.
9. Keine Telemetrie und keine Weitergabe von Daten ohne ausdrückliche Zustimmung. Das README nennt, welche Daten das
   Plugin verarbeitet und welche externen Dienste es kontaktiert.
10. Freigabelinks: prüfe `Perm::memorial()`; sie dürfen Gedenkseite, Galerie und Bilder nie sehen.

**Robustheit**

- Fange erwartbare Fehler ab und melde sie mit `flash(…, 'warning')`; unerwartete Ausnahmen mit `error_log()` ins
  Server-Log, ohne Interna an den Benutzer zu geben.
- Rechne mit leeren Daten, fehlenden Feldern (ältere Datensätze!) und parallelen Anfragen. Lies Felder mit `?? Standard`.
- Weise Standardwerte in der View nicht per `@` weg – behandle den Fall.
- Greife nicht auf `$_SESSION`, `$_COOKIE` oder Header zu, wenn es Helfer gibt; die Sitzung gehört dem Kern.
- Lange Arbeit (Importe, Netzwerk) in kleine Schritte teilen; `set_time_limit()` ist die Ausnahme.

**Daten und Updates**

- Versioniere dein Datenformat (z. B. Feld `schema` in der Datendatei oder pro Eintrag) und migriere in `onUpdate()`.
- Aktualisierungen ändern nur den Code, **nie** Nutzdaten ohne Migration. Entferne beim Deaktivieren keine Daten – der
  Administrator entscheidet beim Löschen („Daten ebenfalls löschen“).
- Semver: Patch = Fehlerkorrektur, Minor = neue Funktion, Major = Bruch im Datenformat oder Verhalten.
- `requires_app` auf die älteste Anwendungsversion setzen, mit der du getestet hast – nicht höher als nötig.
- Ändere die Kennung (`slug`), Route und Store-Namen nie nach der Veröffentlichung; sonst gehen Daten und Verknüpfungen verloren.

## 7. Entwickeln und testen

1. Ordner nach `plugins/<slug>/` legen (oder ZIP importieren), unter **Verwaltung → Plugins** aktivieren. Lokal:
   `php -S 127.0.0.1:8080 -t .`
2. Syntax: `find plugins/<slug> -name '*.php' -exec php -l {} \; | grep -v 'No syntax'`
3. Sprachdateien: `php bin/check-lang.php` (prüft auch `plugins/*/lang`).
4. Als Administrator, als Benutzer mit eingeschränkter Gruppe und über einen Freigabelink aufrufen (Rechte!), mobil
   (≈ 390 px) und in beiden Sprachen.
5. Server-Log auf PHP-Warnungen prüfen; keine `Notice`/`Deprecated`-Meldungen.
6. Import-Test: `plugins/<slug>/` als ZIP packen und auf einer sauberen Installation importieren (prüft Dateitypen und
   Pfade, die der Import erzwingt).
7. Bricht ein Plugin den Start der Anwendung, hilft `'plugins_safe_mode' => true` in `configs/config.php` (kein Plugin-Code
   läuft), danach das Plugin deaktivieren oder löschen.

## 8. Veröffentlichen im Marktplatz

Der Marktplatz findet Plugins auf GitHub. Voraussetzungen:

| Anforderung | Details |
|---|---|
| Repository | öffentlich, Name `funeral-org-<slug>`, **Topic `funeral-org-plugin`** |
| Manifest | `slug`, `name`, `version`, `description`, `author`, `requires_app`; empfohlen `requires_php`, `license`, `repository`, `permissions` |
| Release | Tag `vX.Y.Z` (= `version` im Manifest), **veröffentlicht** (kein Draft, kein Pre-Release) |
| Assets | `<slug>-X.Y.Z.zip`, `<slug>-X.Y.Z.zip.sha256` (`<hash>  <dateiname>`), `plugin.json` (Kopie des Manifests) |
| Unveränderlich | Ein veröffentlichtes Release nie ändern; eine Korrektur ist eine neue Version |

Der Installer prüft SHA-256, ob Kennung und Version im Paket zum Release passen, die Kompatibilität (Anwendungs- und
PHP-Version), den Paketinhalt (Dateitypen, Pfade, Syntax) und – falls angegeben – den Audit-Commit.

### 8.1 Release automatisieren (GitHub Action)

`.gitattributes` im Repository, damit nur Plugin-Dateien ins Paket kommen:

```
.github       export-ignore
.gitignore    export-ignore
.gitattributes export-ignore
```

`.github/workflows/release.yml`:

```yaml
name: Release
on:
  push:
    tags: ['v*']
permissions:
  contents: write
jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Prüfen und Paket bauen
        run: |
          set -euo pipefail
          SLUG=$(jq -r .slug plugin.json); VER=$(jq -r .version plugin.json)
          [ "v$VER" = "$GITHUB_REF_NAME" ] || { echo "Tag und Manifest-Version passen nicht"; exit 1; }
          if find . -name '*.php' -not -path './.git/*' -exec php -l {} \; | grep -v '^No syntax errors'; then
            echo "Syntaxfehler"; exit 1
          fi
          mkdir dist
          git archive --prefix="$SLUG/" HEAD -o "dist/$SLUG-$VER.zip"
          (cd dist && sha256sum "$SLUG-$VER.zip" > "$SLUG-$VER.zip.sha256")
          cp plugin.json dist/plugin.json
      - name: Release veröffentlichen
        env:
          GH_TOKEN: ${{ github.token }}
        run: gh release create "$GITHUB_REF_NAME" dist/* --title "$GITHUB_REF_NAME" --generate-notes
```

Ablauf pro Version: `version` im Manifest erhöhen, Änderungen in `CHANGELOG.md` notieren, committen, `git tag vX.Y.Z`,
`git push --tags`. Die Action baut das Paket samt Prüfsumme.

### 8.2 Audit (optional)

Wer sein Plugin prüfen lässt, trägt `audit.commit` (40-stelliger Git-SHA des geprüften Stands) und `audit.report`
(`AUDIT.md` im Paket) ins Manifest ein. Die Bestätigungsseite im Marktplatz zeigt, ob der Commit des Release-Tags mit dem
geprüften Stand übereinstimmt. Ein Audit gilt nur für genau diesen Stand und ist keine Garantie.

### 8.3 Berechtigungen (`permissions`)

Rein informativ, damit Administratoren vor der Installation sehen, was ein Plugin tut. Sie werden **nicht technisch
erzwungen**. Erlaubte Werte: `network:<host>`, `mail`, `files`, `data:users`, `data:contacts`, `data:gallery`,
`data:memorial`, `data:checklist`. Fordert eine neue Version zusätzliche Berechtigungen an, wird sie nicht automatisch
übernommen („Alle aktualisieren“ überspringt sie), sondern muss einzeln bestätigt werden – deklariere nur, was du wirklich brauchst.

## 9. Checkliste vor dem Release

- [ ] `php -l` ohne Fehler, `php bin/check-lang.php` ohne Fehler
- [ ] Alle schreibenden Aktionen: POST, CSRF, Rechteprüfung, Protokolleintrag
- [ ] Jede Ausgabe über `e()`, keine Inline-Skripte, keine absoluten URLs
- [ ] Als Administrator, eingeschränkter Benutzer und Freigabelink geprüft; mobil geprüft
- [ ] Texte nur über `t()`, beide Sprachen vollständig
- [ ] Keine verbotenen Funktionen (`eval`, `exec`, …); Netzwerkzugriffe in `permissions` genannt
- [ ] Datenformat-Änderungen migrieren in `onUpdate()`; `requires_app`/`requires_php` stimmen
- [ ] README nennt verarbeitete Daten und externe Dienste; `LICENSE` vorhanden
- [ ] Version im Manifest = Tag; `CHANGELOG.md` aktualisiert

## 10. Häufige Fehler

| Symptom | Ursache |
|---|---|
| Route liefert 404 | Plugin inaktiv, Route nicht `[a-z]{2,24}`, oder Kernroute gleichen Namens (Kern hat Vorrang) |
| Klasse nicht gefunden | Plugin inaktiv, Dateiname ≠ Klassenname, oder Datei nicht unter `lib/` |
| Übersetzung zeigt den Schlüssel | Schlüssel fehlt in einer Sprache oder kollidiert mit einem Kern-Schlüssel |
| Aktivieren schlägt fehl | `requires_app`/`requires_php` zu hoch, benötigtes Plugin inaktiv, Datei in `stores` gehört schon einem anderen Plugin |
| Import lehnt die ZIP ab | Punktdatei oder nicht erlaubter Dateityp im Paket, Syntaxfehler, mehrere Plugins in einer ZIP |
| POST wird mit 419 abgelehnt | `Csrf::field()` fehlt im Formular bzw. Header `X-CSRF-Token` bei `fetch` |
| Eintrag verschwindet bei gleichzeitigen Änderungen | Daten außerhalb von `Store::update()` geschrieben |
| Seite bleibt nach Update unverändert | Browser-Cache der Assets; bei Änderungen am JS/CSS einen Versionsparameter anhängen |
