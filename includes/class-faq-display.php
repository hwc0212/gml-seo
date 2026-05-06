<?php
/**
 * Frontend FAQ section renderer.
 *
 * Appends an accessible, styled FAQ section to the_content when a post
 * has AI-generated FAQ data (_gml_seo_faq). The JSON-LD schema is output
 * separately by class-schema.php so FAQPage rich results work even if
 * the visible section is disabled by theme/template.
 *
 * Google requires the FAQ content to be visible on the page for the
 * FAQPage rich result to qualify. This class ensures that.
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

        // Respect per-post disable flag
        $id = get_the_ID();
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
        // Only output on pages that have FAQ
        if ( ! is_singular() ) return;
        $faq = get_post_meta( get_queried_object_id(), '_gml_seo_faq', true );
        if ( empty( $faq ) ) return;
        ?>
<style id="gml-seo-faq-styles">
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
