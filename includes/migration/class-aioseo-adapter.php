<?php
/**
 * All in One SEO (AIOSEO) Migration Adapter
 *
 * Merges two data sources: the custom `{$wpdb->prefix}aioseo_posts` table
 * (primary) and the legacy `_aioseo_*` post meta (fallback). Handles
 * AIOSEO's `#post_title`, `#site_title`, `#separator` variable
 * replacement; the JSON `keywords` → CSV conversion; and
 * `keyphrases.focus.keyphrase` JSON extraction.
 *
 * Hard invariants inherited from GML_SEO_Migration_Adapter:
 *   1. Read-only on `_aioseo_*` meta AND `{$wpdb->prefix}aioseo_posts`
 *      (strict SELECT only — no INSERT/UPDATE/DELETE against that table).
 *   2. Idempotent via `_gml_seo_migrated_from` marker.
 *   3. Never deletes source data.
 *
 * @package GML_SEO
 * @see design.md §3.3, §6.3.4, §6.4
 * @see requirements.md §10.3, §10.4, §8.3
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Final class GML_SEO_AIOSEO_Adapter
 */
final class GML_SEO_AIOSEO_Adapter implements GML_SEO_Migration_Adapter {

	const LEN_TITLE    = 60;
	const LEN_DESC     = 160;
	const LEN_OG_TITLE = 70;
	const LEN_OG_DESC  = 160;

	/**
	 * @inheritDoc
	 */
	public function slug(): string {
		return 'aioseo';
	}

	/**
	 * @inheritDoc
	 */
	public function name(): string {
		return 'All in One SEO';
	}

	/**
	 * Whether the AIOSEO custom table exists.
	 *
	 * @return bool
	 */
	private function has_custom_table(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * @inheritDoc
	 */
	public function is_available(): bool {
		global $wpdb;
		if ( $this->has_custom_table() ) {
			$table = $wpdb->prefix . 'aioseo_posts';
			$row   = (int) $wpdb->get_var( "SELECT 1 FROM {$table} LIMIT 1" );
			if ( $row === 1 ) {
				return true;
			}
		}
		$legacy = (int) $wpdb->get_var(
			"SELECT 1 FROM {$wpdb->postmeta}
			 WHERE meta_key LIKE '\\_aioseo\\_%'
			 LIMIT 1"
		);
		return $legacy === 1;
	}

	/**
	 * @inheritDoc
	 */
	public function count_posts(): int {
		global $wpdb;
		$ids_from_table = [];
		if ( $this->has_custom_table() ) {
			$table          = $wpdb->prefix . 'aioseo_posts';
			$ids_from_table = array_map(
				'intval',
				(array) $wpdb->get_col( "SELECT DISTINCT post_id FROM {$table}" )
			);
		}
		$ids_from_meta = array_map(
			'intval',
			(array) $wpdb->get_col(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key LIKE '\\_aioseo\\_%'"
			)
		);
		$merged = array_unique( array_merge( $ids_from_table, $ids_from_meta ) );
		return count( $merged );
	}

	/**
	 * @inheritDoc
	 */
	public function map_post( int $post_id ): array {
		$row = $this->fetch_custom_row( $post_id );

		$title = $this->pick_string(
			$row['title'] ?? '',
			get_post_meta( $post_id, '_aioseo_title', true )
		);
		$desc = $this->pick_string(
			$row['description'] ?? '',
			get_post_meta( $post_id, '_aioseo_description', true )
		);

		$keywords_primary = $this->normalise_keywords_json( $row['keywords'] ?? '' );
		$keywords_meta    = (string) get_post_meta( $post_id, '_aioseo_keywords', true );
		$keywords         = $this->pick_string( $keywords_primary, $keywords_meta );

		$primary_kw = $this->extract_focus_keyphrase( $row['keyphrases'] ?? '' );

		$canonical  = is_string( $row['canonical_url'] ?? '' ) ? $row['canonical_url'] : '';
		$og_title   = is_string( $row['og_title'] ?? '' ) ? $row['og_title'] : '';
		$og_desc    = is_string( $row['og_description'] ?? '' ) ? $row['og_description'] : '';
		$robots_nix = $row['robots_noindex'] ?? '';

		return [
			[
				'source_key'   => 'aioseo_posts.title',
				'source_value' => $row['title'] ?? '',
				'target_key'   => '_gml_seo_title',
				'target_value' => $this->normalise( $title, $post_id, self::LEN_TITLE ),
			],
			[
				'source_key'   => 'aioseo_posts.description',
				'source_value' => $row['description'] ?? '',
				'target_key'   => '_gml_seo_desc',
				'target_value' => $this->normalise( $desc, $post_id, self::LEN_DESC ),
			],
			[
				'source_key'   => 'aioseo_posts.keywords',
				'source_value' => $row['keywords'] ?? '',
				'target_key'   => '_gml_seo_keywords',
				'target_value' => $keywords,
			],
			[
				'source_key'   => 'aioseo_posts.keyphrases',
				'source_value' => $row['keyphrases'] ?? '',
				'target_key'   => '_gml_seo_primary_kw',
				'target_value' => $primary_kw,
			],
			[
				'source_key'   => 'aioseo_posts.canonical_url',
				'source_value' => $canonical,
				'target_key'   => '_gml_seo_canonical',
				'target_value' => $canonical !== '' ? esc_url_raw( $canonical ) : '',
			],
			[
				'source_key'   => 'aioseo_posts.og_title',
				'source_value' => $og_title,
				'target_key'   => '_gml_seo_og_title',
				'target_value' => $this->normalise( $og_title, $post_id, self::LEN_OG_TITLE ),
			],
			[
				'source_key'   => 'aioseo_posts.og_description',
				'source_value' => $og_desc,
				'target_key'   => '_gml_seo_og_desc',
				'target_value' => $this->normalise( $og_desc, $post_id, self::LEN_OG_DESC ),
			],
			[
				'source_key'   => 'aioseo_posts.robots_noindex',
				'source_value' => $robots_nix,
				'target_key'   => '_gml_seo_noindex',
				'target_value' => ( (int) $robots_nix === 1 ) ? 1 : '',
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function migrate_batch( int $offset, int $limit ): int {
		global $wpdb;
		$offset  = max( 0, $offset );
		$limit   = max( 1, min( 500, $limit ) );
		$written = 0;

		// Build a unique, sorted candidate list from BOTH sources. SELECT only.
		$ids_table = [];
		if ( $this->has_custom_table() ) {
			$table     = $wpdb->prefix . 'aioseo_posts';
			$ids_table = array_map(
				'intval',
				(array) $wpdb->get_col( "SELECT DISTINCT post_id FROM {$table}" )
			);
		}
		$ids_meta = array_map(
			'intval',
			(array) $wpdb->get_col(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key LIKE '\\_aioseo\\_%'"
			)
		);
		$candidates = array_values( array_unique( array_merge( $ids_table, $ids_meta ) ) );
		sort( $candidates, SORT_NUMERIC );
		$batch = array_slice( $candidates, $offset, $limit );

		foreach ( $batch as $pid ) {
			$pid = (int) $pid;
			if ( get_post_meta( $pid, '_gml_seo_migrated_from', true ) ) {
				continue;
			}
			try {
				foreach ( $this->map_post( $pid ) as $row ) {
					if ( $row['target_value'] === '' || $row['target_value'] === null ) {
						continue;
					}
					update_post_meta( $pid, $row['target_key'], $row['target_value'] );
				}
				update_post_meta( $pid, '_gml_seo_migrated_from', $this->slug() );
				update_post_meta( $pid, '_gml_seo_migrated_at', current_time( 'mysql' ) );
				$written++;
			} catch ( \Throwable $e ) {
				if ( class_exists( 'GML_SEO_Migration_Manager' ) ) {
					GML_SEO_Migration_Manager::update_state( [
						'last_error' => sprintf( '[aioseo] post %d: %s', $pid, $e->getMessage() ),
					] );
				}
			}
		}

		return $written;
	}

	/**
	 * @inheritDoc
	 *
	 * AIOSEO separator lives in its own option structure; we leave it
	 * untouched rather than risk misinterpreting a different schema.
	 */
	public function migrate_globals(): void {
		// No global option mapping required for AIOSEO.
	}

	// ── internal helpers ───────────────────────────────────────────────

	/**
	 * Fetch one row from the AIOSEO custom table for the given post.
	 * Read-only SELECT; returns an empty array if no row exists.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function fetch_custom_row( int $post_id ): array {
		if ( ! $this->has_custom_table() ) {
			return [];
		}
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", $post_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : [];
	}

	/**
	 * Choose the primary value if non-empty, otherwise fall back.
	 *
	 * @param mixed $primary
	 * @param mixed $fallback
	 * @return string
	 */
	private function pick_string( $primary, $fallback ): string {
		if ( is_string( $primary ) && $primary !== '' ) {
			return $primary;
		}
		return is_string( $fallback ) ? $fallback : '';
	}

	/**
	 * AIOSEO stores keywords as a JSON array of `{label,value}` objects.
	 * Convert to comma-separated string.
	 *
	 * @param mixed $raw Raw JSON / string value from the custom table.
	 * @return string
	 */
	private function normalise_keywords_json( $raw ): string {
		if ( ! is_string( $raw ) || $raw === '' ) {
			return '';
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return $raw;
		}
		$out = [];
		foreach ( $decoded as $item ) {
			if ( is_array( $item ) && isset( $item['value'] ) && is_string( $item['value'] ) ) {
				$out[] = trim( $item['value'] );
			} elseif ( is_string( $item ) ) {
				$out[] = trim( $item );
			}
		}
		$out = array_filter( $out, static function ( $s ) { return $s !== ''; } );
		return implode( ', ', $out );
	}

	/**
	 * Extract the focus keyphrase from AIOSEO's `keyphrases` JSON blob.
	 *
	 * Schema (AIOSEO Pro v4+): `{"focus":{"keyphrase":"..."}, "additional":[]}`.
	 *
	 * @param mixed $raw Raw JSON string.
	 * @return string
	 */
	private function extract_focus_keyphrase( $raw ): string {
		if ( ! is_string( $raw ) || $raw === '' ) {
			return '';
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		$focus = $decoded['focus']['keyphrase'] ?? '';
		return is_string( $focus ) ? trim( $focus ) : '';
	}

	/**
	 * Normalise + replace AIOSEO `#`-prefixed variables.
	 *
	 * @param string $value      Raw value (post-merge).
	 * @param int    $post_id    Post ID.
	 * @param int    $length_cap 0 = no cap.
	 * @return string
	 */
	private function normalise( string $value, int $post_id, int $length_cap ): string {
		if ( $value === '' ) {
			return '';
		}
		$str = $value;
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $str, 'UTF-8' ) ) {
			$converted = @mb_convert_encoding( $str, 'UTF-8', 'auto' );
			if ( is_string( $converted ) ) {
				$str = $converted;
			}
		}

		$str = $this->replace_vars( $str, $post_id );

		if ( $length_cap > 0 && function_exists( 'mb_strlen' ) && mb_strlen( $str ) > $length_cap ) {
			$str = mb_substr( $str, 0, $length_cap );
		}
		return $str;
	}

	/**
	 * Replace AIOSEO's most common template variables.
	 *
	 * @param string $str     Template string.
	 * @param int    $post_id Post being rendered.
	 * @return string
	 */
	private function replace_vars( string $str, int $post_id ): string {
		$replacements = [
			'#post_title'   => get_the_title( $post_id ),
			'#site_title'   => get_bloginfo( 'name' ),
			'#separator_sa' => (string) ( GML_SEO::opt( 'separator', '-' ) ?: '-' ),
			'#separator'    => (string) ( GML_SEO::opt( 'separator', '-' ) ?: '-' ),
			'#tagline'      => get_bloginfo( 'description' ),
		];
		return strtr( $str, $replacements );
	}
}
