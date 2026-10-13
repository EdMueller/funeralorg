# Changelog

All notable changes are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project uses [Semantic Versioning](https://semver.org/). While the version is below 1.0.0, anything may change.

## [Unreleased]

### Added

- Sender address (Admin → Sender, up to 5 lines) and printing of sender labels on a full sheet of the existing
  address label formats, with selectable start label
- Address and sender labels: label format 2 × 6 (99.1 × 42 mm)

### Changed

- Access-details printing: format 2 × 10 removed; 2 × 6 (99.1 × 42 mm) is now the default

## [0.0.2] - 2026-10-12

### Added

- Access-details printing: label format 2 × 6 (99.1 × 42 mm, 12 labels per A4 sheet, 8 mm left and 22 mm top margin,
  no gap between labels)

### Changed

- Access-details printing: the length from which a username is printed in the smaller font now depends on the
  label width

## [0.0.1] - 2026-10-12

First public release.

### Added

- Memorial page with portrait, dates, quote and text; login page with a slideshow of selected photos
- Stations: timeline and world map, linked to albums
- Pictures: albums, multi-file upload, lightbox, slideshow, selection and ZIP download
- Tasks: checklists with due dates, assignees and notes, plus a starter checklist created during setup
- Contacts: addresses, phone numbers, call/letter status, vCard import, XLSX/CSV export, address labels
- RSVPs with live totals and condolences (card, flowers, wreath, donation, thank-you card)
- Users, groups with area and field permissions, shared links, “log in as user” for administrators
- Statistics dashboard, activity log, daily snapshots and manual backup download
- “Forgot password” by email link or request to the administrator
- English and German interface
- Setup page that creates the first administrator and stores the public address (`base_url`)
- `VERSION` file with the version number, shown in the administration dashboard

### Security

- Password-reset links are built only from the configured public address, never from the Host header;
  without it no reset emails are sent
- CSV exports neutralise cells that start with `=`, `+`, `-` or `@`
- Login timing no longer depends on whether the account exists (same hash cost for unknown users)
- Uploads are limited by pixel count (`image_max_pixels`, default 50 million)

### Documentation and project files

- README in English and German, `ECKDATEN.md` (technical reference, German), `SECURITY.md`,
  `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `THIRD-PARTY.md`
- GitHub Actions (syntax check, language files, smoke test on PHP 8.2 to 8.4) and issue/pull request templates

[Unreleased]: https://github.com/EdMueller/funeralorg/compare/v0.0.2...HEAD
[0.0.2]: https://github.com/EdMueller/funeralorg/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/EdMueller/funeralorg/releases/tag/v0.0.1
