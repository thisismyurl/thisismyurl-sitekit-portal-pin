# Contributing

This is a small, single-purpose plugin maintained as a side project. Contributions are welcome within that scope.

## Bug reports

File an issue using the bug-report template. Include:

- WordPress version, PHP version, and (if relevant) WP Engine plan tier.
- Plugin version (`Version:` from the file header) or commit hash.
- Steps to reproduce, expected behaviour, actual behaviour.
- Any relevant log output (with credentials redacted).

If you think the issue has a security implication, do **not** file it publicly. See [SECURITY.md](SECURITY.md).

## Pull requests

Pull requests are welcome but please **open an issue first** to discuss scope before writing code. The plugin's surface is intentionally small, and PRs that expand the surface (new features, new configurability, new dependencies) need an alignment conversation up front.

PRs that are likely to merge quickly:

- Bug fixes with a reproducer in the issue.
- Documentation improvements.
- Compatibility patches for new WordPress or PHP versions.
- Work that closes an existing open issue, where the issue has clear acceptance criteria.

## Code style

- WordPress Coding Standards (PHPCS `WordPress` ruleset).
- All `.php` files must pass `php -l`.
- Tabs for indentation in PHP, two spaces in YAML / JSON / Markdown (see `.editorconfig`).
- No new external dependencies without discussion. The plugin is intentionally a single file with no Composer requirements.

Run before submitting:

```bash
php -l sitekit-portal-pin.php
phpcs --standard=WordPress sitekit-portal-pin.php
```

## Testing

There is no automated test suite yet. Manual verification expectations:

- Run on a real WP Engine environment if your change touches Portal-copy behaviour, snapshot path resolution, or the `is_production()` gate.
- Run `wp sitekit-pin status` before and after to verify state.
- Confirm the plugin still no-ops on a non-prod siteurl.

## Releases

Maintainer-managed. SemVer (`MAJOR.MINOR.PATCH`). See [CHANGELOG.md](CHANGELOG.md) once it exists (tracked in [issue #9](https://github.com/thisismyurl/thisismyurl-sitekit-portal-pin/issues/9)).

## Maintainer response

Best-effort, single-maintainer. Most response within a week or two; complex PRs may sit longer if the discussion needs deeper review. A polite nudge after two weeks of silence is welcome.
