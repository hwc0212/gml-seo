<?php
/**
 * Migration Manager
 *
 * Orchestrates the four-step migration wizard (Scan → Preview → Execute →
 * Done) plus WP-Cron batching, state machine, AJAX endpoints, and adapter
 * dispatch. Holds the `gml_seo_migration_state` option.
 *
 * @package GML_SEO
 * @see design.md §3.2, §4.1, §6.4, §6.8
 * @see requirements.md §4.*, §5.*, §6.*, §7.*, §15.*, §16.*, §17.*
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Final class GML_SEO_Migration_Manager
 */
final class GML_SEO_Migration_Manager {

	/** Option key that stores the migration state machine. */
	const OPTION_KEY = 'gml_seo_migration_state';

	/** WP-Cron hook name for batch execution. */
	const CRON_HOOK = 'gml_seo_migrate_batch';

	/** Number of posts processed per cron batch. */
	const BATCH_SIZE = 100;

	/** Allowed values for the `status` field of the migration state. */
	const STATUS_ENUM = [ 'idle', 'scanning', 'scanned', 'running', 'completed', 'failed' ];

	/** Allowed values for the `source_slug` field (empty string = not yet chosen). */
	const SOURCE_SLUG_ENUM = [ '', 'yoast', 'rankmath', 'seopress', 'aioseo', 'seoframework' ];

	/** Nonce action used by all migration AJAX endpoints. */
	const NONCE_ACTION = 'gml_seo_migration';

	/**
	 * Adapter class map — slug ⇒ PHP class name.
	 *
	 * @var array<string,string>
	 */
	private static $adapter_classes = [
		'yoast'        => 'GML_SEO_Yoast_Adapter',
		'rankmath'     => 'GML_SEO_RankMath_Adapter',
		'seopress'     => 'GML_SEO_SEOPress_Adapter',
		'aioseo'       => 'GML_SEO_AIOSEO_Adapter',
		'seoframework' => 'GML_SEO_SEOFramework_Adapter',
	];

	/**
	 * Allowed state transitions. Keyed by current status, value is the set
	 * of reachable next statuses. See design.md §3.2.
	 *
	 * @var array<string,string[]>
	 */
	private static $transitions = [
		'idle'       => [ 'scanning' ],
		'scanning'   => [ 'scanned', 'idle', 'failed' ],
		'scanned'    => [ 'running', 'idle', 'scanning' ],
		'running'    => [ 'running', 'completed', 'failed' ],
		'completed'  => [ 'idle' ],
		'failed'     => [ 'running', 'idle' ],
	];

	/**
	 * Constructor. Registers AJAX + cron hooks via hooks().
	 */
	public function __construct() {
		$this->hooks();
	}

	/**
	 * Return the default migration state. Pure — no DB I/O.
	 *
	 * Schema defined in design.md §4.1. The invariant
	 * `processed_posts == written_posts + skipped_posts` holds trivially
	 * at defaults (0 == 0 + 0).
	 *
	 * @return array
	 */
	public static function default_state(): array {
		return [
			'status'          => 'idle',
			'source_slug'     => '',
			'started_at'      => '',
			'completed_at'    => '',
			'total_posts'     => 0,
			'processed_posts' => 0,
			'written_posts'   => 0,
			'skipped_posts'   => 0,
			'last_error'      => '',
			'last_batch_at'   => 0,
		];
	}

	/**
	 * Read the current migration state from DB, parsed against the default
	 * schema and self-healed against the validation rules in design.md §4.3.
	 *
	 * This method is read-only: self-healing only exists in memory and is
	 * never written back to the DB. The next `update_state()` call will
	 * persist the healed structure.
	 *
	 * @return array
	 */
	public static function get_state(): array {
		$raw = get_option( self::OPTION_KEY, self::default_state() );
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}
		$merged = wp_parse_args( $raw, self::default_state() );

		return self::heal_state( $merged );
	}

	/**
	 * Apply a partial patch on top of the current (healed) state, re-heal,
	 * and persist with autoload=false.
	 *
	 * The option is stored with autoload=false because the front-end request
	 * path has no need to read it, and keeping it out of `wp_load_alloptions`
	 * avoids slowing down `wp_head`.
	 *
	 * @param array $patch Partial fields to merge into the current state.
	 * @return void
	 */
	public static function update_state( array $patch ): void {
		$current = self::get_state();
		$merged  = array_merge( $current, $patch );
		$healed  = self::heal_state( $merged );
		update_option( self::OPTION_KEY, $healed, false );
	}

	/**
	 * Self-heal a state array against the validation rules in design.md §4.3.
	 *
	 *   - `status` must be in STATUS_ENUM (fallback: 'idle')
	 *   - `source_slug` must be in SOURCE_SLUG_ENUM (fallback: '')
	 *   - counters must be int >= 0 (fallback: 0)
	 *   - `last_batch_at` must be int (fallback: 0)
	 *
	 * @param array $state State array already merged with default_state().
	 * @return array Healed state.
	 */
	private static function heal_state( array $state ): array {
		if ( ! in_array( $state['status'] ?? null, self::STATUS_ENUM, true ) ) {
			$state['status'] = 'idle';
		}
		if ( ! in_array( $state['source_slug'] ?? null, self::SOURCE_SLUG_ENUM, true ) ) {
			$state['source_slug'] = '';
		}
		foreach ( [ 'total_posts', 'processed_posts', 'written_posts', 'skipped_posts' ] as $counter ) {
			$value = $state[ $counter ] ?? 0;
			if ( ! is_int( $value ) || $value < 0 ) {
				$state[ $counter ] = 0;
			}
		}
		if ( ! is_int( $state['last_batch_at'] ?? null ) ) {
			$state['last_batch_at'] = 0;
		}

		return $state;
	}

	/**
	 * Register AJAX actions and the cron hook.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'wp_ajax_gml_seo_migration_scan',    [ $this, 'ajax_scan' ] );
		add_action( 'wp_ajax_gml_seo_migration_preview', [ $this, 'ajax_preview' ] );
		add_action( 'wp_ajax_gml_seo_migration_start',   [ $this, 'ajax_start' ] );
		add_action( 'wp_ajax_gml_seo_migration_status',  [ $this, 'ajax_status' ] );
		add_action( self::CRON_HOOK, [ $this, 'run_batch' ] );
	}

	/**
	 * Resolve the adapter for the given source slug.
	 *
	 * @param string $slug 'yoast' | 'rankmath' | 'seopress' | 'aioseo' | 'seoframework'.
	 * @return GML_SEO_Migration_Adapter
	 * @throws InvalidArgumentException When slug is unknown.
	 */
	public function adapter( string $slug ): GML_SEO_Migration_Adapter {
		if ( ! isset( self::$adapter_classes[ $slug ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown migration source slug: %s', $slug ) );
		}
		$class = self::$adapter_classes[ $slug ];
		if ( ! class_exists( $class ) ) {
			throw new InvalidArgumentException( sprintf( 'Adapter class not loaded: %s', $class ) );
		}
		$instance = new $class();
		if ( ! $instance instanceof GML_SEO_Migration_Adapter ) {
			throw new InvalidArgumentException( sprintf( 'Adapter %s does not implement GML_SEO_Migration_Adapter', $class ) );
		}
		return $instance;
	}

	/**
	 * Scan the source plugin for migration candidates. Read-only.
	 *
	 * @param string $slug Source plugin slug.
	 * @return array {status, source_slug, total_posts}
	 */
	public function scan( string $slug ): array {
		$this->assert_slug( $slug );
		$this->assert_transition( 'scanning' );

		self::update_state( [
			'status'      => 'scanning',
			'source_slug' => $slug,
		] );

		$adapter = $this->adapter( $slug );
		$total   = $adapter->count_posts();

		self::update_state( [
			'status'      => 'scanned',
			'source_slug' => $slug,
			'total_posts' => (int) $total,
		] );

		return self::get_state();
	}

	/**
	 * Preview the first N posts' field mapping. Read-only.
	 *
	 * @param string $slug  Source plugin slug.
	 * @param int    $limit Number of posts to preview (default 20).
	 * @return array<int,array>
	 */
	public function preview( string $slug, int $limit = 20 ): array {
		$this->assert_slug( $slug );
		$limit = max( 1, min( 100, $limit ) );

		$adapter = $this->adapter( $slug );
		$ids     = $this->candidate_post_ids( $adapter, 0, $limit );

		$rows = [];
		foreach ( $ids as $pid ) {
			$rows[] = [
				'post_id' => (int) $pid,
				'title'   => get_the_title( $pid ),
				'mapping' => $adapter->map_post( (int) $pid ),
			];
		}
		return $rows;
	}

	/**
	 * Kick off the migration: update state and schedule the first cron batch.
	 * Idempotent — if already running, returns without re-scheduling.
	 *
	 * @param string $slug Source plugin slug.
	 * @return array Current state.
	 */
	public function start( string $slug ): array {
		$this->assert_slug( $slug );
		$current = self::get_state();

		// Idempotent protection: if already running, return the state
		// without re-scheduling. See requirements §6.5.
		if ( $current['status'] === 'running' && $current['source_slug'] === $slug ) {
			return $current;
		}

		$this->assert_transition( 'running' );

		self::update_state( [
			'status'          => 'running',
			'source_slug'     => $slug,
			'started_at'      => current_time( 'mysql' ),
			'completed_at'    => '',
			'processed_posts' => 0,
			'written_posts'   => 0,
			'skipped_posts'   => 0,
			'last_error'      => '',
			'last_batch_at'   => 0,
		] );

		// Migrate globals once, up front.
		try {
			$this->adapter( $slug )->migrate_globals();
		} catch ( \Throwable $e ) {
			self::update_state( [ 'last_error' => 'migrate_globals: ' . $e->getMessage() ] );
		}

		// Schedule the first batch to run "as soon as cron fires".
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}

		return self::get_state();
	}

	/**
	 * Cron callback: process one batch, then self-schedule the next one.
	 *
	 * Guarded against direct URL invocation — only reachable via WP-Cron
	 * or via the authenticated AJAX wizard path.
	 *
	 * @return void
	 */
	public function run_batch(): void {
		// Caller gate: WP-Cron OR authenticated AJAX from the wizard.
		$from_cron = ( defined( 'DOING_CRON' ) && DOING_CRON );
		$from_ajax = (
			defined( 'DOING_AJAX' ) && DOING_AJAX
			&& current_user_can( 'manage_options' )
			&& isset( $_REQUEST['nonce'] )
			&& wp_verify_nonce( (string) $_REQUEST['nonce'], self::NONCE_ACTION )
		);
		if ( ! $from_cron && ! $from_ajax ) {
			return;
		}

		$state = self::get_state();
		if ( $state['status'] !== 'running' ) {
			return;
		}

		$slug = (string) $state['source_slug'];
		if ( ! isset( self::$adapter_classes[ $slug ] ) ) {
			self::update_state( [
				'status'     => 'failed',
				'last_error' => 'Invalid source_slug in running state: ' . $slug,
			] );
			return;
		}

		try {
			$adapter = $this->adapter( $slug );
		} catch ( \Throwable $e ) {
			self::update_state( [
				'status'     => 'failed',
				'last_error' => 'adapter(): ' . $e->getMessage(),
			] );
			return;
		}

		$offset  = (int) $state['processed_posts'];
		$batch   = self::BATCH_SIZE;
		$written = 0;
		$before  = [
			'processed' => (int) $state['processed_posts'],
			'written'   => (int) $state['written_posts'],
			'skipped'   => (int) $state['skipped_posts'],
		];

		try {
			$written = (int) $adapter->migrate_batch( $offset, $batch );
		} catch ( \Throwable $e ) {
			// Batch-level fatal is recorded but we DO NOT flip to failed —
			// the next cron tick will retry at the advanced offset thanks
			// to the `_gml_seo_migrated_from` idempotency marker.
			self::update_state( [ 'last_error' => 'run_batch: ' . $e->getMessage() ] );
		}

		// Compute progress delta. We advance `processed_posts` by the full
		// batch size (bounded by remaining), since skipped idempotent
		// posts also count as "processed" per design.md §4.1.
		$remaining_before = max( 0, (int) $state['total_posts'] - $before['processed'] );
		$advance          = min( $batch, $remaining_before );
		if ( $advance === 0 && $written === 0 ) {
			// Nothing to do — treat as completion if total is already met.
			$advance = 0;
		}
		$skipped_delta = max( 0, $advance - $written );

		self::update_state( [
			'processed_posts' => $before['processed'] + $advance,
			'written_posts'   => $before['written'] + $written,
			'skipped_posts'   => $before['skipped'] + $skipped_delta,
			'last_batch_at'   => time(),
		] );

		$after = self::get_state();
		if ( $after['processed_posts'] >= (int) $after['total_posts'] && (int) $after['total_posts'] > 0 ) {
			self::update_state( [
				'status'       => 'completed',
				'completed_at' => current_time( 'mysql' ),
			] );
			// Hand off to the observation period.
			if ( class_exists( 'GML_SEO_Gradual_Mode_Manager' ) ) {
				GML_SEO_Gradual_Mode_Manager::enter();
			}
			return;
		}

		// Schedule the next batch ~60s from now (requirements §6.2).
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::CRON_HOOK );
		}
	}

	/**
	 * Current migration state.
	 *
	 * @return array
	 */
	public function status(): array {
		return self::get_state();
	}

	// ── AJAX handlers ──────────────────────────────────────────────────

	/**
	 * AJAX: gml_seo_migration_scan
	 *
	 * @return void
	 */
	public function ajax_scan(): void {
		$this->authorize_ajax();
		$slug = isset( $_POST['slug'] ) ? sanitize_key( (string) $_POST['slug'] ) : '';
		try {
			$state = $this->scan( $slug );
			wp_send_json_success( $state );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
		}
	}

	/**
	 * AJAX: gml_seo_migration_preview
	 *
	 * @return void
	 */
	public function ajax_preview(): void {
		$this->authorize_ajax();
		$slug  = isset( $_POST['slug'] ) ? sanitize_key( (string) $_POST['slug'] ) : '';
		$limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 20;
		try {
			$rows = $this->preview( $slug, $limit );
			wp_send_json_success( [ 'rows' => $rows ] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
		}
	}

	/**
	 * AJAX: gml_seo_migration_start
	 *
	 * @return void
	 */
	public function ajax_start(): void {
		$this->authorize_ajax();
		$slug = isset( $_POST['slug'] ) ? sanitize_key( (string) $_POST['slug'] ) : '';
		try {
			$state = $this->start( $slug );
			wp_send_json_success( $state );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
		}
	}

	/**
	 * AJAX: gml_seo_migration_status
	 *
	 * @return void
	 */
	public function ajax_status(): void {
		$this->authorize_ajax();
		wp_send_json_success( self::get_state() );
	}

	// ── internal helpers ───────────────────────────────────────────────

	/**
	 * Assert that the source slug is known. Throws if not.
	 *
	 * @param string $slug Slug to check.
	 * @return void
	 * @throws InvalidArgumentException
	 */
	private function assert_slug( string $slug ): void {
		if ( ! isset( self::$adapter_classes[ $slug ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown migration source slug: %s', $slug ) );
		}
	}

	/**
	 * Assert that the current state permits transitioning to $target.
	 *
	 * @param string $target Target status.
	 * @return void
	 * @throws RuntimeException When the transition is not allowed.
	 */
	private function assert_transition( string $target ): void {
		$current = self::get_state();
		$allowed = self::$transitions[ $current['status'] ] ?? [];
		if ( ! in_array( $target, $allowed, true ) ) {
			throw new RuntimeException( sprintf(
				'Illegal state transition: %s → %s',
				$current['status'],
				$target
			) );
		}
	}

	/**
	 * AJAX gatekeeper: nonce + capability. Terminates with HTTP 403 on failure.
	 *
	 * @return void
	 */
	private function authorize_ajax(): void {
		// check_ajax_referer dies on failure; that's fine for our use case.
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}
	}

	/**
	 * Fetch candidate post IDs for a given adapter.
	 *
	 * Used by both `preview()` and `run_batch()` (task 4.9) to enumerate
	 * posts carrying source-plugin meta. Read-only.
	 *
	 * Uses a LIKE query against `wp_postmeta.meta_key` scoped to each
	 * adapter's key prefix. Returns ascending post IDs.
	 *
	 * @param GML_SEO_Migration_Adapter $adapter Adapter whose slug picks the key prefix.
	 * @param int                       $offset  Offset.
	 * @param int                       $limit   Max rows.
	 * @return int[]
	 */
	public function candidate_post_ids( GML_SEO_Migration_Adapter $adapter, int $offset, int $limit ): array {
		global $wpdb;

		$prefixes = [
			'yoast'        => '_yoast_wpseo_%',
			'rankmath'     => 'rank_math_%',
			'seopress'     => '_seopress_%',
			'aioseo'       => '_aioseo_%',
			'seoframework' => '_genesis_%',
		];
		$prefix = $prefixes[ $adapter->slug() ] ?? '';
		if ( $prefix === '' ) {
			return [];
		}

		$offset = max( 0, $offset );
		$limit  = max( 1, min( 500, $limit ) );

		$sql = $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key LIKE %s
			 ORDER BY post_id ASC
			 LIMIT %d OFFSET %d",
			$prefix,
			$limit,
			$offset
		);
		$ids = $wpdb->get_col( $sql );
		return array_map( 'intval', (array) $ids );
	}
}
