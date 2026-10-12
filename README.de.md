# Gedenkseite – Trauerfall digital organisieren

[English](README.md) · **Deutsch**

Selbstgehostete Webanwendung für die Familie: Gedenkseite, Bildergalerie mit Diashow,
Aufgaben-Checklisten, Kontakte mit Anruf-/Briefstatus, Zu- und Absagen zur Trauerfeier,
Kondolenzen und Danksagung. PHP 8.2+, **keine Datenbank** (JSON-Dateien), keine externen
Abhängigkeiten, keine CDNs. Die Oberfläche gibt es auf Deutsch und Englisch.

| | |
|---|---|
| ![Anmeldeseite](docs/screenshots/login.jpg) | ![Gedenkseite](docs/screenshots/memorial.jpg) |
| ![Bilder](docs/screenshots/pictures.jpg) | ![Stationen mit Zeitstrahl und Karte](docs/screenshots/stations.jpg) |
| ![Aufgaben](docs/screenshots/tasks.jpg) | ![Kontakte](docs/screenshots/contacts.jpg) |

Die Screenshots zeigen erfundene Beispieldaten. Die Oberfläche ist für das Handy ausgelegt:

<img src="docs/screenshots/mobile.jpg" alt="Gedenkseite auf dem Handy" width="240">

## Funktionen

- **Gedenkseite** mit Portrait, Lebensdaten, Spruch und Text; Anmeldeseite mit Diashow ausgewählter Fotos
- **Stationen:** Zeitstrahl und Weltkarte, verknüpft mit Alben
- **Bilder:** Alben, Mehrfach-Upload, Leuchtkasten, Diashow, Auswahl und ZIP-Download
- **Aufgaben:** Checklisten mit Fälligkeit, Zuständigkeit und Notizen; Start-Checkliste bei der Einrichtung
- **Kontakte:** Adressen, Telefon, Anruf-/Briefstatus, vCard-Import, Export als XLSX/CSV, Adressetiketten
- **Zu- und Absagen** mit Live-Summen sowie **Kondolenzen** (Karte, Blumen, Kranz, Spende, Dankeskarte)
- **Rechte:** Benutzer, Gruppen mit Bereichs- und Feldrechten, Freigabelinks (nur Lesezugriff auf freigegebene Bereiche)
- **Verwaltung:** Statistik, Protokoll, Backup, „Als Benutzer anmelden“

Eine vollständige technische Beschreibung (Architektur, Routen, Datenmodell) steht in **[ECKDATEN.md](ECKDATEN.md)** (deutsch).

## Schnellstart zum Ausprobieren

```bash
git clone <repository-url> gedenkseite && cd gedenkseite
php -S 127.0.0.1:8080
```

Dann `http://127.0.0.1:8080/` öffnen; beim ersten Aufruf startet die Einrichtung (Administrator anlegen,
öffentliche Adresse bestätigen). Für den echten Betrieb: Abschnitt „Installation“.

> Der eingebaute PHP-Server wertet keine `.htaccess` aus: `data/` und `app/` wären darüber direkt abrufbar.
> Nur lokal und mit Testdaten verwenden, nie im Netz erreichbar machen.

## Voraussetzungen

- PHP ≥ 8.2 mit `gd`, `zip`, `mbstring`, `fileinfo`, `json` (empfohlen: `exif` für automatische Bilddrehung)
- Apache oder nginx mit PHP-FPM, HTTPS (z.B. mit Let's Encrypt)
- PHP-Einstellungen: `upload_max_filesize = 40M`, `post_max_size = 64M`, `memory_limit = 768M`, `max_execution_time = 300`

## Installation

Die Anwendung kennt weder Domain noch Installationspfad: Alle Links und Assets sind relativ,
`index.php` findet das Anwendungsverzeichnis selbst. Betrieb unter `https://example.com/`
oder unter `https://example.com/gedenkseite/` ist ohne Änderung möglich.

### Variante 1 – Unterverzeichnis einer bestehenden Website (Apache)

Alles liegt in einem Ordner unterhalb des Webroots, `app/`, `bin/`, `data/` und `docs/`
werden per `.htaccess` gesperrt. Voraussetzung: `AllowOverride All` (Standard bei Apache).

```bash
unzip /tmp/gedenkseite.zip -d /tmp/gs
cd /tmp/gs/gedenkseite
WEB=/var/www/clients/client1/web1/web/gedenkseite
mkdir -p "$WEB"

# Gesamten Projektordner kopieren (inkl. .htaccess, die app/, bin/, configs/, data/, docs/ sperrt)
rsync -a --exclude .git ./ "$WEB/"

chown -R web1:client1 "$WEB"
chmod -R o-rwx "$WEB"
```

Aufruf: `https://example.com/gedenkseite/` – beim ersten Aufruf startet die Einrichtung (siehe unten).

Danach prüfen, dass die Sperren greifen – beide Aufrufe müssen 403 oder 404 liefern:

```bash
curl -sI https://example.com/gedenkseite/data/contacts.json | head -1
curl -sI https://example.com/gedenkseite/app/bootstrap.php   | head -1
```

> Bei dieser Variante liegen die Daten im Webroot und sind nur durch `.htaccess` geschützt.
> Wird `AllowOverride` später abgeschaltet oder die Website auf nginx umgestellt, sind
> `data/` und `config.php` offen. Dann auf Variante 2 wechseln.

### Variante 2 – eigene Website, Daten außerhalb des Webroots

Die Verwaltungsebene legt je Website `web/` (Webroot) und `private/` an:

```bash
cd /var/www/clients/client1/web1
mkdir -p private/gedenkseite
cp -a /tmp/gs/gedenkseite/{app,bin,configs,data,docs,VERSION} private/gedenkseite/
cp -a /tmp/gs/gedenkseite/{index.php,.htaccess,assets} web/
rm -f web/index.html                             # ISPConfig-Platzhalter (vorher prüfen)

chown -R web1:client1 private/gedenkseite web
chmod -R o-rwx private/gedenkseite
```

`index.php` findet die Anwendung automatisch unter `../private/gedenkseite`.
Für nginx zusätzlich die Direktiven aus `docs/nginx-ispconfig.conf` übernehmen.

### Variante 3 – freier Pfad

Entweder `GEDENK_ROOT=/pfad/zum/projekt` im PHP-FPM-Pool setzen (`env[GEDENK_ROOT] = …`)
oder neben `index.php` eine Datei `app-path.php` ablegen:

```php
<?php return '/var/www/gedenkseite';
```

### PHP-Einstellungen

In der Verwaltungoberfläche z.B. unter Website → Optionen → „Custom php.ini settings":

```
upload_max_filesize = 40M
post_max_size = 64M
memory_limit = 768M
max_execution_time = 300
```

## Einrichtung

Beim ersten Aufruf leitet die Anwendung automatisch auf die Einrichtungsseite (`index.php?r=setup`) um.
Dort legst Du den ersten Administrator an (Passwort ≥ 10 Zeichen, optional den Namen des Verstorbenen) und bestätigst die
**öffentliche Adresse** der Anwendung. Sie wird aus der Anfrage vorgeschlagen und in `data/settings.json` gespeichert; sie wird für die Links in
„Passwort vergessen“-Mails gebraucht. Ohne Adresse verschickt die Anwendung keine Mails (der Link darf nie aus dem Host-Header der
Anfrage entstehen). Später lässt sie sich über `base_url` in `configs/config.php` ändern; dieser Wert hat Vorrang.
Die Seite ist **nur erreichbar, solange noch kein Benutzer existiert** – danach liefert sie 404.
Ruf die Adresse deshalb direkt nach dem Hochladen auf, bevor jemand anderes es tut.

Im Hintergrund (idempotent, überschreibt keine vorhandenen Daten):

1. prüft PHP-Version und Erweiterungen
2. erzeugt `configs/config.php` aus `config.sample.php` (falls nicht vorhanden)
3. legt `data/` mit Unterordnern an (der Webserver-Benutzer muss dort schreiben dürfen, sonst erscheint ein Hinweis)
4. legt die Gruppen **Familie**, **Helfer**, **Gasthaus / Caterer** an
5. befüllt die Checkliste mit typischen Aufgaben (Sofort, Bestattung, Trauerfeier, Behörden & Nachlass, Verträge & Kündigungen, Danksagung)
6. legt den ersten Administrator an und speichert die öffentliche Adresse (`settings.json`)

## Erste Schritte nach dem Login

1. **Verwaltung → Gruppen & Rechte**: Rechte der Standardgruppen prüfen
2. **Verwaltung → Benutzer**: Familienmitglieder anlegen
3. **Bilder**: Alben anlegen, Fotos hochladen; für die Anmeldeseite geeignete Bilder über „Bearbeiten → Auf der Anmeldeseite zeigen" markieren
4. **Gedenken → Gedenkseite bearbeiten**: Name, Lebensdaten, Spruch, Portrait (ein animiertes GIF bleibt animiert)
5. **Kontakte → vCard-Import**: Adressbuch des Verstorbenen importieren
6. **Verwaltung → Freigabelinks**: z. B. Link für das Gasthaus (nur Zu-/Absagen lesen)

> Portrait und markierte Anmeldeseiten-Bilder sind **ohne Login** abrufbar. Alles andere nur nach Anmeldung.

## Backup und Wiederherstellung

- **Automatisch:** täglich ein Snapshot aller JSON-Dateien in `data/backups/JJJJ-MM-TT/`,
  aufbewahrt werden die letzten `backup_keep_days` Tage (Standard 14; ältere Snapshots werden dabei automatisch entfernt).
- **Manuell:** Verwaltung → Backup → Daten-Backup (JSON + Protokolle) oder Komplett-Backup (inkl. Bilder).
- **Server-Backup:** Es genügt, `data/` und `config.php` zu sichern (z. B. per manuellem Backup oder `rsync`).

Wiederherstellen:

```bash
# 1. Komplettes data/ aus ZIP zurückspielen
unzip Backup_komplett_….zip -d /tmp/restore
rsync -a /tmp/restore/data/ <installationsverzeichnis>/data/

# 2. oder einzelne Datei aus Tages-Snapshot
cp data/backups/2026-09-20/contacts.json data/contacts.json

chown -R web1:client1 <installationsverzeichnis>/data
```

Beschädigte JSON-Datei? Die Anwendung meldet das mit Dateinamen – dann Datei aus dem neuesten Snapshot kopieren.

## Passwort vergessen

Ein Admin setzt es unter Verwaltung → Benutzer neu. Ist kein Admin mehr erreichbar:

```bash
php -r 'echo password_hash("NeuesPasswort123", PASSWORD_DEFAULT), "\n";'
# Hash in data/users.json beim Benutzer unter "pass_hash" eintragen
```

## Sicherheit

- Passwörter mit `password_hash()` (bcrypt/argon je PHP-Default), automatisches Rehash
- Login-Sperre nach 5 Fehlversuchen je IP für 15 Minuten
- Session-Cookie `HttpOnly`, `SameSite=Lax`, `Secure` bei HTTPS; Timeout nach 240 Minuten Inaktivität
- CSRF-Token auf allen schreibenden Anfragen
- Content-Security-Policy ohne Inline-Skripte, keine externen Ressourcen
- Uploads: MIME-Prüfung per `finfo`, Neuberechnung der Vorschaubilder, GPS/EXIF-Entfernung, Pixelgrenze (`image_max_pixels`)
- CSV-Exporte schützen Zellen vor Formel-Ausführung (führendes `=`, `+`, `-`, `@` erhält ein `'`)
- Reset-Mails verwenden nur die konfigurierte öffentliche Adresse, nie den Host-Header
- Login-Sperre und Reset-Limits zählen je `REMOTE_ADDR`. Hinter einem Reverse Proxy oder CDN sehen alle Benutzer dieselbe Adresse: Dann sperren fünf Fehlversuche alle. Betrieb deshalb möglichst ohne Proxy oder mit Anpassung von `client_ip()`
- Bilder werden ausschließlich über PHP mit Rechteprüfung ausgeliefert
- Protokoll speichert IP-Adressen gekürzt (einstellbar), Aufbewahrung 12 Monate
- Session-Cookie gilt nur für das Installationsverzeichnis, mehrere Installationen auf einer Domain stören sich nicht

## Entwicklung lokal

```bash
php -S 127.0.0.1:8080
```

Unterverzeichnis-Betrieb lokal nachstellen: Projekt nach `/tmp/wwwroot/gedenkseite/` kopieren
(Variante 1), dann `php -S 127.0.0.1:8080 -t /tmp/wwwroot` und `http://127.0.0.1:8080/gedenkseite/` aufrufen.

Syntaxprüfung: `find . -name '*.php' -not -path './data/*' -exec php -l {} \; | grep -v 'No syntax'`

## Lizenz

Die Anwendung steht unter der **GNU General Public License v3.0** (siehe [LICENSE](LICENSE)).
Bootstrap, Bootstrap Icons, Chart.js, die Weltkarte und die Schriften behalten ihre eigenen Lizenzen,
siehe [THIRD-PARTY.md](THIRD-PARTY.md).
