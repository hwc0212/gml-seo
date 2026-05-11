<?php
/**
 * The SEO Framework Migration Adapter
 *
 * Maps The SEO Framework (TSF) post meta (`_genesis_*`, `_open_graph_*`)
 * — inherited from the Genesis Framework — into the `_gml_seo_*`
 * namespace.
 *
 * Hard invariants inherited from GML_SEO_Migration_Adapter:
 *   1. Read-only on `_genesis_*` and `_open_graph_*`.
 *   2. Idempotent via `_gml_seo_migrated_from` marker.
 *   3. Never deletes source data.
 *
 * @package GML_SEO
 * @see design.md §3.3, §6.3.5, §6.4
 * @see requirements.md §10.5, §8.1
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Final class GML_SEO_SEOFramework_Adapter
 */
final class GML_SEO_SEOFramework_Adapter implements GML_SEO_Migration_Adapter {

	const LEN_TITLE    = 60;
	const LEN_DESC     = 160;
	const LEN_OG_TITLE = 70;
	const LEN_OG_DESC  = 160;

	/**
	 * @inheritDoc
	 */
	public function slug(): string {
		return 'seoframework';
	}

	/**
	 * @inheritDoc
	 */
	public function name(): string {
		return 'The SEO Framework';
	}

	/**
	 * @inheritDoc
	 *
	 * Note: we match BOTH `_genesis_*` and `_open_graph_*` because either
	 * set means TSF (or Genesis) has written data for this post.
	 */
	public function is_available(): bool {
		global $wpdb;
		$exists = (int) $wpdb->get_var(
			"SELECT 1 FROM {$wpdb->postmeta}
			 WHERE meta_key LIKE '\\_genesis\\_%'
			    OR meta_key LIKE '\\_open\\_graph\\_%'
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
			 WHERE meta_key LIKE '\\_genesis\\_%'
			    OR meta_key LIKE '\\_open\\_graph\\_%'"
		);
	}

	/**
	 * @inheritDoc
	 */
	public function map_post( int $post_id ): array {
		$mapping = [];

		$mapping[] = $this->row( $post_id, '_genesis_title', '_gml_seo_title', self::LEN_TITLE );
		$mapping[] = $this->row( $post_id, '_genesis_description', '_gml_seo_desc', self::LEN_DESC );
		$mapping[] = $this->row( $post_id, '_open_graph_title', '_gml_seo_og_title', self::LEN_OG_TITLE );
		$mapping[] = $this->row( $post_id, '_open_graph_description', '_gml_seo_og_desc', self::LEN_OG_DESC );

		$canonical = get_post_meta( $post_id, '_genesis_canonical_uri', true );
		$mapping[] = [
			'source_key'   => '_genesis_canonical_uri',
			'source_value' => $canonical,
			'target_key'   => '_gml_seo_canonical',
			'target_value' => is_string( $canonical ) && $canonical !== '' ? esc_url_raw( $canonical ) : '',
		];

		$noindex_raw = get_post_meta( $post_id, '_genesis_noindex', true );
		$mapping[]   = [
			'source_key'   => '_genesis_noindex',
			'source_value' => $noindex_raw,
			'target_key'   => '_gml_seo_noindex',
			'target_value' => ( $noindex_raw === '1' || $noindex_raw === 1 ) ? 1 : '',
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
			 WHERE meta_key LIKE '\\_genesis\\_%'
			    OR meta_key LIKE '\\_open\\_graph\\_%'
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
						'last_error' => sprintf( '[seoframework] post %d: %s', $pid, $e->getMessage() ),
					] );
				}
			}
		}

		return $written;
	}

	/**
	 * @inheritDoc
	 *
	 * TSF's global options live in `the_seo_framework_site_options`;
	 * we don't migrate them for now — separator logic is not deterministic
	 * enough across TSF versions to be worth the risk.
	 */
	public function migrate_globals(): void {
		// No global migration for TSF at this stage.
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
