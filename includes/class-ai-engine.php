<?php
/**
 * AI Engine — Gemini acts as an SEO Master.
 *
 * Not just title/desc generation. Full expert-level analysis:
 *  - Keyword research & strategy
 *  - Title & description crafted with keyword placement science
 *  - Content structure audit (headings, word count, keyword density)
 *  - Internal linking opportunities
 *  - Image alt text optimization
 *  - URL slug optimization
 *  - Readability & engagement scoring
 *  - Competitor-aware suggestions
 *
 * All actionable items are auto-applied. User sees the report.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_AI_Engine {

    public function __construct() {
        add_action( 'save_post', [ $this, 'on_save' ], 99, 2 );
        add_action( 'gml_seo_generate_cron', [ $this, 'generate_for_post' ] );
        add_action( 'wp_ajax_gml_seo_generate', [ $this, 'ajax_generate' ] );
        add_action( 'wp_ajax_gml_seo_bulk', [ $this, 'ajax_bulk' ] );
    }

    // ── Auto-generate on publish ─────────────────────────────────────

    public function on_save( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;
        if ( $post->post_status !== 'publish' ) return;
        if ( ! in_array( $post->post_type, $this->get_public_types(), true ) ) return;

        $hash = md5( $post->post_title . $post->post_content );
        if ( get_post_meta( $post_id, '_gml_seo_hash', true ) === $hash ) return;

        // Async via cron so save_post isn't blocked
        if ( ! wp_next_scheduled( 'gml_seo_generate_cron', [ $post_id ] ) ) {
            wp_schedule_single_event( time() + 5, 'gml_seo_generate_cron', [ $post_id ] );
        }
    }

    // ── Core: full SEO master analysis ───────────────────────────────

    public function generate_for_post( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) return null;

        $data   = $this->collect_page_data( $post );
        $system = $this->build_master_prompt();
        $prompt = "Analyze this page and provide your expert SEO optimization:\n\n"
                . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

        $api    = new GML_SEO_Gemini();
        $result = $api->call_json( $prompt, $system, 4096 );

        if ( is_wp_error( $result ) ) {
            error_log( 'GML SEO AI error [post ' . $post_id . ']: ' . $result->get_error_message() );
            return $result;
        }

        // ── Auto-apply everything ────────────────────────────────────
        $this->apply_result( $post_id, $post, $result );

        // Store full report for metabox display
        update_post_meta( $post_id, '_gml_seo_report', $result );
        update_post_meta( $post_id, '_gml_seo_generated', current_time( 'mysql' ) );
        update_post_meta( $post_id, '_gml_seo_hash', md5( $post->post_title . $post->post_content ) );

        // ── Generate auto internal links (second AI call, cheap) ─────
        // Uses the candidate index built from all previously-analyzed posts.
        if ( class_exists( 'GML_SEO_Auto_Link' ) ) {
            $links = GML_SEO_Auto_Link::generate_suggestions( $post_id, $data );
            if ( ! empty( $links ) ) {
                update_post_meta( $post_id, '_gml_seo_auto_links', $links );
            } else {
                delete_post_meta( $post_id, '_gml_seo_auto_links' );
            }
        }

        return $result;
    }

    // ── Collect all page data for AI ─────────────────────────────────

    private function collect_page_data( $post ) {
        $content = $post->post_content;
        $plain   = wp_strip_all_tags( strip_shortcodes( $content ) );
        $words   = str_word_count( $plain );

        // Headings
        $headings = [];
        if ( preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h\1>/si', $content, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $h ) $headings[] = 'H' . $h[1] . ': ' . wp_strip_all_tags( $h[2] );
        }

        // Images — collect src for AI alt text generation
        $images = [];
        if ( preg_match_all( '/<img[^>]+>/si', $content, $im ) ) {
            foreach ( $im[0] as $idx => $tag ) {
                $alt = preg_match( '/alt=["\']([^"\']*)["\']/', $tag, $a ) ? $a[1] : '';
                $src = preg_match( '/src=["\']([^"\']+)["\']/', $tag, $s ) ? basename( $s[1] ) : '';
                $images[] = [
                    'index'       => $idx,
                    'filename'    => $src,
                    'alt'         => $alt,
                    'missing_alt' => empty( trim( $alt ) ),
                ];
            }
        }

        // Links
        $home = home_url();
        $internal = $external = 0;
        if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/si', $content, $lm ) ) {
            foreach ( $lm[1] as $href ) {
                if ( strpos( $href, $home ) === 0 || $href[0] === '/' || $href[0] === '#' ) $internal++;
                elseif ( preg_match( '#^https?://#', $href ) ) $external++;
            }
        }

        // Featured image
        $thumb_id  = get_post_thumbnail_id( $post->ID );
        $thumb_alt = $thumb_id ? get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) : '';

        // Categories & tags
        $cats = wp_get_post_categories( $post->ID, [ 'fields' => 'names' ] );
        $tags = wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] );

        // Existing SEO data (if any)
        $existing_title = get_post_meta( $post->ID, '_gml_seo_title', true );
        $existing_desc  = get_post_meta( $post->ID, '_gml_seo_desc', true );

        return [
            'title'           => $post->post_title,
            'slug'            => $post->post_name,
            'url'             => get_permalink( $post->ID ),
            'type'            => $post->post_type,
            'word_count'      => $words,
            'content'         => mb_substr( $plain, 0, 3000 ),
            'headings'        => $headings,
            'images'          => $images,
            'images_no_alt'   => count( array_filter( $images, fn($i) => $i['missing_alt'] ) ),
            'internal_links'  => $internal,
            'external_links'  => $external,
            'featured_image'  => $thumb_id ? true : false,
            'featured_img_alt'=> $thumb_alt,
            'categories'      => $cats ?: [],
            'tags'            => $tags ?: [],
            'site_name'       => GML_SEO::opt( 'site_name', get_bloginfo( 'name' ) ),
            'separator'       => GML_SEO::opt( 'separator', '-' ),
            'locale'          => get_locale(),
            'existing_seo_title' => $existing_title,
            'existing_seo_desc'  => $existing_desc,
        ];
    }

    // ── The SEO Master Prompt ────────────────────────────────────────

    private function build_master_prompt() {
        return <<<'PROMPT'
You are a world-class SEO consultant. Your optimization MUST follow Google's official SEO guidelines (Google Search Essentials + SEO Starter Guide). Every recommendation must be something Google explicitly endorses or rewards.

## GOOGLE'S CORE PRINCIPLES (you must follow these)

1. PEOPLE-FIRST CONTENT: Google rewards content that is helpful, reliable, and created for people, not search engines. Content should demonstrate Experience, Expertise, Authoritativeness, and Trustworthiness (E-E-A-T).

2. NO KEYWORD STUFFING: Google's spam policies explicitly prohibit excessively repeating keywords. Write naturally. Google's language matching systems understand page relevance even without exact keyword matches.

3. TITLE TAG: Google says a good title is "unique to the page, clear and concise, and accurately describes the contents of the page." The <title> element is a primary source for the title link shown in search results. Google may rewrite titles that are too long, keyword-stuffed, or don't match page content. Keep ≤ 60 characters.

4. META DESCRIPTION: Google says it should be "a succinct, one- or two-sentence summary of the page." It's your elevator pitch in search results. Google bolds matching query terms. Occasionally Google generates its own snippet from page content if the meta description isn't relevant. Keep 120-155 characters.

5. DESCRIPTIVE URLs: Google says "Try to include words in the URL that may be useful for users." URLs appear as breadcrumbs in search results. Avoid random IDs. Use readable, keyword-relevant slugs.

6. IMAGE ALT TEXT: Google says alt text is "a short, but descriptive piece of text that explains the relationship between the image and your content." Good alt text is critical for both SEO and accessibility.

7. INTERNAL LINKS: Google says "Links are a great way to connect your users and search engines to other parts of your site." Links help Google discover pages. Use descriptive anchor text that tells users and Google what the linked page contains.

8. STRUCTURED DATA: Google recommends JSON-LD format. Valid structured data makes pages eligible for rich results (review stars, carousels, FAQs, etc.). Must accurately represent page content — never add markup for content not on the page.

9. THINGS GOOGLE SAYS DON'T MATTER:
   - meta keywords tag (Google Search doesn't use it)
   - exact heading order (H1→H2→H3 semantic order doesn't affect ranking)
   - minimum/maximum content length (no magic word count)
   - keywords in domain name (hardly any ranking effect)
   - duplicate content "penalty" (it's inefficient but not penalized)

## YOUR ANALYSIS FRAMEWORK

### 1. SEARCH INTENT & KEYWORD STRATEGY
- Identify the PRIMARY search query a real user would type to find this page
- Identify 3-5 SECONDARY queries (long-tail, related, "People Also Ask" style)
- Classify intent: informational / transactional / navigational / commercial investigation
- Think: "What would a user searching for this topic actually type into Google?"

### 2. TITLE TAG (following Google's guidelines)
- Clear, concise, accurately describes the page — NOT clickbait
- Include the primary search term naturally near the beginning
- Unique to this page — don't use generic/boilerplate titles
- ≤ 60 characters to avoid truncation
- May include site name at end IF space allows and it adds value
- DO NOT keyword-stuff. Google will rewrite stuffed titles.

### 3. META DESCRIPTION (following Google's guidelines)
- One or two sentence summary of the page's most relevant points
- 120-155 characters (Google truncates at ~155 on desktop, ~120 on mobile)
- Include primary search term naturally (Google bolds matching terms in results)
- Write as compelling ad copy — this determines whether users click YOUR result
- Include a value proposition or call-to-action
- Must be unique to this page

### 4. OPEN GRAPH (social sharing)
- OG title: can be more engaging/emotional than SEO title — optimized for social feeds
- OG description: enticing, makes people want to click when shared on Facebook/Twitter/LinkedIn
- ≤ 70 chars for OG title, ≤ 160 chars for OG description

### 5. CONTENT QUALITY AUDIT (Google's people-first content criteria)
- Is the content helpful and reliable?
- Is it well-written, easy to read, free of errors?
- Is it unique (not rehashed from other sources)?
- Is it up-to-date?
- Does it demonstrate experience/expertise on the topic?
- Are headings used to help users navigate? (semantic order doesn't matter for ranking, but helps UX)
- Are there distracting ads or interstitials?

### 6. IMAGE SEO (following Google's guidelines)
- Do all images have descriptive alt text explaining the image's relationship to content?
- Are high-quality images placed near relevant text?
- Does the featured image exist and have alt text?

### 7. LINK AUDIT (following Google's guidelines)
- Are there internal links connecting to other relevant pages on the site?
- Is anchor text descriptive (not "click here")?
- Are there outbound links to trusted, relevant resources?
- Do user-generated links have nofollow?

### 8. URL/SLUG OPTIMIZATION
- Is the slug descriptive and useful for users?
- Does it contain meaningful words (not random IDs)?
- Is it concise? (remove unnecessary stop words)

### 9. TECHNICAL CHECKS
- Featured image present?
- Canonical URL correct?
- Any noindex that shouldn't be there?

### 10. FAQ GENERATION (for rich results)
Generate 3-5 frequently asked questions that REAL users searching for this topic would ask. Each Q&A must:
- Be based on actual content of the page — do NOT invent facts not supported by the content
- Match "People Also Ask" style questions (conversational, specific)
- Have answer 40-150 words, complete and self-contained
- Cover different angles of the primary keyword / search intent
- If the page content is too thin to support accurate FAQ, return an empty array

## OUTPUT FORMAT

Return a valid JSON object (NO markdown fences):

{
  "score": <0-100 overall SEO health score>,
  "grade": "<A+|A|B+|B|C+|C|D|F>",

  "primary_keyword": "<the most natural search query a user would type to find this page>",
  "secondary_keywords": ["<query2>", "<query3>", "<query4>"],
  "search_intent": "<informational|transactional|navigational|commercial>",

  "title": "<optimized title tag, ≤60 chars, clear, concise, accurately describes page>",
  "desc": "<meta description, 120-155 chars, compelling summary with value proposition>",
  "og_title": "<social-optimized title, more engaging, ≤70 chars>",
  "og_desc": "<social-optimized description, enticing, ≤160 chars>",
  "keywords": "<primary query, secondary1, secondary2, secondary3>",

  "slug_suggestion": "<optimized slug with descriptive words, or null if current is good>",

  "audit": {
    "content_score": <0-100 based on Google's people-first content criteria>,
    "issues": [
      {
        "type": "<critical|warning|info|good>",
        "category": "<title|description|content|headings|images|links|technical|social>",
        "message": "<specific issue found>",
        "fix": "<specific actionable fix, or null if just informational>"
      }
    ]
  },

  "content_suggestions": [
    "<specific, actionable suggestion based on Google's content quality guidelines>"
  ],

  "internal_link_suggestions": [
    "<specific suggestion with descriptive anchor text, per Google's link guidelines>"
  ],

  "alt_text_suggestions": {
    "<image_index_or_description>": "<descriptive alt text explaining image's relationship to content>"
  },

  "faq": [
    {
      "q": "<natural question a user would ask>",
      "a": "<complete answer 40-150 words, grounded in page content>"
    }
  ]
}

## CRITICAL RULES

1. Respond in the SAME LANGUAGE as the page content
2. Follow Google's guidelines strictly — no black-hat tactics, no keyword stuffing, no clickbait
3. Title must accurately describe the page (Google rewrites misleading titles)
4. Description must summarize the page's most relevant points (Google ignores irrelevant descriptions)
5. Be specific in audit issues — "Add more content" is useless; "Add a section explaining [specific topic] to better answer the search query '[specific query]'" is useful
6. Score honestly — a page with no custom title, thin content, no internal links = 20-30, not 60
7. At least 5 audit issues, even for well-optimized pages
8. alt_text_suggestions: provide descriptive alt text for images missing it, following Google's guideline that alt text should explain the image's relationship to the content
9. DO NOT suggest meta keywords tag optimization — Google explicitly ignores it
10. Internal link suggestions must use descriptive anchor text (never "click here" — Google says anchor text should tell users what the linked page contains)
11. FAQ questions must be what REAL users would ask (use "People Also Ask" style). Answers must be grounded in the page content — never invent facts. If the page is too thin, return an empty faq array rather than making things up.
PROMPT;
    }

    // ── Auto-apply AI results ────────────────────────────────────────

    private function apply_result( $post_id, $post, $result ) {
        // Core SEO meta — always apply
        $meta_map = [
            'title'    => '_gml_seo_title',
            'desc'     => '_gml_seo_desc',
            'og_title' => '_gml_seo_og_title',
            'og_desc'  => '_gml_seo_og_desc',
            'keywords' => '_gml_seo_keywords',
        ];
        foreach ( $meta_map as $k => $meta_key ) {
            if ( ! empty( $result[ $k ] ) ) {
                update_post_meta( $post_id, $meta_key, sanitize_text_field( $result[ $k ] ) );
            }
        }

        // Store primary keyword separately (used by schema, content analysis)
        if ( ! empty( $result['primary_keyword'] ) ) {
            update_post_meta( $post_id, '_gml_seo_primary_kw', sanitize_text_field( $result['primary_keyword'] ) );
        }

        // Score
        if ( isset( $result['score'] ) ) {
            update_post_meta( $post_id, '_gml_seo_score', (int) $result['score'] );
        }

        // FAQ data — stored for frontend rendering (schema + optional section)
        if ( ! empty( $result['faq'] ) && is_array( $result['faq'] ) ) {
            $faq_clean = [];
            foreach ( $result['faq'] as $item ) {
                if ( ! empty( $item['q'] ) && ! empty( $item['a'] ) ) {
                    $faq_clean[] = [
                        'q' => sanitize_text_field( $item['q'] ),
                        'a' => wp_kses_post( $item['a'] ),
                    ];
                }
            }
            if ( ! empty( $faq_clean ) ) {
                update_post_meta( $post_id, '_gml_seo_faq', $faq_clean );
            } else {
                delete_post_meta( $post_id, '_gml_seo_faq' );
            }
        }

        // After SEO data is saved, update the auto-link candidate index
        if ( class_exists( 'GML_SEO_Auto_Link' ) ) {
            GML_SEO_Auto_Link::update_candidate( $post_id );
        }

        // Auto-fix slug if AI suggests and it's different
        if ( ! empty( $result['slug_suggestion'] ) && $result['slug_suggestion'] !== $post->post_name ) {
            // Only auto-fix slug if the post was just created (avoid breaking existing URLs)
            $age = time() - strtotime( $post->post_date_gmt );
            if ( $age < 86400 ) { // Less than 24 hours old
                wp_update_post( [
                    'ID'        => $post_id,
                    'post_name' => sanitize_title( $result['slug_suggestion'] ),
                ] );
            }
            // Store suggestion for display even if not auto-applied
            update_post_meta( $post_id, '_gml_seo_slug_suggestion', sanitize_text_field( $result['slug_suggestion'] ) );
        }

        // Auto-fix featured image alt if missing
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( $thumb_id && empty( get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) ) ) {
            // Use AI-suggested alt or fall back to SEO title
            $alt = $result['title'] ?? $post->post_title;
            // Check if AI provided specific alt text suggestions
            if ( ! empty( $result['alt_text_suggestions'] ) ) {
                $alts = array_values( $result['alt_text_suggestions'] );
                if ( ! empty( $alts[0] ) ) $alt = $alts[0];
            }
            update_post_meta( $thumb_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
        }

        // Auto-fix content image alt texts where possible
        if ( ! empty( $result['alt_text_suggestions'] ) ) {
            $content  = $post->post_content;
            $modified = false;
            foreach ( $result['alt_text_suggestions'] as $key => $suggested_alt ) {
                if ( empty( $suggested_alt ) ) continue;
                // Try to find images with empty alt and fill them
                // Match: alt="" or alt='' (empty alt attributes)
                $pattern = '/<img([^>]*)\balt=["\']["\']([^>]*)>/i';
                if ( preg_match( $pattern, $content ) ) {
                    $content = preg_replace(
                        $pattern,
                        '<img$1alt="' . esc_attr( sanitize_text_field( $suggested_alt ) ) . '"$2>',
                        $content,
                        1 // Only replace one at a time
                    );
                    $modified = true;
                }
            }
            if ( $modified ) {
                // Use wp_update_post but remove our own hook to avoid infinite loop
                remove_action( 'save_post', [ $this, 'on_save' ], 99 );
                wp_update_post( [ 'ID' => $post_id, 'post_content' => $content ] );
                add_action( 'save_post', [ $this, 'on_save' ], 99, 2 );
            }
        }
    }

    // ── AJAX: manual generate ────────────────────────────────────────

    public function ajax_generate() {
        check_ajax_referer( 'gml_seo_nonce' );
        $pid = absint( $_POST['post_id'] ?? 0 );
        if ( ! $pid || ! current_user_can( 'edit_post', $pid ) ) wp_send_json_error( 'Unauthorized' );

        delete_post_meta( $pid, '_gml_seo_hash' );
        $result = $this->generate_for_post( $pid );

        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

        wp_send_json_success( [
            'meta'   => $this->get_meta_for_js( $pid ),
            'report' => $result,
        ] );
    }

    // ── AJAX: bulk ───────────────────────────────────────────────────

    public function ajax_bulk() {
        check_ajax_referer( 'gml_seo_bulk' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $pid    = absint( $_POST['post_id'] ?? 0 );
        $result = $this->generate_for_post( $pid );

        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
        wp_send_json_success( [ 'score' => $result['score'] ?? 0 ] );
    }

    // ── Helpers ──────────────────────────────────────────────────────

    public function get_meta_for_js( $post_id ) {
        return [
            'title'      => get_post_meta( $post_id, '_gml_seo_title', true ),
            'desc'       => get_post_meta( $post_id, '_gml_seo_desc', true ),
            'og_title'   => get_post_meta( $post_id, '_gml_seo_og_title', true ),
            'og_desc'    => get_post_meta( $post_id, '_gml_seo_og_desc', true ),
            'keywords'   => get_post_meta( $post_id, '_gml_seo_keywords', true ),
            'score'      => get_post_meta( $post_id, '_gml_seo_score', true ),
            'generated'  => get_post_meta( $post_id, '_gml_seo_generated', true ),
        ];
    }

    private function get_public_types() {
        return array_values( get_post_types( [ 'public' => true ], 'names' ) );
    }
}
