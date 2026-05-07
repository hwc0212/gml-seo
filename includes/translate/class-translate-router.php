<?php
/**
 * GML SEO Router — language prefix URL handling
 *
 * Architecture (correct approach):
 *   1. Hook into 'request' filter (fires after WP parses the URL, before query runs).
 *      Strip the language prefix from the parsed query vars so WordPress serves
 *      the correct content as if the URL had no prefix.
 *   2. Use .htaccess / rewrite rules to pass /ru/some-path/ to WordPress as
 *      /some-path/?gml_lang=ru — WordPress never sees the /ru/ prefix.
 *
 * This is the same approach used by WPML and Polylang:
 *   - They hook 'request' to strip the language slug from query vars
 *   - They do NOT manually manipulate $wp_query after the fact
 *
 * @package GML_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Translate_Router {

    /** @var string[] Enabled target language codes */
    private $languages = [];

    public function __construct() {
        $this->languages = $this->get_enabled_languages();

        add_action( 'init',           [ $this, 'add_rewrite_rules' ] );
        add_filter( 'query_vars',     [ $this, 'add_query_vars' ] );
        // 'request' fires after WP parses the URL but before WP_Query runs.
        // This is the correct hook to modify what content WordPress serves.
        add_filter( 'request',        [ $this, 'filter_request' ] );
        // Prevent WordPress canonical redirect from sending /ru/ → /
        add_filter( 'redirect_canonical', [ $this, 'prevent_canonical_redirect' ], 10, 2 );
        // Prevent old-slug redirect from stripping the language prefix
        add_filter( 'old_slug_redirect_url', [ $this, 'prevent_canonical_redirect' ], 10, 1 );
        // Ensure WordPress 'wp_redirect' preserves language prefix
        add_filter( 'wp_redirect', [ $this, 'preserve_lang_in_redirect' ], 10, 2 );
        // After WP_Query runs, fix is_front_page / is_home flags when serving
        // a translated language homepage — page builders (Oxygen, Elementor,
        // Divi, etc.) rely on these conditional tags to render the correct
        // template. Without this fix, /ru/ would be detected as a regular
        // page instead of the site's front page and hero sections disappear.
        add_action( 'parse_query', [ $this, 'fix_front_page_flags' ], 99 );
    }

    // ── Rewrite rules ─────────────────────────────────────────────────────────

    public function add_rewrite_rules() {
        if ( empty( $this->languages ) ) {
            return;
        }

        $pattern = implode( '|', array_map( 'preg_quote', $this->languages ) );

        // /ru/some/path/ → WordPress receives gml_lang=ru, gml_path=some/path/
        add_rewrite_rule(
            "^({$pattern})/(.+?)/?$",
            'index.php?gml_lang=$matches[1]&gml_path=$matches[2]',
            'top'
        );

        // /ru/ (language homepage)
        add_rewrite_rule(
            "^({$pattern})/?$",
            'index.php?gml_lang=$matches[1]',
            'top'
        );
    }

    public function add_query_vars( $vars ) {
        $vars[] = 'gml_lang';
        $vars[] = 'gml_path';
        return $vars;
    }

    // ── Request filter ────────────────────────────────────────────────────────

    /**
     * Fires after WordPress parses the URL into query vars, before WP_Query runs.
     *
     * When WordPress sees /ru/about/, our rewrite rule gives it:
     *   gml_lang=ru, gml_path=about
     *
     * We need to translate that back into the real WordPress query vars
     * (pagename=about, or page_id=X, etc.) so WordPress serves the right content.
     * The output buffer will then translate the HTML.
     *
     * @param array $query_vars Parsed query vars
     * @return array Modified query vars
     */
    public function filter_request( $query_vars ) {
        if ( empty( $query_vars['gml_lang'] ) ) {
            return $query_vars;
        }

        $lang = $query_vars['gml_lang'];
        if ( ! in_array( $lang, $this->languages, true ) ) {
            return $query_vars;
        }

        $path = $query_vars['gml_path'] ?? '';

        // Remove our custom vars — WordPress must not see them in the final query
        unset( $query_vars['gml_lang'], $query_vars['gml_path'] );

        // Empty path = language homepage
        if ( $path === '' || $path === '/' ) {
            if ( empty( $query_vars ) ) {
                // Pure homepage — no search or other params
                if ( get_option( 'show_on_front' ) === 'page' ) {
                    $front_id = (int) get_option( 'page_on_front' );
                    if ( $front_id ) {
                        return [ 'page_id' => $front_id ];
                    }
                }
                return [];
            }
            // Has extra params (e.g. ?s=keyword) — return them as-is
            return $query_vars;
        }

        $bare_path = trim( $path, '/' );

        // ── Strategy 1: url_to_postid() — most reliable for single posts/pages ──
        // This uses WordPress's own internal rewrite engine and handles all
        // post types (posts, pages, products, custom post types) correctly.
        // It's the same function WordPress uses internally.
        $post_id = url_to_postid( home_url( '/' . $bare_path . '/' ) );
        if ( $post_id ) {
            $post_type = get_post_type( $post_id );
            $vars = $post_type === 'page'
                ? [ 'page_id' => $post_id ]
                : [ 'p' => $post_id, 'post_type' => $post_type ];
            return array_merge( $query_vars, $vars );
        }

        // ── Strategy 2: Rewrite rules — handles archives, feeds, pagination, etc ─
        // url_to_postid() only works for single posts/pages. For everything else
        // (shop page with pagination, category archives, feeds, author pages,
        // date archives, search, WooCommerce endpoints), we need to match
        // against WordPress's rewrite rules directly.
        global $wp_rewrite;
        $rewrite_rules = $wp_rewrite->wp_rewrite_rules();

        if ( ! empty( $rewrite_rules ) ) {
            foreach ( $rewrite_rules as $pattern => $rewrite ) {
                if ( ! preg_match( "#^{$pattern}#", $bare_path, $matches )
                  && ! preg_match( "#^{$pattern}#", urldecode( $bare_path ), $matches ) ) {
                    continue;
                }

                // Build query vars from the rewrite string
                $rewrite_str = str_replace( 'index.php?', '', $rewrite );
                $resolved = [];
                parse_str( $rewrite_str, $resolved );

                // Replace $matches[N] placeholders with actual captured values
                foreach ( $resolved as $key => $val ) {
                    if ( preg_match( '/^\$matches\[(\d+)\]$/', $val, $vm ) ) {
                        $idx = (int) $vm[1];
                        $resolved[ $key ] = $matches[ $idx ] ?? '';
                    }
                    if ( $resolved[ $key ] === '' ) {
                        unset( $resolved[ $key ] );
                    }
                }

                // ── Validate pagename rules (same as WP core parse_request) ──
                // WordPress's page rule (.?.+?) is very greedy and matches
                // almost any slug. WP core validates by checking if the page
                // actually exists. We must do the same, otherwise blog posts
                // and products get misrouted to pagename=X, WordPress can't
                // find a page with that slug, and falls back to the front page.
                if ( ! empty( $resolved['pagename'] ) ) {
                    $test_page = get_page_by_path( $resolved['pagename'] );
                    if ( ! $test_page ) {
                        continue; // not a real page — try next rule
                    }
                }

                // Skip our own GML rewrite rules (gml_lang/gml_path)
                if ( isset( $resolved['gml_lang'] ) || isset( $resolved['gml_path'] ) ) {
                    continue;
                }

                return array_merge( $query_vars, $resolved );
            }
        }

        // ── Strategy 3: last resort — try as pagename ────────────────────────
        return array_merge( $query_vars, [ 'pagename' => $bare_path ] );
    }



    /**
     * Fix WP_Query's is_front_page / is_home flags for language-prefixed homepages.
     *
     * When /ru/ is served, we route it to page_id=X (the configured front page).
     * WordPress's WP_Query::parse_query() sets is_page=true but not is_front_page
     * because it validates against REQUEST_URI which contains /ru/ — not the
     * expected / for the front page.
     *
     * Page builders (Oxygen, Elementor, Divi, Astra, GeneratePress, etc.) all
     * rely on is_front_page() to render frontpage-specific templates. Without
     * this fix, translated homepages render a "regular page" layout missing
     * the hero section and other frontpage-only widgets.
     *
     * Symptom: /fr/ shows footer + contact block but no hero; / shows full page.
     */
    public function fix_front_page_flags( $query ) {
        if ( ! $query->is_main_query() ) {
            return;
        }
        if ( is_admin() ) {
            return;
        }

        // Only act when current URL has a language prefix
        $path = strtok( $_SERVER['REQUEST_URI'] ?? '/', '?' );
        if ( empty( $this->languages ) ) {
            return;
        }
        $pat = implode( '|', array_map( 'preg_quote', $this->languages ) );
        if ( ! preg_match( '#^/(' . $pat . ')/?$#', $path ) ) {
            // Not a language-ROOT URL (e.g. /ru/some-page/ — those are pages, not front)
            return;
        }

        // Confirm this is serving the configured front page
        if ( get_option( 'show_on_front' ) !== 'page' ) {
            // "Latest posts" front — set is_home instead
            $query->is_home = true;
            $query->is_front_page = true;
            $query->is_page = false;
            $query->is_singular = false;
            return;
        }

        $front_id = (int) get_option( 'page_on_front' );
        if ( ! $front_id ) {
            return;
        }

        $queried = $query->get( 'page_id' );
        if ( (int) $queried !== $front_id ) {
            return;
        }

        // Flag as front page for all conditional template checks
        $query->is_front_page = true;
        $query->is_home       = false; // "static front + separate posts page" mode
    }

    /**
     * Prevent WordPress from redirecting /ru/about/ → /about/ (canonical redirect).
     * When the request URI has a language prefix, we're intentionally serving
     * content at a different URL — no redirect should happen.
     */
    public function prevent_canonical_redirect( $redirect_url, $requested_url = '' ) {
        $path = strtok( $_SERVER['REQUEST_URI'] ?? '/', '?' );
        if ( ! empty( $this->languages ) ) {
            $pat = implode( '|', array_map( 'preg_quote', $this->languages ) );
            if ( preg_match( '#^/(' . $pat . ')(/|$)#', $path ) ) {
                return false; // suppress redirect
            }
        }
        return $redirect_url;
    }

    /**
     * Preserve language prefix in WordPress internal redirects.
     *
     * When WordPress issues a redirect (e.g. trailing slash normalization,
     * pagination redirect, WooCommerce cart/checkout redirect), the redirect
     * URL is generated without the language prefix. This filter checks if
     * the current request has a language prefix and, if the redirect target
     * is an internal URL without one, adds the prefix back.
     *
     * This prevents the "intermittent redirect to source language" issue
     * that occurs when WordPress internally redirects during navigation.
     */
    public function preserve_lang_in_redirect( $location, $status = 302 ) {
        if ( empty( $this->languages ) ) {
            return $location;
        }

        // Detect current language from request URI
        $request_path = strtok( $_SERVER['REQUEST_URI'] ?? '/', '?' );
        $pat = implode( '|', array_map( 'preg_quote', $this->languages ) );
        if ( ! preg_match( '#^/(' . $pat . ')(/|$)#', $request_path, $m ) ) {
            return $location; // not on a translated page
        }

        $current_lang = $m[1];
        $home_origin  = rtrim( home_url(), '/' );

        // Parse the redirect location
        $redirect_path = null;
        if ( preg_match( '#^https?://#i', $location ) ) {
            // Absolute URL — must be same origin
            if ( stripos( $location, $home_origin ) !== 0 ) {
                return $location; // external redirect
            }
            $redirect_path = substr( $location, strlen( $home_origin ) ) ?: '/';
        } elseif ( isset( $location[0] ) && $location[0] === '/' ) {
            $redirect_path = $location;
        }

        if ( $redirect_path === null ) {
            return $location;
        }

        // Skip admin/login/API/static resource paths
        if ( preg_match( '#^/(wp-admin|wp-login\.php|wp-json|wp-cron|wp-content|wp-includes)(/|$)#i', $redirect_path ) ) {
            return $location;
        }

        // Skip static file redirects
        if ( preg_match( '/\.(?:css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot|otf|mp4|webm|mp3|pdf|zip|xml|txt|map)(\?|$)/i', $redirect_path ) ) {
            return $location;
        }

        // Skip if redirect already has a language prefix
        if ( preg_match( '#^/(' . $pat . ')(/|$)#', $redirect_path ) ) {
            return $location;
        }

        // Add language prefix
        $new_path = '/' . $current_lang . '/' . ltrim( $redirect_path, '/' );

        if ( preg_match( '#^https?://#i', $location ) ) {
            return $home_origin . $new_path;
        }
        return $new_path;
    }

    // ── Language URL helpers ──────────────────────────────────────────────────

    /**
     * Build a map of language_code => absolute URL for the current page.
     */
    public static function get_language_urls() {
        $source_lang = get_option( 'gml_source_lang', 'en' );

        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path        = strtok( $request_uri, '?' );
        $path        = rtrim( $path, '/' ) . '/';

        // Strip any existing language prefix
        $all_langs = self::get_all_language_codes();
        if ( ! empty( $all_langs ) ) {
            $pat  = implode( '|', array_map( 'preg_quote', $all_langs ) );
            $path = preg_replace( '#^/(' . $pat . ')(/|$)#', '/', $path );
        }

        $path = '/' . ltrim( $path, '/' );

        $urls = [ $source_lang => home_url( $path ) ];

        foreach ( get_option( 'gml_languages', [] ) as $lang ) {
            if ( ( $lang['enabled'] ?? true ) && $lang['code'] !== $source_lang ) {
                $urls[ $lang['code'] ] = home_url( '/' . $lang['code'] . $path );
            }
        }

        return $urls;
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private function get_enabled_languages() {
        $codes = [];
        foreach ( get_option( 'gml_languages', [] ) as $lang ) {
            if ( $lang['enabled'] ?? true ) {
                $codes[] = $lang['code'];
            }
        }
        return $codes;
    }

    private static function get_all_language_codes() {
        $source = get_option( 'gml_source_lang', 'en' );
        $codes  = [ $source ];
        foreach ( get_option( 'gml_languages', [] ) as $lang ) {
            $codes[] = $lang['code'];
        }
        return array_unique( $codes );
    }
}
