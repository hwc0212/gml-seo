<?php
/**
 * Migration Adapter Interface
 *
 * Contract for per-plugin migration adapters. Each adapter reads data from
 * a single legacy SEO plugin (Yoast, Rank Math, SEOPress, AIOSEO, The SEO
 * Framework) and writes it to the `_gml_seo_*` post meta namespace.
 *
 * Hard invariants every implementation MUST uphold:
 *   1. Read-only on source plugin data (never update_* / delete_* source meta
 *      or options; never INSERT/UPDATE/DELETE source custom tables).
 *   2. Idempotent (repeat calls produce identical `_gml_seo_*` value sets).
 *   3. Never deletes source data under any code path (including rollback).
 *
 * @package GML_SEO
 * @see design.md §3.3, §6.3, §6.4
 * @see requirements.md §5.3, §7.1, §7.3, §8.1, §8.2, §8.3
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Interface GML_SEO_Migration_Adapter
 *
 * Implemented by:
 *   - GML_SEO_Yoast_Adapter
 *   - GML_SEO_RankMath_Adapter
 *   - GML_SEO_SEOPress_Adapter
 *   - GML_SEO_AIOSEO_Adapter
 *   - GML_SEO_SEOFramework_Adapter
 */
interface GML_SEO_Migration_Adapter {

	/**
	 * Source plugin slug, matches Conflict_Detector slugs.
	 *
	 * @return string One of 'yoast' | 'rankmath' | 'seopress' | 'aioseo' | 'seoframework'.
	 */
	public function slug(): string;

	/**
	 * Human-readable plugin name (e.g. "Yoast SEO").
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * Whether the source plugin's historical data is detectable
	 * (regardless of whether the plugin is currently active).
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Count posts that carry source-plugin meta. Read-only.
	 *
	 * @return int
	 */
	public function count_posts(): int;

	/**
	 * Return the field mapping for a single post. Read-only.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array<string,array{source_key: string, source_value: mixed, target_key: string, target_value: mixed}>
	 */
	public function map_post( int $post_id ): array;

	/**
	 * Migrate a batch of posts (ascending post_id). Idempotent via the
	 * `_gml_seo_migrated_from` marker: posts that already carry the marker
	 * MUST be skipped. Source data MUST remain untouched.
	 *
	 * @param int $offset Batch start offset.
	 * @param int $limit  Max posts to process in this batch.
	 * @return int Number of posts actually written (does NOT include skipped idempotent ones).
	 */
	public function migrate_batch( int $offset, int $limit ): int;

	/**
	 * Migrate global (site-wide) options once per migration run.
	 * E.g. separator character, homepage title template.
	 *
	 * @return void
	 */
	public function migrate_globals(): void;
}
