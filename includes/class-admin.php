<?php
/**
 * Admin settings — minimal setup: API key, GA, code injection, bulk optimize.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'menu' ] );
        add_action( 'admin_init', [ $this, 'register' ] );
    }

    public function menu() {
        add_menu_page( 'GML AI SEO', 'GML AI SEO', 'manage_options', 'gml-seo', [ $this, 'page' ], 'dashicons-superhero-alt', 80 );
    }

    public function register() {
        register_setting( 'gml_seo_group', 'gml_seo', [ 'sanitize_callback' => [ $this, 'sanitize' ] ] );
    }

    public function sanitize( $in ) {
        $o = [];
        $o['gemini_key']  = sanitize_text_field( $in['gemini_key'] ?? '' );
        $o['model']       = sanitize_text_field( $in['model'] ?? 'gemini-2.5-flash' );
        $o['ga_id']       = sanitize_text_field( $in['ga_id'] ?? '' );
        $o['gtm_id']      = sanitize_text_field( $in['gtm_id'] ?? '' );
        $o['adsense_id']  = sanitize_text_field( $in['adsense_id'] ?? '' );
        $o['head_code']   = $in['head_code'] ?? '';   // Allow raw HTML
        $o['body_code']   = $in['body_code'] ?? '';
        $o['footer_code'] = $in['footer_code'] ?? '';
        $o['site_name']   = sanitize_text_field( $in['site_name'] ?? get_bloginfo( 'name' ) );
        $o['separator']   = sanitize_text_field( $in['separator'] ?? '-' );
        return $o;
    }

    public function page() {
        $s   = get_option( 'gml_seo', [] );
        $tab = $_GET['tab'] ?? 'settings';
        ?>
        <div class="wrap">
            <h1>🤖 GML AI SEO</h1>
            <nav class="nav-tab-wrapper">
                <a href="?page=gml-seo&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">⚙️ Settings</a>
                <a href="?page=gml-seo&tab=performance" class="nav-tab <?php echo $tab === 'performance' ? 'nav-tab-active' : ''; ?>">⚡ Performance</a>
                <a href="?page=gml-seo&tab=code" class="nav-tab <?php echo $tab === 'code' ? 'nav-tab-active' : ''; ?>">💉 Code Injection</a>
                <a href="?page=gml-seo&tab=bulk" class="nav-tab <?php echo $tab === 'bulk' ? 'nav-tab-active' : ''; ?>">🚀 Bulk Optimize</a>
                <a href="?page=gml-seo&tab=dashboard" class="nav-tab <?php echo $tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">📊 Dashboard</a>
            </nav>
            <div style="margin-top:20px;">
            <?php
            switch ( $tab ) {
                case 'performance': $this->tab_performance(); break;
                case 'code':      $this->tab_code( $s ); break;
                case 'bulk':      $this->tab_bulk(); break;
                case 'dashboard': $this->tab_dashboard(); break;
                default:          $this->tab_settings( $s ); break;
            }
            ?>
            </div>
        </div>
        <?php
    }

    // ── Settings Tab ─────────────────────────────────────────────────

    private function tab_settings( $s ) {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'gml_seo_group' ); ?>
            <table class="form-table">
                <tr>
                    <th>Gemini API Key</th>
                    <td><input type="password" name="gml_seo[gemini_key]" value="<?php echo esc_attr( $s['gemini_key'] ?? '' ); ?>" class="regular-text" autocomplete="off">
                    <p class="description">从 <a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio</a> 获取。填入后 AI 会自动为每篇文章生成最优 SEO 标题和描述。</p></td>
                </tr>
                <tr>
                    <th>AI Model</th>
                    <td><select name="gml_seo[model]">
                        <?php foreach ( [ 'gemini-2.5-flash' => 'Gemini 2.5 Flash (推荐)', 'gemini-2.5-pro' => 'Gemini 2.5 Pro (最佳质量)', 'gemini-2.0-flash' => 'Gemini 2.0 Flash' ] as $v => $l ) : ?>
                            <option value="<?php echo $v; ?>" <?php selected( $s['model'] ?? '', $v ); ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select></td>
                </tr>
                <tr>
                    <th>Site Name</th>
                    <td><input type="text" name="gml_seo[site_name]" value="<?php echo esc_attr( $s['site_name'] ?? '' ); ?>" class="regular-text">
                    <p class="description">显示在 SEO 标题末尾，如 "文章标题 - 网站名"</p></td>
                </tr>
                <tr>
                    <th>Title Separator</th>
                    <td><select name="gml_seo[separator]">
                        <?php foreach ( [ '-', '|', '–', '—', '·', '»', '/' ] as $sep ) : ?>
                            <option value="<?php echo $sep; ?>" <?php selected( $s['separator'] ?? '-', $sep ); ?>><?php echo $sep; ?></option>
                        <?php endforeach; ?>
                    </select></td>
                </tr>
                <tr>
                    <th>Google Analytics 4</th>
                    <td><input type="text" name="gml_seo[ga_id]" value="<?php echo esc_attr( $s['ga_id'] ?? '' ); ?>" class="regular-text" placeholder="G-XXXXXXXXXX"></td>
                </tr>
                <tr>
                    <th>Google Tag Manager</th>
                    <td><input type="text" name="gml_seo[gtm_id]" value="<?php echo esc_attr( $s['gtm_id'] ?? '' ); ?>" class="regular-text" placeholder="GTM-XXXXXXX"></td>
                </tr>
                <tr>
                    <th>Google AdSense</th>
                    <td><input type="text" name="gml_seo[adsense_id]" value="<?php echo esc_attr( $s['adsense_id'] ?? '' ); ?>" class="regular-text" placeholder="ca-pub-XXXXXXXXXXXXXXXX"></td>
                </tr>
            </table>
            <?php submit_button( '保存设置' ); ?>
        </form>
        <?php
    }

    // ── Performance Tab ──────────────────────────────────────────────

    private function tab_performance() {
        $checks = [
            [ '🟢', '移除 Emoji 脚本和样式', '节省 ~10KB，WordPress 默认加载的 emoji 检测脚本对绝大多数网站无用。', 'always' ],
            [ '🟢', '移除 Dashicons CSS（未登录用户）', '节省 ~46KB，Dashicons 是后台图标字体，前端访客不需要。', 'always' ],
            [ '🟢', '移除 oEmbed 嵌入脚本', '节省 ~6KB，wp-embed.min.js 用于嵌入预览，大多数网站不需要。', 'always' ],
            [ '🟢', '移除 RSD/WLW/Shortlink/REST 链接', '清理 <head> 中的无用 link 标签，减少 HTML 体积。', 'always' ],
            [ '🟢', '隐藏 WordPress 版本号', '移除 <meta name="generator"> 标签，安全 + 减少信息泄露。', 'always' ],
            [ '🟢', '禁用 XML-RPC', '关闭 xmlrpc.php 端点，防止暴力破解攻击，无 SEO 影响。', 'always' ],
            [ '🟢', '禁用自我 Pingback', '防止 WordPress 向自己发送 pingback，浪费服务器资源。', 'always' ],
            [ '🟢', '移除 Gutenberg 全局样式', '移除未使用的 Gutenberg 块库内联 CSS。', 'always' ],
            [ '🟢', 'Defer 非关键 JavaScript', 'Google Lighthouse 建议：消除渲染阻塞资源。自动跳过 jQuery 等关键脚本。', 'always' ],
            [ '🟢', '图片 Lazy Loading', '首屏前 2 张图片正常加载，其余添加 loading="lazy"，减少初始请求。', 'always' ],
            [ '🟢', '自动补全图片 width/height', 'Google Core Web Vitals: 防止 CLS（累积布局偏移），图片加载时不会导致页面跳动。', 'always' ],
            [ '🟢', 'iframe Lazy Loading', 'YouTube、Google Maps 等 iframe 添加 loading="lazy"，减少初始加载。', 'always' ],
            [ '🟢', '首图 fetchpriority="high"', 'Google LCP 优化：告诉浏览器优先加载第一张内容图片。', 'always' ],
            [ '🟢', 'Preload 特色图片', '在 <head> 中 preload 文章特色图片，加速 LCP（最大内容绘制）。', 'always' ],
            [ '🟢', '自动 Preconnect 外部域名', '检测 Google Fonts、GA、GTM 等外部资源，提前建立连接减少延迟。', 'always' ],
            [ '🟢', 'DNS Prefetch', '对 Gravatar 等外部域名提前做 DNS 解析。', 'always' ],
        ];
        ?>
        <h2>⚡ 性能优化状态</h2>
        <p>以下优化已自动启用，遵循 <a href="https://developers.google.com/search/docs/fundamentals/seo-starter-guide" target="_blank">Google SEO 指南</a> 和 <a href="https://web.dev/vitals/" target="_blank">Core Web Vitals</a> 最佳实践。所有优化都是安全的，不会破坏网站功能。</p>

        <table class="wp-list-table widefat fixed striped" style="max-width:900px;">
            <thead>
                <tr>
                    <th style="width:40px;">状态</th>
                    <th style="width:280px;">优化项</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $checks as $c ) : ?>
                <tr>
                    <td><?php echo $c[0]; ?></td>
                    <td><strong><?php echo esc_html( $c[1] ); ?></strong></td>
                    <td style="font-size:13px;color:#555;"><?php echo $c[2]; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:20px;padding:16px;background:#f0f6fc;border:1px solid #c3d9ed;border-radius:6px;max-width:900px;">
            <h3 style="margin-top:0;">🛡️ 为什么不做更激进的优化？</h3>
            <p style="font-size:13px;color:#444;line-height:1.7;">
                Google 官方 SEO 指南强调"以用户为本"。以下优化虽然能进一步提升 Lighthouse 分数，但有破坏网站的风险，我们选择不自动执行：
            </p>
            <ul style="font-size:13px;color:#555;line-height:1.8;">
                <li><strong>移除未使用的 CSS</strong> — 可能导致页面样式错乱，特别是动态内容（WooCommerce、弹窗、手风琴等）</li>
                <li><strong>延迟所有 JavaScript</strong> — 可能导致交互功能失效（表单验证、购物车、滑块等）</li>
                <li><strong>禁用 Google Fonts</strong> — 可能导致字体回退，影响品牌视觉一致性</li>
                <li><strong>内联关键 CSS</strong> — 增加 HTML 体积，对有缓存的回访用户反而更慢</li>
            </ul>
            <p style="font-size:13px;color:#444;">
                如果你需要这些高级优化，建议使用专业性能插件（如 Perfmatters、WP Rocket）配合本插件使用。
            </p>
        </div>
        <?php
    }

    // ── Code Injection Tab ───────────────────────────────────────────

    private function tab_code( $s ) {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'gml_seo_group' ); ?>
            <?php // Hidden fields to preserve other settings ?>
            <input type="hidden" name="gml_seo[gemini_key]" value="<?php echo esc_attr( $s['gemini_key'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[model]" value="<?php echo esc_attr( $s['model'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[ga_id]" value="<?php echo esc_attr( $s['ga_id'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[gtm_id]" value="<?php echo esc_attr( $s['gtm_id'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[adsense_id]" value="<?php echo esc_attr( $s['adsense_id'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[site_name]" value="<?php echo esc_attr( $s['site_name'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[separator]" value="<?php echo esc_attr( $s['separator'] ?? '' ); ?>">

            <h2>自定义代码注入</h2>
            <p>在页面的不同位置插入自定义代码（验证码、像素追踪、自定义 CSS/JS 等）。</p>
            <table class="form-table">
                <tr>
                    <th>&lt;head&gt; 代码</th>
                    <td><textarea name="gml_seo[head_code]" rows="6" class="large-text code"><?php echo esc_textarea( $s['head_code'] ?? '' ); ?></textarea>
                    <p class="description">插入到 &lt;head&gt; 标签内。适合：Google Search Console 验证、Facebook Pixel、自定义 CSS。</p></td>
                </tr>
                <tr>
                    <th>&lt;body&gt; 开头代码</th>
                    <td><textarea name="gml_seo[body_code]" rows="4" class="large-text code"><?php echo esc_textarea( $s['body_code'] ?? '' ); ?></textarea>
                    <p class="description">插入到 &lt;body&gt; 标签之后。适合：GTM noscript 备用、聊天插件。</p></td>
                </tr>
                <tr>
                    <th>&lt;/body&gt; 前代码</th>
                    <td><textarea name="gml_seo[footer_code]" rows="4" class="large-text code"><?php echo esc_textarea( $s['footer_code'] ?? '' ); ?></textarea>
                    <p class="description">插入到 &lt;/body&gt; 之前。适合：统计脚本、延迟加载的 JS。</p></td>
                </tr>
            </table>
            <?php submit_button( '保存代码' ); ?>
        </form>
        <?php
    }

    // ── Bulk Optimize Tab ────────────────────────────────────────────

    private function tab_bulk() {
        if ( ! GML_SEO::opt( 'gemini_key' ) ) {
            echo '<div class="notice notice-warning"><p>⚠️ 请先在 Settings 标签页配置 Gemini API Key。</p></div>';
            return;
        }

        global $wpdb;
        $posts = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_type
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_gml_seo_generated'
             WHERE p.post_status = 'publish'
               AND p.post_type IN ('post','page','product')
               AND pm.meta_id IS NULL
             ORDER BY p.post_date DESC LIMIT 200"
        );
        $count = count( $posts );
        ?>
        <h2>🚀 批量 AI 优化</h2>
        <p>共 <strong><?php echo $count; ?></strong> 篇已发布内容尚未被 AI 优化。点击开始后，AI 会自动为每篇生成最优 SEO 标题、描述和关键词。</p>

        <?php if ( $count > 0 ) : ?>
        <button id="gml-bulk-start" class="button button-primary button-hero">🚀 开始批量优化</button>
        <button id="gml-bulk-stop" class="button button-hero" style="display:none;">⏹ 停止</button>
        <div id="gml-bulk-progress" style="display:none;background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:4px;margin-top:15px;">
            <div><span id="gml-bulk-cur">0</span> / <span id="gml-bulk-tot"><?php echo $count; ?></span></div>
            <div style="background:#e0e0e0;border-radius:4px;height:24px;margin:10px 0;overflow:hidden;">
                <div id="gml-bulk-bar" style="background:#2271b1;height:100%;width:0%;transition:width .3s;"></div>
            </div>
            <div id="gml-bulk-log" style="max-height:300px;overflow-y:auto;font-size:13px;color:#666;"></div>
        </div>
        <script>
        (function(){
            var posts=<?php echo wp_json_encode(array_map(function($p){return['id'=>$p->ID,'t'=>$p->post_title];}, $posts)); ?>;
            var run=false,i=0;
            document.getElementById('gml-bulk-start').onclick=function(){
                run=true;i=0;this.style.display='none';
                document.getElementById('gml-bulk-stop').style.display='';
                document.getElementById('gml-bulk-progress').style.display='';
                next();
            };
            document.getElementById('gml-bulk-stop').onclick=function(){
                run=false;this.style.display='none';
                document.getElementById('gml-bulk-start').style.display='';
            };
            function next(){
                if(!run||i>=posts.length){run=false;document.getElementById('gml-bulk-stop').style.display='none';document.getElementById('gml-bulk-start').style.display='';if(i>=posts.length)log('✅ 全部完成！');return;}
                var p=posts[i];log('⏳ '+p.t+'...');
                var fd=new FormData();fd.append('action','gml_seo_bulk');fd.append('post_id',p.id);fd.append('_wpnonce','<?php echo wp_create_nonce("gml_seo_bulk"); ?>');
                fetch(ajaxurl,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
                    i++;document.getElementById('gml-bulk-cur').textContent=i;
                    document.getElementById('gml-bulk-bar').style.width=Math.round(i/posts.length*100)+'%';
                    log(d.success?'✅ '+p.t:'❌ '+p.t+' — '+(d.data||'Error'));
                    setTimeout(next,800);
                }).catch(e=>{log('❌ '+p.t+' — '+e.message);i++;setTimeout(next,1500);});
            }
            function log(m){var el=document.getElementById('gml-bulk-log');el.innerHTML+='<div>'+m+'</div>';el.scrollTop=el.scrollHeight;}
        })();
        </script>
        <?php else : ?>
            <p style="padding:20px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;">🎉 所有已发布内容都已被 AI 优化！</p>
        <?php endif;
    }

    // ── Dashboard Tab ────────────────────────────────────────────────

    private function tab_dashboard() {
        global $wpdb;

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page','product')" );
        $optimized = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_gml_seo_generated'" );
        $pct = $total > 0 ? round( $optimized / $total * 100 ) : 0;

        $recent = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_type, pm.meta_value as gen_time
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_gml_seo_generated'
             WHERE p.post_status = 'publish'
             ORDER BY pm.meta_value DESC LIMIT 30"
        );
        ?>
        <div style="display:flex;gap:20px;margin-bottom:20px;">
            <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:4px;flex:1;text-align:center;">
                <div style="font-size:36px;font-weight:bold;color:#2271b1;"><?php echo $optimized; ?> / <?php echo $total; ?></div>
                <div style="color:#666;">AI 已优化</div>
            </div>
            <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:4px;flex:1;text-align:center;">
                <div style="font-size:36px;font-weight:bold;color:<?php echo $pct >= 80 ? '#00a32a' : ($pct >= 50 ? '#dba617' : '#d63638'); ?>;"><?php echo $pct; ?>%</div>
                <div style="color:#666;">优化覆盖率</div>
            </div>
        </div>

        <?php if ( $recent ) : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th>页面</th><th style="width:100px;">类型</th><th>AI 标题</th><th style="width:160px;">优化时间</th><th style="width:80px;">操作</th></tr></thead>
            <tbody>
            <?php foreach ( $recent as $r ) :
                $title = get_post_meta( $r->ID, '_gml_seo_title', true );
            ?>
            <tr>
                <td><?php echo esc_html( $r->post_title ); ?></td>
                <td><?php echo $r->post_type; ?></td>
                <td style="color:#2271b1;font-size:12px;"><?php echo esc_html( $title ); ?></td>
                <td><?php echo esc_html( $r->gen_time ); ?></td>
                <td><a href="<?php echo get_edit_post_link( $r->ID ); ?>" class="button button-small">编辑</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif;
    }
}
