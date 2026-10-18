# TODO – Funktionen und Verbesserungen

Stand: 16.10.2026. Quellen: ECKDATEN.md §16 („Grenzen und mögliche Erweiterungen"), §2 („bewusst nicht umgesetzt")
und Vorschläge aus der Code-Durchsicht (mit *Vorschlag* markiert). Reihenfolge innerhalb der Abschnitte = grobe Priorität.

## Angedacht (aus ECKDATEN.md)

- [ ] Druckansicht „Gästeliste für das Gasthaus" (Zusagen mit Erwachsenen/Kindern, ohne Adressen)
- [ ] Sitzplan
- [ ] Kondolenzbuch für Gäste über Freigabelink (Schreibrecht nur für den eigenen Eintrag; Regel 7 bleibt: kein Zugriff auf Gedenkseite/Galerie)
- [ ] Bilder per Drag & Drop sortieren (aktuell Reihenfolge = Upload-Reihenfolge)
- [ ] Adressetiketten optional mit Absenderzeile (heute nur getrennt über Absenderetiketten)
- [ ] Weitere Sprache(n) neben Deutsch/Englisch (`I18n::LANGS`, Pluralregel, ggf. RTL-CSS)
- [ ] SQLite-Backend hinter `Store`, falls die Grenzen (einige Hundert Kontakte, einige Tausend Bilder) überschritten werden

- [ ] Weltkarte nach Art von Google Earth (Zoomen, Verschieben, ggf. Globus/Satellitenbild). Konflikt mit Regel 1 (kein CDN, keine externen Anfragen): nur mit lokal ausgelieferten Karten- bzw. Kachel­daten unter `assets/vendor/` oder nach Rücksprache; Datenschutz (Kachelserver) klären
- [ ] Passkeys-Login (WebAuthn) als Alternative zum Passwort; Verifikation serverseitig ohne Composer, Zuordnung je Benutzer in `users.json`, Registrierung unter `r=account`, Passwort-Login bleibt als Rückfall
- [ ] Bilder-Upload für Benutzer: Hochladen durch Benutzer ohne eigene Gruppenrechte-Pflege klären und umsetzen (Upload besteht bereits über das Galerie-Recht „hochladen“ der Gruppe; Umfang der Erweiterung noch zu präzisieren, z. B. Upload per Freigabelink oder eigene Bilder verwalten)

## Vorschläge

### Betrieb und Sicherheit
- [ ] Wiederherstellen eines Snapshots in der Oberfläche (heute nur Download der Backups)
- [ ] Hinweis im Dashboard bei fehlender Basis-URL / nicht zustellbarer Reset-Mail
- [ ] Optionaler Ablauf von Benutzerzugängen (analog zu Freigabelinks: `expires`)
- [ ] Automatisierte Tests für `Perm`, `Store`, `Stations::parseDate`, `VCard` (ohne Composer, als `bin/test.php`)

### Kontakte, Zu-/Absagen, Kondolenzen
- [ ] Export der Kondolenzen als Dankeskarten-Liste (nur offene „Dankeskarte versendet")
- [ ] Sammelaktion „alle Zusagen → Erwachsene/Kinder übernehmen" bzw. Summen je Beziehung
- [ ] Dublettenprüfung auch beim manuellen Anlegen und Zusammenführen vorhandener Dubletten
- [ ] CSV-/XLSX-Import (neben vCard)

### Aufgaben
- [ ] Sortierung per Drag & Drop (Aufgaben, Unteraufgaben)
- [ ] Filter „Meine Aufgaben" / nach Zuständigkeit und überfällig
- [ ] Wiederverwendbare Vorlagen für Checklisten (Neustart mit Standardliste)

### Bilder und Gedenkseite
- [ ] Mehrere Bilder auf einmal bearbeiten (Unterschrift, Album) – Sammelbearbeitung neben Verschieben
- [ ] Drehen von Bildern nach dem Upload
- [ ] Druckansicht der Gedenkseite (Trauerfeier-Programm)

### Oberfläche
- [ ] Barrierefreiheit prüfen (Kontraste, Fokusreihenfolge, Screenreader in Leuchtkasten und Kontakt-Tabelle)
- [ ] Mobile Kontakt-Tabelle (Spaltenauswahl/Filter ohne Rechtsklick bedienbar machen)

### Dokumentation
- [ ] Screenshots im README nach den letzten Änderungen (Aufgaben ohne Reload, Etiketten) aktualisieren

## Bewusst nicht geplant

PWA/Offline, Zwei-Faktor-Anmeldung, Kosten-/Belegmodul, Fristen-/Vertragsmodul mit iCal-Export, Versand von
Einladungen oder Rundschreiben per E-Mail (siehe ECKDATEN.md §2).
