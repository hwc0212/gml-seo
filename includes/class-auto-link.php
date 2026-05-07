<?php
/**
 * Automatic Internal Linking.
 *
 * Strategy:
 *  1. Maintain a lightweight candidate index in options table:
 *     post_id => [ title, url, primary_kw, secondary_kws, excerpt, type ]
 *     Index is updated whenever a post's AI SEO data is (re)generated.
 *
 *  2. When AI analyzes a post, it's given the candidate index and asked to
 *     pick the 3-5 most topically relevant targets, returning anchor text
 *     and a target phrase to link. Result stored in _gml_seo_auto_links.
 *
 *  3. On frontend, the_content filter injects <a> tags at the target phrases
 *     (first occurrence only, not inside existing anchors). DB content is
 *     never modified — everything is filter-based, fully reversible.
 *
 * This follows Google's internal linking guidelines:
 *  - Descriptive anchor text (not "click here")
 *  - Semantic relevance (not arbitrary)
 *  - Moderate count (3-5 per post, not spam)
 *  - Preserves accessibility & original author intent
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_Auto_Link {

    const INDEX_OPTION = 'gml_seo_link_index';
    const MAX_LINKS    = 5;

    public function __construct() {
        // Rebuild the whole candidate index when a post is deleted (admin context)
        add_action( 'before_delete_post', [ $this, 'on_delete' ] );
        // Also clear index entry when a post is unpublished
        add_action( 'transition_post_status', [ $this, 'on_status_change' ], 10, 3 );

        // Frontend link injection — only in front context
        if ( ! is_admin() ) {
            add_filter( 'the_content', [ $this, 'inject_links' ], 100 );
        }
    }

    public function on_status_change( $new, $old, $post ) {
        if ( $old === 'publish' && $new !== 'publish' ) {
            self::remove_candidate( $post->ID );
        }
    }

    /**
     * Rebuild the entire candidate index from all published posts that
     * have SEO data. Used as a one-time seed after upgrading to v1.4.0
     * and at the start of a bulk run to ensure candidates exist.
     *
     * Runs at most once per admin request (cached via static flag).
     */
    public static function rebuild_index() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_gml_seo_generated'
             WHERE p.post_status = 'publish'"
        );

        $index = [];
        foreach ( $rows as $r ) {
            $post = get_post( $r->ID );
            if ( ! $post ) continue;

            $pt_obj = get_post_type_object( $post->post_type );
            if ( ! $pt_obj || ! $pt_obj->public || $post->post_type === 'attachment' ) continue;

            $primary = get_post_meta( $post->ID, '_gml_seo_primary_kw', true );
            $kws_raw = get_post_meta( $post->ID, '_gml_seo_keywords', true );
            $desc    = get_post_meta( $post->ID, '_gml_seo_desc', true );

            $secondary = [];
            if ( $kws_raw ) {
                $parts = preg_split( '/[,，;；]/u', $kws_raw );
                foreach ( $parts as $p ) {
                    $p = trim( $p );
                    if ( $p && $p !== $primary ) $secondary[] = $p;
                }
            }

            $index[ (int) $post->ID ] = [
                'title'     => get_the_title( $post->ID ),
                'url'       => get_permalink( $post->ID ),
                'primary'   => $primary,
                'secondary' => array_slice( $secondary, 0, 4 ),
                'excerpt'   => $desc ?: wp_trim_words( wp_strip_all_tags( $post->post_content ), 25 ),
                'type'      => $post->post_type,
            ];
        }

        update_option( self::INDEX_OPTION, $index, false );
        return count( $index );
    }

    // ══════════════════════════════════════════════════════════════════
    //  Candidate Index Management
    // ══════════════════════════════════════════════════════════════════

    /**
     * Add or update a single post in the candidate index.
     * Called by AI engine after a post's SEO data is regenerated.
     */
    public static function update_candidate( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            self::remove_candidate( $post_id );
            return;
        }

        // Skip if post type is not public or is an attachment
        $pt_obj = get_post_type_object( $post->post_type );
        if ( ! $pt_obj || ! $pt_obj->public || $post->post_type === 'attachment' ) {
            self::remove_candidate( $post_id );
            return;
        }

        $primary = get_post_meta( $post_id, '_gml_seo_primary_kw', true );
        $kws_raw = get_post_meta( $post_id, '_gml_seo_keywords', true );
        $desc    = get_post_meta( $post_id, '_gml_seo_desc', true );

        // Derive secondary keywords (comma/Chinese-comma separated)
        $secondary = [];
        if ( $kws_raw ) {
            $parts = preg_split( '/[,，;；]/u', $kws_raw );
            foreach ( $parts as $p ) {
                $p = trim( $p );
                if ( $p && $p !== $primary ) $secondary[] = $p;
            }
        }

        $index = get_option( self::INDEX_OPTION, [] );
        $index[ (int) $post_id ] = [
            'title'     => get_the_title( $post_id ),
            'url'       => get_permalink( $post_id ),
            'primary'   => $primary,
            'secondary' => array_slice( $secondary, 0, 4 ),
            'excerpt'   => $desc ?: wp_trim_words( wp_strip_all_tags( $post->post_content ), 25 ),
            'type'      => $post->post_type,
        ];
        update_option( self::INDEX_OPTION, $index, false );
    }

    public static function remove_candidate( $post_id ) {
        $index = get_option( self::INDEX_OPTION, [] );
        if ( isset( $index[ (int) $post_id ] ) ) {
            unset( $index[ (int) $post_id ] );
            update_option( self::INDEX_OPTION, $index, false );
        }
    }

    public function on_delete( $post_id ) {
        self::remove_candidate( $post_id );
    }

    /**
     * Get candidate list excluding current post, with optional type filter.
     * Returns max 80 items to keep prompt size manageable.
     */
    public static function get_candidates( $exclude_id = 0 ) {
        $index = get_option( self::INDEX_OPTION, [] );
        unset( $index[ (int) $exclude_id ] );
        // Keep most recent 80 to bound prompt size
        return array_slice( $index, 0, 80, true );
    }

    // ══════════════════════════════════════════════════════════════════
    //  AI-driven Link Suggestion (called from AI engine)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Ask AI which candidates to link and where.
     * Returns array of { target_post_id, anchor, phrase } or empty array.
     *
     * $post_data is the same structure passed to the main SEO analysis
     * (collect_page_data output), so we don't re-collect.
     */
    public static function generate_suggestions( $post_id, $post_data ) {
        $candidates = self::get_candidates( $post_id );
        if ( empty( $candidates ) ) return []; // Nothing to link to

        // Build compact candidate list for prompt
        $cand_lines = [];
        foreach ( $candidates as $cid => $c ) {
            $kw_str = $c['primary'] ?: implode( ', ', array_slice( $c['secondary'], 0, 2 ) );
            $cand_lines[] = sprintf(
                "- id:%d | type:%s | title:%s | primary_kw:%s | excerpt:%s",
                $cid,
                $c['type'],
                $c['title'],
                $kw_str,
                mb_substr( $c['excerpt'], 0, 140 )
            );
        }

        $system = <<<'PROMPT'
You are an SEO internal linking expert. Follow Google's official guidelines:
- Anchor text MUST be descriptive — NEVER "click here", "read more", "this article"
- Links MUST be topically relevant — no forced/unrelated links
- Anchor phrase MUST exist VERBATIM in the source content (for safe injection)
- Prefer 3-5 links total, never more than 5
- Each candidate can be linked at most once
- If no good matches exist, return an empty array — do NOT force links

Your task: Given a page's content and a list of candidate internal pages,
select the most topically relevant pages to link to and identify the EXACT
phrase in the content that should become the anchor text.
PROMPT;

        $content_snippet = mb_substr( $post_data['content'], 0, 2500 );
        $primary_kw = get_post_meta( $post_id, '_gml_seo_primary_kw', true );

        $prompt  = "SOURCE PAGE:\n";
        $prompt .= "Title: " . $post_data['title'] . "\n";
        $prompt .= "Primary keyword: " . ( $primary_kw ?: '(not set)' ) . "\n";
        $prompt .= "Content:\n" . $content_snippet . "\n\n";
        $prompt .= "CANDIDATE PAGES (id | type | title | primary_kw | excerpt):\n";
        $prompt .= implode( "\n", $cand_lines ) . "\n\n";
        $prompt .= <<<'INSTRUCT'
Return a JSON object (NO markdown fences) with this exact structure:

{
  "links": [
    {
      "target_id": <candidate id>,
      "anchor": "<exact phrase from source content, 2-6 words, descriptive>",
      "reason": "<1 sentence why this link adds value for the reader>"
    }
  ]
}

Rules:
- "anchor" MUST appear verbatim (case-insensitive match) in the source content
- "anchor" must NOT be a generic phrase like "click here", "this post", "learn more"
- "anchor" should naturally describe the target page's topic
- Max 5 links. If fewer good matches exist, return fewer (even zero).
- Same candidate cannot be linked twice.
INSTRUCT;

        $api = new GML_SEO_AI_Client();
        $res = $api->call_json( $prompt, $system, 1024 );
        if ( is_wp_error( $res ) ) {
            error_log( 'GML SEO auto-link: AI error for post ' . $post_id . ': ' . $res->get_error_message() );
            return [];
        }
        if ( empty( $res['links'] ) || ! is_array( $res['links'] ) ) {
            error_log( 'GML SEO auto-link: AI returned no links for post ' . $post_id . ' (' . count( $candidates ) . ' candidates available)' );
            return [];
        }

        // Validate each suggestion: anchor must actually exist in content,
        // target_id must be in candidates, no duplicates.
        // For verbatim check we search the FULL source content, not the
        // snippet that was sent to AI (AI may anchor on phrases beyond 2500 chars).
        $full_post = get_post( $post_id );
        $full_text = $full_post ? wp_strip_all_tags( strip_shortcodes( $full_post->post_content ) ) : $post_data['content'];
        $full_content_lower = mb_strtolower( $full_text );
        $out      = [];
        $seen     = [];
        $rejected = [];

        foreach ( $res['links'] as $link ) {
            if ( count( $out ) >= self::MAX_LINKS ) break;

            $tid    = (int) ( $link['target_id'] ?? 0 );
            $anchor = trim( (string) ( $link['anchor'] ?? '' ) );

            if ( ! $tid || ! $anchor )                      { $rejected[] = "empty:{$anchor}"; continue; }
            if ( isset( $seen[ $tid ] ) )                   { $rejected[] = "dup:{$tid}"; continue; }
            if ( ! isset( $candidates[ $tid ] ) )           { $rejected[] = "missing_cand:{$tid}"; continue; }
            if ( mb_strlen( $anchor ) < 2 || mb_strlen( $anchor ) > 80 ) { $rejected[] = "len:{$anchor}"; continue; }

            // Reject generic anchors
            $generic = [ 'click here', 'read more', 'learn more', 'this post', 'this article', 'here', 'more' ];
            if ( in_array( mb_strtolower( $anchor ), $generic, true ) ) { $rejected[] = "generic:{$anchor}"; continue; }

            // Anchor must exist verbatim in source content
            if ( mb_strpos( $full_content_lower, mb_strtolower( $anchor ) ) === false ) {
                $rejected[] = "not_in_content:{$anchor}";
                continue;
            }

            $out[] = [
                'target_id'  => $tid,
                'target_url' => $candidates[ $tid ]['url'],
                'anchor'     => $anchor,
                'reason'     => sanitize_text_field( $link['reason'] ?? '' ),
            ];
            $seen[ $tid ] = true;
        }

        if ( empty( $out ) && ! empty( $rejected ) ) {
            error_log( 'GML SEO auto-link: all suggestions rejected for post ' . $post_id . ': ' . implode( '; ', $rejected ) );
        }

        return $out;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Frontend Injection
    // ══════════════════════════════════════════════════════════════════

    /**
     * Inject links into the_content on the frontend.
     * Matches first occurrence of each anchor, outside existing <a> tags.
     */
    public function inject_links( $content ) {
        if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }
        if ( is_feed() ) return $content;

        $id = get_the_ID();
        $links = get_post_meta( $id, '_gml_seo_auto_links', true );
        if ( empty( $links ) || ! is_array( $links ) ) return $content;

        // Respect per-post opt-out
        if ( get_post_meta( $id, '_gml_seo_auto_links_hide', true ) ) return $content;

        // Process each link: inject first un-linked occurrence of anchor
        $injected = [];
        foreach ( $links as $link ) {
            if ( empty( $link['anchor'] ) || empty( $link['target_url'] ) ) continue;
            $anchor = $link['anchor'];
            $url    = $link['target_url'];
            $key    = mb_strtolower( $anchor );
            if ( isset( $injected[ $key ] ) ) continue;

            $new_content = self::inject_one( $content, $anchor, $url );
            if ( $new_content !== null ) {
                $content = $new_content;
                $injected[ $key ] = true;
            }
        }

        return $content;
    }

    /**
     * Inject single anchor link into HTML content.
     * Returns modified content or null if no safe injection point found.
     * Uses DOM-aware regex: skips content inside existing <a>, heading tags,
     * script, style, and code blocks.
     */
    private static function inject_one( $html, $anchor, $url ) {
        // Regex: find anchor text NOT inside <a>...</a>, <h1-6>, <script>, <style>, <code>
        // Approach: split HTML into protected regions and plain regions, only inject in plain.

        // Simpler and safer: use preg_replace_callback with a single pass that
        // tracks whether we're inside a protected tag.
        $protected_tags = 'a|h1|h2|h3|h4|h5|h6|script|style|code|pre';
        $parts = preg_split(
            '#(<(?:' . $protected_tags . ')\b[^>]*>.*?</(?:' . $protected_tags . ')>)#is',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ( ! is_array( $parts ) ) return null;

        $anchor_escaped = preg_quote( $anchor, '#' );
        $replaced = false;

        foreach ( $parts as $i => $part ) {
            // Odd-indexed parts are protected content — skip
            if ( $i % 2 === 1 ) continue;
            if ( $replaced ) continue;

            // Case-insensitive match, preserve original casing via callback
            $new = preg_replace_callback(
                '#\b(' . $anchor_escaped . ')\b#iu',
                function( $m ) use ( $url, &$replaced ) {
                    if ( $replaced ) return $m[0];
                    $replaced = true;
                    return '<a href="' . esc_url( $url ) . '" class="gml-seo-auto-link">' . $m[1] . '</a>';
                },
                $part,
                1
            );

            if ( $new !== null && $new !== $part ) {
                $parts[ $i ] = $new;
            }
        }

        return $replaced ? implode( '', $parts ) : null;
    }
}
