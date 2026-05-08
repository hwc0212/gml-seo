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
        $o['engine']          = in_array( $in['engine'] ?? '', [ 'gemini', 'deepseek' ] ) ? $in['engine'] : 'gemini';
        $o['gemini_key']      = sanitize_text_field( $in['gemini_key'] ?? '' );
        $o['model']           = sanitize_text_field( $in['model'] ?? 'gemini-2.5-flash' );
        $o['deepseek_key']    = sanitize_text_field( $in['deepseek_key'] ?? '' );
        $o['deepseek_model']  = sanitize_text_field( $in['deepseek_model'] ?? 'deepseek-chat' );
        $o['deepseek_base_url'] = esc_url_raw( $in['deepseek_base_url'] ?? 'https://api.deepseek.com' );
        $o['ga_id']           = sanitize_text_field( $in['ga_id'] ?? '' );
        $o['gtm_id']          = sanitize_text_field( $in['gtm_id'] ?? '' );
        $o['adsense_id']      = sanitize_text_field( $in['adsense_id'] ?? '' );
        $o['head_code']       = $in['head_code'] ?? '';
        $o['body_code']       = $in['body_code'] ?? '';
        $o['footer_code']     = $in['footer_code'] ?? '';
        $o['site_name']       = sanitize_text_field( $in['site_name'] ?? get_bloginfo( 'name' ) );
        $o['separator']       = sanitize_text_field( $in['separator'] ?? '-' );
        $o['audit_frequency'] = in_array( $in['audit_frequency'] ?? '', [ 'weekly', 'daily', 'monthly', 'disabled' ] ) ? $in['audit_frequency'] : 'weekly';
        $o['indexnow_enabled']       = ! empty( $in['indexnow_enabled'] ) ? 1 : 0;
        $o['google_service_account'] = trim( $in['google_service_account'] ?? '' );
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
                <a href="?page=gml-seo&tab=automation" class="nav-tab <?php echo $tab === 'automation' ? 'nav-tab-active' : ''; ?>">🤖 Automation</a>
                <a href="?page=gml-seo&tab=translate" class="nav-tab <?php echo $tab === 'translate' ? 'nav-tab-active' : ''; ?>">🌐 Translation</a>
                <a href="?page=gml-seo&tab=performance" class="nav-tab <?php echo $tab === 'performance' ? 'nav-tab-active' : ''; ?>">⚡ Performance</a>
                <a href="?page=gml-seo&tab=code" class="nav-tab <?php echo $tab === 'code' ? 'nav-tab-active' : ''; ?>">💉 Code Injection</a>
                <a href="?page=gml-seo&tab=bulk" class="nav-tab <?php echo $tab === 'bulk' ? 'nav-tab-active' : ''; ?>">🚀 Bulk Optimize</a>
                <a href="?page=gml-seo&tab=dashboard" class="nav-tab <?php echo $tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">📊 Dashboard</a>
            </nav>
            <div style="margin-top:20px;">
            <?php
            switch ( $tab ) {
                case 'automation': $this->tab_automation( $s ); break;
                case 'translate': $this->tab_translate(); break;
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
        $engine = $s['engine'] ?? 'gemini';
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'gml_seo_group' ); ?>
            <table class="form-table">
                <tr>
                    <th>AI 引擎</th>
                    <td>
                        <select name="gml_seo[engine]" id="gml-seo-engine">
                            <option value="gemini" <?php selected( $engine, 'gemini' ); ?>>Google Gemini（海外推荐）</option>
                            <option value="deepseek" <?php selected( $engine, 'deepseek' ); ?>>DeepSeek（中国大陆推荐）</option>
                        </select>
                        <p class="description">中国大陆无法访问 Google API，请选择 DeepSeek。</p>
                    </td>
                </tr>

                <!-- Gemini fields -->
                <tr class="gml-seo-engine-gemini" <?php echo $engine !== 'gemini' ? 'style="display:none;"' : ''; ?>>
                    <th>Gemini API Key</th>
                    <td><input type="password" name="gml_seo[gemini_key]" value="<?php echo esc_attr( $s['gemini_key'] ?? '' ); ?>" class="regular-text" autocomplete="off">
                    <p class="description">从 <a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio</a> 获取。</p></td>
                </tr>
                <tr class="gml-seo-engine-gemini" <?php echo $engine !== 'gemini' ? 'style="display:none;"' : ''; ?>>
                    <th>Gemini Model</th>
                    <td><select name="gml_seo[model]">
                        <?php foreach ( [ 'gemini-2.5-flash' => 'Gemini 2.5 Flash (推荐)', 'gemini-2.5-pro' => 'Gemini 2.5 Pro (最佳质量)', 'gemini-2.0-flash' => 'Gemini 2.0 Flash' ] as $v => $l ) : ?>
                            <option value="<?php echo $v; ?>" <?php selected( $s['model'] ?? '', $v ); ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select></td>
                </tr>

                <!-- DeepSeek fields -->
                <tr class="gml-seo-engine-deepseek" <?php echo $engine !== 'deepseek' ? 'style="display:none;"' : ''; ?>>
                    <th>DeepSeek API Key</th>
                    <td><input type="password" name="gml_seo[deepseek_key]" value="<?php echo esc_attr( $s['deepseek_key'] ?? '' ); ?>" class="regular-text" autocomplete="off">
                    <p class="description">从 <a href="https://platform.deepseek.com/api_keys" target="_blank">DeepSeek 开放平台</a> 获取。</p></td>
                </tr>
                <tr class="gml-seo-engine-deepseek" <?php echo $engine !== 'deepseek' ? 'style="display:none;"' : ''; ?>>
                    <th>DeepSeek Model</th>
                    <td><select name="gml_seo[deepseek_model]">
                        <?php foreach ( [ 'deepseek-chat' => 'DeepSeek Chat (推荐)', 'deepseek-reasoner' => 'DeepSeek Reasoner (深度推理)' ] as $v => $l ) : ?>
                            <option value="<?php echo $v; ?>" <?php selected( $s['deepseek_model'] ?? '', $v ); ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select></td>
                </tr>
                <tr class="gml-seo-engine-deepseek" <?php echo $engine !== 'deepseek' ? 'style="display:none;"' : ''; ?>>
                    <th>DeepSeek API Base URL</th>
                    <td><input type="url" name="gml_seo[deepseek_base_url]" value="<?php echo esc_attr( $s['deepseek_base_url'] ?? 'https://api.deepseek.com' ); ?>" class="regular-text">
                    <p class="description">默认 https://api.deepseek.com，如使用代理可修改。</p></td>
                </tr>

                <!-- Common fields -->
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
        <script>
        document.getElementById('gml-seo-engine').addEventListener('change', function(){
            var v = this.value;
            document.querySelectorAll('.gml-seo-engine-gemini').forEach(function(el){ el.style.display = v === 'gemini' ? '' : 'none'; });
            document.querySelectorAll('.gml-seo-engine-deepseek').forEach(function(el){ el.style.display = v === 'deepseek' ? '' : 'none'; });
        });
        </script>
        <?php
    }

    // ── Translate Tab (bundled GML Translate module) ─────────────────

    private function tab_translate() {
        // If standalone GML Translate is still active, the bundled module
        // isn't loaded (to prevent class redeclaration fatals). Show guidance.
        if ( class_exists( 'GML_SEO_Translate_Bootstrap' ) && GML_SEO_Translate_Bootstrap::standalone_is_active() ) {
            $deactivate_url = wp_nonce_url(
                admin_url( 'plugins.php?action=deactivate&plugin=gml-translate/gml-translate.php' ),
                'deactivate-plugin_gml-translate/gml-translate.php'
            );
            echo '<div class="notice notice-warning inline"><p>';
            echo '<strong>⚠️ 独立的 GML Translate 插件正在运行。</strong> ';
            echo '请先停用它，本插件将自动接管翻译功能，所有数据（翻译记忆库、语言配置、术语表）会完整保留。';
            echo '</p><p><a class="button button-primary" href="' . esc_url( $deactivate_url ) . '">停用独立 GML Translate 插件</a></p></div>';
            return;
        }

        if ( ! class_exists( 'GML_Admin_Settings' ) ) {
            echo '<div class="notice notice-error"><p>Translation module failed to load.</p></div>';
            return;
        }

        // Check for standalone GML Translate migration notice
        if ( get_transient( 'gml_seo_translate_migrated' ) ) {
            delete_transient( 'gml_seo_translate_migrated' );
            echo '<div class="notice notice-success"><p>';
            echo '<strong>✅ GML Translate 已合并到本插件。</strong> 原独立插件已自动停用，所有翻译数据（设置、翻译记忆库、语言配置）已无缝保留，无需重新翻译。';
            echo '</p></div>';
        }

        // Detect if languages are configured
        $langs = get_option( 'gml_languages', [] );
        if ( empty( $langs ) ) {
            ?>
            <div class="notice notice-info"><p>
                <strong>🌐 多语言翻译 + SEO 一体化</strong><br>
                配置目标语言后，AI 不只是翻译内容 —— 它会根据目标语言用户的搜索习惯重新优化 SEO 标题、描述、FAQ。
                翻译数据与 SEO 数据共用同一个 AI Key 和引擎。
            </p></div>
            <?php
        }

        // Delegate to the bundled settings renderer
        $settings = new GML_Admin_Settings();
        $settings->render_page();
    }

    // ── Automation Tab (Scheduled Audit + Indexing) ──────────────────

    private function tab_automation( $s ) {
        $freq     = $s['audit_frequency'] ?? 'weekly';
        $next_run = GML_SEO_Health_Monitor::get_next_run();
        $report   = GML_SEO_Health_Monitor::get_report();
        $queue    = GML_SEO_Health_Monitor::get_queue();
        $log      = GML_SEO_Health_Monitor::get_log( 10 );
        $idx_key  = GML_SEO_Indexing::get_indexnow_key();
        ?>
        <style>
        .gml-auto-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin:20px 0;}
        .gml-auto-card{background:#fff;padding:18px;border:1px solid #ccd0d4;border-radius:6px;}
        .gml-auto-card h4{margin:0 0 8px;font-size:13px;color:#666;font-weight:600;text-transform:uppercase;letter-spacing:.3px;}
        .gml-auto-card .v{font-size:26px;font-weight:700;line-height:1.2;}
        .gml-auto-card .s{color:#6b7280;font-size:13px;margin-top:4px;}
        .gml-log{background:#1e1e1e;color:#a0a0a0;font-family:monospace;font-size:12px;padding:12px;border-radius:4px;max-height:280px;overflow-y:auto;line-height:1.6;}
        .gml-log time{color:#6ee7b7;margin-right:8px;}
        </style>

        <h2>🤖 自动化 SEO 引擎</h2>
        <p>插件会定时全站扫描，自动识别需要重新优化的内容并排队处理。遵循 Google 2025 最新指南：停滞内容是 HCS 核心算法的负面信号，特别是时效性敏感的话题。</p>

        <div class="gml-auto-grid">
            <div class="gml-auto-card">
                <h4>🗓 下次自动扫描</h4>
                <div class="v" style="color:#2563eb;font-size:16px;">
                    <?php echo $next_run ? esc_html( human_time_diff( time(), $next_run ) . ' 后' ) : '未安排'; ?>
                </div>
                <div class="s"><?php echo $next_run ? esc_html( wp_date( 'Y-m-d H:i', $next_run ) ) : ''; ?></div>
            </div>
            <div class="gml-auto-card">
                <h4>📋 待重新优化队列</h4>
                <div class="v" style="color:<?php echo count( $queue ) > 0 ? '#dba617' : '#00a32a'; ?>;"><?php echo count( $queue ); ?></div>
                <div class="s">按优先级自动处理</div>
            </div>
            <div class="gml-auto-card">
                <h4>📊 最近扫描</h4>
                <div class="v" style="color:#0891b2;font-size:16px;">
                    <?php echo ! empty( $report['last_run'] ) ? esc_html( human_time_diff( strtotime( $report['last_run'] ), time() ) . ' 前' ) : '尚未运行'; ?>
                </div>
                <div class="s"><?php echo ! empty( $report['stats']['total'] ) ? '扫描 ' . (int) $report['stats']['total'] . ' 篇' : ''; ?></div>
            </div>
            <div class="gml-auto-card">
                <h4>🚀 搜索引擎通知</h4>
                <div class="v" style="color:#6f42c1;font-size:14px;">
                    IndexNow <?php echo GML_SEO::opt( 'indexnow_enabled', 1 ) ? '✓' : '✗'; ?><br>
                    Google API <?php echo GML_SEO::opt( 'google_service_account' ) ? '✓' : '✗'; ?>
                </div>
                <div class="s">发布/更新即推送</div>
            </div>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields( 'gml_seo_group' ); ?>
            <?php
            // Preserve all other settings
            $preserve = [ 'engine', 'gemini_key', 'model', 'deepseek_key', 'deepseek_model',
                'deepseek_base_url', 'ga_id', 'gtm_id', 'adsense_id', 'head_code', 'body_code',
                'footer_code', 'site_name', 'separator' ];
            foreach ( $preserve as $k ) {
                echo '<input type="hidden" name="gml_seo[' . esc_attr( $k ) . ']" value="' . esc_attr( $s[ $k ] ?? '' ) . '">';
            }
            ?>

            <h3>🗓 定时健康扫描</h3>
            <table class="form-table">
                <tr>
                    <th>扫描频率</th>
                    <td>
                        <select name="gml_seo[audit_frequency]">
                            <option value="weekly" <?php selected( $freq, 'weekly' ); ?>>每周一次（推荐）</option>
                            <option value="daily" <?php selected( $freq, 'daily' ); ?>>每天一次（活跃站点）</option>
                            <option value="monthly" <?php selected( $freq, 'monthly' ); ?>>每月一次</option>
                            <option value="disabled" <?php selected( $freq, 'disabled' ); ?>>禁用自动扫描</option>
                        </select>
                        <p class="description">扫描逻辑：<br>
                            ▸ 从未分析 → 优先级 90<br>
                            ▸ 缺失标题/描述 → 优先级 80<br>
                            ▸ 内容变更但未重新分析 → 优先级 70<br>
                            ▸ SEO 分数 &lt; 60 → 优先级 60<br>
                            ▸ 超过新鲜度阈值（新闻 30 天、教程 90 天、普通 180 天、页面 365 天）→ 优先级 40
                        </p>
                    </td>
                </tr>
            </table>

            <h3>🚀 实时索引通知</h3>
            <table class="form-table">
                <tr>
                    <th>IndexNow 协议</th>
                    <td>
                        <label>
                            <input type="checkbox" name="gml_seo[indexnow_enabled]" value="1" <?php checked( ! empty( $s['indexnow_enabled'] ) || ! isset( $s['indexnow_enabled'] ) ); ?>>
                            启用 IndexNow（Bing、Yandex、Seznam、Naver 免配置支持）
                        </label>
                        <p class="description">
                            插件已自动生成验证密钥：<code><?php echo esc_html( $idx_key ); ?></code><br>
                            密钥文件 URL：<a href="<?php echo esc_url( home_url( '/' . $idx_key . '.txt' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/' . $idx_key . '.txt' ) ); ?></a><br>
                            无需任何操作，内容发布/更新时会自动推送给支持的搜索引擎。
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Google Indexing API</th>
                    <td>
                        <textarea name="gml_seo[google_service_account]" rows="6" class="large-text code" placeholder='{"type":"service_account","project_id":"...","private_key":"...",...}'><?php echo esc_textarea( $s['google_service_account'] ?? '' ); ?></textarea>
                        <p class="description">
                            粘贴 Google Cloud 服务账号 JSON。配置步骤：
                            <a href="https://developers.google.com/search/apis/indexing-api/v3/prereqs" target="_blank">创建服务账号</a> →
                            添加 <code>Indexing API</code> 权限 →
                            在 <a href="https://search.google.com/search-console/users" target="_blank">Search Console</a> 里把该服务账号邮箱加为 <strong>Owner</strong>。<br>
                            留空则禁用。配置后，内容变更会通过官方 API 通知 Google，大幅加快收录。
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button( '保存自动化设置' ); ?>
        </form>

        <h3>⚙️ 手动控制</h3>
        <p>
            <button type="button" id="gml-audit-now" class="button button-primary">🔍 立即扫描全站</button>
            <button type="button" id="gml-process-now" class="button" <?php echo empty( $queue ) ? 'disabled' : ''; ?>>⚡ 立即处理队列</button>
        </p>

        <?php if ( ! empty( $report['stats'] ) ) : $st = $report['stats']; ?>
        <h3>📊 上次扫描报告</h3>
        <table class="widefat" style="max-width:700px;">
            <tbody>
                <tr><td>总扫描页面</td><td><strong><?php echo (int) $st['total']; ?></strong></td></tr>
                <tr><td>🟢 健康</td><td><?php echo (int) $st['healthy']; ?></td></tr>
                <tr><td>⚪ 从未分析</td><td><?php echo (int) $st['never']; ?></td></tr>
                <tr><td>⚠️ 缺失 SEO 数据</td><td><?php echo (int) $st['missing']; ?></td></tr>
                <tr><td>🔄 内容变更</td><td><?php echo (int) $st['changed']; ?></td></tr>
                <tr><td>📉 低分 (&lt;60)</td><td><?php echo (int) $st['low_score']; ?></td></tr>
                <tr><td>⏰ 内容过时</td><td><?php echo (int) $st['stale']; ?></td></tr>
                <tr><td><strong>队列总数</strong></td><td><strong><?php echo (int) $st['queued']; ?></strong></td></tr>
            </tbody>
        </table>
        <?php endif; ?>

        <h3>📝 运行日志</h3>
        <div class="gml-log">
            <?php if ( empty( $log ) ) : ?>
                <em style="color:#666;">暂无日志。扫描运行后会显示详细记录。</em>
            <?php else : foreach ( $log as $entry ) : ?>
                <div><time><?php echo esc_html( $entry['time'] ); ?></time><?php echo esc_html( $entry['message'] ); ?></div>
            <?php endforeach; endif; ?>
        </div>

        <script>
        (function(){
            var nonce = '<?php echo wp_create_nonce( 'gml_seo_admin' ); ?>';
            document.getElementById('gml-audit-now').addEventListener('click', function(){
                var btn = this;
                btn.disabled = true; btn.textContent = '⏳ 扫描中...';
                var fd = new FormData();
                fd.append('action', 'gml_seo_run_audit');
                fd.append('_wpnonce', nonce);
                fetch(ajaxurl, { method:'POST', body:fd }).then(r=>r.json()).then(d=>{
                    if (d.success) { alert('✅ 扫描完成！'); window.location.reload(); }
                    else { alert('❌ ' + (d.data || '扫描失败')); btn.disabled = false; btn.textContent = '🔍 立即扫描全站'; }
                });
            });
            var pbtn = document.getElementById('gml-process-now');
            if (pbtn) pbtn.addEventListener('click', function(){
                this.disabled = true; this.textContent = '⏳ 处理中...';
                var fd = new FormData();
                fd.append('action', 'gml_seo_process_now');
                fd.append('_wpnonce', nonce);
                fetch(ajaxurl, { method:'POST', body:fd }).then(r=>r.json()).then(d=>{
                    if (d.success) { alert('✅ 本批处理完成，剩余 ' + d.data.remaining + ' 项'); window.location.reload(); }
                    else { alert('❌ ' + (d.data || '处理失败')); window.location.reload(); }
                });
            });
        })();
        </script>
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
            [ '🆕', 'Google Fonts font-display: swap', 'Google Lighthouse："Ensure text remains visible during webfont load"。自动给 Google Fonts URL 加 display=swap，字体加载时先用系统字体显示，避免不可见文字。', 'always' ],
            [ '🆕', 'HTML 输出压缩', '移除多余空白、HTML 注释（保留条件注释），HTML 体积减少 5-15%。智能跳过 <pre>、<textarea>、<script>、<style>、<code> 内部。', 'always' ],
            [ '🆕', 'HTTP Link 预加载头', '把 <link rel="preload"> 和 preconnect 同时以 HTTP Link 头发送，支持 HTTP/2 Early Hints（Cloudflare / Fastly 会转为 103 状态，浏览器能在 HTML 还未完整到达时就开始连接）。', 'always' ],
            [ '🆕', '禁用 oEmbed REST 端点', '/wp-json/oembed/* 几乎无人使用但被机器人频繁爬取，禁用可减少服务器 CPU 压力。', 'always' ],
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
            <input type="hidden" name="gml_seo[engine]" value="<?php echo esc_attr( $s['engine'] ?? 'gemini' ); ?>">
            <input type="hidden" name="gml_seo[gemini_key]" value="<?php echo esc_attr( $s['gemini_key'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[model]" value="<?php echo esc_attr( $s['model'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[deepseek_key]" value="<?php echo esc_attr( $s['deepseek_key'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[deepseek_model]" value="<?php echo esc_attr( $s['deepseek_model'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[deepseek_base_url]" value="<?php echo esc_attr( $s['deepseek_base_url'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[ga_id]" value="<?php echo esc_attr( $s['ga_id'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[gtm_id]" value="<?php echo esc_attr( $s['gtm_id'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[adsense_id]" value="<?php echo esc_attr( $s['adsense_id'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[site_name]" value="<?php echo esc_attr( $s['site_name'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[separator]" value="<?php echo esc_attr( $s['separator'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[audit_frequency]" value="<?php echo esc_attr( $s['audit_frequency'] ?? 'weekly' ); ?>">
            <input type="hidden" name="gml_seo[indexnow_enabled]" value="<?php echo (int) ( $s['indexnow_enabled'] ?? 1 ); ?>">
            <input type="hidden" name="gml_seo[google_service_account]" value="<?php echo esc_attr( $s['google_service_account'] ?? '' ); ?>">

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
        if ( ! GML_SEO::has_ai_key() ) {
            echo '<div class="notice notice-warning"><p>⚠️ 请先在 Settings 标签页配置 AI API Key（Gemini 或 DeepSeek）。</p></div>';
            return;
        }

        global $wpdb;

        $force = ! empty( $_GET['force'] );

        if ( $force ) {
            $posts = $wpdb->get_results(
                "SELECT p.ID, p.post_title, p.post_type
                 FROM {$wpdb->posts} p
                 WHERE p.post_status = 'publish'
                   AND p.post_type IN ('post','page','product')
                 ORDER BY p.post_date DESC LIMIT 500"
            );
        } else {
            $posts = $wpdb->get_results(
                "SELECT p.ID, p.post_title, p.post_type
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_gml_seo_generated'
                 WHERE p.post_status = 'publish'
                   AND p.post_type IN ('post','page','product')
                   AND pm.meta_id IS NULL
                 ORDER BY p.post_date DESC LIMIT 200"
            );
        }
        $count = count( $posts );
        ?>
        <h2>🚀 批量 AI 优化</h2>
        <p>共 <strong><?php echo $count; ?></strong> 篇<?php echo $force ? '已发布内容（含已优化的，将重新生成 FAQ + 自动内链）' : '已发布内容尚未被 AI 优化'; ?>。</p>

        <p style="background:#f0f6fc;border:1px solid #c3d9ed;padding:12px;border-radius:4px;">
            <label>
                <input type="checkbox" <?php checked( $force ); ?> onchange="window.location='?page=gml-seo&tab=bulk'+(this.checked?'&force=1':'');">
                强制重新分析所有已优化页面（升级到 v1.4.0 后首次运行推荐，让旧文章获得 FAQ 和自动内链）
            </label>
        </p>

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
                if(i===0)fd.append('first','1');
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

        $with_faq   = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_gml_seo_faq'" );
        $with_links = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_gml_seo_auto_links'" );
        $index_size = count( get_option( 'gml_seo_link_index', [] ) );

        $recent = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_type, pm.meta_value as gen_time
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_gml_seo_generated'
             WHERE p.post_status = 'publish'
             ORDER BY pm.meta_value DESC LIMIT 30"
        );
        ?>
        <style>
        .gml-stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin:20px 0;}
        .gml-stat-card{background:#fff;padding:20px 16px;border:1px solid #ccd0d4;border-radius:6px;text-align:center;}
        .gml-stat-num{font-size:34px;font-weight:700;line-height:1.1;margin-bottom:6px;}
        .gml-stat-label{color:#666;font-size:13px;line-height:1.4;}
        .gml-index-panel{background:#f0f6fc;border:1px solid #c3d9ed;padding:14px 18px;border-radius:6px;margin:18px 0;display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
        .gml-index-panel strong{color:#1d2327;}
        .gml-index-panel .description{color:#555;font-size:13px;margin:0;flex:1;min-width:200px;}
        </style>

        <div class="gml-stats-grid">
            <div class="gml-stat-card">
                <div class="gml-stat-num" style="color:#2271b1;"><?php echo $optimized; ?><span style="font-size:20px;color:#999;"> / <?php echo $total; ?></span></div>
                <div class="gml-stat-label">AI 已优化</div>
            </div>
            <div class="gml-stat-card">
                <div class="gml-stat-num" style="color:<?php echo $pct >= 80 ? '#00a32a' : ($pct >= 50 ? '#dba617' : '#d63638'); ?>;"><?php echo $pct; ?>%</div>
                <div class="gml-stat-label">优化覆盖率</div>
            </div>
            <div class="gml-stat-card">
                <div class="gml-stat-num" style="color:#6f42c1;"><?php echo $with_faq; ?></div>
                <div class="gml-stat-label">FAQ Rich Result</div>
            </div>
            <div class="gml-stat-card">
                <div class="gml-stat-num" style="color:#0891b2;"><?php echo $with_links; ?></div>
                <div class="gml-stat-label">AI 自动内链</div>
            </div>
        </div>

        <div class="gml-index-panel">
            <div>
                <strong>🔗 内链候选索引：<?php echo $index_size; ?> 篇</strong>
            </div>
            <p class="description">
                索引记录站内已优化页面用于 AI 匹配内链。从 v1.3.x 升级的旧数据不会自动进入索引，需要手动重建一次。
            </p>
            <button type="button" id="gml-rebuild-index" class="button button-secondary">🔄 重建索引</button>
            <span id="gml-rebuild-msg" style="color:#00a32a;font-size:13px;display:none;"></span>
        </div>

        <script>
        document.getElementById('gml-rebuild-index').addEventListener('click', function(){
            var btn = this, msg = document.getElementById('gml-rebuild-msg');
            btn.disabled = true; btn.textContent = '⏳ 正在重建...';
            var fd = new FormData();
            fd.append('action', 'gml_seo_rebuild_index');
            fd.append('_wpnonce', '<?php echo wp_create_nonce( 'gml_seo_admin' ); ?>');
            fetch(ajaxurl, { method: 'POST', body: fd }).then(r => r.json()).then(d => {
                btn.disabled = false; btn.textContent = '🔄 重建索引';
                if (d.success) {
                    msg.textContent = '✓ 索引已重建，共收录 ' + d.data.count + ' 篇页面';
                    msg.style.display = '';
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    msg.style.color = '#d63638';
                    msg.textContent = '❌ ' + (d.data || '重建失败');
                    msg.style.display = '';
                }
            });
        });
        </script>

        <?php if ( $recent ) : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th>页面</th><th style="width:100px;">类型</th><th style="width:280px;">AI 标题</th><th style="width:160px;">优化时间</th><th style="width:80px;">操作</th></tr></thead>
            <tbody>
            <?php foreach ( $recent as $r ) :
                $title = get_post_meta( $r->ID, '_gml_seo_title', true );
            ?>
            <tr>
                <td><?php echo esc_html( $r->post_title ); ?></td>
                <td><?php echo $r->post_type; ?></td>
                <td style="color:#2271b1;font-size:12px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr( $title ); ?>"><?php echo esc_html( $title ); ?></td>
                <td><?php echo esc_html( $r->gen_time ); ?></td>
                <td><a href="<?php echo get_edit_post_link( $r->ID ); ?>" class="button button-small">编辑</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif;
    }
}
