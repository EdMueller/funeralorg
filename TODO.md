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

## Ideen (Produkt und Geschäft)

Aufgenommen am 19.10.2026. Bereits an anderer Stelle der Liste: Trauerfeier-Programm (Druckansicht der Gedenkseite), Checklisten-Vorlagen, Snapshot-Wiederherstellung, Passkeys, SSO, Newsletter, Premium-Plugins mit Abo. Aufwand grob: S klein, M mittel, L groß.

### Gedenken und Trauerfeier
- [ ] **QR-Code für die Grabstein-Anbringung** (S): Link auf eine öffentliche Gedenkseite als QR-Code (`app/lib/Qr.php` besteht), Druckvorlage mit Maßen für Plakette oder Aufkleber, hohe Fehlerkorrektur und Mindestgröße für den Außeneinsatz, Download als SVG/PNG/PDF. Setzt die öffentliche Gedenkseite voraus
- [ ] **Öffentliche Gedenkseite per Link** (M): optional, nur ausgewählte Inhalte (kein Zugriff auf Kontakte/Galerie-Verwaltung; Regel 7 sinngemäß), Moderation, Spam-Schutz, Abschaltbar, Hinweis auf Datenschutz
- [ ] **Virtuelle Kerzen und Erinnerungen von Gästen** (M): Gäste zünden eine Kerze an oder schreiben eine Erinnerung; Einträge werden moderiert (Spam-Schutz, Rate-Limit)
- [ ] **Livestream- und Aufzeichnungs-Link** zur Trauerfeier (S): Link samt Hinweis/Zeit auf der Seite für Gäste, die nicht anreisen können (nur Verweis, kein Streaming in der Anwendung)
- [ ] **Spenden statt Blumen** (S): Textseite mit Spendenzweck und IBAN, ohne Zahlungsabwicklung
- [ ] **Mehrere Verstorbene / Familienseiten** (L): mehrere Gedenkseiten in einer Installation, Zuordnung von Kontakten und Galerie

### Organisation
- [ ] **Terminübersicht mit iCal-Export** (M): Fristen und Termine zentral, Export für den Kalender (heute unter „Bewusst nicht geplant“, vorher entscheiden)
- [ ] **Erinnerungen zu Aufgaben per E-Mail** (M): braucht einen verlässlichen Mailversand (heute nur „Passwort vergessen“ über `mail()`), SMTP-Option und Abmeldemöglichkeit
- [ ] **Dokumentenablage** (L): Urkunden, Verträge, Rechnungen als Anhänge je Aufgabe oder Kontakt; eigenes Recht, Größenlimits, Ablage außerhalb des Webroots, Auslieferung nur über Controller, Export/Import berücksichtigen

### Erinnerung und Inhalte
- [ ] **Lebenslauf und Zeitleiste als Druck/PDF** (M): automatisch aus Stationen und Gedenkseite
- [ ] **Erinnerungs-Buch als PDF oder Druck** (L): Galerie, Texte und Kondolenzen zu einem Buch zusammengestellt; guter erster Premium-Kandidat
- [ ] **Audio- und Videoerinnerungen von Gästen** (L): Kurzbeiträge hochladen; Größen- und Formatlimits, evtl. Transkodierung (braucht externe Programme, Regel 1 beachten), Moderation

### KI-Funktionen (nur zuschaltbar, nie vorab aktiv)
Alle KI-Funktionen brauchen einen externen Dienst. Das widerspricht Regel 1 (keine externen Anfragen) und die Daten sind höchst persönlich: nur als **zuschaltbares (Premium-)Plugin** mit ausdrücklichem Datenschutzhinweis, Auswahl des Anbieters, Protokoll der Aufrufe und Freigabe nur durch Admins.
- [ ] **KI-Bildverbesserung** (M): Restaurieren, Schärfen, Kolorieren alter Fotos. Das Original bleibt unverändert, das Ergebnis wird als eigene Variante abgelegt und als „bearbeitet“ gekennzeichnet
- [ ] **Sanfte Foto-Animation ohne Sprache** (M): z. B. Ken-Burns-Effekt, leichte Bewegung; deutlich unkritischer als Gesichts- und Stimmnachbildung
- [ ] **Animierte Gesichtsveränderungen und Stimmnachahmung des Verstorbenen** (L, **erst nach rechtlicher Prüfung**): Persönlichkeitsrecht wirkt nach dem Tod fort (Einwilligung zu Lebzeiten, Rechte der Angehörigen), die EU-KI-Verordnung verlangt Kennzeichnung synthetischer Inhalte; emotionale Risiken für Trauernde und Missbrauchsgefahr (Fälschungen). Wenn überhaupt: eigenes Plugin, schriftliche Einwilligungsabfrage, sichtbare Kennzeichnung „KI-erzeugt“, nur Admins, nie vorab aktiv, keine erfundenen Aussagen in der Stimme des Verstorbenen

### Geschäftsmodell und Betrieb
- [ ] **Service-Betrieb mit Whitelabeling** (L): Mandantentrennung (`data_dir` je Mandant oder Mandanten-ID), eigene Domains, Logo, Farben (Design-Tokens in `app.css`), konfigurierbarer Name statt „In Erinnerung“, E-Mail-Absender je Mandant, Abrechnung. Gehört mit Premium-Plugins/Abo und signierten Paketen zu einem Block; Betreiberseitiger Shop und Lizenzserver sind eigene Dienste
- [ ] **Automatische Sicherung auf externen Speicher** (M): SFTP oder S3, mit Verschlüsselung, ergänzt Export und Import; Konflikt mit Regel 1 beachten (nur zuschaltbar)

## Bewusst nicht geplant

PWA/Offline, Zwei-Faktor-Anmeldung, Kosten-/Belegmodul, Fristen-/Vertragsmodul mit iCal-Export, Versand von
Einladungen per E-Mail (siehe ECKDATEN.md §2). Rundschreiben/Newsletter stehen inzwischen unter „Angedacht“.
