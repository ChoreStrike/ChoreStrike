# Security Policy

## Supported Versions

ChoreStrike is pre-1.0. Security fixes target the latest `main` / latest release.

| Version | Supported |
|---------|-----------|
| latest `main` / latest release | ✅ |
| older releases | ❌ |

## Reporting a Vulnerability

**Do not open a public issue for security vulnerabilities.** The Codeberg issue
tracker is public and must not be used for security reports.

Please report privately by emailing the maintainer (see the
[Codeberg user profile](https://github.com/volker-ma) for the contact address),
preferably GPG-encrypted. If you cannot reach a private channel, contact the
maintainer first to coordinate one before sharing any sensitive details. Include:

- A description of the issue and its impact
- Steps to reproduce or a proof of concept
- Affected version / commit

You will receive an acknowledgement within a few days. We follow responsible disclosure and
will credit reporters (unless you prefer to remain anonymous).

> **Maintainer note:** a dedicated `security@` address and published GPG key should be added
> to make private reporting frictionless (tracked as a backlog follow-up).

## Scope

ChoreStrike is a Nextcloud app. Reports about authorization (house membership / ownership),
input validation, SQL injection, or XSS in the app are in scope. Please report Nextcloud-core
or third-party dependency vulnerabilities upstream to their maintainers.

See [`standards/guidelines/security.md`](standards/guidelines/security.md) for the
app's security architecture.
