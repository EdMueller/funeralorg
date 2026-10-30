# Contributing

Thanks for your interest! Bug reports, ideas and pull requests are welcome. Issues can be written in English or
German. Security problems: please follow [SECURITY.md](SECURITY.md) instead of opening an issue.
By taking part you agree to the [Code of Conduct](CODE_OF_CONDUCT.md).

## Principles

The project is deliberately small and easy to host and audit:

- **No database, no Composer, no framework, no CDN.** Data lives in JSON files and is accessed only through
  `Store::read()` / `Store::update()`. New libraries are discussed first and, if accepted, shipped locally under
  `assets/vendor/` (and listed in `THIRD-PARTY.md`).
- PHP ≥ 8.2 with `declare(strict_types=1)`, vanilla JavaScript, Bootstrap 5.
- The interface works on phones first.

A complete description of architecture, routes, permissions and data model is in
[ECKDATEN.md](ECKDATEN.md) (German). Please read the relevant parts before changing code.

## Conventions

- Every write action: **POST + CSRF** (checked centrally in `dispatch()`), a permission check in the controller and a
  log entry (`Log::change()` or `Log::add('admin', …)`).
- Output in views only through `e()`. No inline scripts (Content Security Policy); pass data to JavaScript with
  `data-*` attributes or `<script type="application/json">`.
- Field permissions (`Perm::field()`) apply to display, export **and** saving. Shared links must never see the
  memorial page, the gallery or pictures.
- No hard-coded interface texts: use `t()` / `tn()` with keys from `app/lang/de/` **and** `app/lang/en/`.
  German uses the informal “Du” and sentence case; buttons name the action.
- No absolute URLs or paths: `url()`, `asset()`, `base_path()`. The application must work at a domain root and in a
  subdirectory.
- Do not edit `assets/vendor/` or `data/`.
- Match the style of the surrounding code (see `.editorconfig`).

## Before you open a pull request

```bash
find . -name '*.php' -not -path './data/*' -exec php -l {} \; | grep -v 'No syntax'
php bin/check-lang.php
php bin/test-plugins.php   # only if you touch plugins or the marketplace
bin/smoke-test.sh
```

`bin/smoke-test.sh` copies the project to a temporary directory, runs the built-in PHP server, performs the setup and
checks the main routes, permissions and “log in as user”. It does not touch your own `data/` or `configs/config.php`.
`bin/test-plugins.php` tests the plugin core and the GitHub client against a local mock (`bin/github-mock.php`); the smoke test
also walks through the marketplace screens with it. No test talks to the real GitHub. The same checks run in GitHub Actions
for PHP 8.2 to 8.4.

Please also check the change manually where it matters: as administrator, as a user with a restricted group and
through a shared link, and on a phone-sized screen (about 390 px). Update `ECKDATEN.md` if you change routes, the data
model or configuration keys.

## Releases

The version number lives in the `VERSION` file and must match the newest entry in `CHANGELOG.md` (checked in CI).
To release: move the entries from “Unreleased” to a new version heading, update `VERSION`, commit, tag
(`git tag -a vX.Y.Z`), push the tag and create the GitHub release from the changelog entry.

## Pull requests

- Keep them focused: one topic per pull request.
- Describe what changes and why; link the issue if there is one.
- New or changed behaviour should come with a line in the smoke test if it can be checked with `curl`.

## License

By contributing you agree that your contribution is licensed under the
[GNU General Public License v3.0](LICENSE), like the rest of the project.
