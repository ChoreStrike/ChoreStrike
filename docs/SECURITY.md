# Security Policy

## Supported Versions

ChoreStrike is pre-1.0. Security fixes are developed on `master` and included
in subsequent releases. Older releases do not receive backported security
fixes.

## Reporting a Vulnerability

**Do not open a public issue for security vulnerabilities.** Use the private
reporting channel below instead.

Please report privately to
[chorestrike@gallero.de](mailto:chorestrike@gallero.de).

Ordinary email is accepted, but is not end-to-end encrypted.
If your report requires sharing highly sensitive information,
contact us first without including those details.

Please include:

- A description of the issue and its impact
- Steps to reproduce or a proof of concept
- Affected version / commit

Use synthetic examples where possible. Do not send passwords, tokens or real
household data in a report.

We aim to acknowledge reports within a few days, but response and fix times
are not guaranteed for this privately maintained project.

We aim to coordinate public disclosure with the reporter.
We will only credit reporters publicly with their explicit permission,
using their preferred name or alias.

## Scope

ChoreStrike is a Nextcloud app. Reports about authorization (house membership /
ownership), input validation, SQL injection, or XSS in the app are in scope.

Please report vulnerabilities in Nextcloud core or third-party
dependencies upstream to their maintainers. Please also notify us
if the issue affects ChoreStrike or requires changes to this app.

See [`standards/guidelines/security.md`](standards/guidelines/security.md) for the
app's security architecture.
