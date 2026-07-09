# Site Kit Portal Pin

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/) [![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](LICENSE)

Automatically restores Google Site Kit's connection on WP Engine production after a Portal copy overwrites your OAuth state.

## The problem

You use Google Site Kit on a WP Engine site. You copy your dev environment to production using WP Engine Portal. Site Kit immediately shows as disconnected — `missing_delegation_consent`, `needs_reauthentication`, Google Analytics and Search Console gone from the dashboard. You have to go through the full Google OAuth flow again.

WP Engine Portal copies the database from dev to prod. Dev has empty or stale Site Kit credentials. When those land on production, the connection breaks.

## The fix

Site Kit Portal Pin takes a daily snapshot of your production Site Kit auth state and stores it in an HMAC-sealed file **outside** `wp-content/` where Portal copies cannot reach it. When it detects broken post-copy auth on the next admin page load, it silently restores your known-good credentials. No manual re-authentication.

## What it does

- **Snapshots** every `googlesitekit_*` option and the Site Kit owner's user meta daily via WP Cron
- **Stores** the snapshot at `dirname( WP_CONTENT_DIR ) . '/.sitekit-prod-snapshot.json'` — one level above `wp-content/`, untouched by Portal copies
- **Seals** the snapshot with an HMAC using your site's WordPress auth salt; rejects anything that fails integrity check
- **Detects** broken post-copy state on admin page loads (throttled to once per 5 minutes, administrators only)
- **Restores** Site Kit options and owner user meta from snapshot when broken state is found
- **Guards** against stale snapshots — skips restore if snapshot is older than 30 days

## What it doesn't do

- No admin settings page. One line in `wp-config.php` is all the configuration there is.
- Doesn't intercept or modify Google OAuth flows.
- Doesn't touch anything outside the `googlesitekit_*` namespace.
- Doesn't run on non-production environments, AJAX requests, WP Cron runs, or for non-administrators.
- Doesn't run on git push deployments (those don't touch the database — no fix needed).

## Requirements

- WP Engine hosting with Portal environment copying
- Google Site Kit installed and connected on production
- PHP 7.4+, WordPress 6.0+

## Installation

1. Upload to `/wp-content/plugins/thisismyurl-sitekit-portal-pin/` and activate.
2. Add your production URL to `wp-config.php`:

```php
define( 'SITEKIT_PORTAL_PIN_PROD_URL', 'https://yoursite.com' );
```

Use the exact URL from **Settings > General > Site Address (URL)**, without a trailing slash.

3. Confirm with WP-CLI: `wp sitekit-pin status` should show `Production: YES` and `Auth healthy: YES`.
4. Seed the first snapshot: `wp sitekit-pin snapshot` (don't wait for the daily cron on a fresh install).

## Configuration

Three options, checked in priority order:

| Method | Example |
|--------|---------|
| PHP constant in `wp-config.php` (recommended) | `define( 'SITEKIT_PORTAL_PIN_PROD_URL', 'https://yoursite.com' );` |
| WP option | `update_option( 'sitekit_portal_pin_prod_url', 'https://yoursite.com' );` |
| Filter | `add_filter( 'sitekit_portal_pin_prod_url', fn() => 'https://yoursite.com' );` |

Plugin silently no-ops if no production URL is configured.

## WP-CLI commands

```bash
wp sitekit-pin status     # Show production flag, auth health, snapshot age, HMAC status, next cron
wp sitekit-pin snapshot   # Take a snapshot now (prod + healthy auth required)
wp sitekit-pin restore    # Restore from snapshot now (prod required)
```

## How the snapshot survives a Portal copy

The snapshot file lives at `dirname( WP_CONTENT_DIR ) . '/.sitekit-prod-snapshot.json'`. On a typical WP Engine install, this resolves to the document root level — above `wp-content/`. WP Engine Portal copies replace `wp-content/` and the database, but don't touch files above `wp-content/`. So the snapshot persists across copies.

## Security notes

- Snapshot is sealed with `hash_hmac( 'sha256', $body, wp_salt('auth') )`. A snapshot that fails HMAC verification is rejected with an admin notice and never restored.
- File permissions are set to `0640` on write.
- Restore path is gated to `manage_options` — only administrators can trigger a credential mutation on an admin page load.
- On uninstall, the snapshot file is deleted along with the plugin's two DB options.

## Versioning

Versions follow `X.Yjjj.hhmm` — major version, last digit of year, Julian day, 24-hour time (Toronto). For example: `1.6190.1000` = major 1, year 2026, day 190, 10:00.

## About

Built by [Christopher Ross](https://thisismyurl.com/) to solve a real problem on a real site. This is a narrow, focused tool. It does one thing and only one thing.

**GitHub:** [github.com/thisismyurl](https://github.com/thisismyurl) · **WordPress.org:** [profiles.wordpress.org/thisismyurl](https://profiles.wordpress.org/thisismyurl/)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
