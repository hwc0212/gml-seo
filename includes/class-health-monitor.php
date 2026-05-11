<?php
/**
 * SEO Health Monitor — the continuous optimization engine.
 *
 * This is what makes GML AI SEO a "scheduled worker" rather than a one-off
 * tool. Every week (configurable), it:
 *
 *  1. Scans all published content and scores each post's SEO health
 *  2. Identifies pages that need re-optimization based on multiple signals:
 *     - Content changed since last AI analysis (content hash mismatch)
 *     - Content freshness (older than N days without refresh)
 *     - Missing / incomplete SEO data (no title, no description, no score)
 *     - Low SEO score (< 60) from previous analysis
 *     - Never analyzed
 *  3. Queues them for AI re-optimization in priority order
 *  4. Processes the queue in small batches to avoid API rate limits
 *  5. Logs the run for the admin dashboard
 *
 * Google's official guidance (May 2025 "succeeding in AI search"):
 *  - Content must be unique, valuable, people-first
 *  - Structured data must match visible content
 *  - Page experience matters (Core Web Vitals, clear main content)
 *  - Evolve with users (search is always evolving)
 *
 * The weekly scheduled worker keeps the site aligned with these principles
 * even as content ages and Google's guidance evolves.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_Health_Monitor {

    const CRON_WEEKLY   = 'gml_seo_weekly_audit';
    const CRON_PROCESS  = 'gml_seo_process_queue';
    const QUEUE_OPTION  = 'gml_seo_health_queue';
    const REPORT_OPTION = 'gml_seo_health_report';
    const LOG_OPTION    = 'gml_seo_health_log';

    // Freshness thresholds (days) — content older than this without refresh
    // is considered "stale" regardless of other signals.
    const STALE_DAYS_NEWS    = 30;   // news, announcements
    const STALE_DAYS_TUTORIAL = 90;  // how-to, guides
    const STALE_DAYS_GENERAL  = 180; // general content
    const STALE_DAYS_EVERGREEN = 365; // evergreen reference

    // Batch processing — how many posts to re-optimize per cron tick
    const BATCH_SIZE = 3;

    public function __construct() {
        // Register custom cron schedule
        add_filter( 'cron_schedules', [ $this, 'add_schedule' ] );

        // Main cron handlers
        add_action( self::CRON_WEEKLY, [ $this, 'run_weekly_audit' ] );
        add_action( self::CRON_PROCESS, [ $this, 'process_queue' ] );

        // Ensure cron jobs are scheduled
        add_action( 'admin_init', [ $this, 'ensure_scheduled' ] );

        // AJAX: manual audit trigger
        add_action( 'wp_ajax_gml_seo_run_audit', [ $this, 'ajax_run_audit' ] );
        add_action( 'wp_ajax_gml_seo_process_now', [ $this, 'ajax_process_now' ] );
    }

    // ══════════════════════════════════════════════════════════════════
    //  Cron Schedule Registration
    // ══════════════════════════════════════════════════════════════════

    public function add_schedule( $schedules ) {
        if ( ! isset( $schedules['gml_seo_weekly'] ) ) {
            $schedules['gml_seo_weekly'] = [
                'interval' => 7 * DAY_IN_SECONDS,
                'display'  => __( 'Once Weekly (GML SEO)', 'gml-seo' ),
            ];
        }
        return $schedules;
    }

    public function ensure_scheduled() {
        $freq = GML_SEO::opt( 'audit_frequency', 'weekly' );

        if ( $freq === 'disabled' ) {
            wp_clear_scheduled_hook( self::CRON_WEEKLY );
            return;
        }

        $interval = $freq === 'daily'
            ? 'daily'
            : ( $freq === 'monthly' ? 'gml_seo_weekly' : 'gml_seo_weekly' );

        if ( ! wp_next_scheduled( self::CRON_WEEKLY ) ) {
            // Schedule first run 1 hour from now to avoid activation pileup
            wp_schedule_event( time() + HOUR_IN_SECONDS, $interval, self::CRON_WEEKLY );
        }
    }

    public static function activate_cron() {
        if ( ! wp_next_scheduled( self::CRON_WEEKLY ) ) {
            wp_schedule_event(
                time() + HOUR_IN_SECONDS,
                'gml_seo_weekly',
                self::CRON_WEEKLY
            );
        }
    }

    public static function deactivate_cron() {
        wp_clear_scheduled_hook( self::CRON_WEEKLY );
        wp_clear_scheduled_hook( self::CRON_PROCESS );
    }

    // ══════════════════════════════════════════════════════════════════
    //  Weekly Audit — scans all posts and builds the priority queue
    // ══════════════════════════════════════════════════════════════════

    public function run_weekly_audit() {
        if ( ! GML_SEO::has_ai_key() ) {
            $this->log( 'Audit skipped: no AI key configured.' );
            return;
        }

        global $wpdb;

        // All published, public post types
        $types = get_post_types( [ 'public' => true ], 'names' );
        unset( $types['attachment'] );
        $type_in = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";

        $posts = $wpdb->get_results(
            "SELECT ID, post_title, post_type, post_content, post_modified_gmt, post_date_gmt
             FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type IN ({$type_in})
             LIMIT 2000"
        );

        $queue = [];
        $stats = [
            'total'       => count( $posts ),
            'healthy'     => 0,
            'stale'       => 0,
            'changed'     => 0,
            'missing'     => 0,
            'low_score'   => 0,
            'never'       => 0,
            'queued'      => 0,
        ];

        foreach ( $posts as $p ) {
            $evaluation = $this->evaluate_post( $p );

            if ( $evaluation['healthy'] ) {
                $stats['healthy']++;
                continue;
            }

            // Track reasons for dashboard display
            foreach ( $evaluation['reasons'] as $r ) {
                if ( isset( $stats[ $r ] ) ) $stats[ $r ]++;
            }

            $queue[] = [
                'id'       => (int) $p->ID,
                'priority' => $evaluation['priority'],
                'reasons'  => $evaluation['reasons'],
                'queued'   => time(),
            ];
        }

        // Sort by priority descending (higher = more urgent)
        usort( $queue, fn( $a, $b ) => $b['priority'] - $a['priority'] );

        $stats['queued'] = count( $queue );

        update_option( self::QUEUE_OPTION, $queue, false );
        update_option( self::REPORT_OPTION, [
            'last_run' => current_time( 'mysql' ),
            'stats'    => $stats,
        ], false );

        $this->log( sprintf(
            'Weekly audit complete: %d posts scanned, %d healthy, %d queued for re-optimization.',
            $stats['total'], $stats['healthy'], $stats['queued']
        ) );

        // v1.9.0 Gradual Mode: append an observation-period digest line so
        // admins can track adoption progress from the weekly log.
        if ( class_exists( 'GML_SEO_Gradual_Mode_Manager' )
             && GML_SEO_Gradual_Mode_Manager::is_active() ) {
            $g = GML_SEO_Gradual_Mode_Manager::weekly_digest_stats();
            $this->log( sprintf(
                'Gradual digest: migrated=%d, still using migrated data=%d, AI suggestions adopted=%d (%.1f%%). Close the observation period in Settings once you\'re satisfied with adoption.',
                (int) ( $g['migrated_total'] ?? 0 ),
                (int) ( $g['still_on_migrated'] ?? 0 ),
                (int) ( $g['adopted'] ?? 0 ),
                (float) ( $g['adopted_pct'] ?? 0 )
            ) );
        }

        // Start processing the queue immediately if there's work to do
        if ( ! empty( $queue ) && ! wp_next_scheduled( self::CRON_PROCESS ) ) {
            wp_schedule_single_event( time() + 60, self::CRON_PROCESS );
        }
    }

    /**
     * Evaluate a single post's SEO health.
     * Returns { healthy, priority, reasons[] }
     *
     * Priority score (0-100, higher = more urgent):
     *  - Never analyzed: 90
     *  - Missing title/desc: 80
     *  - Content changed since last analysis: 70
     *  - Low score (<60): 60
     *  - Stale (past freshness threshold): 40
     */
    public function evaluate_post( $post ) {
        $id = $post->ID;
        $reasons = [];
        $priority = 0;

        $generated = get_post_meta( $id, '_gml_seo_generated', true );
        $hash      = get_post_meta( $id, '_gml_seo_hash', true );
        $title     = get_post_meta( $id, '_gml_seo_title', true );
        $desc      = get_post_meta( $id, '_gml_seo_desc', true );
        $score     = (int) get_post_meta( $id, '_gml_seo_score', true );

        // 1. Never analyzed
        if ( ! $generated ) {
            $reasons[] = 'never';
            $priority  = max( $priority, 90 );
        }

        // 2. Missing essential SEO data (analyzed but something got lost)
        if ( $generated && ( ! $title || ! $desc ) ) {
            $reasons[] = 'missing';
            $priority  = max( $priority, 80 );
        }

        // 3. Content changed since last analysis
        if ( $generated && $hash ) {
            $current_hash = md5( $post->post_title . $post->post_content );
            if ( $current_hash !== $hash ) {
                $reasons[] = 'changed';
                $priority  = max( $priority, 70 );
            }
        }

        // 4. Low SEO score from last analysis
        if ( $generated && $score > 0 && $score < 60 ) {
            $reasons[] = 'low_score';
            $priority  = max( $priority, 60 );
        }

        // 5. Stale — older than freshness threshold for its type
        if ( $generated ) {
            $threshold_days = $this->freshness_threshold( $post );
            $last_analyzed  = strtotime( $generated );
            $age_days       = ( time() - $last_analyzed ) / DAY_IN_SECONDS;
            if ( $age_days > $threshold_days ) {
                $reasons[] = 'stale';
                $priority  = max( $priority, 40 );
            }
        }

        return [
            'healthy'  => empty( $reasons ),
            'priority' => $priority,
            'reasons'  => $reasons,
        ];
    }

    /**
     * Guess the appropriate freshness threshold by post type and category.
     * Can be filtered by themes/other plugins.
     */
    private function freshness_threshold( $post ) {
        $default = self::STALE_DAYS_GENERAL;

        // Post types
        if ( $post->post_type === 'page' ) {
            $default = self::STALE_DAYS_EVERGREEN;
        } elseif ( $post->post_type === 'product' ) {
            $default = self::STALE_DAYS_GENERAL;
        }

        // Category hints for posts
        if ( $post->post_type === 'post' ) {
            $cats = wp_get_post_categories( $post->ID, [ 'fields' => 'slugs' ] );
            $news_cats     = [ 'news', 'announcement', 'press', '新闻', '公告' ];
            $tutorial_cats = [ 'tutorial', 'guide', 'how-to', 'howto', '教程', '指南' ];

            if ( array_intersect( $cats, $news_cats ) ) {
                $default = self::STALE_DAYS_NEWS;
            } elseif ( array_intersect( $cats, $tutorial_cats ) ) {
                $default = self::STALE_DAYS_TUTORIAL;
            }
        }

        return (int) apply_filters( 'gml_seo_freshness_threshold_days', $default, $post );
    }

    // ══════════════════════════════════════════════════════════════════
    //  Queue Processor — re-optimizes posts in batches
    // ══════════════════════════════════════════════════════════════════

    public function process_queue() {
        $queue = get_option( self::QUEUE_OPTION, [] );
        if ( empty( $queue ) ) return;

        if ( ! class_exists( 'GML_SEO_AI_Engine' ) ) return;
        $engine = new GML_SEO_AI_Engine();

        $batch = array_splice( $queue, 0, self::BATCH_SIZE );
        update_option( self::QUEUE_OPTION, $queue, false );

        $done = 0;
        $fail = 0;
        foreach ( $batch as $item ) {
            // Clear hash so generate_for_post really re-runs
            delete_post_meta( $item['id'], '_gml_seo_hash' );

            $result = $engine->generate_for_post( $item['id'] );
            if ( is_wp_error( $result ) ) {
                $fail++;
                $this->log( sprintf(
                    'Health optimize FAILED [post %d]: %s',
                    $item['id'],
                    $result->get_error_message()
                ) );
            } else {
                $done++;
            }

            // Brief pause between API calls inside a single batch
            if ( count( $batch ) > 1 ) usleep( 500000 ); // 0.5s
        }

        $this->log( sprintf(
            'Queue batch processed: %d done, %d failed, %d remaining.',
            $done, $fail, count( $queue )
        ) );

        // Schedule next batch if queue has more items
        if ( ! empty( $queue ) ) {
            wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_PROCESS );
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  AJAX handlers
    // ══════════════════════════════════════════════════════════════════

    public function ajax_run_audit() {
        check_ajax_referer( 'gml_seo_admin' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $this->run_weekly_audit();
        $report = get_option( self::REPORT_OPTION, [] );
        wp_send_json_success( $report );
    }

    public function ajax_process_now() {
        check_ajax_referer( 'gml_seo_admin' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $this->process_queue();
        wp_send_json_success( [
            'remaining' => count( get_option( self::QUEUE_OPTION, [] ) ),
        ] );
    }

    // ══════════════════════════════════════════════════════════════════
    //  Logging (for Dashboard display)
    // ══════════════════════════════════════════════════════════════════

    private function log( $message ) {
        $log = get_option( self::LOG_OPTION, [] );
        $log[] = [
            'time'    => current_time( 'mysql' ),
            'message' => $message,
        ];
        // Keep last 50 entries
        if ( count( $log ) > 50 ) {
            $log = array_slice( $log, -50 );
        }
        update_option( self::LOG_OPTION, $log, false );
    }

    // ══════════════════════════════════════════════════════════════════
    //  Public API for admin UI
    // ══════════════════════════════════════════════════════════════════

    public static function get_report() {
        return get_option( self::REPORT_OPTION, [] );
    }

    public static function get_queue() {
        return get_option( self::QUEUE_OPTION, [] );
    }

    public static function get_log( $limit = 20 ) {
        $log = get_option( self::LOG_OPTION, [] );
        return array_slice( array_reverse( $log ), 0, $limit );
    }

    public static function get_next_run() {
        return wp_next_scheduled( self::CRON_WEEKLY );
    }
}
