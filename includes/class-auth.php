<?php
/**
 * Token-based auth for the WPShake REST surface.
 *
 * The site owner generates a token in Settings > WPShake Connect, sees it
 * once, and pastes it into the WPShake dashboard. The plugin stores only
 * the SHA-256 hash. The WPShake agent sends the plain token in the
 * `Authorization: Bearer <token>` header on every request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShakeConnect_Auth {

	const TOKEN_BYTES = 32;

	public static function generate_token() {
		try {
			return bin2hex( random_bytes( self::TOKEN_BYTES ) );
		} catch ( Exception $e ) {
			return wp_generate_password( 64, false, false );
		}
	}

	/**
	 * Read the token hash. On multisite, the token is a network-level
	 * option so the same hash works across every subsite. On single-site,
	 * it falls back to the per-site option (older installs migrated here).
	 */
	public static function get_stored_hash() {
		if ( is_multisite() ) {
			$network_value = get_site_option( SHAKE_CONNECT_OPT_TOKEN, '' );
			if ( ! empty( $network_value ) ) {
				return $network_value;
			}
			// Legacy fallback: token stored as a regular option pre-0.5.0.
			return get_option( SHAKE_CONNECT_OPT_TOKEN, '' );
		}
		return get_option( SHAKE_CONNECT_OPT_TOKEN, '' );
	}

	public static function store_hashed( $plain_token ) {
		$hash = hash( 'sha256', $plain_token );
		if ( is_multisite() ) {
			update_site_option( SHAKE_CONNECT_OPT_TOKEN, $hash );
		} else {
			update_option( SHAKE_CONNECT_OPT_TOKEN, $hash );
		}
	}

	public static function verify( $plain_token ) {
		$stored = self::get_stored_hash();
		if ( empty( $stored ) || ! is_string( $plain_token ) ) {
			return false;
		}
		return hash_equals( $stored, hash( 'sha256', $plain_token ) );
	}

	public static function is_configured() {
		return ! empty( self::get_stored_hash() );
	}

	public static function update_last_seen() {
		if ( is_multisite() ) {
			update_site_option( SHAKE_CONNECT_OPT_LAST_SEEN, time() );
		} else {
			update_option( SHAKE_CONNECT_OPT_LAST_SEEN, time() );
		}
	}

	public static function get_last_seen() {
		if ( is_multisite() ) {
			$network_value = (int) get_site_option( SHAKE_CONNECT_OPT_LAST_SEEN, 0 );
			if ( $network_value > 0 ) {
				return $network_value;
			}
			return (int) get_option( SHAKE_CONNECT_OPT_LAST_SEEN, 0 );
		}
		return (int) get_option( SHAKE_CONNECT_OPT_LAST_SEEN, 0 );
	}

	/**
	 * Permission callback for REST endpoints. Verifies the bearer token
	 * and stamps last-seen for the dashboard.
	 */
	public static function permission_callback( $request ) {
		$header = $request->get_header( 'authorization' );
		if ( empty( $header ) || stripos( $header, 'Bearer ' ) !== 0 ) {
			return new WP_Error( 'shake_connect_unauthorized', 'Missing bearer token', array( 'status' => 401 ) );
		}
		$token = trim( substr( $header, 7 ) );
		if ( ! self::verify( $token ) ) {
			return new WP_Error( 'shake_connect_unauthorized', 'Invalid token', array( 'status' => 401 ) );
		}
		self::update_last_seen();
		return true;
	}
}
