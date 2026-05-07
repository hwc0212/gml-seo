<?php
/**
 * GML Translation API — supports Gemini and DeepSeek engines
 *
 * Gemini: POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 * DeepSeek: POST https://api.deepseek.com/v1/chat/completions (OpenAI-compatible)
 *
 * @package GML_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Gemini_API {

    /** Gemini defaults */
    const DEFAULT_MODEL = 'gemini-2.0-flash';
    const API_BASE      = 'https://generativelanguage.googleapis.com/v1beta';

    /** DeepSeek defaults */
    const DEEPSEEK_API_BASE  = 'https://api.deepseek.com/v1';
    const DEEPSEEK_MODEL     = 'deepseek-chat';

    /** Supported engines */
    const ENGINE_GEMINI   = 'gemini';
    const ENGINE_DEEPSEEK = 'deepseek';

    private $api_key;
    private $model;
    private $engine;
    private $protected_terms = [];

    public function __construct() {
        $this->engine          = get_option( 'gml_translation_engine', self::ENGINE_GEMINI );
        $this->api_key         = $this->get_api_key();
        $this->protected_terms = get_option( 'gml_protected_terms', [ 'GML', 'WordPress', 'WooCommerce', 'Gemini' ] );

        if ( $this->engine === self::ENGINE_DEEPSEEK ) {
            $this->model = get_option( 'gml_deepseek_model', self::DEEPSEEK_MODEL );
        } else {
            $this->model = get_option( 'gml_api_model', self::DEFAULT_MODEL );
        }
    }

    // ── Key management ────────────────────────────────────────────────────────

    private function get_api_key() {
        $option = $this->engine === self::ENGINE_DEEPSEEK
            ? 'gml_deepseek_api_key_encrypted'
            : 'gml_api_key_encrypted';

        $stored = get_option( $option );
        if ( ! $stored ) {
            // Fallback: try the other engine's key if same option name was used
            if ( $this->engine === self::ENGINE_DEEPSEEK ) {
                return null;
            }
            return null;
        }
        return self::decrypt_key( $stored );
    }

    /**
     * Decrypt a stored API key.
     */
    public static function decrypt_key( $stored ) {
        if ( function_exists( 'openssl_decrypt' ) ) {
            $key    = wp_salt( 'auth' );
            $iv_len = openssl_cipher_iv_length( 'AES-256-CBC' );
            $raw    = base64_decode( $stored );
            if ( strlen( $raw ) > $iv_len ) {
                $iv        = substr( $raw, 0, $iv_len );
                $cipher    = substr( $raw, $iv_len );
                $decrypted = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
                if ( $decrypted !== false ) {
                    return $decrypted;
                }
            }
        }
        return $stored;
    }

    public static function save_api_key( $api_key, $engine = null ) {
        if ( $engine === null ) {
            $engine = get_option( 'gml_translation_engine', self::ENGINE_GEMINI );
        }
        $option = $engine === self::ENGINE_DEEPSEEK
            ? 'gml_deepseek_api_key_encrypted'
            : 'gml_api_key_encrypted';

        if ( function_exists( 'openssl_encrypt' ) ) {
            $key    = wp_salt( 'auth' );
            $iv_len = openssl_cipher_iv_length( 'AES-256-CBC' );
            $iv     = openssl_random_pseudo_bytes( $iv_len );
            $enc    = openssl_encrypt( $api_key, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
            update_option( $option, base64_encode( $iv . $enc ) );
        } else {
            update_option( $option, $api_key );
        }
    }

    // ── Public translation methods ────────────────────────────────────────────

    public function translate( $text, $source_lang, $target_lang ) {
        if ( ! $this->api_key ) {
            throw new Exception( $this->engine . ' API key not configured' );
        }
        $response = $this->call_api(
            $this->build_system_instruction( $source_lang, $target_lang, 'text' ),
            $text
        );
        return $this->extract_text( $response );
    }

    public function translate_seo( $text, $source_lang, $target_lang ) {
        if ( ! $this->api_key ) {
            throw new Exception( $this->engine . ' API key not configured' );
        }
        $response = $this->call_api(
            $this->build_system_instruction( $source_lang, $target_lang, 'seo' ),
            $text
        );
        return $this->extract_text( $response );
    }

    public function translate_batch( array $texts, $source_lang, $target_lang, $type = 'text' ) {
        if ( ! $this->api_key ) {
            throw new Exception( $this->engine . ' API key not configured' );
        }
        if ( empty( $texts ) ) {
            return [];
        }

        if ( count( $texts ) === 1 ) {
            $response = $this->call_api(
                $this->build_system_instruction( $source_lang, $target_lang, $type ),
                reset( $texts )
            );
            return [ $this->extract_text( $response ) ];
        }

        $numbered = [];
        $i = 1;
        foreach ( $texts as $text ) {
            $numbered[] = "[{$i}] {$text}";
            $i++;
        }
        $user_text = implode( "\n", $numbered );
        $system    = $this->build_batch_instruction( $source_lang, $target_lang, $type, count( $texts ) );
        $response  = $this->call_api( $system, $user_text );
        $raw_output = $this->extract_text( $response );

        return $this->parse_batch_output( $raw_output, count( $texts ) );
    }

    private function build_batch_instruction( $source_lang, $target_lang, $type, $count ) {
        $src       = $this->get_lang_name( $source_lang );
        $tgt       = $this->get_lang_name( $target_lang );
        $site      = get_bloginfo( 'name' );
        $protected = implode( ', ', $this->protected_terms );

        if ( $type === 'seo_title' ) {
            $seo_rule = 'These are page TITLES only. Keep each ≤60 chars. Do NOT add descriptions. ';
        } elseif ( $type === 'seo' ) {
            $seo_rule = 'These are SEO meta descriptions. Keep each ≤160 chars. ';
        } else {
            $seo_rule = 'Tone: ' . get_option( 'gml_tone', 'professional and friendly' ) . '. ';
        }

        $glossary = '';
        if ( class_exists( 'GML_Glossary' ) ) {
            $glossary = GML_Glossary::build_prompt_instruction( $target_lang );
        }

        return "Translate {$count} {$src} texts to {$tgt} for \"{$site}\". {$seo_rule}"
             . "Keep these unchanged: {$protected}. "
             . $glossary
             . "Input/output: numbered [1]…[{$count}]. Return EXACTLY {$count} lines. "
             . "Plain text only — no HTML, markdown, quotes, or explanations.";
    }

    private function parse_batch_output( $output, $expected_count ) {
        $results = [];
        if ( preg_match_all( '/\[(\d+)\]\s*(.+)/m', $output, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                $idx  = (int) $m[1];
                $text = trim( $m[2] );
                if ( strpos( $text, '<' ) !== false ) {
                    $text = wp_strip_all_tags( $text );
                    $text = trim( $text );
                }
                $text = preg_replace( '/^\*{1,2}[^*]+:\*{1,2}\s*/', '', $text );
                $text = preg_replace( '/\*{1,2}([^*]+)\*{1,2}/', '$1', $text );
                $text = preg_replace( '/__([^_]+)__/', '$1', $text );
                $results[ $idx ] = trim( $text );
            }
        }

        $parsed = [];
        for ( $i = 1; $i <= $expected_count; $i++ ) {
            if ( ! isset( $results[ $i ] ) || $results[ $i ] === '' ) {
                throw new Exception( "Batch translation missing segment [{$i}] — got " . count( $results ) . " of {$expected_count}" );
            }
            $parsed[] = $results[ $i ];
        }
        return $parsed;
    }

    // ── Prompt builders ───────────────────────────────────────────────────────

    private function get_lang_name( $code ) {
        $map = [
            'en' => 'English',     'zh' => 'Chinese',     'ja' => 'Japanese',
            'fr' => 'French',      'de' => 'German',      'es' => 'Spanish',
            'pt' => 'Portuguese',  'ru' => 'Russian',     'ko' => 'Korean',
            'ar' => 'Arabic',      'it' => 'Italian',     'nl' => 'Dutch',
            'pl' => 'Polish',      'tr' => 'Turkish',     'vi' => 'Vietnamese',
            'hi' => 'Hindi',       'th' => 'Thai',        'id' => 'Indonesian',
            'ms' => 'Malay',       'tl' => 'Filipino',    'sv' => 'Swedish',
            'da' => 'Danish',      'nb' => 'Norwegian',   'fi' => 'Finnish',
            'cs' => 'Czech',       'sk' => 'Slovak',      'hu' => 'Hungarian',
            'ro' => 'Romanian',    'bg' => 'Bulgarian',   'hr' => 'Croatian',
            'sr' => 'Serbian',     'sl' => 'Slovenian',   'uk' => 'Ukrainian',
            'el' => 'Greek',       'he' => 'Hebrew',      'lt' => 'Lithuanian',
            'lv' => 'Latvian',     'et' => 'Estonian',    'ca' => 'Catalan',
            'fa' => 'Persian',     'ur' => 'Urdu',        'bn' => 'Bengali',
            'ta' => 'Tamil',       'te' => 'Telugu',      'sw' => 'Swahili',
            'af' => 'Afrikaans',   'ka' => 'Georgian',    'hy' => 'Armenian',
            'az' => 'Azerbaijani', 'kk' => 'Kazakh',     'uz' => 'Uzbek',
            'mn' => 'Mongolian',   'km' => 'Khmer',      'my' => 'Myanmar (Burmese)',
            'lo' => 'Lao',         'ne' => 'Nepali',
        ];
        return $map[ $code ] ?? $code;
    }

    private function build_system_instruction( $source_lang, $target_lang, $type = 'text' ) {
        $src       = $this->get_lang_name( $source_lang );
        $tgt       = $this->get_lang_name( $target_lang );
        $site      = get_bloginfo( 'name' );
        $protected = implode( ', ', $this->protected_terms );

        $glossary = '';
        if ( class_exists( 'GML_Glossary' ) ) {
            $glossary = GML_Glossary::build_prompt_instruction( $target_lang );
        }

        if ( $type === 'seo_title' ) {
            return "You are an SEO expert translator for the website \"{$site}\". "
                 . "Translate the following {$src} page TITLE into natural, search-optimised {$tgt}. "
                 . "Keep it under 60 characters. This is a TITLE only — do NOT add a description. "
                 . "Do NOT translate these brand/product names: {$protected}. "
                 . $glossary
                 . "IMPORTANT: Return ONLY the translated title text. "
                 . "No HTML tags, no markdown, no quotes, no explanations, no 'Description:' prefix.";
        }

        if ( $type === 'seo' ) {
            return "You are an SEO expert translator for the website \"{$site}\". "
                 . "Translate the following {$src} SEO meta description into natural, search-optimised {$tgt}. "
                 . "Keep it under 160 characters. "
                 . "Do NOT translate these brand/product names: {$protected}. "
                 . $glossary
                 . "IMPORTANT: Return ONLY the translated plain text. "
                 . "Do NOT wrap the output in any HTML tags. No <h1>, <p>, <div> or any other tags. "
                 . "No explanations, no quotes, no markdown.";
        }

        $tone = get_option( 'gml_tone', 'professional and friendly' );
        return "You are a professional website translator for \"{$site}\". "
             . "Translate the following {$src} plain text into natural {$tgt}. "
             . "Tone: {$tone}. "
             . "The input is plain text extracted from a webpage — it contains NO HTML tags. "
             . "Do NOT translate these brand/product names: {$protected}. "
             . $glossary
             . "IMPORTANT: Return ONLY the translated plain text. "
             . "Do NOT add any HTML tags (no <h1>, <h2>, <p>, <div>, <span>, etc.). "
             . "Do NOT add markdown, quotes, or explanations. Just the translated text.";
    }

    // ── Core API call — dispatches to Gemini or DeepSeek ──────────────────────

    private function call_api( $system_instruction, $user_text, $retry = 0 ) {
        if ( $this->engine === self::ENGINE_DEEPSEEK ) {
            return $this->call_deepseek( $system_instruction, $user_text, $retry );
        }
        return $this->call_gemini( $system_instruction, $user_text, $retry );
    }

    /**
     * Gemini generateContent REST endpoint.
     */
    private function call_gemini( $system_instruction, $user_text, $retry = 0 ) {
        $url = self::API_BASE . '/models/' . $this->model . ':generateContent?key=' . $this->api_key;

        $body = [
            'systemInstruction' => [
                'parts' => [ [ 'text' => $system_instruction ] ],
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [ [ 'text' => $user_text ] ],
                ],
            ],
            'generationConfig' => [
                'temperature'     => 0.2,
                'maxOutputTokens' => 4096,
            ],
        ];

        $response = wp_remote_post( $url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            if ( $retry < 2 ) { sleep( 1 ); return $this->call_gemini( $system_instruction, $user_text, $retry + 1 ); }
            throw new Exception( 'Gemini API request failed: ' . $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $raw    = wp_remote_retrieve_body( $response );

        if ( $status === 429 && $retry < 3 ) { sleep( pow( 2, $retry + 1 ) ); return $this->call_gemini( $system_instruction, $user_text, $retry + 1 ); }
        if ( $status >= 500 && $retry < 2 )   { sleep( 2 ); return $this->call_gemini( $system_instruction, $user_text, $retry + 1 ); }

        if ( $status !== 200 ) {
            error_log( "GML Gemini API error (HTTP {$status}): {$raw}" );
            throw new Exception( "Gemini API returned HTTP {$status}" );
        }

        $data = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new Exception( 'Invalid JSON from Gemini API' );
        }
        return $data;
    }

    /**
     * DeepSeek chat/completions endpoint (OpenAI-compatible).
     */
    private function call_deepseek( $system_instruction, $user_text, $retry = 0 ) {
        $base_url = rtrim( get_option( 'gml_deepseek_api_base', self::DEEPSEEK_API_BASE ), '/' );
        $url      = $base_url . '/chat/completions';

        $body = [
            'model'       => $this->model,
            'messages'    => [
                [ 'role' => 'system',  'content' => $system_instruction ],
                [ 'role' => 'user',    'content' => $user_text ],
            ],
            'temperature' => 0.2,
            'max_tokens'  => 4096,
        ];

        $response = wp_remote_post( $url, [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
        ] );

        if ( is_wp_error( $response ) ) {
            if ( $retry < 2 ) { sleep( 1 ); return $this->call_deepseek( $system_instruction, $user_text, $retry + 1 ); }
            throw new Exception( 'DeepSeek API request failed: ' . $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $raw    = wp_remote_retrieve_body( $response );

        if ( $status === 429 && $retry < 3 ) { sleep( pow( 2, $retry + 1 ) ); return $this->call_deepseek( $system_instruction, $user_text, $retry + 1 ); }
        if ( $status >= 500 && $retry < 2 )   { sleep( 2 ); return $this->call_deepseek( $system_instruction, $user_text, $retry + 1 ); }

        if ( $status !== 200 ) {
            error_log( "GML DeepSeek API error (HTTP {$status}): {$raw}" );
            throw new Exception( "DeepSeek API returned HTTP {$status}" );
        }

        $data = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new Exception( 'Invalid JSON from DeepSeek API' );
        }
        return $data;
    }

    // ── Response parsing ──────────────────────────────────────────────────────

    private function extract_text( $response ) {
        if ( $this->engine === self::ENGINE_DEEPSEEK ) {
            return $this->extract_text_openai( $response );
        }
        return $this->extract_text_gemini( $response );
    }

    private function extract_text_gemini( $response ) {
        if ( isset( $response['promptFeedback']['blockReason'] ) ) {
            throw new Exception( 'Prompt blocked: ' . $response['promptFeedback']['blockReason'] );
        }

        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ( $text === null ) {
            error_log( 'GML: Unexpected Gemini response: ' . wp_json_encode( $response ) );
            throw new Exception( 'No text in Gemini API response' );
        }
        return $this->clean_output( $text );
    }

    private function extract_text_openai( $response ) {
        $text = $response['choices'][0]['message']['content'] ?? null;
        if ( $text === null ) {
            // Check for error
            if ( isset( $response['error']['message'] ) ) {
                throw new Exception( 'DeepSeek API error: ' . $response['error']['message'] );
            }
            error_log( 'GML: Unexpected DeepSeek response: ' . wp_json_encode( $response ) );
            throw new Exception( 'No text in DeepSeek API response' );
        }
        return $this->clean_output( $text );
    }

    /**
     * Clean up LLM output — strip HTML, markdown formatting.
     */
    private function clean_output( $text ) {
        $text = trim( $text );
        if ( strpos( $text, '<' ) !== false ) {
            $text = wp_strip_all_tags( $text );
            $text = trim( $text );
        }
        $text = preg_replace( '/^\*{1,2}[^*]+:\*{1,2}\s*/', '', $text );
        $text = preg_replace( '/\*{1,2}([^*]+)\*{1,2}/', '$1', $text );
        $text = preg_replace( '/__([^_]+)__/', '$1', $text );
        return trim( $text );
    }

    // ── API key test ──────────────────────────────────────────────────────────

    public static function test_api_key( $api_key = null, $engine = null ) {
        if ( $engine === null ) {
            $engine = get_option( 'gml_translation_engine', self::ENGINE_GEMINI );
        }

        try {
            if ( ! $api_key ) {
                return [ 'valid' => false, 'message' => __( 'No API key provided', 'gml-translate' ) ];
            }

            if ( $engine === self::ENGINE_DEEPSEEK ) {
                return self::test_deepseek_key( $api_key );
            }
            return self::test_gemini_key( $api_key );

        } catch ( Exception $e ) {
            return [ 'valid' => false, 'message' => $e->getMessage() ];
        }
    }

    private static function test_gemini_key( $api_key ) {
        $url  = self::API_BASE . '/models/' . self::DEFAULT_MODEL . ':generateContent?key=' . $api_key;
        $body = [
            'systemInstruction' => [
                'parts' => [ [ 'text' => 'You are a translator. Return only the translation.' ] ],
            ],
            'contents' => [
                [ 'role' => 'user', 'parts' => [ [ 'text' => 'Translate "Hello" to Chinese.' ] ] ],
            ],
            'generationConfig' => [ 'maxOutputTokens' => 20 ],
        ];

        $resp = wp_remote_post( $url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $resp ) ) {
            return [ 'valid' => false, 'message' => $resp->get_error_message() ];
        }

        $status = wp_remote_retrieve_response_code( $resp );
        $data   = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $status === 200 && isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
            return [ 'valid' => true, 'message' => __( 'Gemini API key is valid!', 'gml-translate' ) ];
        }
        if ( $status === 400 ) return [ 'valid' => false, 'message' => __( 'Invalid API key format', 'gml-translate' ) ];
        if ( $status === 403 ) return [ 'valid' => false, 'message' => __( 'API key invalid or lacks permission', 'gml-translate' ) ];
        if ( $status === 429 ) return [ 'valid' => false, 'message' => __( 'Rate limit exceeded — try again later', 'gml-translate' ) ];
        return [ 'valid' => false, 'message' => sprintf( __( 'API error (HTTP %d)', 'gml-translate' ), $status ) ];
    }

    private static function test_deepseek_key( $api_key ) {
        $base_url = rtrim( get_option( 'gml_deepseek_api_base', self::DEEPSEEK_API_BASE ), '/' );
        $url      = $base_url . '/chat/completions';
        $model    = get_option( 'gml_deepseek_model', self::DEEPSEEK_MODEL );

        $body = [
            'model'    => $model,
            'messages' => [
                [ 'role' => 'system', 'content' => 'You are a translator. Return only the translation.' ],
                [ 'role' => 'user',   'content' => 'Translate "Hello" to Chinese.' ],
            ],
            'max_tokens' => 20,
        ];

        $resp = wp_remote_post( $url, [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $resp ) ) {
            return [ 'valid' => false, 'message' => $resp->get_error_message() ];
        }

        $status = wp_remote_retrieve_response_code( $resp );
        $data   = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $status === 200 && isset( $data['choices'][0]['message']['content'] ) ) {
            return [ 'valid' => true, 'message' => __( 'DeepSeek API key is valid!', 'gml-translate' ) ];
        }
        if ( isset( $data['error']['message'] ) ) {
            return [ 'valid' => false, 'message' => 'DeepSeek: ' . $data['error']['message'] ];
        }
        return [ 'valid' => false, 'message' => sprintf( __( 'DeepSeek API error (HTTP %d)', 'gml-translate' ), $status ) ];
    }

    /**
     * Get the current engine name for display.
     */
    public static function get_engine_label( $engine = null ) {
        if ( $engine === null ) {
            $engine = get_option( 'gml_translation_engine', self::ENGINE_GEMINI );
        }
        return $engine === self::ENGINE_DEEPSEEK ? 'DeepSeek' : 'Google Gemini';
    }
}
