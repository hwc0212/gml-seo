<?php
/**
 * GML SEO — AI API Client.
 *
 * Supports:
 *  - gemini:   Google Gemini (generativelanguage.googleapis.com)
 *  - deepseek: DeepSeek Chat (api.deepseek.com, OpenAI-compatible)
 *  - qwen:     Alibaba Cloud DashScope / Qwen (OpenAI-compatible)
 *  - openai:   ChatGPT / OpenAI (OpenAI-compatible)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_AI_Client {

    private $engine;
    private $key;
    private $model;
    private $base_url;
    private $label;

    public function __construct() {
        $this->engine = GML_SEO::opt( 'engine', 'gemini' );

        if ( $this->engine === 'gemini' ) {
            $this->label = 'Gemini';
            $this->key   = GML_SEO::opt( 'gemini_key' );
            $this->model = GML_SEO::opt( 'model', 'gemini-2.5-flash' );
            return;
        }

        $providers = $this->openai_compatible_providers();
        if ( ! isset( $providers[ $this->engine ] ) ) {
            $this->engine = 'gemini';
            $this->label  = 'Gemini';
            $this->key    = GML_SEO::opt( 'gemini_key' );
            $this->model  = GML_SEO::opt( 'model', 'gemini-2.5-flash' );
            return;
        }

        $p = $providers[ $this->engine ];
        $this->label    = $p['label'];
        $this->key      = GML_SEO::opt( $p['key_opt'] );
        $this->model    = GML_SEO::opt( $p['model_opt'], $p['model_default'] );
        $this->base_url = rtrim( GML_SEO::opt( $p['base_url_opt'], $p['base_url_default'] ), '/' );
    }

    /**
     * Call AI API. Returns text string or WP_Error.
     */
    public function call( $prompt, $system = '', $max_tokens = 2048 ) {
        if ( ! $this->key ) {
            return new WP_Error( 'no_key', $this->label . ' API key missing.' );
        }

        return $this->is_openai_compatible()
            ? $this->call_openai_compatible( $prompt, $system, $max_tokens )
            : $this->call_gemini( $prompt, $system, $max_tokens );
    }

    /**
     * Call and parse JSON response. Retries once on parse failure.
     */
    public function call_json( $prompt, $system = '', $max_tokens = 2048 ) {
        $attempts = 2; // 1 initial + 1 retry
        $last_error = null;

        for ( $i = 0; $i < $attempts; $i++ ) {
            $text = $this->call( $prompt, $system, $max_tokens );
            if ( is_wp_error( $text ) ) return $text;

            $text = trim( $text );
            $text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
            $text = preg_replace( '/\s*```$/', '', $text );
            $out  = json_decode( $text, true );

            if ( is_array( $out ) ) {
                return $out;
            }

            $last_error = new WP_Error( 'parse', "Invalid JSON from {$this->label}.", [ 'raw' => mb_substr( $text, 0, 500 ) ] );

            // Log retry attempt
            if ( $i < $attempts - 1 ) {
                error_log( "GML SEO: Invalid JSON from {$this->label}, retrying (attempt " . ( $i + 2 ) . ")..." );
            }
        }

        return $last_error;
    }

    private function openai_compatible_providers() {
        return [
            'deepseek' => [
                'label'            => 'DeepSeek',
                'key_opt'          => 'deepseek_key',
                'model_opt'        => 'deepseek_model',
                'model_default'    => 'deepseek-chat',
                'base_url_opt'     => 'deepseek_base_url',
                'base_url_default' => 'https://api.deepseek.com',
            ],
            'qwen' => [
                'label'            => 'Qwen',
                'key_opt'          => 'qwen_key',
                'model_opt'        => 'qwen_model',
                'model_default'    => 'qwen-plus',
                'base_url_opt'     => 'qwen_base_url',
                'base_url_default' => 'https://dashscope.aliyuncs.com/compatible-mode',
            ],
            'openai' => [
                'label'            => 'OpenAI',
                'key_opt'          => 'openai_key',
                'model_opt'        => 'openai_model',
                'model_default'    => 'gpt-4o-mini',
                'base_url_opt'     => 'openai_base_url',
                'base_url_default' => 'https://api.openai.com',
            ],
        ];
    }

    private function is_openai_compatible() {
        return isset( $this->openai_compatible_providers()[ $this->engine ] );
    }

    // ── Gemini ───────────────────────────────────────────────────────

    private function call_gemini( $prompt, $system, $max_tokens ) {
        $url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $this->model ) . ':generateContent?key=' . rawurlencode( $this->key );
        $body = [
            'contents'         => [ [ 'parts' => [ [ 'text' => $prompt ] ] ] ],
            'generationConfig' => [ 'temperature' => 0.4, 'maxOutputTokens' => $max_tokens ],
        ];
        if ( $system ) {
            $body['systemInstruction'] = [ 'parts' => [ [ 'text' => $system ] ] ];
        }

        $r = wp_remote_post( $url, [
            'timeout' => 60,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $r ) ) return $r;

        $code = wp_remote_retrieve_response_code( $r );
        $data = json_decode( wp_remote_retrieve_body( $r ), true );

        if ( $code !== 200 ) {
            return new WP_Error( 'api', 'Gemini: ' . ( $data['error']['message'] ?? "HTTP $code" ) );
        }

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    // ── OpenAI-compatible providers ──────────────────────────────────

    private function call_openai_compatible( $prompt, $system, $max_tokens ) {
        $url = $this->base_url . '/v1/chat/completions';

        $messages = [];
        if ( $system ) {
            $messages[] = [ 'role' => 'system', 'content' => $system ];
        }
        $messages[] = [ 'role' => 'user', 'content' => $prompt ];

        $body = [
            'model'      => $this->model,
            'messages'   => $messages,
            'max_tokens' => $max_tokens,
            'temperature'=> 0.4,
        ];

        $r = wp_remote_post( $url, [
            'timeout' => 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->key,
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $r ) ) return $r;

        $code = wp_remote_retrieve_response_code( $r );
        $data = json_decode( wp_remote_retrieve_body( $r ), true );

        if ( $code !== 200 ) {
            $msg = $data['error']['message'] ?? ( $data['detail'] ?? "HTTP $code" );
            return new WP_Error( 'api', $this->label . ': ' . $msg );
        }

        return $data['choices'][0]['message']['content'] ?? '';
    }
}

// Backward compat alias — AI engine references this class name
class_alias( 'GML_SEO_AI_Client', 'GML_SEO_Gemini' );
