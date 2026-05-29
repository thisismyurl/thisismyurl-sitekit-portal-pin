=== This Is My URL - Site Kit Portal Pin ===
Contributors: thisismyurl
Donate link: https://github.com/sponsors/thisismyurl
Tags: site-kit, google-site-kit, wp-engine, oauth
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6147
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pins production Site Kit OAuth state so WP Engine Portal copies from dev to prod do not break Google Site Kit connection.

== Description ==

Site Kit Portal Pin snapshots known-good production Site Kit state and restores it after WP Engine Portal copy events that overwrite production auth state.

This plugin was built for Christopher Ross's own WP Engine Portal workflow (dev to prod copies on thisismyurl.com). It is shared as-is; review it before running it on any other site.

Key behavior:
- Runs only on production when a production URL is configured.
- Snapshots every `googlesitekit_*` option and the owner's Site Kit user meta daily.
- Detects broken post-copy auth state during admin loads.
- Restores a known-good snapshot when mismatch symptoms are present.
- Includes WP-CLI commands for snapshot, restore, and status.

What it changes, and when:
- On an admin page load (administrators only), if it detects broken Site Kit auth and a valid snapshot exists, it **writes the snapshot's Site Kit options back to the options table and writes the owner's Site Kit `user_meta` back for the owner user**. This is a real mutation of credential state, not a read-only check.
- The snapshot lives on disk one level above `wp-content/` and is **HMAC-sealed** with the site's `auth` salt. A snapshot that fails its integrity check is rejected and never restored.
- The snapshot file holds live OAuth credential blobs. Uninstalling the plugin deletes that file along with the plugin's options; deactivating clears the daily cron.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`.
2. Activate the plugin from the WordPress Plugins screen.
3. Configure production URL via constant, option, or filter:
   - `SITEKIT_PORTAL_PIN_PROD_URL` constant in `wp-config.php`, or
   - `sitekit_portal_pin_prod_url` option, or
   - `sitekit_portal_pin_prod_url` filter.

== Changelog ==

= 1.6148 =
* Security: gated the admin-load restore behind `manage_options` so only administrators can trigger a credential-state mutation.
* Hardening: snapshots now capture the full `googlesitekit_*` option set (plus a Site Kit version stamp) so a restore can no longer write a partial, internally-inconsistent auth state after a Site Kit upgrade.
* Cleanup: deactivation clears the snapshot cron; uninstall removes the plugin options and deletes the on-disk snapshot file (which holds OAuth credential blobs).
* Docs: documented the restore mutation behaviour and the HMAC-sealed snapshot in the readme.
* i18n/escaping: wrapped and escaped the snapshot-integrity admin notice.

= 1.6147 =
* Unified plugin versioning to the x.Yddd calendar-version scheme.
* Confirmed compatibility with WordPress 7.0.


= 1.0.1 =
* Standardized the donation link to GitHub Sponsors.

= 1.0.0 =
- Initial release.
