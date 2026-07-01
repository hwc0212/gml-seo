<?php
/**
 * Scheduled refresh for analytics data used by AI prompts.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_Analytics_Sync {

    const HOOK          = 'gml_seo_analytics_sync';
    const STATUS_OPTION = 'gml_seo_analytics_sync_status';

    public static function register() {
        add_action( self::HOOK, [ __CLASS__, 'sync_all' ] );
    }

    public static function activate_cron() {
        if ( ! apply_filters( 'gml_seo_analytics_auto_sync_enabled', true ) ) {
            self::deactivate_cron();
            return;
        }
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
        }
    }

    public static function deactivate_cron() {
        wp_clear_scheduled_hook( self::HOOK );
    }

    public static function get_status() {
        $status = get_option( self::STATUS_OPTION, [] );
        return is_array( $status ) ? $status : [];
    }

    public static function sync_all() {
        if ( ! apply_filters( 'gml_seo_analytics_auto_sync_enabled', true ) ) {
            $results = [
                'synced_at' => current_time( 'mysql' ),
                'gsc'       => [ 'skipped' => true, 'message' => 'Analytics auto-sync is disabled by filter.' ],
                'ga4'       => [ 'skipped' => true, 'message' => 'Analytics auto-sync is disabled by filter.' ],
            ];
            update_option( self::STATUS_OPTION, $results, false );
            return $results;
        }

        $strategy = class_exists( 'GML_SEO_Strategy' ) ? GML_SEO_Strategy::get() : [];
        $results  = [
            'synced_at' => current_time( 'mysql' ),
            'gsc'       => [ 'skipped' => true, 'message' => 'Search Console Property URL is not configured.' ],
            'ga4'       => [ 'skipped' => true, 'message' => 'GA4 Property ID is not configured.' ],
        ];

        if ( ! empty( $strategy['gsc_property_url'] ) && class_exists( 'GML_SEO_Search_Console' ) ) {
            $gsc = GML_SEO_Search_Console::sync();
            $results['gsc'] = is_wp_error( $gsc )
                ? [ 'success' => false, 'message' => $gsc->get_error_message() ]
                : [ 'success' => true, 'message' => 'Search Console synced.' ];
        }

        if ( ! empty( $strategy['ga4_property_id'] ) && class_exists( 'GML_SEO_GA4' ) ) {
            $ga4 = GML_SEO_GA4::sync();
            $results['ga4'] = is_wp_error( $ga4 )
                ? [ 'success' => false, 'message' => $ga4->get_error_message() ]
                : [ 'success' => true, 'message' => 'GA4 synced.' ];
        }

        update_option( self::STATUS_OPTION, $results, false );
        return $results;
    }
}
