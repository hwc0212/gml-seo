<?php
/**
 * GML Translation Editor — AJAX handlers for viewing/editing translations
 *
 * Provides a Weglot-like interface for managing translated content:
 * - Browse all translations per language
 * - Search translations
 * - Manually edit translations (saved as 'manual' status)
 * - Delete individual translations
 *
 * @package GML_Translate
 * @since 2.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Translation_Editor {

    public function __construct() {
        add_action( 'wp_ajax_gml_get_translations',    [ $this, 'ajax_get_translations' ] );
        add_action( 'wp_ajax_gml_save_translation',    [ $this, 'ajax_save_translation' ] );
        add_action( 'wp_ajax_gml_delete_translation',  [ $this, 'ajax_delete_translation' ] );
        add_action( 'wp_ajax_gml_retry_failed',        [ $this, 'ajax_retry_failed' ] );
        add_action( 'wp_ajax_gml_crawl_action',        [ $this, 'ajax_crawl_action' ] );
        add_action( 'wp_ajax_gml_crawl_status',        [ $this, 'ajax_crawl_status' ] );
    }

    /**
     * Get paginated translations for a language.
     */
    public function ajax_get_translations() {
        check_ajax_referer( 'gml_editor_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'gml_index';

        $lang   = sanitize_text_field( $_POST['lang'] ?? '' );
        $search = sanitize_text_field( $_POST['search'] ?? '' );
        $page   = max( 1, (int) ( $_POST['page'] ?? 1 ) );
        $per    = 20;
        $offset = ( $page - 1 ) * $per;
        $filter = sanitize_text_field( $_POST['filter'] ?? 'all' ); // all, auto, manual

        $where = $wpdb->prepare( "WHERE target_lang = %s AND status IN ('auto','manual')", $lang );

        if ( $filter === 'auto' ) {
            $where .= " AND status = 'auto'";
        } elseif ( $filter === 'manual' ) {
            $where .= " AND status = 'manual'";
        }

        if ( $search ) {
            $like   = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare( " AND (source_text LIKE %s OR translated_text LIKE %s)", $like, $like );
        }

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table $where" );
        $rows  = $wpdb->get_results(
            "SELECT id, source_hash, source_text, translated_text, context_type, status, updated_at
             FROM $table $where
             ORDER BY updated_at DESC
             LIMIT $per OFFSET $offset"
        );

        wp_send_json_success( [
            'rows'       => $rows,
            'total'      => $total,
            'page'       => $page,
            'pages'      => ceil( $total / $per ),
            'per_page'   => $per,
        ] );
    }

    /**
     * Save a manual translation edit.
     */
    public function ajax_save_translation() {
        check_ajax_referer( 'gml_editor_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'gml_index';

        $id         = (int) ( $_POST['id'] ?? 0 );
        $translated = wp_unslash( $_POST['translated_text'] ?? '' );

        if ( ! $id || $translated === '' ) {
            wp_send_json_error( 'Missing parameters' );
        }

        $wpdb->update( $table, [
            'translated_text' => wp_kses_post( $translated ),
            'status'          => 'manual',
            'updated_at'      => current_time( 'mysql' ),
        ], [ 'id' => $id ] );

        // Invalidate page caches so the manual edit is visible immediately
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_gml_page_%'
                OR option_name LIKE '_transient_timeout_gml_page_%'"
        );

        // Invalidate translation dictionary cache
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT source_lang, target_lang FROM $table WHERE id = %d", $id
        ) );
        if ( $row ) {
            GML_Translator::invalidate_cache( $row->source_lang, $row->target_lang );
        }

        wp_send_json_success( [ 'message' => __( 'Translation saved.', 'gml-translate' ) ] );
    }

    /**
     * Delete a single translation.
     */
    public function ajax_delete_translation() {
        check_ajax_referer( 'gml_editor_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $id = (int) ( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Missing ID' );
        }

        $table = $wpdb->prefix . 'gml_index';

        // Get lang info before deleting for cache invalidation
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT source_lang, target_lang FROM $table WHERE id = %d", $id
        ) );

        $wpdb->delete( $table, [ 'id' => $id ] );

        // Invalidate caches
        if ( $row ) {
            GML_Translator::invalidate_cache( $row->source_lang, $row->target_lang );
        }
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_gml_page_%'
                OR option_name LIKE '_transient_timeout_gml_page_%'"
        );

        wp_send_json_success();
    }

    /**
     * Retry all failed translations for a language.
     */
    public function ajax_retry_failed() {
        check_ajax_referer( 'gml_editor_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $lang  = sanitize_text_field( $_POST['lang'] ?? '' );
        $queue = $wpdb->prefix . 'gml_queue';

        // Reset failed items back to pending with attempts=0
        if ( $lang ) {
            $count = $wpdb->query( $wpdb->prepare(
                "UPDATE $queue SET status = 'pending', attempts = 0, error_message = NULL
                 WHERE status = 'failed' AND target_lang = %s",
                $lang
            ) );
        } else {
            $count = $wpdb->query(
                "UPDATE $queue SET status = 'pending', attempts = 0, error_message = NULL
                 WHERE status = 'failed'"
            );
        }

        // Ensure translation is enabled and trigger cron
        update_option( 'gml_translation_enabled', true );
        update_option( 'gml_translation_paused', false );
        wp_schedule_single_event( time(), GML_Queue_Processor::CRON_HOOK );

        wp_send_json_success( [
            'message' => sprintf( __( '%d failed items queued for retry.', 'gml-translate' ), $count ),
            'count'   => $count,
        ] );
    }

    /**
     * Start/stop content crawl.
     */
    public function ajax_crawl_action() {
        check_ajax_referer( 'gml_editor_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $action = sanitize_text_field( $_POST['crawl_action'] ?? '' );

        if ( $action === 'start' ) {
            GML_Content_Crawler::start_crawl();

            // Enable the translation process so Queue Processor picks up
            // the items that the crawler queues, and per-language status shows "Running".
            update_option( 'gml_translation_enabled', true );
            update_option( 'gml_translation_paused', false );

            // Un-pause all languages
            $langs = get_option( 'gml_languages', [] );
            foreach ( $langs as &$l ) {
                $l['paused'] = false;
            }
            update_option( 'gml_languages', $langs );

            // Run the first crawl batch synchronously so the queue has data
            // immediately — don't wait for WP Cron to fire.
            $crawler = new GML_Content_Crawler();
            $crawler->crawl_batch();

            // Ensure queue processor cron is scheduled
            if ( ! wp_next_scheduled( GML_Queue_Processor::CRON_HOOK ) ) {
                wp_schedule_single_event( time(), GML_Queue_Processor::CRON_HOOK );
            }

            wp_send_json_success( [ 'message' => __( 'Content crawl started.', 'gml-translate' ) ] );
        } elseif ( $action === 'stop' ) {
            GML_Content_Crawler::stop_crawl();
            wp_send_json_success( [ 'message' => __( 'Content crawl stopped.', 'gml-translate' ) ] );
        }

        wp_send_json_error( 'Invalid action' );
    }

    /**
     * Get crawl status.
     */
    public function ajax_crawl_status() {
        check_ajax_referer( 'gml_editor_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // Piggyback: trigger queue processor on each status poll so translations
        // progress even if WP Cron is delayed or not firing reliably.
        if ( get_option( 'gml_translation_enabled', false ) && ! get_option( 'gml_translation_paused', false ) ) {
            if ( class_exists( 'GML_Queue_Processor' ) ) {
                try {
                    ( new GML_Queue_Processor() )->process_batch();
                } catch ( \Throwable $e ) {
                    // Silently ignore — this is a best-effort trigger
                }
            }
        }

        wp_send_json_success( GML_Content_Crawler::get_status() );
    }
}
