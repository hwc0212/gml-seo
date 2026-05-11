<?php
/**
 * Rank Math Migration Adapter
 *
 * Maps Rank Math post meta (`rank_math_*`) and the
 * `rank-math-options-titles` option into the `_gml_seo_*` namespace.
 * Handles `%title%` / `%sitename%` / `%sep%` variable replacement,
 * focus-keyword first-item extraction, and the `rank_math_robots`
 * array → noindex conversion.
 *
 * Hard invariants inherited from GML_SEO_Migration_Adapter:
 *   1. Read-only on `rank_math_*` and `rank-math-options-titles`.
 *   2. Idempotent via `_gml_seo_migrated_from` marker.
 *   3. Never deletes source data.
 *
 * @package GML_SEO
 * @see design.md §3.3, §6.3.2, §6.4
 * @see requirements.md §10.1, §8.1, §8.2
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Final class GML_SEO_RankMath_Adapter
 */
final class GML_SEO_RankMath_Adapter implements GML_SEO_Migration_Adapter {

	const LEN_TITLE    = 60;
	const LEN_DESC     = 160;
	const LEN_OG_TITLE = 70;
	const LEN_OG_DESC  = 160;

	/**
	 * @inheritDoc
	 */
	public function slug(): string {
		return 'rankmath';
	}

	/**
	 * @inheritDoc
	 */
	public function name(): string {
		return 'Rank Math';
	}

	/**
	 * @inheritDoc
	 */
	public function is_available(): bool {
		global $wpdb;
		$exists = (int) $wpdb->get_var(
			"SELECT 1 FROM {$wpdb->postmeta}
			 WHERE meta_key LIKE 'rank\\_math\\_%'
			 LIMIT 1"
		);
		return $exists === 1;
	}

	/**
	 * @inheritDoc
	 */
	public function count_posts(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
			 WHERE meta_key LIKE 'rank\\_math\\_%'"
		);
	}

	/**
	 * @inheritDoc
	 */
	public function map_post( int $post_id ): array {
		$mapping = [];

		$mapping[] = $this->row( $post_id, 'rank_math_title', '_gml_seo_title', self::LEN_TITLE );
		$mapping[] = $this->row( $post_id, 'rank_math_description', '_gml_seo_desc', self::LEN_DESC );

		// Focus keyword — Rank Math stores comma-separated; take the first.
		$fk_raw = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
		$fk     = '';
		if ( is_string( $fk_raw ) && $fk_raw !== '' ) {
			$parts = explode( ',', $fk_raw );
			$fk    = trim( (string) ( $parts[0] ?? '' ) );
		}
		$mapping[] = [
			'source_key'   => 'rank_math_focus_keyword',
			'source_value' => $fk_raw,
			'target_key'   => '_gml_seo_primary_kw',
			'target_value' => $fk,
		];

		$mapping[] = $this->row( $post_id, 'rank_math_facebook_title', '_gml_seo_og_title', self::LEN_OG_TITLE );
		$mapping[] = $this->row( $post_id, 'rank_math_facebook_description', '_gml_seo_og_desc', self::LEN_OG_DESC );

		$canonical = get_post_meta( $post_id, 'rank_math_canonical_url', true );
		$mapping[] = [
			'source_key'   => 'rank_math_canonical_url',
			'source_value' => $canonical,
			'target_key'   => '_gml_seo_canonical',
			'target_value' => is_string( $canonical ) && $canonical !== '' ? esc_url_raw( $canonical ) : '',
		];

		// Robots — Rank Math stores a comma-joined string or array that
		// may contain "noindex".
		$robots_raw = get_post_meta( $post_id, 'rank_math_robots', true );
		$robots_arr = [];
		if ( is_array( $robots_raw ) ) {
			$robots_arr = $robots_raw;
		} elseif ( is_string( $robots_raw ) && $robots_raw !== '' ) {
			// Some RM versions stored it as a serialized array or CSV.
			$unserialised = @maybe_unserialize( $robots_raw );
			if ( is_array( $unserialised ) ) {
				$robots_arr = $unserialised;
			} else {
				$robots_arr = array_map( 'trim', explode( ',', $robots_raw ) );
			}
		}
		$noindex   = in_array( 'noindex', array_map( 'strval', $robots_arr ), true ) ? 1 : '';
		$mapping[] = [
			'source_key'   => 'rank_math_robots',
			'source_value' => $robots_raw,
			'target_key'   => '_gml_seo_noindex',
			'target_value' => $noindex,
		];

		return $mapping;
	}

	/**
	 * @inheritDoc
	 */
	public function migrate_batch( int $offset, int $limit ): int {
		global $wpdb;
		$offset  = max( 0, $offset );
		$limit   = max( 1, min( 500, $limit ) );
		$written = 0;

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key LIKE 'rank\\_math\\_%'
			 ORDER BY post_id ASC
			 LIMIT %d OFFSET %d",
			$limit,
			$offset
		) );

		foreach ( $ids as $pid ) {
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
						'last_error' => sprintf( '[rankmath] post %d: %s', $pid, $e->getMessage() ),
					] );
				}
			}
		}

		return $written;
	}

	/**
	 * @inheritDoc
	 *
	 * Migrates the global separator from `rank-math-options-titles.title_separator`
	 * into `gml_seo.separator`. Rank Math stores the actual character (not
	 * an identifier), so no lookup table is needed.
	 */
	public function migrate_globals(): void {
		$titles = get_option( 'rank-math-options-titles', [] );
		if ( ! is_array( $titles ) ) {
			return;
		}
		$sep = $titles['title_separator'] ?? '';
		if ( ! is_string( $sep ) || $sep === '' ) {
			return;
		}

		$opts = get_option( 'gml_seo', [] );
		if ( ! is_array( $opts ) ) {
			$opts = [];
		}
		$opts['separator'] = $sep;
		update_option( 'gml_seo', $opts );
	}

	// ── internal helpers ───────────────────────────────────────────────

	private function row( int $post_id, string $source_key, string $target_key, int $length_cap ): array {
		$src = get_post_meta( $post_id, $source_key, true );
		return [
			'source_key'   => $source_key,
			'source_value' => $src,
			'target_key'   => $target_key,
			'target_value' => $this->normalise( $src, $post_id, $length_cap ),
		];
	}

	private function normalise( $value, int $post_id, int $length_cap ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		$str = (string) $value;
		if ( $str === '' ) {
			return '';
		}

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

	private function replace_vars( string $str, int $post_id ): string {
		$replacements = [
			'%title%'    => get_the_title( $post_id ),
			'%sitename%' => get_bloginfo( 'name' ),
			'%sep%'      => (string) ( GML_SEO::opt( 'separator', '-' ) ?: '-' ),
			'%page%'     => '',
		];
		return strtr( $str, $replacements );
	}
}
