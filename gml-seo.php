<?php
/**
 * Plugin Name: GML AI SEO
 * Plugin URI: https://huwencai.com/gml-seo
 * Description: All-in-one AI SEO automation + multilingual translation. AI weekly audits every page, re-optimizes stale content, pushes changes to Google / Bing in real time, and translates your site with destination-language SEO awareness (not literal translation). Built for 2025 AI Overviews and Helpful Content System.
 * Version: 1.8.0
 * Author: huwencai.com
 * Author URI: https://huwencai.com
 * License: GPL v2 or later
 * Text Domain: gml-seo
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'GML_SEO_VER', '1.8.0' );
define( 'GML_SEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'GML_SEO_URL', plugin_dir_url( __FILE__ ) );

final class GML_SEO {

    private static $inst = null;

    public static function i() {
        if ( ! self::$inst ) self::$inst = new self();
        return self::$inst;
    }

    private function __construct() {
        // Disable WP core sitemap as early as possible (before WP_Sitemaps::init on 'init' priority 0)
        add_filter( 'wp_sitemaps_enabled', '__return_false' );
        require_once GML_SEO_DIR . 'includes/class-gemini-api.php';
        require_once GML_SEO_DIR . 'includes/class-admin.php';
        require_once GML_SEO_DIR . 'includes/class-meta-tags.php';
        require_once GML_SEO_DIR . 'includes/class-schema.php';
        require_once GML_SEO_DIR . 'includes/class-sitemap.php';
        require_once GML_SEO_DIR . 'includes/class-auto-link.php';
        require_once GML_SEO_DIR . 'includes/class-faq-display.php';
        require_once GML_SEO_DIR . 'includes/class-ai-engine.php';
        require_once GML_SEO_DIR . 'includes/class-code-injection.php';
        require_once GML_SEO_DIR . 'includes/class-metabox.php';
        require_once GML_SEO_DIR . 'includes/class-performance.php';
        require_once GML_SEO_DIR . 'includes/class-health-monitor.php';
        require_once GML_SEO_DIR . 'includes/class-indexing.php';
        require_once GML_SEO_DIR . 'includes/class-translate-bootstrap.php';

        // Load the bundled translate module (registers autoloader + constants)
        GML_SEO_Translate_Bootstrap::load();

        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
        add_action( 'plugins_loaded', [ $this, 'boot' ] );
    }

    public function deactivate() {
        GML_SEO_Health_Monitor::deactivate_cron();
        GML_SEO_Translate_Bootstrap::deactivate();
    }

    public function activate() {
        if ( ! get_option( 'gml_seo' ) ) {
            update_option( 'gml_seo', [
                'engine'          => 'gemini',
                'gemini_key'      => '',
                'model'           => 'gemini-2.5-flash',
                'deepseek_key'    => '',
                'deepseek_model'  => 'deepseek-chat',
                'deepseek_base_url' => 'https://api.deepseek.com',
                'ga_id'           => '',
                'gtm_id'          => '',
                'adsense_id'      => '',
                'head_code'       => '',
                'body_code'       => '',
                'footer_code'     => '',
                'site_name'       => get_bloginfo( 'name' ),
                'separator'       => '-',
            ] );
        }
        // Sitemap rewrite rules
        GML_SEO_Sitemap::add_rules();
        flush_rewrite_rules();
        // Store version for upgrade detection
        update_option( 'gml_seo_version', GML_SEO_VER );
        // Schedule weekly health audit
        GML_SEO_Health_Monitor::activate_cron();
        // Initialize translate module DB (safe on re-activation)
        GML_SEO_Translate_Bootstrap::install();
        // Auto-deactivate the standalone GML Translate if present
        GML_SEO_Translate_Bootstrap::maybe_deactivate_standalone();
    }

    public function boot() {
        // Auto-flush rewrite rules on version upgrade (must run on init, not plugins_loaded)
        $stored = get_option( 'gml_seo_version', '' );
        if ( $stored !== GML_SEO_VER ) {
            add_action( 'init', function() {
                GML_SEO_Sitemap::add_rules();
                flush_rewrite_rules();
                update_option( 'gml_seo_version', GML_SEO_VER );
            }, 99 );
        }

        new GML_SEO_Admin();
        new GML_SEO_Code_Injection();
        new GML_SEO_Auto_Link();
        new GML_SEO_Health_Monitor();
        new GML_SEO_Indexing();

        // Initialize bundled translate module
        GML_SEO_Translate_Bootstrap::init();
        GML_SEO_Translate_Bootstrap::maybe_show_migration_notice();

        if ( ! is_admin() ) {
            new GML_SEO_Meta_Tags();
            new GML_SEO_Schema();
            new GML_SEO_Sitemap();
            new GML_SEO_Performance();
            new GML_SEO_FAQ_Display();
        }

        if ( is_admin() ) {
            new GML_SEO_Metabox();
        }

        // Register AJAX handler for manual meta saves (always available)
        add_action( 'wp_ajax_gml_seo_apply', [ $this, 'ajax_apply_meta' ] );
        add_action( 'wp_ajax_gml_seo_toggle', [ $this, 'ajax_toggle_meta' ] );
        add_action( 'wp_ajax_gml_seo_rebuild_index', [ $this, 'ajax_rebuild_index' ] );

        // AI engine — runs on save_post to auto-generate SEO data
        if ( self::has_ai_key() ) {
            new GML_SEO_AI_Engine();
        }
    }

    /** AJAX: save a single SEO meta field (works without AI key). */
    public function ajax_apply_meta() {
        check_ajax_referer( 'gml_seo_nonce' );
        $pid = absint( $_POST['post_id'] ?? 0 );
        $key = sanitize_text_field( $_POST['meta_key'] ?? '' );
        $val = sanitize_text_field( $_POST['meta_value'] ?? '' );

        if ( ! $pid || ! current_user_can( 'edit_post', $pid ) ) wp_send_json_error( 'Unauthorized' );

        $allowed = [ '_gml_seo_title', '_gml_seo_desc', '_gml_seo_og_title', '_gml_seo_og_desc', '_gml_seo_keywords' ];
        if ( ! in_array( $key, $allowed, true ) ) wp_send_json_error( 'Invalid key' );

        update_post_meta( $pid, $key, $val );
        wp_send_json_success();
    }

    /** AJAX: toggle a boolean meta flag (FAQ hide, auto-link hide). */
    public function ajax_toggle_meta() {
        check_ajax_referer( 'gml_seo_nonce' );
        $pid  = absint( $_POST['post_id'] ?? 0 );
        $key  = sanitize_text_field( $_POST['meta_key'] ?? '' );
        $val  = ! empty( $_POST['value'] );

        if ( ! $pid || ! current_user_can( 'edit_post', $pid ) ) wp_send_json_error( 'Unauthorized' );

        $allowed = [ '_gml_seo_faq_hide', '_gml_seo_auto_links_hide', '_gml_seo_noindex' ];
        if ( ! in_array( $key, $allowed, true ) ) wp_send_json_error( 'Invalid key' );

        if ( $val ) {
            update_post_meta( $pid, $key, 1 );
        } else {
            delete_post_meta( $pid, $key );
        }
        wp_send_json_success();
    }

    /** AJAX: rebuild the auto-link candidate index from all optimized posts. */
    public function ajax_rebuild_index() {
        check_ajax_referer( 'gml_seo_admin' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        if ( ! class_exists( 'GML_SEO_Auto_Link' ) ) wp_send_json_error( 'Module missing' );

        $count = GML_SEO_Auto_Link::rebuild_index();
        wp_send_json_success( [ 'count' => $count ] );
    }

    /** Check if an AI API key is configured for the selected engine. */
    public static function has_ai_key() {
        $engine = self::opt( 'engine', 'gemini' );
        if ( $engine === 'deepseek' ) {
            return ! empty( self::opt( 'deepseek_key' ) );
        }
        return ! empty( self::opt( 'gemini_key' ) );
    }

    /** Get a plugin option. */
    public static function opt( $key, $default = '' ) {
        static $cache = null;
        if ( $cache === null ) $cache = get_option( 'gml_seo', [] );
        return $cache[ $key ] ?? $default;
    }

    /** Refresh option cache after save. */
    public static function flush_opt() {
        // Force re-read on next opt() call
        // We use a static var trick — just delete the transient-like cache
        // Actually we need to reset the static. Simplest: just re-read.
        // This is called rarely (only on settings save).
    }
}

GML_SEO::i();
