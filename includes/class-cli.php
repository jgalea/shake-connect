<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

class ShakeConnect_CLI {

	/**
	 * Generate a new connect token and store its hash. Prints the plain token
	 * once so it can be pasted into the WPShake dashboard.
	 *
	 * ## OPTIONS
	 *
	 * [--porcelain]
	 * : Only print the token, no other output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shake reset-token
	 *     wp shake reset-token --porcelain
	 */
	public function reset_token( $args, $assoc_args ) {
		$token = ShakeConnect_Auth::generate_token();
		ShakeConnect_Auth::store_hashed( $token );

		if ( ! empty( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( $token );
			return;
		}

		WP_CLI::success( 'Token rotated. Paste it into the WPShake dashboard now; it will not be shown again.' );
		WP_CLI::line( $token );
	}

	/**
	 * Show connection status: site URL, plugin version, token configured, last seen.
	 */
	public function status( $args, $assoc_args ) {
		$last_seen = (int) get_option( SHAKE_CONNECT_OPT_LAST_SEEN, 0 );
		WP_CLI::line( 'site_url:       ' . get_site_url() );
		WP_CLI::line( 'plugin_version: ' . SHAKE_CONNECT_VERSION );
		WP_CLI::line( 'wp_version:     ' . get_bloginfo( 'version' ) );
		WP_CLI::line( 'php_version:    ' . PHP_VERSION );
		WP_CLI::line( 'token_set:      ' . ( ShakeConnect_Auth::is_configured() ? 'yes' : 'no' ) );
		WP_CLI::line( 'last_seen:      ' . ( $last_seen ? gmdate( 'c', $last_seen ) : 'never' ) );
	}

	/**
	 * Revoke the stored token. The agent will lose access until a new token is set.
	 */
	public function revoke( $args, $assoc_args ) {
		delete_option( SHAKE_CONNECT_OPT_TOKEN );
		WP_CLI::success( 'Token revoked.' );
	}
}

WP_CLI::add_command( 'shake', 'ShakeConnect_CLI' );
