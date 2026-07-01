<?php
/**
 * Parse the PHP error log into a structured array.
 *
 * Defensive: if the log path is empty, unreadable, or the file is too big
 * to scan from the tail, return an empty list rather than erroring.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShakeConnect_PHP_Errors {

	const HARD_CAP = 500;
	const MAX_BYTES_TO_READ = 4194304; // 4 MB tail.

	/**
	 * @return array{path:string|null, entries:array<int,array<string,mixed>>}
	 */
	public static function read( $since_epoch = 0, $limit = 50 ) {
		$since_epoch = (int) $since_epoch;
		$limit       = max( 1, min( self::HARD_CAP, (int) $limit ) );

		$path = self::resolve_log_path();
		if ( ! $path || ! is_readable( $path ) ) {
			return array( 'path' => $path, 'entries' => array() );
		}

		$lines = self::tail_lines( $path, self::MAX_BYTES_TO_READ );
		if ( empty( $lines ) ) {
			return array( 'path' => $path, 'entries' => array() );
		}

		$entries = array();
		foreach ( $lines as $line ) {
			$parsed = self::parse_line( $line );
			if ( ! $parsed ) {
				continue;
			}
			if ( $since_epoch && $parsed['ts_epoch'] && $parsed['ts_epoch'] < $since_epoch ) {
				continue;
			}
			$entries[] = $parsed;
		}

		// Most recent first.
		usort(
			$entries,
			static function ( $a, $b ) {
				return ( $b['ts_epoch'] ?? 0 ) <=> ( $a['ts_epoch'] ?? 0 );
			}
		);

		if ( count( $entries ) > $limit ) {
			$entries = array_slice( $entries, 0, $limit );
		}

		return array( 'path' => $path, 'entries' => $entries );
	}

	/**
	 * Buckets by level for /php-errors/stats.
	 */
	public static function stats( $hours = 24 ) {
		$hours = max( 1, min( 168, (int) $hours ) );
		$since = time() - ( $hours * 3600 );
		$data  = self::read( $since, self::HARD_CAP );

		$buckets = array(
			'fatal'      => 0,
			'parse'      => 0,
			'warning'    => 0,
			'notice'     => 0,
			'deprecated' => 0,
			'strict'     => 0,
			'other'      => 0,
		);
		foreach ( $data['entries'] as $e ) {
			$lvl = $e['level'];
			if ( isset( $buckets[ $lvl ] ) ) {
				$buckets[ $lvl ]++;
			} else {
				$buckets['other']++;
			}
		}
		return array(
			'hours'   => $hours,
			'since'   => $since,
			'total'   => count( $data['entries'] ),
			'buckets' => $buckets,
			'path'    => $data['path'],
		);
	}

	private static function resolve_log_path() {
		// Prefer WP_DEBUG_LOG when it's a string path.
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && WP_DEBUG_LOG ) {
			return WP_DEBUG_LOG;
		}
		if ( defined( 'WP_DEBUG_LOG' ) && true === WP_DEBUG_LOG ) {
			$default = WP_CONTENT_DIR . '/debug.log';
			if ( file_exists( $default ) ) {
				return $default;
			}
		}
		$ini = ini_get( 'error_log' );
		if ( $ini && file_exists( $ini ) ) {
			return $ini;
		}
		return null;
	}

	/**
	 * Reads up to $max_bytes from the end of $path and returns its lines.
	 * Drops the first (possibly truncated) line.
	 */
	private static function tail_lines( $path, $max_bytes ) {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}
		if ( ! $wp_filesystem || ! $wp_filesystem->exists( $path ) ) {
			return array();
		}
		$size = (int) $wp_filesystem->size( $path );
		if ( $size <= 0 ) {
			return array();
		}
		$contents = $wp_filesystem->get_contents( $path );
		if ( false === $contents || '' === $contents ) {
			return array();
		}
		// Take the tail of $max_bytes to avoid scanning huge logs.
		if ( strlen( $contents ) > $max_bytes ) {
			$contents = substr( $contents, -$max_bytes );
			// Drop the first (possibly truncated) line.
			$first_nl = strpos( $contents, "\n" );
			if ( false !== $first_nl ) {
				$contents = substr( $contents, $first_nl + 1 );
			}
		}
		$lines = preg_split( '/\r?\n/', $contents );
		$out   = array();
		foreach ( $lines as $line ) {
			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * Parses one PHP error log line.
	 *
	 * Expected shape:
	 *   [27-May-2026 14:33:11 UTC] PHP Fatal error:  message in /path on line N
	 *   [27-May-2026 14:33:11 UTC] PHP Warning:  message in /path on line N
	 *   [27-May-2026 14:33:11 UTC] PHP Parse error:  ...
	 * Lines that don't start with a timestamp (stack traces) are skipped.
	 */
	private static function parse_line( $line ) {
		if ( ! preg_match( '/^\[([^\]]+)\]\s+PHP\s+([^:]+):\s*(.*)$/', $line, $m ) ) {
			return null;
		}
		$ts_raw  = $m[1];
		$level   = self::normalise_level( $m[2] );
		$message = $m[3];

		$file = null;
		$line_no = null;
		if ( preg_match( '/^(.*?)\s+in\s+(\/[^\s].*?)\s+on\s+line\s+(\d+)\s*$/', $message, $mm ) ) {
			$message = $mm[1];
			$file    = $mm[2];
			$line_no = (int) $mm[3];
		}

		$ts_epoch = strtotime( $ts_raw );

		return array(
			'level'    => $level,
			'message'  => self::truncate( $message, 1000 ),
			'file'     => $file ? self::truncate( $file, 500 ) : null,
			'line'     => $line_no,
			'ts_epoch' => $ts_epoch ? (int) $ts_epoch : null,
		);
	}

	private static function normalise_level( $raw ) {
		$raw = strtolower( trim( $raw ) );
		if ( false !== strpos( $raw, 'fatal' ) ) {
			return 'fatal';
		}
		if ( false !== strpos( $raw, 'parse' ) ) {
			return 'parse';
		}
		if ( false !== strpos( $raw, 'warning' ) ) {
			return 'warning';
		}
		if ( false !== strpos( $raw, 'notice' ) ) {
			return 'notice';
		}
		if ( false !== strpos( $raw, 'deprecated' ) ) {
			return 'deprecated';
		}
		if ( false !== strpos( $raw, 'strict' ) ) {
			return 'strict';
		}
		return 'other';
	}

	private static function truncate( $s, $len ) {
		if ( strlen( $s ) <= $len ) {
			return $s;
		}
		return substr( $s, 0, $len );
	}
}
