<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_Gemini {

    private $key;
    private $model;

    public function __construct( $key = '', $model = '' ) {
        $this->key   = $key ?: GML_SEO::opt( 'gemini_key' );
        $this->model = $model ?: GML_SEO::opt( 'model', 'gemini-2.5-flash' );
    }

    /**
     * Call Gemini API. Returns text string or WP_Error.
     */
    public function call( $prompt, $system = '', $max_tokens = 2048 ) {
        if ( ! $this->key ) return new WP_Error( 'no_key', 'Gemini API key missing.' );

        $url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->model . ':generateContent?key=' . $this->key;
        $body = [
            'contents'         => [ [ 'parts' => [ [ 'text' => $prompt ] ] ] ],
            'generationConfig' => [ 'temperature' => 0.4, 'maxOutputTokens' => $max_tokens ],
        ];
        if ( $system ) {
            $body['systemInstruction'] = [ 'parts' => [ [ 'text' => $system ] ] ];
        }

        $r = wp_remote_post( $url, [
            'timeout' => 30,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $r ) ) return $r;

        $code = wp_remote_retrieve_response_code( $r );
        $data = json_decode( wp_remote_retrieve_body( $r ), true );

        if ( $code !== 200 ) {
            return new WP_Error( 'api', $data['error']['message'] ?? "HTTP $code" );
        }

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    /**
     * Call and parse JSON response.
     */
    public function call_json( $prompt, $system = '', $max_tokens = 2048 ) {
        $text = $this->call( $prompt, $system, $max_tokens );
        if ( is_wp_error( $text ) ) return $text;

        $text = trim( $text );
        $text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
        $text = preg_replace( '/\s*```$/', '', $text );
        $out  = json_decode( $text, true );
        return is_array( $out ) ? $out : new WP_Error( 'parse', 'Invalid JSON from Gemini.', [ 'raw' => $text ] );
    }
}
