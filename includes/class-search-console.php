<?php
/**
 * Google Search Console integration for strategy insights.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_Search_Console {

    const INSIGHTS_OPTION = 'gml_seo_gsc_insights';
    const TOKEN_TRANSIENT = 'gml_seo_gsc_token';
    const ENDPOINT        = 'https://www.googleapis.com/webmasters/v3/sites/%s/searchAnalytics/query';

    public static function sync() {
        $strategy = GML_SEO_Strategy::get();
        $site_url = trim( (string) ( $strategy['gsc_property_url'] ?? '' ) );
        if ( $site_url === '' ) {
            return new WP_Error( 'missing_property', 'Search Console Property URL is missing.' );
        }

        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $end   = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS * 2 );
        $start = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS * 30 );
        $url   = sprintf( self::ENDPOINT, rawurlencode( $site_url ) );

        $response = wp_remote_post( $url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'startDate'  => $start,
                'endDate'    => $end,
                'dimensions' => [ 'query', 'page' ],
                'rowLimit'   => 250,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 ) {
            $message = $body['error']['message'] ?? "Search Console API returned HTTP {$code}.";
            return new WP_Error( 'gsc_api', $message );
        }

        $insights = self::build_insights( $body['rows'] ?? [], $start, $end, $site_url );
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
            'date_range'          => ( $insights['start_date'] ?? '' ) . ' to ' . ( $insights['end_date'] ?? '' ),
            'top_queries'         => array_slice( $insights['top_queries'] ?? [], 0, 10 ),
            'low_ctr_queries'     => array_slice( $insights['low_ctr_queries'] ?? [], 0, 10 ),
            'striking_distance'   => array_slice( $insights['striking_distance_queries'] ?? [], 0, 10 ),
            'top_pages'           => array_slice( $insights['top_pages'] ?? [], 0, 10 ),
        ];
    }

    private static function build_insights( array $rows, $start, $end, $site_url ) {
        $queries = [];
        $pages   = [];

        foreach ( $rows as $row ) {
            $keys  = $row['keys'] ?? [];
            $query = isset( $keys[0] ) ? (string) $keys[0] : '';
            $page  = isset( $keys[1] ) ? (string) $keys[1] : '';
            if ( $query === '' ) {
                continue;
            }

            if ( ! isset( $queries[ $query ] ) ) {
                $queries[ $query ] = [
                    'query'       => $query,
                    'clicks'      => 0,
                    'impressions' => 0,
                    'ctr_sum'     => 0,
                    'position_sum'=> 0,
                    'rows'        => 0,
                ];
            }
            $queries[ $query ]['clicks']       += (float) ( $row['clicks'] ?? 0 );
            $queries[ $query ]['impressions']  += (float) ( $row['impressions'] ?? 0 );
            $queries[ $query ]['ctr_sum']      += (float) ( $row['ctr'] ?? 0 );
            $queries[ $query ]['position_sum'] += (float) ( $row['position'] ?? 0 );
            $queries[ $query ]['rows']++;

            if ( $page !== '' ) {
                if ( ! isset( $pages[ $page ] ) ) {
                    $pages[ $page ] = [
                        'page'        => $page,
                        'clicks'      => 0,
                        'impressions' => 0,
                    ];
                }
                $pages[ $page ]['clicks']      += (float) ( $row['clicks'] ?? 0 );
                $pages[ $page ]['impressions'] += (float) ( $row['impressions'] ?? 0 );
            }
        }

        foreach ( $queries as &$q ) {
            $q['ctr']      = $q['impressions'] > 0 ? $q['clicks'] / $q['impressions'] : 0;
            $q['position'] = $q['rows'] > 0 ? $q['position_sum'] / $q['rows'] : 0;
            unset( $q['ctr_sum'], $q['position_sum'], $q['rows'] );
        }
        unset( $q );

        usort( $queries, static function( $a, $b ) {
            return $b['clicks'] <=> $a['clicks'];
        } );

        $low_ctr = array_values( array_filter( $queries, static function( $q ) {
            return $q['impressions'] >= 50 && $q['ctr'] < 0.02;
        } ) );
        usort( $low_ctr, static function( $a, $b ) {
            return $b['impressions'] <=> $a['impressions'];
        } );

        $striking = array_values( array_filter( $queries, static function( $q ) {
            return $q['position'] >= 8 && $q['position'] <= 20 && $q['impressions'] >= 20;
        } ) );
        usort( $striking, static function( $a, $b ) {
            return $a['position'] <=> $b['position'];
        } );

        usort( $pages, static function( $a, $b ) {
            return $b['clicks'] <=> $a['clicks'];
        } );

        return [
            'site_url'                  => $site_url,
            'start_date'                => $start,
            'end_date'                  => $end,
            'synced_at'                 => current_time( 'mysql' ),
            'top_queries'               => array_slice( $queries, 0, 25 ),
            'low_ctr_queries'           => array_slice( $low_ctr, 0, 25 ),
            'striking_distance_queries' => array_slice( $striking, 0, 25 ),
            'top_pages'                 => array_slice( array_values( $pages ), 0, 25 ),
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

        $jwt = self::build_jwt( $sa, 'https://www.googleapis.com/auth/webmasters.readonly' );
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
