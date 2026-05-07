<?php
/**
 * GML Language Detector — Automatic user language detection & redirect
 *
 * Detects the visitor's preferred language from:
 *  1. Cookie (returning visitor preference)
 *  2. Browser Accept-Language header
 *
 * On first visit to the homepage (no language prefix), redirects to the
 * best matching enabled language. Stores preference in a cookie so
 * subsequent visits respect the user's choice.
 *
 * @package GML_Translate
 * @since 2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Language_Detector {

    const COOKIE_NAME   = 'gml_preferred_lang';
    const COOKIE_EXPIRY = 365 * DAY_IN_SECONDS;

    /** @var bool Whether auto-redirect is enabled */
    private $auto_redirect;

    /** @var string[] Enabled target language codes */
    private $enabled_langs = [];

    /** @var string Source language code */
    private $source_lang;

    public function __construct() {
        $this->auto_redirect = (bool) get_option( 'gml_auto_detect_language', false );
        $this->source_lang   = get_option( 'gml_source_lang', 'en' );
        $this->enabled_langs = $this->get_enabled_languages();

        // Set cookie when visiting a language-prefixed page
        add_action( 'template_redirect', [ $this, 'set_language_cookie' ], 0 );

        // Auto-redirect on first visit (before output buffer starts)
        if ( $this->auto_redirect ) {
            add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 0 );
        }
    }

    /**
     * Set a cookie recording the user's current language preference.
     * Fires on every page load so the cookie stays fresh.
     */
    public function set_language_cookie() {
        $current_lang = $this->get_url_language();
        if ( $current_lang ) {
            $this->set_cookie( $current_lang );
        }
    }

    /**
     * Redirect first-time visitors to their preferred language.
     *
     * Only redirects if:
     *  - Request is the homepage (no language prefix, path is /)
     *  - No gml_preferred_lang cookie exists (first visit)
     *  - Detected language differs from source language
     *  - Detected language is an enabled target language
     *  - Not a bot/crawler (they should see the canonical source page)
     */
    public function maybe_redirect() {
        // Only redirect on homepage without language prefix
        $path = strtok( $_SERVER['REQUEST_URI'] ?? '/', '?' );
        $path = rtrim( $path, '/' );
        if ( $path !== '' && $path !== '/' ) {
            return;
        }

        // Don't redirect if cookie already set (returning visitor made a choice)
        if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            return;
        }

        // Don't redirect bots
        if ( $this->is_bot() ) {
            return;
        }

        // Don't redirect admin/ajax/cron
        if ( is_admin() || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
            return;
        }

        $detected = $this->detect_browser_language();
        if ( ! $detected || $detected === $this->source_lang ) {
            // Set cookie to source lang so we don't re-detect next time
            $this->set_cookie( $this->source_lang );
            return;
        }

        // Redirect to detected language homepage
        $this->set_cookie( $detected );
        $redirect_url = home_url( '/' . $detected . '/' );
        wp_redirect( $redirect_url, 302 );
        exit;
    }

    /**
     * Detect the best matching language from the Accept-Language header.
     *
     * Parses the header (e.g. "zh-CN,zh;q=0.9,en;q=0.8") and returns
     * the highest-priority language code that matches an enabled language.
     *
     * @return string|null Language code or null if no match
     */
    public function detect_browser_language() {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if ( empty( $header ) ) {
            return null;
        }

        $languages = $this->parse_accept_language( $header );

        foreach ( $languages as $lang_code => $quality ) {
            // Try exact match first (e.g. "zh" matches "zh")
            if ( in_array( $lang_code, $this->enabled_langs, true ) ) {
                return $lang_code;
            }
            // Try base language (e.g. "zh-CN" → "zh")
            $base = substr( $lang_code, 0, 2 );
            if ( $base !== $lang_code && in_array( $base, $this->enabled_langs, true ) ) {
                return $base;
            }
        }

        return null;
    }

    /**
     * Parse Accept-Language header into sorted array.
     *
     * @param string $header Raw Accept-Language header value
     * @return array [ lang_code => quality ] sorted by quality descending
     */
    private function parse_accept_language( $header ) {
        $languages = [];
        $parts = explode( ',', $header );

        foreach ( $parts as $part ) {
            $part = trim( $part );
            if ( empty( $part ) ) continue;

            $segments = explode( ';', $part );
            $code     = strtolower( trim( $segments[0] ) );
            $quality  = 1.0;

            if ( isset( $segments[1] ) && preg_match( '/q\s*=\s*([\d.]+)/', $segments[1], $m ) ) {
                $quality = (float) $m[1];
            }

            $languages[ $code ] = $quality;
        }

        arsort( $languages );
        return $languages;
    }

    /**
     * Get the current language from URL prefix.
     */
    private function get_url_language() {
        $path = strtok( $_SERVER['REQUEST_URI'] ?? '', '?' );
        if ( preg_match( '#^/([a-z]{2})(/|$)#', $path, $m ) ) {
            if ( in_array( $m[1], $this->enabled_langs, true ) ) {
                return $m[1];
            }
        }
        return null;
    }

    private function set_cookie( $lang ) {
        if ( headers_sent() ) return;
        setcookie( self::COOKIE_NAME, $lang, time() + self::COOKIE_EXPIRY, '/', '', is_ssl(), false );
    }

    private function get_enabled_languages() {
        $codes = [];
        foreach ( get_option( 'gml_languages', [] ) as $lang ) {
            if ( $lang['enabled'] ?? true ) {
                $codes[] = $lang['code'];
            }
        }
        return $codes;
    }

    /**
     * Basic bot detection via User-Agent.
     */
    private function is_bot() {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $bots = [ 'googlebot', 'bingbot', 'yandexbot', 'baiduspider', 'facebookexternalhit',
                  'twitterbot', 'rogerbot', 'linkedinbot', 'embedly', 'slurp', 'duckduckbot',
                  'semrushbot', 'ahrefsbot', 'mj12bot', 'dotbot', 'petalbot', 'bytespider' ];
        $ua_lower = strtolower( $ua );
        foreach ( $bots as $bot ) {
            if ( strpos( $ua_lower, $bot ) !== false ) {
                return true;
            }
        }
        return false;
    }
}
