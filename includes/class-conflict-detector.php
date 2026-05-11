<?php
/**
 * Conflict Detector
 *
 * Read-only detection of competing SEO plugins (Yoast, Rank Math, SEOPress,
 * AIOSEO, The SEO Framework). Decides whether GML AI SEO should suppress
 * its own frontend meta output to avoid duplicate `<title>`, description
 * and canonical tags.
 *
 * Hard invariants:
 *   - MUST NOT write any option, post meta, or custom table (read-only).
 *   - MUST cache the scan result within a single request (static cache).
 *
 * @package GML_SEO
 * @see design.md §3.1, §6.5
 * @see requirements.md §1.*, §2.*, §3.*
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Final class GML_SEO_Conflict_Detector
 *
 * All methods are static; class is never instantiated.
 */
final class GML_SEO_Conflict_Detector {

	/**
	 * Per-request cache of the scan result.
	 *
	 * @var array<int,array{slug:string,name:string}>|null
	 */
	private static $cache = null;

	/**
	 * Detection table. Order is preserved in the scan result so callers
	 * get a deterministic slug sequence.
	 *
	 * Each entry:
	 *   - slug:       stable internal identifier used by Adapters + state.
	 *   - name:       human-readable name for UI (untranslated; Admin
	 *                 layer runs it through `__()` at render time).
	 *   - constants:  version / existence constants that imply "active".
	 *   - plugin_files: entries in `active_plugins` that imply "active".
	 *   - classes:    class names whose existence implies "active".
	 *
	 * @var array<int,array{slug:string,name:string,constants:string[],plugin_files:string[],classes:string[]}>
	 */
	private static $detection_rules = [
		[
			'slug'         => 'yoast',
			'name'         => 'Yoast SEO',
			'constants'    => [ 'WPSEO_VERSION' ],
			'plugin_files' => [ 'wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php' ],
			'classes'      => [ 'WPSEO_Options' ],
		],
		[
			'slug'         => 'rankmath',
			'name'         => 'Rank Math SEO',
			'constants'    => [ 'RANK_MATH_VERSION' ],
			'plugin_files' => [ 'seo-by-rank-math/rank-math.php', 'seo-by-rank-math-pro/rank-math-pro.php' ],
			'classes'      => [ 'RankMath' ],
		],
		[
			'slug'         => 'seopress',
			'name'         => 'SEOPress',
			'constants'    => [ 'SEOPRESS_VERSION' ],
			'plugin_files' => [ 'wp-seopress/seopress.php', 'wp-seopress-pro/seopress-pro.php' ],
			'classes'      => [],
		],
		[
			'slug'         => 'aioseo',
			'name'         => 'All in One SEO',
			'constants'    => [ 'AIOSEO_VERSION', 'AIOSEO_FILE' ],
			'plugin_files' => [ 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' ],
			'classes'      => [],
		],
		[
			'slug'         => 'seoframework',
			'name'         => 'The SEO Framework',
			'constants'    => [ 'THE_SEO_FRAMEWORK_VERSION' ],
			'plugin_files' => [ 'autodescription/autodescription.php' ],
			'classes'      => [ 'The_SEO_Framework\\Load' ],
		],
	];

	/**
	 * Scan currently-active competing SEO plugins. Read-only.
	 *
	 * Result is cached in `self::$cache` for the lifetime of the request.
	 * Subsequent calls return the cached array without re-scanning.
	 *
	 * @return array<int,array{slug:string,name:string}> Detected plugins
	 *         in detection order (may be empty).
	 */
	public static function scan(): array {
		if ( is_array( self::$cache ) ) {
			return self::$cache;
		}

		$active_plugins = self::get_active_plugins();
		$detected       = [];

		foreach ( self::$detection_rules as $rule ) {
			if ( self::rule_matches( $rule, $active_plugins ) ) {
				$detected[] = [
					'slug' => $rule['slug'],
					'name' => $rule['name'],
				];
			}
		}

		self::$cache = $detected;
		return self::$cache;
	}

	/**
	 * Whether any competing SEO plugin is currently active.
	 *
	 * @return bool
	 */
	public static function has_competing(): bool {
		return ! empty( self::scan() );
	}

	/**
	 * Whether GML AI SEO's frontend meta output should be suppressed.
	 *
	 * Suppression condition (design §3.1, requirements §2.1–§2.4):
	 *   has_competing() === true
	 *   AND migration_state.status !== 'completed'
	 *
	 * @return bool
	 */
	public static function should_suppress_meta_output(): bool {
		if ( ! self::has_competing() ) {
			return false;
		}

		$state = self::get_migration_state();
		return ( ( $state['status'] ?? 'idle' ) !== 'completed' );
	}

	/**
	 * Data payload used to render the admin conflict notice.
	 *
	 * @return array{detected: string[], migrated: bool, dismissed: bool}
	 */
	public static function get_notice_context(): array {
		$detected = array_map(
			static function ( $row ) {
				return $row['name'];
			},
			self::scan()
		);

		$state    = self::get_migration_state();
		$migrated = ( ( $state['status'] ?? 'idle' ) === 'completed' );

		$opts      = get_option( 'gml_seo', [] );
		$dismissed = ! empty( $opts['conflict_notice_dismissed'] );

		return [
			'detected'  => $detected,
			'migrated'  => $migrated,
			'dismissed' => (bool) $dismissed,
		];
	}

	/**
	 * Reset the request-scoped cache. Only used by tests.
	 *
	 * @internal
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$cache = null;
	}

	// ── internal helpers ───────────────────────────────────────────────

	/**
	 * Check whether a detection rule matches.
	 *
	 * @param array                $rule           One row from self::$detection_rules.
	 * @param array<int,string>    $active_plugins Output of get_active_plugins().
	 * @return bool
	 */
	private static function rule_matches( array $rule, array $active_plugins ): bool {
		foreach ( $rule['constants'] as $constant ) {
			if ( defined( $constant ) ) {
				return true;
			}
		}
		foreach ( $rule['plugin_files'] as $plugin_file ) {
			if ( in_array( $plugin_file, $active_plugins, true ) ) {
				return true;
			}
		}
		foreach ( $rule['classes'] as $class ) {
			if ( class_exists( $class, false ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve the list of active plugin files, including network-activated
	 * plugins on multisite installations.
	 *
	 * Pure read — no DB writes.
	 *
	 * @return array<int,string>
	 */
	private static function get_active_plugins(): array {
		$active = get_option( 'active_plugins', [] );
		if ( ! is_array( $active ) ) {
			$active = [];
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$network = get_site_option( 'active_sitewide_plugins', [] );
			if ( is_array( $network ) && ! empty( $network ) ) {
				$active = array_values( array_unique( array_merge( $active, array_keys( $network ) ) ) );
			}
		}
		return $active;
	}

	/**
	 * Read the migration state without instantiating Migration_Manager.
	 *
	 * Falls back to a minimal `idle` stub when the Migration_Manager class
	 * hasn't been loaded yet (e.g. during very early boot) — this keeps
	 * should_suppress_meta_output() safe to call from any request phase.
	 *
	 * @return array
	 */
	private static function get_migration_state(): array {
		if ( class_exists( 'GML_SEO_Migration_Manager' ) ) {
			return GML_SEO_Migration_Manager::get_state();
		}
		$raw = get_option( 'gml_seo_migration_state', [] );
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}
		return array_merge( [ 'status' => 'idle' ], $raw );
	}
}
