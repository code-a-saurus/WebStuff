<?php
/**
 * Plugin Name: SCW Discourse REST Edge Cache
 * Description: Sets cache headers on WP-Discourse REST responses so Cloudflare can cache them (~60s at edge) while browsers revalidate.
 * Version: 1.0.3
 * Author: Lee Hutchinson + ChatGPT
 */

if (!defined('ABSPATH')) exit;

add_filter('rest_post_dispatch', function($result, $server, $request){
    if (!($request instanceof WP_REST_Request)) return $result;

    // Match the WP-Discourse namespace at the start of the route
    $route = $request->get_route(); // e.g., /wp-discourse/v1/...
    $attrs = method_exists($request, 'get_attributes') ? (array) $request->get_attributes() : [];
    $ns    = $attrs['namespace'] ?? '';

    $is_discourse = ($ns === 'wp-discourse') || (is_string($route) && preg_match('#^/wp-discourse(?:/|$)#', $route));
    if (!$is_discourse) return $result;

    // Safe methods only
    $method = strtoupper($request->get_method() ?: 'GET');
    if ($method !== 'GET' && $method !== 'HEAD') return $result;

    // Don’t cache personalized/authenticated requests
    if (is_user_logged_in()
        || $request->get_header('authorization')
        || $request->get_header('x-wp-nonce')
        || $request->get_header('cookie')) {
        return $result;
    }

    // Cache only successful responses
    // Yeah, yeah, checking http response codes with gt/lt is gross, but it works
    if (is_wp_error($result)) return $result;
    $status = ($result instanceof WP_HTTP_Response) ? (int) $result->get_status() : 200;
    if ($status < 200 || $status >= 400) return $result;

    // Edge TTL ~60s, browsers revalidate; SWR smooths refresh
    $server->send_header('Cache-Control', 'public, s-maxage=60, max-age=0, stale-while-revalidate=30');
    $server->send_header('Vary', 'Accept-Encoding');

    return $result;
}, 10, 3);
