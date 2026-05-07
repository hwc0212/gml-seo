<?php
/**
 * GML Translator - Translation engine with hash-based deduplication
 *
 * @package GML_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


class GML_Translator {

    private $index_table = 'gml_index';
    private $queue_table = 'gml_queue';

    /**
     * In-memory translation dictionary, shared across all translate() calls
     * within the same PHP request. Keyed by target_lang, each entry is a
     * hash → translated_text map.
     *
     * This eliminates redundant DB queries when multiple pages or components
     * are translated in the same request (e.g. output buffer + AJAX).
     *
     * @var array<string, array<string, string>>
     */
    private static $memory_cache = [];

    /**
     * Whether the full translation dictionary for a given language has been
     * preloaded from the object cache / DB into $memory_cache.
     *
     * @var array<string, bool>
     */
    private static $dict_loaded = [];

    /**
     * Translate parsed HTML nodes for a target language.
     *
     * v2.5.4 — Bulk-optimised:
     *  1. Deduplicate nodes by hash (a shop page may have "Add to Cart" 30×)
     *  2. Single SQL query to fetch all cached translations
     *  3. Single SQL query to check which hashes are already queued
     *  4. Batch INSERT for new queue items
     *
     * v2.8.1 — Translation dictionary preloading:
     *  - On first call for a language, loads the ENTIRE translation dictionary
     *    into memory (typically 500–5000 entries, ~200KB–2MB).
     *  - Subsequent calls skip DB entirely — pure in-memory hash lookup.
     *  - Uses WordPress object cache (Redis/Memcached if available) as L2 cache.
     *  - Template/plugin common texts ("Add to Cart", "Related Products", etc.)
     *    are resolved instantly without per-page DB queries.
     *
     * @param array  $parsed      Output of GML_HTML_Parser::parse()
     * @param string $target_lang Target language code
     * @return array $parsed with 'replacements' key added
     */
    public function translate( $parsed, $target_lang ) {
        global $wpdb;

        $source_lang  = get_option( 'gml_source_lang', 'en' );
        $nodes        = $parsed['nodes'];
        $replacements = [];

        if ( empty( $nodes ) ) {
            $parsed['replacements'] = $replacements;
            return $parsed;
        }

        // ── 0. Preload translation dictionary ────────────────────────────────
        // Load the full hash→translation map for this language pair once.
        // All subsequent lookups are pure PHP array access — zero DB queries.
        $this->maybe_preload_dictionary( $source_lang, $target_lang );

        // ── 1. Deduplicate by hash ───────────────────────────────────────────
        // Build a map: hash → { text, context_type }
        // If the same hash appears with different context_types, keep the first.
        $unique = []; // hash => [ 'text' => ..., 'context_type' => ... ]
        foreach ( $nodes as $item ) {
            $hash = $item['hash'];
            if ( ! isset( $unique[ $hash ] ) ) {
                $unique[ $hash ] = [
                    'text'         => $item['text'],
                    'context_type' => $item['context_type'] ?? 'text',
                ];
            }
        }

        // ── 2. Resolve from preloaded dictionary ─────────────────────────────
        $uncached = []; // hash => [ 'text' => ..., 'context_type' => ... ]
        $dict     = &self::$memory_cache[ $target_lang ];

        foreach ( $unique as $hash => $info ) {
            if ( isset( $dict[ $hash ] ) ) {
                $replacements[ $info['text'] ] = $dict[ $hash ];
            } else {
                $uncached[ $hash ] = $info;
            }
        }

        // ── 3. Queue uncached texts for async translation ────────────────────
        if ( ! empty( $uncached ) ) {
            $queue_table     = $wpdb->prefix . $this->queue_table;
            $uncached_hashes = array_keys( $uncached );
            $already_queued  = [];

            foreach ( array_chunk( $uncached_hashes, 500 ) as $chunk ) {
                $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
                $params       = array_merge( $chunk, [ $source_lang, $target_lang ] );
                $rows         = $wpdb->get_results( $wpdb->prepare(
                    "SELECT source_hash
                     FROM $queue_table
                     WHERE source_hash IN ($placeholders)
                       AND source_lang = %s AND target_lang = %s
                       AND status IN ('pending','processing','completed')",
                    $params
                ) );
                foreach ( $rows as $row ) {
                    $already_queued[ $row->source_hash ] = true;
                }
            }

            $now = current_time( 'mysql' );
            foreach ( $uncached as $hash => $info ) {
                if ( isset( $already_queued[ $hash ] ) ) {
                    continue;
                }
                $wpdb->insert( $queue_table, [
                    'source_hash'  => $hash,
                    'source_text'  => $info['text'],
                    'source_lang'  => $source_lang,
                    'target_lang'  => $target_lang,
                    'context_type' => $info['context_type'],
                    'priority'     => $this->calculate_priority( $info['text'], $info['context_type'] ),
                    'status'       => 'pending',
                    'attempts'     => 0,
                    'created_at'   => $now,
                ] );
            }
        }

        $parsed['replacements'] = $replacements;
        return $parsed;
    }

    /**
     * Preload the full translation dictionary for a language pair.
     *
     * Strategy (3-tier cache):
     *  L1: static $memory_cache — survives the entire PHP request (zero cost)
     *  L2: wp_cache (object cache) — survives across requests if Redis/Memcached
     *      is configured (sub-millisecond). Falls back to in-process array cache
     *      on vanilla WordPress (same as L1, but still useful for the key check).
     *  L3: MySQL — single SELECT loads the entire dictionary.
     *
     * The dictionary is typically 500–5000 rows × ~100 bytes = 50KB–500KB.
     * Even a large site with 10,000 translations × 5 languages = 50K rows
     * would only be ~5MB total across all languages, well within memory limits.
     *
     * @param string $source_lang Source language code
     * @param string $target_lang Target language code
     */
    private function maybe_preload_dictionary( $source_lang, $target_lang ) {
        // Already loaded in this request?
        if ( ! empty( self::$dict_loaded[ $target_lang ] ) ) {
            return;
        }

        // Try L2: WordPress object cache
        $cache_key   = "gml_dict_{$source_lang}_{$target_lang}";
        $cache_group = 'gml_translate';
        $cached      = wp_cache_get( $cache_key, $cache_group );

        if ( is_array( $cached ) ) {
            self::$memory_cache[ $target_lang ] = $cached;
            self::$dict_loaded[ $target_lang ]  = true;
            return;
        }

        // L3: Load from MySQL
        global $wpdb;
        $index_table = $wpdb->prefix . $this->index_table;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT source_hash, translated_text
             FROM $index_table
             WHERE source_lang = %s AND target_lang = %s
               AND status IN ('auto','manual')",
            $source_lang, $target_lang
        ) );

        $dict = [];
        foreach ( $rows as $row ) {
            $translated = $row->translated_text;
            if ( strpos( $translated, '<' ) !== false ) {
                $translated = wp_strip_all_tags( $translated );
            }
            $dict[ $row->source_hash ] = $translated;
        }

        self::$memory_cache[ $target_lang ] = $dict;
        self::$dict_loaded[ $target_lang ]  = true;

        // Store in L2 object cache (5 min TTL — invalidated on new translations)
        wp_cache_set( $cache_key, $dict, $cache_group, 300 );
    }

    /**
     * Invalidate the translation dictionary cache for a language pair.
     * Called after new translations are saved (by queue processor).
     *
     * @param string $source_lang Source language code
     * @param string $target_lang Target language code
     */
    public static function invalidate_cache( $source_lang, $target_lang ) {
        $cache_key   = "gml_dict_{$source_lang}_{$target_lang}";
        $cache_group = 'gml_translate';
        wp_cache_delete( $cache_key, $cache_group );
        unset( self::$memory_cache[ $target_lang ] );
        unset( self::$dict_loaded[ $target_lang ] );
    }

    /**
     * Get the preloaded translation dictionary for a language.
     *
     * Returns the hash → translated_text map. If the dictionary hasn't been
     * loaded yet, triggers a preload from cache/DB first.
     *
     * Used by GML_Gettext_Filter to translate WordPress i18n strings at runtime.
     *
     * @param string $target_lang Target language code
     * @return array<string, string> hash => translated_text
     */
    public function get_dictionary( $target_lang ) {
        $source_lang = get_option( 'gml_source_lang', 'en' );
        $this->maybe_preload_dictionary( $source_lang, $target_lang );
        return self::$memory_cache[ $target_lang ] ?? [];
    }

    // ── Index (translation memory) ────────────────────────────────────────────

    /**
     * Save a translation to the index table.
     * Manual translations are never overwritten by auto translations.
     * Also updates the in-memory and object cache dictionaries.
     */
    public function save_to_index( $hash, $source_text, $translated_text, $source_lang, $target_lang, $context_type = 'text', $status = 'auto' ) {
        global $wpdb;
        $table = $wpdb->prefix . $this->index_table;

        // Never overwrite a manual translation with an auto one
        if ( $status === 'auto' ) {
            $existing_status = $wpdb->get_var( $wpdb->prepare(
                "SELECT status FROM $table WHERE source_hash = %s AND source_lang = %s AND target_lang = %s",
                $hash, $source_lang, $target_lang
            ) );
            if ( $existing_status === 'manual' ) {
                return;
            }
        }

        $wpdb->replace( $table, [
            'source_hash'     => $hash,
            'source_text'     => $source_text,
            'source_lang'     => $source_lang,
            'target_lang'     => $target_lang,
            'translated_text' => $translated_text,
            'context_type'    => $context_type,
            'status'          => $status,
            'created_at'      => current_time( 'mysql' ),
            'updated_at'      => current_time( 'mysql' ),
        ] );

        // Update in-memory cache so subsequent calls in the same request
        // (e.g. queue processor processing multiple items) see the new value.
        if ( isset( self::$memory_cache[ $target_lang ] ) ) {
            $clean = $translated_text;
            if ( strpos( $clean, '<' ) !== false ) {
                $clean = wp_strip_all_tags( $clean );
            }
            self::$memory_cache[ $target_lang ][ $hash ] = $clean;
        }

        // Invalidate L2 object cache so the next request picks up the new entry.
        // We don't rebuild the full cache here (queue processor may save 30 items
        // in a row), just delete it — the next frontend request will rebuild.
        $cache_key   = "gml_dict_{$source_lang}_{$target_lang}";
        wp_cache_delete( $cache_key, 'gml_translate' );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function calculate_priority( $text, $context_type ) {
        if ( $context_type === 'seo_title' ) return 10;
        if ( $context_type === 'seo_meta' )  return 10;
        if ( $context_type === 'attribute' ) return 8;
        $len = mb_strlen( $text );
        if ( $len < 50 )  return 7;
        if ( $len < 200 ) return 5;
        return 3;
    }
}

