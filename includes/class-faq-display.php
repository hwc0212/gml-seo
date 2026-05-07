<?php
/**
 * Frontend BLUF (TL;DR) + FAQ renderer.
 *
 * - Prepends a BLUF / TL;DR block to the_content when AI has generated one.
 *   This direct-answer summary makes the page a stronger AI Overviews candidate.
 *   It's also the target of Speakable schema for voice / AI assistant quoting.
 *
 * - Appends an accessible FAQ <details> section when AI-generated FAQ exists.
 *   Google requires the FAQ content to be visible on the page for FAQPage
 *   rich result to qualify.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_FAQ_Display {

    public function __construct() {
        add_filter( 'the_content', [ $this, 'append_faq' ], 99 );
        add_action( 'wp_head', [ $this, 'inline_styles' ], 5 );
    }

    public function append_faq( $content ) {
        // Only on singular main-query content
        if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) return $content;

        $id = get_the_ID();

        // Prepend BLUF if enabled and available (for AI Overviews visibility)
        $bluf = get_post_meta( $id, '_gml_seo_bluf', true );
        $show_bluf = $bluf && ! get_post_meta( $id, '_gml_seo_bluf_hide', true );
        if ( $show_bluf ) {
            $bluf_html = '<div class="gml-seo-bluf" role="note">'
                       . '<strong class="gml-seo-bluf-label">TL;DR</strong> '
                       . esc_html( $bluf )
                       . '</div>';
            $content = $bluf_html . $content;
        }

        // Respect per-post disable flag
        if ( get_post_meta( $id, '_gml_seo_faq_hide', true ) ) return $content;

        $faq = get_post_meta( $id, '_gml_seo_faq', true );
        if ( empty( $faq ) || ! is_array( $faq ) ) return $content;

        $heading = apply_filters(
            'gml_seo_faq_heading',
            __( 'Frequently Asked Questions', 'gml-seo' )
        );

        $html  = '<section class="gml-seo-faq" aria-labelledby="gml-seo-faq-heading">';
        $html .= '<h2 id="gml-seo-faq-heading" class="gml-seo-faq-heading">' . esc_html( $heading ) . '</h2>';
        $html .= '<div class="gml-seo-faq-list">';

        foreach ( $faq as $i => $item ) {
            if ( empty( $item['q'] ) || empty( $item['a'] ) ) continue;
            $html .= '<details class="gml-seo-faq-item" ' . ( $i === 0 ? 'open' : '' ) . '>';
            $html .= '<summary class="gml-seo-faq-q">' . esc_html( $item['q'] ) . '</summary>';
            $html .= '<div class="gml-seo-faq-a">' . wp_kses_post( wpautop( $item['a'] ) ) . '</div>';
            $html .= '</details>';
        }

        $html .= '</div></section>';

        return $content . $html;
    }

    public function inline_styles() {
        // Only output on pages that have FAQ or BLUF
        if ( ! is_singular() ) return;
        $id   = get_queried_object_id();
        $faq  = get_post_meta( $id, '_gml_seo_faq', true );
        $bluf = get_post_meta( $id, '_gml_seo_bluf', true );
        if ( empty( $faq ) && empty( $bluf ) ) return;
        ?>
<style id="gml-seo-faq-styles">
.gml-seo-bluf{margin:0 0 1.5em;padding:.9em 1.1em;background:#f0f7ff;border-left:4px solid #2563eb;border-radius:4px;font-size:1.02em;line-height:1.6;color:#1e3a8a}
.gml-seo-bluf-label{display:inline-block;font-size:.78em;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#2563eb;margin-right:.4em}
.gml-seo-faq{margin:2.5em 0;padding:1.5em 0;border-top:1px solid #e5e7eb}
.gml-seo-faq-heading{margin:0 0 1em;font-size:1.5em;line-height:1.3}
.gml-seo-faq-list{display:flex;flex-direction:column;gap:.5em}
.gml-seo-faq-item{border:1px solid #e5e7eb;border-radius:8px;background:#fff;transition:box-shadow .15s}
.gml-seo-faq-item[open]{box-shadow:0 1px 3px rgba(0,0,0,.06)}
.gml-seo-faq-q{cursor:pointer;padding:1em 1.25em;font-weight:600;font-size:1.05em;list-style:none;position:relative;padding-right:3em}
.gml-seo-faq-q::-webkit-details-marker{display:none}
.gml-seo-faq-q::after{content:"+";position:absolute;right:1.25em;top:50%;transform:translateY(-50%);font-size:1.4em;font-weight:400;color:#6b7280;transition:transform .15s}
.gml-seo-faq-item[open] .gml-seo-faq-q::after{content:"−"}
.gml-seo-faq-a{padding:0 1.25em 1.25em;line-height:1.7;color:#374151}
.gml-seo-faq-a p:first-child{margin-top:0}
.gml-seo-faq-a p:last-child{margin-bottom:0}
</style>
        <?php
    }
}
