<?php
/**
 * Plugin Name: Site Kit Portal Pin
 * Plugin URI: https://github.com/thisismyurl/sitekit-portal-pin
 * Description: Pins production's Site Kit OAuth state so WP Engine Portal copies dev → prod don't break the connection. Snapshots prod's healthy auth to a file outside wp-content/ (untouched by Portal copies); auto-restores after a copy lands dev's empty/wrong state on prod.
 * Author: Christopher Ross
 * Author URI: https://thisismyurl.com
 * Version: 1.0.1
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
	 * Are we running on production?
	 */
	public static function is_production() {
		$prod_url = self::get_prod_siteurl();
		if ( '' === $prod_url ) {
			return false; // No production URL configured — no-op silently.
		}
		return rtrim( (string) get_option( 'siteurl' ), '/' ) === $prod_url;
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

		/** This filter lets site owners auto-derive prod URL from environment conventions. */
		$filtered = (string) apply_filters( 'sitekit_portal_pin_prod_url', '' );
		return rtrim( $filtered, '/' );
	}

	/**
	 * Path to the snapshot file. Lives outside wp-content/ so Portal copies don't touch it.
	 */
	public static function snapshot_path() {
		return dirname( WP_CONTENT_DIR ) . '/.sitekit-prod-snapshot.json';
	}

	/**
	 * Schedule the daily snapshot cron event if not already scheduled.
	 */
	public static function schedule_cron() {
		if ( ! self::is_production() ) {
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
		foreach ( self::TRACKED_OPTIONS as $key ) {
			$val = get_option( $key, null );
			if ( null !== $val ) {
				$options[ $key ] = $val;
			}
		}

		$usermeta_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$owner_id,
				$wpdb->esc_like( self::USERMETA_PREFIX ) . '%'
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
			'version'          => 1,
			'taken_at'         => time(),
			'taken_at_human'   => gmdate( 'c' ),
			'siteurl'          => get_option( 'siteurl' ),
			'owner_id'         => $owner_id,
			'options'          => $options,
			'usermeta'         => $usermeta,
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

		if ( empty( $creds ) || ! $owner ) {
			return false;
		}
		if ( ! empty( $err ) ) {
			return false;
		}
		if ( $proxy && rtrim( $proxy, '/' ) !== rtrim( $siteurl, '/' ) ) {
			return false;
		}

		$access_token = get_user_meta( $owner, 'wp_googlesitekit_access_token', true );
		if ( empty( $access_token ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Snapshot to disk if currently healthy. Daily cron callback.
	 */
	public static function snapshot_if_healthy() {
		if ( ! self::is_production() ) {
			return;
		}
		if ( ! self::is_auth_healthy() ) {
			return;
		}

		$snap = self::build_snapshot();
		if ( ! $snap ) {
			return;
		}

		self::write_snapshot( $snap );
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
			array( 'mac' => $mac, 'body' => $body ),
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
			add_action( 'admin_notices', static function () {
				echo '<div class="notice notice-error"><p><strong>Site Kit Portal Pin:</strong> snapshot integrity check failed (HMAC mismatch). The snapshot file may have been tampered with or corrupted. A fresh snapshot will be taken on the next healthy auth check.</p></div>';
			} );
			return null;
		}

		$decoded = json_decode( (string) $payload['body'], true );
		if ( ! is_array( $decoded ) || empty( $decoded['options'] ) ) {
			return null;
		}
		return $decoded;
	}

	/**
	 * Restore Site Kit auth state from snapshot. Returns array of stats.
	 */
	public static function restore_from_snapshot( $snap = null ) {
		if ( null === $snap ) {
			$snap = self::read_snapshot();
		}
		if ( ! $snap || empty( $snap['options'] ) ) {
			return array( 'restored' => false, 'reason' => 'no_snapshot' );
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
		$taken    = isset( $snap['taken_at'] ) ? (int) $snap['taken_at'] : 0;
		$max_age  = 30 * DAY_IN_SECONDS;
		if ( $taken && ( time() - $taken ) > $max_age ) {
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
		 * Manually take a snapshot of the current Site Kit auth state.
		 *
		 * ## EXAMPLES
		 *
		 *     wp sitekit-pin snapshot
		 *
		 * @when after_wp_load
		 */
		public function snapshot( $args, $assoc_args ) {
			if ( ! TIMU_SiteKit_Prod_Pin::is_production() ) {
					WP_CLI::error( 'Not on production (siteurl != ' . TIMU_SiteKit_Prod_Pin::get_prod_siteurl() . '). Snapshot is prod-only.' );
			}
			if ( ! TIMU_SiteKit_Prod_Pin::is_auth_healthy() ) {
				WP_CLI::error( 'Site Kit auth not healthy on prod right now — refusing to snapshot a broken state. Re-auth in browser first, then re-run.' );
			}
			$snap = TIMU_SiteKit_Prod_Pin::build_snapshot();
			$ok   = TIMU_SiteKit_Prod_Pin::write_snapshot( $snap );
			if ( ! $ok ) {
				WP_CLI::error( 'Failed to write snapshot to ' . TIMU_SiteKit_Prod_Pin::snapshot_path() );
			}
			WP_CLI::success(
				sprintf(
					'Snapshot written to %s — %d options, %d user_meta keys.',
					TIMU_SiteKit_Prod_Pin::snapshot_path(),
					count( $snap['options'] ),
					count( $snap['usermeta'] )
				)
			);
		}

		/**
		 * Manually restore Site Kit auth state from snapshot.
		 *
		 * ## EXAMPLES
		 *
		 *     wp sitekit-pin restore
		 *
		 * @when after_wp_load
		 */
		public function restore( $args, $assoc_args ) {
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
		 * Show snapshot + auth status.
		 *
		 * ## EXAMPLES
		 *
		 *     wp sitekit-pin status
		 *
		 * @when after_wp_load
		 */
		public function status( $args, $assoc_args ) {
			$path = TIMU_SiteKit_Prod_Pin::snapshot_path();
			WP_CLI::log( 'Production: ' . ( TIMU_SiteKit_Prod_Pin::is_production() ? 'YES' : 'no (no-op env)' ) );
			WP_CLI::log( 'Auth healthy: ' . ( TIMU_SiteKit_Prod_Pin::is_auth_healthy() ? 'YES' : 'NO' ) );
			WP_CLI::log( 'Snapshot path: ' . $path );

			if ( file_exists( $path ) ) {
				// Detect MAC envelope before calling read_snapshot() so we can report MAC status.
				$raw_file    = file_get_contents( $path );
				$raw_payload = is_string( $raw_file ) ? json_decode( $raw_file, true ) : null;
				$has_mac     = is_array( $raw_payload ) && isset( $raw_payload['mac'], $raw_payload['body'] );
				$mac_status  = 'MAC-missing (pre-upgrade snapshot; will be replaced on next healthy cron)';
				if ( $has_mac ) {
					$expected   = hash_hmac( 'sha256', (string) $raw_payload['body'], wp_salt( 'auth' ) );
					$mac_status = hash_equals( $expected, (string) $raw_payload['mac'] ) ? 'MAC-verified OK' : 'MAC-FAILED (integrity check failed)';
				}

				$snap     = TIMU_SiteKit_Prod_Pin::read_snapshot();
				$age_days = $snap && ! empty( $snap['taken_at'] ) ? round( ( time() - $snap['taken_at'] ) / DAY_IN_SECONDS, 1 ) : 'unknown';
				WP_CLI::log( 'Snapshot exists: YES' );
				WP_CLI::log( '  integrity: ' . $mac_status );
				WP_CLI::log( '  taken_at:  ' . ( $snap['taken_at_human'] ?? 'unknown' ) );
				WP_CLI::log( '  age_days:  ' . $age_days );
				WP_CLI::log( '  options:   ' . ( $snap ? count( $snap['options'] ) : 0 ) );
				WP_CLI::log( '  usermeta:  ' . ( $snap && ! empty( $snap['usermeta'] ) ? count( $snap['usermeta'] ) : 0 ) );
			} else {
				WP_CLI::log( 'Snapshot exists: NO' );
			}

			$next = wp_next_scheduled( TIMU_SiteKit_Prod_Pin::CRON_HOOK );
			WP_CLI::log( 'Next snapshot cron: ' . ( $next ? gmdate( 'c', $next ) : 'not scheduled' ) );
		}
	}
}

TIMU_SiteKit_Prod_Pin::init();
