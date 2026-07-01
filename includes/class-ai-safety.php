<?php
/**
 * AI output safety checks and SEO change history.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_AI_Safety {

    const HISTORY_META = '_gml_seo_history';
    const SAFETY_META  = '_gml_seo_safety_issues';

    public static function validate_result( array $result, array $page_data ) {
        $issues = [];

        $title = trim( (string) ( $result['title'] ?? '' ) );
        $desc  = trim( (string) ( $result['desc'] ?? '' ) );

        if ( $title === '' ) {
            $issues[] = self::issue( 'critical', 'title', 'AI did not return an SEO title.', 'Keep the current title and regenerate.' );
        } elseif ( mb_strlen( $title ) > 70 ) {
            $issues[] = self::issue( 'warning', 'title', 'SEO title is longer than 70 characters.', 'Shorten it to about 50-60 characters.' );
        }

        if ( $desc === '' ) {
            $issues[] = self::issue( 'critical', 'description', 'AI did not return a meta description.', 'Keep the current description and regenerate.' );
        } elseif ( mb_strlen( $desc ) > 180 ) {
            $issues[] = self::issue( 'warning', 'description', 'Meta description is longer than 180 characters.', 'Shorten it to about 120-155 characters.' );
        } elseif ( mb_strlen( $desc ) < 80 ) {
            $issues[] = self::issue( 'info', 'description', 'Meta description is quite short.', 'Consider adding a clearer value proposition.' );
        }

        $primary = trim( (string) ( $result['primary_keyword'] ?? '' ) );
        if ( $primary !== '' && $title !== '' && substr_count( mb_strtolower( $title ), mb_strtolower( $primary ) ) > 1 ) {
            $issues[] = self::issue( 'warning', 'keyword', 'Primary keyword appears repeatedly in the title.', 'Use the keyword once, naturally.' );
        }

        foreach ( [ 'best', 'guaranteed', 'cheapest', 'number one', '#1' ] as $claim ) {
            if ( stripos( $title . ' ' . $desc, $claim ) !== false ) {
                $issues[] = self::issue( 'warning', 'claims', 'AI output contains a strong promotional claim: ' . $claim, 'Verify the claim or rewrite it more neutrally.' );
            }
        }

        $strategy = $page_data['site_strategy'] ?? [];
        if ( is_array( $strategy ) && ! empty( $strategy['avoid_terms'] ) ) {
            $avoid_terms = preg_split( '/[\r\n,]+/', (string) $strategy['avoid_terms'] );
            foreach ( $avoid_terms as $term ) {
                $term = trim( $term );
                if ( $term !== '' && stripos( $title . ' ' . $desc, $term ) !== false ) {
                    $issues[] = self::issue( 'warning', 'brand_safety', 'AI output contains a configured avoid term: ' . $term, 'Remove the term or update the SEO strategy if this term is now allowed.' );
                }
            }
        }

        if ( ! empty( $result['goal_scores'] ) && is_array( $result['goal_scores'] ) ) {
            $goal = $result['goal_scores'];
            $risk = isset( $goal['risk'] ) ? (int) $goal['risk'] : null;
            if ( $risk !== null && $risk >= 85 ) {
                $issues[] = self::issue( 'critical', 'ai_risk', 'AI self-assessed this recommendation as high risk (' . $risk . '/100).', 'Keep it in suggestion mode and review the risk reasons before applying.' );
            } elseif ( $risk !== null && $risk >= 70 ) {
                $issues[] = self::issue( 'warning', 'ai_risk', 'AI self-assessed this recommendation as elevated risk (' . $risk . '/100).', 'Review the recommendation before applying automatically.' );
            }

            $business_fit = isset( $goal['business_fit'] ) ? (int) $goal['business_fit'] : null;
            if ( $business_fit !== null && $business_fit < 40 ) {
                $issues[] = self::issue( 'warning', 'business_fit', 'AI output has low business-goal fit (' . $business_fit . '/100).', 'Regenerate after improving site strategy or page context.' );
            }

            $conversion_intent = isset( $goal['conversion_intent'] ) ? (int) $goal['conversion_intent'] : null;
            if ( $conversion_intent !== null && $conversion_intent < 35 ) {
                $issues[] = self::issue( 'info', 'conversion', 'AI output has weak conversion-intent fit (' . $conversion_intent . '/100).', 'Consider adding clearer CTA, audience, or conversion goal context.' );
            }
        }

        if ( ! empty( $result['faq'] ) && is_array( $result['faq'] ) ) {
            foreach ( $result['faq'] as $idx => $item ) {
                $answer = trim( wp_strip_all_tags( (string) ( $item['a'] ?? '' ) ) );
                if ( $answer !== '' && mb_strlen( $answer ) < 40 ) {
                    $issues[] = self::issue( 'info', 'faq', 'FAQ answer #' . ( $idx + 1 ) . ' is very short.', 'Expand the answer or remove the FAQ item.' );
                }
            }
        }

        $schema_type = $result['schema_type'] ?? '';
        $content     = mb_strtolower( (string) ( $page_data['content'] ?? '' ) );
        if ( $schema_type === 'HowTo' && strpos( $content, 'step' ) === false && strpos( $content, '步骤' ) === false ) {
            $issues[] = self::issue( 'warning', 'schema', 'HowTo schema was suggested but visible step-by-step content was not obvious.', 'Use WebPage/Article unless the page visibly contains steps.' );
        }
        if ( $schema_type === 'Review' && strpos( $content, 'review' ) === false && strpos( $content, '评价' ) === false ) {
            $issues[] = self::issue( 'warning', 'schema', 'Review schema was suggested but visible review content was not obvious.', 'Use Review only when the page visibly contains a review.' );
        }

        $blocking = array_values( array_filter( $issues, static function( $issue ) {
            return in_array( $issue['severity'], [ 'critical', 'warning' ], true );
        } ) );

        return [
            'passed'   => empty( $blocking ),
            'blocking' => $blocking,
            'issues'   => $issues,
        ];
    }

    public static function save_safety( $post_id, array $safety ) {
        if ( empty( $safety['issues'] ) ) {
            delete_post_meta( $post_id, self::SAFETY_META );
            return;
        }
        update_post_meta( $post_id, self::SAFETY_META, $safety['issues'] );
    }

    public static function record_history( $post_id, array $result, array $safety, $mode ) {
        $history = get_post_meta( $post_id, self::HISTORY_META, true );
        if ( ! is_array( $history ) ) {
            $history = [];
        }

        $entry = [
            'time'   => current_time( 'mysql' ),
            'mode'   => sanitize_key( $mode ),
            'engine' => GML_SEO::opt( 'engine', 'gemini' ),
            'before' => [
                'title'    => get_post_meta( $post_id, '_gml_seo_title', true ),
                'desc'     => get_post_meta( $post_id, '_gml_seo_desc', true ),
                'og_title' => get_post_meta( $post_id, '_gml_seo_og_title', true ),
                'og_desc'  => get_post_meta( $post_id, '_gml_seo_og_desc', true ),
                'keywords' => get_post_meta( $post_id, '_gml_seo_keywords', true ),
            ],
            'after' => [
                'title'    => sanitize_text_field( $result['title'] ?? '' ),
                'desc'     => sanitize_text_field( $result['desc'] ?? '' ),
                'og_title' => sanitize_text_field( $result['og_title'] ?? '' ),
                'og_desc'  => sanitize_text_field( $result['og_desc'] ?? '' ),
                'keywords' => sanitize_text_field( $result['keywords'] ?? '' ),
            ],
            'safety_passed' => ! empty( $safety['passed'] ),
            'safety_issues' => $safety['issues'] ?? [],
        ];

        array_unshift( $history, $entry );
        $history = array_slice( $history, 0, 20 );
        update_post_meta( $post_id, self::HISTORY_META, $history );
    }

    private static function issue( $severity, $category, $message, $fix ) {
        return [
            'severity' => $severity,
            'category' => $category,
            'message'  => $message,
            'fix'      => $fix,
        ];
    }
}
