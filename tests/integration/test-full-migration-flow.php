<?php
/**
 * Integration smoke: full migration flow (design.md §8.3).
 *
 * Covers the end-to-end path a site admin walks:
 *   1. Yoast is active + has post meta
 *   2. Conflict Detector sees it → guard suppresses frontend meta
 *   3. Admin fires the migration wizard (scan → preview → start)
 *   4. Cron runs a batch; _gml_seo_* meta appears + _yoast_wpseo_* survives
 *   5. Status transitions to completed → Gradual Mode enters
 *   6. AI result routes to suggestion channel, not frontend meta
 *   7. apply_suggestion copies over & clears the suggestion bucket
 *   8. exit() flips gradual_mode off, Bulk Optimize becomes allowed
 *
 * Self-contained: no WordPress, no PHPUnit. Uses a tiny in-memory mock of
 * get_option / update_option / postmeta / wpdb / hooks just enough to
 * drive the code paths we exercise.
 *
 * Run: php plugins/gml-seo/tests/integration/test-full-migration-flow.php
 * Exits 0 on success; non-zero with FAIL ... on first broken assertion.
 *
 * @package GML_SEO
 * @since   1.9.0
 */

require_once __DIR__ . '/../bootstrap-mock.php';
require_once __DIR__ . '/../../includes/class-migration-manager.php';
require_once __DIR__ . '/../../includes/class-conflict-detector.php';
require_once __DIR__ . '/../../includes/class-gradual-mode-manager.php';
require_once __DIR__ . '/../../includes/migration/interface-migration-adapter.php';
require_once __DIR__ . '/../../includes/migration/class-yoast-adapter.php';

function iassert( $cond, $label ) {
	if ( ! $cond ) {
		fwrite( STDERR, "FAIL: {$label}\n" );
		debug_print_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 3 );
		exit( 1 );
	}
}

// ─── Fixture: pretend Yoast is installed + 3 posts carry Yoast meta ─────

GML_SEO_Mock::reset();
GML_SEO_Mock::$options['active_plugins']    = [ 'wordpress-seo/wp-seo.php' ];
GML_SEO_Mock::$options['gml_seo']           = [ 'separator' => '-', 'site_name' => 'Example' ];
GML_SEO_Mock::$options['wpseo_titles']      = [ 'separator' => 'sc-dash' ];
GML_SEO_Mock::$post_titles[ 101 ]           = 'How to bake bread';
GML_SEO_Mock::$post_titles[ 102 ]           = 'Best flour guide';
GML_SEO_Mock::$post_titles[ 103 ]           = 'Sourdough starter recipe';
GML_SEO_Mock::$postmeta = [
	101 => [
		'_yoast_wpseo_title'            => [ '%%title%% %%sep%% %%sitename%%' ],
		'_yoast_wpseo_metadesc'         => [ 'A quick guide to baking bread at home.' ],
		'_yoast_wpseo_focuskw'          => [ 'bake bread' ],
		'_yoast_wpseo_canonical'        => [ 'https://example.com/bake-bread' ],
		'_yoast_wpseo_meta-robots-noindex' => [ '1' ],
	],
	102 => [
		'_yoast_wpseo_title'    => [ 'Best Flour' ],
		'_yoast_wpseo_metadesc' => [ 'Compare 10 types of flour.' ],
	],
	103 => [
		'_yoast_wpseo_twitter-title' => [ 'Sourdough Starter' ],
		'_yoast_wpseo_twitter-description' => [ 'Start a levain in 5 days.' ],
	],
];
// Snapshot source meta so we can assert it survives.
$yoast_snapshot = GML_SEO_Mock::$postmeta;
$wpseo_titles_snapshot = GML_SEO_Mock::$options['wpseo_titles'];

// ─── Step 1: Conflict_Detector sees Yoast ──────────────────────────────

GML_SEO_Conflict_Detector::reset_cache();
$detected = GML_SEO_Conflict_Detector::scan();
iassert( ! empty( $detected ), 'Detector should find at least one competing plugin' );
iassert( $detected[ 0 ][ 'slug' ] === 'yoast', 'Detector should identify Yoast' );
iassert( GML_SEO_Conflict_Detector::has_competing(), 'has_competing() true' );

// ─── Step 2: migration not completed → frontend meta should be suppressed

iassert(
	GML_SEO_Conflict_Detector::should_suppress_meta_output() === true,
	'Suppression active while status != completed'
);

// ─── Step 3: Migration wizard — scan → start ───────────────────────────

$mgr   = new GML_SEO_Migration_Manager();
$state = $mgr->scan( 'yoast' );
iassert( $state[ 'status' ] === 'scanned', 'After scan() status=scanned' );
iassert( $state[ 'total_posts' ] === 3, 'Scan counts 3 posts (got ' . $state[ 'total_posts' ] . ')' );

$mgr->start( 'yoast' );
$state = GML_SEO_Migration_Manager::get_state();
iassert( $state[ 'status' ] === 'running', 'After start() status=running' );
iassert( $state[ 'source_slug' ] === 'yoast', 'source_slug=yoast' );
iassert( GML_SEO_Mock::$options[ 'gml_seo' ][ 'separator' ] === '-', 'migrate_globals mapped sc-dash → -' );

// ─── Step 4: drive the cron batch manually ─────────────────────────────

// Simulate being inside a WP-Cron tick.
if ( ! defined( 'DOING_CRON' ) ) define( 'DOING_CRON', true );
$mgr->run_batch();

$state = GML_SEO_Migration_Manager::get_state();
iassert( $state[ 'processed_posts' ] === 3, 'processed=3 (got ' . $state[ 'processed_posts' ] . ')' );
iassert(
	$state[ 'processed_posts' ] === $state[ 'written_posts' ] + $state[ 'skipped_posts' ],
	'invariant processed == written + skipped'
);
iassert( $state[ 'status' ] === 'completed', 'status→completed when processed hits total' );

// Frontend keys land on each migrated post.
iassert(
	GML_SEO_Mock::get_post_meta( 101, '_gml_seo_migrated_from' ) === 'yoast',
	'post 101 carries _gml_seo_migrated_from=yoast'
);
$title_101 = GML_SEO_Mock::get_post_meta( 101, '_gml_seo_title' );
iassert(
	strpos( (string) $title_101, 'How to bake bread' ) !== false,
	'post 101 title has %%title%% expanded (got ' . var_export( $title_101, true ) . ')'
);
iassert(
	GML_SEO_Mock::get_post_meta( 101, '_gml_seo_noindex' ) === 1,
	'post 101 noindex=1'
);
iassert(
	GML_SEO_Mock::get_post_meta( 103, '_gml_seo_og_title' ) === 'Sourdough Starter',
	'post 103 falls back to twitter-title as og_title'
);

// ─── Step 5: source data survives ─────────────────────────────────────

// Source Yoast keys must be byte-for-byte identical. The post's meta row
// set grew (we added _gml_seo_*), so we compare key-by-key on the Yoast
// keys only (property 4: source data preservation).
foreach ( $yoast_snapshot as $pid => $keys ) {
	foreach ( $keys as $key => $value ) {
		iassert(
			( GML_SEO_Mock::$postmeta[ $pid ][ $key ] ?? null ) === $value,
			sprintf( 'source key %s on post %d survives migration', $key, $pid )
		);
	}
}
iassert(
	GML_SEO_Mock::$options[ 'wpseo_titles' ] === $wpseo_titles_snapshot,
	'wpseo_titles option untouched'
);

// ─── Step 6: Gradual mode is active, suppression lifts ─────────────────

iassert( GML_SEO_Gradual_Mode_Manager::is_active() === true, 'Gradual Mode entered automatically' );

GML_SEO_Conflict_Detector::reset_cache();
iassert(
	GML_SEO_Conflict_Detector::should_suppress_meta_output() === false,
	'Suppression lifts once status=completed, even with Yoast still active'
);

// ─── Step 7: AI result routes to suggestion channel ────────────────────

$ai_result = [
	'title'    => 'Easy Bread Recipe — 5 Step Guide',
	'desc'     => 'Learn to bake artisan bread at home with 5 easy steps.',
	'og_title' => 'Easy Bread Recipe',
	'og_desc'  => 'Artisan bread in 5 steps.',
	'keywords' => 'bread,recipe,baking',
	'score'    => 82,
];

$meta_before = [
	'title'    => GML_SEO_Mock::get_post_meta( 101, '_gml_seo_title' ),
	'desc'     => GML_SEO_Mock::get_post_meta( 101, '_gml_seo_desc' ),
	'og_title' => GML_SEO_Mock::get_post_meta( 101, '_gml_seo_og_title' ),
	'og_desc'  => GML_SEO_Mock::get_post_meta( 101, '_gml_seo_og_desc' ),
	'keywords' => GML_SEO_Mock::get_post_meta( 101, '_gml_seo_keywords' ),
];

GML_SEO_Gradual_Mode_Manager::route_ai_result( 101, $ai_result );

// Frontend-facing keys must NOT change.
$meta_after = [
	'title'    => GML_SEO_Mock::get_post_meta( 101, '_gml_seo_title' ),
	'desc'     => GML_SEO_Mock::get_post_meta( 101, '_gml_seo_desc' ),
	'og_title' => GML_SEO_Mock::get_post_meta( 101, '_gml_seo_og_title' ),
	'og_desc'  => GML_SEO_Mock::get_post_meta( 101, '_gml_seo_og_desc' ),
	'keywords' => GML_SEO_Mock::get_post_meta( 101, '_gml_seo_keywords' ),
];
iassert( $meta_before === $meta_after, 'property 9: AI suggestion does not overwrite frontend meta' );

// Suggestion channel populated.
iassert(
	GML_SEO_Mock::get_post_meta( 101, '_gml_seo_suggestion_title' ) === $ai_result[ 'title' ],
	'suggestion_title written'
);
iassert(
	GML_SEO_Mock::get_post_meta( 101, '_gml_seo_suggestion_score' ) === 82,
	'suggestion_score written'
);

// ─── Step 8: Adopt the suggestion ──────────────────────────────────────

GML_SEO_Gradual_Mode_Manager::apply_suggestion( 101 );
iassert(
	GML_SEO_Mock::get_post_meta( 101, '_gml_seo_title' ) === $ai_result[ 'title' ],
	'after apply_suggestion, _gml_seo_title equals previous suggestion'
);
iassert(
	GML_SEO_Mock::get_post_meta( 101, '_gml_seo_suggestion_title' ) === '',
	'property 10: suggestion keys cleared after adoption'
);
iassert(
	GML_SEO_Mock::get_post_meta( 101, '_gml_seo_migrated_from' ) === 'yoast',
	'migrated_from marker preserved after adoption'
);

// ─── Step 9: Bulk Optimize gate ────────────────────────────────────────

iassert( GML_SEO_Gradual_Mode_Manager::bulk_optimize_allowed() === false, 'Bulk blocked while gradual_mode on' );

// ─── Step 10: Exit gradual mode, Bulk returns ─────────────────────────

GML_SEO_Gradual_Mode_Manager::exit();
iassert( GML_SEO_Gradual_Mode_Manager::is_active() === false, 'Gradual mode exits on request' );
iassert( GML_SEO_Gradual_Mode_Manager::bulk_optimize_allowed() === true, 'Bulk Optimize re-enabled' );

echo "OK test-full-migration-flow\n";
