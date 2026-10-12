# Security policy

## Reporting a vulnerability

Please **do not** open a public issue for security problems.

Use GitHub's private reporting instead: open the **Security** tab of this repository and choose
**Report a vulnerability**. Reports in English or German are fine.

Please include:

- what you found and which part of the application is affected (route, file, function),
- how to reproduce it, ideally on a fresh local installation with test data,
- the impact you expect (who can do what to whom).

Please never test against someone else's installation and never include real personal data in a report.

This is a small project maintained in spare time. Reports are handled on a best-effort basis; you will get an
answer as soon as possible, and a fix is released before details are published.

## Supported versions

Only the latest release (and the `main` branch) receives security fixes.

## Scope

In scope: the application code in this repository (authentication, sessions, permissions, shared links, uploads,
exports, password reset, backup download).

Out of scope: vulnerabilities in your web server, PHP, operating system or hosting setup, and installations that
ignore the hardening notes in the README (for example `data/` reachable from the web).

## Notes for operators

- Install the application so that `data/`, `app/` and `configs/` are not reachable from the web
  (README, installation options 1 and 2) and check it with the `curl` commands from the README.
- Use HTTPS and set the public address (`base_url`) during setup. Without it no password-reset emails are sent.
- Run the setup page right after installing; it is only available while no user exists.
- Behind a reverse proxy or CDN the login lock counts the proxy's address, see the “Security” section of the README.
- Keep PHP up to date and back up `data/` and `configs/config.php`.
