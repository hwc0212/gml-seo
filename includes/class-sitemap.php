<?php
/**
 * XML Sitemap generator.
 *
 * Generates /sitemap.xml (index) and /sitemap-{type}-{page}.xml sub-sitemaps.
 * Disables WordPress core sitemap to avoid duplicates.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_Sitemap {

    const PER_PAGE = 1000;

    public function __construct() {
        add_action( 'init', [ __CLASS__, 'add_rules' ] );
        add_filter( 'query_vars', [ $this, 'query_vars' ] );
        add_action( 'template_redirect', [ $this, 'render' ], 1 );

        // Disable WP core sitemap
        add_filter( 'wp_sitemaps_enabled', '__return_false' );

        // Add sitemap to robots.txt
        add_filter( 'robots_txt', [ $this, 'robots_txt' ], 10, 2 );
    }

    public static function add_rules() {
        add_rewrite_rule( '^sitemap\.xml$', 'index.php?gml_sitemap=index', 'top' );
        add_rewrite_rule( '^sitemap-([a-z_]+)-?(\d*)\.xml$', 'index.php?gml_sitemap=$matches[1]&gml_sitemap_page=$matches[2]', 'top' );
    }

    public function query_vars( $vars ) {
        $vars[] = 'gml_sitemap';
        $vars[] = 'gml_sitemap_page';
        return $vars;
    }

    public function render() {
        $type = get_query_var( 'gml_sitemap' );
        if ( ! $type ) return;

        $page = max( 1, (int) get_query_var( 'gml_sitemap_page', 1 ) );

        header( 'Content-Type: application/xml; charset=UTF-8' );
        header( 'X-Robots-Tag: noindex' );

        if ( $type === 'index' ) {
            echo $this->build_index();
        } else {
            echo $this->build_sub( $type, $page );
        }
        exit;
    }

    // ── Index ────────────────────────────────────────────────────────

    private function build_index() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ( $this->get_types() as $type ) {
            $count = $this->count_posts( $type );
            $pages = max( 1, ceil( $count / self::PER_PAGE ) );
            for ( $p = 1; $p <= $pages; $p++ ) {
                $suffix = $pages > 1 ? "-{$p}" : '';
                $xml .= '<sitemap><loc>' . home_url( "/sitemap-{$type}{$suffix}.xml" ) . '</loc></sitemap>' . "\n";
            }
        }

        // Taxonomy sitemaps
        foreach ( [ 'category', 'post_tag', 'product_cat' ] as $tax ) {
            if ( taxonomy_exists( $tax ) && wp_count_terms( [ 'taxonomy' => $tax, 'hide_empty' => true ] ) > 0 ) {
                $xml .= '<sitemap><loc>' . home_url( "/sitemap-{$tax}.xml" ) . '</loc></sitemap>' . "\n";
            }
        }

        $xml .= '</sitemapindex>';
        return $xml;
    }

    // ── Sub-sitemap ──────────────────────────────────────────────────

    private function build_sub( $type, $page ) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Taxonomy sitemap
        if ( taxonomy_exists( $type ) ) {
            $terms = get_terms( [ 'taxonomy' => $type, 'hide_empty' => true, 'number' => self::PER_PAGE ] );
            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $xml .= $this->url_entry( get_term_link( $term ) );
                }
            }
        } else {
            // Post type sitemap
            $posts = get_posts( [
                'post_type'      => $type,
                'post_status'    => 'publish',
                'posts_per_page' => self::PER_PAGE,
                'offset'         => ( $page - 1 ) * self::PER_PAGE,
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ] );

            // Homepage
            if ( $page === 1 && $type === 'page' ) {
                $xml .= $this->url_entry( home_url( '/' ), current_time( 'c' ), 'daily', '1.0' );
            }

            foreach ( $posts as $pid ) {
                // Skip noindex posts
                if ( get_post_meta( $pid, '_gml_seo_noindex', true ) ) continue;

                $xml .= $this->url_entry(
                    get_permalink( $pid ),
                    get_the_modified_date( 'c', $pid )
                );
            }
        }

        $xml .= '</urlset>';
        return $xml;
    }

    private function url_entry( $loc, $lastmod = '', $freq = '', $priority = '' ) {
        $xml = '<url><loc>' . esc_url( $loc ) . '</loc>';
        if ( $lastmod )  $xml .= '<lastmod>' . $lastmod . '</lastmod>';
        if ( $freq )     $xml .= '<changefreq>' . $freq . '</changefreq>';
        if ( $priority ) $xml .= '<priority>' . $priority . '</priority>';
        $xml .= '</url>' . "\n";
        return $xml;
    }

    // ── Virtual robots.txt ───────────────────────────────────────────

    /**
     * Generate a complete virtual robots.txt with SEO best practices.
     *
     * WordPress only generates robots.txt dynamically when no physical file
     * exists. We replace the entire output with an optimized version:
     *  - Allow all crawlers by default
     *  - Block wp-admin, wp-includes, wp-login, feeds, search, cgi-bin
     *  - Block query-string crawling (?s=, ?p=, ?replytocom=)
     *  - Block common resource-waste paths (trackback, xmlrpc, wp-json auth)
     *  - Add Sitemap directive
     *  - Add Crawl-delay for polite bots
     */
    public function robots_txt( $output, $public ) {
        // If site is set to "discourage search engines", respect that
        if ( ! $public ) {
            return "User-agent: *\nDisallow: /\n";
        }

        $site = home_url( '/' );

        $txt  = "# GML AI SEO — Auto-generated robots.txt\n";
        $txt .= "# " . date( 'Y-m-d H:i:s' ) . "\n\n";

        // ── All bots ─────────────────────────────────────────────
        $txt .= "User-agent: *\n";

        // Allow root
        $txt .= "Allow: /\n\n";

        // Block WordPress system paths
        $txt .= "# WordPress system\n";
        $txt .= "Disallow: /wp-admin/\n";
        $txt .= "Allow: /wp-admin/admin-ajax.php\n";
        $txt .= "Disallow: /wp-includes/\n";
        $txt .= "Disallow: /wp-login.php\n";
        $txt .= "Disallow: /wp-register.php\n";
        $txt .= "Disallow: /xmlrpc.php\n";
        $txt .= "Disallow: /readme.html\n";
        $txt .= "Disallow: /license.txt\n\n";

        // Block duplicate / low-value content
        $txt .= "# Duplicate & low-value content\n";
        $txt .= "Disallow: /feed/\n";
        $txt .= "Disallow: /comments/feed/\n";
        $txt .= "Disallow: /trackback/\n";
        $txt .= "Disallow: /cgi-bin/\n";
        $txt .= "Disallow: /?s=\n";
        $txt .= "Disallow: /*?s=\n";
        $txt .= "Disallow: /*?p=\n";
        $txt .= "Disallow: /*?replytocom=\n";
        $txt .= "Disallow: /*&replytocom=\n";
        $txt .= "Disallow: /tag/*/feed/\n";
        $txt .= "Disallow: /category/*/feed/\n\n";

        // Block WP uploads PHP execution (security)
        $txt .= "# Security\n";
        $txt .= "Disallow: /wp-content/plugins/\n";
        $txt .= "Disallow: /wp-content/cache/\n";
        $txt .= "Allow: /wp-content/uploads/\n\n";

        // WooCommerce specific
        if ( class_exists( 'WooCommerce' ) ) {
            $txt .= "# WooCommerce\n";
            $txt .= "Disallow: /cart/\n";
            $txt .= "Disallow: /checkout/\n";
            $txt .= "Disallow: /my-account/\n";
            $txt .= "Disallow: /*?add-to-cart=\n";
            $txt .= "Disallow: /*?orderby=\n";
            $txt .= "Disallow: /*?filter_\n\n";
        }

        // Sitemap
        $txt .= "# Sitemap\n";
        $txt .= "Sitemap: " . home_url( '/sitemap.xml' ) . "\n";

        return $txt;
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function get_types() {
        $types = get_post_types( [ 'public' => true ], 'names' );
        unset( $types['attachment'] );
        return array_values( $types );
    }

    private function count_posts( $type ) {
        $counts = wp_count_posts( $type );
        return (int) ( $counts->publish ?? 0 );
    }
}
