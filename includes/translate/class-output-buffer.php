<?php
/**
 * GML Output Buffer — frontend HTML interception & translation
 *
 * Strategy:
 *  - Only intercepts frontend HTML responses (not admin, AJAX, REST, feeds).
 *  - Detects target language from URL prefix (/ru/, /en/, …) then cookie.
 *  - Passes the full HTML through GML_HTML_Parser → GML_Translator.
 *  - Does NOT change WordPress locale (backend language packs are irrelevant).
 *
 * @package GML_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Output_Buffer {

    private $enabled     = false;
    private $source_lang = '';
    private $target_lang = '';

    public function __construct() {
        add_action( 'template_redirect', [ $this, 'start_buffer' ], 1 );
        add_action( 'shutdown',          [ $this, 'end_buffer'   ], 999 );
    }

    // ── Buffer lifecycle ──────────────────────────────────────────────────────

    public function start_buffer() {
        if ( $this->should_skip() ) {
            return;
        }

        $this->source_lang = get_option( 'gml_source_lang', 'en' );
        $this->target_lang = $this->detect_target_language();

        // Nothing to do if we're already on the source language
        if ( $this->target_lang === $this->source_lang ) {
            return;
        }

        $this->enabled = true;
        ob_start( [ $this, 'process_buffer' ] );
    }

    public function end_buffer() {
        if ( $this->enabled && ob_get_level() > 0 ) {
            ob_end_flush();
        }
    }

    // ── Buffer callback ───────────────────────────────────────────────────────

    
        public function process_buffer( $html ) {
            if ( ! $this->is_html( $html ) ) {
                return $html;
            }

            // ── Page-level HTML cache ────────────────────────────────────────────
            // For non-logged-in visitors, cache the fully translated HTML output
            // keyed by URL + language. This skips the entire parse → translate →
            // rebuild pipeline for repeat visits to the same page.
            //
            // Logged-in users always get fresh output (admin bar, user-specific
            // content like "Hello, [name]", WooCommerce cart count, etc.).
            //
            // Cache is stored as a WordPress transient (auto-expires after 1 hour).
            // Invalidated when new translations are saved (queue processor).
            $use_page_cache = ! is_user_logged_in();
            $page_cache_key = '';

            if ( $use_page_cache ) {
                $request_uri    = $_SERVER['REQUEST_URI'] ?? '/';
                $page_cache_key = 'gml_page_' . md5( $this->target_lang . '|' . $request_uri );
                $cached_html    = get_transient( $page_cache_key );
                if ( $cached_html !== false ) {
                    return $cached_html;
                }
            }

            // ── Safety: skip translation if resources are tight ──────────────────
            // Prevents fatal errors on very large pages (e.g. WooCommerce shop with
            // 200+ products) that could cause a blank page for the visitor.
            $html_len = strlen( $html );

            // 1. Skip pages larger than 1 MB — DOMDocument + mb_encode_numericentity
            //    on a 1 MB+ string can spike memory usage by 4-6×.
            if ( $html_len > 1048576 ) {
                return $html;
            }

            // 2. Skip if less than 16 MB of memory headroom remains.
            $memory_limit = $this->get_memory_limit_bytes();
            if ( $memory_limit > 0 ) {
                $headroom = $memory_limit - memory_get_usage( true );
                // Need roughly 6× the HTML size for DOMDocument + tokenisation + rebuild
                $needed = $html_len * 6;
                if ( $headroom < max( $needed, 16 * 1024 * 1024 ) ) {
                    return $html;
                }
            }

            try {
                // Protect elements that must never be translated.
                // We inject translate="no" before extract_no_translate_blocks() runs,
                // so the entire block gets lifted out and is never seen by str_replace.
                //
                // #wpadminbar — WordPress admin toolbar shown to logged-in users on
                //               the frontend. Contains WP UI strings, not page content.
                $html = preg_replace(
                    '/(<div\s[^>]*id=["\']wpadminbar["\'])/i',
                    '$1 translate="no"',
                    $html
                );

                // CSS-hidden elements — elements with inline display:none or
                // visibility:hidden are not visible to the user and should not
                // be translated. More importantly, if str_replace changes their
                // content, it can break JS that relies on the original text or
                // cause the element to become visible (e.g. Oxygen Builder
                // lightbox close buttons, hidden overlays, off-screen menus).
                $html = preg_replace(
                    '/(<[a-z][a-z0-9]*\b[^>]*\bstyle\s*=\s*["\'][^"\']*(?:display\s*:\s*none|visibility\s*:\s*hidden)[^"\']*["\'][^>]*)(>)/i',
                    '$1 translate="no"$2',
                    $html
                );

                // Extract translate="no" blocks before parsing so str_replace
                // in rebuild() never touches them (DOM exclusion alone is not enough
                // because rebuild() operates on the raw HTML string).
                [ $html_clean, $placeholders ] = $this->extract_no_translate_blocks( $html );

                $parser     = new GML_HTML_Parser();
                $parsed     = $parser->parse( $html_clean );
                $translated = ( new GML_Translator() )->translate( $parsed, $this->target_lang );
                $result     = $parser->rebuild( $translated );

                // ── Server-side link rewriting ───────────────────────────────
                // Rewrite internal href/action URLs to include the language
                // prefix BEFORE restoring translate="no" blocks. This way the
                // language switcher (which has translate="no") is still hidden
                // inside placeholder tokens and its links won't be rewritten.
                $result = $this->rewrite_internal_links( $result );

                // Restore extracted blocks (language switcher, admin bar, etc.)
                if ( ! empty( $placeholders ) ) {
                    $result = str_replace(
                        array_keys( $placeholders ),
                        array_values( $placeholders ),
                        $result
                    );
                }

                // ── Store in page-level cache ────────────────────────────────
                // Cache the translated HTML for non-logged-in visitors.
                // Even partially translated pages are cached — it's better to
                // serve a fast partially-translated page than re-process every
                // time. The cache is invalidated when new translations arrive
                // (queue processor deletes all gml_page_* transients).
                if ( $use_page_cache && $page_cache_key ) {
                    set_transient( $page_cache_key, $result, HOUR_IN_SECONDS );
                }

                return $result;
            } catch ( \Throwable $e ) {
                // Catch both Exception and Error (e.g. TypeError, ValueError in PHP 8)
                error_log( 'GML Output Buffer error: ' . $e->getMessage() );
                return $html;
            }
        }

        /**
         * Parse php.ini memory_limit into bytes.
         */
        private function get_memory_limit_bytes() {
            $limit = ini_get( 'memory_limit' );
            if ( $limit === '-1' || $limit === '' || $limit === false ) {
                return 0; // unlimited or unknown
            }
            $limit = trim( $limit );
            $last  = strtolower( substr( $limit, -1 ) );
            $val   = (int) $limit;
            switch ( $last ) {
                case 'g': $val *= 1024;
                case 'm': $val *= 1024;
                case 'k': $val *= 1024;
            }
            return $val;
        }


    /**
     * Extract all elements with translate="no" from the HTML string,
     * replacing each with a unique placeholder token.
     *
     * Uses a depth-counter approach to handle nested tags correctly.
     * Also safely skips <script> and <style> blocks whose content may
     * contain '>' characters that would confuse a naive tag parser.
     *
     * Returns [ $html_with_placeholders, [ placeholder => original_html ] ]
     */
    private function extract_no_translate_blocks( $html ) {
        $placeholders = [];
        $counter      = 0;
        $result       = '';
        $pos          = 0;
        $len          = strlen( $html );

        // Tags whose raw content must be copied verbatim (may contain '<' and '>')
        $raw_tags = [ 'script', 'style', 'noscript', 'textarea' ];

        while ( $pos < $len ) {
            // Find next '<'
            $lt = strpos( $html, '<', $pos );
            if ( $lt === false ) {
                $result .= substr( $html, $pos );
                break;
            }

            // Copy everything before '<'
            if ( $lt > $pos ) {
                $result .= substr( $html, $pos, $lt - $pos );
            }

            // Find the end of this tag
            $gt = strpos( $html, '>', $lt );
            if ( $gt === false ) {
                // Malformed — copy rest as-is
                $result .= substr( $html, $lt );
                break;
            }

            $tag_str = substr( $html, $lt, $gt - $lt + 1 );

            // Extract tag name (handles closing tags and comments)
            if ( substr( $tag_str, 0, 4 ) === '<!--' ) {
                // HTML comment — find closing -->
                $end = strpos( $html, '-->', $lt );
                if ( $end === false ) {
                    $result .= substr( $html, $lt );
                    break;
                }
                $result .= substr( $html, $lt, $end - $lt + 3 );
                $pos = $end + 3;
                continue;
            }

            if ( ! preg_match( '/^<\/?([a-zA-Z][a-zA-Z0-9]*)/', $tag_str, $nm ) ) {
                $result .= $tag_str;
                $pos = $gt + 1;
                continue;
            }

            $tag_name_raw = $nm[1];
            $tag_name     = strtolower( $tag_name_raw );
            $is_closing   = ( $tag_str[1] === '/' );
            $is_self_close = ( substr( rtrim( $tag_str ), -2 ) === '/>' );

            // ── Raw content tags (script/style/etc.) — copy verbatim ──────────
            if ( ! $is_closing && in_array( $tag_name, $raw_tags, true ) ) {
                // Use regex to find the real closing tag (not one inside a JS string like "</script>")
                // The real closing tag is: </ + tagname + optional whitespace + >
                $close_pattern = '#</' . preg_quote( $tag_name, '#' ) . '\s*>#i';
                if ( ! preg_match( $close_pattern, $html, $close_m, PREG_OFFSET_CAPTURE, $gt + 1 ) ) {
                    // No closing tag — copy to end
                    $result .= substr( $html, $lt );
                    break;
                }
                $close_end = $close_m[0][1] + strlen( $close_m[0][0] ) - 1;
                $result .= substr( $html, $lt, $close_end - $lt + 1 );
                $pos = $close_end + 1;
                continue;
            }

            // ── Closing or self-closing tags — copy as-is ────────────────────
            if ( $is_closing || $is_self_close ) {
                $result .= $tag_str;
                $pos = $gt + 1;
                continue;
            }

            // ── Check for translate="no" ──────────────────────────────────────
            if ( ! preg_match( '/\btranslate\s*=\s*["\']no["\']/i', $tag_str ) ) {
                $result .= $tag_str;
                $pos = $gt + 1;
                continue;
            }

            // ── Found translate="no" — extract full block with depth counter ──
            $block_start = $lt;
            $depth       = 1;
            $j           = $gt + 1;

            while ( $j < $len && $depth > 0 ) {
                $next_lt = strpos( $html, '<', $j );
                if ( $next_lt === false ) break;

                $next_gt = strpos( $html, '>', $next_lt );
                if ( $next_gt === false ) break;

                $next_tag = substr( $html, $next_lt, $next_gt - $next_lt + 1 );

                // Skip comments inside the block
                if ( substr( $next_tag, 0, 4 ) === '<!--' ) {
                    $end = strpos( $html, '-->', $next_lt );
                    $j   = $end !== false ? $end + 3 : $next_gt + 1;
                    continue;
                }

                if ( ! preg_match( '/^<\/?([a-zA-Z][a-zA-Z0-9]*)/', $next_tag, $nm2 ) ) {
                    $j = $next_gt + 1;
                    continue;
                }

                $inner_name = strtolower( $nm2[1] );

                // Skip raw-content tags inside the block (their content may contain '>')
                if ( ! ( $next_tag[1] === '/' ) && in_array( $inner_name, $raw_tags, true ) ) {
                    $inner_close_pattern = '#</' . preg_quote( $inner_name, '#' ) . '\s*>#i';
                    if ( ! preg_match( $inner_close_pattern, $html, $inner_m, PREG_OFFSET_CAPTURE, $next_gt + 1 ) ) {
                        $j = $len;
                        break;
                    }
                    $j = $inner_m[0][1] + strlen( $inner_m[0][0] );
                    continue;
                }

                if ( $inner_name === $tag_name ) {
                    if ( $next_tag[1] === '/' ) {
                        $depth--;
                    } elseif ( substr( rtrim( $next_tag ), -2 ) !== '/>' ) {
                        $depth++;
                    }
                }
                $j = $next_gt + 1;
            }

            $block = substr( $html, $block_start, $j - $block_start );
            $token = '<!--GML_NOTRANSLATE_' . $counter . '_' . md5( $block ) . '-->';
            $placeholders[ $token ] = $block;
            $counter++;
            $result .= $token;
            $pos = $j;
        }

        return [ $result, $placeholders ];
    }

    // ── Server-side link rewriting ───────────────────────────────────────────

    /**
     * Rewrite internal links in the final HTML to include the language prefix.
     *
     * WordPress generates all menu links, pagination links, breadcrumbs, etc.
     * pointing to the source language URL (e.g. /about/, /shop/page/2/).
     * Previously we relied on client-side JS (rewriteLinks) to add the prefix,
     * but this caused intermittent redirects to the source language when:
     *   - The user clicked a link before DOMContentLoaded fired
     *   - JS was delayed by slow network or large page
     *   - JS failed due to a conflict with another script
     *
     * By rewriting links server-side, the browser receives HTML with correct
     * URLs already in place. The JS rewriter remains as a safety net for
     * dynamically injected content (AJAX, mega-menus, etc.).
     *
     * Strategy:
     *   - Match href="..." and action="..." attributes
     *   - Only rewrite internal URLs (same origin, no admin/login paths)
     *   - Skip URLs that already have a language prefix
     *   - Skip links inside .gml-language-switcher (already correct)
     *   - Skip non-HTTP schemes (mailto:, tel:, javascript:, #)
     */
    private function rewrite_internal_links( $html ) {
        $home_url    = home_url();
        $home_origin = rtrim( $home_url, '/' );
        $prefix      = '/' . $this->target_lang . '/';

        // Build language code pattern for detecting existing prefixes
        $all_langs = [];
        $source    = get_option( 'gml_source_lang', 'en' );
        $all_langs[] = $source;
        foreach ( get_option( 'gml_languages', [] ) as $l ) {
            $all_langs[] = $l['code'];
        }
        $lang_pattern = implode( '|', array_map( 'preg_quote', array_unique( $all_langs ) ) );

        // NOTE: The language switcher (class="gml-language-switcher" translate="no")
        // has already been extracted by extract_no_translate_blocks() before this
        // method runs, so its links are safely hidden inside placeholder tokens
        // and will NOT be rewritten here.

        // Rewrite only <a href="..."> and <form action="..."> — the same
        // elements that the JS rewriteLinks() targets. We must NOT touch
        // <link href> (stylesheets, canonical, hreflang, preload, prefetch),
        // <base href>, <script src>, <img src>, or any other resource tags.
        //
        // Strategy: match the opening tag + attribute together so we can
        // distinguish <a href="..."> from <link href="...">.
        $html = preg_replace_callback(
            '/<(a)\b([^>]*?)(?<![a-zA-Z\-])(href)\s*=\s*(["\'])(.*?)\4([^>]*?)>/si',
            function( $m ) use ( $home_origin, $prefix, $lang_pattern ) {
                return $this->rewrite_single_link( $m, $home_origin, $prefix, $lang_pattern );
            },
            $html
        );
        $html = preg_replace_callback(
            '/<(form)\b([^>]*?)(?<![a-zA-Z\-])(action)\s*=\s*(["\'])(.*?)\4([^>]*?)>/si',
            function( $m ) use ( $home_origin, $prefix, $lang_pattern ) {
                return $this->rewrite_single_link( $m, $home_origin, $prefix, $lang_pattern );
            },
            $html
        );

        return $html;
    }

    /**
     * Rewrite a single <a href> or <form action> match.
     *
     * @param array  $m            Regex match: [0]=full tag, [1]=tagname, [2]=before-attr, [3]=attr, [4]=quote, [5]=url, [6]=after-attr
     * @param string $home_origin  e.g. "https://www.tc239300.com"
     * @param string $prefix       e.g. "/ru/"
     * @param string $lang_pattern Regex alternation of all language codes
     * @return string Rewritten tag or original
     */
    private function rewrite_single_link( $m, $home_origin, $prefix, $lang_pattern ) {
        $tag    = $m[1];
        $before = $m[2];
        $attr   = $m[3];
        $quote  = $m[4];
        $url    = $m[5];
        $after  = $m[6];

        // Skip empty, anchors, non-http schemes
        if ( $url === '' || $url[0] === '#' ) {
            return $m[0];
        }
        if ( preg_match( '/^(mailto:|tel:|javascript:|data:)/i', $url ) ) {
            return $m[0];
        }

        $path = null;

        if ( preg_match( '#^https?://#i', $url ) ) {
            if ( stripos( $url, $home_origin ) !== 0 ) {
                return $m[0]; // external
            }
            $path = substr( $url, strlen( $home_origin ) ) ?: '/';
        } elseif ( $url[0] === '/' ) {
            $path = $url;
        } else {
            return $m[0]; // relative — skip
        }

        if ( $path[0] !== '/' ) {
            $path = '/' . $path;
        }

        // Skip WordPress system paths
        if ( preg_match( '#^/(wp-admin|wp-login\.php|wp-json|wp-cron|wp-content|wp-includes)(/|$)#i', $path ) ) {
            return $m[0];
        }

        // Skip if already has a language prefix
        if ( preg_match( '#^/(' . $lang_pattern . ')(/|$|\?)#', $path ) ) {
            return $m[0];
        }

        // Skip WooCommerce AJAX / WordPress admin-ajax
        if ( preg_match( '#[?&](wc-ajax|action)=#i', $url ) ) {
            return $m[0];
        }

        // Skip feed URLs
        if ( preg_match( '#^/feed(/|$)#i', $path ) || preg_match( '#/feed/?$#i', $path ) ) {
            return $m[0];
        }

        $new_path = $prefix . ltrim( $path, '/' );

        if ( preg_match( '#^https?://#i', $url ) ) {
            $new_url = $home_origin . $new_path;
        } else {
            $new_url = $new_path;
        }

        return '<' . $tag . $before . $attr . '=' . $quote . $new_url . $quote . $after . '>';
    }

    // ── Language detection ────────────────────────────────────────────────────

    /**
     * Detect the language the visitor wants.
     *
     * ONLY the URL prefix triggers translation (/ru/, /en/, …).
     * We deliberately ignore cookies and Accept-Language headers here —
     * a visitor on /about/ always sees the source language regardless of
     * what language they previously browsed. This matches how Weglot works:
     * the URL is the single source of truth for which language is served.
     *
     * The cookie is still written by the SEO router (so the language switcher
     * can highlight the active language), but it must NOT cause translation
     * on non-prefixed URLs.
     */
    private function detect_target_language() {
        return $this->get_url_language() ?? $this->source_lang;
    }

    /**
     * Extract language code from REQUEST_URI, e.g. /ru/about/ → 'ru'.
     * Returns null if the URL has no recognised language prefix.
     */
    private function get_url_language() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        // Strip query string before matching
        $path = strtok( $uri, '?' );
        if ( preg_match( '#^/([a-z]{2})(/|$)#', $path, $m ) ) {
            $lang = $m[1];
            if ( $this->is_enabled_language( $lang ) ) {
                return $lang;
            }
        }
        return null;
    }

    private function is_enabled_language( $lang ) {
        $configured = get_option( 'gml_languages', [] );
        foreach ( $configured as $l ) {
            if ( ( $l['enabled'] ?? true ) && $l['code'] === $lang ) {
                return true;
            }
        }
        return false;
    }

    // ── Guards ────────────────────────────────────────────────────────────────

    private function should_skip() {
        // Admin pages
        if ( is_admin() ) {
            return true;
        }
        // AJAX
        if ( wp_doing_ajax() ) {
            return true;
        }
        // REST API
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return true;
        }
        // Internal crawler request — wants original HTML, not translated
        if ( isset( $_GET['gml_crawl'] ) && $_GET['gml_crawl'] === '1' ) {
            return true;
        }
        // Login / register
        $pagenow = $GLOBALS['pagenow'] ?? '';
        if ( in_array( $pagenow, [ 'wp-login.php', 'wp-register.php' ], true ) ) {
            return true;
        }
        // No API key
        if ( empty( get_option( 'gml_api_key_encrypted' ) ) ) {
            return true;
        }
        // Translation not yet started by admin
        if ( ! get_option( 'gml_translation_enabled', false ) ) {
            return true;
        }
        // Page builder editor modes — never translate inside live editors
        if ( $this->is_page_builder_editor() ) {
            return true;
        }
        // Non-HTML response (feeds, JSON, etc.)
        if ( ! $this->is_html_response() ) {
            return true;
        }
        // Exclusion rules — check if this URL is excluded from translation
        if ( class_exists( 'GML_Exclusion_Rules' ) ) {
            $exclusion = new GML_Exclusion_Rules();
            if ( $exclusion->is_page_excluded() ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Detect whether the current request is a page builder live editor session.
     * Translation must be disabled in all editor contexts to avoid corrupting
     * the builder's preview iframe or AJAX responses.
     *
     * Covers: Elementor, Beaver Builder, Divi, Bricks, WPBakery, Oxygen, Breakdance.
     */
    private function is_page_builder_editor() {
        // Elementor: sets a query var when in editor preview
        if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) ) {
            return true;
        }
        // Elementor editor action (used in AJAX calls from the editor)
        if ( isset( $_GET['action'] ) && strpos( $_GET['action'], 'elementor' ) !== false ) {
            return true;
        }
        // Beaver Builder: ?fl_builder in URL
        if ( isset( $_GET['fl_builder'] ) ) {
            return true;
        }
        // Divi Visual Builder: ?et_fb=1 or ?et_pb_preview
        if ( isset( $_GET['et_fb'] ) || isset( $_GET['et_pb_preview'] ) ) {
            return true;
        }
        // WPBakery (Visual Composer): ?vc_action or ?vc_editable
        if ( isset( $_GET['vc_action'] ) || isset( $_GET['vc_editable'] ) ) {
            return true;
        }
        // Bricks Builder: ?bricks=run
        if ( isset( $_GET['bricks'] ) ) {
            return true;
        }
        // Oxygen Builder: ?ct_builder
        if ( isset( $_GET['ct_builder'] ) ) {
            return true;
        }
        // Breakdance Builder: ?breakdance=builder
        if ( isset( $_GET['breakdance'] ) ) {
            return true;
        }
        // Generic: any request with ?builder or ?preview=true from known builders
        if ( isset( $_GET['builder'] ) ) {
            return true;
        }
        return false;
    }

    private function is_html_response() {
        foreach ( headers_list() as $header ) {
            if ( stripos( $header, 'Content-Type:' ) === 0 ) {
                return stripos( $header, 'text/html' ) !== false;
            }
        }
        return true; // assume HTML if no Content-Type header yet
    }

    private function is_html( $content ) {
        return stripos( $content, '<html' ) !== false
            || stripos( $content, '<!DOCTYPE' ) !== false;
    }
}
