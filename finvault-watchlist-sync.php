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
