<?php
/**
 * GA4 Data API integration for conversion-aware SEO insights.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_GA4 {

    const INSIGHTS_OPTION = 'gml_seo_ga4_insights';
    const TOKEN_TRANSIENT = 'gml_seo_ga4_token';
    const ENDPOINT        = 'https://analyticsdata.googleapis.com/v1beta/properties/%s:runReport';

    public static function sync() {
        $strategy    = GML_SEO_Strategy::get();
        $property_id = preg_replace( '/[^0-9]/', '', (string) ( $strategy['ga4_property_id'] ?? '' ) );
        if ( $property_id === '' ) {
            return new WP_Error( 'missing_property', 'GA4 Property ID is missing.' );
        }

        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $end   = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
        $start = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS * 29 );
        $url   = sprintf( self::ENDPOINT, rawurlencode( $property_id ) );

        $page_report = self::run_report( $url, $token, [
            'dateRanges' => [ [ 'startDate' => $start, 'endDate' => $end ] ],
            'dimensions' => [ [ 'name' => 'pagePath' ] ],
            'metrics'    => [
                [ 'name' => 'sessions' ],
                [ 'name' => 'activeUsers' ],
                [ 'name' => 'conversions' ],
                [ 'name' => 'engagementRate' ],
            ],
            'limit'      => 100,
            'orderBys'   => [ [ 'metric' => [ 'metricName' => 'sessions' ], 'desc' => true ] ],
        ] );
        if ( is_wp_error( $page_report ) ) {
            return $page_report;
        }

        $event_report = self::run_report( $url, $token, [
            'dateRanges' => [ [ 'startDate' => $start, 'endDate' => $end ] ],
            'dimensions' => [ [ 'name' => 'eventName' ] ],
            'metrics'    => [ [ 'name' => 'eventCount' ], [ 'name' => 'conversions' ] ],
            'limit'      => 100,
            'orderBys'   => [ [ 'metric' => [ 'metricName' => 'eventCount' ], 'desc' => true ] ],
        ] );
        if ( is_wp_error( $event_report ) ) {
            return $event_report;
        }

        $insights = self::build_insights( $page_report, $event_report, $start, $end, $property_id, $strategy );
        update_option( self::INSIGHTS_OPTION, $insights, false );

        return $insights;
    }

    public static function get_insights() {
        $insights = get_option( self::INSIGHTS_OPTION, [] );
        return is_array( $insights ) ? $insights : [];
    }

    public static function context_for_ai() {
        $insights = self::get_insights();
        if ( empty( $insights ) ) {
            return [];
        }

        return [
            'date_range'           => ( $insights['start_date'] ?? '' ) . ' to ' . ( $insights['end_date'] ?? '' ),
            'top_pages'            => array_slice( $insights['top_pages'] ?? [], 0, 10 ),
            'conversion_pages'     => array_slice( $insights['conversion_pages'] ?? [], 0, 10 ),
            'low_conversion_pages' => array_slice( $insights['low_conversion_pages'] ?? [], 0, 10 ),
            'top_events'           => array_slice( $insights['top_events'] ?? [], 0, 10 ),
            'target_events'        => $insights['target_events'] ?? '',
        ];
    }

    private static function run_report( $url, $token, array $body ) {
        $response = wp_remote_post( $url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 ) {
            $message = $data['error']['message'] ?? "GA4 Data API returned HTTP {$code}.";
            return new WP_Error( 'ga4_api', $message );
        }

        return is_array( $data ) ? $data : [];
    }

    private static function build_insights( array $page_report, array $event_report, $start, $end, $property_id, array $strategy ) {
        $pages = [];
        foreach ( $page_report['rows'] ?? [] as $row ) {
            $path        = $row['dimensionValues'][0]['value'] ?? '';
            $metrics     = $row['metricValues'] ?? [];
            $sessions    = (float) ( $metrics[0]['value'] ?? 0 );
            $users       = (float) ( $metrics[1]['value'] ?? 0 );
            $conversions = (float) ( $metrics[2]['value'] ?? 0 );
            $engagement  = (float) ( $metrics[3]['value'] ?? 0 );
            if ( $path === '' ) {
                continue;
            }

            $pages[] = [
                'page'            => $path,
                'sessions'        => $sessions,
                'active_users'    => $users,
                'conversions'     => $conversions,
                'engagement_rate' => $engagement,
                'conversion_rate' => $sessions > 0 ? $conversions / $sessions : 0,
            ];
        }

        $conversion_pages = array_values( array_filter( $pages, static function( $page ) {
            return $page['conversions'] > 0;
        } ) );
        usort( $conversion_pages, static function( $a, $b ) {
            return $b['conversions'] <=> $a['conversions'];
        } );

        $low_conversion_pages = array_values( array_filter( $pages, static function( $page ) {
            return $page['sessions'] >= 20 && $page['conversions'] <= 0;
        } ) );
        usort( $low_conversion_pages, static function( $a, $b ) {
            return $b['sessions'] <=> $a['sessions'];
        } );

        $events = [];
        foreach ( $event_report['rows'] ?? [] as $row ) {
            $event   = $row['dimensionValues'][0]['value'] ?? '';
            $metrics = $row['metricValues'] ?? [];
            if ( $event === '' ) {
                continue;
            }
            $events[] = [
                'event'       => $event,
                'event_count' => (float) ( $metrics[0]['value'] ?? 0 ),
                'conversions' => (float) ( $metrics[1]['value'] ?? 0 ),
            ];
        }

        return [
            'property_id'          => $property_id,
            'start_date'           => $start,
            'end_date'             => $end,
            'synced_at'            => current_time( 'mysql' ),
            'target_events'        => $strategy['conversion_events'] ?? '',
            'top_pages'            => array_slice( $pages, 0, 25 ),
            'conversion_pages'     => array_slice( $conversion_pages, 0, 25 ),
            'low_conversion_pages' => array_slice( $low_conversion_pages, 0, 25 ),
            'top_events'           => array_slice( $events, 0, 25 ),
        ];
    }

    private static function get_access_token() {
        $json = GML_SEO::opt( 'google_service_account', '' );
        if ( ! $json ) {
            return new WP_Error( 'missing_service_account', 'Google service account JSON is missing.' );
        }

        $cached = get_transient( self::TOKEN_TRANSIENT );
        if ( $cached ) {
            return $cached;
        }

        $sa = json_decode( $json, true );
        if ( ! is_array( $sa ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
            return new WP_Error( 'invalid_service_account', 'Google service account JSON is invalid.' );
        }

        $jwt = self::build_jwt( $sa, 'https://www.googleapis.com/auth/analytics.readonly' );
        if ( is_wp_error( $jwt ) ) {
            return $jwt;
        }

        $res = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ] );

        if ( is_wp_error( $res ) ) {
            return $res;
        }

        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( empty( $data['access_token'] ) ) {
            return new WP_Error( 'token_failed', 'Could not get Google access token.' );
        }

        set_transient( self::TOKEN_TRANSIENT, $data['access_token'], 45 * MINUTE_IN_SECONDS );
        return $data['access_token'];
    }

    private static function build_jwt( array $sa, $scope ) {
        $header = [ 'alg' => 'RS256', 'typ' => 'JWT' ];
        $now    = time();
        $claim  = [
            'iss'   => $sa['client_email'],
            'scope' => $scope,
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ];

        $h64 = rtrim( strtr( base64_encode( wp_json_encode( $header ) ), '+/', '-_' ), '=' );
        $c64 = rtrim( strtr( base64_encode( wp_json_encode( $claim ) ), '+/', '-_' ), '=' );
        $sig = '';
        $ok  = openssl_sign( $h64 . '.' . $c64, $sig, $sa['private_key'], 'SHA256' );
        if ( ! $ok ) {
            return new WP_Error( 'jwt_sign_failed', 'Could not sign Google service account JWT.' );
        }

        $s64 = rtrim( strtr( base64_encode( $sig ), '+/', '-_' ), '=' );
        return "{$h64}.{$c64}.{$s64}";
    }
}
