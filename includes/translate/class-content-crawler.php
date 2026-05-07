<?php
/**
 * GML Content Crawler — Discovers all site content and queues for translation
 *
 * Instead of waiting for page visits, this crawler fetches all published
 * pages/posts internally and feeds them through the HTML parser + translator
 * to populate the translation queue proactively.
 *
 * @package GML_Translate
 * @since 2.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Content_Crawler {

    const CRON_HOOK  = 'gml_crawl_content';
    const BATCH_SIZE = 5; // pages per cron run

    public function __construct() {
        add_action( self::CRON_HOOK, [ $this, 'crawl_batch' ] );
        add_filter( 'cron_schedules', [ $this, 'add_schedule' ] );

        // Resume crawl if it was interrupted by a plugin update.
        // WordPress deactivates → reactivates the plugin during updates,
        // which clears all cron schedules. If gml_crawl_running is still
        // true but the cron event is gone, re-schedule it.
        add_action( 'wp_loaded', [ $this, 'maybe_resume_crawl' ] );
    }

    /**
     * Re-schedule the crawl cron if it was lost during a plugin update.
     */
    public function maybe_resume_crawl() {
        if ( ! get_option( 'gml_crawl_running', false ) ) {
            return;
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'every_two_minutes', self::CRON_HOOK );
        }
    }

    public function add_schedule( $schedules ) {
        if ( ! isset( $schedules['every_two_minutes'] ) ) {
            $schedules['every_two_minutes'] = [
                'interval' => 120,
                'display'  => __( 'Every 2 Minutes', 'gml-translate' ),
            ];
        }
        return $schedules;
    }

    /**
     * Start a full-site crawl for all configured languages.
     */
    public static function start_crawl() {
        // Reset crawl progress
        delete_option( 'gml_crawl_offset' );
        // Cache total count so get_status() doesn't recount every time
        update_option( 'gml_crawl_total', self::count_crawlable_content() );
        update_option( 'gml_crawl_running', true );

        // Schedule recurring crawl
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'every_two_minutes', self::CRON_HOOK );
        }
        // Also trigger immediately
        wp_schedule_single_event( time(), self::CRON_HOOK );
    }

    /**
     * Stop the crawl.
     */
    public static function stop_crawl() {
        update_option( 'gml_crawl_running', false );
        delete_option( 'gml_crawl_total' );
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
        }
    }

    /**
     * Get crawl status.
     */
    public static function get_status() {
        $running = get_option( 'gml_crawl_running', false );
        $offset  = (int) get_option( 'gml_crawl_offset', 0 );
        // Use cached total to avoid counting all posts on every status check.
        // The total is cached when crawl starts and refreshed if missing.
        $total = (int) get_option( 'gml_crawl_total', 0 );
        if ( $running && $total === 0 ) {
            $total = self::count_crawlable_content();
            update_option( 'gml_crawl_total', $total );
        }
        return [
            'running'   => $running,
            'processed' => min( $offset, $total ),
            'total'     => $total,
            'percent'   => $total > 0 ? min( 100, round( $offset / $total * 100 ) ) : 0,
        ];
    }

    /**
     * Count total crawlable posts/pages.
     */
    public static function count_crawlable_content() {
        $post_types = get_post_types( [ 'public' => true ], 'names' );
        unset( $post_types['attachment'] );
        $count = 0;
        foreach ( $post_types as $pt ) {
            $count += (int) wp_count_posts( $pt )->publish;
        }
        return $count;
    }

    /**
     * Crawl a batch of pages — called by WP Cron.
     *
     * For each page, we do an internal HTTP request to get the rendered HTML,
     * then parse it and feed it to the translator (which queues untranslated strings).
     */
    public function crawl_batch() {
        if ( ! get_option( 'gml_crawl_running', false ) ) {
            return;
        }

        $languages   = get_option( 'gml_languages', [] );
        $source_lang = get_option( 'gml_source_lang', 'en' );

        if ( empty( $languages ) ) {
            self::stop_crawl();
            return;
        }

        $offset     = (int) get_option( 'gml_crawl_offset', 0 );
        $post_types = get_post_types( [ 'public' => true ], 'names' );
        // Exclude 'attachment' — media items don't have translatable content
        unset( $post_types['attachment'] );

        $posts = get_posts( [
            'post_type'      => array_values( $post_types ),
            'post_status'    => 'publish',
            'posts_per_page' => self::BATCH_SIZE,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ] );

        if ( empty( $posts ) ) {
            // Crawl complete
            self::stop_crawl();
            return;
        }

        $parser     = new GML_HTML_Parser();
        $translator = new GML_Translator();

        foreach ( $posts as $post ) {
            try {
                // Try to fetch the fully rendered page via internal HTTP request.
                // This captures template/plugin output (WooCommerce "Add to Cart",
                // "Related Products", breadcrumbs, sidebar widgets, etc.) that
                // build_post_html() cannot see.
                $html = $this->fetch_rendered_html( $post );

                // Fallback to simplified HTML if HTTP fetch fails
                if ( empty( $html ) ) {
                    $html = $this->build_post_html( $post );
                }

                if ( empty( $html ) ) {
                    continue;
                }

                $parsed = $parser->parse( $html );

                // Queue for each target language
                foreach ( $languages as $lang ) {
                    if ( $lang['code'] === $source_lang ) {
                        continue;
                    }
                    if ( ! empty( $lang['paused'] ) ) {
                        continue;
                    }
                    $translator->translate( $parsed, $lang['code'] );
                }
            } catch ( \Throwable $e ) {
                error_log( 'GML Crawler: Error processing post #' . $post->ID . ': ' . $e->getMessage() );
            }
        }

        // Update offset
        update_option( 'gml_crawl_offset', $offset + count( $posts ) );

        // Trigger queue processor immediately so translations start without
        // waiting for the next WP Cron tick (which may be delayed in some
        // hosting environments).
        if ( class_exists( 'GML_Queue_Processor' ) ) {
            try {
                ( new GML_Queue_Processor() )->process_batch();
            } catch ( \Throwable $e ) {
                error_log( 'GML Crawler: Queue processor error: ' . $e->getMessage() );
            }
        }
    }

    /**
     * Fetch the fully rendered HTML of a post via internal HTTP request.
     *
     * This captures ALL translatable text on the page, including:
     * - Theme template strings ("Read more", "Search results for", etc.)
     * - Plugin output (WooCommerce "Add to Cart", "Description", "Reviews",
     *   "Related Products", "You may also like…", breadcrumbs, etc.)
     * - Sidebar widgets, footer content, navigation menus
     * - Dynamic content that only appears in the rendered page
     *
     * The request is made to the source language URL (no language prefix)
     * so we get the original untranslated HTML.
     *
     * @param WP_Post $post The post to fetch
     * @return string|null Rendered HTML or null on failure
     */
    private function fetch_rendered_html( $post ) {
        $url = get_permalink( $post );
        if ( ! $url ) {
            return null;
        }

        // Add a query parameter to identify crawler requests.
        // This allows the output buffer to skip translation for these requests
        // (we want the original HTML, not translated HTML).
        $url = add_query_arg( 'gml_crawl', '1', $url );

        $response = wp_remote_get( $url, [
            'timeout'    => 15,
            'sslverify'  => false, // internal request, SSL cert may be self-signed
            'user-agent' => 'GML-Content-Crawler/1.0',
            'cookies'    => [], // no cookies — ensures non-logged-in view
            'headers'    => [
                'Accept' => 'text/html',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'GML Crawler: HTTP fetch failed for post #' . $post->ID . ': ' . $response->get_error_message() );
            return null;
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status !== 200 ) {
            return null;
        }

        $body = wp_remote_retrieve_body( $response );

        // Sanity check: must be HTML and reasonably sized
        if ( empty( $body ) || strlen( $body ) < 100 ) {
            return null;
        }
        if ( stripos( $body, '<html' ) === false && stripos( $body, '<!DOCTYPE' ) === false ) {
            return null;
        }

        // Cap at 512KB to avoid memory issues during parsing
        if ( strlen( $body ) > 524288 ) {
            $body = substr( $body, 0, 524288 );
        }

        return $body;
    }

    /**
     * Build HTML string from a post's content, title, and meta.
     *
     * IMPORTANT: We do NOT call apply_filters('the_content') or do_shortcode()
     * because in a cron context many plugins (Elementor, WPBakery, Oxygen, etc.)
     * register callbacks that depend on the frontend environment and would cause
     * fatal errors or infinite loops. Instead we use wpautop() + wptexturize()
     * which are safe in cron and sufficient for extracting translatable text.
     */
    private function build_post_html( $post ) {
        $parts = [];

        // Title
        if ( ! empty( $post->post_title ) ) {
            $parts[] = '<h1>' . esc_html( $post->post_title ) . '</h1>';
        }

        // Excerpt
        if ( ! empty( $post->post_excerpt ) ) {
            $parts[] = '<p>' . esc_html( $post->post_excerpt ) . '</p>';
        }

        // Content — use wpautop() only (safe in cron, no shortcode/plugin hooks)
        // Shortcode tags like [gallery] are left as-is and will be ignored by
        // the HTML parser since they're plain text, not HTML elements.
        $content = $post->post_content;
        if ( ! empty( $content ) ) {
            // Strip shortcode tags to avoid noise in translation queue
            $content = strip_shortcodes( $content );
            // Convert double newlines to <p> tags (safe, no plugin hooks)
            $content = wpautop( $content );
            $parts[] = $content;
        }

        // SEO meta (Yoast, RankMath, etc.)
        $seo_title = get_post_meta( $post->ID, '_yoast_wpseo_title', true )
                  ?: get_post_meta( $post->ID, 'rank_math_title', true );
        $seo_desc  = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true )
                  ?: get_post_meta( $post->ID, 'rank_math_description', true );

        if ( $seo_title ) {
            $parts[] = '<meta name="title" content="' . esc_attr( $seo_title ) . '">';
        }
        if ( $seo_desc ) {
            $parts[] = '<meta name="description" content="' . esc_attr( $seo_desc ) . '">';
        }

        // WooCommerce: short description is stored in post_excerpt
        if ( ! empty( $post->post_type ) && $post->post_type === 'product' ) {
            // Product attributes (custom taxonomy-based or custom attributes)
            $attrs = get_post_meta( $post->ID, '_product_attributes', true );
            if ( is_array( $attrs ) ) {
                foreach ( $attrs as $attr ) {
                    if ( ! empty( $attr['name'] ) && ! empty( $attr['value'] ) ) {
                        $parts[] = '<span>' . esc_html( $attr['name'] ) . '</span>';
                        $parts[] = '<span>' . esc_html( $attr['value'] ) . '</span>';
                    }
                }
            }
        }

        // Also crawl taxonomy terms associated with this post
        $taxonomies = get_object_taxonomies( $post->post_type );
        foreach ( $taxonomies as $tax ) {
            $terms = get_the_terms( $post->ID, $tax );
            if ( $terms && ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $parts[] = '<span>' . esc_html( $term->name ) . '</span>';
                    if ( ! empty( $term->description ) ) {
                        $parts[] = '<p>' . esc_html( $term->description ) . '</p>';
                    }
                }
            }
        }

        if ( empty( $parts ) ) {
            return '';
        }

        return '<html><body>' . implode( "\n", $parts ) . '</body></html>';
    }
}
