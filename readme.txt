=== Site Kit Portal Pin ===
Contributors: thisismyurl
Author URI: https://thisismyurl.com
Plugin URI: https://github.com/thisismyurl/sitekit-portal-pin
Tags: site kit, wp engine, oauth, google, deployment
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pins production's Site Kit OAuth state so WP Engine Portal copies dev to prod don't break the Google connection. Auto-restores after a copy.

== Description ==

WP Engine's Portal "copy environment" feature overwrites prod's `wp_options` and `wp_usermeta` with dev's values, which clobbers prod's healthy Google Site Kit auth state. After a copy, prod throws `missing_delegation_consent` and reporting silently breaks until somebody re-OAuths in a browser.

Site Kit Portal Pin snapshots prod's known-good auth state to a file one level above `wp-content/` (where Portal copies can't touch it), then auto-restores it whenever a copy lands a broken state on prod — no browser re-auth required.

* **Snapshot.** A daily wp-cron event on production captures all `googlesitekit_*` options and the owner user's `wp_googlesitekit_*` user_meta to an HMAC-signed JSON file outside `wp-content/`.
* **Detect.** On every admin page load (throttled to once per 5 minutes) the plugin checks for symptoms of a freshly-landed Portal copy: missing credentials, owner_id mismatch, or an error_code on the owner user.
* **Restore.** When broken state is detected and a recent snapshot exists, prod's known-good auth blobs are re-stamped over the dev values the copy landed.

The plugin no-ops silently on any environment whose `siteurl` does not match the resolved production URL, so the same file ships safely to dev and prod.

**Developer surface:** the plugin exposes a master gate filter, decision-point filters, lifecycle action hooks, and a read-only `wp sitekit-pin status` command. See the FAQ and `README.md`.

This plugin is distributed via GitHub, not WordPress.org — its audience is WP Engine customers who use Site Kit and copy environments, and who live in SSH, git, and wp-cli.

== Installation ==

1. Download `sitekit-portal-pin.php` from the latest GitHub release.
2. Drop it in `wp-content/mu-plugins/` (recommended) or `wp-content/plugins/` and activate.
3. Set the production URL in `wp-config.php`: `define( 'SITEKIT_PORTAL_PIN_PROD_URL', 'https://example.com' );` — or via `wp option update sitekit_portal_pin_prod_url https://example.com`, or the `sitekit_portal_pin_prod_url` filter.

The plugin runs unattended once installed and only acts on the production environment.

== Frequently Asked Questions ==

= Are there hooks and filters for developers? =

Yes. All hooks use the `sitekit_portal_pin_` prefix.

Filters: `sitekit_portal_pin_enabled` (master on/off gate), `sitekit_portal_pin_prod_url` (resolve the prod URL), `sitekit_portal_pin_is_production` (override environment detection), `sitekit_portal_pin_tracked_options` (which option keys are pinned), `sitekit_portal_pin_usermeta_prefix` (owner meta prefix), `sitekit_portal_pin_snapshot_path` (snapshot location), `sitekit_portal_pin_is_auth_healthy` (health heuristic), `sitekit_portal_pin_pre_pin` (short-circuit a pin), `sitekit_portal_pin_should_restore` (per-trigger gate on auto-restore), and `sitekit_portal_pin_max_restore_age` (auto-restore staleness cap).

Actions: `sitekit_portal_pin_before_pin` / `sitekit_portal_pin_after_pin`, `sitekit_portal_pin_state_pinned`, `sitekit_portal_pin_pin_failed`, `sitekit_portal_pin_before_restore` / `sitekit_portal_pin_after_restore`, `sitekit_portal_pin_state_restored`, and `sitekit_portal_pin_restore_failed`.

WP-CLI: `wp sitekit-pin status` (read-only), `wp sitekit-pin snapshot`, and `wp sitekit-pin restore` (both require `manage_options`). The full reference with argument signatures lives in `README.md`.

= How do I disable it temporarily without deactivating? =

Return `false` from the `sitekit_portal_pin_enabled` filter. That suspends the daily snapshot cron and the auto-restore on admin load. Manual recovery via `wp sitekit-pin restore` still works — the gate never blocks the rollback path.

= Does it run on my dev or staging site? =

No. It no-ops on any environment whose `siteurl` does not match the resolved production URL.

== Changelog ==

= 1.1.0 =
* Added: master gate filter `sitekit_portal_pin_enabled` (default true) — suspends automatic pin/restore without deactivating; the manual restore path is never blocked.
* Added: decision-point filters — `sitekit_portal_pin_is_production`, `sitekit_portal_pin_tracked_options`, `sitekit_portal_pin_usermeta_prefix`, `sitekit_portal_pin_snapshot_path`, `sitekit_portal_pin_is_auth_healthy`, `sitekit_portal_pin_pre_pin`, `sitekit_portal_pin_should_restore`, `sitekit_portal_pin_max_restore_age`.
* Added: lifecycle actions — `sitekit_portal_pin_before_pin`/`after_pin`, `sitekit_portal_pin_state_pinned`, `sitekit_portal_pin_pin_failed`, `sitekit_portal_pin_before_restore`/`after_restore`, `sitekit_portal_pin_state_restored`, `sitekit_portal_pin_restore_failed`.
* Added: `wp sitekit-pin status` now supports `--format=list|table|json|yaml` and reports gate state and tracked keys.
* Security: `wp sitekit-pin snapshot` and `wp sitekit-pin restore` now require the `manage_options` capability. `status` remains read-only.
* Documented: the existing `sitekit_portal_pin_prod_url` filter (shipped in 1.0.0).

= 1.0.0 =
* First public release: configurable production URL, HMAC snapshot integrity, atomic write with LOCK_EX, restore throttle, daily wp-cron snapshot, auto-restore on broken-state detection, and `wp sitekit-pin snapshot|restore|status`.

== Upgrade Notice ==

= 1.1.0 =
Adds a developer hook/filter surface and closes a capability-check gap on the mutating WP-CLI commands (`snapshot`, `restore` now require manage_options). Fully backward compatible — automatic behaviour is unchanged unless you opt in via a filter.
