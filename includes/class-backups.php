<?php
/**
 * Detect installed backup plugins and extract last-run metadata.
 *
 * Per-plugin detectors return null for fields we can't extract reliably.
 * Detection is by plugin file presence (not active state) so a deactivated
 * UpdraftPlus is still reported.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShakeConnect_Backups {

	/**
	 * @return array{plugins:array<int,array<string,mixed>>}
	 */
	public static function state() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();

		$plugins = array();
		foreach ( self::detectors() as $slug => $detector ) {
			$detected = call_user_func( $detector['installed'], $installed );
			if ( ! $detected ) {
				continue;
			}
			$row = array(
				'slug'                    => $slug,
				'name'                    => $detector['name'],
				'detected'                => true,
				'last_backup_at'          => null,
				'last_backup_size_bytes'  => null,
				'last_backup_destination' => null,
				'last_backup_status'      => null,
				'next_scheduled_at'       => null,
			);
			$details = call_user_func( $detector['details'] );
			if ( is_array( $details ) ) {
				$row = array_merge( $row, $details );
			}
			$plugins[] = $row;
		}

		return array( 'plugins' => $plugins );
	}

	private static function detectors() {
		return array(
			'updraftplus' => array(
				'name'      => 'UpdraftPlus',
				'installed' => static function ( $installed ) {
					return isset( $installed['updraftplus/updraftplus.php'] );
				},
				'details'   => array( __CLASS__, 'details_updraftplus' ),
			),
			'backwpup'    => array(
				'name'      => 'BackWPup',
				'installed' => static function ( $installed ) {
					return isset( $installed['backwpup/backwpup.php'] );
				},
				'details'   => array( __CLASS__, 'details_backwpup' ),
			),
			'blogvault'   => array(
				'name'      => 'BlogVault',
				'installed' => static function ( $installed ) {
					foreach ( $installed as $file => $_ ) {
						if ( false !== strpos( $file, 'blogvault' ) ) {
							return true;
						}
					}
					return false;
				},
				'details'   => array( __CLASS__, 'details_blogvault' ),
			),
			'solid'       => array(
				'name'      => 'Solid Backups',
				'installed' => static function ( $installed ) {
					return isset( $installed['backupbuddy/backupbuddy.php'] )
						|| isset( $installed['solid-backups/solid-backups.php'] );
				},
				'details'   => array( __CLASS__, 'details_solid' ),
			),
			'wpvivid'     => array(
				'name'      => 'WPvivid',
				'installed' => static function ( $installed ) {
					return isset( $installed['wpvivid-backuprestore/wpvivid-backuprestore.php'] );
				},
				'details'   => array( __CLASS__, 'details_wpvivid' ),
			),
			'duplicator'  => array(
				'name'      => 'Duplicator',
				'installed' => static function ( $installed ) {
					foreach ( array_keys( $installed ) as $file ) {
						if ( 0 === strpos( $file, 'duplicator/' ) || 0 === strpos( $file, 'duplicator-pro/' ) ) {
							return true;
						}
					}
					return false;
				},
				'details'   => array( __CLASS__, 'details_duplicator' ),
			),
		);
	}

	public static function details_updraftplus() {
		$last  = get_option( 'updraft_last_backup' );
		$row   = array();
		if ( is_array( $last ) ) {
			if ( ! empty( $last['backup_time'] ) ) {
				$row['last_backup_at'] = gmdate( 'c', (int) $last['backup_time'] );
			}
			if ( isset( $last['success'] ) ) {
				$row['last_backup_status'] = $last['success'] ? 'success' : 'failed';
			}
		}
		$next = wp_next_scheduled( 'updraft_backup' );
		if ( $next ) {
			$row['next_scheduled_at'] = gmdate( 'c', (int) $next );
		}
		return $row;
	}

	public static function details_backwpup() {
		$logs = get_posts(
			array(
				'post_type'   => 'backwpup_log',
				'numberposts' => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		$row = array();
		if ( ! empty( $logs ) ) {
			$log = $logs[0];
			$row['last_backup_at'] = get_post_time( 'c', true, $log );
			$status = get_post_meta( $log->ID, 'backwpup_backup_status', true );
			if ( $status ) {
				$row['last_backup_status'] = sanitize_key( $status );
			}
		}
		return $row;
	}

	public static function details_blogvault() {
		$row = array();
		$last = get_option( 'bv_last_backup' );
		if ( $last && is_numeric( $last ) ) {
			$row['last_backup_at'] = gmdate( 'c', (int) $last );
		}
		// BlogVault stores account details opaque-ish; surface "remote" as destination.
		if ( get_option( 'bv_account_details' ) ) {
			$row['last_backup_destination'] = 'blogvault-cloud';
		}
		return $row;
	}

	public static function details_solid() {
		$row = array();
		$stats = get_option( 'pb_backupbuddy_stats' );
		if ( is_array( $stats ) && ! empty( $stats['last_backup_finish'] ) ) {
			$row['last_backup_at'] = gmdate( 'c', (int) $stats['last_backup_finish'] );
		}
		return $row;
	}

	public static function details_wpvivid() {
		$row = array();
		$list = get_option( 'wpvivid_backup_list' );
		if ( is_array( $list ) && ! empty( $list ) ) {
			// Latest by created.
			$latest = null;
			foreach ( $list as $item ) {
				if ( is_array( $item ) && isset( $item['create_time'] ) ) {
					if ( ! $latest || $item['create_time'] > $latest['create_time'] ) {
						$latest = $item;
					}
				}
			}
			if ( $latest ) {
				$row['last_backup_at'] = gmdate( 'c', (int) $latest['create_time'] );
				if ( isset( $latest['size'] ) ) {
					$row['last_backup_size_bytes'] = (int) $latest['size'];
				}
				if ( isset( $latest['status'] ) ) {
					$row['last_backup_status'] = sanitize_key( (string) $latest['status'] );
				}
			}
		}
		return $row;
	}

	public static function details_duplicator() {
		$row      = array();
		$packages = get_posts(
			array(
				'post_type'   => 'duplicator_pro_package',
				'numberposts' => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		if ( empty( $packages ) ) {
			$packages = get_posts(
				array(
					'post_type'   => 'duplicator_package',
					'numberposts' => 1,
					'orderby'     => 'date',
					'order'       => 'DESC',
				)
			);
		}
		if ( ! empty( $packages ) ) {
			$row['last_backup_at'] = get_post_time( 'c', true, $packages[0] );
		}
		return $row;
	}
}
