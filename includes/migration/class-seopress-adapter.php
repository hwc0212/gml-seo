<?php
/**
 * SEOPress Migration Adapter
 *
 * Maps SEOPress post meta (`_seopress_*`) into the `_gml_seo_*` namespace.
 * Handles focus-keyword first-item extraction and the
 * `_seopress_robots_index == 'yes'` → noindex conversion.
 *
 * Hard invariants inherited from GML_SEO_Migration_Adapter:
 *   1. Read-only on `_seopress_*`.
 *   2. Idempotent via `_gml_seo_migrated_from` marker.
 *   3. Never deletes source data.
 *
 * @package GML_SEO
 * @see design.md §3.3, §6.3.3, §6.4
 * @see requirements.md §10.2, §8.1
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Final class GML_SEO_SEOPress_Adapter
 */
final class GML_SEO_SEOPress_Adapter implements GML_SEO_Migration_Adapter {

	const LEN_TITLE    = 60;
	const LEN_DESC     = 160;
	const LEN_OG_TITLE = 70;
	const LEN_OG_DESC  = 160;

	/**
	 * @inheritDoc
	 */
	public function slug(): string {
		return 'seopress';
	}

	/**
	 * @inheritDoc
	 */
	public function name(): string {
		return 'SEOPress';
	}

	/**
	 * @inheritDoc
	 */
	public function is_available(): bool {
		global $wpdb;
		$exists = (int) $wpdb->get_var(
			"SELECT 1 FROM {$wpdb->postmeta}
			 WHERE meta_key LIKE '\\_seopress\\_%'
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
			 WHERE meta_key LIKE '\\_seopress\\_%'"
		);
	}

	/**
	 * @inheritDoc
	 */
	public function map_post( int $post_id ): array {
		$mapping = [];

		$mapping[] = $this->row( $post_id, '_seopress_titles_title', '_gml_seo_title', self::LEN_TITLE );
		$mapping[] = $this->row( $post_id, '_seopress_titles_desc', '_gml_seo_desc', self::LEN_DESC );

		$kw_raw = get_post_meta( $post_id, '_seopress_analysis_target_kw', true );
		$kw     = '';
		if ( is_string( $kw_raw ) && $kw_raw !== '' ) {
			$parts = explode( ',', $kw_raw );
			$kw    = trim( (string) ( $parts[0] ?? '' ) );
		}
		$mapping[] = [
			'source_key'   => '_seopress_analysis_target_kw',
			'source_value' => $kw_raw,
			'target_key'   => '_gml_seo_primary_kw',
			'target_value' => $kw,
		];

		$mapping[] = $this->row( $post_id, '_seopress_social_fb_title', '_gml_seo_og_title', self::LEN_OG_TITLE );
		$mapping[] = $this->row( $post_id, '_seopress_social_fb_desc', '_gml_seo_og_desc', self::LEN_OG_DESC );

		$canonical = get_post_meta( $post_id, '_seopress_robots_canonical', true );
		$mapping[] = [
			'source_key'   => '_seopress_robots_canonical',
			'source_value' => $canonical,
			'target_key'   => '_gml_seo_canonical',
			'target_value' => is_string( $canonical ) && $canonical !== '' ? esc_url_raw( $canonical ) : '',
		];

		$noindex_raw = get_post_meta( $post_id, '_seopress_robots_index', true );
		$mapping[]   = [
			'source_key'   => '_seopress_robots_index',
			'source_value' => $noindex_raw,
			'target_key'   => '_gml_seo_noindex',
			'target_value' => ( $noindex_raw === 'yes' ) ? 1 : '',
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
			 WHERE meta_key LIKE '\\_seopress\\_%'
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
						'last_error' => sprintf( '[seopress] post %d: %s', $pid, $e->getMessage() ),
					] );
				}
			}
		}

		return $written;
	}

	/**
	 * @inheritDoc
	 *
	 * SEOPress does not expose a global separator in the same way as
	 * Yoast/Rank Math, so this adapter is a no-op.
	 */
	public function migrate_globals(): void {
		// No global option mapping required for SEOPress.
	}

	// ── internal helpers ───────────────────────────────────────────────

	private function row( int $post_id, string $source_key, string $target_key, int $length_cap ): array {
		$src = get_post_meta( $post_id, $source_key, true );
		return [
			'source_key'   => $source_key,
			'source_value' => $src,
			'target_key'   => $target_key,
			'target_value' => $this->normalise( $src, $length_cap ),
		];
	}

	private function normalise( $value, int $length_cap ): string {
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
		if ( $length_cap > 0 && function_exists( 'mb_strlen' ) && mb_strlen( $str ) > $length_cap ) {
			$str = mb_substr( $str, 0, $length_cap );
		}
		return $str;
	}
}
