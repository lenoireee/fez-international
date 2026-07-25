<?php
/**
 * Premier Dispatch — Shared Fez API Layer
 *
 * Defines auth, logging, and HTTP helpers shared by both
 * Premier Dispatch (International) and Premier Dispatch (Domestic).
 *
 * Each plugin includes this file but all functions are guarded with
 * function_exists() so only the first plugin to load defines them.
 * Both plugins then share the same implementations and the same token.
 *
 * Do not call these functions before 'plugins_loaded' fires.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ---------------------------------------------------------------------------
// SHARED CONSTANTS
// Defined once — guarded so the second plugin doesn't redefine them.
// ---------------------------------------------------------------------------
if ( ! defined( 'FEZ_API_BASE' ) ) {
    define( 'FEZ_API_BASE', 'https://apisandbox.fezdelivery.co/v1' );
}

// Shared token key — both plugins read/write this exact key.
// NOT blog-scoped so the token is shared across the whole WordPress install.
if ( ! defined( 'PD_SHARED_AUTH_KEY' ) ) {
    define( 'PD_SHARED_AUTH_KEY', 'pd_dom_auth_token' );
}

if ( ! defined( 'PD_AUTH_LOCK_KEY' ) ) {
    define( 'PD_AUTH_LOCK_KEY', 'pd_auth_lock' );
}

// Token TTL in seconds. Both plugins use this value.
// Set slightly under 1 hour so the token is refreshed proactively
// rather than expiring mid-request.
if ( ! defined( 'PD_TOKEN_TTL' ) ) {
    define( 'PD_TOKEN_TTL', 55 * MINUTE_IN_SECONDS );
}


// ---------------------------------------------------------------------------
// LOGGING
// Format: PD|CONTEXT|LEVEL|message[|json_data]
// Grep instantly: grep "PD|" /path/to/error.log
// grep "PD|AUTH" — auth issues only
// grep "PD|FETCH|ERROR" — failed price fetches
// ---------------------------------------------------------------------------
if ( ! function_exists( 'pd_log' ) ) {
    function pd_log( $context, $level, $message, $data = [] ) {
        $entry = sprintf( 'PD|%s|%s|%s', strtoupper( $context ), strtoupper( $level ), $message );
        if ( ! empty( $data ) ) {
            $entry .= '|' . wp_json_encode( $data );
        }
        error_log( $entry );

        // Persist last error so debug pages can surface it without log access
        if ( strtoupper( $level ) === 'ERROR' ) {
            $blog_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
            update_option( 'pd_last_error_' . $blog_id, [
                'context' => $context,
                'message' => $message,
                'data'    => $data,
                'at'      => time(),
            ], false );
        }
    }
}


// ---------------------------------------------------------------------------
// AUTH FAILURE DETECTOR
// Checks HTTP status code and common body error messages.
// Fez may return auth errors as 401, 403, or embedded in a 200 body.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'pd_is_auth_failure' ) ) {
    function pd_is_auth_failure( $response ) {
        if ( is_wp_error( $response ) ) return false;

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code === 401 || $code === 403 ) return true;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $msg  = strtolower( $body['message'] ?? $body['error'] ?? $body['msg'] ?? '' );

        return ( strpos( $msg, 'unauthorized' )    !== false
              || strpos( $msg, 'unauthenticated' ) !== false
              || strpos( $msg, 'invalid token' )   !== false
              || strpos( $msg, 'token expired' )   !== false
              || strpos( $msg, 'access denied' )   !== false );
    }
}


// ---------------------------------------------------------------------------
// AUTHENTICATED API REQUEST
// Injects auth headers. On auth failure: clears token, re-auths, retries once.
// Validates URL host against FEZ_API_BASE to prevent SSRF.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'pd_api_request' ) ) {
    function pd_api_request( $method, $url, $args = [] ) {
        // Host validation — block requests to unexpected hosts
        $parsed      = parse_url( $url );
        $expected    = parse_url( FEZ_API_BASE );
        $actual_host = $parsed['host'] ?? '';
        $expect_host = $expected['host'] ?? '';

        if ( ! $actual_host || $actual_host !== $expect_host ) {
            pd_log( 'HTTP', 'ERROR', 'Request blocked — unexpected host', [
                'actual'   => $actual_host,
                'expected' => $expect_host,
            ] );
            return new WP_Error( 'pd_invalid_url', 'Request blocked: unexpected API host.' );
        }

        $auth = pd_fez_auth();
        if ( ! $auth ) {
            return new WP_Error( 'pd_auth_failed', 'Authentication failed.' );
        }

        $args['method']  = strtoupper( $method );
        $args['headers'] = array_merge( $args['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $auth['token'],
            'secret-key'    => $auth['secret'],
        ] );

        $response = wp_remote_request( $url, $args );

        // Auth failure — clear token, re-authenticate, retry once
        if ( pd_is_auth_failure( $response ) ) {
            pd_log( 'AUTH', 'WARN', 'Auth failure on API call — clearing token and retrying', [ 'url' => $url ] );
            delete_transient( PD_SHARED_AUTH_KEY );

            $auth = pd_fez_auth( true );
            if ( ! $auth ) {
                pd_log( 'AUTH', 'ERROR', 'Re-authentication failed after 401' );
                return new WP_Error( 'pd_reauth_failed', 'Re-authentication failed after 401.' );
            }

            $args['headers'] = array_merge( $args['headers'], [
                'Authorization' => 'Bearer ' . $auth['token'],
                'secret-key'    => $auth['secret'],
            ] );
            $response = wp_remote_request( $url, $args );
        }

        return $response;
    }
}


// ---------------------------------------------------------------------------
// AUTHENTICATION
// Shared between both plugins via the PD_SHARED_AUTH_KEY transient.
// Whichever plugin authenticates, both immediately benefit from the token.
//
// force_refresh = true — always hits the API (used at start of sync runs).
// force_refresh = false — returns cached token if valid.
//
// Concurrency lock prevents duplicate auth requests when multiple PHP
// processes start simultaneously and all find an empty token cache.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'pd_fez_auth' ) ) {
    function pd_fez_auth( $force_refresh = false ) {
        if ( ! defined( 'FEZ_USER_ID' ) || ! defined( 'FEZ_PASSWORD' ) ) {
            pd_log( 'AUTH', 'ERROR', 'FEZ_USER_ID or FEZ_PASSWORD not defined in wp-config.php' );
            return false;
        }

        $token_key = PD_SHARED_AUTH_KEY;
        $lock_key  = PD_AUTH_LOCK_KEY;

        // Return cached token if not forcing refresh
        if ( ! $force_refresh ) {
            $cached = get_transient( $token_key );
            if ( $cached && isset( $cached['token'], $cached['secret'] ) ) {
                return $cached;
            }
        }

        // Concurrency lock — another process is mid-auth
        if ( get_transient( $lock_key ) ) {
            usleep( 400000 ); // 0.4s — wait for the other process to finish
            $cached = get_transient( $token_key );
            if ( $cached && isset( $cached['token'], $cached['secret'] ) ) {
                return $cached;
            }
            pd_log( 'AUTH', 'WARN', 'Auth lock active — concurrent request could not obtain token' );
            return false;
        }

        set_transient( $lock_key, 1, 30 ); // 30s lock

        $res = wp_remote_post( FEZ_API_BASE . '/user/authenticate', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'user_id' => FEZ_USER_ID, 'password' => FEZ_PASSWORD ] ),
            'timeout' => 10,
        ] );

        delete_transient( $lock_key );

        if ( is_wp_error( $res ) ) {
            pd_log( 'AUTH', 'ERROR', 'Auth request failed', [ 'error' => $res->get_error_message() ] );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( empty( $body['authDetails']['authToken'] ) ) {
            pd_log( 'AUTH', 'ERROR', 'Auth response missing token', [ 'body' => $body ] );
            return false;
        }

        $auth = [
            'token'     => $body['authDetails']['authToken'],
            'secret'    => $body['orgDetails']['secret-key'] ?? '',
            'cached_at' => time(),
        ];

        set_transient( $token_key, $auth, PD_TOKEN_TTL );
        pd_log( 'AUTH', 'INFO', 'Token refreshed', [ 'ttl_min' => round( PD_TOKEN_TTL / 60 ) ] );

        return $auth;
    }
}