<?php
/**
 * Gradual Mode Manager (Anti-Penalty Observation Period)
 *
 * Manages the post-migration observation period. While active:
 *   - AI results are routed to the `_gml_seo_suggestion_*` channel and
 *     MUST NOT overwrite `_gml_seo_*` frontend meta.
 *   - Bulk Optimize is disabled at both UI and AJAX layers.
 *   - Metabox renders a side-by-side "migrated vs suggestion" view so the
 *     user can adopt suggestions per post.
 *
 * @package GML_SEO
 * @see design.md §3.4, §6.6, §6.7, §6.9, §6.10
 * @see requirements.md §11.*, §12.*, §13.*, §14.*, §15.3, §15.4
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Final class GML_SEO_Gradual_Mode_Manager
 *
 * All methods are static. Request-time hooks are registered by init().
 */
final class GML_SEO_Gradual_Mode_Manager {

	/**
	 * The five AI-facing suggestion keys that must mirror the frontend
	 * meta keys one-for-one. Score and timestamp live outside this map.
	 *
	 * @var array<string,string> ai-result-key => gml-meta-key
	 */
	private static $core_map = [
		'title'    => '_gml_seo_title',
		'desc'     => '_gml_seo_desc',
		'og_title' => '_gml_seo_og_title',
		'og_desc'  => '_gml_seo_og_desc',
		'keywords' => '_gml_seo_keywords',
	];

	/**
	 * The corresponding suggestion-channel keys.
	 *
	 * @var array<string,string>
	 */
	private static $suggestion_map = [
		'title'    => '_gml_seo_suggestion_title',
		'desc'     => '_gml_seo_suggestion_desc',
		'og_title' => '_gml_seo_suggestion_og_title',
		'og_desc'  => '_gml_seo_suggestion_og_desc',
		'keywords' => '_gml_seo_suggestion_keywords',
	];

	/**
	 * Register AJAX endpoints. Must be called once at plugin boot.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_gml_seo_apply_suggestion',       [ __CLASS__, 'ajax_apply_suggestion' ] );
		add_action( 'wp_ajax_gml_seo_apply_suggestion_field', [ __CLASS__, 'ajax_apply_suggestion_field' ] );
		add_action( 'wp_ajax_gml_seo_gradual_exit',           [ __CLASS__, 'ajax_exit' ] );
	}

	/**
	 * Whether the observation period is active.
	 *
	 * Default after migration completion is ACTIVE; user can opt out via
	 * the Settings tab.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		$opts = get_option( 'gml_seo', [] );
		return ! empty( $opts['gradual_mode'] );
	}

	/**
	 * Enter the observation period. Called from Migration_Manager when
	 * migration status transitions to 'completed'.
	 *
	 * Writes:
	 *   gml_seo[gradual_mode] = 1
	 *   gml_seo[gradual_entered_at] = current_time('mysql')
	 *
	 * @return void
	 */
	public static function enter(): void {
		$opts = get_option( 'gml_seo', [] );
		if ( ! is_array( $opts ) ) {
			$opts = [];
		}
		$opts['gradual_mode']       = 1;
		$opts['gradual_entered_at'] = current_time( 'mysql' );
		update_option( 'gml_seo', $opts );
	}

	/**
	 * Exit the observation period (user-initiated).
	 *
	 * Writes:
	 *   gml_seo[gradual_mode] = 0
	 *   gml_seo[gradual_exited_at] = current_time('mysql')
	 *
	 * @return void
	 */
	public static function exit(): void {
		$opts = get_option( 'gml_seo', [] );
		if ( ! is_array( $opts ) ) {
			$opts = [];
		}
		$opts['gradual_mode']      = 0;
		$opts['gradual_exited_at'] = current_time( 'mysql' );
		update_option( 'gml_seo', $opts );
	}

	/**
	 * Route an AI analysis result.
	 *
	 * When observation period is active, writes to `_gml_seo_suggestion_*`
	 * ONLY and MUST NOT touch `_gml_seo_*` frontend keys.
	 *
	 * @param int   $post_id   Post being analyzed.
	 * @param array $ai_result Structured AI result from the engine.
	 * @return void
	 */
	public static function route_ai_result( int $post_id, array $ai_result ): void {
		if ( ! self::is_active() ) {
			return;
		}

		// Pre-compare: if all five core suggestions equal existing frontend
		// values, skip the write entirely (requirements §12.4).
		$all_same = true;
		foreach ( self::$core_map as $ai_key => $meta_key ) {
			$suggested = isset( $ai_result[ $ai_key ] ) ? (string) $ai_result[ $ai_key ] : '';
			$current   = (string) get_post_meta( $post_id, $meta_key, true );
			if ( $suggested !== '' && $suggested !== $current ) {
				$all_same = false;
				break;
			}
		}
		if ( $all_same ) {
			return;
		}

		foreach ( self::$core_map as $ai_key => $_meta_key ) {
			if ( ! isset( $ai_result[ $ai_key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $ai_result[ $ai_key ] );
			update_post_meta( $post_id, self::$suggestion_map[ $ai_key ], $value );
		}

		if ( isset( $ai_result['score'] ) ) {
			update_post_meta( $post_id, '_gml_seo_suggestion_score', (int) $ai_result['score'] );
		}
		update_post_meta( $post_id, '_gml_seo_suggestion_generated_at', current_time( 'mysql' ) );
	}

	/**
	 * Adopt the whole AI suggestion for a post.
	 *
	 * Copies `_gml_seo_suggestion_*` (title/desc/og_title/og_desc/keywords)
	 * into `_gml_seo_*`, then deletes all suggestion meta keys. Preserves
	 * `_gml_seo_migrated_from` so the user can still roll back.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function apply_suggestion( int $post_id ): void {
		foreach ( self::$core_map as $ai_key => $meta_key ) {
			$sug_key   = self::$suggestion_map[ $ai_key ];
			$sug_value = get_post_meta( $post_id, $sug_key, true );
			if ( $sug_value === '' || $sug_value === null ) {
				continue;
			}
			update_post_meta( $post_id, $meta_key, $sug_value );
		}
		self::delete_all_suggestion_keys( $post_id );
	}

	/**
	 * Adopt a single suggestion field.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   One of 'title'|'desc'|'og_title'|'og_desc'|'keywords'.
	 * @return void
	 */
	public static function apply_suggestion_field( int $post_id, string $field ): void {
		if ( ! isset( self::$core_map[ $field ] ) ) {
			return;
		}
		$sug_key = self::$suggestion_map[ $field ];
		$value   = get_post_meta( $post_id, $sug_key, true );
		if ( $value === '' || $value === null ) {
			return;
		}
		update_post_meta( $post_id, self::$core_map[ $field ], $value );
		delete_post_meta( $post_id, $sug_key );
	}

	/**
	 * Whether Bulk Optimize is allowed. Returns false whenever the
	 * observation period is active.
	 *
	 * @return bool
	 */
	public static function bulk_optimize_allowed(): bool {
		return ! self::is_active();
	}

	/**
	 * Side-by-side payload for the Metabox "migrated vs suggestion" view.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_side_by_side( int $post_id ): array {
		$payload = [
			'migrated_from'  => (string) get_post_meta( $post_id, '_gml_seo_migrated_from', true ),
			'migrated_at'    => (string) get_post_meta( $post_id, '_gml_seo_migrated_at', true ),
			'has_suggestion' => false,
			'suggestion_at'  => (string) get_post_meta( $post_id, '_gml_seo_suggestion_generated_at', true ),
			'fields'         => [],
			'scores'         => [
				'current'    => (int) get_post_meta( $post_id, '_gml_seo_score', true ),
				'suggestion' => (int) get_post_meta( $post_id, '_gml_seo_suggestion_score', true ),
			],
		];

		foreach ( self::$core_map as $ai_key => $meta_key ) {
			$current    = (string) get_post_meta( $post_id, $meta_key, true );
			$suggestion = (string) get_post_meta( $post_id, self::$suggestion_map[ $ai_key ], true );
			$payload['fields'][ $ai_key ] = [
				'current'    => $current,
				'suggestion' => $suggestion,
			];
			if ( $suggestion !== '' ) {
				$payload['has_suggestion'] = true;
			}
		}

		return $payload;
	}

	/**
	 * Aggregate stats for the weekly digest (Health Monitor).
	 *
	 * @return array {
	 *     @type int   migrated_total       Posts bearing _gml_seo_migrated_from.
	 *     @type int   still_on_migrated    Migrated posts that still have no adopted suggestion.
	 *     @type int   adopted              Migrated posts that no longer have suggestion meta.
	 *     @type float adopted_pct          Percentage (0–100).
	 * }
	 */
	public static function weekly_digest_stats(): array {
		global $wpdb;

		$migrated_total = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
			 WHERE meta_key = '_gml_seo_migrated_from'"
		);

		// "Still on migrated data" = migrated_from present AND at least one
		// of the 5 core suggestion keys still exists (i.e. user hasn't
		// adopted the AI's newer take yet).
		$still_on = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT m.post_id)
			 FROM {$wpdb->postmeta} m
			 JOIN {$wpdb->postmeta} s
			   ON s.post_id = m.post_id AND s.meta_key IN (
			     '_gml_seo_suggestion_title',
			     '_gml_seo_suggestion_desc',
			     '_gml_seo_suggestion_og_title',
			     '_gml_seo_suggestion_og_desc',
			     '_gml_seo_suggestion_keywords'
			   )
			 WHERE m.meta_key = '_gml_seo_migrated_from'"
		);

		$adopted = max( 0, $migrated_total - $still_on );
		$pct     = $migrated_total > 0 ? round( ( $adopted / $migrated_total ) * 100, 1 ) : 0.0;

		return [
			'migrated_total'    => $migrated_total,
			'still_on_migrated' => $still_on,
			'adopted'           => $adopted,
			'adopted_pct'       => (float) $pct,
		];
	}

	// ── AJAX handlers ──────────────────────────────────────────────────

	/**
	 * AJAX: gml_seo_apply_suggestion — adopt the full AI suggestion.
	 *
	 * @return void
	 */
	public static function ajax_apply_suggestion(): void {
		check_ajax_referer( 'gml_seo_nonce', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}
		self::apply_suggestion( $post_id );
		wp_send_json_success( self::get_side_by_side( $post_id ) );
	}

	/**
	 * AJAX: gml_seo_apply_suggestion_field — adopt a single field.
	 *
	 * @return void
	 */
	public static function ajax_apply_suggestion_field(): void {
		check_ajax_referer( 'gml_seo_nonce', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$field   = isset( $_POST['field'] ) ? sanitize_key( (string) $_POST['field'] ) : '';
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}
		if ( ! isset( self::$core_map[ $field ] ) ) {
			wp_send_json_error( [ 'message' => 'Invalid field' ], 400 );
		}
		self::apply_suggestion_field( $post_id, $field );
		wp_send_json_success( self::get_side_by_side( $post_id ) );
	}

	/**
	 * AJAX: gml_seo_gradual_exit — user opts out of observation period.
	 *
	 * @return void
	 */
	public static function ajax_exit(): void {
		check_ajax_referer( 'gml_seo_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}
		self::exit();
		wp_send_json_success();
	}

	// ── internal helpers ───────────────────────────────────────────────

	/**
	 * Delete every `_gml_seo_suggestion_*` key for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function delete_all_suggestion_keys( int $post_id ): void {
		foreach ( self::$suggestion_map as $key ) {
			delete_post_meta( $post_id, $key );
		}
		delete_post_meta( $post_id, '_gml_seo_suggestion_score' );
		delete_post_meta( $post_id, '_gml_seo_suggestion_generated_at' );
	}
}
