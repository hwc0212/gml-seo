<?php
/**
 * Yoast SEO Migration Adapter
 *
 * Maps Yoast post meta (`_yoast_wpseo_*`) and the `wpseo_titles` option
 * into the `_gml_seo_*` namespace. Handles Yoast's `%%title%%` /
 * `%%sitename%%` / `%%sep%%` variable replacement, per-field length
 * truncation, Twitter → OG fallback, and noindex mapping.
 *
 * Hard invariants inherited from GML_SEO_Migration_Adapter:
 *   1. Read-only on `_yoast_wpseo_*` and `wpseo_titles`.
 *   2. Idempotent via `_gml_seo_migrated_from` marker.
 *   3. Never deletes source data.
 *
 * @package GML_SEO
 * @see design.md §3.3, §6.3.1, §6.4
 * @see requirements.md §9.*, §8.1, §8.2
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Final class GML_SEO_Yoast_Adapter
 */
final class GML_SEO_Yoast_Adapter implements GML_SEO_Migration_Adapter {

	/** Per-field length caps (design.md §6.3.1). */
	const LEN_TITLE    = 60;
	const LEN_DESC     = 160;
	const LEN_OG_TITLE = 70;
	const LEN_OG_DESC  = 160;

	/**
	 * Yoast's `wpseo_titles.separator` value is an identifier (e.g.
	 * "sc-dash") rather than the actual character. This table maps it
	 * back to a real glyph.
	 *
	 * @var array<string,string>
	 */
	private static $separator_map = [
		'sc-dash'   => '-',
		'sc-ndash'  => '–',
		'sc-mdash'  => '—',
		'sc-middot' => '·',
		'sc-bull'   => '•',
		'sc-star'   => '*',
		'sc-smstar' => '⋆',
		'sc-pipe'   => '|',
		'sc-tilde'  => '~',
		'sc-laquo'  => '«',
		'sc-raquo'  => '»',
		'sc-lt'     => '<',
		'sc-gt'     => '>',
	];

	/**
	 * @inheritDoc
	 */
	public function slug(): string {
		return 'yoast';
	}

	/**
	 * @inheritDoc
	 */
	public function name(): string {
		return 'Yoast SEO';
	}

	/**
	 * @inheritDoc
	 *
	 * Yoast leaves `_yoast_wpseo_*` meta on posts even after the plugin
	 * is deactivated, so presence of any such meta is enough.
	 */
	public function is_available(): bool {
		global $wpdb;
		$exists = (int) $wpdb->get_var(
			"SELECT 1 FROM {$wpdb->postmeta}
			 WHERE meta_key LIKE '\\_yoast\\_wpseo\\_%'
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
			 WHERE meta_key LIKE '\\_yoast\\_wpseo\\_%'"
		);
	}

	/**
	 * @inheritDoc
	 *
	 * Returns the mapping array used by both `preview` and `migrate_batch`.
	 * This method is read-only and has no side effects. Property 6 (field
	 * mapping consistency) relies on preview/execute returning the same
	 * target set for a given post_id.
	 */
	public function map_post( int $post_id ): array {
		$mapping = [];

		// ── Title ──────────────────────────────────────────────
		$mapping[] = $this->row(
			$post_id,
			'_yoast_wpseo_title',
			'_gml_seo_title',
			self::LEN_TITLE
		);

		// ── Description ───────────────────────────────────────
		$mapping[] = $this->row(
			$post_id,
			'_yoast_wpseo_metadesc',
			'_gml_seo_desc',
			self::LEN_DESC
		);

		// ── Focus keyword ─────────────────────────────────────
		$mapping[] = $this->row(
			$post_id,
			'_yoast_wpseo_focuskw',
			'_gml_seo_primary_kw',
			0
		);

		// ── OG title (Twitter fallback) ───────────────────────
		$og_title_src = get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true );
		if ( $og_title_src === '' || $og_title_src === null ) {
			$og_title_src = get_post_meta( $post_id, '_yoast_wpseo_twitter-title', true );
			$og_title_key = '_yoast_wpseo_twitter-title';
		} else {
			$og_title_key = '_yoast_wpseo_opengraph-title';
		}
		$mapping[] = [
			'source_key'   => $og_title_key,
			'source_value' => $og_title_src,
			'target_key'   => '_gml_seo_og_title',
			'target_value' => $this->normalise( $og_title_src, $post_id, self::LEN_OG_TITLE ),
		];

		// ── OG description (Twitter fallback) ─────────────────
		$og_desc_src = get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true );
		if ( $og_desc_src === '' || $og_desc_src === null ) {
			$og_desc_src = get_post_meta( $post_id, '_yoast_wpseo_twitter-description', true );
			$og_desc_key = '_yoast_wpseo_twitter-description';
		} else {
			$og_desc_key = '_yoast_wpseo_opengraph-description';
		}
		$mapping[] = [
			'source_key'   => $og_desc_key,
			'source_value' => $og_desc_src,
			'target_key'   => '_gml_seo_og_desc',
			'target_value' => $this->normalise( $og_desc_src, $post_id, self::LEN_OG_DESC ),
		];

		// ── Canonical ─────────────────────────────────────────
		$canonical = get_post_meta( $post_id, '_yoast_wpseo_canonical', true );
		$mapping[] = [
			'source_key'   => '_yoast_wpseo_canonical',
			'source_value' => $canonical,
			'target_key'   => '_gml_seo_canonical',
			'target_value' => $canonical ? esc_url_raw( $canonical ) : '',
		];

		// ── Noindex ────────────────────────────────────────────
		// Yoast stores '1' → noindex, '2' → index (explicit), '' → inherit.
		$noindex_src = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		$noindex_val = ( $noindex_src === '1' || $noindex_src === 1 ) ? 1 : '';
		$mapping[]   = [
			'source_key'   => '_yoast_wpseo_meta-robots-noindex',
			'source_value' => $noindex_src,
			'target_key'   => '_gml_seo_noindex',
			'target_value' => $noindex_val,
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
			 WHERE meta_key LIKE '\\_yoast\\_wpseo\\_%'
			 ORDER BY post_id ASC
			 LIMIT %d OFFSET %d",
			$limit,
			$offset
		) );

		foreach ( $ids as $pid ) {
			$pid = (int) $pid;
			if ( get_post_meta( $pid, '_gml_seo_migrated_from', true ) ) {
				continue; // idempotent skip
			}

			try {
				$mapping = $this->map_post( $pid );
				foreach ( $mapping as $row ) {
					if ( $row['target_value'] === '' || $row['target_value'] === null ) {
						continue;
					}
					update_post_meta( $pid, $row['target_key'], $row['target_value'] );
				}
				update_post_meta( $pid, '_gml_seo_migrated_from', $this->slug() );
				update_post_meta( $pid, '_gml_seo_migrated_at', current_time( 'mysql' ) );
				$written++;
			} catch ( \Throwable $e ) {
				// Per-post failure is logged in state but doesn't break the batch.
				if ( class_exists( 'GML_SEO_Migration_Manager' ) ) {
					GML_SEO_Migration_Manager::update_state( [
						'last_error' => sprintf( '[yoast] post %d: %s', $pid, $e->getMessage() ),
					] );
				}
			}
		}

		return $written;
	}

	/**
	 * @inheritDoc
	 *
	 * Migrates `wpseo_titles.separator` → `gml_seo.separator` (converting
	 * Yoast's "sc-dash"-style identifier back to the actual glyph).
	 */
	public function migrate_globals(): void {
		$titles = get_option( 'wpseo_titles', [] );
		if ( ! is_array( $titles ) ) {
			return;
		}
		$raw = $titles['separator'] ?? '';
		if ( ! is_string( $raw ) || $raw === '' ) {
			return;
		}
		$glyph = self::$separator_map[ $raw ] ?? $raw;

		$opts = get_option( 'gml_seo', [] );
		if ( ! is_array( $opts ) ) {
			$opts = [];
		}
		$opts['separator'] = $glyph;
		update_option( 'gml_seo', $opts );
	}

	// ── internal helpers ───────────────────────────────────────────────

	/**
	 * Build a mapping row for a post meta → post meta transform with an
	 * optional length cap.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $source_key Yoast meta key.
	 * @param string $target_key GML meta key.
	 * @param int    $length_cap 0 = no cap, >0 = char cap after normalisation.
	 * @return array
	 */
	private function row( int $post_id, string $source_key, string $target_key, int $length_cap ): array {
		$src = get_post_meta( $post_id, $source_key, true );
		return [
			'source_key'   => $source_key,
			'source_value' => $src,
			'target_key'   => $target_key,
			'target_value' => $this->normalise( $src, $post_id, $length_cap ),
		];
	}

	/**
	 * Normalise a Yoast source value:
	 *   1. Coerce to a string (arrays / objects become empty string).
	 *   2. Fix non-UTF-8 encoding via mb_convert_encoding.
	 *   3. Replace Yoast variables (%%title%%, %%sitename%%, %%sep%%).
	 *   4. Truncate if a length cap is given.
	 *
	 * @param mixed  $value      Raw meta value.
	 * @param int    $post_id    Post ID, needed for %%title%% expansion.
	 * @param int    $length_cap 0 = no cap.
	 * @return string Cleaned value (may be empty string).
	 */
	private function normalise( $value, int $post_id, int $length_cap ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		$str = (string) $value;
		if ( $str === '' ) {
			return '';
		}

		// Fix encoding if it's not valid UTF-8.
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $str, 'UTF-8' ) ) {
			$converted = @mb_convert_encoding( $str, 'UTF-8', 'auto' );
			if ( is_string( $converted ) ) {
				$str = $converted;
			}
		}

		$str = $this->replace_yoast_vars( $str, $post_id );

		if ( $length_cap > 0 && function_exists( 'mb_strlen' ) && mb_strlen( $str ) > $length_cap ) {
			$str = mb_substr( $str, 0, $length_cap );
		}

		return $str;
	}

	/**
	 * Replace Yoast's three most common template variables with actual
	 * post / site values. Unknown variables are left intact (documented
	 * as a non-goal in requirements §6).
	 *
	 * @param string $str     Template string.
	 * @param int    $post_id Post being rendered.
	 * @return string
	 */
	private function replace_yoast_vars( string $str, int $post_id ): string {
		$replacements = [
			'%%title%%'    => get_the_title( $post_id ),
			'%%sitename%%' => get_bloginfo( 'name' ),
			'%%page%%'     => '',
			'%%sep%%'      => (string) ( GML_SEO::opt( 'separator', '-' ) ?: '-' ),
		];
		return strtr( $str, $replacements );
	}
}
