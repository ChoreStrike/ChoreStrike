# Security Policy

## Supported Versions

ChoreStrike is pre-1.0. Security fixes are developed on `master` and included
in subsequent releases. Older releases do not receive backported security
fixes.

## Reporting a Vulnerability

**Do not open a public issue or discussion for security vulnerabilities.**
Report them through GitHub private vulnerability reporting instead: on the
[repository](https://github.com/ChoreStrike/ChoreStrike) open
*Security → Report a vulnerability* (a GitHub account is required). The report
stays private between you and the maintainer, and keeps the discussion with
the code. If your report requires sharing highly sensitive information,
describe the issue first without including those details.

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
