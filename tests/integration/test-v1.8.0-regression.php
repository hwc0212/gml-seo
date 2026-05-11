<?php
/**
 * Regression smoke: with no competing plugin, no migration in progress,
 * and no gradual_mode, v1.9.0 MUST behave identically to v1.8.0 in the
 * four touch-points listed in requirements §18.1:
 *   - Frontend wp_head meta output
 *   - Metabox rendering (no side-by-side block)
 *   - Bulk Optimize tab (no observation-period notice)
 *   - AI Engine apply_result (no suggestion routing)
 *
 * Self-contained mock, no WordPress, no PHPUnit.
 *
 * Run: php plugins/gml-seo/tests/integration/test-v1.8.0-regression.php
 *
 * @package GML_SEO
 * @since   1.9.0
 */

require_once __DIR__ . '/../bootstrap-mock.php';
require_once __DIR__ . '/../../includes/class-migration-manager.php';
require_once __DIR__ . '/../../includes/class-conflict-detector.php';
require_once __DIR__ . '/../../includes/class-gradual-mode-manager.php';

function rassert( $cond, $label ) {
	if ( ! $cond ) {
		fwrite( STDERR, "FAIL: {$label}\n" );
		exit( 1 );
	}
}

// ─── Baseline: clean v1.8.0-equivalent site ────────────────────────────

GML_SEO_Mock::reset();
GML_SEO_Mock::$options[ 'active_plugins' ] = [ 'akismet/akismet.php' ]; // no SEO competitor
GML_SEO_Mock::$options[ 'gml_seo' ]        = [ 'separator' => '-', 'site_name' => 'Example' ];
// No migration state set — Migration_Manager defaults to 'idle'.
// No gradual_mode flag — Gradual_Mode_Manager::is_active() returns false.

// ─── (1) Detection: nothing competing ──────────────────────────────────

GML_SEO_Conflict_Detector::reset_cache();
rassert( GML_SEO_Conflict_Detector::has_competing() === false, 'no competitor detected' );
rassert(
	GML_SEO_Conflict_Detector::should_suppress_meta_output() === false,
	'front-end meta suppression is OFF'
);

// ─── (2) Migration state: idle ─────────────────────────────────────────

$state = GML_SEO_Migration_Manager::get_state();
rassert( $state[ 'status' ] === 'idle', 'migration state stays idle' );
rassert( $state[ 'processed_posts' ] === 0, 'counters are zero' );

// ─── (3) Gradual mode: not active ──────────────────────────────────────

rassert( GML_SEO_Gradual_Mode_Manager::is_active() === false, 'gradual_mode OFF' );
rassert( GML_SEO_Gradual_Mode_Manager::bulk_optimize_allowed() === true, 'Bulk allowed' );

// ─── (4) AI routing is a no-op when gradual_mode is off ────────────────

// route_ai_result must not touch _gml_seo_* nor _gml_seo_suggestion_* when
// the observation period isn't active (requirements §18.1).
$ai_result = [
	'title' => 'AI Title',
	'desc'  => 'AI Description',
];
$before = GML_SEO_Mock::$postmeta[ 500 ] ?? [];
GML_SEO_Gradual_Mode_Manager::route_ai_result( 500, $ai_result );
$after = GML_SEO_Mock::$postmeta[ 500 ] ?? [];
rassert( $before === $after, 'route_ai_result is a no-op when gradual_mode is off' );

// ─── (5) The gml_seo option's legacy keys are untouched by v1.9.0 ──────

rassert(
	GML_SEO_Mock::$options[ 'gml_seo' ][ 'separator' ] === '-'
	&& GML_SEO_Mock::$options[ 'gml_seo' ][ 'site_name' ] === 'Example',
	'Legacy gml_seo fields preserved on pristine install'
);
rassert(
	! isset( GML_SEO_Mock::$options[ 'gml_seo' ][ 'gradual_mode' ] ),
	'gml_seo.gradual_mode not auto-injected for pristine installs'
);

echo "OK test-v1.8.0-regression\n";
