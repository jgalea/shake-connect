<?php
/**
 * Read WPSAL (WP Activity Log) entries.
 *
 * Confirmed schema (verified against live wp_wsal_occurrences on 2026-05-29):
 *   id, site_id, alert_id (event code), created_on (double Unix sec), client_ip,
 *   severity, object, event_type, user_agent, user_roles, username, user_id,
 *   session_id, post_status, post_type, post_id.
 *
 * Metadata table `wp_wsal_metadata` (occurrence_id, name, value) holds the
 * variable bits — we fetch and bundle as JSON.
 *
 * Defensive: if the table doesn't exist, return [] with 200.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShakeConnect_Activity_Log {

	const HARD_CAP = 500;

	/**
	 * Detect which tier (Pro / Free / none) is present.
	 *
	 * @return array{source:string, table:string, table_rows:int|null}
	 */
	public static function source() {
		global $wpdb;
		$table = $wpdb->prefix . 'wsal_occurrences';
		if ( ! self::table_exists( $table ) ) {
			return array(
				'source'     => 'none',
				'table'      => $table,
				'table_rows' => null,
			);
		}

		$is_pro = class_exists( 'WSAL_Premium' )
			|| class_exists( 'WpSecurityAuditLog_Premium' )
			|| (bool) get_option( 'wsal_premium' );

		// Reading the WP Activity Log plugin's own table. Table name comes
		// from $wpdb->prefix + a static suffix; no external input.
		// Per-request state — caching makes no sense.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		// phpcs:enable

		return array(
			'source'     => $is_pro ? 'wpsal_pro' : 'wpsal_free',
			'table'      => $table,
			'table_rows' => $rows,
		);
	}

	/**
	 * Recent WPSAL events, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( $since_epoch = 0, $limit = 100 ) {
		global $wpdb;
		$since_epoch = (float) $since_epoch;
		$limit       = max( 1, min( self::HARD_CAP, (int) $limit ) );

		$table = $wpdb->prefix . 'wsal_occurrences';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}
		$meta_table = $wpdb->prefix . 'wsal_metadata';
		$has_meta   = self::table_exists( $meta_table );

		// Table names come from $wpdb->prefix + a static literal; safe to
		// interpolate. Values are bound via prepare().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, alert_id, created_on, client_ip, severity, object, event_type, user_roles, username, user_id, post_status, post_type, post_id
				 FROM `{$table}`
				 WHERE created_on > %f
				 ORDER BY created_on DESC
				 LIMIT %d",
				$since_epoch,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( ! $rows ) {
			return array();
		}

		// Bulk-fetch metadata for the result set.
		$meta_by_occ = array();
		if ( $has_meta && ! empty( $rows ) ) {
			$ids         = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// $placeholders is just commas + %d markers we constructed; $ids are ints.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$meta_rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT occurrence_id, name, value FROM `{$meta_table}` WHERE occurrence_id IN ({$placeholders})",
					$ids
				),
				ARRAY_A
			);
			// phpcs:enable
			foreach ( (array) $meta_rows as $m ) {
				$occ = (int) $m['occurrence_id'];
				if ( ! isset( $meta_by_occ[ $occ ] ) ) {
					$meta_by_occ[ $occ ] = array();
				}
				$meta_by_occ[ $occ ][ $m['name'] ] = $m['value'];
			}
		}

		$out = array();
		foreach ( $rows as $r ) {
			$code  = (int) $r['alert_id'];
			$label = self::decode( $code );
			$out[] = array(
				'source_event_id' => (string) $r['id'],
				'event_code'      => $code,
				'event_label'     => $label,
				'severity'        => self::map_severity( $r['severity'] ),
				'client_ip'       => $r['client_ip'] ?: null,
				'username'        => $r['username'] ?: null,
				'user_roles'      => $r['user_roles'] ?: null,
				'object_type'     => self::detect_object_type( $r ),
				'object_id'       => self::detect_object_id( $r ),
				'occurred_at'     => gmdate( 'c', (int) $r['created_on'] ),
				'metadata'        => isset( $meta_by_occ[ (int) $r['id'] ] ) ? $meta_by_occ[ (int) $r['id'] ] : null,
			);
		}
		return $out;
	}

	private static function table_exists( $table ) {
		global $wpdb;
		$like  = $wpdb->esc_like( $table );
		// SHOW TABLES is the canonical way to probe table existence; there is
		// no WP API helper for this. Result is bool-ish, no caching needed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		return ( $found === $table );
	}

	/**
	 * WPSAL severity is a string (200, 300, ...). Map to our labels.
	 */
	private static function map_severity( $raw ) {
		$n = (int) $raw;
		if ( $n >= 500 ) {
			return 'critical';
		}
		if ( $n >= 400 ) {
			return 'high';
		}
		if ( $n >= 300 ) {
			return 'medium';
		}
		if ( $n >= 200 ) {
			return 'low';
		}
		return 'informational';
	}

	private static function detect_object_type( $row ) {
		if ( ! empty( $row['post_id'] ) ) {
			return $row['post_type'] ? sanitize_key( $row['post_type'] ) : 'post';
		}
		if ( ! empty( $row['object'] ) ) {
			return sanitize_key( $row['object'] );
		}
		return null;
	}

	private static function detect_object_id( $row ) {
		if ( ! empty( $row['post_id'] ) ) {
			return (string) (int) $row['post_id'];
		}
		return null;
	}

	/**
	 * Decoder map for the 20 most common WPSAL alert codes.
	 * Unknown codes get a defensive `unknown_<code>` label.
	 */
	private static function decode( $code ) {
		static $map = array(
			1000 => 'user_logged_in',
			1002 => 'login_failed',
			1003 => 'login_blocked',
			1006 => 'user_logged_out',
			4000 => 'user_created',
			4001 => 'user_deleted',
			4002 => 'user_role_changed',
			4007 => 'user_password_changed',
			2000 => 'post_published',
			2001 => 'post_modified',
			2002 => 'post_deleted',
			2008 => 'post_status_changed',
			5000 => 'plugin_installed',
			5001 => 'plugin_activated',
			5002 => 'plugin_deactivated',
			5004 => 'plugin_deleted',
			5005 => 'plugin_updated',
			5010 => 'theme_activated',
			5011 => 'theme_installed',
			6000 => 'option_changed',
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : 'unknown_' . $code;
	}
}
