# Drittanbieter-Komponenten

Alle Komponenten liegen lokal im Repository (keine CDNs). Die Anwendung selbst steht unter
der GNU GPL v3 (siehe `LICENSE`); die folgenden Komponenten behalten ihre eigenen Lizenzen,
die mit der GPL-3.0 kompatibel sind.

| Komponente | Version | Lizenz | Urheber (laut Dateikopf bzw. Schrift-Metadaten) | Ort im Projekt |
|---|---|---|---|---|
| [Bootstrap](https://getbootstrap.com/) (CSS, JS-Bundle) | 5.3.3 | MIT | © 2011–2024 The Bootstrap Authors | `assets/vendor/bootstrap/` |
| [Bootstrap Icons](https://icons.getbootstrap.com/) (CSS, Schrift) | 1.11.3 | MIT | © 2019–2024 The Bootstrap Authors | `assets/vendor/bootstrap/` |
| [Chart.js](https://www.chartjs.org/) | 4.4.4 | MIT | © 2024 Chart.js Contributors | `assets/vendor/chartjs/` |
| Weltkarte `world.svg`, abgeleitet aus [Natural Earth](https://www.naturalearthdata.com/) 1:50m Admin 0 – Countries (Winkel Tripel, `bin/make-worldmap.php`) | – | gemeinfrei | Natural Earth | `assets/vendor/worldmap/` |
| [EB Garamond](https://github.com/octaviopardo/EBGaramond12) (woff2, 400/500, normal/kursiv) | 1.003 | SIL Open Font License 1.1 | © 2017 The EB Garamond Project Authors | `assets/fonts/` |
| [Source Sans 3](https://github.com/adobe-fonts/source-sans) (woff2, 400/600) | 3.052 | SIL Open Font License 1.1 | © 2023 Adobe, Reserved Font Name „Source“ | `assets/fonts/` |

## Herkunft der Angaben

- **Bootstrap, Bootstrap Icons, Chart.js:** Version, Urheber und „MIT“ stehen in den Kopfkommentaren der
  mitgelieferten Dateien.
- **Schriften:** Version, Urheberzeile und OFL-Verweis stammen aus der `name`-Tabelle der `.woff2`-Dateien
  (Namens-IDs 0, 5 und 14). Die vollständigen Lizenztexte liegen neben den Dateien:
  `assets/fonts/LICENSE-EBGaramond.txt` und `assets/fonts/LICENSE-SourceSans3.txt`. Sie sind der unveränderte
  Text der SIL Open Font License 1.1 mit den Urheberzeilen der Schriften.
- **Weltkarte:** Quelle und Erzeugung stehen in `assets/vendor/worldmap/README.txt`.

## Hinweise

- Die OFL erlaubt Weitergabe und Einbettung der Schriften zusammen mit der Anwendung, verlangt aber, dass
  Urheberzeile und Lizenztext mitgeliefert werden (erledigt) und die Schriften nicht für sich allein verkauft werden.
- Für Source Sans 3 ist „Source“ ein reservierter Schriftname. Wer die Schriftdateien verändert (zum Beispiel
  neu subsettet) und weitergibt, muss sie umbenennen.
- Dateien in `assets/vendor/` werden nicht bearbeitet. Updates ersetzen sie unverändert; danach Version und
  Lizenz in dieser Tabelle prüfen.
