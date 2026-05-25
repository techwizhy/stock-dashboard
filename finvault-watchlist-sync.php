<?php
/**
 * Plugin Name: Fin Vault Watchlist Sync
 * Plugin URI: https://github.com/techwizhy/stock-dashboard
 * Description: Secure REST API backend to sync user watchlists and serve live market data
 *              via NSE India (Indian stocks/indices) and Yahoo Finance v8 (global/commodities).
 * Version: 2.0.0
 * Author: Ashish & Antigravity
 * Author URI: https://github.com/techwizhy
 * License: GPL2
 * Text Domain: finvault-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// SECTION 1: REST ROUTE REGISTRATION
// ============================================================

add_action( 'rest_api_init', 'finvault_register_watchlist_routes' );

function finvault_register_watchlist_routes() {

    // --- Watchlist GET ---
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
                    'description'       => 'Array of ticker symbols.',
                    'validate_callback' => 'finvault_validate_watchlist_data',
                ),
            ),
        ),
    ) );

    // --- Live Market Data (public proxy endpoint) ---
    register_rest_route( 'finvault/v1', '/market-data', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'finvault_get_market_data',
        'permission_callback' => '__return_true',
    ) );

    // --- Cache Busting (admin only) ---
    register_rest_route( 'finvault/v1', '/clear-cache', array(
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => 'finvault_clear_market_cache',
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
    ) );
}

// ============================================================
// SECTION 2: WATCHLIST ENDPOINTS (unchanged)
// ============================================================

function finvault_validate_watchlist_data( $param, $request, $key ) {
    if ( ! is_array( $param ) ) return false;
    if ( count( $param ) > 50 ) return false;
    foreach ( $param as $symbol ) {
        if ( ! is_string( $symbol ) || ! preg_match( '/^[A-Za-z0-9\/\s\(\)\.\:\-\#\&]+$/', $symbol ) ) {
            return false;
        }
    }
    return true;
}

function finvault_check_sync_permissions( $request ) {
    if ( ! is_user_logged_in() ) {
        return new WP_Error( 'finvault_unauthorized', __( 'You must be logged in.', 'finvault-sync' ), array( 'status' => 401 ) );
    }
    if ( ! current_user_can( 'read' ) ) {
        return new WP_Error( 'finvault_forbidden', __( 'Permission denied.', 'finvault-sync' ), array( 'status' => 403 ) );
    }
    return true;
}

function finvault_get_user_watchlist( $request ) {
    $user_id   = get_current_user_id();
    $watchlist = get_user_meta( $user_id, 'finvault_watchlist', true );
    if ( ! is_array( $watchlist ) ) $watchlist = array();
    return rest_ensure_response( array( 'success' => true, 'user_id' => $user_id, 'watchlist' => $watchlist ) );
}

function finvault_save_user_watchlist( $request ) {
    $user_id   = get_current_user_id();
    $watchlist = $request->get_param( 'watchlist' );
    $sanitized = array();
    foreach ( $watchlist as $symbol ) {
        $sanitized[] = sanitize_text_field( strtoupper( trim( $symbol ) ) );
    }
    update_user_meta( $user_id, 'finvault_watchlist', $sanitized );
    return rest_ensure_response( array( 'success' => true, 'message' => 'Watchlist synced.', 'watchlist' => $sanitized ) );
}

function finvault_clear_market_cache( $request ) {
    delete_transient( 'finvault_market_data_v2' );
    delete_transient( 'finvault_nse_cookies' );
    delete_transient( 'finvault_yahoo_crumb' );
    return rest_ensure_response( array( 'success' => true, 'message' => 'Market data cache cleared.' ) );
}

// ============================================================
// SECTION 3: LIVE MARKET DATA — MAIN ENDPOINT
// ============================================================

/**
 * Master market data endpoint.
 * Priority 1: NSE India API   → Indian indices + all Nifty 50 stocks
 * Priority 2: Yahoo Finance v8 → Global indices, commodities, USD/INR
 * Caches final merged payload in WP Transient for 60 seconds.
 */
function finvault_get_market_data( $request ) {

    $cache_key    = 'finvault_market_data_v2';
    $cached_data  = get_transient( $cache_key );

    if ( false !== $cached_data ) {
        return rest_ensure_response( array(
            'success' => true,
            'source'  => 'cache',
            'data'    => $cached_data,
        ) );
    }

    $results = array();

    // --- Pull Indian data from NSE India ---
    $nse_data = finvault_fetch_nse_market_data();
    if ( ! empty( $nse_data ) ) {
        $results = array_merge( $results, $nse_data );
    }

    // --- Pull global data from Yahoo Finance v8 ---
    $yahoo_symbols = array( '^DJI', '^GSPC', '^IXIC', '^BSESN', 'GC=F', 'SI=F', 'BZ=F', 'INR=X' );
    $yahoo_data    = finvault_fetch_yahoo_market_data( $yahoo_symbols );
    if ( ! empty( $yahoo_data ) ) {
        $results = array_merge( $results, $yahoo_data );
    }

    if ( empty( $results ) ) {
        return new WP_Error( 'fetch_failed', 'All market data sources failed. Check server logs.', array( 'status' => 503 ) );
    }

    // Cache for 60 seconds (1 minute) — balances freshness vs server load
    set_transient( $cache_key, $results, 60 );

    return rest_ensure_response( array(
        'success' => true,
        'source'  => 'live',
        'data'    => $results,
    ) );
}

// ============================================================
// SECTION 4: NSE INDIA API INTEGRATION
// ============================================================

/**
 * Fetches Indian indices (Nifty 50, Nifty Bank, Nifty IT, Sensex via Yahoo)
 * and all Nifty 50 constituent stocks from NSE India's unofficial public API.
 *
 * NSE India blocks direct API calls without a valid session cookie.
 * We get it by visiting the NSE homepage first — exactly like a browser does.
 */
function finvault_fetch_nse_market_data() {
    $results = array();

    $cookies = finvault_get_nse_session_cookies();
    if ( empty( $cookies ) ) {
        error_log( '[FinVault] NSE cookie fetch failed. Skipping NSE data.' );
        return $results;
    }

    $common_headers = array(
        'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept'          => 'application/json, text/plain, */*',
        'Accept-Language' => 'en-US,en;q=0.9',
        'Accept-Encoding' => 'gzip, deflate, br',
        'Referer'         => 'https://www.nseindia.com/',
        'Connection'      => 'keep-alive',
        'Cookie'          => $cookies,
    );

    // ---- Fetch All NSE Indices ----
    $indices_res = wp_remote_get( 'https://www.nseindia.com/api/allIndices', array(
        'timeout' => 15,
        'headers' => $common_headers,
    ) );

    if ( ! is_wp_error( $indices_res ) && wp_remote_retrieve_response_code( $indices_res ) === 200 ) {
        $body = json_decode( wp_remote_retrieve_body( $indices_res ), true );
        if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
            foreach ( $body['data'] as $idx ) {
                $index_name = $idx['index'] ?? '';
                switch ( $index_name ) {
                    case 'NIFTY 50':
                        $results['^NSEI'] = finvault_map_nse_index_quote( $idx, '^NSEI', 'Nifty 50' );
                        break;
                    case 'NIFTY BANK':
                        $results['^NSEBANK'] = finvault_map_nse_index_quote( $idx, '^NSEBANK', 'Nifty Bank' );
                        break;
                    case 'NIFTY IT':
                        $results['^CNXIT'] = finvault_map_nse_index_quote( $idx, '^CNXIT', 'Nifty IT' );
                        break;
                }
            }
        }
    } else {
        error_log( '[FinVault] NSE allIndices API failed: ' . ( is_wp_error( $indices_res ) ? $indices_res->get_error_message() : wp_remote_retrieve_response_code( $indices_res ) ) );
    }

    // Small delay to avoid NSE rate-limiting
    usleep( 300000 ); // 300ms

    // ---- Fetch Nifty 50 Constituent Stocks ----
    $stocks_res = wp_remote_get( 'https://www.nseindia.com/api/equity-stockIndices?index=NIFTY%2050', array(
        'timeout' => 15,
        'headers' => $common_headers,
    ) );

    if ( ! is_wp_error( $stocks_res ) && wp_remote_retrieve_response_code( $stocks_res ) === 200 ) {
        $body = json_decode( wp_remote_retrieve_body( $stocks_res ), true );
        if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
            foreach ( $body['data'] as $stock ) {
                // Skip the index row itself (NSE returns it as first entry)
                if ( ! isset( $stock['symbol'] ) || $stock['symbol'] === 'NIFTY 50' ) {
                    continue;
                }
                $sym = strtoupper( trim( $stock['symbol'] ) );
                // Yahoo Finance appends .NS for NSE stocks — we use same key format
                $yahoo_key = $sym . '.NS';
                $results[ $yahoo_key ] = array(
                    'symbol'    => $yahoo_key,
                    'name'      => $stock['meta']['companyName'] ?? $sym,
                    'ltp'       => floatval( $stock['lastPrice'] ?? 0 ),
                    'change'    => floatval( $stock['change'] ?? 0 ),
                    'chgPct'    => floatval( $stock['pChange'] ?? 0 ),
                    'high'      => floatval( $stock['dayHigh'] ?? 0 ),
                    'low'       => floatval( $stock['dayLow'] ?? 0 ),
                    'open'      => floatval( $stock['open'] ?? 0 ),
                    'prevClose' => floatval( $stock['previousClose'] ?? 0 ),
                    'h52w'      => floatval( $stock['yearHigh'] ?? 0 ),
                    'l52w'      => floatval( $stock['yearLow'] ?? 0 ),
                );
            }
        }
    } else {
        error_log( '[FinVault] NSE equity-stockIndices API failed: ' . ( is_wp_error( $stocks_res ) ? $stocks_res->get_error_message() : wp_remote_retrieve_response_code( $stocks_res ) ) );
    }

    return $results;
}

/**
 * Gets NSE India session cookies by loading the NSE homepage first.
 * NSE requires a valid browser session cookie on all API calls.
 * Cookies are cached in WP Transient for 4 minutes.
 */
function finvault_get_nse_session_cookies() {
    $cached_cookies = get_transient( 'finvault_nse_cookies' );
    if ( false !== $cached_cookies && ! empty( $cached_cookies ) ) {
        return $cached_cookies;
    }

    $homepage_res = wp_remote_get( 'https://www.nseindia.com', array(
        'timeout' => 20,
        'headers' => array(
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection'      => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
        ),
        'redirection' => 5,
    ) );

    if ( is_wp_error( $homepage_res ) ) {
        error_log( '[FinVault] NSE homepage request failed: ' . $homepage_res->get_error_message() );
        return '';
    }

    // Extract all Set-Cookie headers and build a Cookie string
    $raw_cookies  = wp_remote_retrieve_header( $homepage_res, 'set-cookie' );
    $cookie_string = '';

    if ( is_array( $raw_cookies ) ) {
        foreach ( $raw_cookies as $cookie_line ) {
            // Take only the name=value part (before the first semicolon)
            $parts = explode( ';', $cookie_line );
            $name_value = trim( $parts[0] );
            if ( ! empty( $name_value ) ) {
                $cookie_string .= $name_value . '; ';
            }
        }
    } elseif ( ! empty( $raw_cookies ) ) {
        $parts = explode( ';', $raw_cookies );
        $cookie_string = trim( $parts[0] );
    }

    $cookie_string = rtrim( $cookie_string, '; ' );

    if ( ! empty( $cookie_string ) ) {
        // Cache for 4 minutes — NSE sessions expire after ~5 min of inactivity
        set_transient( 'finvault_nse_cookies', $cookie_string, 4 * MINUTE_IN_SECONDS );
    }

    return $cookie_string;
}

/**
 * Normalizes an NSE index object into our standard quote structure.
 */
function finvault_map_nse_index_quote( $idx, $symbol_key, $name ) {
    return array(
        'symbol'    => $symbol_key,
        'name'      => $name,
        'ltp'       => floatval( $idx['last'] ?? $idx['indexValue'] ?? 0 ),
        'change'    => floatval( $idx['variation'] ?? 0 ),
        'chgPct'    => floatval( $idx['percentChange'] ?? 0 ),
        'high'      => floatval( $idx['high'] ?? 0 ),
        'low'       => floatval( $idx['low'] ?? 0 ),
        'open'      => floatval( $idx['open'] ?? 0 ),
        'prevClose' => floatval( $idx['previousClose'] ?? 0 ),
        'h52w'      => floatval( $idx['yearHigh'] ?? 0 ),
        'l52w'      => floatval( $idx['yearLow'] ?? 0 ),
    );
}

// ============================================================
// SECTION 5: YAHOO FINANCE v8 API WITH CRUMB AUTHENTICATION
// ============================================================

/**
 * Fetches global market data from Yahoo Finance v8 quote endpoint.
 *
 * Yahoo Finance requires a 2-step auth handshake since 2023:
 *  Step 1: GET https://finance.yahoo.com/ → capture session cookies
 *  Step 2: GET https://query1.finance.yahoo.com/v1/test/getcrumb (with cookies) → get crumb string
 *  Step 3: GET https://query2.finance.yahoo.com/v8/finance/quote?symbols=...&crumb=CRUMB (with cookies)
 *
 * Crumb + cookies are cached for 50 minutes (Yahoo crumbs last ~1 hour).
 */
function finvault_fetch_yahoo_market_data( $symbols ) {
    $results = array();

    list( $crumb, $cookies ) = finvault_get_yahoo_crumb_and_cookies();

    if ( empty( $crumb ) || empty( $cookies ) ) {
        error_log( '[FinVault] Yahoo Finance crumb/cookie fetch failed. Skipping global data.' );
        return $results;
    }

    $symbols_string = implode( ',', array_map( 'rawurlencode', $symbols ) );
    $url = 'https://query2.finance.yahoo.com/v8/finance/quote'
         . '?symbols=' . $symbols_string
         . '&crumb=' . rawurlencode( $crumb )
         . '&fields=regularMarketPrice,regularMarketChange,regularMarketChangePercent,'
         . 'regularMarketDayHigh,regularMarketDayLow,regularMarketOpen,'
         . 'regularMarketPreviousClose,fiftyTwoWeekHigh,fiftyTwoWeekLow,shortName';

    $response = wp_remote_get( $url, array(
        'timeout' => 15,
        'headers' => array(
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept'          => 'application/json',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Referer'         => 'https://finance.yahoo.com/',
            'Cookie'          => $cookies,
        ),
    ) );

    if ( is_wp_error( $response ) ) {
        error_log( '[FinVault] Yahoo Finance v8 request failed: ' . $response->get_error_message() );
        return $results;
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    if ( $http_code === 401 ) {
        // Crumb expired — clear cached crumb and cookies so next request re-fetches
        error_log( '[FinVault] Yahoo Finance returned 401. Clearing crumb cache.' );
        delete_transient( 'finvault_yahoo_crumb' );
        return $results;
    }

    if ( $http_code !== 200 ) {
        error_log( "[FinVault] Yahoo Finance v8 returned HTTP {$http_code}." );
        return $results;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! isset( $body['quoteResponse']['result'] ) || empty( $body['quoteResponse']['result'] ) ) {
        error_log( '[FinVault] Yahoo Finance v8 returned empty result array.' );
        return $results;
    }

    foreach ( $body['quoteResponse']['result'] as $quote ) {
        $sym = $quote['symbol'];
        $results[ $sym ] = array(
            'symbol'    => $sym,
            'name'      => $quote['shortName'] ?? $sym,
            'ltp'       => floatval( $quote['regularMarketPrice'] ?? 0 ),
            'change'    => floatval( $quote['regularMarketChange'] ?? 0 ),
            'chgPct'    => floatval( $quote['regularMarketChangePercent'] ?? 0 ),
            'high'      => floatval( $quote['regularMarketDayHigh'] ?? 0 ),
            'low'       => floatval( $quote['regularMarketDayLow'] ?? 0 ),
            'open'      => floatval( $quote['regularMarketOpen'] ?? 0 ),
            'prevClose' => floatval( $quote['regularMarketPreviousClose'] ?? 0 ),
            'h52w'      => floatval( $quote['fiftyTwoWeekHigh'] ?? 0 ),
            'l52w'      => floatval( $quote['fiftyTwoWeekLow'] ?? 0 ),
        );
    }

    return $results;
}

/**
 * Performs the Yahoo Finance 2-step session + crumb handshake.
 * Returns array( $crumb_string, $cookie_string ).
 * Caches result in WP Transient for 50 minutes.
 */
function finvault_get_yahoo_crumb_and_cookies() {
    $cached = get_transient( 'finvault_yahoo_crumb' );
    if ( false !== $cached && isset( $cached['crumb'], $cached['cookies'] ) ) {
        return array( $cached['crumb'], $cached['cookies'] );
    }

    $browser_ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    // --- Step 1: Load Yahoo Finance homepage to get session cookies ---
    $homepage_res = wp_remote_get( 'https://finance.yahoo.com/', array(
        'timeout'     => 20,
        'redirection' => 5,
        'headers'     => array(
            'User-Agent'      => $browser_ua,
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
        ),
    ) );

    if ( is_wp_error( $homepage_res ) ) {
        error_log( '[FinVault] Yahoo homepage request failed: ' . $homepage_res->get_error_message() );
        return array( '', '' );
    }

    $raw_cookies   = wp_remote_retrieve_header( $homepage_res, 'set-cookie' );
    $cookie_string = '';

    if ( is_array( $raw_cookies ) ) {
        foreach ( $raw_cookies as $line ) {
            $parts = explode( ';', $line );
            $nv    = trim( $parts[0] );
            if ( ! empty( $nv ) ) {
                $cookie_string .= $nv . '; ';
            }
        }
    } elseif ( ! empty( $raw_cookies ) ) {
        $parts         = explode( ';', $raw_cookies );
        $cookie_string = trim( $parts[0] );
    }

    $cookie_string = rtrim( $cookie_string, '; ' );

    if ( empty( $cookie_string ) ) {
        error_log( '[FinVault] Yahoo homepage returned no cookies.' );
        return array( '', '' );
    }

    // Small delay to appear like a real browser navigation
    usleep( 500000 ); // 500ms

    // --- Step 2: Fetch the crumb token using the session cookies ---
    $crumb_res = wp_remote_get( 'https://query1.finance.yahoo.com/v1/test/getcrumb', array(
        'timeout' => 15,
        'headers' => array(
            'User-Agent'      => $browser_ua,
            'Accept'          => 'text/plain, */*',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Referer'         => 'https://finance.yahoo.com/',
            'Cookie'          => $cookie_string,
        ),
    ) );

    if ( is_wp_error( $crumb_res ) ) {
        error_log( '[FinVault] Yahoo crumb fetch failed: ' . $crumb_res->get_error_message() );
        return array( '', '' );
    }

    $crumb = trim( wp_remote_retrieve_body( $crumb_res ) );

    // The crumb endpoint returns a plain text string (not JSON).
    // A valid crumb looks like: "Avn5xqFQF4r" (alphanumeric, ~11 chars).
    if ( empty( $crumb ) || strlen( $crumb ) < 5 || substr( $crumb, 0, 1 ) === '<' ) {
        error_log( '[FinVault] Yahoo returned invalid crumb: ' . substr( $crumb, 0, 50 ) );
        return array( '', '' );
    }

    // Cache for 50 minutes (Yahoo crumbs valid for ~1 hour)
    set_transient( 'finvault_yahoo_crumb', array(
        'crumb'   => $crumb,
        'cookies' => $cookie_string,
    ), 50 * MINUTE_IN_SECONDS );

    error_log( '[FinVault] Yahoo crumb obtained and cached successfully.' );
    return array( $crumb, $cookie_string );
}
