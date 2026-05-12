<?php
/**
 * GML SEO — Performance Optimizer
 *
 * Auto-applies safe, Google-recommended performance optimizations.
 * Each optimization can be toggled individually under
 * **GML AI SEO → Settings → Performance**. Defaults are ALL ON
 * (equivalent to pre-v1.9.1 always-on behaviour).
 *
 * @package GML_SEO
 * @since   1.1.0
 * @since   1.9.1 Individual on/off switches per optimization.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_Performance {

    /**
     * Declarative list of all toggleable optimizations.
     *
     * Layout: group_id => [ key => [ label, default, help ] ].
     * UI rendering in class-admin.php walks this map; defaults also
     * flow from here so nothing drifts between code and UI.
     *
     * @var array
     */
    public static $toggles = [
        'wp_bloat' => [
            'label'   => 'WordPress 瘦身',
            'options' => [
                'perf_remove_emoji'         => [ '移除 Emoji 脚本和样式', 1, '节省 ~10KB，绝大多数网站用不上。' ],
                'perf_remove_dashicons'     => [ '移除 Dashicons CSS（未登录用户）', 1, '节省 ~46KB，Dashicons 是后台图标字体。' ],
                'perf_remove_embed'         => [ '移除 oEmbed 嵌入脚本', 1, '节省 ~6KB，wp-embed.min.js 前端很少用。' ],
                'perf_remove_head_links'    => [ '清理 <head> 中的无用 link 标签', 1, 'RSD / WLW / Shortlink / REST / oEmbed discovery。' ],
                'perf_hide_generator'       => [ '隐藏 WordPress 版本号', 1, '移除 <meta name="generator">，安全 + 减少信息泄露。' ],
                'perf_remove_global_styles' => [ '移除 Gutenberg 全局样式', 1, '移除未使用的块库内联 CSS。' ],
                'perf_disable_xmlrpc'       => [ '禁用 XML-RPC', 1, '关闭 xmlrpc.php 端点，防止暴力破解，无 SEO 影响。' ],
                'perf_disable_self_ping'    => [ '禁用自我 Pingback', 1, '防止 WordPress 向自己发送 pingback。' ],
            ],
        ],
        'js' => [
            'label'   => 'JavaScript 优化',
            'options' => [
                'perf_defer_js' => [ 'Defer 非关键 JavaScript', 0, '默认关闭。某些主题的导航 / 下拉菜单 / 滑块 JS 会被延后到 DOMContentLoaded 之后才初始化，打开前请在前台测试导航是否正常。自动跳过 jQuery / wc-cart-fragments 等关键脚本。' ],
            ],
        ],
        'fonts' => [
            'label'   => '字体优化',
            'options' => [
                'perf_font_swap' => [ 'Google Fonts font-display: swap', 1, '字体加载期间先用系统字体显示，避免不可见文字。' ],
            ],
        ],
        'images' => [
            'label'   => '图片与 iframe 优化',
            'options' => [
                'perf_lazy_images'       => [ '图片 Lazy Loading（首屏前 2 张除外）', 1, '加 loading="lazy" + decoding="async"。' ],
                'perf_image_dimensions'  => [ '自动补全图片 width / height', 1, '防止 CLS（累积布局偏移）。仅对本地 uploads 图片生效。v1.9.2 起同时注入 style="height:auto;max-width:100%" 兜底，防止主题 CSS 缺 height:auto 时图片被压扁 / 拉伸。' ],
                'perf_lcp_fetchpriority' => [ '首图 fetchpriority="high"', 1, 'Google LCP 优化建议。' ],
                'perf_lazy_iframes'      => [ 'iframe Lazy Loading', 1, 'YouTube、Google Maps 等 iframe 加 loading="lazy"。' ],
            ],
        ],
        'hints' => [
            'label'   => '资源提示与预加载',
            'options' => [
                'perf_resource_hints'   => [ 'Preconnect / DNS Prefetch 外部域名', 1, '自动检测 Google Fonts、GA、GTM、Gravatar 等。' ],
                'perf_preload_featured' => [ 'Preload 特色图片', 1, '在 <head> 中 preload 文章特色图，加速 LCP。' ],
                'perf_http_link_header' => [ 'HTTP Link 预加载头（HTTP/2 Early Hints）', 1, 'Cloudflare / Fastly 会转为 103 状态。' ],
            ],
        ],
        'html' => [
            'label'   => 'HTML 与 REST API',
            'options' => [
                'perf_minify_html'         => [ 'HTML 输出压缩', 1, '移除多余空白和 HTML 注释，HTML 体积减少 5-15%。智能跳过 pre/textarea/script/style/code。标签间空白折叠为单个空格（不完全删除），兼容依赖空白节点的主题导航 JS。' ],
                'perf_disable_oembed_rest' => [ '禁用 oEmbed REST 端点', 1, '/wp-json/oembed/* 几乎无人使用但被机器人频繁爬取。' ],
            ],
        ],
    ];

    /**
     * Read a `perf_*` toggle. Unknown keys default to their declared
     * default (ON for all current toggles).
     *
     * @param string $key perf_* option key.
     * @return bool
     */
    public static function is_enabled( string $key ): bool {
        foreach ( self::$toggles as $group ) {
            if ( isset( $group['options'][ $key ] ) ) {
                $default = (int) $group['options'][ $key ][1];
                return (bool) GML_SEO::opt( $key, $default );
            }
        }
        return (bool) GML_SEO::opt( $key, 1 );
    }

    /**
     * Flat list of all perf_* option keys (used by sanitize()).
     *
     * @return string[]
     */
    public static function all_keys(): array {
        $keys = [];
        foreach ( self::$toggles as $group ) {
            foreach ( $group['options'] as $key => $_meta ) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    public function __construct() {
        // Skip for logged-in admins — never break the admin experience
        if ( is_admin() || ( is_user_logged_in() && current_user_can( 'manage_options' ) ) ) {
            return;
        }

        // ── WP Bloat Removal ─────────────────────────────────────
        $this->remove_wp_bloat();

        // ── JS Optimization ──────────────────────────────────────
        if ( self::is_enabled( 'perf_defer_js' ) ) {
            add_filter( 'script_loader_tag', [ $this, 'defer_js' ], 10, 3 );
        }

        // ── Font optimization (font-display: swap) ───────────────
        if ( self::is_enabled( 'perf_font_swap' ) ) {
            add_filter( 'style_loader_tag', [ $this, 'add_font_display_swap' ], 10, 4 );
        }

        // ── Image / iframe / HTML output buffer ──────────────────
        // Only start the buffer if at least one of its sub-features is on.
        $buffer_needed = (
            self::is_enabled( 'perf_lazy_images' )
            || self::is_enabled( 'perf_image_dimensions' )
            || self::is_enabled( 'perf_lcp_fetchpriority' )
            || self::is_enabled( 'perf_lazy_iframes' )
            || self::is_enabled( 'perf_minify_html' )
        );
        if ( $buffer_needed ) {
            // Priority 0: run BEFORE GML Translate (priority 1) so that
            // translated HTML still gets image lazy-load / width-height fixes.
            add_action( 'template_redirect', [ $this, 'start_buffer' ], 0 );
        }

        // ── Resource Hints + Preload HTTP header ─────────────────
        if ( self::is_enabled( 'perf_resource_hints' ) ) {
            add_action( 'wp_head', [ $this, 'resource_hints' ], 1 );
        }

        // ── Preload LCP image ────────────────────────────────────
        if ( self::is_enabled( 'perf_preload_featured' ) ) {
            add_action( 'wp_head', [ $this, 'preload_featured_image' ], 2 );
        }

        // ── Disable unused oEmbed REST endpoints ─────────────────
        if ( self::is_enabled( 'perf_disable_oembed_rest' ) ) {
            $this->disable_oembed_rest();
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 1. REMOVE WORDPRESS BLOAT
    // Individual toggles — each group is cheap and independent.
    // ═══════════════════════════════════════════════════════════════

    private function remove_wp_bloat() {
        if ( self::is_enabled( 'perf_remove_emoji' ) ) {
            remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
            remove_action( 'wp_print_styles', 'print_emoji_styles' );
            remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
            remove_action( 'admin_print_styles', 'print_emoji_styles' );
            add_filter( 'emoji_svg_url', '__return_false' );
        }

        if ( self::is_enabled( 'perf_remove_head_links' ) ) {
            remove_action( 'wp_head', 'rsd_link' );
            remove_action( 'wp_head', 'wlwmanifest_link' );
            remove_action( 'wp_head', 'wp_shortlink_wp_head' );
            remove_action( 'wp_head', 'rest_output_link_wp_head' );
            remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
        }

        if ( self::is_enabled( 'perf_hide_generator' ) ) {
            remove_action( 'wp_head', 'wp_generator' );
        }

        if ( self::is_enabled( 'perf_remove_dashicons' ) ) {
            add_action( 'wp_enqueue_scripts', function() {
                if ( ! is_user_logged_in() ) {
                    wp_deregister_style( 'dashicons' );
                }
            } );
        }

        if ( self::is_enabled( 'perf_remove_embed' ) ) {
            add_action( 'wp_footer', function() {
                wp_deregister_script( 'wp-embed' );
            } );
        }

        if ( self::is_enabled( 'perf_remove_global_styles' ) ) {
            remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
            remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );
        }

        if ( self::is_enabled( 'perf_disable_self_ping' ) ) {
            add_action( 'pre_ping', function( &$links ) {
                $home = home_url();
                foreach ( $links as $i => $link ) {
                    if ( strpos( $link, $home ) === 0 ) {
                        unset( $links[ $i ] );
                    }
                }
            } );
        }

        if ( self::is_enabled( 'perf_disable_xmlrpc' ) ) {
            add_filter( 'xmlrpc_enabled', '__return_false' );
            add_filter( 'wp_headers', function( $headers ) {
                unset( $headers['X-Pingback'] );
                return $headers;
            } );
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. DEFER NON-CRITICAL JAVASCRIPT
    // ═══════════════════════════════════════════════════════════════

    public function defer_js( $tag, $handle, $src ) {
        if ( is_admin() ) return $tag;
        if ( strpos( $tag, ' defer' ) !== false || strpos( $tag, ' async' ) !== false ) {
            return $tag;
        }
        $skip = [
            'jquery-core', 'jquery', 'jquery-migrate',
            'wp-polyfill', 'wp-hooks',
            'wc-cart-fragments',
        ];
        if ( in_array( $handle, $skip, true ) ) return $tag;
        if ( empty( $src ) ) return $tag;
        return str_replace( ' src=', ' defer src=', $tag );
    }

    // ═══════════════════════════════════════════════════════════════
    // 2b. FONT DISPLAY SWAP
    // ═══════════════════════════════════════════════════════════════

    public function add_font_display_swap( $html, $handle, $href, $media ) {
        if ( stripos( $href, 'fonts.googleapis.com' ) === false ) return $html;
        if ( stripos( $href, 'display=' ) !== false ) return $html;
        $separator = ( strpos( $href, '?' ) !== false ) ? '&' : '?';
        $new_href  = $href . $separator . 'display=swap';
        return str_replace( $href, $new_href, $html );
    }

    // ═══════════════════════════════════════════════════════════════
    // 2c. DISABLE UNUSED oEmbed REST ENDPOINTS
    // ═══════════════════════════════════════════════════════════════

    private function disable_oembed_rest() {
        remove_action( 'rest_api_init', 'wp_oembed_register_route' );
        remove_filter( 'oembed_response_data', 'get_oembed_response_data_rich', 10 );
        remove_filter( 'rest_pre_serve_request', '_oembed_rest_pre_serve_request', 10 );
        add_filter( 'tiny_mce_plugins', function( $plugins ) {
            return array_diff( $plugins, [ 'wpembed' ] );
        } );
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. IMAGE / IFRAME / HTML OUTPUT BUFFER
    // Each sub-feature has its own toggle and is skipped individually
    // when disabled.
    // ═══════════════════════════════════════════════════════════════

    public function start_buffer() {
        if ( is_feed() || is_robots() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            return;
        }
        ob_start( [ $this, 'process_buffer' ] );
    }

    public function process_buffer( $html ) {
        if ( strlen( $html ) < 255 ) return $html;
        if ( stripos( $html, '<html' ) === false ) return $html;

        $lazy_img    = self::is_enabled( 'perf_lazy_images' );
        $dim_fill    = self::is_enabled( 'perf_image_dimensions' );
        $fetchp      = self::is_enabled( 'perf_lcp_fetchpriority' );
        $lazy_iframe = self::is_enabled( 'perf_lazy_iframes' );
        $minify      = self::is_enabled( 'perf_minify_html' );

        if ( $lazy_img || $dim_fill || $fetchp ) {
            $img_count = 0;
            $html = preg_replace_callback( '/<img\b([^>]*)>/is', function( $m ) use ( &$img_count, $lazy_img, $dim_fill, $fetchp ) {
                $atts = $m[1];
                $img_count++;

                if ( $img_count <= 2 ) {
                    if ( $lazy_img ) {
                        // First 1-2 images: don't lazy load (above the fold / LCP candidate)
                        $atts = preg_replace( '/\s*loading=["\'][^"\']*["\']/', '', $atts );
                    }
                    if ( $fetchp && $img_count === 1 && strpos( $atts, 'fetchpriority' ) === false ) {
                        $atts .= ' fetchpriority="high"';
                    }
                } else {
                    if ( $lazy_img ) {
                        if ( stripos( $atts, 'loading=' ) === false ) {
                            $atts .= ' loading="lazy"';
                        }
                        if ( stripos( $atts, 'decoding=' ) === false ) {
                            $atts .= ' decoding="async"';
                        }
                    }
                }

                if ( $dim_fill && stripos( $atts, 'width=' ) === false && stripos( $atts, 'height=' ) === false ) {
                    $dims = $this->get_image_dimensions( $atts );
                    if ( $dims ) {
                        $atts .= ' width="' . $dims[0] . '" height="' . $dims[1] . '"';
                    }
                }

                // Always inject height:auto when the image has explicit
                // width/height attributes (whether added by us above, by
                // WordPress core, or by the theme) and no inline style yet.
                // Without this, browsers keep the literal height value when
                // CSS/flexbox scales the width down, squashing or stretching
                // the image. Author-provided style attributes are left alone.
                if ( stripos( $atts, 'style=' ) === false
                     && (
                         stripos( $atts, 'width=' ) !== false
                         || stripos( $atts, 'height=' ) !== false
                     )
                ) {
                    $atts .= ' style="height:auto;max-width:100%"';
                }

                return '<img' . $atts . '>';
            }, $html );
        }

        if ( $lazy_iframe ) {
            $html = preg_replace_callback( '/<iframe\b([^>]*)>/is', function( $m ) {
                $atts = $m[1];
                if ( stripos( $atts, 'loading=' ) === false ) {
                    $atts .= ' loading="lazy"';
                }
                return '<iframe' . $atts . '>';
            }, $html );
        }

        if ( $minify ) {
            $html = $this->minify_html( $html );
        }

        return $html;
    }

    /**
     * Minify HTML by collapsing whitespace and removing comments.
     * Preserves content inside tags that require exact whitespace
     * (pre, textarea, script, style, code) and conditional comments.
     */
    private function minify_html( $html ) {
        $protected = [];
        $counter   = 0;
        $tags      = 'pre|textarea|script|style|code';

        $html = preg_replace_callback(
            '#<(' . $tags . ')\b[^>]*>.*?</\1>#is',
            function ( $m ) use ( &$protected, &$counter ) {
                $token = '<!--GMLMIN_' . ( $counter++ ) . '-->';
                $protected[ $token ] = $m[0];
                return $token;
            },
            $html
        );

        $html = preg_replace_callback(
            '/<!--\[if\b[^>]*?\]>.*?<!\[endif\]-->/is',
            function ( $m ) use ( &$protected, &$counter ) {
                $token = '<!--GMLMIN_' . ( $counter++ ) . '-->';
                $protected[ $token ] = $m[0];
                return $token;
            },
            $html
        );

        $html = preg_replace( '/<!--(?!\[if)(?!-).*?-->/s', '', $html );
        $html = preg_replace( '/\s{2,}/', ' ', $html );
        // Collapse whitespace between tags to a single space rather than
        // removing it entirely. Removing it (replacing >\s+< with ><) breaks
        // theme navigation JS that uses nextElementSibling / jQuery .next()
        // to locate sub-menus — those APIs skip text nodes only when the
        // text node is purely whitespace AND the browser has already
        // normalised it; stripping it in the raw HTML changes the DOM tree
        // in ways that differ across browsers and JS libraries.
        $html = preg_replace( '/>\s+</', '> <', $html );
        $html = preg_replace( '/\s{2,}/', ' ', $html );

        if ( ! empty( $protected ) ) {
            $html = str_replace(
                array_keys( $protected ),
                array_values( $protected ),
                $html
            );
        }

        return $html;
    }

    private function get_image_dimensions( $atts ) {
        if ( ! preg_match( '/src=["\']([^"\']+)["\']/', $atts, $m ) ) return null;
        $src = $m[1];

        $upload_dir = wp_get_upload_dir();
        if ( strpos( $src, $upload_dir['baseurl'] ) === false ) return null;

        $file = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $src );
        if ( ! file_exists( $file ) ) return null;

        if ( preg_match( '/-(\d+)x(\d+)\.[a-z]+$/i', $file, $dm ) ) {
            return [ (int) $dm[1], (int) $dm[2] ];
        }

        $size = @getimagesize( $file );
        if ( $size && $size[0] > 0 && $size[1] > 0 ) {
            return [ $size[0], $size[1] ];
        }
        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    // 4. RESOURCE HINTS
    // ═══════════════════════════════════════════════════════════════

    public function resource_hints() {
        $hints       = [];
        $link_header = self::is_enabled( 'perf_http_link_header' );

        global $wp_styles;
        if ( $wp_styles ) {
            foreach ( $wp_styles->registered as $style ) {
                if ( ! empty( $style->src ) && strpos( $style->src, 'fonts.googleapis.com' ) !== false ) {
                    $hints['https://fonts.googleapis.com'] = 'preconnect';
                    $hints['https://fonts.gstatic.com']    = 'preconnect';
                    break;
                }
            }
        }

        if ( GML_SEO::opt( 'ga_id' ) || GML_SEO::opt( 'gtm_id' ) ) {
            $hints['https://www.googletagmanager.com'] = 'preconnect';
            $hints['https://www.google-analytics.com'] = 'dns-prefetch';
        }

        if ( is_singular() && comments_open() ) {
            $hints['https://secure.gravatar.com'] = 'dns-prefetch';
        }

        foreach ( $hints as $url => $rel ) {
            $crossorigin = ( $rel === 'preconnect' ) ? ' crossorigin' : '';
            echo '<link rel="' . $rel . '" href="' . esc_url( $url ) . '"' . $crossorigin . '>' . "\n";

            if ( $link_header && ! headers_sent() ) {
                $header = '<' . esc_url_raw( $url ) . '>; rel=' . $rel;
                if ( $rel === 'preconnect' ) {
                    $header .= '; crossorigin';
                }
                header( 'Link: ' . $header, false );
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 5. PRELOAD LCP IMAGE
    // ═══════════════════════════════════════════════════════════════

    public function preload_featured_image() {
        if ( ! is_singular() ) return;

        $id    = get_queried_object_id();
        $thumb = get_post_thumbnail_id( $id );
        if ( ! $thumb ) return;

        $src = wp_get_attachment_image_src( $thumb, 'large' );
        if ( ! $src ) return;

        $srcset = wp_get_attachment_image_srcset( $thumb, 'large' );
        $sizes  = wp_get_attachment_image_sizes( $thumb, 'large' );

        echo '<link rel="preload" as="image" href="' . esc_url( $src[0] ) . '"';
        if ( $srcset ) echo ' imagesrcset="' . esc_attr( $srcset ) . '"';
        if ( $sizes ) echo ' imagesizes="' . esc_attr( $sizes ) . '"';
        echo ' fetchpriority="high">' . "\n";

        if ( self::is_enabled( 'perf_http_link_header' ) && ! headers_sent() ) {
            $link = '<' . esc_url_raw( $src[0] ) . '>; rel=preload; as=image';
            if ( $srcset ) {
                $link .= '; imagesrcset="' . str_replace( '"', '%22', $srcset ) . '"';
            }
            if ( $sizes ) {
                $link .= '; imagesizes="' . str_replace( '"', '%22', $sizes ) . '"';
            }
            header( 'Link: ' . $link, false );
        }
    }
}
