# ECKDATEN – Gedenkseite

Technische Referenz für die Weiterentwicklung (PHPStorm, Claude Code).
Stand: 29.10.2026 · Version 0.0.15

---

## 1. Zweck

Webanwendung, mit der eine Familie einen Trauerfall organisiert:

| Bereich | Inhalt |
|---|---|
| Gedenken | Startseite mit ovalem Portrait, Lebensdaten, Spruch, Gedenktext, Status-Übersicht |
| Anmeldeseite | Vollbild-Diashow ausgewählter Fotos, Portrait (auch animiertes GIF), Name, Spruch |
| Stationen (Plugin) | Horizontaler Zeitstrahl (Geburt bis Tod) und Weltkarte; Hover zeigt Popup am Ort, Klick startet die Diashow des verknüpften Albums; Farbe/Kategorie je Album, Legende filtert |
| Bilder | Alben, Upload (Mehrfach, Drag & Drop, Fortschritt), Leuchtkasten, Diashow mit Überblendung, Auswahl („Warenkorb"), ZIP-Download |
| Aufgaben | Checklisten in Kategorien, abhaken, Fälligkeit, Zuständigkeit, Notiz |
| Kontakte | Postadresse, Festnetz/Mobil getrennt (`tel:`-Links), E-Mail, Status „angerufen" / „Brief versendet", vCard-Import |
| Zu- und Absagen | Status je Kontakt, Erwachsene/Kinder, Live-Summen |
| Kondolenzen | Karte, Blumen, Kranz, Spende (Betrag), Dankeskarte versendet |
| Export | XLSX, CSV (Serienbrief), Adressetiketten und Absenderetiketten A4 (3×8, 2×7, 2×6, Startetikett wählbar) |
| Verwaltung | Benutzer, Gruppen & Rechte, Freigabelinks, Plugins, Statistik-Dashboard, Protokoll, Backup |

Zielgruppe: Familie und Helfer, überwiegend auf dem Handy. Tonalität der Oberfläche: ruhig, sachlich, würdevoll, Du-Form.

---

## 2. Technische Entscheidungen

| Entscheidung | Festlegung | Begründung |
|---|---|---|
| Sprache | PHP ≥ 8.2, `declare(strict_types=1)` überall | Hosting unter Linux vorhanden |
| Datenhaltung | **JSON-Dateien, keine Datenbank** | Wenige Hundert Datensätze, einfaches Backup, kein DB-Setup |
| Frameworks | **keine**, kein Composer | Wartungsarm, auditierbar, keine Supply-Chain |
| Frontend | Bootstrap 5.3.3 + Bootstrap Icons 1.11.3 + Vanilla JS, Chart.js 4.4.4 nur im Dashboard | Lokal ausgeliefert, **kein CDN** |
| Schriften | EB Garamond (400/500, normal/kursiv), Source Sans 3 (400/600) als woff2, lokal | Datenschutz, Gestaltung |
| Routing | Query-Parameter `index.php?r=<route>&a=<aktion>` | Funktioniert ohne Rewrite-Regeln auf Apache und nginx |
| Pfade | ausschließlich relativ, keine Kenntnis von Domain oder Verzeichnis | Betrieb an der Domainwurzel und in einem Unterverzeichnis (z. B. `/beerdigung/`) ohne Änderung |
| Bilder | GD, Originale + `medium` (1800 px) + `thumb` (480 px) als JPEG, bei transparenten PNG/WebP als PNG | Keine ImageMagick-Abhängigkeit |
| XLSX | Eigener Writer (`app/lib/Xlsx.php`) über `ZipArchive` | Kein PhpSpreadsheet |
| Protokoll | JSON Lines, eine Datei pro Monat | Append-only, rotierbar |

Bewusst **nicht** umgesetzt (kein Ziel des Projekts): PWA/Offline, Zwei-Faktor-Anmeldung,
Kosten-/Belegmodul, Fristen-/Vertragsmodul mit iCal-Export, Versand von Einladungen oder Rundschreiben per E-Mail. Mails verschickt die Anwendung nur für „Passwort vergessen“ (`Reset.php`, `mail()`).

---

## 3. Verzeichnisstruktur

```
gedenkseite/                    ← Webroot (gesamter Projektordner)
├── index.php                   Front-Controller, findet app/ (Layout A/C/D, s. README)
├── .htaccess                   nur index.php ausführbar, sperrt app/bin/configs/data/docs, Caching für Assets
├── assets/
│   ├── css/app.css             gesamte eigene Gestaltung (Tokens in :root)
│   ├── js/app.js               gesamte Client-Logik (IIFE, keine globalen Symbole)
│   ├── js/login.js             Hintergrund-Diashow der Anmeldeseite
│   ├── js/print.js             Druckknopf der Etiketten-Ansicht
│   ├── fonts/*.woff2           EB Garamond 1.003, Source Sans 3 (3.052) + LICENSE-*.txt (SIL OFL 1.1)
│   └── vendor/                 bootstrap/, chartjs/ (unverändert, nicht editieren)
├── app/
│   ├── bootstrap.php           Konstanten, Autoload, Session, dispatch(), Security-Header
│   ├── helpers.php             globale Hilfsfunktionen (e, url, view, flash, post, get, t, tn, fmt_ts …)
│   ├── lang/<sprache>/*.php    Sprachdateien (de, en): je Datei ein Array 'bereich.schlüssel' => Text bzw. ['one'=>…, 'other'=>…]
│   ├── lib/                    Klassen, Autoload: Klassenname = Dateiname
│   │   ├── Store.php           JSON-Speicher (Locking, atomar, Snapshots)
│   │   ├── Auth.php            Login, Session-Kontext, Freigabelinks, Rate-Limit
│   │   ├── Perm.php            Rechteprüfung
│   │   ├── I18n.php            Mehrsprachigkeit: Sprachwahl (Cookie/Konto/Accept-Language), Texte, Plural, Export für JS
│   │   ├── Csrf.php            Token-Erzeugung/-Prüfung
│   │   ├── Log.php             Ereignisprotokoll, Statistik mit Cache, Wartung
│   │   ├── Contacts.php        Kontakt-Domäne: Felder, Filter, Summen, Export-Tabellen
│   │   ├── Plugins.php         Plugin-System: Entdecken, Manifest, Zustand, Laden, Import/Export/Löschen (§17)
│   │   ├── PluginApi.php       Schnittstelle, die `plugin.php` erhält (route, nav, onActivate, onDeactivate)
│   │   ├── Image.php           Upload, EXIF, Vorschaubilder, Auslieferung
│   │   ├── Access.php          Zugänge für Kontakte: Benutzername/Passwort erzeugen, Massenanlage, Druckformate
│   │   ├── Reset.php           „Passwort vergessen“: Tokens (nur Hash), Rate-Limit, Anfragen an den Admin, Mailversand über mail()
│   │   ├── Qr.php              QR-Code-Generator (Byte-Modus, Stufe M, Version 1–10) → SVG, ohne Abhängigkeiten
│   │   ├── VCard.php           vCard 2.1/3.0/4.0-Parser
│   │   ├── Xlsx.php            XLSX-Writer
│   │   └── Setup.php           Ersteinrichtung: Verzeichnisse, Standardgruppen, Start-Checkliste, erster Admin
│   ├── controllers/            je Datei eine Funktion ctrl_<name>(string $r, string $a)
│   └── views/                  PHP-Templates; Präfix _ = Teil-View
├── plugins/                    Plugins (`<slug>/plugin.json`, `plugin.php`, …), per .htaccess gesperrt; enthält `stations/` (§17)
├── bin/                        check-lang.php (Sprachdateien prüfen), smoke-test.sh (Rauchtest in temporärer Kopie), per .htaccess gesperrt
├── configs/                    Konfiguration, per .htaccess gesperrt
│   ├── config.php               (nie ins Repo)
│   └── config.sample.php       Vorlage → config.php
├── data/                       Laufzeitdaten (nie ins Repo, nie in den Webroot)
├── docs/                       Server-Snippets für ISPConfig (nginx/Apache), screenshots/ (Bilder für das README, erfundene Beispieldaten)
├── README.md                   Vorstellung, Schnellstart, Installation, Betrieb (englisch)
├── README.de.md                dieselbe Anleitung auf Deutsch
├── VERSION                     Versionsnummer (`app_version()`, Anzeige im Admin-Dashboard; muss zum neuesten CHANGELOG-Eintrag passen, per CI geprüft)
├── LICENSE                     GNU GPL v3.0
├── CHANGELOG.md                Änderungsprotokoll (Keep a Changelog, Semantic Versioning)
├── SECURITY.md  CONTRIBUTING.md  CODE_OF_CONDUCT.md   Meldeweg für Sicherheitslücken, Beitragsregeln, Verhaltenskodex (Contributor Covenant 2.1, Kontakt support@eduard.de; englisch)
├── .github/                    CI (php -l, check-lang, Rauchtest für PHP 8.2–8.4), Issue-/PR-Vorlagen, Dependabot für Actions
├── THIRD-PARTY.md              Lizenzen der mitgelieferten Fremdkomponenten
├── ECKDATEN.md                 dieses Dokument
└── CLAUDE.md                   Arbeitsanweisungen für Claude Code (nicht im Repository, siehe .gitignore)
```

### Inhalt von `data/`

```
data/
├── users.json  groups.json  shares.json  contacts.json  checklist.json
├── memorial.json  gallery.json  selections.json  ratelimit.json
├── stations.json               gehört dem Plugin „stations“ (Manifest `stores`); weitere Plugin-Dateien analog
├── settings.json               Einstellungen: `base_url` (öffentliche Adresse, Basis für Reset-Mail-Links, aus der Ersteinrichtung), `sender` (Absenderadresse, Zeilen-Array), `plugins` (`<slug>` → `{active, changed}`)
├── resets.json                 flüchtig: Reset-Tokens (Hash), Anfragen, Zähler – nicht in Snapshots/Backup-Download
├── *.lock                      Lock-Dateien (flock), dürfen gelöscht werden, wenn nichts läuft
├── logs/JJJJ-MM.jsonl          Ereignisprotokoll
├── uploads/orig|medium|thumb/  Bilddateien, Name = <bild-id>.<ext>
├── backups/JJJJ-MM-TT/         Tages-Snapshots der JSON-Dateien
├── backups/vor-import-…/       Sicherung der JSON-Dateien vor einem Import (die letzten 5)
├── cache/                      Statistik-Cache, Wartungsmarker (löschbar)
└── tmp/                        temporäre ZIP/XLSX (automatisch nach 1 h entfernt)
```

---

## 4. Ablauf einer Anfrage

1. `index.php` definiert `PUBLIC_DIR`, sucht die Anwendung (`GEDENK_ROOT` → `..` → `../private/gedenkseite`) und lädt `app/bootstrap.php`.
2. `bootstrap.php` lädt `configs/config.php`, definiert `ROOT_DIR`, `APP_DIR`, `DATA_DIR`, registriert den Autoloader, lädt `helpers.php`.
3. `dispatch()`:
   - startet die Session (`start_session()`: Cookie-Flags, Idle-Timeout)
   - sendet Security-Header (CSP, `X-Frame-Options`, `nosniff`, `noindex`)
   - lädt die aktiven Plugins (`Plugins::boot()`), bildet `r` auf einen Controller ab (Tabelle `$routes`, danach Plugin-Routen)
   - öffentliche Routen: `setup`, `login`, `s`, `pubimg` – alle anderen verlangen `Auth::check()`
   - bei **jedem POST** `Csrf::check()` (Feld `_csrf` oder Header `X-CSRF-Token`)
   - ruft `ctrl_<controller>($r, $a)` auf
4. Controller prüfen Rechte (`Perm::need()`, `Perm::needAdmin()`, `Perm::gallery()`), verändern Daten nur über `Store::update()`, protokollieren über `Log::…`, antworten mit `view()`, `redirect()`/`redirect_back()` oder `json_out()` (bei AJAX).
5. AJAX-Erkennung: Header `X-Requested-With: fetch` → `is_ajax()`; Fehler kommen dann als JSON `{ok:false, error:"…"}`.

Fehlerbehandlung: Ausnahmen werden in `index.php` gefangen, per `error_log()` protokolliert, der Nutzer sieht eine neutrale Seite.

---

## 4a. Pfad- und Domainunabhängigkeit

Die Anwendung enthält keine absolute URL und keinen festen Installationspfad.

| Stelle | Umsetzung |
|---|---|
| Interne Links | `url($r, $params)` → `index.php?r=…` (relativ) |
| Assets | `asset($pfad)` → `assets/…?v=<mtime>` (relativ) |
| Weiterleitungen | `redirect()` sendet einen relativen `Location`-Header (RFC 7231) |
| CSS/Schriften | relative `url()`-Angaben in `app.css` und im Bootstrap-Icons-CSS |
| JavaScript | `u(route, action)` in `app.js` baut ebenfalls `index.php?…` |
| Session-Cookie | `base_path()` = `dirname(SCRIPT_NAME)` mit Schrägstrich, z. B. `/beerdigung/` – mehrere Installationen je Domain möglich |
| Anwendungsverzeichnis | `index.php` sucht `app/bootstrap.php`: `GEDENK_ROOT` → `app-path.php` → eigenes Verzeichnis → bis zu 5 Ebenen aufwärts, je Ebene auch `private/gedenkseite` |
| Absolute URLs | nur Freigabelinks, Zugangsdaten-Ausdruck (QR) und Reset-Mails. `configured_base_url()`: `configs/config.php → base_url`, sonst `settings.json → base_url` (bei der Ersteinrichtung vorgeschlagen). `site_base_url()` fällt für Freigabelinks und Ausdruck auf `HTTP_HOST` + `base_path()` zurück, **Reset-Mails nie** (ohne Basis-URL kein Mailversand, Protokolleintrag `pw_reset`) |

Voraussetzung im Browser: Die Verzeichnis-URL muss mit Schrägstrich enden. Apache (`DirectorySlash On`,
in `.htaccess` gesetzt) und nginx leiten selbstständig um.

**Deployment-Layouts** (Details im README):

| Layout | Ablage | Schutz von `data/` |
|---|---|---|
| Unterverzeichnis (Apache) | gesamter Projektordner als `web/beerdigung/` | `.htaccess` in `app/`, `bin/`, `configs/`, `data/`, `docs/` plus Sperrregeln in der Webroot-`.htaccess` |
| eigene Website | `web/` = `index.php` + `assets/` + `.htaccess`, Rest in `private/gedenkseite/` | außerhalb des Webroots |
| freier Pfad | beliebig | außerhalb des Webroots, Pfad über `GEDENK_ROOT` oder `app-path.php` |

---

## 5. Routen und Aktionen

`M` = Methode, Rechte: `R` lesen, `C` abhaken/Status, `E` bearbeiten, `A` Admin.

| r | a | M | Recht | Zweck |
|---|---|---|---|---|
| `setup` | – | GET/POST | öffentlich, nur solange kein Benutzer existiert | Ersteinrichtung (ersten Admin anlegen, öffentliche Adresse vorschlagen und in `settings.json` speichern); sonst 404. Ohne Benutzer leitet jede andere Route hierher um |
| `login` | – | GET/POST | öffentlich | Anmeldeseite / Anmeldung |
| `logout` | – | POST | angemeldet | Abmelden; bei laufender Übernahme (siehe unten) nur Rückkehr zum Admin |
| `unimpersonate` | – | POST | angemeldet, nur bei laufender Übernahme | Zurück zum Admin |
| `s` | – (`t`=Token) | GET | öffentlich | Freigabelink öffnen → erste erlaubte Seite |
| `pubimg` | – (`id`, `s`=thumb/medium) | GET | öffentlich | nur Portrait und als „Anmeldeseite" markierte Bilder |
| `home` | – | GET | Benutzer (kein Link) | Gedenkseite |
| `home` | `edit` | GET/POST | A | Gedenkseite bearbeiten |
| `stations` (Plugin) | – | GET | Benutzer (kein Link) | Zeitstrahl + Weltkarte; Bild/Diashow-Link nur mit Galerie-Recht |
| `stations` (Plugin) | `save` / `delete` | POST | A | Station anlegen/ändern/löschen (Geburt/Tod sind fest, nicht löschbar) |
| `checklist` | – (`show=open`) | GET | checklist R | Übersicht |
| `checklist` | `toggle` | POST | checklist C | Aufgabe erledigt/offen (AJAX) |
| `checklist` | `save` / `delete` | POST | checklist E | Aufgabe/Unteraufgabe (`parent`) anlegen/ändern/löschen (Löschen entfernt Unteraufgaben) |
| `checklist` | `move` | POST | checklist E | Aufgabe verschieben/umsortieren (`id`, Ziel `cat`, `parent` (leer = Hauptaufgabe), `ids[]` = Reihenfolge der Zielliste); Hauptaufgabe mit Unteraufgaben kann keine Unteraufgabe werden |
| `checklist` | `catsave` / `catmove` / `catdelete` | POST | checklist E | Kategorien (Löschen entfernt enthaltene Aufgaben) |

**Checkliste ohne Reload:** Alle schreibenden Aktionen (`toggle`, `save`, `delete`, `catsave`, `catmove`, `catdelete`) antworten bei AJAX (`X-Requested-With: fetch`) mit JSON: `msg`, `cats` (Liste `{id, html}` neu gerenderter Kategorieblöcke aus `_checklist_cat.php`/`_checklist_task.php`; leeres `html` = Block entfernen), `order` (Kategorie-Reihenfolge), `options` (Kategorie-Auswahl), `empty`, `lead`; `toggle` liefert stattdessen Zähler (`cat_progress`, `parent_progress`, `lead`). Der Filter `show` kommt als POST-Feld aus `#clRoot[data-show]`. Ohne JS gelten weiter Redirect und Flash.
| `contacts`, `rsvp`, `condolences` | – (`filter`, `rel`) | GET | jeweiliger Bereich R | Listen |
| dito | `export` (`fmt`=csv/xlsx) | GET/POST | Bereich R | Export, Spalten je Bereich und Feldrechten; POST mit `ids[]` exportiert nur die Auswahl |
| dito | `labels` (`format`, `start`) | GET/POST | Bereich R + Feld „Postadresse" | Etiketten-Druckansicht; POST mit `ids[]` nur für die Auswahl |
| dito | `senderlabels` (`format`, `start`) | POST | Bereich R + Feld „Postadresse" | Druckansicht mit der Absenderadresse auf einem vollen Bogen (ab Startetikett); nur wenn eine Absenderadresse hinterlegt ist |
| `contacts` | `access` (`mode`=new/reset; `ids[]`, `group_id`, `format`, `start`) | POST | A | Zugänge für die Auswahl anlegen bzw. Passwörter neu setzen; Antwort = Druckansicht „Klebestreifen“ (Formate 2×6 [Standard] und 2×9, 2 Spalten × Zeilen: Benutzername, Passwort, Adresse, QR-Code der Anmeldeseite; ohne Namen). **Klartext-Passwörter nur in dieser Antwort**, `Cache-Control: no-store`, nicht gespeichert; Kontakte mit bestehendem Zugang werden bei `new` übersprungen, ohne Zugang bei `reset` |
| `forgot` | – (`a=help` für Weg 2) | GET/POST | öffentlich | Passwort vergessen: Benutzername → Link per Mail; `help`: Name + optionale Telefonnummer → Anfrage an den Admin. **Antwort immer gleich**, Mail wird nach dem Abschluss der Antwort gesendet |
| `lang` | – (`lang`=de/en, `_back`) | POST | öffentlich | Sprache wählen: setzt Cookie `gdk_lang` (1 Jahr, Pfad = `base_path()`, SameSite=Lax) und speichert sie im Konto (nur bei Benutzern, nicht bei Freigabelinks); kein Protokolleintrag (keine Datenänderung); Rücksprung über `_back` |
| `reset` | – (`t`=Token) | GET/POST | öffentlich | Neues Passwort per Link (≥ 10 Zeichen); Token 60 min, einmalig; danach Bestätigungsmail, alle Sitzungen des Kontos enden |
| `admin` | `requests` / `requestdone` | GET/POST | A | Passwortanfragen (Weg 2), „Passwort neu setzen und drucken“ nutzt `contacts&a=access&mode=reset`; „Erledigt“ löscht die Anfrage |
| `account` | – | GET/POST | Benutzer (kein Link) | Eigenes Passwort ändern (aktuelles + neu zweimal, ≥ 10 Zeichen); falsches aktuelles Passwort → `login_fail` + 1 s Bremse |
| `contacts` | – (`view`=cards/table) | GET | contacts R | Karten- oder Tabellenansicht; ohne `view` gilt das Cookie `gdk_cview` (vom Umschalter per JS gesetzt, 1 Jahr, Pfad = `base_path()`) |
| `contacts` | `toggle` (`field`=called/letter) | POST | contacts C | Umschalter (AJAX) |
| `contacts` | `cellset` (`id`, `field`, `value`) | POST | contacts E + Feldrecht | Inline-Bearbeitung eines Textfelds aus `Contacts::TEXT_FIELDS` (AJAX, JSON) |
| `contacts` | `bulkdelete` (`ids[]`) | POST | contacts E | mehrere Kontakte löschen, Protokolleintrag je Kontakt |
| `contacts` | `bulkset` (`ids[]`, `what`=called_1/called_0/letter_1/letter_0/relation, `value`) | POST | contacts C (Beziehung: E) | Sammelstatus bzw. Beziehung setzen |
| `condolences` | `toggle` (`field`=card/flowers/wreath/donation/thanks) | POST | condolences C | Umschalter (AJAX) |
| `condolences` | `condset` | POST | condolences C | Betrag, Notiz |
| `rsvp` | `rsvpset` | POST | rsvp C | Status, Erwachsene, Kinder (AJAX, liefert Summen) |
| `contacts` | `form` (`id`, `from`) / `save` / `delete` | GET/POST | contacts E | Kontaktformular |
| `contacts` | `import` | GET/POST | contacts E | vCard-Import mit Dublettenprüfung |
| `gallery` | – (`album`, `open`, `play=1`) | GET | Galerie ansehen | Übersicht, `open=<id>` öffnet Leuchtkasten, `play=1` (mit `album`) startet die Diashow |
| `gallery` | `seen` | POST | Galerie ansehen | protokolliert Bildansicht (einmal je Bild und Seitenaufruf) |
| `gallery` | `upload` | POST | Galerie hochladen | Mehrfach-Upload, Antwort JSON |
| `gallery` | `select` (`mode`=toggle/add/remove/clear) | POST | Galerie herunterladen | Auswahl pflegen |
| `gallery` | `cart` | GET | Galerie herunterladen | Auswahl anzeigen |
| `gallery` | `move` (`album`) | POST | Galerie herunterladen | Verschiebt die Auswahl in ein Album; Admin alle, sonst nur eigene Bilder (Rest wird übersprungen), Auswahl bleibt |
| `gallery` | `bulkdelete` | POST | A | Löscht die ausgewählten Bilder samt Dateien, Portrait-Verweis und Auswahleinträgen |
| `gallery` | `zip` (`what`=selection/album/all) | GET | Galerie herunterladen | ZIP (unkomprimiert, Originale) |
| `gallery` | `imgorder` | POST | Galerie hochladen | Bildreihenfolge eines Albums (`album`=ID oder `_none`, `ids[]` in neuer Reihenfolge), Antwort JSON |
| `gallery` | `imgsortby` | POST | Galerie hochladen | Album sortieren (`by`=uploaded/uploaded_desc/name/caption) |
| `gallery` | `edit` | POST | Admin oder Hochlader | Bildunterschrift, Album, `links[]` (weitere Alben), (Admin:) Anmeldeseite |
| `gallery` | `albummove` (`dir`=up/down als Query) | POST | A | Album unter Geschwistern (gleiches `parent`) nach vorn/hinten, `sort` wird neu nummeriert |
| `gallery` | `delete` / `albumsave` / `albumdelete` | POST | A | Löschen, Alben (`parent`, `links[]`; Album löschen behält Unteralben und Bilder) |
| `img` | – (`id`, `s`=thumb/medium/orig) | GET | Galerie ansehen; `orig` braucht Herunterladen | Bildauslieferung mit ETag |
| `admin` | – (`days`) | GET | A | Dashboard |
| `admin` | `users` / `usersave` / `userdelete` | GET/POST | A | Benutzer |
| `admin` | `impersonate` (`id`) | POST | A | „Als Benutzer anmelden“ (siehe unten) |
| `admin` | `groups` / `groupsave` / `groupdelete` | GET/POST | A | Gruppen (Löschen nur ohne Mitglieder) |
| `admin` | `shares` / `sharesave` / `sharerevoke` / `sharedelete` | GET/POST | A | Freigabelinks |
| `admin` | `sender` / `sendersave` | GET/POST | A | Absenderadresse für Umschläge (bis 5 Zeilen à 60 Zeichen), gespeichert in `settings.json` → `sender` (Array), gelesen über `sender_lines()` |
| `admin` | `log` (`days`, `act`, `usr`, `q`, `fmt`) | GET | A | Protokoll, Export CSV/XLSX |
| `admin` | `backup` / `snapshot` / `download` (`full=1`) | GET/POST | A | Backup |
| `admin` | `transferexport` | POST | A | Export-Paket (ZIP) der gewählten Bereiche (`parts[]`) |
| `admin` | `transferimport` / `transferpreview` (`token`) | POST/GET | A | Paket hochladen und prüfen (Entpacken nach `data/tmp/import-<token>/`), Vorschau mit Bereichsauswahl |
| `admin` | `transferapply` / `transferdrop` | POST | A | Gewählte Bereiche ersetzen (nach Sicherung) bzw. Paket verwerfen |
| `admin` | `plugins` / `pluginset` (`slug`, `state`=on/off) / `pluginimport` (`plugin_zip`, multipart) / `pluginexport` (`p`, GET) / `plugindelete` (`slug`, `delete_data`) | GET/POST | A | Plugin-Verwaltung (§17) |
| `pluginasset` | – (`p`, `f`, `v`) | GET | Benutzer | Datei aus `assets/` eines **aktiven** Plugins (Whitelist der Endungen, Pfadprüfung, ETag) |

Regel: **Jede zustandsändernde Aktion ist POST** (CSRF-geschützt). GET-Aktionen verändern nur Protokoll/Cache.

---

## 6. Rechtemodell

- Rollen: `admin` (alles, ohne Gruppenprüfung) und `user` (Rechte aus Gruppe).
- Rechte hängen **ausschließlich an Gruppen**. Benutzer und Freigabelinks referenzieren `group_id`.
- Stufen je Bereich (`Perm::LEVELS`):

| Wert | Konstante | Bedeutung |
|---|---|---|
| 0 | `Perm::NONE` | kein Zugriff, Menüpunkt unsichtbar |
| 1 | `Perm::READ` | lesen, exportieren |
| 2 | `Perm::CHECK` | zusätzlich abhaken/Status setzen |
| 3 | `Perm::EDIT` | zusätzlich anlegen, bearbeiten, löschen |

- Bereiche (`Perm::MODULES`): `checklist`, `contacts`, `rsvp`, `condolences`.
- Feldrechte (`Perm::FIELDS`): `address` (Straße/PLZ/Ort/Bundesland/Land), `phone`, `mobile`, `email`.
  Nicht erlaubte Felder werden **nicht ausgegeben, nicht exportiert und beim Speichern nicht überschrieben** (`Contacts::fromForm`).
- Galerie (`group.gallery`): `view`, `upload`, `download`. Gedenkseite sichtbar für alle angemeldeten Benutzer.
- **Freigabelinks** (`Auth::isShare()`): erben Gruppenrechte, sehen **nie** Gedenkseite und Galerie, haben keinen Abmelden-Knopf, sehen einen Hinweisbalken. Ungültig bei `revoked` oder abgelaufenem `expires` (Ende des Tages). Ein angemeldeter Benutzer behält beim Öffnen eines Links seine Sitzung.
- Standardgruppen aus `Setup::create()`:

| Gruppe | Aufgaben | Kontakte | Zu/Absagen | Kondolenzen | Felder | Galerie |
|---|---|---|---|---|---|---|
| Familie | E | E | E | E | alle | ansehen, hochladen, herunterladen |
| Helfer | C | C | C | – | Festnetz, Mobil | ansehen, hochladen |
| Gasthaus / Caterer | – | – | R | – | keine | – |

---

## 7. Datenmodell

Alle Dateien UTF-8, `JSON_PRETTY_PRINT`, IDs = 16 Hex-Zeichen (`new_id()`), Zeitstempel ISO 8601 (`now()` = `date('c')`).
Standardinhalte: `Store::DEFAULTS`. Fehlende Felder in Kontakten werden über `Contacts::blank()` ergänzt – **neue Felder immer dort mit Default eintragen**.

### users.json – Liste
```json
{ "id": "…", "username": "max", "name": "Max Mustermann", "role": "admin|user",
  "group_id": "…", "active": true, "pass_hash": "$2y$…", "created": "…", "last_login": "…",
  "contact_id": "<kontakt-id>|fehlt", "pw_changed": 1760000000, "lang": "de|en" }
```
`username`: klein, `[a-z0-9._@-]{3,60}`, eindeutig (Vergleich case-insensitiv beim Login). `lang`: bevorzugte Sprache; wird beim Umschalten gespeichert und nach der Anmeldung als Cookie übernommen (fehlt sie, wird die aktuelle Sprache eingetragen).

### groups.json – Liste
```json
{ "id": "…", "name": "Familie",
  "perms":   { "checklist": 3, "contacts": 3, "rsvp": 3, "condolences": 3 },
  "fields":  { "address": true, "phone": true, "mobile": true, "email": true },
  "gallery": { "view": true, "upload": true, "download": true } }
```

### shares.json – Liste
```json
{ "id": "…", "token": "<48 hex>", "label": "Gasthaus Linde", "group_id": "…",
  "expires": "2026-12-31|''", "revoked": false, "created": "…", "uses": 3, "last_used": "…" }
```
Token: `bin2hex(random_bytes(24))`, Vergleich mit `hash_equals`.

### contacts.json – Liste
```json
{ "id": "…", "salutation": "Frau", "first": "Anna", "last": "Schmidt", "relation": "Nachbarn",
  "street": "…", "zip": "…", "city": "…", "state": "", "country": "",
  "phone": "0561 123456", "mobile": "0171 …", "email": "…", "note": "…",
  "called": false, "letter": false,
  "rsvp": "|open|yes|no", "adults": 0, "children": 0, "rsvp_note": "",
  "card": false, "flowers": false, "wreath": false, "donation": false,
  "amount": 0.0, "cond_note": "", "thanks": false,
  "created": "…", "updated": "…", "updated_by": "Anzeigename" }
```
- `rsvp = ""` bedeutet „nicht eingeladen" → erscheint nicht in der Zu-/Absagenliste.
- „Hat Kondolenz" = `card || flowers || wreath || donation` (`Contacts::hasCondolence`).
- Filter (`Contacts::FILTERS`): `called_open`, `letter_open`, `rsvp_yes`, `rsvp_no`, `rsvp_open`, `cond_any`, `thanks_open`.
- Dublette (vCard-Import): gleicher Vor-+Nachname (case-insensitiv) oder gleiche Telefonnummer (nur Ziffern).

### checklist.json
```json
{ "categories": [ { "id": "…", "name": "Sofort", "sort": 0 } ],
  "items": [ { "id": "…", "cat": "<category-id>", "title": "…", "done": false,
               "parent": "<aufgaben-id>|''", "sort": 0, "done_by": "", "done_at": "", "due": "YYYY-MM-DD|''", "who": "", "note": "", "created": "…" } ] }
```
Sortierung in der Ansicht (`cl_cmp()`): offen vor erledigt; Aufgaben mit manuellem `sort` (Position in ihrer Liste, gesetzt per Ziehen/Pfeilen über `move`) vor den übrigen, diese nach Fälligkeit, dann Anlagedatum.
**Unteraufgaben:** genau eine Ebene. `parent` verweist auf eine Hauptaufgabe (`parent = ''`); `cat` wird von der Hauptaufgabe übernommen und beim Kategoriewechsel mitgezogen. Löschen einer Hauptaufgabe löscht ihre Unteraufgaben mit. Aufklapp-Zustand pro Browser in `localStorage` (`funeralorg.subtasks.open`). Dashboard- und Kategoriezähler zählen Unteraufgaben mit.

### memorial.json
```json
{ "name": "…", "born": "12. März 1941", "died": "15. September 2026", "place": "",
  "quote": "…", "quote_by": "…", "text": "…", "portrait": "<bild-id>|''", "frame": "oval" }
```
Lebensdaten sind **Freitext** (Formatfreiheit, z. B. nur Jahr). `frame` ist einer der Schlüssel aus `Frame::KEYS` (`oval`, `round`, `rect`, `passepartout`, `wood`, `antique`, `baroque`, `mourning`); fehlende oder unbekannte Werte gelten als `oval`.

### gallery.json
```json
{ "albums": [ { "id": "…", "name": "Kindheit", "sort": 0, "created": "…",
                  "cat": "Wohnorte|''", "color": "#4a5d4f|''",
                  "parent": "<album-id>|''", "links": ["<album-id>", …], "hidden": false } ],
  "images": [ { "id": "…", "ext": "jpg|png|gif|webp", "mime": "image/jpeg", "w": 1600, "h": 1200,
                "size": 123456, "orig_name": "IMG_1234.jpg", "animated": false, "alpha": false,
                "album": "<album-id>|''", "links": ["<album-id>", …], "caption": "", "login": false,
                "uploaded_by": "Name", "uploaded_by_id": "<user-id>", "uploaded_at": "…",
                "ord": { "<album-id>|_none": 0 } } ] }
```

**Album-Hierarchie** (`app/lib/Albums.php`, alle Durchläufe schleifensicher): `parent` = Heimat-Elternalbum (leer = oberste Ebene, fehlend = leer), `links` = zusätzliche Eltern (Album erscheint auch dort, wird nicht kopiert). Bild: `album` = Heimatalbum, `links` = weitere Alben, in denen es erscheint. Beim Speichern sind Eltern/Verweise im eigenen Teilbaum gesperrt (Server und Modal). Ansicht eines Albums: Raster = direkte Bilder, Diashow/Leuchtkasten und „Dieses Album als ZIP“ = rekursiv inkl. Unteralben (ZIP mit Unterordnern; ein Bild liegt im Ordner seines Heimatalbums, Verweise nur als Rückfall). „Alle“ zeigt alle Bilder, Chips = oberste Alben. Album löschen: Unteralben und Bilder rücken in das Elternalbum auf, Verweise darauf entfallen. Verschieben setzt nur das Heimatalbum (`links` auf das Ziel entfallen).

**Versteckte Alben:** `hidden` (nur Admin, `albumsave`/API `hidden`) macht ein Album samt Unteralben (über die Heimat-Eltern, `Albums::hiddenIds()`) für alle außer Admins unsichtbar. `Albums::forViewer($g)` filtert Alben und Bilder und wird überall dort vor dem Lesen angewandt: Übersicht/Zähler/Chips, Leuchtkasten/Diashow, `img` (404), `seen`, `select`, `cart`, `move`, `zip`, Upload-Ziel, `imgorder`/`imgsortby`, „Zuletzt hinzugefügt“ der Startseite, Stationen-Plugin (Bild/Diashow-Link, Albumliste) und Web-API/MCP (Token wirken mit den Rechten ihres Benutzers). Ein Bild bleibt sichtbar, wenn es zusätzlich (`links`) in einem sichtbaren Album erscheint; sein Heimatalbum ist dann das erste sichtbare. Nicht betroffen: Login-/Portraitbild (`pubimg`, bewusste Admin-Entscheidung). Admins sehen versteckte Alben mit Augen-Symbol (`bi-eye-slash`).

**Bildreihenfolge:** `ord[<album-id>|_none]` = Position im Raster dieses Albums (`Albums::sortIn()`); Bilder ohne Eintrag folgen in Upload-Reihenfolge. Ändern per Ziehen (Maus), langem Druck und Ziehen (Touch/Stift, Pointer-Events in `app.js`) oder Pfeilen (`imgorder`) oder Sortier-Dropdown (`imgsortby`); in „Alle“ nicht möglich. Wirkt auf Raster, Leuchtkasten und Diashow, nicht auf ZIP.

Bild-Tooltip: `title` am Vorschaubild, erzeugt von `image_tip()` (`app/helpers.php`) aus Unterschrift/Dateiname, Maßen, Größe, Typ, Album, Hochlader und Datum (EXIF wird beim Upload entfernt).

### stations.json (Datendatei des Plugins „stations“)
```json
[ { "id": "…|birth|death", "type": "''|birth|death", "title": "Lehre in Hamburg",
    "date": "JJJJ-MM-TT|JJJJ-MM|JJJJ", "place": "Hamburg", "lat": 53.55, "lon": 9.99,
    "text": "", "album": "<album-id>|''", "image": "<bild-id>|''", "created": "…" } ]
```
- **Geburt/Tod** sind feste Stationen (`id`/`type` = `birth`/`death`). Sie existieren erst nach dem ersten Speichern in der Datei; bis dahin liefert `Stations::all()` leere Platzhalter. Titel und **Datum werden bei jeder Anzeige aus `memorial.json` (`born`/`died`, Freitext) gelesen** (`Stations::parseDate`: „12. März 1941“, „12.03.1941“, „März 1941“, „3/1941“, „1941“ …); das Datum wird nicht gespeichert.
- **Datum** normaler Stationen wird beim Speichern aus Freitext gelesen und als ISO-Teilformat abgelegt; ungenaue Angaben liegen in der Mitte ihres Zeitraums.
- **Farbe und Kategorie** kommen vom Album (`color` aus `Stations::COLORS`, `cat` frei; leere Werte werden vom nächsten Heimat-Vorfahren geerbt, Fallback Albumname; ohne Album grau „Ohne Album“). Die Diashow der Station umfasst auch Unteralben. Die Legende filtert Zeitstrahl und Karte.
- Ohne `lat`/`lon` erscheint die Station nur im Zeitstrahl. Ohne Datum nur auf der Karte (Geburt/Tod mit nicht lesbarem Freitext).
- Karte: `assets/vendor/worldmap/world.svg` (Natural Earth 1:50m, gemeinfrei; Winkel Tripel 84°N–58°S, viewBox 400×208,989; erzeugt mit `php bin/make-worldmap.php <ne_50m_admin_0_countries.geojson>`). Pins in Prozent über `wt()` in `stations.js` (Konstanten `WT_X0/Y0/XS/YS` aus dem Skript); Klick → Breite/Länge über `unproject()` (Newton-Verfahren, `null` außerhalb des Umrisses). Kein Kachelserver, keine externen Anfragen.
- Popup/Zeitstrahl: `plugins/stations/assets/js/stations.js` (Auslieferung über `pluginasset`; füllt auch den Dialog „Station“) (Daten aus `<script type="application/json" id="stationsData">`). Klick (Maus) bzw. zweiter Tipp (Touch) öffnet `gallery&album=<id>&play=1`, ohne Album das einzelne Bild per `gallery&open=<bild-id>`.

### selections.json
`{ "<user-id>": ["<bild-id>", …] }` – Auswahl bleibt über Sitzungen erhalten; ungültige IDs werden beim nächsten Schreiben bereinigt.

### ratelimit.json
```json
{ "<sha256(ip)>":        { "count": 2, "first": 1758…, "locked_until": 0, "strikes": 0, "strike_at": 0 },
  "u:<sha256(benutzer)>": { "count": 3, "last": 1758…, "next_ok": 0 } }
```

---

## 8. Speicherschicht (`Store`)

- `Store::read($name)` – Shared-Lock auf `<name>.lock`, Ergebnis im Request gecacht.
- `Store::update($name, fn(array &$data) => mixed)` – Exclusive-Lock, lesen, Callback, atomar schreiben (tmp + `rename`), Rückgabewert des Callbacks wird durchgereicht.
- Vor jedem `update()` prüft `dailyBackup()`, ob der Tages-Snapshot existiert, sonst Kopie aller `*.json` und Rotation auf `backup_keep_days`.
- **Niemals** `file_put_contents` direkt auf Datendateien – immer `Store::update`.
- Beschädigtes JSON → `RuntimeException` mit Hinweis auf `data/backups`.
- Mehrere `Store::update()` in einem Request sind unabhängige Transaktionen (keine dateiübergreifende Atomarität).

---

## 9. Protokoll und Statistik (`Log`)

Format je Zeile (`data/logs/JJJJ-MM.jsonl`):
```json
{ "t": 1758450000, "a": "img_view", "u": "max", "n": "Max Mustermann", "k": "user|share|''",
  "o": "<objekt>", "ip": "192.168.1.0", "v": "<admin>", "m": { "c": "Bildunterschrift", "mod": "contacts", "what": "…" } }
```

| Aktion `a` | Auslöser | `o` |
|---|---|---|
| `login_ok`, `login_fail`, `login_blocked`, `logout` | Auth | Benutzername bei Fehlern |
| `share_open` | Freigabelink geöffnet | Link-Bezeichnung |
| `img_view` | Leuchtkasten zeigt Bild (Beacon `gallery&a=seen`) | Bild-ID |
| `img_download` | Original geladen | Bild-ID |
| `zip_download` | ZIP | Beschreibung |
| `upload` | Bild hochgeladen | Bild-ID |
| `export` | CSV/XLSX/Etiketten | Beschreibung |
| `change` | Datenänderung (`Log::change($mod, $what, $obj)`) | betroffener Datensatz |
| `pw_reset` | „Passwort vergessen“: angefordert (Benutzername, nie die Adresse), abgeschlossen, Hilfe angefordert | Beschreibung |
| `admin` | Verwaltungsaktionen | Beschreibung |

- IP-Kürzung (`log_full_ip=false`): IPv4 letztes Oktett → 0, IPv6 auf /48.
- `Log::stats($days)` aggregiert KPIs, Tagesverlauf, Benutzer, Top-Bilder, auffällige IPs (≥ 3 Fehlversuche); Cache in `data/cache/stats-<days>.json`, Invalidierung über Dateigrößen der Logs + Datum.
- `Log::maintenance()` (einmal täglich beim ersten Login): Logs älter als `log_retention_months` und `tmp/`-Dateien älter als 1 h entfernen.

---

## 10. Bildverarbeitung (`Image`)

- Erlaubt: JPEG, PNG, GIF, WebP (Prüfung per `finfo`, zusätzlich `getimagesize`), Größe ≤ `upload_max_mb`. Wirksam ist das Minimum aus `upload_max_mb`, `upload_max_filesize` und `post_max_size` (`upload_limit_bytes()`, wird in der Galerie angezeigt). PHP-Upload-Fehlercodes werden im Klartext gemeldet (`gallery_upload`).
- JPEG: EXIF-Orientierung > 1 → drehen und mit Qualität 94 neu speichern; sonst **verlustfrei** APP1 (EXIF/XMP/GPS) und APP13 (IPTC) entfernen (`stripJpegMeta`).
- GIF: Original unverändert (Animation bleibt), `medium` = Original, `thumb` = erstes Frame.
- PNG/WebP: Original unverändert. Hat das Bild echte Transparenz (Stichprobe `hasAlpha`), werden `thumb`/`medium` als PNG mit Alphakanal erzeugt und der Datensatz erhält `alpha: true`; `Image::path()` liefert dann `.png`, `Image::variantMime()` den MIME-Typ. Ohne Transparenz: JPEG auf hellem Grund. Bilder, die vor Einführung des Felds hochgeladen wurden, haben kein `alpha` und bleiben JPEG (neu hochladen).
- Diashow: Beim Wechsel zu oder von einem transparenten Bild entfällt die Überblendung (`.lb-stage.cut`), da sonst das alte Bild durchscheint.
- Auslieferung nur über `index.php?r=img|pubimg` mit `Cache-Control: private`, ETag, 304.
- Speicherbedarf: GD benötigt ca. 5 Byte × Pixel → `image_memory_limit` (Standard 768M); `image_max_pixels` (Standard 50 Mio.) lehnt größere Bilder vor dem Dekodieren ab.
- Client lädt in Paketen (≤ 10 Dateien bzw. ≤ 60 MB je Request), damit `post_max_size` reicht.

---

## 11. Exporte

- **CSV:** UTF-8 mit BOM, Trennzeichen `;`, Beträge mit Komma – öffnet direkt in Excel (DE) und eignet sich als Serienbrief-Quelle in Word/LibreOffice. Zellen, die mit `=`, `+`, `-` oder `@` beginnen, erhalten ein führendes `'` (`csv_safe()`, Schutz vor Formel-Ausführung; auch bei Telefonnummern mit `+`).
- **XLSX:** eine Tabelle, fette hinterlegte Kopfzeile, fixierte erste Zeile, Autofilter, Spaltenbreite nach Inhalt, Zahlen als Zahlen. Getestet mit Excel-kompatiblen Lesern (openpyxl, LibreOffice).
- **Spalten** (`Contacts::exportTable`): Anrede, Vorname, Nachname, Beziehung, [Adresse], [Festnetz], [Mobil], [E-Mail] + bereichsspezifisch (Kontakte: Angerufen/Brief/Notiz · Zu-/Absagen: Status/Erwachsene/Kinder/Notiz · Kondolenzen: Karte/Blumen/Kranz/Spende/Betrag/Dankeskarte/Notiz).
- **Etiketten:** A4 ohne Seitenränder, Formate in `configs/config.php → label_formats` (mm). Start-Etikett für angebrochene Bögen. Nur Kontakte mit Straße und Ort.

---

## 12. Frontend

### Gestaltung
| Token | Wert | Verwendung |
|---|---|---|
| `--schiefer` | `#2e3438` | Text, Navigation |
| `--schiefer-tief` | `#1d2225` | Anmeldeseite, Leuchtkasten-Umfeld |
| `--kalk` | `#f0efeb` | Seitenhintergrund |
| `--papier` | `#fbfbf9` | Flächen |
| `--efeu` | `#4a5d4f` | Primärfarbe, aktive Zustände |
| `--messing` | `#9c8558` | feine Linien, Portrait-Rahmen, Akzent |
| `--nebel` | `#d9dcd6` | Rahmen, Trennlinien |

- Schrift: EB Garamond für Namen, Überschriften, Gedenktexte; Source Sans 3 für die Oberfläche.
- Leitmotiv: `.portrait-frame` – Portrait im wählbaren Rahmen (Standard `oval`: doppelter Messingrand wie ein Porzellanbild am Grabstein). Klasse `frame-<schlüssel>`, Auswahl im Formular „Gedenkseite bearbeiten“, wirkt auf Startseite und Anmeldeseite. Oval, Rund, Rechteckig, Passepartout und Holz sind reines CSS; Antik-Gold, Barock und Trauerflor sind SVG-Overlays aus `assets/img/frames/` (`::after`, 14 % Überstand, Seitenverhältnis 4:5 bleibt). Neuer Rahmen → Schlüssel in `Frame::KEYS`, CSS-Block `.frame-<schlüssel>`, Text `memorial.frame.<schlüssel>` in de und en.
- Ohne Portrait zeigt `.portrait-empty` eine neutrale Silhouette (`assets/img/portrait-placeholder.svg`, per CSS-Hintergrund) – kein Datensatz, nur Fallback auf Startseite, Anmeldeseite und Rahmenauswahl.
- Leere Listen (`.empty`) nennen bei Schreibrecht die nächste Aktion als Button (Kontakte: „Ersten Kontakt anlegen“, Galerie: „Erste Bilder hochladen“). Es werden bewusst keine Beispieldaten angelegt.
- Bewegung nur auf der Anmeldeseite (langsame Überblendung, leichter Zoom); `prefers-reduced-motion` wird respektiert.
- Mobile first; Listen statt Tabellen in den Arbeitsbereichen.

### Kontakt-Tabelle
- Spalten: `Contacts::tableColumns()` (rechte- und feldrechteabhängig, Textfelder in `Contacts::TEXT_FIELDS`). Neues Kontaktfeld → dort eintragen, dann erscheint es in der Tabelle.
- Spalte „Dankeskarte versendet“ (`thanks`, Ja/Nein): nur mit Leserecht auf Kondolenzen, standardmäßig ausgeblendet; umschaltbar nur mit Recht „Abhaken“ auf Kondolenzen, sonst schreibgeschützt.
- Nur für Admins: schreibgeschützte Spalte „Zugang“ (Benutzername des mit `contact_id` verknüpften Zugangs, standardmäßig ausgeblendet; der Controller ergänzt `access` je Zeile). Knopf „Zugänge“ in der Auswahlleiste öffnet den Dialog (Gruppe, Klebepapier, Startstreifen).
- Browser-Speicher (`localStorage`): `gdk.ct.cols` (sichtbare Spalten), `gdk.ct.w` (Breiten), `gdk.ct.filters` (Filterzeile).
- Sortierung, Spaltenfilter, Suche und laufende Nummer arbeiten nur im Browser auf den geladenen Zeilen; die Nummer wird nicht gespeichert.
- Bearbeiten nur mit Recht „Kontakte bearbeiten“, Rückmeldung nur mit Recht „Zu-/Absagen abhaken“; Spalten ohne Feldrecht fehlen komplett.

### JavaScript-Konventionen
- **CSP erlaubt keine Inline-Skripte.** Daten für JS kommen über `data-*`-Attribute oder `<script type="application/json" id="…">`.
- Alle Anfragen über `send(url, data)` in `app.js` (fügt CSRF-Token und `X-Requested-With: fetch` hinzu).
- Verhalten wird über Attribute angebunden:

| Attribut | Wirkung |
|---|---|
| `data-confirm="Text"` (form) / `data-confirm-click="Text"` (submit-Button) | Rückfrage vor dem Absenden |
| `data-autosubmit` | Select sendet Formular bei Änderung |
| `data-search="<selector>"` | clientseitige Suche über `data-text` der Zielelemente |
| `data-confirm-count="<js-Schlüssel>"` (am Formular) | Bestätigung mit Plural über `#cartCount` (Text `js.<Schlüssel>`, `{n}` = Anzahl) |
| `.lang-switch` (Formular, `_lang.php`) | Sprachumschalter DE/EN in Navigation und auf Seiten ohne Anmeldung |
| `data-toggle-task="<id>"` | Aufgabe abhaken |
| `data-station="<json>"` (an Modal-Buttons) | füllt den Stationen-Dialog (in `plugins/stations/assets/js/stations.js`) |
| `data-sub-toggle="sub-<id>"` | Unteraufgaben ein-/ausklappen (merkt Zustand in localStorage) |
| `form[data-cl-form]` (+ `data-cl-modal`) | Checkliste: Formular per `send()` abschicken, Modal schließen, Kategorieblöcke/Zähler/Meldung aus der JSON-Antwort einsetzen (`applyChecklist`); `#clRoot` = Container, `[data-cl-lead]` = Zählzeile, `[data-cl-empty]` = Leerhinweis |
| `data-flag="<feld>" data-id="<id>"` | Ja/Nein-Umschalter Kontakte/Kondolenzen |
| `form[data-rsvp]` | Zu-/Absage automatisch speichern, Summen aktualisieren |
| `data-task`, `data-cat`, `data-user`, `data-album` (JSON) | befüllen das jeweilige Bootstrap-Modal |
| `data-pick="<bild-id>"`, `data-select-all` | Auswahl |
| `data-open="<index>"`, `data-slideshow` | Leuchtkasten / Diashow |
| `data-copy="<text>"` | in Zwischenablage kopieren |
| `table[data-sortable]` | Spaltenkopf-Klick sortiert die Tabelle (auf-/absteigend, `aria-sort`); `data-v` an einer Zelle liefert einen numerischen Sortierwert (z. B. Zeitstempel). Aktuell: Verwaltung → Protokoll |
| `data-ct` (`#ctable`), `data-ct-search`, `data-ct-ids` (form) | Kontakt-Tabelle: Sortieren, Spaltenfilter, Spaltenauswahl (Rechtsklick auf Kopf), Breite ziehen, Inline-Bearbeitung (Doppelklick), Auswahl, Fußleiste, Rückgängig. Spalten kommen als JSON aus `#ctCols`; `data-ct-ids` hängt die Auswahl als `ids[]` an das Formular |
| `data-view="cards\|table"` (Link) | merkt die Kontakt-Ansicht im Cookie `gdk_cview` |

---

## 13. Konfiguration (`configs/config.php`)

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `app_name` | In Erinnerung | Titel, falls kein Name hinterlegt |
| `timezone` | Europe/Berlin | |
| `data_dir` | `dirname(__DIR__).'/data'` | möglichst außerhalb des Webroots |
| `base_url` | `''` | öffentliche Basis-URL ohne `index.php`; hat Vorrang vor `settings.json → base_url` (Ersteinrichtung). Leer und nicht gespeichert: Freigabelinks aus der Anfrage, **keine Reset-Mails** |
| `session_name`, `session_idle_minutes` | gdk_session, 240 | |
| `login_max_attempts`, `login_window_minutes`, `login_lock_minutes` | 5, 15, 15 | Rate-Limit je IP |
| `login_lock_max_hours` | 24 | Obergrenze der IP-Sperre; die Dauer verdoppelt sich bei jeder weiteren Sperre (`strikes` in `ratelimit.json`, verfällt nach dieser Zeit) |
| `login_max_attempts_user`, `login_user_cooldown_max_seconds` | 10, 60 | Je Benutzername (alle IPs, Schlüssel `u:<sha256>`): ab dieser Zahl Fehlversuche kurze, wachsende Wartezeit (2^n s, höchstens der Wert) – nie Dauersperre. Schützt vor verteilten Angriffen ohne Aussperrgefahr |
| `log_full_ip`, `log_retention_months` | false, 12 | |
| `backup_keep_days` | 14 | Tages-Snapshots |
| `upload_max_mb`, `thumb_size`, `medium_size`, `jpeg_quality`, `image_memory_limit` | 40, 480, 1800, 88, 768M | |
| `label_formats` | 3x8, 2x7, 2x6 | Etikettenmaße in mm |
| `access_formats` | 2x6, 2x9 (`Access::FORMATS`) | Klebestreifen-Bögen in mm (wie `label_formats`); das erste Format ist der Standard: 2 × 6 à 99,1 × 42 mm |
| `privacy_url` | leer | Optionaler Link zur Datenschutzerklärung auf den Seiten „Passwort vergessen“ |
| `mail_from` | leer | Absender für „Passwort vergessen“; leer = `noreply@<Domain der Anwendung ohne www./Port>` (`Reset::fromAddress()`) |
| `image_max_pixels` | 50000000 | Obergrenze Breite × Höhe beim Upload (0 = unbegrenzt); Prüfung vor dem Dekodieren |
| `api_allow_http` | false | Plugin „api“: Token-Zugriff auch über unverschlüsseltes HTTP erlauben (sonst nur HTTPS bzw. Loopback) |
| `plugins_safe_mode` | false | `true`: kein Plugin-Code wird ausgeführt (Notausgang, falls ein Plugin die Anwendung blockiert) |

---

## 14. Code-Konventionen

- PSR-12-Stil, 4 Leerzeichen, `declare(strict_types=1)`, Typdeklarationen überall.
- Oberflächentexte **nie fest im Code**, sondern über `t('bereich.schlüssel', ['name' => …])` bzw. `tn('…', $n)` (Plural, `{n}` = formatierte Zahl) aus `app/lang/<sprache>/*.php`; in Views `e(t(…))`. Deutsch ist Basis und Rückfall, Englisch muss dieselben Schlüssel und Platzhalter haben (`php bin/check-lang.php`). Deutsch: Du-Form, Satzschreibung; Englisch: sachlich, Satzschreibung. Buttons nennen die Aktion („Kontakt speichern", nicht „Absenden").
- Konstanten mit Beschriftungen (`Perm`, `Log`, `Contacts`, `Albums`) halten nur Schlüssel; die Beschriftung liefern `Perm::levels()/modules()/fields()`, `Log::actions()`, `Contacts::rsvpLabels()/filterLabels()` bzw. `t('cf.<feld>')`, `Albums::colorName()`.
- JS-Texte: Schlüssel `js.*` in den Sprachdateien (auch die der Plugins); das Layout liefert sie als `<script type="application/json" id="i18n">`, `app.js`/`stations.js` lesen sie mit `tr(key, params, n)`.
- Datum/Zahlen: `fmt_ts()`, `fmt_date()`, `fmt_money()`, `fmt_bytes()`, `lang_dec()`/`lang_thou()` – Deutsch `31.12.2026` und `1.234,50`, Englisch ISO `2026-12-31` und `1,234.50`.
- Ausgabe in Views **immer** über `e()`; JSON in Attributen `e(json_encode(…))`, in `<script type="application/json">` mit `JSON_HEX_TAG`.
- Rechte **im Controller** prüfen, Views blenden nur zusätzlich aus.
- Jede Datenänderung: `Store::update()` + `Log::change()` bzw. `Log::add('admin', …)`.
- Neue Kontaktfelder: `Contacts::blank()`, ggf. `fromForm()`, `exportTable()`, Formular-View.
- Neuer Bereich mit Rechten: `Perm::MODULES` erweitern → erscheint automatisch in der Gruppenverwaltung; Route in `dispatch()` eintragen; Menüpunkt in `layout.php`; `Perm::firstRoute()` berücksichtigt ihn automatisch.
- Neue Route: Eintrag in `$routes` (`bootstrap.php`), Controller-Funktion `ctrl_<name>`, bei öffentlicher Route in `$public` (nur wenn zwingend).
- Keine externen Ressourcen (CDN, Webfonts, Analytics) – CSP blockiert sie ohnehin.
- **Keine absoluten URLs oder Pfade** im Code: Links über `url()`, Assets über `asset()`, alles andere relativ zu `base_path()`. Ausnahme: Freigabelinks und der Zugangsdaten-Ausdruck über `site_base_url()` (Papier braucht eine absolute Adresse).
- Löschaktionen immer mit `data-confirm`/`data-confirm-click`.

---

## 15. Testfälle (manuell, im Container durchgeführt)

| Bereich | Fall | Ergebnis |
|---|---|---|
| Login | falsches Passwort, 5 Fehlversuche → Sperre auch bei richtigem Passwort | ✔ |
| Login (Rate-Limit) | 12 Fehlversuche von wechselnden IPs → Wartezeit für das Konto; zweite IP-Sperre dauert doppelt so lang; ab 3 Fehlversuchen in Folge 1–3 s Antwortverzögerung | ✔ (Skript-Test, nicht im Browser) |
| CSRF | POST ohne Token → 419 | ✔ |
| Rechte | Helfer: Kondolenzen 403, Verwaltung 403, Formular 403, Original-Download 403, Adresse/E-Mail ausgeblendet, Export ohne Adressspalten | ✔ |
| Freigabelink | öffnet Zu-/Absagen, Gedenkseite → Weiterleitung, Galerie/Kontakte/Bilder 403, nach Sperre → Login | ✔ |
| vCard | 3.0, 2.1 mit Quoted-Printable + Softbreak, 4.0 mit `tel:`-URI, Escapes; Reimport erkennt 3 Dubletten | ✔ |
| Bilder | EXIF-Orientierung 6 gedreht, GPS entfernt (verlustfrei bzw. neu kodiert), PNG mit Transparenz, animiertes GIF bleibt animiert, `.vcf` abgelehnt | ✔ |
| Öffentliche Bilder | nicht markiertes Bild 404, markiertes und Portrait 200 | ✔ |
| ZIP | Auswahl, Album, alles (mit Albumordnern) | ✔ |
| Export | CSV-BOM/Semikolon, XLSX von openpyxl und LibreOffice gelesen | ✔ |
| Etiketten | 2×7 mit Startetikett 3 | ✔ |
| Layout | ISPConfig-Variante mit `web/` + `private/gedenkseite` | ✔ |
| Layout | Unterverzeichnis `/beerdigung/` (alles in einem Ordner): Anmeldung, AJAX, Assets, Cookie-Pfad `/beerdigung/`, Freigabelink, Aufruf ohne `index.php` | ✔ |
| Browser | Chromium Desktop 1280 px und Mobil 390 px, keine Konsolenfehler | ✔ |

---

## 16. Grenzen und mögliche Erweiterungen

- Ausgelegt für einige Hundert Kontakte und einige Tausend Bilder. Darüber: SQLite-Backend hinter `Store` erwägen.
- Sprachen: Deutsch und Englisch. Weitere Sprache = Ordner `app/lang/<code>/` mit allen Dateien, Eintrag in `I18n::LANGS`, Pluralregel in `I18n::plural()` (de/en: `n == 1` → `one`), bei Rechts-nach-links-Sprachen zusätzlich CSS. Nicht übersetzt (bewusst): Protokolleinträge in `data/logs` (bleiben als geschriebene Audit-Daten Deutsch; nur die Aktionsnamen sind übersetzt), Nutzerinhalte (Gedenktext, Notizen, Albumnamen), Kategorien und Aufgaben der Start-Checkliste sowie Standardgruppen (werden einmalig in der Sprache der Einrichtung angelegt), Bundesland-Vorschläge, Namen der Druckformate in `config.php`.
- **Passwort vergessen (DSGVO):** Mailadresse nur über den verknüpften Kontakt (`users.contact_id` → `contacts.email`), kein eigenes Benutzerfeld. Konten ohne Kontakt/Mail (z. B. der erste Admin) können nur über Weg 2 oder durch einen anderen Admin zurückgesetzt werden. Tokens 256 Bit, nur als SHA-256 gespeichert, 60 min, einmalig; Limit 5 Anfragen/h je IP, 3/h je Benutzername; Anfragen nach 30 Tagen gelöscht (`Log::maintenance` → `Reset::purge`); `resets.json` bleibt aus Snapshots und Backup-Download; Passwortwechsel setzt `users.pw_changed` und beendet ältere Sitzungen (`$_SESSION['login_t']`). Links in Mails entstehen nur aus `configured_base_url()` (nie aus dem Host-Header); ohne Basis-URL entfällt der Versand. Mailversand über `mail()` (Zustellung hängt vom MTA des Servers ab: SPF/DKIM für die Absenderdomain). Datenschutzhinweis auf `forgot` (Mail/Hilfe) und `reset` über `app/views/_reset_privacy.php`; optionaler Link zur Datenschutzerklärung über `privacy_url`. Der Text beschreibt nur die tatsächliche Verarbeitung und ersetzt keine rechtliche Prüfung – bei Änderungen am Speicherverhalten anpassen.
- Eigenes Passwort ändern über `r=account` (freiwillig, kein Änderungszwang). Zugangsdaten für Kontakte: Passwort-Klartext gibt es nur beim Anlegen/Zurücksetzen; verloren = „Passwort neu setzen und drucken“.
- Adressetiketten ohne Absenderzeile; die Absenderadresse wird getrennt über „Absenderetiketten drucken“ auf eigene Bögen gedruckt (`labels.php` mit `$sender`).
- Denkbar: Druckansicht „Gästeliste für das Gasthaus", Sitzplan, Kondolenzbuch für Gäste über Freigabelink (Schreibrecht nur für eigenen Eintrag).

## 17. Plugins

Entwicklerleitfaden: `docs/plugin-development.md`. Hier die Festlegungen der Anwendung.

- **Ordner** `plugins/<slug>/` mit Pflichtdateien `plugin.json` (Manifest, `Plugins::parseManifest`) und `plugin.php`
  (`return function (PluginApi $p): void`). Der Slug ist die Ordnerkennung (`^[a-z][a-z0-9-]{1,39}$`).
- **Zustand**: `settings.json → plugins.<slug>.active`; fehlt der Eintrag, gilt `default_active` des Manifests.
  `Plugins::boot()` (in `dispatch()`, vor dem Routing) lädt alle aktiven Plugins in Abhängigkeitsreihenfolge (`requires`),
  prüft `requires_app` und fängt Ausnahmen aus `plugin.php` ab (das Plugin gilt dann als „nicht geladen“, Grund in der Verwaltung).
  `plugins_safe_mode` überspringt das Laden.
- **Automatisch aus der Ordnerstruktur** (nur aktive Plugins): `lib/` → Autoload (nach dem Kern); `lang/<sprache>/*.php` → zu den
  Sprachdateien ergänzt (Kern hat Vorrang; `I18n::reset()` nach dem Laden); Manifest-`stores` → `Store::extend()` mit Standardinhalt;
  `views/` → `view('@slug/name')`; `assets/` → `plugin_asset('slug', 'pfad')` über Route `pluginasset`.
- **PluginApi**: `route()` (Datei wird erst beim Aufruf per `require_once` geladen, Pfad auf den Plugin-Ordner geprüft; Kernrouten
  haben Vorrang; immer Anmeldung nötig), `nav()` (Callback für Sichtbarkeit, Einfügeposition über `after`; `Plugins::applyNav()` im Layout),
  `apiRoute()` (wie `route()`, aber ohne Sitzungsanmeldung und CSRF-Prüfung; der Controller muss sich selbst authentifizieren und danach `Auth::actAs()` aufrufen; `dispatch()` überspringt dafür Login- und CSRF-Prüfung, `Plugins::isApiRoute()`),
  `onActivate()`, `onDeactivate()`. Bewusst klein gehalten; weitere Erweiterungspunkte (Rechtebereiche, Admin-Seiten, Filter/Hooks in Views) erst bei Bedarf.
- **Verwaltung** (`admin&a=plugins`, nur Admin, POST + CSRF + Protokoll `Log::add('admin', …)`):
  - *Aktivieren*: prüft Mindestversion, Abhängigkeiten, Kollision der Datendateien; lädt das Plugin sofort, ruft `onActivate`; bei Fehler Rückgängig.
  - *Deaktivieren*: verweigert, solange ein aktives Plugin dieses benötigt; ruft `onDeactivate`. Daten bleiben unverändert.
  - *Import* (immer verfügbar für Admins): ZIP ≤ 10 MB / entpackt ≤ 20 MB / ≤ 300 Dateien, Pfad- und Verknüpfungsprüfung, Dateityp-Whitelist
    (php, json, js, css, svg, png, jpg, jpeg, gif, webp, woff, woff2, txt, md; keine Punktdateien), PHP-Syntaxprüfung per `token_get_all(TOKEN_PARSE)`,
    Schreiben in `plugins/.tmp-*` und Umbenennen. Ein vorhandener Slug wird nie überschrieben. Das Plugin ist danach **inaktiv**.
  - *Export*: ZIP des Plugin-Ordners (`<slug>/…`, ohne Nutzdaten), temporär in `data/tmp/`.
  - *Löschen*: nur inaktive, nicht `core`-Plugins. Checkbox „Daten ebenfalls löschen“ entfernt die `stores`-Dateien (vorher Snapshot `Store::dailyBackup(true)`);
    ohne Haken bleiben die Daten und sind nach erneutem Import wieder da.
- **Sicherheit**: Plugin-Code ist vertrauenswürdiger Code ohne Sandbox. `plugins/` ist per `.htaccess` gesperrt (nginx: wie `app/`); ausgeliefert wird nur
  `assets/` über `pluginasset` (angemeldet, aktives Plugin, Endungs-Whitelist, `realpath`-Prüfung). Backup-Download enthält `plugins/` nicht – der Ordner gehört zur Installation.
- **Web-API und MCP** (`plugins/api/`, standardmäßig inaktiv): REST-API (`r=api&p=/pfad`), MCP-Server (`r=mcp`), Token-Verwaltung (`r=apitokens`). Persönliche Zugriffstoken (SHA-256-Hash in `data/api_tokens.json`), Bereich `read`/`write`, Rechte des Besitzers inkl. Feldrechte. Zugriffsprotokoll (`r=apilog`, Admin, `data/logs/api/JJJJ-MM.jsonl`, nur Parameternamen). Ausführlich: `docs/api.md`.
- **Stationen** ist das vorinstallierte Kern-Plugin (`core: true`, `default_active: true`): Route `stations`, Menüpunkt hinter „Gedenken“,
  Datendatei `stations.json`. Albumfarben/-kategorie (`Albums::COLORS`, `Albums::colorName()`, Album-Felder `color`/`cat`) gehören dem Kern und bleiben auch ohne das Plugin bestehen.
  Ist das Plugin deaktiviert, liefert `r=stations` 404 und der Menüpunkt entfällt.

## Als Benutzer anmelden („Run as user“)

- Nur Admins, nur auf **aktive Benutzer mit `role = user`** (nie Admins, nie sich selbst, nie Freigabelinks). Start per POST `admin&a=impersonate`, Button in `admin_users.php`.
- Sitzung: `$_SESSION['imp'] = ['admin' => <uid>, 'login_t' => <alter Wert>]`, `uid` = Ziel, `login_t = time()` (sonst würde ein jüngeres `pw_changed` des Ziels die Sitzung beenden). Beim Start und bei der Rückkehr `session_regenerate_id(true)`.
- Es gelten Rechte und Feldrechte des Ziels; Admin-Routen sind gesperrt (`Perm::needAdmin`). Die Kontoseite (`account`) ist gesperrt (403), damit Passwort des Ziels nicht geändert wird.
- `Auth::user()` liefert zusätzlich `via` (Benutzername des Admins). `Log::add()` schreibt es als `v` in jede Zeile; Protokollansicht zeigt „via admin“, Export hängt `(via admin)` an die Benutzerspalte.
- Beenden: Banner-Button (`unimpersonate`) **oder Logout** → zurück als Admin (`Auth::stopImpersonation()`). Ist der Admin inzwischen deaktiviert, kein Admin mehr oder hat sein Passwort geändert, wird die Sitzung komplett beendet (Hinweis `imp.admin_gone`).
- Hat das Ziel keinen zugänglichen Bereich (`Perm::firstRoute() === null`), wird die Übernahme sofort zurückgenommen.

---

## 18. Export und Import (`app/lib/Transfer.php`)

Admin → Backup. Ergänzt die Backup-Downloads: der Admin wählt per Dialog, welche **Bereiche** in ein ZIP-Paket kommen (Konfiguration: `settings`, `groups`, `configphp`; Inhalte: `memorial`, `gallery` mit Bilddateien, `contacts` inkl. Zu-/Absagen und Kondolenzen, `checklist`, `plugindata` = `stores` der installierten Plugins; Zugänge: `users`, `shares`, `logs`). Vorbelegt sind Konfiguration und Inhalte.

- **Paket:** `manifest.json` (`format` = `funeralorg-export`, `version`, `app`, `created`, `parts`), `data/<store>.json`, `data/uploads/<orig|medium|thumb>/<id>.<ext>`, `data/logs/JJJJ-MM.jsonl`, `config/config.json` (nur Schlüssel aus `Transfer::CONFIG_KEYS`, keine Pfade/Basis-URL). Nicht verschlüsselt; `resets`/`ratelimit` nie enthalten.
- **Import in zwei Schritten:** `stage()` entpackt nur erlaubte Pfade (Whitelist, kein `..`, keine Verknüpfungen, Größen-/Dateigrenzen), prüft JSON-Form und Bild-MIME (ungültige Bilder werden übersprungen) und legt das Paket unter `data/tmp/import-<token>/` ab (nach 1 h verworfen). Die Vorschau listet die gefundenen Bereiche mit Kennzahlen. `apply()` legt zuerst `data/backups/vor-import-<Zeitstempel>/` an, **ersetzt** dann die gewählten Stores vollständig (`Store::update`) und schreibt Bilder/Protokolle (vorhandene Bilddateien werden nicht gelöscht, gleichnamige überschrieben).
- **Sonderfälle:** `settings` behält die `base_url` dieser Installation. `configphp` ist beim Import nur eine Ansicht (Vergleich und PHP-Auszug), `configs/config.php` wird nie geschrieben. `users` wird abgelehnt, wenn das Paket keinen aktiven Administrator enthält; nach dem Import der Konten wird der Admin abgemeldet. Plugin-Code gehört nicht ins Paket (Plugins → Export).
- **Kompatibilität:** Auch Daten-/Komplett-Backups (ohne Manifest) lassen sich importieren. Ein Paket mit höherer `version` wird abgelehnt.
