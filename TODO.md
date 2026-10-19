# TODO – Funktionen und Verbesserungen

Stand: 19.10.2026. Quellen: ECKDATEN.md §16 („Grenzen und mögliche Erweiterungen"), §2 („bewusst nicht umgesetzt")
und Vorschläge aus der Code-Durchsicht (mit *Vorschlag* markiert). Reihenfolge innerhalb der Abschnitte = grobe Priorität.

## Angedacht (aus ECKDATEN.md)

- [ ] Druckansicht „Gästeliste für das Gasthaus" (Zusagen mit Erwachsenen/Kindern, ohne Adressen)
- [ ] Sitzplan
- [ ] Kondolenzbuch für Gäste über Freigabelink (Schreibrecht nur für den eigenen Eintrag; Regel 7 bleibt: kein Zugriff auf Gedenkseite/Galerie)
- [ ] Adressetiketten optional mit Absenderzeile (heute nur getrennt über Absenderetiketten)
- [ ] Weitere Sprache(n) neben Deutsch/Englisch (`I18n::LANGS`, Pluralregel, ggf. RTL-CSS)
- [ ] SQLite-Backend hinter `Store`, falls die Grenzen (einige Hundert Kontakte, einige Tausend Bilder) überschritten werden

- [ ] Bessere Weltkarte (Stationen-Plugin): Zoomen und Verschieben, ggf. Globus/Satellitenbild, Clustering nahe beieinanderliegender Stationen, Routen zwischen Stationen. Konflikt mit Regel 1 (kein CDN, keine externen Anfragen): nur mit lokal ausgelieferten Karten- bzw. Kacheldaten unter `assets/vendor/` oder nach Rücksprache; Datenschutz (Kachelserver) klären
- [ ] Koordinaten automatisch aus dem Ortsnamen übernehmen (Stationen: `place` → `lat`/`lon`, manuelle Korrektur bleibt möglich). Konflikt mit Regel 1 bei externen Geocodern (Nominatim u. a.; Ortsnamen verlassen den Server): Alternativen sind ein lokaler Ortsindex (z. B. GeoNames-Auszug als Datei, durchsuchbar ohne Fremdaufruf) oder ein ausdrücklich zuschaltbarer serverseitiger Abruf mit Hinweis
- [ ] Passkeys-Login (WebAuthn) als Alternative zum Passwort; Verifikation serverseitig ohne Composer, Zuordnung je Benutzer in `users.json`, Registrierung unter `r=account`, Passwort-Login bleibt als Rückfall
- [ ] SSO (OpenID Connect, Authorization-Code-Flow mit PKCE) als Anmelde-Alternative, z. B. als Plugin; JWT-Prüfung (RS256) mit `openssl_verify` ohne Composer, Zuordnung per `sub`/E-Mail zu vorhandenen Benutzern (keine automatische Anlage ohne Freigabe), Einstellungen für Issuer/Client-ID/Secret, Passwort-Login bleibt als Rückfall. Braucht einen Erweiterungspunkt im Login
- [ ] Newsletter-/Rundschreibenverwaltung (Empfängerlisten aus Kontakten, Vorlagen, Versand per Mail mit Abmeldelink, Versandprotokoll). Widerspricht dem bisherigen Ausschluss „Versand von Rundschreiben per E-Mail“ (ECKDATEN.md §2); vorher klären: Einwilligung/DSGVO, Versandweg (`mail()` vs. SMTP), Zustellbarkeit (SPF/DKIM), Umfang als Plugin
- [ ] Premium-Plugins mit Bezahloption (Abo): Plugin-Katalog, Lizenzschlüssel bzw. Abo-Status je Installation, Zahlungsanbieter (z. B. Mollie/Stripe/PayPal), Prüfung und Verlängerung, Verhalten bei Ablauf (Plugin deaktivieren, Daten behalten). Plugin-Code läuft ohne Sandbox: Pakete sollten signiert sein (Signaturprüfung vor Import/Update). Betreiberseitiger Shop und Lizenzserver sind eigene Dienste außerhalb dieser Anwendung; Konflikt mit Regel 1 (Fremdaufrufe) und Datenschutz klären
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
Einladungen per E-Mail (siehe ECKDATEN.md §2). Rundschreiben/Newsletter stehen inzwischen unter „Angedacht“.
