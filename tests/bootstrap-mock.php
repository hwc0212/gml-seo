<?php
/**
 * Self-contained WordPress mock for GML AI SEO integration tests.
 *
 * Only implements the primitives the seo-plugin-migration code paths
 * exercise. When new code needs a WP function, add a stub here.
 *
 * @package GML_SEO
 * @since   1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

// ── Shared in-memory state ────────────────────────────────────────────

class GML_SEO_Mock {
	/** @var array<string,mixed> */
	public static $options = [];

	/**
	 * postmeta[post_id][meta_key] = [ value, ... ]  (WP stores arrays)
	 *
	 * @var array<int,array<string,array<int,mixed>>>
	 */
	public static $postmeta = [];

	/** @var array<int,string> */
	public static $post_titles = [];

	/** @var array<string,array<int,callable>> hook => callables */
	public static $hooks = [];

	public static function reset() {
		self::$options     = [];
		self::$postmeta    = [];
		self::$post_titles = [];
		self::$hooks       = [];
		// Reset detector cache too.
		if ( class_exists( 'GML_SEO_Conflict_Detector' ) ) {
			GML_SEO_Conflict_Detector::reset_cache();
		}
	}

	/**
	 * Convenience single-value read (WP get_post_meta($pid,$key,true) semantics).
	 */
	public static function get_post_meta( $pid, $key ) {
		$bucket = self::$postmeta[ $pid ][ $key ] ?? [];
		return is_array( $bucket ) && ! empty( $bucket ) ? reset( $bucket ) : '';
	}
}

// ── Options API ───────────────────────────────────────────────────────

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, GML_SEO_Mock::$options ) ? GML_SEO_Mock::$options[ $key ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		GML_SEO_Mock::$options[ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		unset( GML_SEO_Mock::$options[ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( $key, $default = false ) {
		return get_option( $key, $default );
	}
}

// ── Post meta API ─────────────────────────────────────────────────────

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$all = GML_SEO_Mock::$postmeta[ $post_id ] ?? [];
		if ( $key === '' ) return $all;
		$bucket = $all[ $key ] ?? [];
		if ( $single ) {
			return is_array( $bucket ) && ! empty( $bucket ) ? reset( $bucket ) : '';
		}
		return is_array( $bucket ) ? $bucket : [];
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		GML_SEO_Mock::$postmeta[ $post_id ][ $key ] = [ $value ];
		return true;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key ) {
		unset( GML_SEO_Mock::$postmeta[ $post_id ][ $key ] );
		return true;
	}
}

// ── Posts API ─────────────────────────────────────────────────────────

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post_id ) {
		return GML_SEO_Mock::$post_titles[ $post_id ] ?? ( 'Post ' . $post_id );
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $what ) {
		return $what === 'name' ? 'Example' : '';
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post_id ) { return 'https://example.com/?p=' . $post_id; }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return date( 'Y-m-d H:i:s' );
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() { return false; }
}

// ── Misc utilities ────────────────────────────────────────────────────

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = [] ) {
		if ( is_array( $args ) ) return array_merge( $defaults, $args );
		return $defaults;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return is_string( $s ) ? trim( $s ) : '';
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) { return is_string( $url ) ? $url : ''; }
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $v ) {
		if ( ! is_string( $v ) ) return $v;
		$u = @unserialize( $v );
		return $u === false && $v !== 'b:0;' ? $v : $u;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) { return abs( intval( $v ) ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

// ── Hook API (no-op for mocked classes) ───────────────────────────────

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
		GML_SEO_Mock::$hooks[ $hook ][] = $cb;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {
		GML_SEO_Mock::$hooks[ $hook ][] = $cb;
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) { return false; }
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook ) { return true; }
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		foreach ( GML_SEO_Mock::$hooks[ $hook ] ?? [] as $cb ) {
			call_user_func_array( $cb, $args );
		}
	}
}

// ── wpdb shim ─────────────────────────────────────────────────────────

if ( ! isset( $GLOBALS[ 'wpdb' ] ) ) {
	$GLOBALS[ 'wpdb' ] = new GML_SEO_Mock_WPDB();
}

class GML_SEO_Mock_WPDB {
	public $prefix   = 'wp_';
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';

	public function prepare( $sql, ...$args ) {
		// Ultra-naive: replace %d / %s in-order. Strings get quoted.
		foreach ( $args as $a ) {
			if ( is_int( $a ) ) {
				$sql = preg_replace( '/%d/', (string) $a, $sql, 1 );
			} else {
				$sql = preg_replace( '/%s/', "'" . addslashes( (string) $a ) . "'", $sql, 1 );
			}
		}
		return $sql;
	}

	/**
	 * Returns DISTINCT post IDs whose postmeta key matches the LIKE pattern
	 * embedded in the SQL. This is good enough for adapters' SELECTs.
	 */
	public function get_col( $sql ) {
		if ( preg_match( "/meta_key LIKE '([^']+)'/", $sql, $m ) ) {
			$pattern    = str_replace( '\\_', '_', $m[ 1 ] );
			$regex_safe = str_replace( [ '%', '_' ], [ '.*', '.' ], preg_quote( $pattern, '#' ) );
			$regex      = '#^' . str_replace( [ '\\.\\*', '\\.' ], [ '.*', '.' ], preg_quote( $pattern, '#' ) ) . '$#';
			// Build regex a simpler way: convert SQL LIKE wildcards to regex.
			$regex = '#^' . strtr(
				preg_quote( $pattern, '#' ),
				[ '%' => '.*', '_' => '.' ]
			) . '$#';

			$ids = [];
			foreach ( GML_SEO_Mock::$postmeta as $pid => $keys ) {
				foreach ( array_keys( $keys ) as $k ) {
					if ( preg_match( $regex, $k ) ) {
						$ids[ $pid ] = true;
						break;
					}
				}
			}
			$ids = array_keys( $ids );
			sort( $ids, SORT_NUMERIC );

			// Apply LIMIT / OFFSET if present.
			if ( preg_match( '/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $sql, $mm ) ) {
				$ids = array_slice( $ids, (int) $mm[ 2 ], (int) $mm[ 1 ] );
			} elseif ( preg_match( '/LIMIT\s+(\d+)/i', $sql, $mm ) ) {
				$ids = array_slice( $ids, 0, (int) $mm[ 1 ] );
			}
			return array_map( 'strval', $ids );
		}
		return [];
	}

	public function get_var( $sql ) {
		if ( preg_match( '/COUNT\(DISTINCT post_id\)/i', $sql )
		     && preg_match( "/meta_key LIKE '([^']+)'/", $sql, $m ) ) {
			$pattern = str_replace( '\\_', '_', $m[ 1 ] );
			$regex   = '#^' . strtr(
				preg_quote( $pattern, '#' ),
				[ '%' => '.*', '_' => '.' ]
			) . '$#';
			$n = 0;
			foreach ( GML_SEO_Mock::$postmeta as $pid => $keys ) {
				foreach ( array_keys( $keys ) as $k ) {
					if ( preg_match( $regex, $k ) ) { $n++; break; }
				}
			}
			return $n;
		}
		if ( preg_match( "/SELECT 1 FROM .+meta_key LIKE '([^']+)'/s", $sql, $m ) ) {
			$pattern = str_replace( '\\_', '_', $m[ 1 ] );
			$regex   = '#^' . strtr(
				preg_quote( $pattern, '#' ),
				[ '%' => '.*', '_' => '.' ]
			) . '$#';
			foreach ( GML_SEO_Mock::$postmeta as $pid => $keys ) {
				foreach ( array_keys( $keys ) as $k ) {
					if ( preg_match( $regex, $k ) ) return 1;
				}
			}
			return 0;
		}
		return 0;
	}

	public function get_row( $sql, $format = null ) { return null; }
	public function query( $sql ) { return 0; }
}

// ── Stub global GML_SEO class for option lookups ──────────────────────

if ( ! class_exists( 'GML_SEO' ) ) {
	class GML_SEO {
		public static function opt( $k, $default = '' ) {
			$opts = get_option( 'gml_seo', [] );
			return is_array( $opts ) && array_key_exists( $k, $opts ) ? $opts[ $k ] : $default;
		}
		public static function has_ai_key() { return false; }
	}
}

// mb_* helpers — native in most builds, but guard.
if ( ! function_exists( 'mb_strlen' ) )  { function mb_strlen( $s ) { return strlen( $s ); } }
if ( ! function_exists( 'mb_substr' ) )  { function mb_substr( $s, $o, $l ) { return substr( $s, $o, $l ); } }
if ( ! function_exists( 'mb_check_encoding' ) ) { function mb_check_encoding( $s, $e ) { return true; } }
if ( ! function_exists( 'mb_convert_encoding' ) ) { function mb_convert_encoding( $s, $to, $from = null ) { return $s; } }
