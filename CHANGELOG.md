# Changelog

All notable changes to Site Kit Portal Pin are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html) — `MAJOR.MINOR.PATCH`

---

## [1.0.1] — 2026-05-23

### Changed
- Standardized the donation link to GitHub Sponsors (`https://github.com/sponsors/thisismyurl`).

## [1.0.0] — 2026-05-06

First public release.

### Added

- **Configurable production URL.** No longer hard-coded. Resolved via (in priority order): `SITEKIT_PORTAL_PIN_PROD_URL` PHP constant → `sitekit_portal_pin_prod_url` WP option → `sitekit_portal_pin_prod_url` filter. Plugin no-ops silently if nothing is configured.
- **HMAC snapshot integrity.** Snapshot file is wrapped in a `{mac, body}` envelope signed with `wp_salt('auth')`. Integrity is verified on every read; a mismatch produces a single admin notice and fails closed. `wp sitekit-pin status` reports `MAC-verified OK`, `MAC-missing`, or `MAC-FAILED`.
- **Atomic snapshot write with `LOCK_EX`.** File permissions set to `0640` on write.
- **Restore throttle via transient.** The admin-init restore check runs at most once per 5 minutes using a transient (not an option write), reducing DB writes on high-traffic admin sessions.
- **Daily wp-cron snapshot.** Captures all `googlesitekit_*` options and the owner user's `wp_googlesitekit_*` user_meta to a JSON file at `dirname(WP_CONTENT_DIR)/.sitekit-prod-snapshot.json` — a path Portal copies do not touch.
- **Auto-restore on broken state detection.** On every admin page load (throttled), checks for missing credentials, owner_id mismatch, or `error_code` on the owner user. Restores from snapshot when triggered. Skips restore if snapshot is older than 30 days.
- **WP-CLI commands.** `wp sitekit-pin snapshot`, `wp sitekit-pin restore`, `wp sitekit-pin status`.
- **GitHub Actions PR lint.** `php -l` across PHP 7.4, 8.0, 8.1, 8.2, 8.3 matrix on every pull request.
- **`phpcs.xml`.** WordPress Coding Standards ruleset with documented suppressions for intentional single-file distribution and out-of-`wp-content/` file I/O.

[1.0.0]: https://github.com/thisismyurl/thisismyurl-sitekit-portal-pin/releases/tag/v1.0.0
