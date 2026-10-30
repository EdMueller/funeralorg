## What does this change?

<!-- Short description and the reason for the change. Link the issue, if there is one. -->

## Checklist

- [ ] `php -l` reports no errors and `php bin/check-lang.php` passes
- [ ] `bin/smoke-test.sh` passes (and `php bin/test-plugins.php` if plugins or the marketplace are touched)
- [ ] No database, Composer, framework or CDN added (see `CONTRIBUTING.md`)
- [ ] Every write action uses POST + CSRF, a permission check and a log entry
- [ ] Output in views goes through `e()`; texts are language keys in **both** `app/lang/de/` and `app/lang/en/`
- [ ] Links use `url()`, assets use `asset()`; it still works in a subdirectory
- [ ] Checked as administrator, as a user with a restricted group and through a shared link (if relevant)
- [ ] Checked on a phone-sized screen (about 390 px), if the change affects the interface
- [ ] `ECKDATEN.md` is updated (routes, data model, configuration), if relevant
