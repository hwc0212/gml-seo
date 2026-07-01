<?php
/**
 * GML Gettext Filter — Runtime i18n string translation via WordPress gettext hooks
 *
 * WordPress core, themes, and plugins output translatable strings through the
 * i18n API: __(), _e(), _x(), _n(), esc_html__(), esc_attr__(), etc.
 * All of these ultimately call translate() in wp-includes/l10n.php, which
 * fires the 'gettext' and 'gettext_with_context' filter hooks.
 *
 * By hooking into these filters, we can translate strings at PHP runtime
 * BEFORE they reach the output buffer. This has several advantages:
 *
 * 1. Header/footer/sidebar/widget strings are translated immediately,
 *    without waiting for the full HTML to be parsed by DOMDocument.
 *
 * 2. Common template strings ("Add to Cart", "Search", "Read more",
 *    "Name", "Email", "Leave a comment", etc.) that appear on every
 *    page are translated once and cached in memory — zero overhead
 *    for subsequent occurrences.
 *
 * 3. Strings translated via gettext are ALREADY in the target language
 *    when the output buffer receives the HTML, so DOMDocument extracts
 *    fewer untranslated nodes, and str_replace has fewer replacements
 *    to make — reducing both CPU and memory usage.
 *
 * 4. Plugin/theme strings that are NOT in the post content (e.g.
 *    WooCommerce "Description", "Reviews", "Related products",
 *    breadcrumb labels, pagination "Previous"/"Next") are caught
 *    here even if the content crawler never saw them.
 *
 * The output buffer (DOMDocument + str_replace) remains as a safety net
 * for text that doesn't go through gettext: post content, page titles,
 * custom fields, Elementor/Gutenberg block output, etc.
 *
 * @package GML_Translate
 * @since 2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Gettext_Filter {

    /** @var string Target language code (e.g. 'ru', 'es') */
    private $target_lang = '';

    /** @var string Source language code */
    private $source_lang = '';

    /**
     * In-memory lookup: md5(text) => translated_text.
     * Populated from the GML translation dictionary on first use.
     * @var array<string, string>|null
     */
    private $dict = null;

    /**
     * Texts discovered via gettext that are NOT yet in the dictionary.
     * Queued for async translation by the queue processor.
     * @var array<string, array>  hash => [ 'text' => ..., 'context_type' => 'text' ]
     */
    private $pending = [];

    /**
     * Strings we've already seen and know are not in the dictionary.
     * Avoids re-computing md5 + is_translatable for the same string
     * when __() is called multiple times with the same text.
     * @var array<string, true>
     */
    private $miss_cache = [];

    /** @var bool Whether we've already flushed pending items on shutdown */
    private $flushed = false;

    public function __construct() {
        // Detect language early — before template_redirect (priority 1).
        // We hook into 'wp' which fires after query parsing but before template loading.
        add_action( 'wp', [ $this, 'maybe_activate' ], 1 );
    }

    /**
     * Activate gettext filtering if we're on a translated page.
     */
    public function maybe_activate() {
        // Skip admin, AJAX, REST, cron
        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return;
        }

        // Skip for logged-in users — the admin bar is rendered with gettext
        // strings from WordPress core and plugins (Elementor, WooCommerce, etc.).
        // These plugins may call __() during init/wp_loaded and cache the result,
        // so we cannot reliably suspend gettext only during admin bar rendering.
        // Logged-in users still get page content translated via the output buffer's
        // str_replace pipeline (which uses extract_no_translate_blocks to protect
        // the admin bar from str_replace damage).
        if ( is_user_logged_in() ) {
            return;
        }

        $this->source_lang = get_option( 'gml_source_lang', 'en' );
        $this->target_lang = $this->detect_language();

        if ( ! $this->target_lang || $this->target_lang === $this->source_lang ) {
            return;
        }

        if ( ! get_option( 'gml_translation_enabled', false ) ) {
            return;
        }

        // Hook into WordPress gettext filters.
        // Priority 10 is fine — we want to run after any plugin that modifies
        // the source string but before the string reaches the template.
        add_filter( 'gettext',              [ $this, 'filter_gettext' ],              10, 3 );
        add_filter( 'gettext_with_context', [ $this, 'filter_gettext_with_context' ], 10, 4 );
        add_filter( 'ngettext',             [ $this, 'filter_ngettext' ],             10, 5 );
        add_filter( 'ngettext_with_context',[ $this, 'filter_ngettext_with_context' ],10, 6 );

        // Flush pending texts to queue on shutdown (after output buffer).
        add_action( 'shutdown', [ $this, 'flush_pending' ], 0 );
    }

    // ── Gettext filter callbacks ─────────────────────────────────────────────

    /**
     * Filter for __() and _e().
     *
     * @param string $translation Translated text (from .mo file or original).
     * @param string $text        Original text.
     * @param string $domain      Text domain.
     * @return string GML-translated text or original.
     */
    public function filter_gettext( $translation, $text, $domain ) {
        return $this->translate_string( $translation );
    }

    /**
     * Filter for _x() and _ex().
     */
    public function filter_gettext_with_context( $translation, $text, $context, $domain ) {
        return $this->translate_string( $translation );
    }

    /**
     * Filter for _n().
     */
    public function filter_ngettext( $translation, $single, $plural, $number, $domain ) {
        return $this->translate_string( $translation );
    }

    /**
     * Filter for _nx().
     */
    public function filter_ngettext_with_context( $translation, $single, $plural, $number, $context, $domain ) {
        return $this->translate_string( $translation );
    }

    // ── Core translation logic ───────────────────────────────────────────────

    /**
     * Translate a single string using the GML dictionary.
     *
     * @param string $text The string to translate (already passed through WP's
     *                     own .mo translation, so it's in the WP locale language).
     * @return string Translated string or original.
     */
    private function translate_string( $text ) {
        // Skip empty, numeric, very short strings
        $trimmed = trim( $text );
        if ( $trimmed === '' || is_numeric( $trimmed ) || mb_strlen( $trimmed ) < 2 ) {
            return $text;
        }

        // Lazy-load dictionary on first call
        if ( $this->dict === null ) {
            $this->load_dictionary();
        }

        // Fast path: already known to be a miss — skip md5 + dict lookup + is_translatable
        // WordPress calls __() / _e() hundreds of times per page, and many strings
        // repeat (e.g. "Search", "Menu", "Close"). The miss cache avoids redundant
        // work for strings we've already checked and know aren't in the dictionary.
        if ( isset( $this->miss_cache[ $trimmed ] ) ) {
            return $text;
        }

        $hash = md5( $trimmed );

        // Check dictionary
        if ( isset( $this->dict[ $hash ] ) ) {
            return $this->dict[ $hash ];
        }

        // Not in dictionary — record as miss so we skip it on repeat calls.
        $this->miss_cache[ $trimmed ] = true;

        // Queue for async translation if it looks like real translatable content.
        if ( ! isset( $this->pending[ $hash ] ) && $this->is_translatable( $trimmed ) ) {
            $this->pending[ $hash ] = [
                'text'         => $trimmed,
                'context_type' => 'text',
            ];
        }

        return $text;
    }

    /**
     * Load the translation dictionary from GML_Translator's preloaded cache.
     */
    private function load_dictionary() {
        $translator = new GML_Translator();
        $this->dict = $translator->get_dictionary( $this->target_lang );
    }

    /**
     * Check if a string is worth translating (not a URL, not pure punctuation, etc.)
     */
    private function is_translatable( $text ) {
        // Skip URLs
        if ( filter_var( $text, FILTER_VALIDATE_URL ) ) {
            return false;
        }
        // Skip email addresses
        if ( filter_var( $text, FILTER_VALIDATE_EMAIL ) ) {
            return false;
        }
        // Skip pure numbers / phone-like
        if ( preg_match( '/^[\d\s\-\+\(\)\.]+$/', $text ) ) {
            return false;
        }
        // Skip prices
        if ( preg_match( '/^[\$€£¥₩₹][\d,\.]+$/', $text ) ) {
            return false;
        }
        // Skip CSS values
        if ( preg_match( '/^\d+(\.\d+)?(px|em|rem|vh|vw|pt|%)$/', $text ) ) {
            return false;
        }
        // Skip HTML tags only
        if ( wp_strip_all_tags( $text ) === '' ) {
            return false;
        }
        // Must contain at least one letter
        if ( ! preg_match( '/[a-zA-Z\x{00C0}-\x{024F}\x{4E00}-\x{9FFF}]/u', $text ) ) {
            return false;
        }
        return true;
    }

    /**
     * Flush pending (undiscovered) texts to the translation queue.
     * Runs on shutdown so it doesn't slow down page rendering.
     */
    public function flush_pending() {
        if ( $this->flushed || empty( $this->pending ) ) {
            return;
        }
        $this->flushed = true;

        global $wpdb;
        $queue_table = $wpdb->prefix . 'gml_queue';
        $source_lang = $this->source_lang;
        $target_lang = $this->target_lang;

        // Check which hashes are already in the queue
        $hashes      = array_keys( $this->pending );
        $already     = [];

        foreach ( array_chunk( $hashes, 500 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $params       = array_merge( $chunk, [ $source_lang, $target_lang ] );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT source_hash FROM $queue_table
                 WHERE source_hash IN ($placeholders)
                   AND source_lang = %s AND target_lang = %s
                   AND status IN ('pending','processing','completed')",
                $params
            ) );
            foreach ( $rows as $row ) {
                $already[ $row->source_hash ] = true;
            }
        }

        // Also check the index table (already translated)
        $index_table = $wpdb->prefix . 'gml_index';
        foreach ( array_chunk( $hashes, 500 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $params       = array_merge( $chunk, [ $source_lang, $target_lang ] );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT source_hash FROM $index_table
                 WHERE source_hash IN ($placeholders)
                   AND source_lang = %s AND target_lang = %s",
                $params
            ) );
            foreach ( $rows as $row ) {
                $already[ $row->source_hash ] = true;
            }
        }

        // Insert new items
        $now = current_time( 'mysql' );
        foreach ( $this->pending as $hash => $info ) {
            if ( isset( $already[ $hash ] ) ) {
                continue;
            }
            $wpdb->insert( $queue_table, [
                'source_hash'  => $hash,
                'source_text'  => $info['text'],
                'source_lang'  => $source_lang,
                'target_lang'  => $target_lang,
                'context_type' => 'text',
                'priority'     => 8, // template strings are high priority
                'status'       => 'pending',
                'attempts'     => 0,
                'created_at'   => $now,
            ] );
        }
    }

    // ── Language detection ────────────────────────────────────────────────────

    /**
     * Detect target language from URL prefix.
     * Same logic as GML_Output_Buffer::get_url_language().
     */
    private function detect_language() {
        $uri  = $_SERVER['REQUEST_URI'] ?? '';
        $path = strtok( $uri, '?' );
        if ( preg_match( '#^/([a-z]{2})(/|$)#', $path, $m ) ) {
            $lang = $m[1];
            $configured = get_option( 'gml_languages', [] );
            foreach ( $configured as $l ) {
                if ( ( $l['enabled'] ?? true ) && $l['code'] === $lang ) {
                    return $lang;
                }
            }
        }
        return null;
    }
}
