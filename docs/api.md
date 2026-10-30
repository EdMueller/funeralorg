# Web-API und MCP-Server (Plugin „api“)

Das Plugin `plugins/api/` stellt eine REST-API und einen MCP-Server (Model Context Protocol) bereit. Es ist ausgeliefert, aber
**standardmäßig inaktiv**: Aktivieren unter **Verwaltung → Plugins**. Danach erscheint im Menü „API-Zugang“ (Token-Verwaltung).

## Sicherheitsmodell

- **Persönliche Zugriffstoken** (`fo_` + 64 Hex-Zeichen, 256 Bit). Gespeichert wird nur der SHA-256-Hash (`data/api_tokens.json`); der Klartext
  erscheint einmal nach dem Erstellen.
- Ein Token handelt **immer als sein Besitzer**: Rolle, Gruppenrechte (Bereiche, Galerie) und Feldrechte (Adresse, Telefon, Mobil, E-Mail) gelten wie in der
  Oberfläche, auch für die Ausgabe. Zusätzlich hat jedes Token einen **Bereich**: `read` (nur GET) oder `write`.
- Ungültig wird ein Token bei Ablauf (30 Tage bis unbegrenzt), Widerruf, deaktiviertem Besitzer und nach einem **Passwortwechsel** des Besitzers.
- Nur **HTTPS** (hinter einem Proxy `X-Forwarded-Proto: https`), lokal (Loopback) oder mit `'api_allow_http' => true` in `configs/config.php`.
- Die API ignoriert Sitzungs-Cookies vollständig und verlangt deshalb kein CSRF-Token. Ein Cookie allein berechtigt zu nichts.
- Administratoren erstellen Token für sich oder andere Benutzer und widerrufen alle. Ob normale Benutzer eigene Token erstellen dürfen, steht in der Token-Verwaltung (Standard: nein).
- Nicht erreichbar über die API: Benutzer, Gruppen, Freigabelinks, Plugins, Backup, Protokoll (nur die Oberfläche für Administratoren).
- **Datenschutz:** Ein KI-Assistent überträgt abgerufene Daten an den Modellanbieter. Vergib Token sparsam und möglichst nur zum Lesen.
- Export/Import (Admin → Backup) nimmt die Token-Datei als „Plugin-Daten“ mit (nur Hashes).

## Zugriffsprotokoll

Administratoren sehen unter **API-Zugang → Zugriffsprotokoll** (`r=apilog`), wer wann über Token zugegriffen hat: Übersicht je Token
(Aufrufe, schreibende, Fehler, zuletzt) und die Einzeleinträge, filterbar nach Zeitraum, Token, Protokoll (REST/MCP) und „nur schreibende“/„nur Fehler“.
Gespeichert wird in `data/logs/api/JJJJ-MM.jsonl` je REST-Aufruf und je MCP-`initialize`/`tools/call`: Zeit, Token (ID, Bezeichnung), Benutzer,
Operation, Pfad, HTTP-Status, **Namen** der Parameter, gekürzte IP (`Log::ip()`), Client-Kennung. **Nie** Parameterwerte oder Token.
Fehlgeschlagene Anmeldungen stehen höchstens einmal pro IP und Minute darin. Aufbewahrung wie das übrige Protokoll (`log_retention_months`);
Export/Import (Admin → Backup) enthält diese Dateien nicht. Änderungen erscheinen zusätzlich im Änderungsprotokoll („via API“).

## REST

Aufruf: `index.php?r=api&p=<Pfad>`, Methode = HTTP-Verb, Header `Authorization: Bearer <Token>`. Schreibende Aufrufe senden JSON
(`Content-Type: application/json`); der Upload ist `multipart/form-data`. Antwort: `{"ok": true, "data": …}` bzw. `{"ok": false, "error": "…"}` mit
Status 400/401/403/404/405/422. Die maschinenlesbare Beschreibung (OpenAPI 3) liefert `index.php?r=api&p=/openapi.json` (ohne Token).

```bash
curl -H "Authorization: Bearer fo_…" "https://example.org/index.php?r=api&p=/contacts&filter=thanks_open"
curl -H "Authorization: Bearer fo_…" -H "Content-Type: application/json" \
     -d '{"title":"Blumen bestellen","cat":"<kategorie-id>"}' "https://example.org/index.php?r=api&p=/checklist/tasks"
```

Der Pfad steht im Query-Parameter `p`, weil die Anwendung keine URL-Umschreibung voraussetzt (läuft auch im Unterverzeichnis und ohne Rewrite-Regeln).
Beim Apache wird der `Authorization`-Header per `.htaccess` an PHP durchgereicht (bei ISPConfig mit eigener Konfiguration ggf.
`SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`).

| Methode | Pfad | Art | Beschreibung |
|---|---|---|---|
| `GET` | `/me` | lesen | Angemeldeter Benutzer, Token-Bereich und Rechte |
| `GET` | `/summary` | lesen | Kennzahlen wie auf der Startseite (offene Aufgaben, Rückmeldungen, offene Dankeskarten, Anrufe) |
| `GET` | `/memorial` | lesen | Gedenkseite: Name, Lebensdaten, Spruch, Text, Portrait-Bild-ID, Rahmen |
| `PATCH` | `/memorial` | schreiben | Gedenkseite ändern (nur Administratoren); nur übergebene Felder werden gesetzt |
| `GET` | `/contacts` | lesen | Kontakte auflisten (nur Felder, die der Benutzer sehen darf) |
| `GET` | `/contacts/{id}` | lesen | Einen Kontakt lesen |
| `POST` | `/contacts` | schreiben | Kontakt anlegen (Recht „Kontakte bearbeiten“; Vor- oder Nachname nötig) |
| `PATCH` | `/contacts/{id}` | schreiben | Kontakt ändern; nur übergebene Felder, soweit die Rechte reichen (Abhaken, Rückmeldung, Kondolenz je nach Bereichsrecht) |
| `DELETE` | `/contacts/{id}` | schreiben | Kontakt unwiderruflich löschen |
| `GET` | `/rsvp/summary` | lesen | Summen der Zu-/Absagen (Zusagen, Absagen, offen, Erwachsene, Kinder) |
| `GET` | `/checklist` | lesen | Checkliste: Kategorien und alle Aufgaben samt Unteraufgaben in Anzeigereihenfolge |
| `GET` | `/checklist/tasks/{id}` | lesen | Eine Aufgabe lesen |
| `POST` | `/checklist/tasks` | schreiben | Aufgabe anlegen: in Kategorie `cat` oder als Unteraufgabe von `parent` |
| `PATCH` | `/checklist/tasks/{id}` | schreiben | Aufgabe ändern. `done` verlangt das Recht „Abhaken“, alles andere „Bearbeiten“ |
| `POST` | `/checklist/tasks/{id}/move` | schreiben | Aufgabe verschieben/umsortieren. Ohne `cat`/`parent` bleibt die Ebene, `position` (0 = ganz oben) setzt die Stelle. Hauptaufgaben mit Unteraufgaben können keine Unteraufgabe werden |
| `DELETE` | `/checklist/tasks/{id}` | schreiben | Aufgabe samt Unteraufgaben löschen |
| `POST` | `/checklist/categories` | schreiben | Kategorie anlegen |
| `PATCH` | `/checklist/categories/{id}` | schreiben | Kategorie umbenennen |
| `DELETE` | `/checklist/categories/{id}` | schreiben | Kategorie samt allen Aufgaben löschen |
| `GET` | `/gallery/albums` | lesen | Alben mit Bildanzahl in Baumreihenfolge (Elternalbum vor seinen Unteralben, je Ebene wie in der Galerie). `sort` = Position unter dem Heimat-Elternalbum, `ord` = Position je Elternalbum (`_root` = oberste Ebene) |
| `POST` | `/gallery/albums` | schreiben | Album anlegen (nur Administratoren) |
| `PATCH` | `/gallery/albums/{id}` | schreiben | Album ändern (nur Administratoren); nur übergebene Felder (u. a. `hidden`: für Nicht-Admins unsichtbar) |
| `DELETE` | `/gallery/albums/{id}` | schreiben | Album löschen; Unteralben und Bilder rücken in das übergeordnete Album auf |
| `PUT` | `/gallery/albums/{id}/albums-order` | schreiben | Reihenfolge der Alben unter einem Elternalbum setzen (`id` = Album-ID oder `_root` für die oberste Ebene, `ids` in neuer Reihenfolge; Verweise zählen mit); nur Administratoren |
| `PUT` | `/gallery/albums/{id}/order` | schreiben | Reihenfolge der Bilder eines Albums setzen (`id` = Album-ID oder `_none`); Recht „Galerie hochladen“ |
| `GET` | `/gallery/images` | lesen | Bilder auflisten (Metadaten, ohne Bilddaten) |
| `GET` | `/gallery/images/{id}` | lesen | Bild-Metadaten lesen |
| `PATCH` | `/gallery/images/{id}` | schreiben | Bildunterschrift/Album/Verweise ändern (Administratoren oder Hochlader des Bildes); `login` nur Administratoren |
| `DELETE` | `/gallery/images/{id}` | schreiben | Bild samt Dateien löschen (nur Administratoren) |
| `GET` | `/gallery/images/{id}/file` | lesen | Bilddatei ausliefern (binär) |
| `POST` | `/gallery/images` | schreiben | Bilder hochladen (multipart/form-data: `files[]`, optional `album`) |
| `GET` | `/stations` | lesen | Stationen (Plugin „Stationen“), nur lesend |

Details zu Parametern: OpenAPI-Beschreibung. Besonderheiten:

- `PATCH` setzt nur übergebene Felder. Felder ohne Recht werden **ohne Fehler ignoriert** (wie in der Oberfläche); lesen kann man sie ebenfalls nicht.
- Kontakte: Wer nur „Abhaken“, Zu-/Absagen oder Kondolenzen bearbeiten darf, ändert nur diese Felder; Namen, Adresse und Notiz bleiben unverändert.
- Aufgaben: `done` verlangt das Recht „Abhaken“, alles andere „Bearbeiten“. Es gibt nur eine Ebene Unteraufgaben.
- Listen (`limit` ≤ 500, `offset`) liefern `total` und `items`.

## MCP

Der Server spricht MCP über **Streamable HTTP** (zustandslos, JSON-RPC 2.0 per POST, Antworten als `application/json`) unter `index.php?r=mcp`,
Protokollversionen 2025-06-18, 2025-03-26 und 2024-11-05, Fähigkeit `tools`. Die Tools entstehen aus derselben Tabelle wie die REST-Routen
(`ApiSpec`), z. B. `contacts_list`, `contacts_update`, `checklist_task_create`, `gallery_albums`; dazu `gallery_image_view` (liefert ein Bild als Bildinhalt).
Lesetoken sehen nur lesende Tools. Zerstörende Tools sind mit `destructiveHint` markiert. Ein `Origin`-Header muss zum eigenen Host passen.
Binäre Operationen (Bild-Upload, Bilddatei) gibt es nur über REST.

**Claude Code** (HTTP):

```bash
claude mcp add --transport http funeralorg "https://example.org/index.php?r=mcp" --header "Authorization: Bearer fo_…"
```

**Claude Desktop** und andere Clients, die nur lokale Server starten: die stdio-Brücke `plugins/api/bin/mcp-stdio.php` (läuft auf dem Rechner des Clients,
PHP ≥ 8.2; kopiere die Datei dorthin):

```json
{ "mcpServers": { "funeralorg": {
    "command": "php", "args": ["/pfad/zu/mcp-stdio.php"],
    "env": { "FUNERALORG_URL": "https://example.org/index.php?r=mcp", "FUNERALORG_TOKEN": "fo_…" } } } }
```

Clients, die für entfernte MCP-Server ausschließlich OAuth unterstützen (z. B. gehostete Connectoren), funktionieren mit Token nicht;
ein OAuth-2.1-Server ist nicht enthalten.

## Aufbau

```
plugins/api/
├── plugin.json / plugin.php      Routen api, mcp (apiRoute) und apitokens (Sitzung), Menüpunkt
├── lib/ApiSpec.php               Tabelle aller Operationen → REST, MCP-Tools, OpenAPI
├── lib/ApiService.php            Fachlogik (Rechte, Feldrechte, Store, Protokoll) – ein Ort für REST und MCP
├── lib/ApiTokens.php             Token erstellen/prüfen/widerrufen, Bearer-Header, HTTPS-Prüfung
├── lib/ApiLog.php                Zugriffsprotokoll (schreiben, lesen, Kennzahlen, Aufbewahrung)
├── lib/Mcp.php                   JSON-RPC-Verarbeitung (initialize, ping, tools/list, tools/call)
├── controllers/api.php|mcp.php|tokens.php|log.php
├── views/tokens.php|log.php, lang/{de,en}/api.php
└── bin/mcp-stdio.php             stdio-Brücke
```

Kern-Erweiterungen dafür: `PluginApi::apiRoute()` (Route ohne Sitzungsanmeldung und ohne CSRF; der Controller authentifiziert selbst),
`Auth::actAs()` (Anfrage als Token-Besitzer, im Protokoll mit „via API“), `is_ajax()` ist bei `API_REQUEST` wahr (Fehlerseiten als JSON) und
`cl_move()` in `app/controllers/checklist.php` (Verschieben, von Oberfläche und API genutzt).
