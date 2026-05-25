<?php
/**
 * Plugin Name: Fin Vault Watchlist Sync
 * Plugin URI: https://github.com/techwizhy/stock-dashboard
 * Description: Secure, zero-maintenance REST API backend to sync user watchlists for the Fin Vault Terminal on a WordPress website.
 * Version: 1.0.0
 * Author: Ashish & Antigravity
 * Author URI: https://github.com/techwizhy
 * License: GPL2
 * Text Domain: finvault-sync
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the custom REST API endpoints for Fin Vault.
 */
add_action( 'rest_api_init', 'finvault_register_watchlist_routes' );

function finvault_register_watchlist_routes() {
    // 1. Sync User Watchlist
    register_rest_route( 'finvault/v1', '/watchlist', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'finvault_get_user_watchlist',
            'permission_callback' => 'finvault_check_sync_permissions',
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'finvault_save_user_watchlist',
            'permission_callback' => 'finvault_check_sync_permissions',
            'args'                => array(
                'watchlist' => array(
                    'required'          => true,
                    'type'              => 'array',
                    'description'       => 'Array of starred stock / commodity ticker symbols.',
                    'validate_callback' => 'finvault_validate_watchlist_data',
                ),
            ),
        ),
    ) );

    // 2. Secure Yahoo Finance Public Proxy API
    register_rest_route( 'finvault/v1', '/market-data', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'finvault_get_market_data',
        'permission_callback' => '__return_true', // Publicly accessible CORS-friendly proxy!
    ) );
}

/**
 * Validate that the watchlist data is a clean, structured array of ticker symbols.
 */
function finvault_validate_watchlist_data( $param, $request, $key ) {
    if ( ! is_array( $param ) ) {
        return false;
    }
    // Limit to max 50 items to prevent resource exhaustion attacks
    if ( count( $param ) > 50 ) {
        return false;
    }
    // Check that each symbol is a clean alpha-numeric string
    foreach ( $param as $symbol ) {
        if ( ! is_string( $symbol ) || ! preg_match( '/^[A-Za-z0-9\/\s\(\)\.\:\-\#]+$/', $symbol ) ) {
            return false;
        }
    }
    return true;
}

/**
 * Restrict endpoint visibility to authenticated users with read capability (all logged-in members).
 */
function finvault_check_sync_permissions( $request ) {
    if ( ! is_user_logged_in() ) {
        return new WP_Error(
            'finvault_unauthorized',
            __( 'You must be logged in to sync your terminal watchlist.', 'finvault-sync' ),
            array( 'status' => 401 )
        );
    }
    
    // Fallback security check using WP native capabilities
    if ( ! current_user_can( 'read' ) ) {
        return new WP_Error(
            'finvault_forbidden',
            __( 'You do not have permission to access this financial resource.', 'finvault-sync' ),
            array( 'status' => 403 )
        );
    }
    
    return true;
}

/**
 * GET callback: Retrieve the watchlist for the logged-in user.
 */
function finvault_get_user_watchlist( $request ) {
    $user_id = get_current_user_id();
    $watchlist = get_user_meta( $user_id, 'finvault_watchlist', true );
    
    // Return empty array if meta is not set yet
    if ( ! is_array( $watchlist ) ) {
        $watchlist = array();
    }
    
    return rest_ensure_response( array(
        'success'   => true,
        'user_id'   => $user_id,
        'watchlist' => $watchlist,
    ) );
}

/**
 * POST callback: Validate and save the watchlist to the user profile meta.
 */
function finvault_save_user_watchlist( $request ) {
    $user_id = get_current_user_id();
    $watchlist = $request->get_param( 'watchlist' );
    
    // Sanitize every ticker symbol inside the array
    $sanitized_watchlist = array();
    foreach ( $watchlist as $symbol ) {
        $sanitized_watchlist[] = sanitize_text_field( strtoupper( trim( $symbol ) ) );
    }
    
    // Save to native wp_usermeta table safely
    $updated = update_user_meta( $user_id, 'finvault_watchlist', $sanitized_watchlist );
    
    return rest_ensure_response( array(
        'success'   => true,
        'message'   => __( 'Watchlist successfully synced to your profile.', 'finvault-sync' ),
        'watchlist' => $sanitized_watchlist,
    ) );
}

/**
 * Public GET callback: Fetch live, accurate stock, indices, commodities, and currency quotes from Yahoo Finance.
 */
function finvault_get_market_data( $request ) {
    $custom_symbols = $request->get_param( 'symbols' );
    
    // Create a unique cache key per parameter layout to prevent IP throttling
    $cache_key = 'finvault_market_data_' . ( ! empty( $custom_symbols ) ? md5( $custom_symbols ) : 'default' );
    $cached_data = get_transient( $cache_key );
    if ( false !== $cached_data ) {
        return rest_ensure_response( array(
            'success' => true,
            'source'  => 'cache',
            'data'    => $cached_data
        ) );
    }

    // Default fallbacks (Indices & Commodities)
    $symbols = array(
        'nifty'      => '^NSEI',
        'sensex'     => '^BSESN',
        'niftybank'  => '^NSEBANK',
        'niftyit'    => '^CNXIT',
        'dow'        => '^DJI',
        'sp500'      => '^GSPC',
        'nasdaq'     => '^IXIC',
        'gold'       => 'GC=F',
        'silver'     => 'SI=F',
        'crude'      => 'BZ=F',
        'usdinr'     => 'INR=X'
    );

    if ( ! empty( $custom_symbols ) ) {
        // Sanitize incoming comma-separated symbols securely
        $symbols_array = array_map( 'sanitize_text_field', explode( ',', $custom_symbols ) );
    } else {
        $symbols_array = array_values( $symbols );
    }

    $symbols_string = implode( ',', $symbols_array );
    $url = 'https://query1.finance.yahoo.com/v7/finance/quote?symbols=' . urlencode( $symbols_string );

    // Fetch from Yahoo Finance REST API securely (bypasses browser CORS completely!)
    $response = wp_remote_get( $url, array(
        'timeout' => 12,
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        )
    ) );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'api_error', 'Failed to retrieve real-time quotes.', array( 'status' => 500 ) );
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( empty( $data ) || ! isset( $data['quoteResponse']['result'] ) ) {
        return new WP_Error( 'parse_error', 'Invalid quotes data layout.', array( 'status' => 502 ) );
    }

    $raw_results = $data['quoteResponse']['result'];
    $results = array();

    // Map to custom payload structure compatible with frontend mapping
    foreach ( $raw_results as $quote ) {
        $symbol_key = $quote['symbol'];
        $default_key = array_search( $quote['symbol'], $symbols );
        $key = ( false !== $default_key ) ? $default_key : $symbol_key;

        $results[ $key ] = array(
            'symbol'    => $quote['symbol'],
            'name'      => isset( $quote['shortName'] ) ? $quote['shortName'] : $quote['symbol'],
            'ltp'       => isset( $quote['regularMarketPrice'] ) ? floatval( $quote['regularMarketPrice'] ) : 0.0,
            'change'    => isset( $quote['regularMarketChange'] ) ? floatval( $quote['regularMarketChange'] ) : 0.0,
            'chgPct'    => isset( $quote['regularMarketChangePercent'] ) ? floatval( $quote['regularMarketChangePercent'] ) : 0.0,
            'high'      => isset( $quote['regularMarketDayHigh'] ) ? floatval( $quote['regularMarketDayHigh'] ) : 0.0,
            'low'       => isset( $quote['regularMarketDayLow'] ) ? floatval( $quote['regularMarketDayLow'] ) : 0.0,
            'open'      => isset( $quote['regularMarketOpen'] ) ? floatval( $quote['regularMarketOpen'] ) : 0.0,
            'prevClose' => isset( $quote['regularMarketPreviousClose'] ) ? floatval( $quote['regularMarketPreviousClose'] ) : 0.0,
        );
    }

    // Cache the parsed response in WordPress Transient for 60 seconds (1 minute cache)
    set_transient( $cache_key, $results, 60 );

    return rest_ensure_response( array(
        'success' => true,
        'source'  => 'live',
        'data'    => $results
    ) );
}
