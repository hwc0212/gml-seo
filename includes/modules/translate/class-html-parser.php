<?php
/**
 * GML HTML Parser — regex-based text extraction, DOMDocument for node location only.
 *
 * Strategy: use DOMDocument to FIND translatable text, but do NOT use saveHTML()
 * to rebuild the page (it truncates on some libxml versions).
 * Instead we return a replacement map and apply it to the original HTML string.
 *
 * @package GML_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_HTML_Parser {

    private $skip_tags = [ 'script', 'style', 'code', 'pre', 'svg', 'noscript', 'iframe', 'textarea' ];
    private $text_attrs = [ 'alt', 'placeholder', 'aria-label', 'aria-description' ];
    private $seo_meta_names = [
        'title', 'description', 'keywords',
        'og:title', 'og:description', 'og:site_name',
        'twitter:title', 'twitter:description',
    ];
    private $protected_terms = [];

    public function __construct() {
        $this->protected_terms = get_option( 'gml_protected_terms', [
            'GML', 'WordPress', 'WooCommerce', 'Gemini',
        ] );
    }

    /**
     * Parse HTML — returns [ 'html' => original_html, 'nodes' => [...] ]
     * We use DOMDocument only to walk the tree; we never call saveHTML().
     */
    public function parse( $html ) {
        libxml_use_internal_errors( true );
        $dom = new DOMDocument( '1.0', 'UTF-8' );
        $encoded = mb_encode_numericentity( $html, [ 0x80, 0x10FFFF, 0, 0x1FFFFF ], 'UTF-8' );
        $dom->loadHTML( $encoded, LIBXML_HTML_NODEFDTD | LIBXML_COMPACT );
        libxml_clear_errors();

        $nodes = [];
        if ( $dom->documentElement ) {
            $this->walk( $dom->documentElement, $nodes );
        }

        return [ 'html' => $html, 'nodes' => $nodes ];
    }

    /**
     * Apply translations to the original HTML string.
     * $parsed['replacements'] = [ original_text => translated_text ]
     *
     * IMPORTANT: Before doing str_replace we tokenise all URL-bearing attributes
     * (href, src, srcset, action, data-src, data-href, poster, formaction, etc.)
     * so that str_replace never touches URL values — even if a word in the URL
     * happens to match a translatable text node.
     *
     * This is the fix for: /ru/product/portable-childrens-Имя-hanging-buckle/
     * and for broken image src / srcset after translation.
     */
    public function rebuild( $parsed ) {
        $html         = $parsed['html'];
        $replacements = $parsed['replacements'] ?? [];

        if ( empty( $replacements ) ) {
            return $html;
        }

        // ── Step 1: tokenise all attribute values that must not be translated ───
        //
        // Strategy: tokenise FIRST, translate SECOND, restore THIRD.
        // Any attribute value that is tokenised is completely invisible to
        // str_replace — even if its content happens to match a translatable string.
        //
        // Categories protected (in execution order):
        //
        // BLOCK-LEVEL (run first to prevent attribute patterns matching inside):
        // L) HTML comments <!-- ... -->
        // G) <svg>...</svg> blocks
        // K) <code>...</code> blocks
        // O) <script>...</script> blocks
        // P) <style>...</style> blocks
        // Q) <pre>...</pre> blocks
        // R) <noscript>...</noscript> blocks
        // S) <textarea>...</textarea> blocks
        // T) <iframe>...</iframe> blocks
        //
        // ATTRIBUTE-LEVEL:
        // A) Standard URL attributes: href, src, srcset, action, poster,
        //    formaction, and common data-src / data-lazy / data-bg variants.
        // B) style="..." — CSS values, background-image URLs, etc.
        // C) content="..." on <meta> tags — technical values only.
        // D) data-* attributes with JSON/URLs/image paths.
        // E) value="..." on <input>/<button> — form values.
        // F) ALL remaining data-* attributes.
        // H) class="..." — CSS class names.
        // I) id="..." — element IDs.
        // J) on*="..." — inline event handlers.
        // M) ARIA state/property attributes + other technical HTML attributes
        //    (aria-expanded, aria-hidden, role, tabindex, hidden, type, etc.)

        $url_tokens    = [];
        $token_counter = 0;

        $tokenise = function( $match ) use ( &$url_tokens, &$token_counter ) {
            $token = '<!--GMLURL_' . $token_counter . '_' . md5( $match[0] ) . '-->';
            $url_tokens[ $token ] = $match[0];
            $token_counter++;
            return $token;
        };

        // ── Block-level protection (must run FIRST) ──────────────────────────
        // These protect entire blocks of HTML that should never be touched.
        // They must run before attribute-level patterns (A-J) because those
        // patterns would match syntax inside comments/SVG/code and corrupt
        // the block structure. E.g. <!-- data-section="header_html" --> would
        // have its data-* matched by Category F, breaking the comment.

        // L) HTML comments <!-- ... --> — may contain conditional comments,
        //    IE hacks, builder markers, or attribute-like syntax that must
        //    not be matched by subsequent attribute patterns.
        $html = preg_replace_callback(
            '/<!--(?!GMLURL_|GML_NOTRANSLATE_).*?-->/s',
            $tokenise,
            $html
        );

        // G) <svg>...</svg> — tokenise entire SVG blocks. SVG elements contain
        //    technical text (<text>, <title>, <desc>) and attribute values that
        //    must never be translated. walk() already skips SVG for extraction,
        //    but str_replace is global and would still hit SVG content if any
        //    SVG text matches a translatable string elsewhere on the page.
        $html = preg_replace_callback(
            '/<svg\b[^>]*>.*?<\/svg>/si',
            $tokenise,
            $html
        );

        // K) <code>...</code> — inline code snippets should not be translated.
        //    walk() already skips <code> for extraction, but str_replace is global.
        $html = preg_replace_callback(
            '/<code\b[^>]*>.*?<\/code>/si',
            $tokenise,
            $html
        );

        // O) <script>...</script> — JavaScript code must never be translated.
        //    walk() skips <script> for text extraction, but str_replace is global.
        //    Page builders like Oxygen embed inline JS that references CSS class
        //    names (e.g. jQuery('.t-auto-close')), and if a class name fragment
        //    like "close" matches a translatable word, str_replace corrupts the
        //    JS selector, breaking accordion/toggle/tab functionality.
        $html = preg_replace_callback(
            '/<script\b[^>]*>.*?<\/script>/si',
            $tokenise,
            $html
        );

        // P) <style>...</style> — CSS code must never be translated.
        //    Same reasoning as <script>: CSS selectors, property values, and
        //    animation names could be corrupted by str_replace.
        $html = preg_replace_callback(
            '/<style\b[^>]*>.*?<\/style>/si',
            $tokenise,
            $html
        );

        // Q) <pre>...</pre> — preformatted text / code blocks.
        //    walk() skips <pre> for text extraction, but str_replace is global.
        $html = preg_replace_callback(
            '/<pre\b[^>]*>.*?<\/pre>/si',
            $tokenise,
            $html
        );

        // R) <noscript>...</noscript> — fallback content for non-JS browsers.
        //    May contain HTML with text that looks translatable but is not
        //    visible to JS-enabled users. str_replace could corrupt it.
        $html = preg_replace_callback(
            '/<noscript\b[^>]*>.*?<\/noscript>/si',
            $tokenise,
            $html
        );

        // S) <textarea>...</textarea> — form textarea default values.
        //    Content between tags is raw text, not child elements.
        //    walk() skips <textarea>, but str_replace could hit matching text.
        $html = preg_replace_callback(
            '/<textarea\b[^>]*>.*?<\/textarea>/si',
            $tokenise,
            $html
        );

        // T) <iframe>...</iframe> — embedded content frames.
        //    walk() skips <iframe>, and any fallback text inside should not
        //    be translated as it may contain technical content or URLs.
        $html = preg_replace_callback(
            '/<iframe\b[^>]*>.*?<\/iframe>/si',
            $tokenise,
            $html
        );

        // N) <title>...</title> — protect the entire title tag from global
        //    str_replace. The title text will be replaced precisely inside
        //    the tokenised block AFTER the main str_replace pass, preventing
        //    cross-contamination with description or other overlapping texts.
        $title_token_map = []; // token => [ 'full' => '<title>...</title>', 'inner' => 'text' ]
        $html = preg_replace_callback(
            '/<title\b[^>]*>(.*?)<\/title>/si',
            function( $m ) use ( &$url_tokens, &$token_counter, &$title_token_map ) {
                $token = '<!--GMLURL_' . $token_counter . '_' . md5( $m[0] ) . '-->';
                $url_tokens[ $token ] = $m[0]; // will be overwritten later with translated version
                $title_token_map[ $token ] = [
                    'full'  => $m[0],
                    'inner' => $m[1],
                ];
                $token_counter++;
                return $token;
            },
            $html
        );

        // ── Attribute-level protection ───────────────────────────────────────

        // A) Standard URL attributes (single-line values are fine with /s flag)
        $url_attrs = 'href|src|srcset|action|poster|formaction'
                   . '|data\-src|data\-href|data\-bg|data\-lazy|data\-original'
                   . '|data\-url|data\-link|data\-image|data\-thumb|data\-full'
                   . '|data\-large_image|data\-zoom\-image';
        $html = preg_replace_callback(
            '/\b(' . $url_attrs . ')\s*=\s*(["\'])(.*?)\2/si',
            $tokenise,
            $html
        );

        // B) style="..." — tokenise entire attribute value
        $html = preg_replace_callback(
            '/\bstyle\s*=\s*(["\'])(.*?)\1/si',
            $tokenise,
            $html
        );

        // C) content="..." on <meta> tags — protect URL/technical values only.
        //    og:title, og:description, twitter:title etc. ARE translated (their
        //    text is already in the replacements map from parse()).
        //    We only tokenise content values that look like URLs, file paths,
        //    or technical directives (robots, viewport, charset, etc.) —
        //    i.e. values that should NEVER be translated.
        $html = preg_replace_callback(
            '/<meta\b([^>]*)\bcontent\s*=\s*(["\'])(.*?)\2([^>]*>)/si',
            function( $m ) use ( &$url_tokens, &$token_counter ) {
                $content = $m[3];
                // Only tokenise if the content looks like a URL, file path,
                // or a known technical meta value (not human-readable text).
                $is_technical = (
                    preg_match( '#^https?://#i', $content )           // absolute URL
                    || preg_match( '#^/#', $content )                  // root-relative path
                    || preg_match( '/\.(jpg|jpeg|png|webp|gif|svg|mp4)(\?|$)/i', $content ) // image path
                    || preg_match( '/^(noindex|nofollow|index|follow|noarchive|max-image-preview|max-snippet)/i', $content ) // robots
                    || preg_match( '/^width=device-width/i', $content ) // viewport
                    || preg_match( '/^[a-zA-Z0-9+\/=]{20,}$/', $content ) // base64 / token
                    || preg_match( '/^\d+$/', $content )               // pure number
                );
                if ( ! $is_technical ) {
                    return $m[0]; // leave translatable meta content alone
                }
                $token = '<!--GMLURL_' . $token_counter . '_' . md5( $m[0] ) . '-->';
                $url_tokens[ $token ] = $m[0];
                $token_counter++;
                return $token;
            },
            $html
        );

        // D) data-* attributes containing JSON / URLs / image paths.
        //    Match any data-* whose value contains a URL-like pattern.
        //    Using a possessive-style pattern to avoid catastrophic backtracking.
        $html = preg_replace_callback(
            '/\bdata-[a-z][a-z0-9_-]*\s*=\s*(["\'])([^"\']*(?:https?:|\.jpg|\.jpeg|\.png|\.webp|\.gif|\.svg|\.mp4|\.webm|"url"|\\\\u)[^"\']*)\1/si',
            $tokenise,
            $html
        );

        // E) value="..." on non-text <input> elements and <button> elements.
        //    Protects nonces, IDs, submit values, hidden field values.
        //    We do NOT protect <input type="text|search|email|tel|url|password">
        //    because those are user-facing labels handled elsewhere.
        $html = preg_replace_callback(
            '/(<(?:input|button)\b[^>]*)\bvalue\s*=\s*(["\'])(.*?)\2/si',
            function( $m ) use ( &$url_tokens, &$token_counter ) {
                // Check if this is a text-type input — if so, leave it alone
                if ( preg_match( '/\btype\s*=\s*["\'](?:text|search|email|tel|url|password)["\']/i', $m[1] ) ) {
                    return $m[0]; // don't tokenise
                }
                $token = '<!--GMLURL_' . $token_counter . '_' . md5( $m[0] ) . '-->';
                $url_tokens[ $token ] = $m[0];
                $token_counter++;
                return $token;
            },
            $html
        );

        // F) ALL data-* attributes — tokenise every data-* value regardless of
        //    content. Page builders (Oxygen, Elementor, Bricks, etc.) store JS
        //    config, action names, state flags, and selectors in data-* attrs.
        //    If str_replace hits e.g. data-action="close" → data-action="schließen",
        //    the JS breaks and hidden elements become visible.
        //    Category D above already caught URL/JSON data-* attrs; this pass
        //    catches ALL remaining data-* attrs that D missed.
        $html = preg_replace_callback(
            '/\bdata-[a-z][a-z0-9_-]*\s*=\s*(["\'])(.*?)\1/si',
            $tokenise,
            $html
        );

        // H) class="..." — CSS class names must never be translated. Page builders
        //    use semantic class names like "close", "hidden", "active", "open",
        //    "menu-item", "button-text" etc. If a class name matches translatable
        //    text, str_replace would break CSS selectors and JS querySelector calls.
        $html = preg_replace_callback(
            '/\bclass\s*=\s*(["\'])(.*?)\1/si',
            $tokenise,
            $html
        );

        // I) id="..." — element IDs are used by JS (getElementById, querySelector)
        //    and CSS. Must never be translated.
        $html = preg_replace_callback(
            '/\bid\s*=\s*(["\'])(.*?)\1/si',
            $tokenise,
            $html
        );

        // J) on*="..." event handler attributes — inline JS code that must not
        //    be translated. Covers onclick, onchange, onsubmit, onload, etc.
        $html = preg_replace_callback(
            '/\bon[a-z]+\s*=\s*(["\'])(.*?)\1/si',
            $tokenise,
            $html
        );

        // M) ARIA state/property attributes and other technical HTML attributes.
        //    Accordion/toggle/tab components use aria-expanded="false",
        //    aria-hidden="true", aria-selected, aria-controls, role, tabindex
        //    etc. to track open/closed state. If str_replace changes "false"
        //    or "true" (or any value inside these attrs), the JS state machine
        //    breaks and all panels expand or collapse incorrectly.
        //    We protect ALL aria-* EXCEPT aria-label and aria-description
        //    (those contain human-readable text and are in $text_attrs for
        //    translation via walk()).
        //    Also protects: role, tabindex, hidden, type, name, for, method,
        //    enctype, target, rel, media, sizes, loading, decoding, dir, lang,
        //    translate, slot, is, part, contenteditable, draggable, spellcheck,
        //    autocomplete, autofocus, disabled, readonly, required, checked,
        //    selected, multiple, colspan, rowspan, scope, headers, wrap, width,
        //    height, min, max, step, pattern, maxlength, minlength, accept,
        //    crossorigin, integrity, referrerpolicy, fetchpriority, nonce.
        $html = preg_replace_callback(
            '/\b(?:aria-(?!label\b|description\b)[a-z][a-z0-9-]*|role|tabindex|hidden|type|name|for|method|enctype|target|rel|media|sizes|loading|decoding|dir|lang|translate|slot|is|part|contenteditable|draggable|spellcheck|autocomplete|autofocus|disabled|readonly|required|checked|selected|multiple|colspan|rowspan|scope|headers|wrap|width|height|min|max|step|pattern|maxlength|minlength|accept|crossorigin|integrity|referrerpolicy|fetchpriority|nonce)\s*=\s*(["\'])(.*?)\1/si',
            $tokenise,
            $html
        );

        // Longest originals first to avoid partial-match collisions
        uksort( $replacements, function( $a, $b ) {
            return mb_strlen( $b ) - mb_strlen( $a );
        });

        // Map of UTF-8 characters → common HTML entity forms found in WordPress
        // content. DOMDocument decodes these to UTF-8 when extracting text, but
        // the raw HTML may still contain the entity form, causing str_replace to
        // miss. We generate entity-encoded variants of each original string.
        $entity_map = [
            "\u{2026}" => '&hellip;',  // …
            "\u{2019}" => '&#8217;',   // ' right single quote
            "\u{2018}" => '&#8216;',   // ' left single quote
            "\u{201C}" => '&#8220;',   // " left double quote
            "\u{201D}" => '&#8221;',   // " right double quote
            "\u{2013}" => '&#8211;',   // – en dash
            "\u{2014}" => '&#8212;',   // — em dash
            "\u{00A0}" => '&nbsp;',    // non-breaking space
        ];

        foreach ( $replacements as $original => $translated ) {
            if ( $original === $translated ) continue;

            // ── Strip Markdown formatting from cached translations ───────────
            // Gemini sometimes returns **Label:** prefixes or **bold** markers
            // despite prompt instructions. Clean them before applying.
            $translated = preg_replace( '/^\*{1,2}[^*]+:\*{1,2}\s*/', '', $translated );
            $translated = preg_replace( '/\*{1,2}([^*]+)\*{1,2}/', '$1', $translated );
            $translated = preg_replace( '/__([^_]+)__/', '$1', $translated );
            $translated = trim( $translated );

            // ── Preserve leading/trailing decorative symbols ──────────────────
            // Gemini often strips decorative Unicode symbols (✔, ✓, ★, ●, ▶, →,
            // ✦, ♦, etc.) that appear at the start or end of text nodes.
            // If the original has them but the translation doesn't, we re-attach
            // them so the visual presentation stays intact.
            $leading  = '';
            $trailing = '';
            if ( preg_match( '/^(\s*[\x{2000}-\x{27FF}\x{2900}-\x{2BFF}\x{FE00}-\x{FEFF}\x{1F300}-\x{1F9FF}]+\s*)/u', $original, $lm ) ) {
                $leading = $lm[1];
                // Check if translation already starts with the same symbols
                if ( mb_strpos( $translated, trim( $leading ) ) !== 0 ) {
                    $translated = $leading . $translated;
                }
            }
            if ( preg_match( '/(\s*[\x{2000}-\x{27FF}\x{2900}-\x{2BFF}\x{FE00}-\x{FEFF}\x{1F300}-\x{1F9FF}]+\s*)$/u', $original, $tm ) ) {
                $trailing = $tm[1];
                $trimmed_trailing = trim( $trailing );
                if ( mb_strrpos( $translated, $trimmed_trailing ) !== mb_strlen( $translated ) - mb_strlen( $trimmed_trailing ) ) {
                    $translated = $translated . $trailing;
                }
            }

            // Pass 1: direct UTF-8 match
            $html = str_replace( $original, $translated, $html );

            // Pass 2: htmlspecialchars-encoded match (covers &amp; &lt; &gt; &quot; &#039;)
            $enc_orig  = htmlspecialchars( $original,   ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $enc_trans = htmlspecialchars( $translated,  ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            if ( $enc_orig !== $original ) {
                $html = str_replace( $enc_orig, $enc_trans, $html );
            }

            // Pass 3: HTML entity-encoded variants for smart quotes, ellipsis, dashes etc.
            // Only run if the original contains any of the mapped characters.
            $entity_orig = strtr( $original, $entity_map );
            if ( $entity_orig !== $original ) {
                $entity_trans = strtr( $translated, $entity_map );
                $html = str_replace( $entity_orig, $entity_trans, $html );
            }
        }

        // ── Step 2b: precise title replacement ────────────────────────────────
        // The <title> tag was tokenised in step 1 (Category N) to prevent
        // global str_replace from cross-contaminating it with description
        // or other overlapping translations. Now we do a targeted replacement
        // ONLY for the exact title text inside the tokenised block.
        if ( ! empty( $title_token_map ) ) {
            foreach ( $title_token_map as $token => $info ) {
                $title_html  = $info['full'];
                $title_inner = $info['inner'];

                // Find the translation for this exact title text.
                // DOMDocument's textContent decodes HTML entities, so the
                // replacements key is UTF-8 while $title_inner may contain
                // entities like &#8211; or &ndash;. Try both forms.
                $decoded_inner = html_entity_decode( $title_inner, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $lookup_key    = isset( $replacements[ $title_inner ] ) ? $title_inner : ( isset( $replacements[ $decoded_inner ] ) ? $decoded_inner : null );

                if ( $lookup_key !== null && $replacements[ $lookup_key ] !== $lookup_key ) {
                    $translated_title = $replacements[ $lookup_key ];

                    // Clean Markdown formatting (same as main loop)
                    $translated_title = preg_replace( '/^\*{1,2}[^*]+:\*{1,2}\s*/', '', $translated_title );
                    $translated_title = preg_replace( '/\*{1,2}([^*]+)\*{1,2}/', '$1', $translated_title );
                    $translated_title = preg_replace( '/__([^_]+)__/', '$1', $translated_title );
                    $translated_title = trim( $translated_title );

                    // Safety: strip "Description: ..." suffix that Gemini sometimes
                    // appends when it merges title and description translations.
                    // Pattern: "Translated TitleDescription: some description text"
                    // or "Translated Title - Description: some description text"
                    $translated_title = preg_replace( '/\s*[-–—]?\s*Description\s*:\s*.+$/si', '', $translated_title );
                    $translated_title = trim( $translated_title );

                    // Replace the inner text within the <title> tag.
                    // Use $title_inner (raw HTML form) as the search string.
                    $title_html = str_replace( $title_inner, $translated_title, $title_html );
                }

                // Update the token's restoration value with the translated title
                $url_tokens[ $token ] = $title_html;
            }
        }

        // ── Step 3: restore URL tokens ────────────────────────────────────────
        if ( ! empty( $url_tokens ) ) {
            $html = str_replace(
                array_keys( $url_tokens ),
                array_values( $url_tokens ),
                $html
            );
        }

        return $html;
    }

    public function verify_brand_protection( $original, $translated ) {
        $orig_lower  = mb_strtolower( $original );
        $trans_lower = mb_strtolower( $translated );
        foreach ( $this->protected_terms as $term ) {
            $term_lower = mb_strtolower( $term );
            if ( mb_strpos( $orig_lower, $term_lower ) === false ) continue;
            if ( mb_strpos( $trans_lower, $term_lower ) === false ) {
                error_log( 'GML: Brand term "' . $term . '" missing from translation' );
                return false;
            }
        }
        return true;
    }

    private function walk( DOMNode $node, array &$nodes ) {
        if ( $node->nodeType === XML_ELEMENT_NODE ) {
            $tag = strtolower( $node->nodeName );
            if ( in_array( $tag, $this->skip_tags, true ) ) return;
            if ( $this->is_excluded( $node ) ) return;
            if ( $tag === 'meta' ) { $this->extract_meta( $node, $nodes ); return; }
            // Translate <title> as SEO meta (affects browser tab + search snippet)
            if ( $tag === 'title' ) { $this->extract_title( $node, $nodes ); return; }

            foreach ( $this->text_attrs as $attr ) {
                if ( $node->hasAttribute( $attr ) ) {
                    $val = trim( $node->getAttribute( $attr ) );
                    if ( $val !== '' && ! $this->skip_text( $val ) ) {
                        $nodes[] = [ 'type' => 'attribute', 'attr' => $attr, 'text' => $val, 'hash' => md5( $val ), 'context_type' => 'attribute' ];
                    }
                }
            }
        }

        if ( $node->nodeType === XML_TEXT_NODE ) {
            $text = trim( $node->nodeValue );
            if ( $text !== '' && ! $this->skip_text( $text ) ) {
                $nodes[] = [ 'type' => 'text', 'text' => $text, 'hash' => md5( $text ), 'context_type' => 'text' ];
            }
            return;
        }

        if ( $node->hasChildNodes() ) {
            foreach ( iterator_to_array( $node->childNodes ) as $child ) {
                $this->walk( $child, $nodes );
            }
        }
    }

    private function extract_meta( DOMNode $node, array &$nodes ) {
        $key = $node->getAttribute( 'name' ) ?: $node->getAttribute( 'property' );
        if ( ! in_array( $key, $this->seo_meta_names, true ) ) return;
        $content = trim( $node->getAttribute( 'content' ) );
        if ( $content === '' || $this->skip_text( $content ) ) return;
        // Title-type metas use seo_title context to batch separately from descriptions
        $title_metas = [ 'title', 'og:title', 'twitter:title' ];
        $ctx = in_array( $key, $title_metas, true ) ? 'seo_title' : 'seo_meta';
        $nodes[] = [ 'type' => 'attribute', 'attr' => 'content', 'text' => $content, 'hash' => md5( $content ), 'context_type' => $ctx ];
    }

    /**
     * Extract <title> text as SEO title so it gets translated with the SEO prompt
     * and appears correctly in browser tabs and search engine snippets.
     *
     * Uses context_type 'seo_title' (distinct from 'seo_meta' used by description)
     * so that title and description are NEVER batched together in the same API call.
     * Gemini sometimes merges adjacent batch items when they are semantically related
     * (e.g. title + description of the same page), producing corrupted translations.
     */
    private function extract_title( DOMNode $node, array &$nodes ) {
        $text = trim( $node->textContent );
        if ( $text === '' || $this->skip_text( $text ) ) return;
        $nodes[] = [ 'type' => 'text', 'text' => $text, 'hash' => md5( $text ), 'context_type' => 'seo_title' ];
    }

    private function is_excluded( DOMNode $node ) {
        if ( $node->hasAttribute( 'translate' ) && $node->getAttribute( 'translate' ) === 'no' ) return true;
        if ( $node->hasAttribute( 'class' ) ) {
            if ( in_array( 'notranslate', preg_split( '/\s+/', $node->getAttribute( 'class' ) ), true ) ) return true;
        }
        // Check default exclude selectors
        foreach ( get_option( 'gml_exclude_selectors', [] ) as $sel ) {
            if ( $this->matches_simple_selector( $node, $sel ) ) return true;
        }
        // Check exclusion rules (CSS selector type)
        if ( class_exists( 'GML_Exclusion_Rules' ) ) {
            $exclusion = new GML_Exclusion_Rules();
            foreach ( $exclusion->get_excluded_selectors() as $sel ) {
                if ( $this->matches_simple_selector( $node, $sel ) ) return true;
            }
        }
        return false;
    }

    private function matches_simple_selector( DOMNode $node, $selector ) {
        $selector = trim( $selector );
        if ( $selector === '' ) return false;
        if ( $selector[0] === '.' ) {
            return $node->hasAttribute( 'class' ) && in_array( substr( $selector, 1 ), preg_split( '/\s+/', $node->getAttribute( 'class' ) ), true );
        }
        if ( $selector[0] === '#' ) {
            return $node->hasAttribute( 'id' ) && $node->getAttribute( 'id' ) === substr( $selector, 1 );
        }
        return false;
    }

    private function skip_text( $text ) {
        if ( is_numeric( $text ) ) return true;
        if ( mb_strlen( $text ) < 2 ) return true;
        if ( filter_var( $text, FILTER_VALIDATE_URL ) ) return true;
        if ( filter_var( $text, FILTER_VALIDATE_EMAIL ) ) return true;
        // Pure numbers, phone-like strings, prices ($29.99), percentages (100%)
        if ( preg_match( '/^[\d\s\-\+\(\)\.]+$/', $text ) ) return true;
        // Price patterns: $29.99, €100, ¥500, £9.99
        if ( preg_match( '/^[\$€£¥₩₹][\d,\.]+$/', $text ) ) return true;
        // Percentage: 99%, 100.5%
        if ( preg_match( '/^\d+(\.\d+)?%$/', $text ) ) return true;
        // CSS color codes: #fff, #FF0000
        if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $text ) ) return true;
        // CSS/unit values: 16px, 1.5em, 100vh
        if ( preg_match( '/^\d+(\.\d+)?(px|em|rem|vh|vw|pt|cm|mm|%)$/', $text ) ) return true;
        $lower = strtolower( trim( $text ) );
        foreach ( $this->protected_terms as $term ) {
            if ( strtolower( $term ) === $lower ) return true;
        }
        return false;
    }
}
