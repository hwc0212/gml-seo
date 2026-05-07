<?php
/**
 * GML SEO Hreflang Tags Injection
 *
 * @package GML_Translate
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GML SEO Hreflang class
 */
class GML_Translate_Hreflang {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_head', [$this, 'inject_hreflang'], 1);
        // Fix <html lang=""> attribute for translated pages
        add_filter('language_attributes', [$this, 'filter_language_attributes'], 10, 2);
    }

    /**
     * Override the lang= attribute on <html> for language-prefixed pages.
     * Without this, the HTML always says lang="en" (or whatever the WP locale is)
     * even when serving /ru/ or /es/ — bad for SEO and screen readers.
     */
    public function filter_language_attributes( $output, $doctype ) {
        $current_lang = $this->get_current_lang();
        $source_lang  = get_option( 'gml_source_lang', 'en' );
        if ( $current_lang && $current_lang !== $source_lang ) {
            // Replace the lang="xx" value with the target language
            $output = preg_replace( '/\blang="[^"]*"/', 'lang="' . esc_attr( $current_lang ) . '"', $output );
        }
        return $output;
    }

    /**
     * Get the language code for the current request from URL prefix.
     */
    private function get_current_lang() {
        $path = strtok( $_SERVER['REQUEST_URI'] ?? '/', '?' );
        if ( preg_match( '#^/([a-z]{2})(/|$)#', $path, $m ) ) {
            return $m[1];
        }
        return get_option( 'gml_source_lang', 'en' );
    }
    
    /**
     * Inject hreflang tags
     */
    public function inject_hreflang() {
        // Skip admin pages
        if (is_admin()) {
            return;
        }
        
        // Get current URL
        $current_url = $this->get_current_url();
        
        // Get source language
        $wp_locale = get_locale();
        $default_lang = substr($wp_locale, 0, 2);
        $source_lang = get_option('gml_source_lang', $default_lang);

        // Detect current language from URL prefix
        $current_lang = $this->get_current_lang();
        
        // Get configured languages (new structure)
        $languages_config = get_option('gml_languages', []);
        $languages = [$source_lang]; // Start with source language
        foreach ($languages_config as $lang) {
            if (($lang['enabled'] ?? true) && $lang['code'] !== $source_lang) {
                $languages[] = $lang['code'];
            }
        }
        
        // x-default (default language)
        $default_url = $this->get_language_url($current_url, $source_lang);
        echo '<link rel="alternate" hreflang="x-default" href="' . esc_url($default_url) . '" />' . "\n";
        
        // All language versions
        foreach ($languages as $lang) {
            $translated_url = $this->get_language_url($current_url, $lang);
            $hreflang = $this->get_hreflang_code($lang);
            echo '<link rel="alternate" hreflang="' . esc_attr($hreflang) . '" href="' . esc_url($translated_url) . '" />' . "\n";
        }

        // ── og:locale / og:locale:alternate ──────────────────────────────────
        // Helps social platforms (Facebook, LinkedIn) understand the language
        // of the current page and its alternates.
        $og_locale_map = [
            'en' => 'en_US', 'zh' => 'zh_CN', 'ja' => 'ja_JP', 'fr' => 'fr_FR',
            'de' => 'de_DE', 'es' => 'es_ES', 'pt' => 'pt_PT', 'ru' => 'ru_RU',
            'ko' => 'ko_KR', 'ar' => 'ar_SA', 'it' => 'it_IT', 'nl' => 'nl_NL',
            'pl' => 'pl_PL', 'tr' => 'tr_TR', 'vi' => 'vi_VN', 'th' => 'th_TH',
            'id' => 'id_ID', 'ms' => 'ms_MY', 'tl' => 'tl_PH', 'sv' => 'sv_SE',
            'da' => 'da_DK', 'nb' => 'nb_NO', 'fi' => 'fi_FI', 'cs' => 'cs_CZ',
            'sk' => 'sk_SK', 'hu' => 'hu_HU', 'ro' => 'ro_RO', 'bg' => 'bg_BG',
            'hr' => 'hr_HR', 'sr' => 'sr_RS', 'sl' => 'sl_SI', 'uk' => 'uk_UA',
            'el' => 'el_GR', 'he' => 'he_IL', 'lt' => 'lt_LT', 'lv' => 'lv_LV',
            'et' => 'et_EE', 'ca' => 'ca_ES', 'fa' => 'fa_IR', 'ur' => 'ur_PK',
            'bn' => 'bn_BD', 'hi' => 'hi_IN', 'ta' => 'ta_IN', 'te' => 'te_IN',
            'sw' => 'sw_KE', 'af' => 'af_ZA', 'ka' => 'ka_GE', 'hy' => 'hy_AM',
            'az' => 'az_AZ', 'kk' => 'kk_KZ', 'uz' => 'uz_UZ', 'mn' => 'mn_MN',
            'km' => 'km_KH', 'my' => 'my_MM', 'lo' => 'lo_LA', 'ne' => 'ne_NP',
        ];
        $current_og_locale = $og_locale_map[$current_lang] ?? ( $current_lang . '_' . strtoupper($current_lang) );
        echo '<meta property="og:locale" content="' . esc_attr($current_og_locale) . '" />' . "\n";
        foreach ($languages as $lang) {
            if ($lang === $current_lang) continue;
            $alt_og = $og_locale_map[$lang] ?? ( $lang . '_' . strtoupper($lang) );
            echo '<meta property="og:locale:alternate" content="' . esc_attr($alt_og) . '" />' . "\n";
        }
        
        // Canonical URL — only output on language-prefixed pages (non-source).
        // On source-language pages WordPress itself outputs the canonical.
        $request_path = strtok( $_SERVER['REQUEST_URI'] ?? '/', '?' );
        $is_lang_page = false;
        $all_lang_codes = array_unique( array_merge( [$source_lang], array_column( get_option('gml_languages', []), 'code' ) ) );
        foreach ( $all_lang_codes as $lc ) {
            if ( $lc !== $source_lang && preg_match( '#^/' . preg_quote($lc, '#') . '(/|$)#', $request_path ) ) {
                $is_lang_page = true;
                break;
            }
        }
        if ( $is_lang_page ) {
            $canonical_url = $this->get_canonical_url($current_url);
            echo '<link rel="canonical" href="' . esc_url($canonical_url) . '" />' . "\n";
        }
    }
    
    /**
     * Get current URL — always returns the source-language equivalent URL
     * (no language prefix), so get_language_url() can add the right prefix.
     *
     * $wp->request on /ru/about/ returns "ru/about", which would cause
     * get_language_url() to produce /en/ru/about/ for the source lang.
     * We strip the language prefix here to get the canonical path.
     *
     * @return string
     */
    private function get_current_url() {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path        = strtok( $request_uri, '?' );

        // Strip any language prefix so we always work with the bare path
        $all_langs = array_unique( array_merge(
            [ get_option( 'gml_source_lang', 'en' ) ],
            array_column( get_option( 'gml_languages', [] ), 'code' )
        ) );
        if ( ! empty( $all_langs ) ) {
            $pat  = implode( '|', array_map( 'preg_quote', $all_langs ) );
            $path = preg_replace( '#^/(' . $pat . ')(/|$)#', '/', $path );
        }

        // Ensure trailing slash if permalink structure requires it
        if ( get_option( 'permalink_structure' ) && substr( $path, -1 ) !== '/' ) {
            $path .= '/';
        }

        return home_url( $path );
    }
    
    /**
     * Get language-specific URL
     *
     * @param string $url Base URL
     * @param string $lang Language code
     * @return string
     */
    private function get_language_url($url, $lang) {
        // Get source language
        $wp_locale = get_locale();
        $default_lang = substr($wp_locale, 0, 2);
        $source_lang = get_option('gml_source_lang', $default_lang);
        
        // Parse URL
        $parsed = parse_url($url);
        $path = isset($parsed['path']) ? $parsed['path'] : '/';
        
        // Get all configured languages
        $languages_config = get_option('gml_languages', []);
        $all_languages = [$source_lang];
        foreach ($languages_config as $lang_config) {
            if ($lang_config['enabled'] ?? true) {
                $all_languages[] = $lang_config['code'];
            }
        }
        
        // Remove existing language prefix
        $lang_pattern = implode('|', array_map('preg_quote', array_unique($all_languages)));
        $path = preg_replace('#^/(' . $lang_pattern . ')(/|$)#', '/', $path);
        
        // Add new language prefix (except for source language)
        if ($lang !== $source_lang) {
            $path = '/' . $lang . $path;
        }
        
        // Rebuild URL
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';
        $host = isset($parsed['host']) ? $parsed['host'] : $_SERVER['HTTP_HOST'];
        $new_url = $scheme . '://' . $host . $path;
        
        // Add query string if exists
        if (isset($parsed['query'])) {
            $new_url .= '?' . $parsed['query'];
        }
        
        return $new_url;
    }
    
    /**
     * Get canonical URL (remove language prefix)
     *
     * @param string $url Current URL
     * @return string
     */
    private function get_canonical_url($url) {
        $wp_locale = get_locale();
        $default_lang = substr($wp_locale, 0, 2);
        $source_lang = get_option('gml_source_lang', $default_lang);
        return $this->get_language_url($url, $source_lang);
    }
    
    /**
     * Get hreflang code from language code
     *
     * @param string $lang Language code (en, zh, ja, etc.)
     * @return string hreflang code (en, zh-CN, ja, etc.)
     */
    private function get_hreflang_code($lang) {
        $hreflang_map = [
            'zh' => 'zh-CN',
            'zh-cn' => 'zh-CN',
            'zh-tw' => 'zh-TW',
            'zh-hk' => 'zh-HK',
            'en' => 'en',
            'ja' => 'ja',
            'ko' => 'ko',
            'fr' => 'fr',
            'de' => 'de',
            'es' => 'es',
            'it' => 'it',
            'pt' => 'pt',
            'ru' => 'ru',
            'ar' => 'ar',
        ];
        
        return isset($hreflang_map[$lang]) ? $hreflang_map[$lang] : $lang;
    }
}
