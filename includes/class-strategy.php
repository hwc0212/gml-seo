<?php
/**
 * Site-level SEO strategy settings used by AI prompts.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_Strategy {

    public static function defaults() {
        return [
            'site_type'          => 'b2b',
            'primary_markets'    => '',
            'target_languages'   => '',
            'core_offerings'     => '',
            'customer_profile'   => '',
            'conversion_goals'   => 'inquiry, contact form, WhatsApp',
            'brand_voice'        => 'professional, clear, trustworthy',
            'must_use_terms'     => '',
            'avoid_terms'        => '',
            'competitors'        => '',
            'gsc_property_url'   => '',
            'ga4_property_id'     => '',
            'conversion_events'   => 'generate_lead, purchase, form_submit',
            'analytics_notes'    => '',
        ];
    }

    public static function get() {
        $saved = get_option( 'gml_seo_strategy', [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
        return array_merge( self::defaults(), $saved );
    }

    public static function sanitize( $in ) {
        $in  = is_array( $in ) ? $in : [];
        $out = self::defaults();

        $out['site_type'] = in_array( $in['site_type'] ?? '', [ 'b2b', 'ecommerce', 'blog', 'corporate', 'local_service', 'saas', 'other' ], true )
            ? $in['site_type']
            : 'b2b';

        foreach ( [ 'primary_markets', 'target_languages', 'core_offerings', 'customer_profile', 'conversion_goals', 'brand_voice', 'must_use_terms', 'avoid_terms', 'competitors', 'conversion_events', 'analytics_notes' ] as $key ) {
            $out[ $key ] = isset( $in[ $key ] ) ? sanitize_textarea_field( $in[ $key ] ) : '';
        }

        $out['gsc_property_url'] = isset( $in['gsc_property_url'] ) ? esc_url_raw( $in['gsc_property_url'] ) : '';
        $out['ga4_property_id']  = isset( $in['ga4_property_id'] ) ? preg_replace( '/[^0-9]/', '', sanitize_text_field( $in['ga4_property_id'] ) ) : '';

        return $out;
    }

    public static function context_for_ai() {
        $s = self::get();
        $labels = [
            'b2b'           => 'B2B / lead generation',
            'ecommerce'     => 'Ecommerce / WooCommerce',
            'blog'          => 'Content / blog',
            'corporate'     => 'Corporate website',
            'local_service' => 'Local service business',
            'saas'          => 'SaaS / software',
            'other'         => 'Other',
        ];

        $context = [
            'site_type'        => $labels[ $s['site_type'] ] ?? $s['site_type'],
            'primary_markets'  => $s['primary_markets'],
            'target_languages' => $s['target_languages'],
            'core_offerings'   => $s['core_offerings'],
            'customer_profile' => $s['customer_profile'],
            'conversion_goals' => $s['conversion_goals'],
            'brand_voice'      => $s['brand_voice'],
            'must_use_terms'   => $s['must_use_terms'],
            'avoid_terms'      => $s['avoid_terms'],
            'competitors'      => $s['competitors'],
            'conversion_events'=> $s['conversion_events'],
            'analytics_notes'  => $s['analytics_notes'],
        ];

        if ( class_exists( 'GML_SEO_Search_Console' ) ) {
            $gsc = GML_SEO_Search_Console::context_for_ai();
            if ( ! empty( $gsc ) ) {
                $context['search_console_insights'] = $gsc;
            }
        }

        if ( class_exists( 'GML_SEO_GA4' ) ) {
            $ga4 = GML_SEO_GA4::context_for_ai();
            if ( ! empty( $ga4 ) ) {
                $context['ga4_insights'] = $ga4;
            }
        }

        return array_filter( $context, static function( $value ) {
            return $value !== '';
        } );
    }
}
