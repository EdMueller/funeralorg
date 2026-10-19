# Changelog

All notable changes are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project uses [Semantic Versioning](https://semver.org/). While the version is below 1.0.0, anything may change.

## [Unreleased]

### Added

- Export and import (Admin → Backup): choose in a dialog which areas go into a ZIP package (settings, groups and
  permissions, `config.php` values, memorial page, gallery with images, contacts, checklist, plugin data, user
  accounts, share links, log). The import checks the package, shows what it contains, lets you select the areas to
  replace and saves the current JSON files first. Data/full backups can be imported as well. `configs/config.php`
  is never written, differing values are only shown.

## [0.0.7] - 2026-10-19

### Added

- Checklist: tasks can be reordered by drag and drop (handle) or with arrow buttons, and moved to another category
  or under another task (and back). Manually ordered tasks come first; open tasks stay before completed ones.

## [0.0.6] - 2026-10-18

### Added

- Gallery: pictures can be re-ordered per album (drag and drop, or arrow buttons on touch devices) or sorted by
  upload date, file name or caption. Admins and users with upload permission may do this; new uploads are appended.

## [0.0.5] - 2026-10-18

### Added

- Plugin system (Admin → Plugins): list, activate, deactivate, import (ZIP), export (ZIP) and delete plugins,
  optionally including their data. A plugin is a folder `plugins/<slug>/` with `plugin.json` and `plugin.php`;
  it can register routes, menu items, data files, language files, classes, views and assets
  (see `docs/plugin-development.md`)
- `plugins_safe_mode` in `configs/config.php` starts the application without running any plugin code

### Changed

- Stations (timeline and map) is now a pre-installed plugin (`plugins/stations/`, can be deactivated but not deleted);
  data in `stations.json` is unchanged
- Album colours (`Albums::COLORS`, `Albums::colorName()`) moved from the stations code into the core

## [0.0.4] - 2026-10-14

### Fixed

- Tasks: saving, deleting and moving without a reload did nothing in Firefox and Safari (the form target was taken
  from `submitter.formAction`, which returns the page URL there), and the page was returned instead of a JSON response

## [0.0.3] - 2026-10-14

### Added

- Sender address (Admin → Sender, up to 5 lines) and printing of sender labels on a full sheet of the existing
  address label formats, with selectable start label
- Address and sender labels: label format 2 × 6 (99.1 × 42 mm)

### Changed

- Access-details printing: format 2 × 10 removed; 2 × 6 (99.1 × 42 mm) is now the default
- Tasks: subtasks use a smaller font size and tighter line height
- Tasks: adding, editing, moving and deleting categories, tasks and subtasks, and ticking tasks off, update the page
  without a reload (JSON responses with server-rendered fragments; without JavaScript the redirect still works)

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

[Unreleased]: https://github.com/EdMueller/funeralorg/compare/v0.0.7...HEAD
[0.0.7]: https://github.com/EdMueller/funeralorg/compare/v0.0.6...v0.0.7
[0.0.6]: https://github.com/EdMueller/funeralorg/compare/v0.0.5...v0.0.6
[0.0.5]: https://github.com/EdMueller/funeralorg/compare/v0.0.4...v0.0.5
[0.0.4]: https://github.com/EdMueller/funeralorg/compare/v0.0.3...v0.0.4
[0.0.3]: https://github.com/EdMueller/funeralorg/compare/v0.0.2...v0.0.3
[0.0.2]: https://github.com/EdMueller/funeralorg/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/EdMueller/funeralorg/releases/tag/v0.0.1
