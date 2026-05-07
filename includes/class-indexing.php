<?php
/**
 * Real-time search engine indexing via IndexNow + Google Indexing API.
 *
 * When content changes (publish, update, delete), we immediately notify
 * search engines so they crawl the new/updated URL rather than waiting
 * for their next scheduled crawl. This can reduce indexing latency from
 * days to hours.
 *
 * Protocols supported:
 *  - IndexNow (Bing, Yandex, Seznam, Naver) — no auth needed, just a key
 *    file hosted on the site. We auto-generate the key and serve it.
 *  - Google Indexing API — requires a service account JSON. Officially
 *    limited to Job Posting / LiveStream schemas, but works on all URLs
 *    and is widely used by WP plugins.
 *
 * Both are non-blocking: we queue the URL and flush in a shutdown action
 * so post saves don't slow down.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_Indexing {

    const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';
    const GOOGLE_ENDPOINT   = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

    const KEY_OPTION  = 'gml_seo_indexnow_key';

    private $pending_urls = [];
    private $pending_deletes = [];

    public function __construct() {
        // Auto-generate IndexNow key on activation
        $this->ensure_indexnow_key();

        // Serve the key file at /{key}.txt (IndexNow verification)
        add_action( 'init', [ $this, 'register_key_endpoint' ] );
        add_action( 'template_redirect', [ $this, 'serve_key_file' ], 0 );

        // Queue URLs on post publish/update/delete
        add_action( 'transition_post_status', [ $this, 'on_status_change' ], 10, 3 );
        add_action( 'before_delete_post', [ $this, 'on_delete' ] );

        // Flush queue on shutdown (non-blocking)
        add_action( 'shutdown', [ $this, 'flush_queue' ] );

        // AJAX: manual resubmit
        add_action( 'wp_ajax_gml_seo_reindex', [ $this, 'ajax_reindex_post' ] );
    }

    // ══════════════════════════════════════════════════════════════════
    //  IndexNow Key Management
    // ══════════════════════════════════════════════════════════════════

    public function ensure_indexnow_key() {
        if ( ! get_option( self::KEY_OPTION ) ) {
            // 32-char hex string (recommended format by IndexNow spec)
            $key = bin2hex( random_bytes( 16 ) );
            update_option( self::KEY_OPTION, $key, false );
        }
    }

    public static function get_indexnow_key() {
        return get_option( self::KEY_OPTION, '' );
    }

    public function register_key_endpoint() {
        add_rewrite_rule(
            '^([a-f0-9]{32})\.txt$',
            'index.php?gml_indexnow_key=$matches[1]',
            'top'
        );
        add_rewrite_tag( '%gml_indexnow_key%', '([a-f0-9]{32})' );
    }

    public function serve_key_file() {
        $requested = get_query_var( 'gml_indexnow_key' );
        if ( ! $requested ) {
            // Fallback: parse URL directly (rewrite rules may not be flushed)
            $path = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
            if ( preg_match( '/^([a-f0-9]{32})\.txt$/', $path, $m ) ) {
                $requested = $m[1];
            }
        }
        if ( ! $requested ) return;

        $actual = self::get_indexnow_key();
        if ( $requested !== $actual ) {
            status_header( 404 );
            exit;
        }

        global $wp_query;
        if ( $wp_query ) $wp_query->is_404 = false;
        status_header( 200 );
        header( 'Content-Type: text/plain; charset=UTF-8' );
        echo $actual;
        exit;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Queue on Content Changes
    // ══════════════════════════════════════════════════════════════════

    public function on_status_change( $new, $old, $post ) {
        if ( wp_is_post_revision( $post->ID ) ) return;
        if ( ! in_array( $post->post_type, $this->indexable_types(), true ) ) return;

        $url = get_permalink( $post->ID );
        if ( ! $url ) return;

        // Newly published or updated
        if ( $new === 'publish' ) {
            $this->pending_urls[] = $url;
        }
        // Unpublished / trashed (signal removal)
        elseif ( $old === 'publish' && $new !== 'publish' ) {
            $this->pending_deletes[] = $url;
        }
    }

    public function on_delete( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) return;
        $url = get_permalink( $post_id );
        if ( $url ) $this->pending_deletes[] = $url;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Flush — batch notify search engines
    // ══════════════════════════════════════════════════════════════════

    public function flush_queue() {
        $this->pending_urls    = array_values( array_unique( $this->pending_urls ) );
        $this->pending_deletes = array_values( array_unique( $this->pending_deletes ) );

        if ( empty( $this->pending_urls ) && empty( $this->pending_deletes ) ) return;

        // IndexNow: update + delete use the same endpoint (Bing infers from URL)
        $all = array_merge( $this->pending_urls, $this->pending_deletes );
        if ( ! empty( $all ) ) {
            $this->submit_indexnow( $all );
        }

        // Google Indexing API (official URL_UPDATED / URL_DELETED)
        foreach ( $this->pending_urls as $u ) {
            $this->submit_google( $u, 'URL_UPDATED' );
        }
        foreach ( $this->pending_deletes as $u ) {
            $this->submit_google( $u, 'URL_DELETED' );
        }
    }

    // ── IndexNow ─────────────────────────────────────────────────────

    private function submit_indexnow( $urls ) {
        if ( ! GML_SEO::opt( 'indexnow_enabled', 1 ) ) return;

        $key = self::get_indexnow_key();
        if ( ! $key ) return;

        $host = parse_url( home_url(), PHP_URL_HOST );
        $body = [
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => home_url( '/' . $key . '.txt' ),
            'urlList'     => array_values( array_unique( $urls ) ),
        ];

        wp_remote_post( self::INDEXNOW_ENDPOINT, [
            'timeout'  => 10,
            'blocking' => false, // fire-and-forget
            'headers'  => [ 'Content-Type' => 'application/json; charset=utf-8' ],
            'body'     => wp_json_encode( $body ),
        ] );
    }

    // ── Google Indexing API ──────────────────────────────────────────

    private function submit_google( $url, $type = 'URL_UPDATED' ) {
        $token = $this->get_google_access_token();
        if ( ! $token ) return;

        wp_remote_post( self::GOOGLE_ENDPOINT, [
            'timeout'  => 10,
            'blocking' => false,
            'headers'  => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'url'  => $url,
                'type' => $type,
            ] ),
        ] );
    }

    /**
     * Get Google OAuth2 access token from stored service account JSON.
     * Token is cached for 45 minutes (valid for 60).
     */
    private function get_google_access_token() {
        $json = GML_SEO::opt( 'google_service_account', '' );
        if ( ! $json ) return null;

        $cached = get_transient( 'gml_seo_gsa_token' );
        if ( $cached ) return $cached;

        $sa = json_decode( $json, true );
        if ( ! is_array( $sa ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
            return null;
        }

        // Build JWT
        $header = [ 'alg' => 'RS256', 'typ' => 'JWT' ];
        $now    = time();
        $claim  = [
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/indexing',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ];

        $h64 = rtrim( strtr( base64_encode( wp_json_encode( $header ) ), '+/', '-_' ), '=' );
        $c64 = rtrim( strtr( base64_encode( wp_json_encode( $claim ) ), '+/', '-_' ), '=' );
        $sig = '';
        $ok  = openssl_sign( $h64 . '.' . $c64, $sig, $sa['private_key'], 'SHA256' );
        if ( ! $ok ) return null;
        $s64 = rtrim( strtr( base64_encode( $sig ), '+/', '-_' ), '=' );
        $jwt = "{$h64}.{$c64}.{$s64}";

        $res = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ] );

        if ( is_wp_error( $res ) ) return null;
        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( empty( $data['access_token'] ) ) return null;

        set_transient( 'gml_seo_gsa_token', $data['access_token'], 45 * MINUTE_IN_SECONDS );
        return $data['access_token'];
    }

    // ══════════════════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════════════════

    private function indexable_types() {
        $types = get_post_types( [ 'public' => true ], 'names' );
        unset( $types['attachment'] );
        return array_values( $types );
    }

    public function ajax_reindex_post() {
        check_ajax_referer( 'gml_seo_admin' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Unauthorized' );

        $pid = absint( $_POST['post_id'] ?? 0 );
        if ( ! $pid ) wp_send_json_error( 'Invalid post' );

        $url = get_permalink( $pid );
        if ( ! $url ) wp_send_json_error( 'No URL' );

        $this->pending_urls[] = $url;
        $this->flush_queue();

        wp_send_json_success( [ 'url' => $url ] );
    }
}
