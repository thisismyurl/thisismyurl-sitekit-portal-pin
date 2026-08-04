# Changelog

All notable changes to Site Kit Portal Pin are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html) — `MAJOR.MINOR.PATCH`

---

## [1.1.0] — 2026-05-24

Developer surface — hooks, filters, and a hardened WP-CLI. Fully backward compatible; defaults reproduce 1.0.0 behaviour exactly.

### Added

- **Master gate filter `sitekit_portal_pin_enabled`** (default `true`). Suspends the daily snapshot cron and the auto-restore on admin load without deactivating the plugin. The `wp sitekit-pin restore` recovery path is intentionally never blocked by this gate.
- **Decision-point filters.** `sitekit_portal_pin_is_production` (environment-detection result), `sitekit_portal_pin_tracked_options` (which option keys are pinned), `sitekit_portal_pin_usermeta_prefix` (owner meta prefix), `sitekit_portal_pin_snapshot_path` (snapshot location), `sitekit_portal_pin_is_auth_healthy` (health heuristic), `sitekit_portal_pin_pre_pin` (short-circuit a pin), `sitekit_portal_pin_should_restore` (per-trigger gate on auto-restore), `sitekit_portal_pin_max_restore_age` (auto-restore staleness cap).
- **Lifecycle actions.** `sitekit_portal_pin_before_pin` / `sitekit_portal_pin_after_pin`, `sitekit_portal_pin_state_pinned` (success), `sitekit_portal_pin_pin_failed`; `sitekit_portal_pin_before_restore` / `sitekit_portal_pin_after_restore`, `sitekit_portal_pin_state_restored` (re-apply over a clobbered state), `sitekit_portal_pin_restore_failed`.
- **`wp sitekit-pin status --format=`** now supports `list` (default), `table`, `json`, and `yaml`, and reports the gate state and the tracked option keys alongside environment, integrity, and age.

### Security

- **Capability check on mutating CLI commands.** `wp sitekit-pin snapshot` and `wp sitekit-pin restore` now require the `manage_options` capability. Previously a CLI invocation with no user context could mutate auth state with no authorization check. `status` remains read-only and uncapped.

### Documented

- **Existing `sitekit_portal_pin_prod_url` filter** (shipped in 1.0.0) is now documented in the developer surface.

[1.1.0]: https://github.com/thisismyurl/thisismyurl-sitekit-portal-pin/releases/tag/v1.1.0

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
