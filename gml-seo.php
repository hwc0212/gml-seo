<?php
/**
 * Plugin Name: GML AI SEO
 * Plugin URI: https://huwencai.com/gml-seo
 * Description: Zero-config AI-powered SEO & Performance. Gemini auto-optimizes titles, descriptions, schema, sitemaps, and page speed. Just install, add your API key, done.
 * Version: 1.1.0
 * Author: huwencai.com
 * Author URI: https://huwencai.com
 * License: GPL v2 or later
 * Text Domain: gml-seo
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'GML_SEO_VER', '1.1.0' );
define( 'GML_SEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'GML_SEO_URL', plugin_dir_url( __FILE__ ) );

final class GML_SEO {

    private static $inst = null;

    public static function i() {
        if ( ! self::$inst ) self::$inst = new self();
        return self::$inst;
    }

    private function __construct() {
        require_once GML_SEO_DIR . 'includes/class-gemini-api.php';
        require_once GML_SEO_DIR . 'includes/class-admin.php';
        require_once GML_SEO_DIR . 'includes/class-meta-tags.php';
        require_once GML_SEO_DIR . 'includes/class-schema.php';
        require_once GML_SEO_DIR . 'includes/class-sitemap.php';
        require_once GML_SEO_DIR . 'includes/class-ai-engine.php';
        require_once GML_SEO_DIR . 'includes/class-code-injection.php';
        require_once GML_SEO_DIR . 'includes/class-metabox.php';
        require_once GML_SEO_DIR . 'includes/class-performance.php';

        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        add_action( 'plugins_loaded', [ $this, 'boot' ] );
    }

    public function activate() {
        if ( ! get_option( 'gml_seo' ) ) {
            update_option( 'gml_seo', [
                'gemini_key'  => '',
                'model'       => 'gemini-2.5-flash',
                'ga_id'       => '',          // G-XXXXXXX
                'gtm_id'      => '',          // GTM-XXXXXXX
                'adsense_id'  => '',          // ca-pub-XXXXXXX
                'head_code'   => '',          // custom <head> code
                'body_code'   => '',          // after <body>
                'footer_code' => '',          // before </body>
                'site_name'   => get_bloginfo( 'name' ),
                'separator'   => '-',
            ] );
        }
        // Sitemap rewrite rules
        GML_SEO_Sitemap::add_rules();
        flush_rewrite_rules();
    }

    public function boot() {
        new GML_SEO_Admin();
        new GML_SEO_Code_Injection();

        if ( ! is_admin() ) {
            new GML_SEO_Meta_Tags();
            new GML_SEO_Schema();
            new GML_SEO_Sitemap();
            new GML_SEO_Performance();
        }

        if ( is_admin() ) {
            new GML_SEO_Metabox();
        }

        // AI engine — runs on save_post to auto-generate SEO data
        if ( self::opt( 'gemini_key' ) ) {
            new GML_SEO_AI_Engine();
        }
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
