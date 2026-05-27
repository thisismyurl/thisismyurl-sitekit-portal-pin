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

Key behavior:
- Runs only on production when a production URL is configured.
- Snapshots Site Kit options and owner user meta daily.
- Detects broken post-copy auth state during admin loads.
- Restores a known-good snapshot when mismatch symptoms are present.
- Includes WP-CLI commands for snapshot, restore, and status.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`.
2. Activate the plugin from the WordPress Plugins screen.
3. Configure production URL via constant, option, or filter:
   - `SITEKIT_PORTAL_PIN_PROD_URL` constant in `wp-config.php`, or
   - `sitekit_portal_pin_prod_url` option, or
   - `sitekit_portal_pin_prod_url` filter.

== Changelog ==

= 1.6147 =
* Unified plugin versioning to the x.Yddd calendar-version scheme.
* Confirmed compatibility with WordPress 7.0.


= 1.0.1 =
* Standardized the donation link to GitHub Sponsors.

= 1.0.0 =
- Initial release.
