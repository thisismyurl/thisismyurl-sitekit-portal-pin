=== Site Kit Portal Pin by Christopher Ross ===
Contributors: thisismyurl
Donate link: https://github.com/sponsors/thisismyurl
Tags: site-kit, google-site-kit, wp-engine, google-analytics, oauth
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6190.1000
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically restores Google Site Kit's connection on WP Engine production after a Portal copy overwrites your OAuth state.

== Description ==

If you run Google Site Kit on a WP Engine site, you have probably seen this: you copy your dev environment to production using WP Engine Portal, and Site Kit immediately shows as disconnected. Google Analytics and Search Console data vanish from the dashboard. You get errors like `missing_delegation_consent` or `needs_reauthentication`. You have to go through the full Google OAuth flow again.

This happens because WP Engine Portal copies the entire database from one environment to another. Dev has empty or stale Site Kit credentials. Once those land on prod, Site Kit's connection is gone.

**Site Kit Portal Pin fixes this automatically.**

It takes a daily snapshot of your production Site Kit auth state and stores it in an HMAC-sealed file *outside* wp-content/ where Portal copies cannot reach it. After a Portal copy lands dev's empty credentials on production, the plugin detects the broken state on the next admin page load and silently restores your known-good credentials. Your Site Kit connection survives the copy with no manual re-authentication required.

= How it works =

1. **Daily snapshot.** WP Cron captures every `googlesitekit_*` option and the Site Kit owner's user meta to a JSON file at `dirname( WP_CONTENT_DIR ) . '/.sitekit-prod-snapshot.json'`. That path sits one level above wp-content/ and is not touched by Portal copies.

2. **HMAC integrity.** The snapshot is sealed with an HMAC computed from your site's WordPress auth salt. A snapshot that fails its integrity check is rejected and never restored.

3. **Automatic restore.** On every admin page load — throttled to once per five minutes, administrators only — if broken Site Kit auth is detected (missing credentials, `missing_delegation_consent`, owner mismatch), the plugin restores the snapshot. It writes Site Kit options back to the options table and the owner's Site Kit user meta back for the owner user.

4. **30-day staleness guard.** If the snapshot is older than 30 days, restore is skipped. The daily cron keeps it current on an active site.

5. **WP-CLI commands.** `wp sitekit-pin snapshot`, `wp sitekit-pin restore`, and `wp sitekit-pin status` for manual control and diagnostics.

= What this plugin does not do =

* No admin settings page. Configuration is one line in wp-config.php.
* Does not intercept or modify Google OAuth flows.
* Does not touch any options or user meta outside the `googlesitekit_*` namespace.
* Runs only on production. Silent no-op everywhere else.
* Does not run on AJAX requests, WP Cron runs, or for non-administrator users.

= Configuration =

Tell the plugin your production site URL. Three options, in priority order:

**Option 1 (recommended) — constant in wp-config.php:**

    define( 'SITEKIT_PORTAL_PIN_PROD_URL', 'https://yoursite.com' );

**Option 2 — WP option:**

    update_option( 'sitekit_portal_pin_prod_url', 'https://yoursite.com' );

**Option 3 — filter:**

    add_filter( 'sitekit_portal_pin_prod_url', fn() => 'https://yoursite.com' );

Use the exact URL from Settings > General > Site Address (URL), without a trailing slash.

= This is a narrow tool =

Site Kit Portal Pin does exactly one thing: keep your WP Engine production Site Kit connection alive across Portal copies. If you are not on WP Engine or are not using Portal environment copies, this plugin does nothing useful for you.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from the WordPress Plugins screen.
3. Add your production URL to `wp-config.php`:

    define( 'SITEKIT_PORTAL_PIN_PROD_URL', 'https://yoursite.com' );

4. Confirm setup by running `wp sitekit-pin status` via WP-CLI. You should see `Production: YES` and `Auth healthy: YES`.
5. Run `wp sitekit-pin snapshot` once manually to create the first snapshot without waiting for the daily cron.

== Frequently Asked Questions ==

= Why does Google Site Kit disconnect after a WP Engine Portal copy? =

WP Engine Portal copies the database from one environment to another. This includes the `wp_options` rows where Site Kit stores its OAuth credentials. Dev has empty or stale credentials. When those overwrite prod's valid credentials, Site Kit loses its connection and shows errors like `missing_delegation_consent` or `needs_reauthentication`.

= What is the `missing_delegation_consent` error after a Portal copy? =

This error appears when Site Kit's OAuth token-binding metadata from dev doesn't match what Google issued to prod. After a Portal copy, dev's Site Kit options land on prod's database. The OAuth tokens were issued against prod's URL, but the binding context from dev is wrong, so Google rejects the connection. Restoring prod's known-good options fixes this without a new OAuth dance.

= Does Site Kit disconnect after every WP Engine deployment? =

Only Portal copy operations (environment copies) overwrite the database. Git-push deployments through WP Engine only touch files in wp-content/; they do not touch the database, so Site Kit's connection survives git deploys. This plugin is specifically for Portal copy operations that copy the database.

= How does the snapshot survive a Portal copy? =

The snapshot lives at `dirname( WP_CONTENT_DIR ) . '/.sitekit-prod-snapshot.json'` — one level above wp-content/ in the document root. WP Engine Portal copies replace wp-content/ and the database but do not touch files above wp-content/, so the snapshot is never overwritten.

= Is the snapshot file secure? =

The snapshot is sealed with an HMAC computed from your site's WordPress auth salt (`wp_salt('auth')`). The plugin verifies the HMAC before restoring any data; a snapshot that fails its integrity check is rejected with an admin notice and nothing is restored. File permissions are set to 0640 on write. On uninstall, the file is deleted.

= Does this work with git push deployments on WP Engine? =

Git push deployments don't touch the database, so Site Kit's connection is unaffected and no restoration is needed. This plugin is only useful for Portal copy operations that overwrite the database.

= Will this work on non-WP Engine hosts? =

The plugin will activate but is unlikely to be useful. The problem it solves is specific to WP Engine Portal's database copy behavior. On hosts where deployments don't overwrite the database, Site Kit's connection is persistent.

= How do I set the production URL? =

Add this to `wp-config.php`: `define( 'SITEKIT_PORTAL_PIN_PROD_URL', 'https://yoursite.com' );`

Use the exact URL shown in Settings > General > Site Address (URL), without a trailing slash.

= What happens if the snapshot is more than 30 days old? =

The plugin skips the restore. This is a safety measure: Site Kit's Google OAuth refresh tokens are long-lived but not indefinite, and a very old snapshot may carry expired tokens. The daily WP Cron event keeps the snapshot current as long as the site is active and Site Kit is connected.

= How do I verify the plugin is working? =

Run `wp sitekit-pin status` via WP-CLI. It reports: whether you are on production, whether Site Kit auth is healthy, whether a snapshot exists, the snapshot's age and option/meta counts, HMAC integrity status, and when the next cron snapshot is scheduled.

= Can I trigger a snapshot or restore manually? =

Yes. `wp sitekit-pin snapshot` takes a snapshot immediately (prod only, healthy auth only). `wp sitekit-pin restore` restores from the most recent snapshot. `wp sitekit-pin status` shows current state.

= What does the plugin write to the database? =

Two options: `sitekit_portal_pin_prod_url` (your configured production URL, if set via the option method) and `timu_sitekit_pin_last_check` (the throttle timestamp — updated at most once per five minutes during admin loads). Both are removed on uninstall.

== Changelog ==

= 1.6190 — 2026-07-09 =
* Fix: correct docblock on `PROD_URL_OPTION` constant (previously read "Throttle option key").
* Fix: correct indentation in WP-CLI `snapshot` command error handler.

= 1.6148 — 2026-05-28 =
* Security: gated the admin-load restore behind `manage_options` so only administrators can trigger a credential-state mutation.
* Hardening: snapshots now capture the full `googlesitekit_*` option set (plus a Site Kit version stamp) so a restore never writes a partial, internally inconsistent auth state after a Site Kit upgrade.
* Cleanup: deactivation clears the snapshot cron; uninstall removes the plugin options and deletes the on-disk snapshot file.
* Docs: documented the restore mutation behaviour and the HMAC-sealed snapshot.
* i18n: wrapped and escaped the snapshot-integrity admin notice.

= 1.6147 — 2026-05-27 =
* Unified plugin versioning to the X.Yjjj.hhmm calendar-version scheme (single-digit year, Julian day, 24h time in Toronto).
* Confirmed compatibility with WordPress 7.0.

= 1.0.1 =
* Standardized the donation link to GitHub Sponsors.

= 1.0.0 =
* Initial release. Configurable production URL, HMAC snapshot integrity, atomic file write, restore throttle via option, daily WP Cron snapshot, auto-restore on broken state detection, WP-CLI commands.
