# Changelog

All notable changes to Site Kit Portal Pin are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
Versioning: `X.Yjjj.hhmm` — major version, last digit of year, Julian day, 24-hour time (Toronto). Example: `1.6190.1000` = major 1, year 2026, day 190, 10:00.

---

## [1.6190.1000] — 2026-07-09

### Fixed
- Correct docblock on `PROD_URL_OPTION` constant (incorrectly read "Throttle option key").
- Correct indentation in WP-CLI `snapshot` command error handler.

### Changed
- readme.txt and README.md rewritten for clarity and SEO; README.md previously described an unrelated plugin.

---

## [1.6148.2110] — 2026-05-28

### Security
- Gated the admin-load restore behind `manage_options` so only administrators can trigger a credential-state mutation.

### Added
- HMAC snapshot integrity — snapshot file is wrapped in a `{mac, body}` envelope signed with `wp_salt('auth')`. Integrity verified on every read; HMAC failure produces an admin notice and fails closed.
- Full `googlesitekit_*` option capture — snapshot includes every matching option dynamically (not just a fixed baseline list), so a restore after a Site Kit upgrade never writes a partial auth state.
- Site Kit version stamp (`googlesitekit_db_version`) captured in snapshot for diagnostics.
- Restore skip when snapshot is older than 30 days.

### Changed
- Restore throttle uses a WP option (`timu_sitekit_pin_last_check`), updated at most once per five minutes. Lower DB write frequency than the prior approach.

### Fixed
- Deactivation hook now correctly clears the scheduled snapshot cron.
- Uninstall hook deletes the on-disk snapshot file (which holds OAuth credential blobs) and removes both plugin options.

---

## [1.6147] — 2026-05-27

### Changed
- Unified versioning to the `X.Yjjj.hhmm` calendar-version scheme.
- Confirmed compatibility with WordPress 7.0.

---

## [1.0.1] — 2026-05-23

### Changed
- Standardized donation link to GitHub Sponsors.

---

## [1.0.0] — 2026-05-06

First public release.

### Added
- Configurable production URL via constant, option, or filter.
- Daily WP Cron snapshot of all `googlesitekit_*` options and owner user meta.
- Auto-restore on broken auth state detection (admin page loads, administrators only, throttled).
- WP-CLI commands: `wp sitekit-pin snapshot`, `wp sitekit-pin restore`, `wp sitekit-pin status`.
- Atomic snapshot write with `LOCK_EX`; file permissions set to `0640`.
- Deactivation clears the cron event; uninstall removes plugin options and snapshot file.
- GitHub Actions PR lint — `php -l` across PHP 7.4–8.3 matrix.
- `phpcs.xml` with WordPress Coding Standards and documented suppressions.
