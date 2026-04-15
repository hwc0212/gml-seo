<?php
/**
 * Frontend <head> meta tags output.
 *
 * Outputs: title, description, canonical, robots, OG, Twitter Card.
 * Reads from _gml_seo_* post meta (AI-generated).
 * Falls back to WordPress defaults if no AI data.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_Meta_Tags {

    public function __construct() {
        // Title tag
        add_filter( 'pre_get_document_title', [ $this, 'document_title' ], 20 );
        add_theme_support( 'title-tag' );

        // Meta tags in <head>
        add_action( 'wp_head', [ $this, 'render_head' ], 1 );

        // Remove WordPress default canonical (we handle it)
        remove_action( 'wp_head', 'rel_canonical' );

        // Remove WP default meta description if any theme adds it
        remove_action( 'wp_head', 'noindex', 1 );
    }

    // ── Title ────────────────────────────────────────────────────────

    public function document_title( $title ) {
        if ( is_singular() ) {
            $id  = get_queried_object_id();
            $seo = get_post_meta( $id, '_gml_seo_title', true );
            if ( $seo ) return $seo;
        }

        if ( is_front_page() ) {
            $site = GML_SEO::opt( 'site_name', get_bloginfo( 'name' ) );
            $desc = get_bloginfo( 'description' );
            return $desc ? "{$site} {$this->sep()} {$desc}" : $site;
        }

        if ( is_category() || is_tag() || is_tax() ) {
            $term = get_queried_object();
            return $term ? $term->name . ' ' . $this->sep() . ' ' . $this->site() : $title;
        }

        if ( is_search() ) {
            return sprintf( __( 'Search: %s', 'gml-seo' ), get_search_query() ) . ' ' . $this->sep() . ' ' . $this->site();
        }

        if ( is_404() ) {
            return __( 'Page Not Found', 'gml-seo' ) . ' ' . $this->sep() . ' ' . $this->site();
        }

        return $title;
    }

    // ── Head meta ────────────────────────────────────────────────────

    public function render_head() {
        $id = is_singular() ? get_queried_object_id() : 0;

        // Detect if we're on a GML Translate language-prefixed page
        $is_translated_page = $this->is_gml_translate_page();

        // Meta description
        $desc = $id ? get_post_meta( $id, '_gml_seo_desc', true ) : '';
        if ( ! $desc && is_front_page() ) {
            $desc = get_bloginfo( 'description' );
        }
        if ( $desc ) {
            echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
        }

        // Canonical — skip if GML Translate is handling it (translated pages)
        if ( ! $is_translated_page ) {
            $canonical = $this->get_canonical();
            if ( $canonical ) {
                echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
            }
        }

        // Robots
        $this->render_robots( $id );

        // Open Graph
        $this->render_og( $id, $desc );

        // Twitter Card
        $this->render_twitter( $id, $desc );

        // Keywords (still used by some search engines in Asia)
        if ( $id ) {
            $kw = get_post_meta( $id, '_gml_seo_keywords', true );
            if ( $kw ) {
                echo '<meta name="keywords" content="' . esc_attr( $kw ) . '">' . "\n";
            }
        }
    }

    // ── Canonical ────────────────────────────────────────────────────

    private function get_canonical() {
        if ( is_singular() ) {
            $custom = get_post_meta( get_queried_object_id(), '_gml_seo_canonical', true );
            return $custom ?: get_permalink();
        }
        if ( is_front_page() ) return home_url( '/' );
        if ( is_category() || is_tag() || is_tax() ) {
            $term = get_queried_object();
            return $term ? get_term_link( $term ) : '';
        }
        return '';
    }

    // ── Robots ───────────────────────────────────────────────────────

    private function render_robots( $id ) {
        $robots = [];

        // Respect per-post noindex
        if ( $id && get_post_meta( $id, '_gml_seo_noindex', true ) ) {
            $robots[] = 'noindex';
        }

        // Search results, archives with thin content
        if ( is_search() ) $robots[] = 'noindex';

        // Paginated archives
        if ( is_paged() && ( is_category() || is_tag() || is_tax() || is_author() || is_date() ) ) {
            $robots[] = 'noindex';
        }

        if ( ! empty( $robots ) ) {
            echo '<meta name="robots" content="' . esc_attr( implode( ', ', array_unique( $robots ) ) ) . '">' . "\n";
        }
    }

    // ── Open Graph ───────────────────────────────────────────────────

    private function render_og( $id, $desc ) {
        $site = GML_SEO::opt( 'site_name', get_bloginfo( 'name' ) );

        // Skip og:locale if GML Translate is handling it (it outputs the correct
        // locale for translated pages + og:locale:alternate for other languages)
        if ( ! $this->is_gml_translate_page() ) {
            echo '<meta property="og:locale" content="' . esc_attr( get_locale() ) . '">' . "\n";
        }
        echo '<meta property="og:site_name" content="' . esc_attr( $site ) . '">' . "\n";

        if ( is_singular() && $id ) {
            $og_title = get_post_meta( $id, '_gml_seo_og_title', true ) ?: get_the_title( $id );
            $og_desc  = get_post_meta( $id, '_gml_seo_og_desc', true ) ?: $desc;
            $og_type  = ( $id == get_option( 'page_on_front' ) ) ? 'website' : 'article';

            echo '<meta property="og:type" content="' . $og_type . '">' . "\n";
            echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '">' . "\n";
            if ( $og_desc ) echo '<meta property="og:description" content="' . esc_attr( $og_desc ) . '">' . "\n";
            echo '<meta property="og:url" content="' . esc_url( get_permalink( $id ) ) . '">' . "\n";

            // Image: featured image
            $thumb = get_post_thumbnail_id( $id );
            if ( $thumb ) {
                $img = wp_get_attachment_image_src( $thumb, 'large' );
                if ( $img ) {
                    echo '<meta property="og:image" content="' . esc_url( $img[0] ) . '">' . "\n";
                    echo '<meta property="og:image:width" content="' . (int) $img[1] . '">' . "\n";
                    echo '<meta property="og:image:height" content="' . (int) $img[2] . '">' . "\n";
                }
            }

            // Article meta
            if ( $og_type === 'article' ) {
                echo '<meta property="article:published_time" content="' . get_the_date( 'c', $id ) . '">' . "\n";
                echo '<meta property="article:modified_time" content="' . get_the_modified_date( 'c', $id ) . '">' . "\n";
            }
        } else {
            echo '<meta property="og:type" content="website">' . "\n";
            echo '<meta property="og:title" content="' . esc_attr( wp_get_document_title() ) . '">' . "\n";
            if ( $desc ) echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
            echo '<meta property="og:url" content="' . esc_url( home_url( $_SERVER['REQUEST_URI'] ?? '/' ) ) . '">' . "\n";
        }
    }

    // ── Twitter Card ─────────────────────────────────────────────────

    private function render_twitter( $id, $desc ) {
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";

        if ( is_singular() && $id ) {
            $title = get_post_meta( $id, '_gml_seo_og_title', true ) ?: get_the_title( $id );
            $tdesc = get_post_meta( $id, '_gml_seo_og_desc', true ) ?: $desc;

            echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
            if ( $tdesc ) echo '<meta name="twitter:description" content="' . esc_attr( $tdesc ) . '">' . "\n";

            $thumb = get_post_thumbnail_id( $id );
            if ( $thumb ) {
                $img = wp_get_attachment_image_url( $thumb, 'large' );
                if ( $img ) echo '<meta name="twitter:image" content="' . esc_url( $img ) . '">' . "\n";
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function sep() { return GML_SEO::opt( 'separator', '-' ); }
    private function site() { return GML_SEO::opt( 'site_name', get_bloginfo( 'name' ) ); }

    /**
     * Check if current page is a GML Translate language-prefixed page.
     * GML Translate handles canonical, og:locale, and hreflang on these pages.
     */
    private function is_gml_translate_page() {
        if ( ! defined( 'GML_VERSION' ) ) return false;

        $uri  = $_SERVER['REQUEST_URI'] ?? '';
        $path = strtok( $uri, '?' );
        if ( preg_match( '#^/([a-z]{2})(/|$)#', $path, $m ) ) {
            $lang   = $m[1];
            $source = get_option( 'gml_source_lang', 'en' );
            if ( $lang !== $source ) {
                $langs = get_option( 'gml_languages', [] );
                foreach ( $langs as $l ) {
                    if ( ( $l['enabled'] ?? true ) && $l['code'] === $lang ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
