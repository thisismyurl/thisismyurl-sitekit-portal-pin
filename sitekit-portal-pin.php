<?php
/**
 * Plugin Name: Site Kit Portal Pin
 * Plugin URI: https://github.com/thisismyurl/sitekit-portal-pin
 * Description: Pins production's Site Kit OAuth state so WP Engine Portal copies dev → prod don't break the connection. Snapshots prod's healthy auth to a file outside wp-content/ (untouched by Portal copies); auto-restores after a copy lands dev's empty/wrong state on prod. Ships a developer surface: hooks, filters, and a read-only WP-CLI status command.
 * Author: Christopher Ross
 * Author URI: https://thisismyurl.com
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sitekit-portal-pin
 *
 * Copyright (C) 2026 Christopher Ross (https://thisismyurl.com)
 *
 * Mechanism:
 *   - Active only on production (resolved via SITEKIT_PORTAL_PIN_PROD_URL constant, option, or filter).
 *   - Daily wp-cron snapshots all `googlesitekit_*` options + owner user_meta keys
 *     to a JSON file at dirname(WP_CONTENT_DIR) . '/.sitekit-prod-snapshot.json'.
 *     That path lives one level above wp-content/, which Portal copies do not touch.
 *   - On every admin page load, checks for symptoms of a freshly-landed Portal copy
 *     (missing credentials, owner_id mismatch, error_code set). If detected and a
 *     snapshot exists, restores from snapshot.
 *   - WP-CLI: `wp eval-file <this file>` won't run; use the seed/restore commands
 *     registered below instead — `wp sitekit-pin snapshot|restore|status`.
 *
 * Why this works: dev and prod share identical wp-config salts, so Site Kit's
 * encrypted credential blobs are portable between installs. The "missing_delegation_consent"
 * error after a Portal copy is the OAuth-token-binding mismatch — but if we re-stamp
 * prod's known-good blobs back over dev's blobs, Google sees the same token it issued
 * on prod's last successful auth, and the connection works.
 *
 * @package SiteKitPortalPin
 */

defined( 'ABSPATH' ) || exit;

class TIMU_SiteKit_Prod_Pin {

	/**
	 * Cron hook for daily snapshot refresh.
	 */
	const CRON_HOOK = 'timu_sitekit_pin_snapshot';

	/**
	 * Throttle key for restore-on-admin-init checks.
	 */
	const RESTORE_THROTTLE_KEY = 'timu_sitekit_pin_last_check';

	/**
	 * Throttle window — only run the heavy restore check once per N seconds.
	 */
	const RESTORE_THROTTLE_SECONDS = 300;

	/**
	 * Default soft cap on snapshot age before auto-restore declines to act.
	 */
	const DEFAULT_MAX_RESTORE_AGE = 30 * DAY_IN_SECONDS;

	/**
	 * Site Kit options to snapshot/restore.
	 */
	const TRACKED_OPTIONS = array(
		'googlesitekit_credentials',
		'googlesitekit_search-console_settings',
		'googlesitekit_analytics-4_settings',
		'googlesitekit_active_modules',
		'googlesitekit_connected_proxy_url',
		'googlesitekit_has_connected_admins',
		'googlesitekit_owner_id',
		'googlesitekit_db_version',
		'googlesitekit_disconnected_modules',
		'googlesitekit_conversion_tracking',
	);

	/**
	 * User-meta key prefix for Site Kit auth state. We snapshot all keys
	 * matching this prefix for the owner user.
	 */
	const USERMETA_PREFIX = 'wp_googlesitekit';

	/**
	 * Bootstrap.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_restore' ), 1 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'snapshot_if_healthy' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'schedule_cron' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'sitekit-pin', 'TIMU_SiteKit_Pin_CLI' );
		}
	}

	/**
	 * Master gate for the plugin's automatic pin/restore behaviour.
	 *
	 * Returning false from the `sitekit_portal_pin_enabled` filter disables the
	 * daily snapshot cron and the auto-restore on admin page load, without
	 * deactivating the plugin. Recovery is never blocked: `wp sitekit-pin restore`
	 * calls restore_from_snapshot() directly and runs regardless of this gate, so
	 * an operator can always re-apply a pinned state by hand.
	 *
	 * @return bool Whether automatic pin/restore behaviour is enabled. Default true.
	 */
	public static function is_enabled() {
		/**
		 * Filter the master on/off gate for automatic pin and restore behaviour.
		 *
		 * @since 1.1.0
		 *
		 * @param bool $enabled Whether automatic behaviour is enabled. Default true.
		 */
		return (bool) apply_filters( 'sitekit_portal_pin_enabled', true );
	}

	/**
	 * Resolve the Site Kit options snapshotted and restored.
	 *
	 * Single resolver for every caller (snapshot builder and any future reader)
	 * so the option set can be extended programmatically without editing the
	 * constant. Returns the canonical TRACKED_OPTIONS unless a filter overrides.
	 *
	 * @return string[] Option keys to track.
	 */
	public static function get_tracked_options() {
		/**
		 * Filter the Site Kit option keys captured in a snapshot and re-applied on restore.
		 *
		 * @since 1.1.0
		 *
		 * @param string[] $keys Option keys. Default self::TRACKED_OPTIONS.
		 */
		$keys = apply_filters( 'sitekit_portal_pin_tracked_options', self::TRACKED_OPTIONS );

		return array_values( array_filter( array_map( 'strval', (array) $keys ) ) );
	}

	/**
	 * Resolve the owner user_meta key prefix captured in a snapshot.
	 *
	 * @return string Meta-key prefix.
	 */
	public static function get_usermeta_prefix() {
		/**
		 * Filter the owner user_meta key prefix captured in a snapshot.
		 *
		 * @since 1.1.0
		 *
		 * @param string $prefix Meta-key prefix. Default self::USERMETA_PREFIX.
		 */
		$prefix = (string) apply_filters( 'sitekit_portal_pin_usermeta_prefix', self::USERMETA_PREFIX );

		return '' === $prefix ? self::USERMETA_PREFIX : $prefix;
	}

	/**
	 * Are we running on production?
	 */
	public static function is_production() {
		$prod_url = self::get_prod_siteurl();
		if ( '' === $prod_url ) {
			$result = false; // No production URL configured — no-op silently.
		} else {
			$result = rtrim( (string) get_option( 'siteurl' ), '/' ) === $prod_url;
		}

		/**
		 * Filter the environment-detection result.
		 *
		 * Lets a site override how "is this production?" is decided — for example
		 * to pin against a WP-Engine environment name instead of a URL match.
		 * Returning true on a non-prod environment will arm the automatic
		 * pin/restore behaviour, so override deliberately.
		 *
		 * @since 1.1.0
		 *
		 * @param bool   $result   Whether the current install is production.
		 * @param string $prod_url Resolved production URL, or '' if unconfigured.
		 */
		return (bool) apply_filters( 'sitekit_portal_pin_is_production', $result, $prod_url );
	}

	/**
	 * Resolve the production site URL via, in priority order:
	 *   1. SITEKIT_PORTAL_PIN_PROD_URL constant (define in wp-config.php)
	 *   2. sitekit_portal_pin_prod_url WP option (settable from admin)
	 *   3. sitekit_portal_pin_prod_url filter (opt-in for env-name detection)
	 *
	 * Returns empty string if not configured anywhere, which causes is_production()
	 * to return false and the plugin to no-op silently.
	 */
	public static function get_prod_siteurl(): string {
		if ( defined( 'SITEKIT_PORTAL_PIN_PROD_URL' ) && '' !== (string) SITEKIT_PORTAL_PIN_PROD_URL ) {
			return rtrim( (string) SITEKIT_PORTAL_PIN_PROD_URL, '/' );
		}

		$db_value = (string) get_option( 'sitekit_portal_pin_prod_url', '' );
		if ( '' !== $db_value ) {
			return rtrim( $db_value, '/' );
		}

		/**
		 * Filter the resolved production site URL.
		 *
		 * Lets site owners auto-derive the prod URL from environment conventions
		 * (e.g. a WP-Engine environment name) instead of a constant or option.
		 * Existing since 1.0.0; documented here as part of the developer surface.
		 *
		 * @since 1.0.0
		 *
		 * @param string $prod_url Production URL. Default '' (unconfigured).
		 */
		$filtered = (string) apply_filters( 'sitekit_portal_pin_prod_url', '' );
		return rtrim( $filtered, '/' );
	}

	/**
	 * Path to the snapshot file. Lives outside wp-content/ so Portal copies don't touch it.
	 */
	public static function snapshot_path() {
		$path = dirname( WP_CONTENT_DIR ) . '/.sitekit-prod-snapshot.json';

		/**
		 * Filter the absolute path the snapshot file is written to and read from.
		 *
		 * The default lives one level above wp-content/ so WP Engine Portal copies
		 * leave it untouched. Override only with a path that survives Portal copies,
		 * or the auto-restore safety net stops working.
		 *
		 * @since 1.1.0
		 *
		 * @param string $path Absolute snapshot file path.
		 */
		return (string) apply_filters( 'sitekit_portal_pin_snapshot_path', $path );
	}

	/**
	 * Schedule the daily snapshot cron event if not already scheduled.
	 */
	public static function schedule_cron() {
		if ( ! self::is_production() ) {
			return;
		}
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Build a snapshot from current DB state. Returns array; does not touch disk.
	 */
	public static function build_snapshot() {
		global $wpdb;

		$owner_id = (int) get_option( 'googlesitekit_owner_id', 0 );
		if ( ! $owner_id ) {
			return null;
		}

		$options = array();
		foreach ( self::get_tracked_options() as $key ) {
			$val = get_option( $key, null );
			if ( null !== $val ) {
				$options[ $key ] = $val;
			}
		}

		$usermeta_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$owner_id,
				$wpdb->esc_like( self::get_usermeta_prefix() ) . '%'
			)
		);

		$usermeta = array();
		foreach ( (array) $usermeta_keys as $mk ) {
			$mv = get_user_meta( $owner_id, $mk, true );
			if ( '' !== $mv && null !== $mv ) {
				$usermeta[ $mk ] = $mv;
			}
		}

		return array(
			'version'        => 1,
			'taken_at'       => time(),
			'taken_at_human' => gmdate( 'c' ),
			'siteurl'        => get_option( 'siteurl' ),
			'owner_id'       => $owner_id,
			'options'        => $options,
			'usermeta'       => $usermeta,
		);
	}

	/**
	 * Heuristic — is the current Site Kit auth state healthy?
	 * If yes, we'll snapshot. If no, we'll restore from snapshot.
	 */
	public static function is_auth_healthy() {
		$creds   = get_option( 'googlesitekit_credentials' );
		$owner   = (int) get_option( 'googlesitekit_owner_id', 0 );
		$err     = get_user_meta( $owner, 'wp_googlesitekit_error_code', true );
		$proxy   = (string) get_option( 'googlesitekit_connected_proxy_url' );
		$siteurl = (string) get_option( 'siteurl' );

		$healthy = true;

		if ( empty( $creds ) || ! $owner ) {
			$healthy = false;
		} elseif ( ! empty( $err ) ) {
			$healthy = false;
		} elseif ( $proxy && rtrim( $proxy, '/' ) !== rtrim( $siteurl, '/' ) ) {
			$healthy = false;
		} elseif ( empty( get_user_meta( $owner, 'wp_googlesitekit_access_token', true ) ) ) {
			$healthy = false;
		}

		/**
		 * Filter the auth-health heuristic result.
		 *
		 * The default heuristic flags missing credentials, a missing owner, an
		 * error_code on the owner, a proxy/siteurl mismatch, or a missing access
		 * token. Override to tighten or loosen what counts as "healthy" — a false
		 * result on prod arms auto-restore, a true result arms the daily snapshot.
		 *
		 * @since 1.1.0
		 *
		 * @param bool $healthy Whether Site Kit auth is considered healthy.
		 * @param int  $owner   Resolved Site Kit owner user ID (0 if unset).
		 */
		return (bool) apply_filters( 'sitekit_portal_pin_is_auth_healthy', $healthy, $owner );
	}

	/**
	 * Daily cron callback: pin current state to disk if it is healthy.
	 *
	 * Thin trigger over pin_state(). Respects the master gate because this is
	 * automatic behaviour; the CLI `snapshot` command routes through the same
	 * orchestrator but is exempt from the gate (an operator opted in by running it).
	 */
	public static function snapshot_if_healthy() {
		if ( ! self::is_production() ) {
			return;
		}
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( ! self::is_auth_healthy() ) {
			return;
		}

		self::pin_state();
	}

	/**
	 * Capture the current healthy auth state and write it to disk.
	 *
	 * Single orchestrator for both the daily cron and the CLI `snapshot` command,
	 * so the before/after/state_pinned/pin_failed lifecycle fires from one place
	 * regardless of caller. The disk write itself stays in write_snapshot().
	 *
	 * @return array {
	 *     Pin result.
	 *
	 *     @type bool        $pinned   Whether the snapshot was written.
	 *     @type string      $reason   Failure reason when $pinned is false.
	 *     @type string      $path     Snapshot path.
	 *     @type int         $options  Option count captured.
	 *     @type int         $usermeta Owner user_meta key count captured.
	 *     @type array|null  $snapshot The snapshot array, or null on build failure.
	 * }
	 */
	public static function pin_state() {
		$path = self::snapshot_path();

		/**
		 * Short-circuit a pin before any work begins.
		 *
		 * Return a non-null value to bypass the snapshot entirely; the value is
		 * returned to the caller as the pin result. Use to suspend pinning under
		 * a maintenance window or external lock without touching the master gate.
		 *
		 * @since 1.1.0
		 *
		 * @param null|array $pre  Short-circuit result. Default null.
		 * @param string     $path Snapshot path the pin would write to.
		 */
		$pre = apply_filters( 'sitekit_portal_pin_pre_pin', null, $path );
		if ( null !== $pre ) {
			return (array) $pre;
		}

		/**
		 * Fires immediately before the current auth state is pinned to disk.
		 *
		 * @since 1.1.0
		 *
		 * @param string $path Snapshot path the pin will write to.
		 */
		do_action( 'sitekit_portal_pin_before_pin', $path );

		$snapshot = self::build_snapshot();

		if ( null === $snapshot ) {
			$result = array(
				'pinned'   => false,
				'reason'   => 'no_owner',
				'path'     => $path,
				'options'  => 0,
				'usermeta' => 0,
				'snapshot' => null,
			);
		} elseif ( ! self::write_snapshot( $snapshot ) ) {
			$result = array(
				'pinned'   => false,
				'reason'   => 'write_failed',
				'path'     => $path,
				'options'  => count( $snapshot['options'] ),
				'usermeta' => count( $snapshot['usermeta'] ),
				'snapshot' => $snapshot,
			);
		} else {
			$result = array(
				'pinned'   => true,
				'reason'   => '',
				'path'     => $path,
				'options'  => count( $snapshot['options'] ),
				'usermeta' => count( $snapshot['usermeta'] ),
				'snapshot' => $snapshot,
			);
		}

		if ( $result['pinned'] ) {
			/**
			 * Fires after the current auth state is successfully pinned to disk.
			 *
			 * @since 1.1.0
			 *
			 * @param string $path     Snapshot path written.
			 * @param array  $snapshot Snapshot array written: version, taken_at,
			 *                          taken_at_human, siteurl, owner_id, options, usermeta.
			 */
			do_action( 'sitekit_portal_pin_state_pinned', $path, $snapshot );
		} else {
			/**
			 * Fires when a pin attempt fails.
			 *
			 * @since 1.1.0
			 *
			 * @param string $reason Failure reason: 'no_owner' or 'write_failed'.
			 * @param string $path   Snapshot path the pin targeted.
			 */
			do_action( 'sitekit_portal_pin_pin_failed', $result['reason'], $path );
		}

		/**
		 * Fires after a pin attempt, on success or failure.
		 *
		 * @since 1.1.0
		 *
		 * @param array $result Pin result: pinned, reason, path, options, usermeta, snapshot.
		 */
		do_action( 'sitekit_portal_pin_after_pin', $result );

		return $result;
	}

	/**
	 * Write a snapshot array to disk, wrapped in an HMAC envelope for integrity. Returns bool.
	 */
	public static function write_snapshot( $snap ) {
		$path = self::snapshot_path();
		$body = wp_json_encode( $snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return false;
		}
		$mac     = hash_hmac( 'sha256', $body, wp_salt( 'auth' ) );
		$payload = wp_json_encode(
			array(
				'mac'  => $mac,
				'body' => $body,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		if ( false === $payload ) {
			return false;
		}
		$ok = (bool) file_put_contents( $path, $payload, LOCK_EX );
		if ( $ok ) {
			chmod( $path, 0640 );
		}
		return $ok;
	}

	/**
	 * Read snapshot from disk. Verifies HMAC before returning data.
	 * Returns array (snapshot) or null on failure/tampering/missing MAC.
	 */
	public static function read_snapshot() {
		$path = self::snapshot_path();
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			return null;
		}
		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return null;
		}

		// Pre-MAC snapshots (plain JSON with 'options' key at root) are silently rejected.
		// The next healthy cron run will write a fresh MAC'd snapshot.
		if ( ! isset( $payload['mac'], $payload['body'] ) ) {
			return null;
		}

		$expected = hash_hmac( 'sha256', (string) $payload['body'], wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, (string) $payload['mac'] ) ) {
			// Integrity check failed. Log a single notice and fail closed.
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p><strong>Site Kit Portal Pin:</strong> snapshot integrity check failed (HMAC mismatch). The snapshot file may have been tampered with or corrupted. A fresh snapshot will be taken on the next healthy auth check.</p></div>';
				}
			);
			return null;
		}

		$decoded = json_decode( (string) $payload['body'], true );
		if ( ! is_array( $decoded ) || empty( $decoded['options'] ) ) {
			return null;
		}
		return $decoded;
	}

	/**
	 * Re-apply pinned Site Kit auth state from a snapshot.
	 *
	 * Recovery path: brackets the re-apply with before/after/state_restored/
	 * restore_failed actions but is NEVER blocked by the master gate. Disabling
	 * the plugin's automatic behaviour must never disable the ability to roll a
	 * good state back over a clobbered one. The work itself lives in
	 * do_restore_from_snapshot(); this method owns the lifecycle hooks so they
	 * fire from one place whether the snapshot is missing or applied.
	 *
	 * @param array|null $snap Snapshot array, or null to read from disk.
	 *
	 * @return array Restore stats: restored, reason|options_set+meta_set+taken_at.
	 */
	public static function restore_from_snapshot( $snap = null ) {
		if ( null === $snap ) {
			$snap = self::read_snapshot();
		}

		/**
		 * Fires immediately before pinned state is re-applied from a snapshot.
		 *
		 * @since 1.1.0
		 *
		 * @param array|null $snap Snapshot array to restore, or null if none was found.
		 */
		do_action( 'sitekit_portal_pin_before_restore', $snap );

		$result = self::do_restore_from_snapshot( $snap );

		if ( ! empty( $result['restored'] ) ) {
			/**
			 * Fires after pinned state is successfully re-applied over a clobbered state.
			 *
			 * This is the recovery event a WP Engine Portal copy triggers: prod's
			 * known-good auth blobs have just been re-stamped over the dev values
			 * the copy landed.
			 *
			 * @since 1.1.0
			 *
			 * @param array $result Restore stats: options_set, meta_set, taken_at.
			 * @param array $snap   Snapshot array that was applied.
			 */
			do_action( 'sitekit_portal_pin_state_restored', $result, (array) $snap );
		} else {
			/**
			 * Fires when a restore attempt does not re-apply any state.
			 *
			 * @since 1.1.0
			 *
			 * @param string $reason Failure reason (e.g. 'no_snapshot').
			 */
			do_action( 'sitekit_portal_pin_restore_failed', isset( $result['reason'] ) ? $result['reason'] : 'unknown' );
		}

		/**
		 * Fires after a restore attempt, on success or failure.
		 *
		 * @since 1.1.0
		 *
		 * @param array $result Restore stats.
		 */
		do_action( 'sitekit_portal_pin_after_restore', $result );

		return $result;
	}

	/**
	 * Apply a snapshot's options and owner user_meta to the database.
	 *
	 * @param array|null $snap Snapshot array, or null.
	 *
	 * @return array Restore stats.
	 */
	private static function do_restore_from_snapshot( $snap ) {
		if ( ! $snap || empty( $snap['options'] ) ) {
			return array(
				'restored' => false,
				'reason'   => 'no_snapshot',
			);
		}

		$owner_id = isset( $snap['owner_id'] ) ? (int) $snap['owner_id'] : 0;
		$opts_set = 0;
		$meta_set = 0;

		foreach ( (array) $snap['options'] as $k => $v ) {
			update_option( $k, $v, false );
			++$opts_set;
		}

		if ( $owner_id && ! empty( $snap['usermeta'] ) ) {
			foreach ( (array) $snap['usermeta'] as $mk => $mv ) {
				update_user_meta( $owner_id, $mk, $mv );
				++$meta_set;
			}
		}

		// Clear the error_code that signals broken state.
		if ( $owner_id ) {
			delete_user_meta( $owner_id, 'wp_googlesitekit_error_code' );
		}

		return array(
			'restored'    => true,
			'options_set' => $opts_set,
			'meta_set'    => $meta_set,
			'taken_at'    => isset( $snap['taken_at_human'] ) ? $snap['taken_at_human'] : null,
		);
	}

	/**
	 * Throttled admin_init hook. If broken state detected, restore.
	 */
	public static function maybe_restore() {
		if ( ! self::is_production() ) {
			return;
		}
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		// Throttle to avoid checking on every single admin page load.
		$last = (int) get_option( self::RESTORE_THROTTLE_KEY, 0 );
		if ( $last && ( time() - $last ) < self::RESTORE_THROTTLE_SECONDS ) {
			return;
		}
		update_option( self::RESTORE_THROTTLE_KEY, time(), false );

		if ( self::is_auth_healthy() ) {
			return;
		}

		$snap = self::read_snapshot();
		if ( ! $snap ) {
			return;
		}

		// Don't restore if the snapshot is stale enough that token refresh might fail.
		// Site Kit's refresh tokens are long-lived but we keep a soft 30-day cap.
		$taken = isset( $snap['taken_at'] ) ? (int) $snap['taken_at'] : 0;

		/**
		 * Filter the soft cap, in seconds, on snapshot age for auto-restore.
		 *
		 * Auto-restore declines to act on a snapshot older than this, on the
		 * assumption that a stale refresh token may have lapsed. Does not affect
		 * a manual `wp sitekit-pin restore`, which always re-applies the snapshot.
		 *
		 * @since 1.1.0
		 *
		 * @param int $max_age Maximum snapshot age in seconds. Default 30 days.
		 */
		$max_age = (int) apply_filters( 'sitekit_portal_pin_max_restore_age', self::DEFAULT_MAX_RESTORE_AGE );
		if ( $taken && $max_age > 0 && ( time() - $taken ) > $max_age ) {
			return;
		}

		/**
		 * Per-trigger gate on the automatic admin-init restore.
		 *
		 * Return false to skip this particular auto-restore — distinct from the
		 * master gate and from the restore operation itself, which a manual CLI
		 * run always reaches. Fires only after broken state and a fresh-enough
		 * snapshot have both been confirmed.
		 *
		 * @since 1.1.0
		 *
		 * @param bool  $should Whether to auto-restore now. Default true.
		 * @param array $snap   Snapshot array that would be applied.
		 */
		if ( ! apply_filters( 'sitekit_portal_pin_should_restore', true, $snap ) ) {
			return;
		}

		self::restore_from_snapshot( $snap );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * WP-CLI: wp sitekit-pin <subcommand>
	 */
	class TIMU_SiteKit_Pin_CLI {

		/**
		 * Capability required to run mutating subcommands.
		 */
		const REQUIRED_CAP = 'manage_options';

		/**
		 * Guard mutating subcommands behind a capability check.
		 *
		 * WP-CLI runs as the system user with no inherent WordPress capability
		 * context, so a snapshot/restore invoked without `--user` would otherwise
		 * mutate auth state with no authorization check at all. Read-only `status`
		 * is exempt. Errors out (does not return) when the check fails.
		 */
		private function require_cap() {
			if ( ! current_user_can( self::REQUIRED_CAP ) ) {
				WP_CLI::error(
					'You need the ' . self::REQUIRED_CAP . ' capability to run this command. Re-run as an administrator (wp --user=<admin> ...).'
				);
			}
		}

		/**
		 * Manually take a snapshot of the current Site Kit auth state.
		 *
		 * Runs regardless of the sitekit_portal_pin_enabled gate — invoking this
		 * command is itself the opt-in.
		 *
		 * ## EXAMPLES
		 *
		 *     wp sitekit-pin snapshot
		 *     wp --user=admin sitekit-pin snapshot
		 *
		 * @when after_wp_load
		 *
		 * @param array $args       Positional arguments (unused).
		 * @param array $assoc_args Associative arguments (unused).
		 *
		 * @return void
		 */
		public function snapshot( $args, $assoc_args ) {
			$this->require_cap();

			if ( ! TIMU_SiteKit_Prod_Pin::is_production() ) {
				WP_CLI::error( 'Not on production (siteurl != ' . TIMU_SiteKit_Prod_Pin::get_prod_siteurl() . '). Snapshot is prod-only.' );
			}
			if ( ! TIMU_SiteKit_Prod_Pin::is_auth_healthy() ) {
				WP_CLI::error( 'Site Kit auth not healthy on prod right now — refusing to snapshot a broken state. Re-auth in browser first, then re-run.' );
			}

			$result = TIMU_SiteKit_Prod_Pin::pin_state();
			if ( empty( $result['pinned'] ) ) {
				WP_CLI::error( 'Failed to write snapshot to ' . $result['path'] . ' (' . $result['reason'] . ').' );
			}

			WP_CLI::success(
				sprintf(
					'Snapshot written to %s — %d options, %d user_meta keys.',
					$result['path'],
					$result['options'],
					$result['usermeta']
				)
			);
		}

		/**
		 * Manually restore Site Kit auth state from snapshot.
		 *
		 * Recovery path: runs regardless of the sitekit_portal_pin_enabled gate so
		 * an operator can always re-apply a pinned state by hand.
		 *
		 * ## EXAMPLES
		 *
		 *     wp sitekit-pin restore
		 *     wp --user=admin sitekit-pin restore
		 *
		 * @when after_wp_load
		 *
		 * @param array $args       Positional arguments (unused).
		 * @param array $assoc_args Associative arguments (unused).
		 *
		 * @return void
		 */
		public function restore( $args, $assoc_args ) {
			$this->require_cap();

			if ( ! TIMU_SiteKit_Prod_Pin::is_production() ) {
				WP_CLI::error( 'Not on production. Restore is prod-only.' );
			}
			$result = TIMU_SiteKit_Prod_Pin::restore_from_snapshot();
			if ( empty( $result['restored'] ) ) {
				WP_CLI::error( 'Restore failed: ' . ( $result['reason'] ?? 'unknown' ) );
			}
			WP_CLI::success(
				sprintf(
					'Restored %d options + %d user_meta keys (snapshot from %s).',
					$result['options_set'],
					$result['meta_set'],
					$result['taken_at'] ?? 'unknown'
				)
			);
		}

		/**
		 * Show gate state, detected environment, pinned keys, and snapshot status.
		 *
		 * Read-only — no capability check, no mutation. Reports the master gate
		 * state, whether the install is detected as production, the resolved prod
		 * URL, the tracked option keys, auth health, and snapshot integrity/age.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : Output format.
		 * ---
		 * default: list
		 * options:
		 *   - list
		 *   - table
		 *   - json
		 *   - yaml
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     wp sitekit-pin status
		 *     wp sitekit-pin status --format=json
		 *
		 * @when after_wp_load
		 *
		 * @param array $args       Positional arguments (unused).
		 * @param array $assoc_args Associative arguments.
		 *
		 * @return void
		 */
		public function status( $args, $assoc_args ) {
			$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'list';
			$path   = TIMU_SiteKit_Prod_Pin::snapshot_path();

			$snapshot_exists = file_exists( $path );
			$mac_status      = 'none';
			$taken_at_human  = null;
			$age_days        = null;
			$snap_options    = 0;
			$snap_usermeta   = 0;

			if ( $snapshot_exists ) {
				// Detect MAC envelope directly so we can report integrity even when
				// read_snapshot() fails closed on a mismatch.
				$raw_file    = file_get_contents( $path );
				$raw_payload = is_string( $raw_file ) ? json_decode( $raw_file, true ) : null;
				$has_mac     = is_array( $raw_payload ) && isset( $raw_payload['mac'], $raw_payload['body'] );
				$mac_status  = 'missing (pre-upgrade snapshot; will be replaced on next healthy cron)';
				if ( $has_mac ) {
					$expected   = hash_hmac( 'sha256', (string) $raw_payload['body'], wp_salt( 'auth' ) );
					$mac_status = hash_equals( $expected, (string) $raw_payload['mac'] ) ? 'verified-ok' : 'FAILED (integrity check failed)';
				}

				$snap = TIMU_SiteKit_Prod_Pin::read_snapshot();
				if ( $snap ) {
					$taken_at_human = $snap['taken_at_human'] ?? 'unknown';
					$age_days       = ! empty( $snap['taken_at'] ) ? round( ( time() - $snap['taken_at'] ) / DAY_IN_SECONDS, 1 ) : 'unknown';
					$snap_options   = count( $snap['options'] );
					$snap_usermeta  = ! empty( $snap['usermeta'] ) ? count( $snap['usermeta'] ) : 0;
				}
			}

			$next = wp_next_scheduled( TIMU_SiteKit_Prod_Pin::CRON_HOOK );

			$status = array(
				'enabled'        => TIMU_SiteKit_Prod_Pin::is_enabled() ? 'yes' : 'no',
				'production'     => TIMU_SiteKit_Prod_Pin::is_production() ? 'yes' : 'no (no-op env)',
				'prod_url'       => TIMU_SiteKit_Prod_Pin::get_prod_siteurl(),
				'auth_healthy'   => TIMU_SiteKit_Prod_Pin::is_auth_healthy() ? 'yes' : 'no',
				'tracked_keys'   => implode( ', ', TIMU_SiteKit_Prod_Pin::get_tracked_options() ),
				'snapshot_path'  => $path,
				'snapshot'       => $snapshot_exists ? 'present' : 'absent',
				'integrity'      => $snapshot_exists ? $mac_status : 'n/a',
				'taken_at'       => $taken_at_human ?? 'n/a',
				'age_days'       => null === $age_days ? 'n/a' : (string) $age_days,
				'options_count'  => (string) $snap_options,
				'usermeta_count' => (string) $snap_usermeta,
				'next_cron'      => $next ? gmdate( 'c', $next ) : 'not scheduled',
			);

			if ( 'list' === $format ) {
				foreach ( $status as $key => $value ) {
					WP_CLI::log( str_pad( $key . ':', 16 ) . $value );
				}
				return;
			}

			$items = array();
			foreach ( $status as $key => $value ) {
				$items[] = array(
					'field' => $key,
					'value' => $value,
				);
			}
			WP_CLI\Utils\format_items( $format, $items, array( 'field', 'value' ) );
		}
	}
}

TIMU_SiteKit_Prod_Pin::init();
