<?php
/**
 * Plugin Name: WP Inquiries Webhook
 * Description: Custom Post Type, REST endpoint and webhook demo.
 * Version: 0.1.0
 * Author: Rachel Noigershel
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( file_exists( __DIR__ . '/config.php' ) ) {
    require_once __DIR__ . '/config.php';
}

// Register a Custom Post Type: "Inquiry"
add_action( 'init', function () {
    register_post_type( 'inquiry', [
        'labels' => [
            'name'          => 'Inquiries',
            'singular_name' => 'Inquiry',
        ],
        'public'       => true,
        'show_in_rest' => true,
        'supports'     => [ 'title', 'editor', 'custom-fields' ],
        'menu_icon'    => 'dashicons-email',
    ] );
} );

// Custom REST endpoint: GET /wp-json/inquiries/v1/list
add_action( 'rest_api_init', function () {
    register_rest_route( 'inquiries/v1', '/list', [
        'methods'             => 'GET',
        'callback'            => 'wpiw_get_inquiries',
        'permission_callback' => '__return_true',
        'args'                => [
            'per_page' => [
                'default'           => 10,
                'sanitize_callback' => 'absint',
            ],
        ],
    ] );
} );

function wpiw_get_inquiries( WP_REST_Request $request ) {
    $query = new WP_Query( [
        'post_type'      => 'inquiry',
        'post_status'    => 'publish',
        'posts_per_page' => min( $request->get_param( 'per_page' ), 50 ),
    ] );

    $items = array_map( function ( $post ) {
        return [
            'id'      => $post->ID,
            'title'   => get_the_title( $post ),
            'content' => wp_strip_all_tags( $post->post_content ),
            'date'    => get_the_date( 'c', $post ),
        ];
    }, $query->posts );

    return rest_ensure_response( [
        'total' => (int) $query->found_posts,
        'items' => $items,
    ] );
}

// Send a webhook when a new Inquiry is published
add_action( 'transition_post_status', 'wpiw_send_webhook', 10, 3 );

function wpiw_send_webhook( $new_status, $old_status, $post ) {
    if ( $post->post_type !== 'inquiry' ) {
        return;
    }
    // Only on first publish, not on every edit
    if ( $new_status !== 'publish' || $old_status === 'publish' ) {
        return;
    }

    $url = defined( 'WPIW_WEBHOOK_URL' ) ? WPIW_WEBHOOK_URL : '';
    if ( empty( $url ) ) {
        return;
    }

    $payload = [
        'event'   => 'inquiry.created',
        'id'      => $post->ID,
        'title'   => get_the_title( $post ),
        'content' => wp_strip_all_tags( $post->post_content ),
        'date'    => get_the_date( 'c', $post ),
    ];
    $body = wp_json_encode( $payload );

    // HMAC signature so the receiver can verify the sender
    $secret = defined( 'WPIW_WEBHOOK_SECRET' ) ? WPIW_WEBHOOK_SECRET : '';

    wp_remote_post( $url, [
        'timeout'  => 5,
        'blocking' => false,
        'headers'  => [
            'Content-Type'    => 'application/json',
            'X-WPIW-Signature' => hash_hmac( 'sha256', $body, $secret ),
        ],
        'body'     => $body,
        'sslverify' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
    ] );
}