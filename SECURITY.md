# Security policy

This plugin handles Google OAuth credential blobs and writes a snapshot file containing those blobs to disk. Security reports are taken seriously even though this is a single-maintainer side project.

## Reporting a vulnerability

**Please do not report security issues via public GitHub issues.**

Email **cross@thisismyurl.com** with:

- A description of the issue and the affected version (Plugin header `Version:` line, or commit hash if running from `main`).
- Steps to reproduce, or a proof-of-concept if you have one.
- The impact you believe the issue has, and any mitigating factors.
- Whether you'd like credit in the public disclosure once the issue is fixed.

## What to expect

This is a single-maintainer project, so response is best-effort:

- **Acknowledgement** within 5 business days of your report.
- **Initial triage** (fix path, severity assessment, disclosure timeline) within 14 days.
- **Coordinated disclosure** once a fix lands, typically within 30–90 days depending on severity. Trivially exploitable issues that put live OAuth credentials at risk get prioritised over hardening improvements.

If a report does not get a response within the windows above, treat that as the maintainer being heads-down elsewhere rather than a refusal. A polite nudge to the same address is welcome.

## Scope

In scope:

- Code in this repository.
- The default snapshot file path, file permissions, and any data the plugin writes outside the database.
- The `wp sitekit-pin` WP-CLI commands.

Out of scope:

- Vulnerabilities in WordPress core, Google Site Kit, WP Engine infrastructure, or any other upstream dependency. Report those to the relevant project.
- Issues that require an attacker to already have shell access to the server, server-level filesystem access, or database superuser privileges. Those threat models are explicitly outside the plugin's risk surface.
- Social-engineering attacks against the maintainer.

## Supported versions

The latest tagged release on `main` is the only supported version. Older tags will not receive security patches; upgrade to the latest release.
