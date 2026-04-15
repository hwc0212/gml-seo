<?php
/**
 * GML SEO — Performance Optimizer
 *
 * Auto-applies safe, Google-recommended performance optimizations.
 * No configuration needed. Follows Google's Core Web Vitals guidance:
 *
 * SAFE (always on, won't break anything):
 *  - Remove WP bloat (emoji, dashicons, embed, RSD, shortlink, WP version, wlwmanifest)
 *  - Lazy load images + iframes (native loading="lazy")
 *  - Add missing width/height to images (prevents CLS)
 *  - Defer non-critical JS
 *  - Preconnect to detected external domains (Google Fonts, CDN, etc.)
 *  - DNS prefetch for external resources
 *  - Add fetchpriority="high" to first content image (LCP hint)
 *
 * CONSERVATIVE (avoids over-optimization per Google's guidance):
 *  - Does NOT remove unused CSS (can break layouts)
 *  - Does NOT delay all JS (can break interactivity)
 *  - Does NOT disable features blindly
 *  - Does NOT touch logged-in admin experience
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_Performance {

    public function __construct() {
        // Skip for logged-in admins — never break the admin experience
        if ( is_admin() || ( is_user_logged_in() && current_user_can( 'manage_options' ) ) ) {
            return;
        }

        // ── WP Bloat Removal ─────────────────────────────────────
        $this->remove_wp_bloat();

        // ── JS Optimization ──────────────────────────────────────
        add_filter( 'script_loader_tag', [ $this, 'defer_js' ], 10, 3 );

        // ── Image Optimization (output buffer) ───────────────────
        // Priority 0: run BEFORE GML Translate (priority 1) so that
        // translated HTML still gets image lazy-load / width-height fixes.
        // Buffer nesting: GML Translate ob_start(1) → GML SEO ob_start(0)
        // Flush order: GML SEO processes first, then GML Translate translates.
        add_action( 'template_redirect', [ $this, 'start_buffer' ], 0 );

        // ── Resource Hints ───────────────────────────────────────
        add_action( 'wp_head', [ $this, 'resource_hints' ], 1 );

        // ── Preload LCP image ────────────────────────────────────
        add_action( 'wp_head', [ $this, 'preload_featured_image' ], 2 );
    }

    // ═══════════════════════════════════════════════════════════════
    // 1. REMOVE WORDPRESS BLOAT
    // Google says: "Google needs to be able to access the same resources
    // as the user's browser." These removals only strip things that
    // add zero value to users or crawlers.
    // ═══════════════════════════════════════════════════════════════

    private function remove_wp_bloat() {
        // Emoji scripts + styles (~10KB saved)
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
        remove_action( 'admin_print_styles', 'print_emoji_styles' );
        add_filter( 'emoji_svg_url', '__return_false' );

        // RSD link (for blog clients nobody uses)
        remove_action( 'wp_head', 'rsd_link' );

        // Windows Live Writer manifest
        remove_action( 'wp_head', 'wlwmanifest_link' );

        // WP version meta tag (security + no SEO value)
        remove_action( 'wp_head', 'wp_generator' );

        // Shortlink
        remove_action( 'wp_head', 'wp_shortlink_wp_head' );

        // REST API link in head (still accessible, just not advertised)
        remove_action( 'wp_head', 'rest_output_link_wp_head' );

        // oEmbed discovery links
        remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );

        // Remove dashicons CSS for non-logged-in users (~46KB saved)
        add_action( 'wp_enqueue_scripts', function() {
            if ( ! is_user_logged_in() ) {
                wp_deregister_style( 'dashicons' );
            }
        } );

        // Remove WP embed script (~6KB saved)
        add_action( 'wp_footer', function() {
            wp_deregister_script( 'wp-embed' );
        } );

        // Remove global styles (Gutenberg block library inline CSS for unused blocks)
        remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
        remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );

        // Disable self-pingbacks (waste of resources)
        add_action( 'pre_ping', function( &$links ) {
            $home = home_url();
            foreach ( $links as $i => $link ) {
                if ( strpos( $link, $home ) === 0 ) {
                    unset( $links[ $i ] );
                }
            }
        } );

        // Disable XML-RPC (security + performance, no SEO value)
        add_filter( 'xmlrpc_enabled', '__return_false' );
        add_filter( 'wp_headers', function( $headers ) {
            unset( $headers['X-Pingback'] );
            return $headers;
        } );
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. DEFER NON-CRITICAL JAVASCRIPT
    // Google: "Eliminate render-blocking resources" is a key Lighthouse
    // audit. We add defer to scripts that aren't critical for first paint.
    //
    // SAFE: We never defer jQuery core (breaks too many things) or
    // scripts already marked async/defer.
    // ═══════════════════════════════════════════════════════════════

    public function defer_js( $tag, $handle, $src ) {
        // Don't touch admin
        if ( is_admin() ) return $tag;

        // Skip if already has async or defer
        if ( strpos( $tag, ' defer' ) !== false || strpos( $tag, ' async' ) !== false ) {
            return $tag;
        }

        // Never defer these — they break things
        $skip = [
            'jquery-core', 'jquery', 'jquery-migrate',
            'wp-polyfill', 'wp-hooks',
            'wc-cart-fragments',  // WooCommerce cart
        ];
        if ( in_array( $handle, $skip, true ) ) {
            return $tag;
        }

        // Don't defer inline scripts (no src)
        if ( empty( $src ) ) return $tag;

        // Add defer
        return str_replace( ' src=', ' defer src=', $tag );
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. IMAGE OPTIMIZATION VIA OUTPUT BUFFER
    // Google: "Add descriptive alt text to the image" and
    // "images can be how people find your website for the first time"
    //
    // We do:
    //  a) Add native loading="lazy" to images below the fold
    //  b) Add missing width/height attributes (prevents CLS)
    //  c) Add fetchpriority="high" to the first content image (LCP)
    //  d) Add loading="lazy" to iframes (YouTube, maps, etc.)
    // ═══════════════════════════════════════════════════════════════

    public function start_buffer() {
        if ( is_feed() || is_robots() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            return;
        }
        ob_start( [ $this, 'process_buffer' ] );
    }

    public function process_buffer( $html ) {
        if ( strlen( $html ) < 255 ) return $html;
        if ( stripos( $html, '<html' ) === false ) return $html;

        $img_count = 0;

        // ── Process <img> tags ───────────────────────────────────
        $html = preg_replace_callback( '/<img\b([^>]*)>/is', function( $m ) use ( &$img_count ) {
            $atts = $m[1];
            $img_count++;

            // First 1-2 images: don't lazy load (above the fold / LCP candidate)
            // Add fetchpriority="high" to the very first image
            if ( $img_count <= 2 ) {
                // Remove any loading="lazy" that WP might have added to first images
                $atts = preg_replace( '/\s*loading=["\'][^"\']*["\']/', '', $atts );

                if ( $img_count === 1 && strpos( $atts, 'fetchpriority' ) === false ) {
                    $atts .= ' fetchpriority="high"';
                }
            } else {
                // Add loading="lazy" if not already present
                if ( stripos( $atts, 'loading=' ) === false ) {
                    $atts .= ' loading="lazy"';
                }
                // Add decoding="async" for non-critical images
                if ( stripos( $atts, 'decoding=' ) === false ) {
                    $atts .= ' decoding="async"';
                }
            }

            // ── Add missing width/height (prevents CLS) ─────────
            if ( stripos( $atts, 'width=' ) === false && stripos( $atts, 'height=' ) === false ) {
                $dims = $this->get_image_dimensions( $atts );
                if ( $dims ) {
                    $atts .= ' width="' . $dims[0] . '" height="' . $dims[1] . '"';
                }
            }

            return '<img' . $atts . '>';
        }, $html );

        // ── Process <iframe> tags — add loading="lazy" ───────────
        $html = preg_replace_callback( '/<iframe\b([^>]*)>/is', function( $m ) {
            $atts = $m[1];
            if ( stripos( $atts, 'loading=' ) === false ) {
                $atts .= ' loading="lazy"';
            }
            return '<iframe' . $atts . '>';
        }, $html );

        return $html;
    }

    /**
     * Try to get image dimensions from the src URL.
     * Only works for images in the WP uploads directory.
     */
    private function get_image_dimensions( $atts ) {
        if ( ! preg_match( '/src=["\']([^"\']+)["\']/', $atts, $m ) ) {
            return null;
        }
        $src = $m[1];

        // Only process local images
        $upload_dir = wp_get_upload_dir();
        if ( strpos( $src, $upload_dir['baseurl'] ) === false ) {
            return null;
        }

        // Convert URL to file path
        $file = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $src );
        if ( ! file_exists( $file ) ) {
            return null;
        }

        // Try to get from filename pattern (e.g. image-300x200.jpg)
        if ( preg_match( '/-(\d+)x(\d+)\.[a-z]+$/i', $file, $dm ) ) {
            return [ (int) $dm[1], (int) $dm[2] ];
        }

        // Fall back to getimagesize (slightly slower but accurate)
        $size = @getimagesize( $file );
        if ( $size && $size[0] > 0 && $size[1] > 0 ) {
            return [ $size[0], $size[1] ];
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    // 4. RESOURCE HINTS
    // Google: Preconnect reduces DNS+TLS latency for external domains.
    // We auto-detect common external domains used by the site.
    // ═══════════════════════════════════════════════════════════════

    public function resource_hints() {
        $hints = [];

        // Always preconnect to Google Fonts if theme uses them
        // (detected by checking if any Google Fonts are enqueued)
        global $wp_styles;
        if ( $wp_styles ) {
            foreach ( $wp_styles->registered as $style ) {
                if ( ! empty( $style->src ) && strpos( $style->src, 'fonts.googleapis.com' ) !== false ) {
                    $hints['https://fonts.googleapis.com'] = 'preconnect';
                    $hints['https://fonts.gstatic.com']    = 'preconnect';
                    break;
                }
            }
        }

        // Preconnect to GA/GTM if configured
        if ( GML_SEO::opt( 'ga_id' ) || GML_SEO::opt( 'gtm_id' ) ) {
            $hints['https://www.googletagmanager.com'] = 'preconnect';
            $hints['https://www.google-analytics.com'] = 'dns-prefetch';
        }

        // Preconnect to Gravatar if comments are open
        if ( is_singular() && comments_open() ) {
            $hints['https://secure.gravatar.com'] = 'dns-prefetch';
        }

        // Output hints
        foreach ( $hints as $url => $rel ) {
            $crossorigin = ( $rel === 'preconnect' ) ? ' crossorigin' : '';
            echo '<link rel="' . $rel . '" href="' . esc_url( $url ) . '"' . $crossorigin . '>' . "\n";
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 5. PRELOAD LCP IMAGE
    // Google: "Largest Contentful Paint (LCP) is one of the three
    // Core Web Vitals metrics." Preloading the featured image on
    // singular pages helps the browser fetch it earlier.
    // ═══════════════════════════════════════════════════════════════

    public function preload_featured_image() {
        if ( ! is_singular() ) return;

        $id    = get_queried_object_id();
        $thumb = get_post_thumbnail_id( $id );
        if ( ! $thumb ) return;

        // Get the large size (most likely to be the LCP image)
        $src = wp_get_attachment_image_src( $thumb, 'large' );
        if ( ! $src ) return;

        // Also get srcset for responsive preload
        $srcset = wp_get_attachment_image_srcset( $thumb, 'large' );
        $sizes  = wp_get_attachment_image_sizes( $thumb, 'large' );

        echo '<link rel="preload" as="image" href="' . esc_url( $src[0] ) . '"';
        if ( $srcset ) {
            echo ' imagesrcset="' . esc_attr( $srcset ) . '"';
        }
        if ( $sizes ) {
            echo ' imagesizes="' . esc_attr( $sizes ) . '"';
        }
        echo ' fetchpriority="high">' . "\n";
    }
}
