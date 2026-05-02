# Site Kit Portal Pin

Pins production's Site Kit OAuth state across WP Engine Portal copies so dev → prod environment overwrites don't break the Google connection.

## The problem

WP Engine's Portal "copy environment" feature is the canonical way to push content/DB from dev → prod. It's atomic and clean — except for one thing: it overwrites prod's `wp_options` and `wp_usermeta` with dev's values, which means prod's healthy Site Kit auth state gets clobbered by dev's empty/half-configured one. After a copy, prod throws `missing_delegation_consent` on the Site Kit dashboard and reporting silently breaks until somebody re-OAuths in a browser.

If you do Portal copies regularly — on a content-staging workflow, a release cadence, or just because dev is where editorial happens — this turns into a known-painful weekly chore.

## The mechanism

1. **Snapshot.** A daily wp-cron event on production captures all `googlesitekit_*` options and the owner user's `wp_googlesitekit_*` user_meta keys to a JSON file at `dirname(WP_CONTENT_DIR) . '/.sitekit-prod-snapshot.json'`. That path lives one level above `wp-content/`, which Portal copies don't touch.
2. **Detect.** On every admin page load (throttled to once per 5 min), the plugin checks for symptoms of a freshly-landed Portal copy: missing credentials, owner_id mismatch, or `error_code` set on the owner user.
3. **Restore.** If broken state is detected and a recent snapshot exists, the plugin re-stamps prod's known-good auth blobs back over the dev values that just landed. Google sees the same token it issued on prod's last successful auth, and the connection works again — no browser re-auth needed.

### Why this works

Dev and prod share identical `wp-config` salts (they're sibling environments of the same WPE install), so Site Kit's encrypted credential blobs are portable between the two databases. The OAuth-token-binding error is purely about which blob is currently in the table — not a server-side credential rotation.

## Install

This plugin is GitHub-only by design (see [Why not WordPress.org](#why-not-wordpressorg) below). Pick the install method that matches your workflow:

**As a must-use plugin** (recommended — survives plugin-list resets and runs on every request without needing activation):

```bash
cd wp-content/mu-plugins
curl -O https://raw.githubusercontent.com/thisismyurl/sitekit-portal-pin/main/sitekit-portal-pin.php
```

**As a regular plugin** via git submodule:

```bash
cd wp-content/plugins
git submodule add https://github.com/thisismyurl/sitekit-portal-pin
```

Then activate from the Plugins screen.

**Manual download:** grab `sitekit-portal-pin.php` from the latest release and drop it in `wp-content/mu-plugins/`.

## Configure

Currently the production siteurl is hard-coded to `https://thisismyurl.com` (see [issue #1](https://github.com/thisismyurl/sitekit-portal-pin/issues/1)). Until that's resolved, edit the `PROD_SITEURL` constant at the top of the plugin file to match your production environment.

The plugin no-ops on any environment where `get_option('siteurl')` does not match this constant — so it's safe to leave installed on dev.

## Use

The plugin runs unattended once installed. The WP-CLI commands are for manual operation and debugging:

```bash
# Show snapshot age, auth health, next cron
wp sitekit-pin status

# Force a snapshot now (refuses if current auth is unhealthy)
wp sitekit-pin snapshot

# Force a restore from the latest snapshot
wp sitekit-pin restore
```

All three commands refuse to run on non-production environments.

## What it doesn't do

- **It doesn't refresh expired tokens.** If your snapshot is older than 30 days, the plugin skips restore on the assumption that token refresh might fail; you'll need to re-auth in the browser, after which the next daily cron captures a fresh snapshot.
- **It doesn't touch dev.** The plugin no-ops on any non-prod siteurl. You can ship the same file to both environments without conditional loading.
- **It doesn't replace Site Kit Pro features** or modify how Site Kit talks to Google. It only restores the auth state Portal copies clobber.

## Why not WordPress.org

The audience for this plugin is narrow and technical — WP Engine customers who use Site Kit and copy environments. That audience lives in SSH, git, and `wp-cli`, not the Plugins → Add New search. A `.org` listing would mostly buy us a long tail of support tickets from people who installed it without reading what it does. GitHub keeps the artifact discoverable for the people who'd actually use it, and keeps maintenance on a single channel.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Author

[Christopher Ross](https://thisismyurl.com) — built to scratch a personal itch on a real production site, shared in case it's useful.
