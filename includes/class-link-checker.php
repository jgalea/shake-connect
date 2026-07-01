<?php
/**
 * Broken-link checker.
 *
 * Extracts <a href> URLs from post_content + post_excerpt of published
 * posts/pages, then probes each unique URL with a HEAD request. Soft-caches
 * successful (2xx/3xx) results for 24h via WP transients so repeat scans are
 * mostly free.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShakeConnect_Link_Checker {

	const MAX_URLS         = 200;
	const MAX_DURATION_SEC = 25;
	const CACHE_TTL        = DAY_IN_SECONDS;
	const HOST_DELAY_MS    = 200;
	const TIMEOUT_SEC      = 10;
	const USER_AGENT       = 'WPShake-LinkChecker/1.0';

	/**
	 * @return array{broken:array<int,array<string,mixed>>,stats:array<string,int>}
	 */
	public static function scan( $limit = self::MAX_URLS ) {
		$started_at = microtime( true );
		$limit      = max( 1, min( self::MAX_URLS, (int) $limit ) );

		$urls = self::collect_urls( $limit );
		if ( empty( $urls ) ) {
			return array(
				'broken'  => array(),
				'stats'   => array( 'checked' => 0, 'broken_count' => 0, 'duration_ms' => 0 ),
				'partial' => false,
			);
		}

		$site_host = self::host_of( get_site_url() );
		$broken    = array();
		$checked   = 0;
		$partial   = false;
		$host_last = array(); // microtime per host for politeness.

		foreach ( $urls as $url => $found_in ) {
			$elapsed = microtime( true ) - $started_at;
			if ( $elapsed >= self::MAX_DURATION_SEC ) {
				$partial = true;
				break;
			}

			$cache_key  = 'shake_connect_link_' . md5( $url );
			$cached     = get_transient( $cache_key );
			if ( false !== $cached ) {
				$checked++;
				if ( is_array( $cached ) && ! empty( $cached['ok'] ) ) {
					continue;
				}
				if ( is_array( $cached ) ) {
					$broken[] = array(
						'url'              => $url,
						'status'           => $cached['status'] ?? null,
						'classification'   => $cached['classification'] ?? 'unknown',
						'found_in'         => array_values( $found_in ),
					);
				}
				continue;
			}

			$host = self::host_of( $url );
			if ( $host && isset( $host_last[ $host ] ) ) {
				$gap = ( microtime( true ) - $host_last[ $host ] ) * 1000;
				if ( $gap < self::HOST_DELAY_MS ) {
					usleep( (int) ( ( self::HOST_DELAY_MS - $gap ) * 1000 ) );
				}
			}

			$result = self::probe( $url, $host === $site_host );
			$host_last[ $host ] = microtime( true );
			$checked++;

			set_transient( $cache_key, $result, self::CACHE_TTL );

			if ( empty( $result['ok'] ) ) {
				$broken[] = array(
					'url'              => $url,
					'status'           => $result['status'] ?? null,
					'classification'   => $result['classification'] ?? 'unknown',
					'found_in'         => array_values( $found_in ),
				);
			}
		}

		$duration_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );

		return array(
			'broken'  => $broken,
			'stats'   => array(
				'checked'      => $checked,
				'broken_count' => count( $broken ),
				'duration_ms'  => $duration_ms,
			),
			'partial' => $partial,
		);
	}

	/**
	 * Probe a single URL via cURL HEAD. Falls back to GET if HEAD returns
	 * 405/501 (some servers reject HEAD).
	 *
	 * @return array{ok:bool,status:int|null,classification:string}
	 */
	private static function probe( $url, $is_internal ) {
		$args = array(
			'method'      => 'HEAD',
			'timeout'     => self::TIMEOUT_SEC,
			'redirection' => 5,
			'user-agent'  => self::USER_AGENT,
			'sslverify'   => true,
		);
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return self::classify_error( $response );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );

		// Some servers don't allow HEAD. Re-probe with GET.
		if ( in_array( $status, array( 405, 501 ), true ) ) {
			$args['method'] = 'GET';
			$response       = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				return self::classify_error( $response );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
		}

		if ( $status >= 200 && $status < 400 ) {
			return array( 'ok' => true, 'status' => $status, 'classification' => 'ok' );
		}
		if ( $status >= 400 && $status < 500 ) {
			return array( 'ok' => false, 'status' => $status, 'classification' => 'broken_4xx' );
		}
		if ( $status >= 500 ) {
			return array( 'ok' => false, 'status' => $status, 'classification' => 'broken_5xx' );
		}
		return array( 'ok' => false, 'status' => $status, 'classification' => 'unknown' );
	}

	private static function classify_error( $wp_error ) {
		$code = $wp_error->get_error_code();
		$msg  = strtolower( (string) $wp_error->get_error_message() );
		if ( false !== strpos( $msg, 'timed out' ) || false !== strpos( $msg, 'timeout' ) || 'http_request_timeout' === $code ) {
			return array( 'ok' => false, 'status' => null, 'classification' => 'timeout' );
		}
		if ( false !== strpos( $msg, 'could not resolve' ) || false !== strpos( $msg, 'name or service' ) || false !== strpos( $msg, 'dns' ) ) {
			return array( 'ok' => false, 'status' => null, 'classification' => 'dns' );
		}
		return array( 'ok' => false, 'status' => null, 'classification' => 'request_failed' );
	}

	/**
	 * Walk recent published posts/pages, parse <a href>, dedupe.
	 *
	 * @return array<string,array<string,mixed>>  URL => list of {post_id, post_title}
	 */
	private static function collect_urls( $limit ) {
		global $wpdb;
		// Direct posts-table read for batch link extraction. WP_Query would
		// materialize 500 full post objects and hydrate metadata we don't
		// need; the direct query is materially cheaper for this scan path.
		// Values are static literals; no user input.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT ID, post_title, post_content, post_excerpt
			   FROM {$wpdb->posts}
			  WHERE post_status = 'publish'
			    AND post_type IN ('post', 'page')
			  ORDER BY post_modified_gmt DESC
			  LIMIT 500"
		);
		// phpcs:enable

		$urls = array();
		foreach ( (array) $rows as $row ) {
			$haystack = (string) $row->post_content . "\n" . (string) $row->post_excerpt;
			if ( '' === trim( $haystack ) ) {
				continue;
			}
			if ( ! preg_match_all( '/<a\s+[^>]*href\s*=\s*["\']([^"\']+)["\']/i', $haystack, $matches ) ) {
				continue;
			}
			foreach ( $matches[1] as $raw ) {
				$url = self::normalise_url( $raw );
				if ( ! $url ) {
					continue;
				}
				if ( ! isset( $urls[ $url ] ) ) {
					$urls[ $url ] = array();
				}
				$key = (int) $row->ID;
				if ( ! isset( $urls[ $url ][ $key ] ) ) {
					$urls[ $url ][ $key ] = array(
						'post_id'    => (int) $row->ID,
						'post_title' => (string) $row->post_title,
					);
				}
				if ( count( $urls ) >= $limit ) {
					break 2;
				}
			}
		}
		return $urls;
	}

	private static function normalise_url( $raw ) {
		$raw = trim( html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $raw ) {
			return null;
		}
		if ( 0 === stripos( $raw, 'mailto:' ) || 0 === stripos( $raw, 'tel:' ) || 0 === stripos( $raw, 'javascript:' ) || 0 === strpos( $raw, '#' ) ) {
			return null;
		}
		// Resolve protocol-relative URLs.
		if ( 0 === strpos( $raw, '//' ) ) {
			$raw = 'https:' . $raw;
		}
		// Resolve site-root relative.
		if ( 0 === strpos( $raw, '/' ) ) {
			$raw = rtrim( get_site_url(), '/' ) . $raw;
		}
		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			return null;
		}
		// Strip fragment.
		$hash = strpos( $raw, '#' );
		if ( false !== $hash ) {
			$raw = substr( $raw, 0, $hash );
		}
		if ( strlen( $raw ) > 2048 ) {
			return null;
		}
		return $raw;
	}

	private static function host_of( $url ) {
		$parts = wp_parse_url( $url );
		return isset( $parts['host'] ) ? strtolower( $parts['host'] ) : null;
	}
}
