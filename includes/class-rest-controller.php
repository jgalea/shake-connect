<?php
/**
 * WPShake REST endpoints.
 *
 * Scoped, explicit endpoints — no raw `eval` exposed. Every operation the
 * WPShake agent needs has its own dedicated route with documented inputs
 * and outputs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShakeConnect_REST {

	/**
	 * Hard cap on subsite enumeration. Multisites this large are rare in
	 * the customer base; the dashboard should page if it ever sees this
	 * number returned. Keep the cap to bound response size and runtime.
	 */
	const MAX_SUBSITES = 500;

	/**
	 * Shared arg spec for endpoints that accept ?blog_id=N on multisite.
	 * Validation of blog existence happens inside the callback.
	 */
	private function blog_id_arg() {
		return array(
			'blog_id' => array(
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Resolve the optional blog_id param and switch context if applicable.
	 *
	 * Returns either an int blog_id to indicate switch_to_blog was called
	 * (caller must restore on success and failure paths), a WP_Error on
	 * invalid input, or null when no switch happened.
	 */
	private function maybe_switch_to_blog( $request ) {
		$raw = $request->get_param( 'blog_id' );
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		$blog_id = absint( $raw );
		if ( $blog_id <= 0 ) {
			return new WP_Error( 'shake_connect_bad_blog_id', 'blog_id must be a positive integer', array( 'status' => 400 ) );
		}
		if ( ! is_multisite() ) {
			return new WP_Error( 'shake_connect_not_multisite', 'blog_id is only valid on multisite installs', array( 'status' => 400 ) );
		}
		if ( ! get_blog_details( $blog_id ) ) {
			return new WP_Error( 'shake_connect_blog_not_found', "Subsite {$blog_id} does not exist", array( 'status' => 404 ) );
		}
		switch_to_blog( $blog_id );
		return $blog_id;
	}

	/**
	 * Run a callable inside the optional ?blog_id context. Restores the
	 * original blog on every exit path (success, exception, WP_Error
	 * return). The caller passes a closure that produces the response.
	 */
	private function with_blog_context( $request, callable $work ) {
		$switched = $this->maybe_switch_to_blog( $request );
		if ( is_wp_error( $switched ) ) {
			return $switched;
		}
		try {
			return $work();
		} finally {
			if ( null !== $switched ) {
				restore_current_blog();
			}
		}
	}

	public function register_routes() {
		$auth = array( 'ShakeConnect_Auth', 'permission_callback' );

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/site/info',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'site_info' ),
				'permission_callback' => $auth,
				'args'                => $this->blog_id_arg(),
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/plugins',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_plugins' ),
				'permission_callback' => $auth,
				'args'                => $this->blog_id_arg(),
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/themes',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_themes' ),
				'permission_callback' => $auth,
				'args'                => $this->blog_id_arg(),
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/core/info',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'core_info' ),
				'permission_callback' => $auth,
				'args'                => $this->blog_id_arg(),
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/checksums',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'core_checksums' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/db/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'db_health' ),
				'permission_callback' => $auth,
				'args'                => $this->blog_id_arg(),
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/orders/recent',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'recent_orders' ),
				'permission_callback' => $auth,
				'args'                => $this->blog_id_arg(),
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/php-errors/recent',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'php_errors_recent' ),
				'permission_callback' => $auth,
				'args'                => array_merge(
					$this->blog_id_arg(),
					array(
						'since' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
						'limit' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
					)
				),
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/php-errors/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'php_errors_stats' ),
				'permission_callback' => $auth,
				'args'                => array_merge(
					$this->blog_id_arg(),
					array(
						'hours' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
					)
				),
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/backups/state',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'backups_state' ),
				'permission_callback' => $auth,
				'args'                => $this->blog_id_arg(),
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/activity-log/source',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'activity_log_source' ),
				'permission_callback' => $auth,
				'args'                => $this->blog_id_arg(),
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/activity-log/recent',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'activity_log_recent' ),
				'permission_callback' => $auth,
				'args'                => array_merge(
					$this->blog_id_arg(),
					array(
						'since' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
						'limit' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
					)
				),
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/security/malware-scan',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'malware_scan' ),
				'permission_callback' => $auth,
				'args'                => $this->blog_id_arg(),
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/links/broken',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'links_broken' ),
				'permission_callback' => $auth,
				'args'                => array_merge(
					$this->blog_id_arg(),
					array(
						'limit' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
					)
				),
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/network/sites',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'network_sites' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/cache/flush',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cache_flush' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/cron/run-due',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_due_cron' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/transients/delete-expired',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'delete_expired_transients' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			SHAKE_CONNECT_NAMESPACE,
			'/permalinks/flush',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'permalinks_flush' ),
				'permission_callback' => $auth,
			)
		);

	}

	/* ---------- READ ---------- */

	public function ping( $request ) {
		return rest_ensure_response(
			array(
				'ok'              => true,
				'plugin_version'  => SHAKE_CONNECT_VERSION,
				'site_url'        => get_site_url(),
				'wp_version'      => get_bloginfo( 'version' ),
				'php_version'     => PHP_VERSION,
			)
		);
	}

	public function site_info( $request ) {
		return $this->with_blog_context(
			$request,
			function () {
				global $wp_version;
				$admin_count = count( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) );
				return rest_ensure_response(
					array(
						'site_url'          => get_site_url(),
						'home_url'          => get_home_url(),
						'wp_version'        => $wp_version,
						'php_version'       => PHP_VERSION,
						'admin_user_count'  => $admin_count,
						'is_multisite'      => is_multisite(),
						'blog_id'           => get_current_blog_id(),
						'debug_log_enabled' => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
						'object_cache'      => wp_using_ext_object_cache(),
						'active_theme'      => array(
							'stylesheet' => get_stylesheet(),
							'template'   => get_template(),
						),
					)
				);
			}
		);
	}

	public function list_plugins( $request ) {
		return $this->with_blog_context(
			$request,
			function () {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				require_once ABSPATH . 'wp-admin/includes/update.php';
				wp_update_plugins();
				$updates = get_site_transient( 'update_plugins' );
				$out     = array();
				foreach ( get_plugins() as $file => $data ) {
					$slug = dirname( $file );
					if ( '.' === $slug ) {
						$slug = basename( $file, '.php' );
					}
					$update_info = isset( $updates->response[ $file ] ) ? $updates->response[ $file ] : null;
					$out[]       = array(
						'slug'           => $slug,
						'file'           => $file,
						'name'           => $data['Name'],
						'status'         => is_plugin_active( $file ) ? 'active' : 'inactive',
						'version'        => $data['Version'],
						'update'         => $update_info ? 'available' : 'none',
						'update_version' => $update_info ? $update_info->new_version : null,
					);
				}
				return rest_ensure_response( $out );
			}
		);
	}

	public function list_themes( $request ) {
		return $this->with_blog_context(
			$request,
			function () {
				require_once ABSPATH . 'wp-admin/includes/update.php';
				wp_update_themes();
				$updates = get_site_transient( 'update_themes' );
				$active  = get_stylesheet();
				$parent  = get_template();
				$out     = array();
				foreach ( wp_get_themes() as $stylesheet => $theme ) {
					$update_info = isset( $updates->response[ $stylesheet ] ) ? $updates->response[ $stylesheet ] : null;
					$status      = $stylesheet === $active ? 'active' : ( $stylesheet === $parent ? 'parent' : 'inactive' );
					$out[]       = array(
						'slug'           => $stylesheet,
						'name'           => $theme->get( 'Name' ),
						'status'         => $status,
						'version'        => $theme->get( 'Version' ),
						'update'         => $update_info ? 'available' : 'none',
						'update_version' => $update_info ? $update_info['new_version'] : null,
					);
				}
				return rest_ensure_response( $out );
			}
		);
	}

	public function core_info( $request ) {
		return $this->with_blog_context(
			$request,
			function () {
				require_once ABSPATH . 'wp-admin/includes/update.php';
				$updates    = get_core_updates();
				$update_av  = false;
				$update_ver = null;
				foreach ( (array) $updates as $u ) {
					if ( isset( $u->response ) && 'upgrade' === $u->response ) {
						$update_av  = true;
						$update_ver = $u->current;
						break;
					}
				}
				return rest_ensure_response(
					array(
						'version'         => get_bloginfo( 'version' ),
						'updateAvailable' => $update_av,
						'updateVersion'   => $update_ver,
					)
				);
			}
		);
	}

	public function network_sites( $request ) {
		if ( ! is_multisite() ) {
			return rest_ensure_response( array() );
		}
		$sites = get_sites(
			array(
				'number'  => self::MAX_SUBSITES,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);
		$out = array();
		foreach ( $sites as $site ) {
			$blog_id = (int) $site->blog_id;
			$out[]   = array(
				'blog_id'      => $blog_id,
				'domain'       => $site->domain,
				'path'         => $site->path,
				'url'          => get_site_url( $blog_id ),
				'is_main_site' => is_main_site( $blog_id ),
				'registered'   => $site->registered,
				'public'       => (bool) (int) $site->public,
				'archived'     => (bool) (int) $site->archived,
				'deleted'      => (bool) (int) $site->deleted,
			);
		}
		return rest_ensure_response( $out );
	}

	public function core_checksums( $request ) {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		$checksums = get_core_checksums( get_bloginfo( 'version' ), get_locale() );
		if ( false === $checksums ) {
			return new WP_Error( 'shake_connect_checksum_fetch_failed', 'Could not fetch core checksums.', array( 'status' => 502 ) );
		}
		$mismatched = array();
		foreach ( $checksums as $file => $expected ) {
			$path = ABSPATH . $file;
			if ( ! file_exists( $path ) ) {
				continue;
			}
			if ( md5_file( $path ) !== $expected ) {
				$mismatched[] = $file;
				if ( count( $mismatched ) >= 50 ) {
					break;
				}
			}
		}
		return rest_ensure_response(
			array(
				'verified'    => empty( $mismatched ),
				'mismatched'  => $mismatched,
				'total_files' => count( $checksums ),
			)
		);
	}

	public function db_health( $request ) {
		return $this->with_blog_context(
			$request,
			function () {
				global $wpdb;
				// Aggregate health stats on core tables. Inputs are literal SQL, no
				// user input. WP doesn't expose API-level helpers for these
				// aggregates, so direct queries are unavoidable. Per-request runtime
				// data — caching across requests would defeat the purpose.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$autoload_bytes     = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on')" );
				$expired_transients = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()" );
				$orphan_postmeta    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" );
				$db_size_mb         = (float) $wpdb->get_var( "SELECT ROUND(SUM(data_length + index_length)/1024/1024, 2) FROM information_schema.tables WHERE table_schema = DATABASE()" );
				// phpcs:enable
				return rest_ensure_response(
					array(
						'autoloadBytes'     => $autoload_bytes,
						'expiredTransients' => $expired_transients,
						'orphanPostmeta'    => $orphan_postmeta,
						'dbTotalMb'         => $db_size_mb,
					)
				);
			}
		);
	}

	public function recent_orders( $request ) {
		return $this->with_blog_context(
			$request,
			function () {
				if ( ! class_exists( 'WC_Order' ) ) {
					return rest_ensure_response( array( 'wc_active' => false ) );
				}
				$args = array(
					'limit'   => 20,
					'orderby' => 'date',
					'order'   => 'DESC',
					'return'  => 'ids',
					'status'  => array( 'processing', 'completed', 'on-hold', 'failed' ),
				);
				$ids = function_exists( 'wc_get_orders' ) ? wc_get_orders( $args ) : array();
				$out = array();
				foreach ( $ids as $id ) {
					$order = wc_get_order( $id );
					if ( ! $order ) {
						continue;
					}
					$out[] = array(
						'id'       => $order->get_id(),
						'status'   => $order->get_status(),
						'date_gmt' => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
					);
				}
				return rest_ensure_response(
					array(
						'wc_active' => true,
						'orders'    => $out,
					)
				);
			}
		);
	}

	public function php_errors_recent( $request ) {
		return $this->with_blog_context(
			$request,
			function () use ( $request ) {
				$since = (int) $request->get_param( 'since' );
				$limit = (int) $request->get_param( 'limit' );
				if ( $limit <= 0 ) {
					$limit = 50;
				}
				$data = ShakeConnect_PHP_Errors::read( $since, $limit );
				return rest_ensure_response(
					array(
						'log_path' => $data['path'],
						'entries'  => $data['entries'],
					)
				);
			}
		);
	}

	public function php_errors_stats( $request ) {
		return $this->with_blog_context(
			$request,
			function () use ( $request ) {
				$hours = (int) $request->get_param( 'hours' );
				if ( $hours <= 0 ) {
					$hours = 24;
				}
				return rest_ensure_response( ShakeConnect_PHP_Errors::stats( $hours ) );
			}
		);
	}

	public function backups_state( $request ) {
		return $this->with_blog_context(
			$request,
			function () {
				return rest_ensure_response( ShakeConnect_Backups::state() );
			}
		);
	}

	public function activity_log_source( $request ) {
		return $this->with_blog_context(
			$request,
			function () {
				return rest_ensure_response( ShakeConnect_Activity_Log::source() );
			}
		);
	}

	public function activity_log_recent( $request ) {
		return $this->with_blog_context(
			$request,
			function () use ( $request ) {
				$since = (int) $request->get_param( 'since' );
				$limit = (int) $request->get_param( 'limit' );
				if ( $limit <= 0 ) {
					$limit = 100;
				}
				return rest_ensure_response( ShakeConnect_Activity_Log::recent( $since, $limit ) );
			}
		);
	}

	public function malware_scan( $request ) {
		return $this->with_blog_context(
			$request,
			function () {
				return rest_ensure_response( ShakeConnect_Malware_Scanner::scan() );
			}
		);
	}

	public function links_broken( $request ) {
		return $this->with_blog_context(
			$request,
			function () use ( $request ) {
				$limit = (int) $request->get_param( 'limit' );
				if ( $limit <= 0 ) {
					$limit = 200;
				}
				return rest_ensure_response( ShakeConnect_Link_Checker::scan( $limit ) );
			}
		);
	}

	/* ---------- WRITE (maintenance only — no install/update endpoints in the .org build) ---------- */

	public function cache_flush( $request ) {
		wp_cache_flush();
		// Try popular caching plugin clear-all hooks.
		do_action( 'shake_connect_clear_caches' );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function run_due_cron( $request ) {
		$crons = _get_cron_array();
		$ran   = 0;
		$now   = time();
		foreach ( $crons as $timestamp => $hooks ) {
			if ( $timestamp > $now ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				foreach ( $events as $event ) {
					// We're invoking existing WP-Cron hooks scheduled by core,
					// the theme, or other plugins. The hook name is dynamic by
					// the nature of running due jobs, not a prefix we control.
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
					do_action_ref_array( $hook, $event['args'] );
					wp_unschedule_event( $timestamp, $hook, $event['args'] );
					$ran++;
				}
			}
		}
		return rest_ensure_response( array( 'ok' => true, 'ran' => $ran ) );
	}

	public function delete_expired_transients( $request ) {
		global $wpdb;
		$now    = time();
		// Bulk delete on the options table. Prepared SQL with proper escaping.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', $now ) );
		return rest_ensure_response( array( 'ok' => true, 'deleted' => (int) $result ) );
	}

	public function permalinks_flush( $request ) {
		flush_rewrite_rules( true );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ---------- SANITIZERS ---------- */

	public function sanitize_slug( $value ) {
		$value = (string) $value;
		if ( ! preg_match( '/^[a-zA-Z0-9._\-]+$/', $value ) || strlen( $value ) > 100 ) {
			return '';
		}
		return $value;
	}
}
