<?php
/**
 * Admin settings — minimal setup: API key, GA, code injection, bulk optimize.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'menu' ] );
        add_action( 'admin_init', [ $this, 'register' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_notices', [ $this, 'render_conflict_notice' ] );
        add_action( 'wp_ajax_gml_seo_dismiss_conflict_notice', [ $this, 'ajax_dismiss_conflict_notice' ] );
        add_action( 'wp_ajax_gml_seo_sync_gsc', [ $this, 'ajax_sync_gsc' ] );
        add_action( 'wp_ajax_gml_seo_sync_ga4', [ $this, 'ajax_sync_ga4' ] );
        add_action( 'wp_ajax_gml_seo_test_ai_engine', [ $this, 'ajax_test_ai_engine' ] );
    }

    public function menu() {
        add_menu_page( 'GML AI SEO', 'GML AI SEO', 'manage_options', 'gml-seo', [ $this, 'page' ], 'dashicons-superhero-alt', 80 );
    }

    public function register() {
        register_setting( 'gml_seo_group', 'gml_seo', [ 'sanitize_callback' => [ $this, 'sanitize' ] ] );
        register_setting( 'gml_seo_strategy_group', 'gml_seo_strategy', [ 'sanitize_callback' => [ 'GML_SEO_Strategy', 'sanitize' ] ] );
    }

    public function sanitize( $in ) {
        // Start from the existing option so we preserve fields NOT managed
        // by the current form (e.g. gradual_mode, gradual_entered_at,
        // gradual_exited_at, conflict_notice_dismissed, and the perf_*
        // toggles when another tab is the one submitting).
        $old = get_option( 'gml_seo', [] );
        if ( ! is_array( $old ) ) {
            $old = [];
        }
        $o = $old;

        $o['engine']            = in_array( $in['engine'] ?? '', [ 'gemini', 'deepseek', 'qwen', 'openai' ], true ) ? $in['engine'] : ( $old['engine'] ?? 'gemini' );
        $o['gemini_key']        = isset( $in['gemini_key'] ) ? GML_SEO::normalize_secret_option( sanitize_text_field( $in['gemini_key'] ), $old['gemini_key'] ?? '' ) : ( $old['gemini_key'] ?? '' );
        $o['model']             = isset( $in['model'] ) ? sanitize_text_field( $in['model'] ) : ( $old['model'] ?? 'gemini-2.5-flash' );
        $o['deepseek_key']      = isset( $in['deepseek_key'] ) ? GML_SEO::normalize_secret_option( sanitize_text_field( $in['deepseek_key'] ), $old['deepseek_key'] ?? '' ) : ( $old['deepseek_key'] ?? '' );
        $o['deepseek_model']    = isset( $in['deepseek_model'] ) ? sanitize_text_field( $in['deepseek_model'] ) : ( $old['deepseek_model'] ?? 'deepseek-chat' );
        $o['deepseek_base_url'] = isset( $in['deepseek_base_url'] ) ? esc_url_raw( $in['deepseek_base_url'] ) : ( $old['deepseek_base_url'] ?? 'https://api.deepseek.com' );
        $o['qwen_key']          = isset( $in['qwen_key'] ) ? GML_SEO::normalize_secret_option( sanitize_text_field( $in['qwen_key'] ), $old['qwen_key'] ?? '' ) : ( $old['qwen_key'] ?? '' );
        $o['qwen_model']        = isset( $in['qwen_model'] ) ? sanitize_text_field( $in['qwen_model'] ) : ( $old['qwen_model'] ?? 'qwen-plus' );
        $o['qwen_base_url']     = isset( $in['qwen_base_url'] ) ? esc_url_raw( $in['qwen_base_url'] ) : ( $old['qwen_base_url'] ?? 'https://dashscope.aliyuncs.com/compatible-mode' );
        $o['openai_key']        = isset( $in['openai_key'] ) ? GML_SEO::normalize_secret_option( sanitize_text_field( $in['openai_key'] ), $old['openai_key'] ?? '' ) : ( $old['openai_key'] ?? '' );
        $o['openai_model']      = isset( $in['openai_model'] ) ? sanitize_text_field( $in['openai_model'] ) : ( $old['openai_model'] ?? 'gpt-4o-mini' );
        $o['openai_base_url']   = isset( $in['openai_base_url'] ) ? esc_url_raw( $in['openai_base_url'] ) : ( $old['openai_base_url'] ?? 'https://api.openai.com' );
        $o['ai_apply_mode']     = in_array( $in['ai_apply_mode'] ?? '', [ 'suggest', 'apply' ], true ) ? $in['ai_apply_mode'] : ( $old['ai_apply_mode'] ?? 'suggest' );
        $o['ga_id']             = isset( $in['ga_id'] ) ? sanitize_text_field( $in['ga_id'] ) : ( $old['ga_id'] ?? '' );
        $o['gtm_id']            = isset( $in['gtm_id'] ) ? sanitize_text_field( $in['gtm_id'] ) : ( $old['gtm_id'] ?? '' );
        $o['adsense_id']        = isset( $in['adsense_id'] ) ? sanitize_text_field( $in['adsense_id'] ) : ( $old['adsense_id'] ?? '' );
        $o['head_code']         = $in['head_code'] ?? ( $old['head_code'] ?? '' );
        $o['body_code']         = $in['body_code'] ?? ( $old['body_code'] ?? '' );
        $o['footer_code']       = $in['footer_code'] ?? ( $old['footer_code'] ?? '' );
        $o['site_name']         = isset( $in['site_name'] ) ? sanitize_text_field( $in['site_name'] ) : ( $old['site_name'] ?? get_bloginfo( 'name' ) );
        $o['separator']         = isset( $in['separator'] ) ? sanitize_text_field( $in['separator'] ) : ( $old['separator'] ?? '-' );
        $o['audit_frequency']   = in_array( $in['audit_frequency'] ?? '', [ 'weekly', 'daily', 'monthly', 'disabled' ] ) ? $in['audit_frequency'] : ( $old['audit_frequency'] ?? 'weekly' );

        // Automation tab checkbox + textarea (only when Automation submitted).
        if ( ! empty( $in['__automation_submitted'] ) ) {
            $o['indexnow_enabled']       = ! empty( $in['indexnow_enabled'] ) ? 1 : 0;
            $o['google_service_account'] = GML_SEO::normalize_secret_option( wp_unslash( $in['google_service_account'] ?? '' ), $old['google_service_account'] ?? '' );
        } else {
            $o['indexnow_enabled']       = isset( $in['indexnow_enabled'] ) ? ( ! empty( $in['indexnow_enabled'] ) ? 1 : 0 ) : ( $old['indexnow_enabled'] ?? 1 );
            $o['google_service_account'] = isset( $in['google_service_account'] ) ? GML_SEO::normalize_secret_option( wp_unslash( $in['google_service_account'] ), $old['google_service_account'] ?? '' ) : ( $old['google_service_account'] ?? '' );
        }

        // Performance tab toggles (only touch when Performance tab submitted).
        if ( class_exists( 'GML_SEO_Performance' ) && ! empty( $in['__perf_submitted'] ) ) {
            foreach ( GML_SEO_Performance::all_keys() as $key ) {
                $o[ $key ] = ! empty( $in[ $key ] ) ? 1 : 0;
            }
        }

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
                <a href="?page=gml-seo&tab=strategy" class="nav-tab <?php echo $tab === 'strategy' ? 'nav-tab-active' : ''; ?>">🎯 Strategy</a>
                <a href="?page=gml-seo&tab=automation" class="nav-tab <?php echo $tab === 'automation' ? 'nav-tab-active' : ''; ?>">🤖 Automation</a>
                <a href="?page=gml-seo&tab=translate" class="nav-tab <?php echo $tab === 'translate' ? 'nav-tab-active' : ''; ?>">🌐 Translation</a>
                <a href="?page=gml-seo&tab=performance" class="nav-tab <?php echo $tab === 'performance' ? 'nav-tab-active' : ''; ?>">⚡ Performance</a>
                <a href="?page=gml-seo&tab=code" class="nav-tab <?php echo $tab === 'code' ? 'nav-tab-active' : ''; ?>">💉 Code Injection</a>
                <a href="?page=gml-seo&tab=bulk" class="nav-tab <?php echo $tab === 'bulk' ? 'nav-tab-active' : ''; ?>">🚀 Bulk Optimize</a>
                <a href="?page=gml-seo&tab=migration" class="nav-tab <?php echo $tab === 'migration' ? 'nav-tab-active' : ''; ?>">📦 Migration</a>
                <a href="?page=gml-seo&tab=dashboard" class="nav-tab <?php echo $tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">📊 Dashboard</a>
            </nav>
            <div style="margin-top:20px;">
            <?php
            switch ( $tab ) {
                case 'strategy':   $this->tab_strategy(); break;
                case 'automation': $this->tab_automation( $s ); break;
                case 'translate': $this->tab_translate(); break;
                case 'performance': $this->tab_performance(); break;
                case 'code':      $this->tab_code( $s ); break;
                case 'bulk':      $this->tab_bulk(); break;
                case 'migration': $this->tab_migration(); break;
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
                            <option value="qwen" <?php selected( $engine, 'qwen' ); ?>>通义千问 Qwen（阿里云百炼）</option>
                            <option value="openai" <?php selected( $engine, 'openai' ); ?>>ChatGPT / OpenAI</option>
                        </select>
                        <p class="description">中国大陆环境可优先选择 DeepSeek 或通义千问；海外环境可选择 Gemini 或 ChatGPT。</p>
                    </td>
                </tr>

                <!-- Gemini fields -->
                <tr class="gml-seo-engine-gemini" <?php echo $engine !== 'gemini' ? 'style="display:none;"' : ''; ?>>
                    <th>Gemini API Key</th>
                    <td><input type="password" name="gml_seo[gemini_key]" value="" class="regular-text" autocomplete="off" placeholder="<?php echo ! empty( $s['gemini_key'] ) ? esc_attr__( '已配置，留空则不修改', 'gml-seo' ) : ''; ?>">
                    <p class="description">从 <a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio</a> 获取。API Key 会加密保存。</p></td>
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
                    <td><input type="password" name="gml_seo[deepseek_key]" value="" class="regular-text" autocomplete="off" placeholder="<?php echo ! empty( $s['deepseek_key'] ) ? esc_attr__( '已配置，留空则不修改', 'gml-seo' ) : ''; ?>">
                    <p class="description">从 <a href="https://platform.deepseek.com/api_keys" target="_blank">DeepSeek 开放平台</a> 获取。API Key 会加密保存。</p></td>
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

                <!-- Qwen fields -->
                <tr class="gml-seo-engine-qwen" <?php echo $engine !== 'qwen' ? 'style="display:none;"' : ''; ?>>
                    <th>Qwen API Key</th>
                    <td><input type="password" name="gml_seo[qwen_key]" value="" class="regular-text" autocomplete="off" placeholder="<?php echo ! empty( $s['qwen_key'] ) ? esc_attr__( '已配置，留空则不修改', 'gml-seo' ) : ''; ?>">
                    <p class="description">从 <a href="https://bailian.console.aliyun.com/" target="_blank" rel="noopener">阿里云百炼 DashScope</a> 获取。API Key 会加密保存。</p></td>
                </tr>
                <tr class="gml-seo-engine-qwen" <?php echo $engine !== 'qwen' ? 'style="display:none;"' : ''; ?>>
                    <th>Qwen Model</th>
                    <td><select name="gml_seo[qwen_model]">
                        <?php foreach ( [ 'qwen-plus' => 'Qwen Plus (推荐)', 'qwen-turbo' => 'Qwen Turbo (更快)', 'qwen-max' => 'Qwen Max (更高质量)' ] as $v => $l ) : ?>
                            <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $s['qwen_model'] ?? 'qwen-plus', $v ); ?>><?php echo esc_html( $l ); ?></option>
                        <?php endforeach; ?>
                    </select></td>
                </tr>
                <tr class="gml-seo-engine-qwen" <?php echo $engine !== 'qwen' ? 'style="display:none;"' : ''; ?>>
                    <th>Qwen API Base URL</th>
                    <td><input type="url" name="gml_seo[qwen_base_url]" value="<?php echo esc_attr( $s['qwen_base_url'] ?? 'https://dashscope.aliyuncs.com/compatible-mode' ); ?>" class="regular-text">
                    <p class="description">默认使用 DashScope OpenAI 兼容模式；如使用代理可修改。</p></td>
                </tr>

                <!-- OpenAI fields -->
                <tr class="gml-seo-engine-openai" <?php echo $engine !== 'openai' ? 'style="display:none;"' : ''; ?>>
                    <th>OpenAI API Key</th>
                    <td><input type="password" name="gml_seo[openai_key]" value="" class="regular-text" autocomplete="off" placeholder="<?php echo ! empty( $s['openai_key'] ) ? esc_attr__( '已配置，留空则不修改', 'gml-seo' ) : ''; ?>">
                    <p class="description">从 <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">OpenAI Platform</a> 获取。API Key 会加密保存。</p></td>
                </tr>
                <tr class="gml-seo-engine-openai" <?php echo $engine !== 'openai' ? 'style="display:none;"' : ''; ?>>
                    <th>OpenAI Model</th>
                    <td><select name="gml_seo[openai_model]">
                        <?php foreach ( [ 'gpt-4o-mini' => 'GPT-4o mini (推荐)', 'gpt-4.1-mini' => 'GPT-4.1 mini', 'gpt-4.1' => 'GPT-4.1 (更高质量)' ] as $v => $l ) : ?>
                            <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $s['openai_model'] ?? 'gpt-4o-mini', $v ); ?>><?php echo esc_html( $l ); ?></option>
                        <?php endforeach; ?>
                    </select></td>
                </tr>
                <tr class="gml-seo-engine-openai" <?php echo $engine !== 'openai' ? 'style="display:none;"' : ''; ?>>
                    <th>OpenAI API Base URL</th>
                    <td><input type="url" name="gml_seo[openai_base_url]" value="<?php echo esc_attr( $s['openai_base_url'] ?? 'https://api.openai.com' ); ?>" class="regular-text">
                    <p class="description">默认 https://api.openai.com；如使用兼容代理可修改。</p></td>
                </tr>

                <!-- Common fields -->
                <tr>
                    <th>AI 应用方式</th>
                    <td>
                        <select name="gml_seo[ai_apply_mode]">
                            <option value="suggest" <?php selected( $s['ai_apply_mode'] ?? 'suggest', 'suggest' ); ?>>建议模式（推荐，人工采纳）</option>
                            <option value="apply" <?php selected( $s['ai_apply_mode'] ?? 'suggest', 'apply' ); ?>>自动应用（直接覆盖 SEO 字段）</option>
                        </select>
                        <p class="description">建议模式会把 AI 结果保存到 suggestion 通道，不直接覆盖标题、描述、OG 和关键词。</p>
                    </td>
                </tr>
                <tr>
                    <th>AI 连接测试</th>
                    <td>
                        <button type="button" class="button" id="gml-test-ai-engine">测试当前 AI 引擎连接</button>
                        <span id="gml-test-ai-engine-msg" style="margin-left:10px;"></span>
                        <p class="description">测试会使用当前保存的 API Key 和模型发送一条极短请求。请先保存设置后再测试。</p>
                    </td>
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
        // v1.9.0: Gradual Mode (anti-penalty observation period) exit control.
        if ( class_exists( 'GML_SEO_Gradual_Mode_Manager' )
             && GML_SEO_Gradual_Mode_Manager::is_active() ) :
            $entered = GML_SEO::opt( 'gradual_entered_at', '' );
            $exit_nonce = wp_create_nonce( 'gml_seo_admin' );
        ?>
        <hr style="margin:32px 0;">
        <h2>🛡 防惩罚观察期</h2>
        <div class="notice notice-info inline" style="padding:14px 16px;">
            <p>
                <strong>观察期正在运行。</strong>
                <?php if ( $entered ) : ?>迁移完成于 <?php echo esc_html( $entered ); ?>。<?php endif; ?>
            </p>
            <p>
                期间 AI 不会自动覆盖你的 meta — 新建议写入 suggestion 通道，在文章编辑页并排显示供你逐篇采纳；Bulk Optimize 被禁用。
                遵循 <a href="https://developers.google.com/search/docs/fundamentals/seo-starter-guide" target="_blank" rel="noopener">Google SEO Starter Guide</a>，避免 Core Update 对全站大规模变动做出惩罚性反应。
            </p>
            <p>
                <button type="button" class="button button-secondary" id="gml-seo-gradual-exit-btn">
                    退出观察期 · 恢复 Bulk Optimize
                </button>
                <span id="gml-seo-gradual-exit-msg" style="margin-left:10px;color:#00a32a;display:none;"></span>
            </p>
        </div>
        <script>
        ( function () {
            var btn = document.getElementById( 'gml-seo-gradual-exit-btn' );
            if ( ! btn ) return;
            btn.addEventListener( 'click', function () {
                if ( ! confirm( '确认退出观察期？退出后 AI 结果将恢复直接覆盖 _gml_seo_* 字段，Bulk Optimize 也会恢复可用。' ) ) return;
                btn.disabled = true;
                var fd = new FormData();
                fd.append( 'action', 'gml_seo_gradual_exit' );
                fd.append( 'nonce', '<?php echo esc_js( $exit_nonce ); ?>' );
                fetch( window.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
                    .then( function ( r ) { return r.json(); } )
                    .then( function ( res ) {
                        var msg = document.getElementById( 'gml-seo-gradual-exit-msg' );
                        if ( res && res.success ) {
                            msg.textContent = '✓ 已退出观察期，刷新页面中…';
                            msg.style.display = '';
                            setTimeout( function () { window.location.reload(); }, 800 );
                        } else {
                            btn.disabled = false;
                            alert( '退出失败：' + ( res && res.data && res.data.message ? res.data.message : '未知错误' ) );
                        }
                    } );
            } );
        } )();
        </script>
        <?php endif; ?>

        <script>
        ( function () {
            var btn = document.getElementById( 'gml-test-ai-engine' );
            var msg = document.getElementById( 'gml-test-ai-engine-msg' );
            if ( ! btn ) return;
            btn.addEventListener( 'click', function () {
                btn.disabled = true;
                msg.textContent = '测试中...';
                var fd = new FormData();
                fd.append( 'action', 'gml_seo_test_ai_engine' );
                fd.append( 'nonce', '<?php echo esc_js( wp_create_nonce( 'gml_seo_admin' ) ); ?>' );
                fetch( window.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
                    .then( function ( r ) { return r.json(); } )
                    .then( function ( res ) {
                        btn.disabled = false;
                        if ( res && res.success ) {
                            msg.textContent = '连接成功：' + ( res.data && res.data.message ? res.data.message : 'OK' );
                        } else {
                            msg.textContent = '连接失败：' + ( res && res.data && res.data.message ? res.data.message : '未知错误' );
                        }
                    } )
                    .catch( function () {
                        btn.disabled = false;
                        msg.textContent = '连接失败，请重试';
                    } );
            } );
        } )();

        document.getElementById('gml-seo-engine').addEventListener('change', function(){
            var v = this.value;
            document.querySelectorAll('.gml-seo-engine-gemini').forEach(function(el){ el.style.display = v === 'gemini' ? '' : 'none'; });
            document.querySelectorAll('.gml-seo-engine-deepseek').forEach(function(el){ el.style.display = v === 'deepseek' ? '' : 'none'; });
            document.querySelectorAll('.gml-seo-engine-qwen').forEach(function(el){ el.style.display = v === 'qwen' ? '' : 'none'; });
            document.querySelectorAll('.gml-seo-engine-openai').forEach(function(el){ el.style.display = v === 'openai' ? '' : 'none'; });
        });
        </script>
        <?php
    }

    private function tab_strategy() {
        $s = GML_SEO_Strategy::get();
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'gml_seo_strategy_group' ); ?>
            <h2>🎯 SEO Strategy</h2>
            <p>这些设置会进入 AI 分析上下文，让优化结果更贴合业务目标、市场和转化路径。</p>
            <table class="form-table">
                <tr>
                    <th>网站类型</th>
                    <td><select name="gml_seo_strategy[site_type]">
                        <?php foreach ( [
                            'b2b' => 'B2B 外贸 / 询盘',
                            'ecommerce' => 'WooCommerce / 电商',
                            'blog' => '博客 / 内容站',
                            'corporate' => '企业官网',
                            'local_service' => '本地服务',
                            'saas' => 'SaaS / 软件',
                            'other' => '其它',
                        ] as $v => $label ) : ?>
                            <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $s['site_type'], $v ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select></td>
                </tr>
                <?php
                $fields = [
                    'primary_markets'  => [ '主要市场', '例如：United States, Germany, Middle East' ],
                    'target_languages' => [ '目标语言', '例如：English, German, Arabic' ],
                    'core_offerings'   => [ '核心产品/服务', '写出最重要的产品线、服务或解决方案。' ],
                    'customer_profile' => [ '目标客户画像', '例如：procurement managers, distributors, factory owners' ],
                    'conversion_goals' => [ '转化目标', '例如：inquiry, WhatsApp, RFQ, purchase, newsletter signup' ],
                    'brand_voice'      => [ '品牌语气', '例如：professional, technical, trustworthy, concise' ],
                    'must_use_terms'   => [ '必须保留/优先使用词', '品牌名、产品型号、核心术语，每行或逗号分隔。' ],
                    'avoid_terms'      => [ '禁用词/避免承诺', '例如：best, cheapest, guaranteed，或不想触碰的竞品词。' ],
                    'competitors'      => [ '竞争对手域名', '每行一个域名，供 AI 做定位参考，不会自动抓取。' ],
                    'analytics_notes'  => [ '分析数据备注', '可手动粘贴 GSC/GA 洞察，例如高曝光低 CTR、重点转化词。' ],
                ];
                foreach ( $fields as $key => $meta ) :
                ?>
                <tr>
                    <th><?php echo esc_html( $meta[0] ); ?></th>
                    <td>
                        <textarea name="gml_seo_strategy[<?php echo esc_attr( $key ); ?>]" rows="3" class="large-text"><?php echo esc_textarea( $s[ $key ] ?? '' ); ?></textarea>
                        <p class="description"><?php echo esc_html( $meta[1] ); ?></p>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <th>Search Console Property URL</th>
                    <td>
                        <input type="url" name="gml_seo_strategy[gsc_property_url]" value="<?php echo esc_attr( $s['gsc_property_url'] ?? '' ); ?>" class="regular-text" placeholder="https://example.com/">
                        <p class="description">为下一步 Search Console API 同步预留；当前会作为策略上下文提供给 AI。</p>
                    </td>
                </tr>
                <tr>
                    <th>GA4 Property ID</th>
                    <td>
                        <input type="text" name="gml_seo_strategy[ga4_property_id]" value="<?php echo esc_attr( $s['ga4_property_id'] ?? '' ); ?>" class="regular-text" placeholder="123456789">
                        <p class="description">GA4 Data API 使用数字 Property ID，不是 G- 开头的 Measurement ID。服务账号需要在 GA4 Property 中拥有 Viewer 权限。</p>
                    </td>
                </tr>
                <tr>
                    <th>GA4 转化事件</th>
                    <td>
                        <textarea name="gml_seo_strategy[conversion_events]" rows="2" class="large-text"><?php echo esc_textarea( $s['conversion_events'] ?? '' ); ?></textarea>
                        <p class="description">填写你最重视的 GA4 转化事件名，例如 generate_lead、purchase、form_submit。AI 会优先围绕这些目标优化。</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( '保存 SEO Strategy' ); ?>
        </form>
        <?php $this->render_analytics_sync_status(); ?>
        <?php $this->render_gsc_panel(); ?>
        <?php $this->render_ga4_panel(); ?>
        <?php
    }

    private function render_analytics_sync_status() {
        if ( ! class_exists( 'GML_SEO_Analytics_Sync' ) ) {
            return;
        }
        $status = GML_SEO_Analytics_Sync::get_status();
        ?>
        <hr style="margin:28px 0;">
        <h2>Analytics Auto Sync</h2>
        <p>插件会每天自动刷新已配置的 Search Console 和 GA4 数据，让 AI 使用更近的数据做 SEO 建议。</p>
        <?php if ( empty( $status ) ) : ?>
            <p style="color:#666;">暂无自动同步记录。保存配置后可等待定时任务，或使用下面的手动同步按钮。</p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:760px;">
                <tbody>
                    <tr>
                        <th style="width:180px;">上次自动同步</th>
                        <td><?php echo esc_html( $status['synced_at'] ?? '' ); ?></td>
                    </tr>
                    <?php foreach ( [ 'gsc' => 'Search Console', 'ga4' => 'GA4' ] as $key => $label ) :
                        $row = $status[ $key ] ?? [];
                        $state = ! empty( $row['success'] ) ? '成功' : ( ! empty( $row['skipped'] ) ? '跳过' : '失败' );
                    ?>
                    <tr>
                        <th><?php echo esc_html( $label ); ?></th>
                        <td><strong><?php echo esc_html( $state ); ?></strong>：<?php echo esc_html( $row['message'] ?? '' ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    private function render_gsc_panel() {
        $insights = class_exists( 'GML_SEO_Search_Console' ) ? GML_SEO_Search_Console::get_insights() : [];
        $nonce = wp_create_nonce( 'gml_seo_admin' );
        ?>
        <hr style="margin:28px 0;">
        <h2>Search Console Insights</h2>
        <p>使用 Automation 页里的 Google Service Account JSON 读取 Search Console 最近 28 天查询数据。服务账号邮箱需要先添加到 Search Console property。</p>
        <p>
            <button type="button" class="button button-primary" id="gml-sync-gsc">同步 Search Console 数据</button>
            <span id="gml-sync-gsc-msg" style="margin-left:10px;"></span>
        </p>
        <?php if ( ! empty( $insights ) ) : ?>
            <p><strong>上次同步：</strong><?php echo esc_html( $insights['synced_at'] ?? '' ); ?>　
            <strong>范围：</strong><?php echo esc_html( ( $insights['start_date'] ?? '' ) . ' → ' . ( $insights['end_date'] ?? '' ) ); ?></p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;max-width:1100px;">
                <?php
                $sections = [
                    'top_queries' => '高点击关键词',
                    'low_ctr_queries' => '高曝光低 CTR',
                    'striking_distance_queries' => '8-20 名机会词',
                ];
                foreach ( $sections as $key => $label ) :
                    $rows = array_slice( $insights[ $key ] ?? [], 0, 8 );
                ?>
                    <div style="border:1px solid #ccd0d4;background:#fff;padding:12px;">
                        <h3 style="margin-top:0;"><?php echo esc_html( $label ); ?></h3>
                        <?php if ( empty( $rows ) ) : ?>
                            <p style="color:#666;">暂无数据</p>
                        <?php else : ?>
                            <ol style="margin-left:18px;">
                            <?php foreach ( $rows as $row ) : ?>
                                <li>
                                    <strong><?php echo esc_html( $row['query'] ?? '' ); ?></strong><br>
                                    <span style="color:#666;font-size:12px;">
                                        clicks <?php echo esc_html( (string) (int) ( $row['clicks'] ?? 0 ) ); ?> ·
                                        impr <?php echo esc_html( (string) (int) ( $row['impressions'] ?? 0 ) ); ?> ·
                                        CTR <?php echo esc_html( number_format_i18n( 100 * (float) ( $row['ctr'] ?? 0 ), 1 ) ); ?>% ·
                                        pos <?php echo esc_html( number_format_i18n( (float) ( $row['position'] ?? 0 ), 1 ) ); ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <script>
        ( function () {
            var btn = document.getElementById( 'gml-sync-gsc' );
            var msg = document.getElementById( 'gml-sync-gsc-msg' );
            if ( ! btn ) return;
            btn.addEventListener( 'click', function () {
                btn.disabled = true;
                msg.textContent = '同步中...';
                var fd = new FormData();
                fd.append( 'action', 'gml_seo_sync_gsc' );
                fd.append( 'nonce', '<?php echo esc_js( $nonce ); ?>' );
                fetch( window.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
                    .then( function ( r ) { return r.json(); } )
                    .then( function ( res ) {
                        if ( res && res.success ) {
                            msg.textContent = '同步完成，刷新中...';
                            window.location.reload();
                        } else {
                            btn.disabled = false;
                            msg.textContent = '同步失败：' + ( res && res.data && res.data.message ? res.data.message : '未知错误' );
                        }
                    } )
                    .catch( function () {
                        btn.disabled = false;
                        msg.textContent = '同步失败，请重试';
                    } );
            } );
        } )();
        </script>
        <?php
    }

    public function ajax_sync_gsc() {
        check_ajax_referer( 'gml_seo_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        if ( ! class_exists( 'GML_SEO_Search_Console' ) ) {
            wp_send_json_error( [ 'message' => 'Search Console module missing.' ], 500 );
        }
        $result = GML_SEO_Search_Console::sync();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
        }
        wp_send_json_success( $result );
    }

    // GA4 insights panel and AJAX endpoints.
    private function render_ga4_panel() {
        $insights = class_exists( 'GML_SEO_GA4' ) ? GML_SEO_GA4::get_insights() : [];
        $nonce = wp_create_nonce( 'gml_seo_admin' );
        ?>
        <hr style="margin:28px 0;">
        <h2>GA4 Conversion Insights</h2>
        <p>使用 Automation 页里的 Google Service Account JSON 读取 GA4 最近 28 天页面、事件和转化数据。服务账号需要先加入 GA4 Property。</p>
        <p>
            <button type="button" class="button button-primary" id="gml-sync-ga4">同步 GA4 数据</button>
            <span id="gml-sync-ga4-msg" style="margin-left:10px;"></span>
        </p>
        <?php if ( ! empty( $insights ) ) : ?>
            <p><strong>上次同步：</strong><?php echo esc_html( $insights['synced_at'] ?? '' ); ?>　
            <strong>范围：</strong><?php echo esc_html( ( $insights['start_date'] ?? '' ) . ' → ' . ( $insights['end_date'] ?? '' ) ); ?></p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;max-width:1100px;">
                <?php
                $sections = [
                    'top_pages'            => '高流量页面',
                    'conversion_pages'     => '转化页面',
                    'low_conversion_pages' => '有流量无转化',
                ];
                foreach ( $sections as $key => $label ) :
                    $rows = array_slice( $insights[ $key ] ?? [], 0, 8 );
                ?>
                    <div style="border:1px solid #ccd0d4;background:#fff;padding:12px;">
                        <h3 style="margin-top:0;"><?php echo esc_html( $label ); ?></h3>
                        <?php if ( empty( $rows ) ) : ?>
                            <p style="color:#666;">暂无数据</p>
                        <?php else : ?>
                            <ol style="margin-left:18px;">
                            <?php foreach ( $rows as $row ) : ?>
                                <li>
                                    <strong><?php echo esc_html( $row['page'] ?? '' ); ?></strong><br>
                                    <span style="color:#666;font-size:12px;">
                                        sessions <?php echo esc_html( (string) (int) ( $row['sessions'] ?? 0 ) ); ?> ·
                                        conv <?php echo esc_html( (string) (float) ( $row['conversions'] ?? 0 ) ); ?> ·
                                        CVR <?php echo esc_html( number_format_i18n( 100 * (float) ( $row['conversion_rate'] ?? 0 ), 1 ) ); ?>% ·
                                        engagement <?php echo esc_html( number_format_i18n( 100 * (float) ( $row['engagement_rate'] ?? 0 ), 1 ) ); ?>%
                                    </span>
                                </li>
                            <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div style="border:1px solid #ccd0d4;background:#fff;padding:12px;">
                    <h3 style="margin-top:0;">事件</h3>
                    <?php $events = array_slice( $insights['top_events'] ?? [], 0, 8 ); ?>
                    <?php if ( empty( $events ) ) : ?>
                        <p style="color:#666;">暂无数据</p>
                    <?php else : ?>
                        <ol style="margin-left:18px;">
                        <?php foreach ( $events as $row ) : ?>
                            <li>
                                <strong><?php echo esc_html( $row['event'] ?? '' ); ?></strong><br>
                                <span style="color:#666;font-size:12px;">
                                    count <?php echo esc_html( (string) (int) ( $row['event_count'] ?? 0 ) ); ?> ·
                                    conv <?php echo esc_html( (string) (float) ( $row['conversions'] ?? 0 ) ); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <script>
        ( function () {
            var btn = document.getElementById( 'gml-sync-ga4' );
            var msg = document.getElementById( 'gml-sync-ga4-msg' );
            if ( ! btn ) return;
            btn.addEventListener( 'click', function () {
                btn.disabled = true;
                msg.textContent = '同步中...';
                var fd = new FormData();
                fd.append( 'action', 'gml_seo_sync_ga4' );
                fd.append( 'nonce', '<?php echo esc_js( $nonce ); ?>' );
                fetch( window.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
                    .then( function ( r ) { return r.json(); } )
                    .then( function ( res ) {
                        if ( res && res.success ) {
                            msg.textContent = '同步完成，刷新中...';
                            window.location.reload();
                        } else {
                            btn.disabled = false;
                            msg.textContent = '同步失败：' + ( res && res.data && res.data.message ? res.data.message : '未知错误' );
                        }
                    } )
                    .catch( function () {
                        btn.disabled = false;
                        msg.textContent = '同步失败，请重试';
                    } );
            } );
        } )();
        </script>
        <?php
    }

    public function ajax_sync_ga4() {
        check_ajax_referer( 'gml_seo_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        if ( ! class_exists( 'GML_SEO_GA4' ) ) {
            wp_send_json_error( [ 'message' => 'GA4 module missing.' ], 500 );
        }
        $result = GML_SEO_GA4::sync();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
        }
        wp_send_json_success( $result );
    }

    public function ajax_test_ai_engine() {
        check_ajax_referer( 'gml_seo_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        if ( ! class_exists( 'GML_SEO_AI_Client' ) ) {
            wp_send_json_error( [ 'message' => 'AI client missing.' ], 500 );
        }

        $client = new GML_SEO_AI_Client();
        $result = $client->call( 'Reply with OK only.', 'You are a connection test endpoint.', 8 );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
        }

        $engine = GML_SEO::opt( 'engine', 'gemini' );
        wp_send_json_success( [
            'message' => strtoupper( $engine ) . ' OK',
            'sample'  => mb_substr( trim( wp_strip_all_tags( (string) $result ) ), 0, 80 ),
        ] );
    }

    // Translate tab (bundled GML Translate module).
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
            <input type="hidden" name="gml_seo[__automation_submitted]" value="1">
            <?php
            // Preserve all other settings
            $preserve = [ 'engine', 'gemini_key', 'model', 'deepseek_key', 'deepseek_model',
                'deepseek_base_url', 'qwen_key', 'qwen_model', 'qwen_base_url',
                'openai_key', 'openai_model', 'openai_base_url', 'ai_apply_mode', 'ga_id', 'gtm_id', 'adsense_id', 'head_code', 'body_code',
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
                        <textarea name="gml_seo[google_service_account]" rows="6" class="large-text code" placeholder="<?php echo ! empty( $s['google_service_account'] ) ? esc_attr__( 'Configured; leave blank to keep unchanged', 'gml-seo' ) : esc_attr( '{"type":"service_account","project_id":"...","private_key":"...",...}' ); ?>"></textarea>
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
        $s = get_option( 'gml_seo', [] );
        if ( ! is_array( $s ) ) $s = [];

        $preserve = [ 'engine', 'gemini_key', 'model', 'deepseek_key', 'deepseek_model',
            'deepseek_base_url', 'qwen_key', 'qwen_model', 'qwen_base_url',
            'openai_key', 'openai_model', 'openai_base_url', 'ai_apply_mode', 'ga_id', 'gtm_id', 'adsense_id', 'head_code', 'body_code',
            'footer_code', 'site_name', 'separator', 'audit_frequency',
            'indexnow_enabled', 'google_service_account' ];
        ?>
        <h2>⚡ 性能优化</h2>
        <p>
            遵循 <a href="https://developers.google.com/search/docs/fundamentals/seo-starter-guide" target="_blank" rel="noopener">Google SEO 指南</a>
            和 <a href="https://web.dev/vitals/" target="_blank" rel="noopener">Core Web Vitals</a> 最佳实践。
            所有优化默认启用，遇到主题 / 插件冲突时可以按项关闭。
        </p>

        <p style="margin:14px 0;">
            <button type="button" class="button" id="gml-perf-all-on">✓ 全部启用</button>
            <button type="button" class="button" id="gml-perf-all-off" style="margin-left:6px;">✗ 全部禁用</button>
            <span style="margin-left:10px;color:#646970;font-size:13px;">点击按钮后，还需要点底部"保存优化设置"才会生效。</span>
        </p>

        <form method="post" action="options.php" id="gml-perf-form">
            <?php settings_fields( 'gml_seo_group' ); ?>
            <input type="hidden" name="gml_seo[__perf_submitted]" value="1">
            <?php
            // Preserve non-perf fields so only Performance toggles are mutated.
            foreach ( $preserve as $k ) {
                echo '<input type="hidden" name="gml_seo[' . esc_attr( $k ) . ']" value="' . esc_attr( (string) ( $s[ $k ] ?? '' ) ) . '">';
            }
            ?>

            <?php if ( class_exists( 'GML_SEO_Performance' ) ) :
                foreach ( GML_SEO_Performance::$toggles as $group_id => $group ) : ?>
                <h3 style="margin-top:28px;"><?php echo esc_html( $group['label'] ); ?></h3>
                <table class="wp-list-table widefat fixed striped" style="max-width:980px;">
                    <thead>
                        <tr>
                            <th style="width:70px;">启用</th>
                            <th style="width:320px;">优化项</th>
                            <th>说明</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $group['options'] as $key => $meta ) :
                        list( $label, $default, $help ) = $meta;
                        $current = array_key_exists( $key, $s ) ? (int) $s[ $key ] : (int) $default;
                    ?>
                        <tr>
                            <td style="text-align:center;">
                                <input type="checkbox"
                                       class="gml-perf-toggle"
                                       id="<?php echo esc_attr( $key ); ?>"
                                       name="gml_seo[<?php echo esc_attr( $key ); ?>]"
                                       value="1"
                                       <?php checked( $current, 1 ); ?>>
                            </td>
                            <td><label for="<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label></td>
                            <td style="font-size:13px;color:#555;"><?php echo esc_html( $help ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach;
            endif; ?>

            <?php submit_button( '保存优化设置' ); ?>
        </form>

        <div style="margin-top:20px;padding:16px;background:#f0f6fc;border:1px solid #c3d9ed;border-radius:6px;max-width:980px;">
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

        <script>
        ( function () {
            function setAll( v ) {
                document.querySelectorAll( '.gml-perf-toggle' ).forEach( function ( el ) { el.checked = v; } );
            }
            var on  = document.getElementById( 'gml-perf-all-on' );
            var off = document.getElementById( 'gml-perf-all-off' );
            if ( on )  on.addEventListener( 'click', function () { setAll( true ); } );
            if ( off ) off.addEventListener( 'click', function () { setAll( false ); } );
        } )();
        </script>
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
            <input type="hidden" name="gml_seo[qwen_key]" value="<?php echo esc_attr( $s['qwen_key'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[qwen_model]" value="<?php echo esc_attr( $s['qwen_model'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[qwen_base_url]" value="<?php echo esc_attr( $s['qwen_base_url'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[openai_key]" value="<?php echo esc_attr( $s['openai_key'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[openai_model]" value="<?php echo esc_attr( $s['openai_model'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[openai_base_url]" value="<?php echo esc_attr( $s['openai_base_url'] ?? '' ); ?>">
            <input type="hidden" name="gml_seo[ai_apply_mode]" value="<?php echo esc_attr( $s['ai_apply_mode'] ?? 'suggest' ); ?>">
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
        // v1.9.0 anti-penalty observation period: replace the bulk UI with
        // an explanatory notice. AJAX is separately blocked in class-ai-engine.
        if ( class_exists( 'GML_SEO_Gradual_Mode_Manager' )
             && GML_SEO_Gradual_Mode_Manager::is_active() ) {
            $entered = GML_SEO::opt( 'gradual_entered_at', '' );
            ?>
            <div class="notice notice-warning" style="margin-top:20px;">
                <h3 style="margin-top:10px;">🛡 <?php esc_html_e( 'Bulk Optimize is paused during the anti-penalty observation period.', 'gml-seo' ); ?></h3>
                <?php if ( $entered ) : ?>
                <p><?php printf( esc_html__( 'Migration completed on %s.', 'gml-seo' ), esc_html( $entered ) ); ?></p>
                <?php endif; ?>
                <p>
                    <?php esc_html_e( 'Per the', 'gml-seo' ); ?>
                    <a href="https://developers.google.com/search/docs/fundamentals/seo-starter-guide" target="_blank" rel="noopener">Google SEO Starter Guide</a>,
                    <?php esc_html_e( 'large-scale meta rewrites right after a migration can look suspicious to Core Updates. Review each post in the editor instead and adopt AI suggestions field-by-field.', 'gml-seo' ); ?>
                </p>
                <p>
                    <?php esc_html_e( 'When you\'re confident with the results, you can end the observation period in Settings and Bulk Optimize will come back.', 'gml-seo' ); ?>
                </p>
            </div>
            <?php
            return;
        }

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

    // ── Migration Tab (v1.9.0 — placeholder in Stage 0) ──────────────

    /**
     * Renders the Migration wizard tab.
     *
     * Server-side only emits the layout scaffold + a localized payload.
     * All real interaction (Scan → Preview → Execute → progress polling)
     * lives in `assets/js/migration-wizard.js`.
     *
     * @since 1.9.0
     */
    private function tab_migration() {
        $detected = [];
        $state    = [];
        if ( class_exists( 'GML_SEO_Conflict_Detector' ) ) {
            $detected = GML_SEO_Conflict_Detector::scan();
        }
        if ( class_exists( 'GML_SEO_Migration_Manager' ) ) {
            $state = GML_SEO_Migration_Manager::get_state();
        }

        $slugs = [
            'yoast'        => 'Yoast SEO',
            'rankmath'     => 'Rank Math',
            'seopress'     => 'SEOPress',
            'aioseo'       => 'All in One SEO',
            'seoframework' => 'The SEO Framework',
        ];
        $detected_slugs = array_column( $detected, 'slug' );

        // Localize config for the wizard JS.
        wp_localize_script(
            'gml-seo-migration-wizard',
            'gmlSeoMigration',
            [
                'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                'nonce'         => wp_create_nonce( 'gml_seo_migration' ),
                'slugs'         => $slugs,
                'detectedSlugs' => $detected_slugs,
                'initialState'  => $state,
                'pollInterval'  => 5000,
                'staleAfter'    => 120,
                'i18n'          => [
                    'step1'        => __( 'Step 1 — Scan', 'gml-seo' ),
                    'step2'        => __( 'Step 2 — Preview (first 20 posts)', 'gml-seo' ),
                    'step3'        => __( 'Step 3 — Execute', 'gml-seo' ),
                    'step4'        => __( 'Step 4 — Progress', 'gml-seo' ),
                    'scanBtn'      => __( 'Scan', 'gml-seo' ),
                    'previewBtn'   => __( 'Preview', 'gml-seo' ),
                    'executeBtn'   => __( 'Start migration', 'gml-seo' ),
                    'backupCheck'  => __( 'I have backed up my database and understand this cannot be automatically undone.', 'gml-seo' ),
                    'cronStalled'  => __( 'WP-Cron doesn\'t seem to be running. You can manually trigger the next batch.', 'gml-seo' ),
                    'triggerNext'  => __( 'Trigger next batch', 'gml-seo' ),
                    'postsCounted' => __( '{n} posts detected', 'gml-seo' ),
                    'progressFmt'  => __( '{processed} / {total} processed — {written} written, {skipped} skipped', 'gml-seo' ),
                    'completed'    => __( 'Migration complete — entering the anti-penalty observation period.', 'gml-seo' ),
                    'pickSource'   => __( 'Pick a source plugin', 'gml-seo' ),
                ],
            ]
        );
        ?>
        <h2>📦 <?php esc_html_e( 'Migrate from another SEO plugin', 'gml-seo' ); ?></h2>
        <p>
            <?php esc_html_e( 'The wizard moves hand-tuned meta (title, description, OG, canonical, noindex, keywords) from Yoast / Rank Math / SEOPress / AIOSEO / The SEO Framework into GML AI SEO\'s own keys. Source data is never deleted.', 'gml-seo' ); ?>
            <a href="https://developers.google.com/search/docs/fundamentals/seo-starter-guide" target="_blank" rel="noopener">
                <?php esc_html_e( 'Google SEO Starter Guide', 'gml-seo' ); ?>
            </a>.
        </p>
        <div id="gml-seo-migration-root" class="gml-seo-migration-wizard"></div>
        <?php
    }

    // ── Asset Enqueuing ──────────────────────────────────────────────

    /**
     * Enqueue GML AI SEO admin-side page assets.
     *
     * Currently only registers the migration-wizard JS/CSS shells, and
     * only on the Migration tab. Other tabs use inline scripts — left
     * unchanged to preserve v1.8.0 behaviour.
     *
     * @param string $hook Current admin page slug.
     * @since 1.9.0
     */
    public function enqueue_assets( $hook ) {
        // Only target our plugin page.
        if ( strpos( (string) $hook, 'gml-seo' ) === false ) {
            return;
        }

        $current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
        if ( $current_tab !== 'migration' ) {
            return;
        }

        wp_enqueue_style(
            'gml-seo-migration-wizard',
            GML_SEO_URL . 'assets/css/migration-wizard.css',
            [],
            GML_SEO_VER
        );

        wp_enqueue_script(
            'gml-seo-migration-wizard',
            GML_SEO_URL . 'assets/js/migration-wizard.js',
            [],
            GML_SEO_VER,
            true
        );
    }

    // ── Conflict Notice (v1.9.0) ─────────────────────────────────────

    /**
     * Render a persistent admin_notice when a competing SEO plugin is
     * detected, migration isn't completed, and the user hasn't dismissed it.
     *
     * Rendered on all admin pages EXCEPT the Migration wizard tab itself
     * (to avoid showing the "go to migration wizard" CTA while the user
     * is already on it).
     *
     * @since 1.9.0
     */
    public function render_conflict_notice() {
        if ( ! class_exists( 'GML_SEO_Conflict_Detector' ) ) {
            return;
        }

        $ctx = GML_SEO_Conflict_Detector::get_notice_context();
        if ( empty( $ctx['detected'] ) || $ctx['migrated'] || $ctx['dismissed'] ) {
            return;
        }

        // Skip on the migration wizard itself.
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
        $tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
        if ( $page === 'gml-seo' && $tab === 'migration' ) {
            return;
        }

        $nonce  = wp_create_nonce( 'gml_seo_admin' );
        $cta    = admin_url( 'admin.php?page=gml-seo&tab=migration' );
        $joined = implode( ', ', array_map( 'esc_html', $ctx['detected'] ) );
        ?>
        <div class="notice notice-warning is-dismissible gml-seo-conflict-notice"
             data-nonce="<?php echo esc_attr( $nonce ); ?>">
            <p>
                <strong><?php esc_html_e( 'GML AI SEO detected a competing SEO plugin:', 'gml-seo' ); ?></strong>
                <?php echo $joined; // already escaped ?>
            </p>
            <p>
                <?php esc_html_e( 'To avoid duplicate <title>, meta description and canonical tags, GML AI SEO is currently holding back its frontend meta output. Run the migration wizard to safely move your hand-tuned meta into GML AI SEO.', 'gml-seo' ); ?>
            </p>
            <p>
                <a href="<?php echo esc_url( $cta ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Go to Migration Wizard', 'gml-seo' ); ?>
                </a>
            </p>
        </div>
        <script>
        ( function () {
            var notice = document.querySelector( '.gml-seo-conflict-notice' );
            if ( ! notice ) return;
            notice.addEventListener( 'click', function ( e ) {
                var btn = e.target.closest( '.notice-dismiss' );
                if ( ! btn ) return;
                var fd = new FormData();
                fd.append( 'action', 'gml_seo_dismiss_conflict_notice' );
                fd.append( 'nonce', notice.dataset.nonce );
                fetch( window.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } );
            } );
        } )();
        </script>
        <?php
    }

    /**
     * AJAX: gml_seo_dismiss_conflict_notice.
     *
     * Persists `gml_seo[conflict_notice_dismissed] = 1` so the notice
     * stops showing until the value is manually cleared.
     *
     * @since 1.9.0
     */
    public function ajax_dismiss_conflict_notice() {
        check_ajax_referer( 'gml_seo_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $opts                              = get_option( 'gml_seo', [] );
        if ( ! is_array( $opts ) ) {
            $opts = [];
        }
        $opts['conflict_notice_dismissed'] = 1;
        update_option( 'gml_seo', $opts );

        wp_send_json_success();
    }
}
