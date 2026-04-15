<?php
/**
 * Post editor metabox — full SEO master report with score, audit, suggestions.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_Metabox {

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
    }

    public function add() {
        foreach ( get_post_types( [ 'public' => true ], 'names' ) as $pt ) {
            add_meta_box( 'gml-seo-box', '🤖 GML AI SEO', [ $this, 'render' ], $pt, 'normal', 'high' );
        }
    }

    public function assets( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ] ) ) return;
        wp_enqueue_style( 'gml-seo-admin', GML_SEO_URL . 'assets/css/admin.css', [], GML_SEO_VER );
        wp_enqueue_script( 'gml-seo-admin', GML_SEO_URL . 'assets/js/admin.js', [ 'jquery' ], GML_SEO_VER, true );
        wp_localize_script( 'gml-seo-admin', 'gmlSeo', [
            'ajax'  => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'gml_seo_nonce' ),
        ] );
    }

    public function render( $post ) {
        $has_key = ! empty( GML_SEO::opt( 'gemini_key' ) );
        $report  = get_post_meta( $post->ID, '_gml_seo_report', true );
        $meta    = [
            'title'     => get_post_meta( $post->ID, '_gml_seo_title', true ),
            'desc'      => get_post_meta( $post->ID, '_gml_seo_desc', true ),
            'og_title'  => get_post_meta( $post->ID, '_gml_seo_og_title', true ),
            'og_desc'   => get_post_meta( $post->ID, '_gml_seo_og_desc', true ),
            'keywords'  => get_post_meta( $post->ID, '_gml_seo_keywords', true ),
            'score'     => get_post_meta( $post->ID, '_gml_seo_score', true ),
            'generated' => get_post_meta( $post->ID, '_gml_seo_generated', true ),
        ];
        ?>
        <div id="gml-seo-box-inner" data-post-id="<?php echo $post->ID; ?>">
        <?php if ( ! $has_key ) : ?>
            <p class="gml-seo-notice-warn">⚠️ 请先 <a href="<?php echo admin_url( 'admin.php?page=gml-seo' ); ?>">配置 Gemini API Key</a>，AI 才能自动优化 SEO。</p>
        <?php else : ?>
            <div class="gml-seo-toolbar">
                <button type="button" id="gml-seo-gen-btn" class="button button-primary">
                    🤖 <?php echo $report ? 'AI 重新分析' : 'AI 一键优化'; ?>
                </button>
                <?php if ( $meta['generated'] ) : ?>
                    <span class="gml-seo-time">上次分析: <?php echo esc_html( $meta['generated'] ); ?></span>
                <?php endif; ?>
            </div>

            <div id="gml-seo-loading" style="display:none;" class="gml-seo-loading">
                <span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
                AI SEO 大师正在深度分析页面：关键词策略、标题优化、内容审计、技术 SEO...
            </div>

            <div id="gml-seo-report">
            <?php if ( $report ) $this->render_report( $post, $meta, $report ); ?>
            </div>
        <?php endif; ?>
        </div>
        <?php
    }

    // ── Render full report ───────────────────────────────────────────

    public function render_report( $post, $meta, $report ) {
        $score = (int) ( $report['score'] ?? 0 );
        $grade = $report['grade'] ?? '';

        // Score color
        if ( $score >= 80 ) { $color = '#00a32a'; $emoji = '🟢'; }
        elseif ( $score >= 60 ) { $color = '#dba617'; $emoji = '🟡'; }
        elseif ( $score >= 40 ) { $color = '#e65100'; $emoji = '🟠'; }
        else { $color = '#d63638'; $emoji = '🔴'; }
        ?>

        <!-- ── Score + Keyword Strategy ── -->
        <div class="gml-seo-score-row">
            <div class="gml-seo-score-circle" style="border-color:<?php echo $color; ?>;">
                <span class="gml-seo-score-num" style="color:<?php echo $color; ?>;"><?php echo $score; ?></span>
                <span class="gml-seo-score-grade"><?php echo $grade; ?></span>
            </div>
            <div class="gml-seo-kw-strategy">
                <div class="gml-seo-kw-primary">
                    🎯 主关键词: <strong><?php echo esc_html( $report['primary_keyword'] ?? '' ); ?></strong>
                    <span class="gml-seo-intent"><?php echo esc_html( $report['search_intent'] ?? '' ); ?></span>
                </div>
                <?php if ( ! empty( $report['secondary_keywords'] ) ) : ?>
                <div class="gml-seo-kw-secondary">
                    次要关键词: <?php echo esc_html( implode( ', ', $report['secondary_keywords'] ) ); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Editable SEO Fields ── -->
        <div id="gml-seo-fields" class="gml-seo-fields-grid">
            <?php
            $fields = [
                [ '_gml_seo_title',   'SEO 标题',  $meta['title'],    60,  'text' ],
                [ '_gml_seo_desc',    'Meta 描述', $meta['desc'],     160, 'textarea' ],
                [ '_gml_seo_keywords','关键词',     $meta['keywords'], 0,   'text' ],
                [ '_gml_seo_og_title','OG 标题',   $meta['og_title'], 70,  'text' ],
                [ '_gml_seo_og_desc', 'OG 描述',   $meta['og_desc'],  160, 'textarea' ],
            ];
            foreach ( $fields as $f ) : ?>
            <div class="gml-seo-field" data-key="<?php echo $f[0]; ?>">
                <label><?php echo $f[1]; ?>
                    <?php if ( $f[3] ) : ?>
                    <span class="gml-seo-counter" data-max="<?php echo $f[3]; ?>"><span class="gml-seo-count"><?php echo mb_strlen( $f[2] ); ?></span>/<?php echo $f[3]; ?></span>
                    <?php endif; ?>
                </label>
                <?php if ( $f[4] === 'textarea' ) : ?>
                    <textarea name="<?php echo $f[0]; ?>" rows="2" class="large-text gml-seo-input"><?php echo esc_textarea( $f[2] ); ?></textarea>
                <?php else : ?>
                    <input type="text" name="<?php echo $f[0]; ?>" value="<?php echo esc_attr( $f[2] ); ?>" class="large-text gml-seo-input">
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <div class="gml-seo-toolbar">
                <button type="button" id="gml-seo-save-btn" class="button">💾 保存修改</button>
                <span id="gml-seo-save-msg" style="color:#00a32a;display:none;">✓ 已保存</span>
            </div>
        </div>

        <!-- ── Google Preview ── -->
        <div class="gml-seo-preview-box">
            <div class="gml-seo-preview-label">Google 搜索预览</div>
            <div id="gml-seo-preview-title" class="gml-seo-preview-t"><?php echo esc_html( $meta['title'] ?: $post->post_title ); ?></div>
            <div class="gml-seo-preview-u"><?php echo esc_html( get_permalink( $post->ID ) ); ?></div>
            <div id="gml-seo-preview-desc" class="gml-seo-preview-d"><?php echo esc_html( $meta['desc'] ); ?></div>
        </div>

        <!-- ── SEO Audit Issues ── -->
        <?php if ( ! empty( $report['audit']['issues'] ) ) : ?>
        <div class="gml-seo-audit">
            <h4>📋 SEO 审计报告</h4>
            <?php foreach ( $report['audit']['issues'] as $issue ) :
                $type = $issue['type'] ?? 'info';
                $icon = [ 'critical' => '🔴', 'warning' => '🟡', 'info' => '🔵', 'good' => '🟢' ][ $type ] ?? '⚪';
            ?>
            <div class="gml-seo-issue gml-seo-issue-<?php echo $type; ?>">
                <span class="gml-seo-issue-icon"><?php echo $icon; ?></span>
                <div class="gml-seo-issue-body">
                    <span class="gml-seo-issue-cat"><?php echo esc_html( strtoupper( $issue['category'] ?? '' ) ); ?></span>
                    <span class="gml-seo-issue-msg"><?php echo esc_html( $issue['message'] ?? '' ); ?></span>
                    <?php if ( ! empty( $issue['fix'] ) ) : ?>
                        <div class="gml-seo-issue-fix">💡 <?php echo esc_html( $issue['fix'] ); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── Content Suggestions ── -->
        <?php if ( ! empty( $report['content_suggestions'] ) ) : ?>
        <div class="gml-seo-suggestions">
            <h4>✍️ 内容优化建议</h4>
            <ul>
            <?php foreach ( $report['content_suggestions'] as $s ) : ?>
                <li><?php echo esc_html( $s ); ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- ── Internal Link Suggestions ── -->
        <?php if ( ! empty( $report['internal_link_suggestions'] ) ) : ?>
        <div class="gml-seo-suggestions">
            <h4>🔗 内链优化建议</h4>
            <ul>
            <?php foreach ( $report['internal_link_suggestions'] as $s ) : ?>
                <li><?php echo esc_html( $s ); ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- ── Slug Suggestion ── -->
        <?php $slug_sug = $report['slug_suggestion'] ?? null;
        if ( $slug_sug && $slug_sug !== $post->post_name ) : ?>
        <div class="gml-seo-suggestions">
            <h4>🔗 URL Slug 优化</h4>
            <p>当前: <code><?php echo esc_html( $post->post_name ); ?></code></p>
            <p>建议: <code style="color:#2271b1;font-weight:bold;"><?php echo esc_html( $slug_sug ); ?></code></p>
            <p style="font-size:12px;color:#999;">⚠️ 修改已发布页面的 URL 可能影响已有的外链和排名。如果页面已被搜索引擎收录，建议设置 301 重定向。</p>
        </div>
        <?php endif;
    }
}
