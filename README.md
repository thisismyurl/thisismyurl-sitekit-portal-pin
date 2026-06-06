# Christopher Ross - Site Kit Portal Pin

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue)](https://wordpress.org/) [![License](https://img.shields.io/badge/License-GPL--2.0-blue)](LICENSE)

Pins production's Site Kit OAuth state across WP Engine Portal copies so dev → prod environment overwrites don't break the Google connection.

## The problem

WP Engine's Portal "copy environment" feature is the canonical way to push content/DB from dev → prod. It's atomic and clean, with one exception: it overwrites prod's `wp_options` and `wp_usermeta` with dev's values, which means prod's healthy Site Kit auth state gets clobbered by dev's empty/half-configured one. After a copy, prod throws `missing_delegation_consent` on the Site Kit dashboard and reporting silently breaks until somebody re-OAuths in a browser.

If you do Portal copies regularly (on a content-staging workflow, a release cadence, or just because dev is where editorial happens), this turns into a recurring chore.

## The mechanism

1. **Snapshot.** A daily wp-cron event on production captures all `googlesitekit_*` options and the owner user's `wp_googlesitekit_*` user_meta keys to a JSON file at `dirname(WP_CONTENT_DIR) . '/.sitekit-prod-snapshot.json'`. That path lives one level above `wp-content/`, which Portal copies don't touch.
2. **Detect.** On every admin page load (throttled to once per 5 min), the plugin checks for symptoms of a freshly-landed Portal copy: missing credentials, owner_id mismatch, or `error_code` set on the owner user.
3. **Restore.** If broken state is detected and a recent snapshot exists, the plugin re-stamps prod's known-good auth blobs back over the dev values that just landed. Google sees the same token it issued on prod's last successful auth, and the connection works again, with no browser re-auth.

### Why this works

Dev and prod share identical `wp-config` salts (they're sibling environments of the same WPE install), so Site Kit's encrypted credential blobs are portable between the two databases. The OAuth-token-binding error is purely about which blob is currently in the table, not a server-side credential rotation.

## Install

This plugin is GitHub-only by design (see [Why not WordPress.org](#why-not-wordpressorg) below). Pick the install method that matches your workflow:

**As a must-use plugin** (recommended; survives plugin-list resets and runs on every request without needing activation):

```bash
cd wp-content/mu-plugins
curl -L -O https://github.com/thisismyurl/thisismyurl-sitekit-portal-pin/releases/latest/download/sitekit-portal-pin.php
```

**As a regular plugin** via git submodule:

```bash
cd wp-content/plugins
git submodule add https://github.com/thisismyurl/thisismyurl-sitekit-portal-pin
```

Then activate from the Plugins screen.

**Manual download:** grab `sitekit-portal-pin.php` from the [latest release](https://github.com/thisismyurl/thisismyurl-sitekit-portal-pin/releases/latest) and drop it in `wp-content/mu-plugins/`.

## Configure

The production site URL is resolved in priority order — no editing the plugin file required:

1. **PHP constant** (recommended — set in `wp-config.php`):
   ```php
   define( 'SITEKIT_PORTAL_PIN_PROD_URL', 'https://example.com' );
   ```
2. **WP option** — set via WP-CLI or code:
   ```bash
   wp option update sitekit_portal_pin_prod_url https://example.com
   ```
3. **Filter** — for environment-convention detection:
   ```php
   add_filter( 'sitekit_portal_pin_prod_url', fn() => 'https://example.com' );
   ```

The plugin no-ops silently on any environment where `get_option('siteurl')` does not match the resolved production URL, so it's safe to install on dev and prod from the same codebase.

## Use

The plugin runs unattended once installed. The WP-CLI commands are for manual operation and debugging:

```bash
# Show snapshot age, auth health, MAC integrity status, next cron
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

## Releases

Versioned with the `x.Yddd` calendar scheme used across the Christopher Ross plugin family. Tagged releases are published on the [Releases page](https://github.com/thisismyurl/thisismyurl-sitekit-portal-pin/releases). See [CHANGELOG.md](CHANGELOG.md) for the full history.

This plugin has infrequent, intentional releases — not a rolling commit cadence. `main` is stable between releases, but always point at a tagged release in production.

## Why not WordPress.org

The audience for this plugin is narrow and technical — WP Engine customers who use Site Kit and copy environments. That audience lives in SSH, git, and `wp-cli`, not the Plugins → Add New search. A `.org` listing would mostly generate a long tail of support tickets from people who installed it without reading what it does. GitHub keeps the artifact discoverable for the people who'd actually use it, and keeps maintenance on a single channel.

---

## Support and donations

I build these tools because WordPress sites in the wild keep hitting the same problems, and a small, focused plugin is usually the right fix. They're free to use, with no tracking and no ads.

If one of them saves you time, here are the genuine ways to help:

- **Sponsor the work.** [GitHub Sponsors](https://github.com/sponsors/thisismyurl) is the simplest way, and the Sponsor button at the top of this repo lists it alongside Bitcoin, Dogecoin, PayPal, and Interac e-transfer. Any amount helps, and none of it is expected.
- **Contribute code or ideas.** A pull request, a bug report, or a tested edge case is worth as much as a donation. See [CONTRIBUTING.md](CONTRIBUTING.md) to get started.
- **Share it.** A note on [WordPress.org](https://profiles.wordpress.org/thisismyurl/), [GitHub](https://github.com/thisismyurl), or [LinkedIn](https://linkedin.com/in/thisismyurl) helps other people find work that might save them the same afternoon.

### Report issues and questions

- **Found a bug or want a feature?** Open an issue on the [Issues](../../issues) tab. Include your WordPress and PHP versions and the steps to reproduce it.
- **Have a question?** Start a thread on the [Discussions](../../discussions) tab.

### Contributing code

Code contributions are welcome. The short version:

1. Fork the repository and clone your fork.
2. Create a branch with a clear name, like `feature/short-descriptive-name`.
3. Make your change and test it against the edge cases.
4. Run the coding-standards check before you open the pull request.
5. Open a pull request that explains what changed and why.

The full workflow and standards live in [CONTRIBUTING.md](CONTRIBUTING.md). Contributing is never required, but it is always appreciated.

## About Christopher Ross

This plugin is built and maintained by [Christopher Ross](https://thisismyurl.com/), the WordPress development and technical SEO practice of Christopher Ross. I help teams build WordPress sites that stay secure, fast, and maintainable, and I write small, focused plugins like this one for the problems those sites keep running into.

### My background

- On the web since 1996, and in WordPress since 2007
- WordPress.org plugin developer with 19 plugins published since 2009
- Technical SEO practitioner focused on performance, security, and search visibility
- Lead instructor and curriculum architect at the M.L. Campbell Training Center, the Sherwin-Williams® international training facility for its industrial wood division

### Ways to connect

- **Website:** [thisismyurl.com](https://thisismyurl.com/)
- **WordPress.org:** [profiles.wordpress.org/thisismyurl](https://profiles.wordpress.org/thisismyurl/)
- **GitHub:** [github.com/thisismyurl](https://github.com/thisismyurl)
- **LinkedIn:** [linkedin.com/in/thisismyurl](https://linkedin.com/in/thisismyurl)

## Contributors

- **Christopher Ross** ([@thisismyurl](https://github.com/thisismyurl)) — author and maintainer
- Thanks to everyone who has reported issues, tested edge cases, and contributed code

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) or [gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).

---
*This project follows the [10 Core Pillars](PILLARS.md). Support quality work [here](https://github.com/sponsors/thisismyurl).*
