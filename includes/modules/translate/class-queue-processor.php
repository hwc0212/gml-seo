<?php
/**
 * Queue Processor class - WP Cron async translation
 *
 * @package GML_Translate
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GML Queue Processor class
 */
class GML_Queue_Processor {
    
    /**
     * Cron hook name
     */
    const CRON_HOOK = 'gml_process_queue';
    
    /**
     * Batch size — number of queue items fetched per cron run.
     * With batch translation, all items going to the same language+type
     * are sent in a single API call, so we can afford a larger batch.
     */
    const BATCH_SIZE = 30;
    
    /**
     * Constructor
     *
     * Keep this as lightweight as possible — it runs on every page load.
     * Only register the cron action handler here; defer the schedule check
     * to a later hook so it doesn't run during normal frontend requests.
     */
    public function __construct() {
        // Register the cron interval filter — must be registered early so
        // WordPress knows about 'every_minute' before it evaluates schedules.
        add_filter('cron_schedules', [$this, 'add_cron_interval']);

        // Register the cron action handler.
        add_action(self::CRON_HOOK, [$this, 'process_batch']);

        // Defer the "schedule if not scheduled" check to wp_loaded so it
        // doesn't run during plugins_loaded (which fires on every request
        // including frontend pages) and avoids triggering wp_next_scheduled()
        // — and the full hook chain it invokes — on every page load.
        add_action('wp_loaded', [$this, 'maybe_schedule_cron']);
    }

    /**
     * Schedule cron if it isn't already scheduled.
     * Runs once per request on wp_loaded (admin or cron only — skipped on
     * frontend to avoid unnecessary DB queries on every page view).
     */
    public function maybe_schedule_cron() {
        // Only bother checking on admin pages or during cron runs.
        if ( ! is_admin() && ! ( defined('DOING_CRON') && DOING_CRON ) ) {
            return;
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            $this->schedule_cron();
        }
    }
    
    /**
     * Add custom cron interval (every minute)
     *
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     */
    public function add_cron_interval($schedules) {
        if (!isset($schedules['every_minute'])) {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display'  => __('Every Minute', 'gml-translate'),
            ];
        }
        return $schedules;
    }
    
    /**
     * Schedule cron job
     */
    private function schedule_cron() {
        wp_schedule_event(time(), 'every_minute', self::CRON_HOOK);
    }
    
    /**
     * Unschedule cron job
     */
    public static function unschedule_cron() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }
    
    /**
     * Process batch of queue items.
     *
     * Groups items by (target_lang, context_type) and sends each group as a
     * single batch API call. This reduces HTTP round-trips and saves ~90% of
     * system instruction tokens compared to one-item-per-call.
     */
    public function process_batch() {
        // Respect the global paused flag
        if (get_option('gml_translation_paused', false)) {
            return;
        }
        if (!get_option('gml_translation_enabled', false)) {
            return;
        }

        global $wpdb;

        $queue_table = $wpdb->prefix . 'gml_queue';

        // ── Recover items stuck in 'processing' ──────────────────────────────
        $wpdb->query( "UPDATE $queue_table SET status = 'pending' WHERE status = 'processing'" );

        // Build list of per-language paused codes
        $paused_langs = [];
        foreach (get_option('gml_languages', []) as $l) {
            if (!empty($l['paused'])) {
                $paused_langs[] = $l['code'];
            }
        }

        // Get pending items, excluding individually-paused languages
        $exclude_sql = '';
        if (!empty($paused_langs)) {
            $placeholders = implode(',', array_fill(0, count($paused_langs), '%s'));
            $exclude_sql  = $wpdb->prepare(" AND target_lang NOT IN ($placeholders)", $paused_langs);
        }
        
        $limit = (int) self::BATCH_SIZE;
        $sql   = "SELECT * FROM $queue_table
                  WHERE status = 'pending' AND attempts < 3
                  $exclude_sql
                  ORDER BY priority DESC, created_at ASC
                  LIMIT $limit";
        $items = $wpdb->get_results( $sql );
        
        if (empty($items)) {
            return;
        }

        // Mark all as processing
        $ids = array_map( function($item) { return (int) $item->id; }, $items );
        $id_list = implode( ',', $ids );
        $wpdb->query( "UPDATE $queue_table SET status = 'processing' WHERE id IN ($id_list)" );
        
        // ── Group items by (target_lang, context_type) ───────────────────────
        // Each group becomes one batch API call.
        // context_type 'seo_meta' and 'seo_title' use the SEO prompt,
        // but they are kept in SEPARATE groups so that title and description
        // are never sent in the same batch (Gemini tends to merge them).
        // 'text' and 'attribute' share the same prompt and group together.
        $groups = [];
        foreach ( $items as $item ) {
            $ctx = trim( $item->context_type ?? '' );
            if ( $ctx === 'seo_title' ) {
                $type_key = 'seo_title';
            } elseif ( $ctx === 'seo_meta' ) {
                $type_key = 'seo';
            } else {
                $type_key = 'text';
            }
            $group_key = $item->target_lang . '|' . $type_key;
            $groups[ $group_key ][] = $item;
        }

        $api        = new GML_Gemini_API();
        $translator = new GML_Translator();
        $parser     = new GML_HTML_Parser();

        foreach ( $groups as $group_key => $group_items ) {
            [ $target_lang, $type_key ] = explode( '|', $group_key );
            $source_lang = $group_items[0]->source_lang;

            // Collect texts for batch
            $texts = [];
            foreach ( $group_items as $item ) {
                $texts[] = $item->source_text;
            }

            $api_type = $type_key; // 'seo_title', 'seo', or 'text'

            try {
                $translated_texts = $api->translate_batch( $texts, $source_lang, $target_lang, $api_type );

                // Save each result
                foreach ( $group_items as $idx => $item ) {
                    $translated = $translated_texts[ $idx ] ?? null;
                    if ( $translated === null ) {
                        $this->fail_or_retry_item( $wpdb, $queue_table, $item, 'Missing from batch result' );
                        continue;
                    }

                    if ( ! $parser->verify_brand_protection( $item->source_text, $translated ) ) {
                        error_log( "GML: Brand protection warning for queue item #{$item->id} — saving anyway" );
                    }

                    $translator->save_to_index(
                        $item->source_hash, $item->source_text, $translated,
                        $item->source_lang, $item->target_lang, $item->context_type, 'auto'
                    );

                    $wpdb->update( $queue_table,
                        [ 'status' => 'completed', 'processed_at' => current_time( 'mysql' ) ],
                        [ 'id' => $item->id ]
                    );
                }

            } catch ( Exception $e ) {
                // ── Batch failed — fallback to single-item translation ────────
                // Instead of marking all items as failed, try each one individually.
                // This prevents one "problem text" from dragging down the entire batch.
                error_log( "GML: Batch failed ({$group_key}): {$e->getMessage()} — falling back to single-item mode" );

                foreach ( $group_items as $item ) {
                    try {
                        $single_result = $api->translate_batch(
                            [ $item->source_text ], $source_lang, $target_lang, $api_type
                        );
                        $translated = $single_result[0] ?? null;

                        if ( $translated === null || $translated === '' ) {
                            $this->fail_or_retry_item( $wpdb, $queue_table, $item, 'Empty single-item result' );
                            continue;
                        }

                        $translator->save_to_index(
                            $item->source_hash, $item->source_text, $translated,
                            $item->source_lang, $item->target_lang, $item->context_type, 'auto'
                        );

                        $wpdb->update( $queue_table,
                            [ 'status' => 'completed', 'processed_at' => current_time( 'mysql' ) ],
                            [ 'id' => $item->id ]
                        );
                    } catch ( Exception $single_e ) {
                        $this->fail_or_retry_item( $wpdb, $queue_table, $item, $single_e->getMessage() );
                    }

                    usleep( 100000 ); // 0.1s between single calls
                }
            }

            // Small delay between groups to avoid rate limiting
            if ( count( $groups ) > 1 ) {
                usleep( 200000 ); // 0.2s
            }
        }

        // ── Invalidate page-level HTML caches ────────────────────────────────
        // New translations were saved, so cached pages may be stale.
        // We flush ALL gml_page_* transients for the affected languages.
        // This is a lightweight operation — transients auto-expire anyway,
        // but flushing ensures visitors see new translations immediately.
        $affected_langs = [];
        foreach ( $groups as $group_key => $_ ) {
            [ $lang, ] = explode( '|', $group_key );
            $affected_langs[ $lang ] = true;
        }
        if ( ! empty( $affected_langs ) ) {
            global $wpdb;
            // Delete transients matching gml_page_* pattern.
            // WordPress stores transients in options table with _transient_ prefix.
            $wpdb->query(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_gml_page_%'
                    OR option_name LIKE '_transient_timeout_gml_page_%'"
            );
        }
    }
    
    /**
     * Mark a single queue item as failed or re-queue for retry.
     *
     * If the item has been attempted fewer than 3 times, it goes back to
     * 'pending' so the next cron run picks it up again. After 3 attempts
     * it is marked 'failed' permanently (until the admin clicks Retry).
     *
     * @param wpdb   $wpdb        WordPress DB instance
     * @param string $queue_table  Fully-qualified table name
     * @param object $item         Queue row object
     * @param string $error_message Human-readable error
     */
    private function fail_or_retry_item( $wpdb, $queue_table, $item, $error_message ) {
        $new_attempts = (int) $item->attempts + 1;
        $new_status   = $new_attempts >= 3 ? 'failed' : 'pending';
        $wpdb->update(
            $queue_table,
            [
                'status'        => $new_status,
                'attempts'      => $new_attempts,
                'error_message' => mb_substr( $error_message, 0, 500 ),
            ],
            [ 'id' => $item->id ]
        );
    }

    /**
     * Get queue statistics
     *
     * @return array Queue stats
     */
    public static function get_queue_stats() {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'gml_queue';
        
        $stats = [
            'pending' => $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'pending'"),
            'processing' => $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'processing'"),
            'completed' => $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'completed'"),
            'failed' => $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'failed'"),
        ];
        
        $stats['total'] = array_sum($stats);
        
        return $stats;
    }
    
    /**
     * Clear completed queue items
     *
     * @param int $days_old Days old to clear
     * @return int Number of items cleared
     */
    public static function clear_completed($days_old = 7) {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'gml_queue';
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $queue_table 
             WHERE status = 'completed' 
             AND processed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days_old
        ));
        
        return $deleted;
    }
    
    /**
     * Retry failed queue items
     *
     * @return int Number of items reset
     */
    public static function retry_failed() {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'gml_queue';
        
        $updated = $wpdb->query(
            "UPDATE $queue_table 
             SET status = 'pending', attempts = 0, error_message = NULL
             WHERE status = 'failed'"
        );
        
        return $updated;
    }
}
