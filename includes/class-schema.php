<?php
/**
 * Auto JSON-LD structured data.
 *
 * Outputs schema.org markup automatically based on post type:
 *  - WebSite (homepage)
 *  - Article / BlogPosting (posts)
 *  - WebPage (pages)
 *  - Product (WooCommerce products)
 *  - BreadcrumbList (all pages)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_Schema {

    public function __construct() {
        add_action( 'wp_head', [ $this, 'render' ], 2 );
    }

    public function render() {
        $schemas = [];

        if ( is_front_page() ) {
            $schemas[] = $this->website_schema();
        }

        if ( is_singular() ) {
            $id   = get_queried_object_id();
            $post = get_post( $id );

            if ( $post->post_type === 'product' && function_exists( 'wc_get_product' ) ) {
                $schemas[] = $this->product_schema( $id );
            } elseif ( $post->post_type === 'post' ) {
                $schemas[] = $this->article_schema( $id );
            } else {
                $schemas[] = $this->webpage_schema( $id );
            }

            $schemas[] = $this->breadcrumb_schema( $id );

            // FAQ schema (if AI generated FAQ for this post)
            $faq_schema = $this->faq_schema( $id );
            if ( $faq_schema ) $schemas[] = $faq_schema;
        }

        foreach ( $schemas as $s ) {
            if ( $s ) {
                echo '<script type="application/ld+json">' . wp_json_encode( $s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
            }
        }
    }

    // ── WebSite ──────────────────────────────────────────────────────

    private function website_schema() {
        $name = GML_SEO::opt( 'site_name', get_bloginfo( 'name' ) );
        return [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $name,
            'url'      => home_url( '/' ),
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => home_url( '/?s={search_term_string}' ),
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    // ── Article ──────────────────────────────────────────────────────

    private function article_schema( $id ) {
        $post   = get_post( $id );
        $author = get_the_author_meta( 'display_name', $post->post_author );
        $img    = get_the_post_thumbnail_url( $id, 'large' );
        $desc   = get_post_meta( $id, '_gml_seo_desc', true ) ?: wp_trim_words( $post->post_content, 30 );

        $schema = [
            '@context'         => 'https://schema.org',
            '@type'            => 'Article',
            'headline'         => get_the_title( $id ),
            'description'      => $desc,
            'url'              => get_permalink( $id ),
            'datePublished'    => get_the_date( 'c', $id ),
            'dateModified'     => get_the_modified_date( 'c', $id ),
            'author'           => [ '@type' => 'Person', 'name' => $author ],
            'publisher'        => [
                '@type' => 'Organization',
                'name'  => GML_SEO::opt( 'site_name', get_bloginfo( 'name' ) ),
            ],
            'mainEntityOfPage' => [ '@type' => 'WebPage', '@id' => get_permalink( $id ) ],
        ];

        if ( $img ) {
            $schema['image'] = $img;
        }

        return $schema;
    }

    // ── WebPage ──────────────────────────────────────────────────────

    private function webpage_schema( $id ) {
        $desc = get_post_meta( $id, '_gml_seo_desc', true );
        return [
            '@context'    => 'https://schema.org',
            '@type'       => 'WebPage',
            'name'        => get_the_title( $id ),
            'description' => $desc ?: '',
            'url'         => get_permalink( $id ),
        ];
    }

    // ── Product (WooCommerce) ────────────────────────────────────────

    private function product_schema( $id ) {
        $product = wc_get_product( $id );
        if ( ! $product ) return null;

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Product',
            'name'        => $product->get_name(),
            'description' => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
            'url'         => get_permalink( $id ),
            'sku'         => $product->get_sku(),
        ];

        $img = wp_get_attachment_url( $product->get_image_id() );
        if ( $img ) $schema['image'] = $img;

        // Offers
        $schema['offers'] = [
            '@type'         => 'Offer',
            'price'         => $product->get_price(),
            'priceCurrency' => get_woocommerce_currency(),
            'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url'           => get_permalink( $id ),
        ];

        // Reviews
        if ( $product->get_review_count() > 0 ) {
            $schema['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => $product->get_average_rating(),
                'reviewCount' => $product->get_review_count(),
            ];
        }

        // Brand from attribute or tag
        $brand = $product->get_attribute( 'brand' ) ?: $product->get_attribute( 'pa_brand' );
        if ( $brand ) {
            $schema['brand'] = [ '@type' => 'Brand', 'name' => $brand ];
        }

        return $schema;
    }

    // ── FAQ ──────────────────────────────────────────────────────────

    private function faq_schema( $id ) {
        $faq = get_post_meta( $id, '_gml_seo_faq', true );
        if ( empty( $faq ) || ! is_array( $faq ) ) return null;

        $main = [];
        foreach ( $faq as $item ) {
            if ( empty( $item['q'] ) || empty( $item['a'] ) ) continue;
            $main[] = [
                '@type'          => 'Question',
                'name'           => $item['q'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => wp_strip_all_tags( $item['a'] ),
                ],
            ];
        }

        if ( empty( $main ) ) return null;

        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $main,
        ];
    }

    // ── Breadcrumb ───────────────────────────────────────────────────

    private function breadcrumb_schema( $id ) {
        $items = [];
        $pos   = 1;

        $items[] = [
            '@type'    => 'ListItem',
            'position' => $pos++,
            'name'     => __( 'Home', 'gml-seo' ),
            'item'     => home_url( '/' ),
        ];

        $post = get_post( $id );

        // Category for posts
        if ( $post->post_type === 'post' ) {
            $cats = get_the_category( $id );
            if ( ! empty( $cats ) ) {
                $cat = $cats[0];
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => $pos++,
                    'name'     => $cat->name,
                    'item'     => get_category_link( $cat->term_id ),
                ];
            }
        }

        // Product category
        if ( $post->post_type === 'product' ) {
            $terms = get_the_terms( $id, 'product_cat' );
            if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                $term = $terms[0];
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => $pos++,
                    'name'     => $term->name,
                    'item'     => get_term_link( $term ),
                ];
            }
        }

        // Current page
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $pos,
            'name'     => get_the_title( $id ),
        ];

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }
}
