<?php
/**
 * Plugin Name: SCW Discourse → Cloudflare Safe Cache for Comments
 * Description: Prevents caching of single-post HTML until Discourse linkage exists; also purges Cloudflare when linkage lands.
 * Author: Lee Hutchinson + ChatGPT
 * Version: 1.6.2
 *
 * Changelog:
 * 1.6.2 - Add delayed re-purge for home/RSS to avoid racing FastCGI purges.
 * 1.6.1 - Added timeout failsafe so that we're not emitting no-cache headers forever
 * 1.6.0 — ADD pre-linkage no-cache gate (template_redirect) so edge/origin never cache the WP-native-comments HTML.
 * 1.4.0 — Purge on added/updated postmeta + status transition; purge slash/no-slash variants.
 * 1.3.0 — Credentials via wp-config.php constants only.
 */

if (!defined('ABSPATH')) exit;

/** Resolve Cloudflare credentials from wp-config.php */
function scw_dcfp_get_creds(): array {
    $zone  = defined('SCW_CF_ZONE_ID')   ? trim((string) SCW_CF_ZONE_ID)   : '';
    $token = defined('SCW_CF_API_TOKEN') ? trim((string) SCW_CF_API_TOKEN) : '';
    return ['zone_id' => $zone, 'api_token' => $token];
}

/** Discourse linkage meta keys (filterable) */
function scw_dcfp_meta_keys(): array {
    $keys = ['discourse_topic_id', 'discourse_post_id', 'discourse_permalink'];
    return apply_filters('scw_dcfp_meta_keys', $keys);
}

/** Build URLs to purge for a post (slash + no-slash, plus home + RSS) */
function scw_dcfp_build_urls(int $post_id): array {
    $urls = [];
    $permalink = get_permalink($post_id);
    if ($permalink) {
        $urls[] = $permalink;
        $urls[] = (substr($permalink, -1) === '/') ? rtrim($permalink, '/') : trailingslashit($permalink);
    }
    $urls[] = home_url('/');
    $urls[] = get_bloginfo('rss2_url');
    $urls = array_unique(array_filter($urls));
    return apply_filters('scw_dcfp_urls', $urls, $post_id);
}

/** Minimum seconds before a follow-up edge purge (0 disables; runs on next WP-Cron tick) */
function scw_dcfp_delayed_purge_delay(): int {
    $delay = defined('SCW_CF_DELAYED_PURGE_SEC') ? (int) SCW_CF_DELAYED_PURGE_SEC : 30;
    return max(0, $delay);
}

/** URLs for delayed purge (home + RSS by default, filterable) */
function scw_dcfp_delayed_purge_urls(int $post_id): array {
    $urls = [home_url('/'), get_bloginfo('rss2_url')];
    $urls = array_unique(array_filter($urls));
    return apply_filters('scw_dcfp_delayed_urls', $urls, $post_id);
}

/** Schedule a delayed purge to let origin cache clear first (executes on next WP-Cron tick) */
function scw_dcfp_schedule_delayed_purge(int $post_id): void {
    $delay = scw_dcfp_delayed_purge_delay();
    if ($delay <= 0) return;
    if (wp_next_scheduled('scw_dcfp_delayed_purge', [$post_id])) return;
    wp_schedule_single_event(time() + $delay, 'scw_dcfp_delayed_purge', [$post_id]);
}

/** Run delayed purge event */
function scw_dcfp_run_delayed_purge($post_id): void {
    $post_id = (int) $post_id;
    if ($post_id <= 0) return;
    if (get_post_status($post_id) !== 'publish') return;
    $urls = scw_dcfp_delayed_purge_urls($post_id);
    scw_dcfp_purge_urls($urls);
}
add_action('scw_dcfp_delayed_purge', 'scw_dcfp_run_delayed_purge', 10, 1);

/** Minutes since publish (UTC) or null if unknown */
function scw_dcfp_minutes_since_publish(int $post_id): ?int {
    $post_time = get_post_time('U', true, $post_id);
    if (!$post_time) return null;
    return (int) floor((time() - (int)$post_time) / 60);
}

/** Low-level Cloudflare purge-by-URL */
function scw_dcfp_purge_urls(array $urls): void {
    $creds = scw_dcfp_get_creds();
    if ($creds['zone_id'] === '' || $creds['api_token'] === '' || empty($urls)) return;

    $endpoint = sprintf('https://api.cloudflare.com/client/v4/zones/%s/purge_cache', $creds['zone_id']);
    $response = wp_remote_post($endpoint, [
        'headers' => [
            'Authorization' => 'Bearer ' . $creds['api_token'],
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode(['files' => array_values($urls)]),
        'timeout' => 10,
    ]);

    if (is_wp_error($response)) {
        error_log('[SCW CF Purge] WP_Error: ' . $response->get_error_message());
        return;
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        $body = wp_remote_retrieve_body($response);
        error_log(sprintf('[SCW CF Purge] HTTP %d. Response: %s', $code, $body));
    }
}

/** Common gate: only act when linkage meta key written & post is public */
function scw_dcfp_should_trigger($post_id, $meta_key, $meta_value): bool {
    $creds = scw_dcfp_get_creds();
    if ($creds['zone_id'] === '' || $creds['api_token'] === '') return false;
    if (empty($meta_value)) return false;
    if (!in_array($meta_key, scw_dcfp_meta_keys(), true)) return false;
    return (get_post_status($post_id) === 'publish');
}

/** Purge on added/updated Discourse linkage meta */
function scw_dcfp_on_post_meta_change($meta_id, $post_id, $meta_key, $meta_value): void {
    if (!scw_dcfp_should_trigger($post_id, $meta_key, $meta_value)) return;
    $urls = scw_dcfp_build_urls((int) $post_id);
    scw_dcfp_purge_urls($urls);
    scw_dcfp_schedule_delayed_purge((int) $post_id);
}
add_action('added_post_meta',   'scw_dcfp_on_post_meta_change', 10, 4);
add_action('updated_post_meta', 'scw_dcfp_on_post_meta_change', 10, 4);

/** Purge on publish transition, if linkage meta already present */
function scw_dcfp_on_transition_post_status($new_status, $old_status, $post): void {
    if ($new_status !== 'publish' || !($post instanceof WP_Post)) return;
    foreach (scw_dcfp_meta_keys() as $k) {
        if (get_post_meta($post->ID, $k, true)) {
            $urls = scw_dcfp_build_urls((int) $post->ID);
            scw_dcfp_purge_urls($urls);
            break;
        }
    }
    scw_dcfp_schedule_delayed_purge((int) $post->ID);
}
add_action('transition_post_status', 'scw_dcfp_on_transition_post_status', 10, 3);

/**
 * NEW: Pre-linkage no-cache gate.
 * Until any Discourse linkage meta exists on a published single post,
 * emit strong no-cache headers so neither origin (FastCGI) nor Cloudflare
 * can store the pre-linkage HTML that shows WP's native comment form.
 */
function scw_dcfp_prelinkage_nocache(): void {
    if (!is_singular('post')) return;

    $post = get_queried_object();
    if (!($post instanceof WP_Post) || get_post_status($post) !== 'publish') return;

    foreach (scw_dcfp_meta_keys() as $k) {
        if (get_post_meta($post->ID, $k, true)) return; // linkage exists; cache normally
    }

    // In wp-config.php you can set: define('SCW_CF_LINKAGE_GRACE_MIN', 10);
    $grace = defined('SCW_CF_LINKAGE_GRACE_MIN') ? (int) SCW_CF_LINKAGE_GRACE_MIN : 0;
    if ($grace > 0) {
        $mins = scw_dcfp_minutes_since_publish($post->ID);
        if ($mins !== null && $mins >= $grace) {
            // We've waited long enough; stop gating and let caching resume.
            // (Optionally, trigger one purge to refresh edge with whatever is current.)
            // scw_dcfp_purge_urls(scw_dcfp_build_urls((int)$post->ID));
            return;
        }
    }

    // Block caching for this response (origin + edge + browsers)
    if (!headers_sent()) {
        nocache_headers();                    // Cache-Control: no-store, no-cache, must-revalidate, etc.
        header('cf-edge-cache: no-cache');    // Extra hint for Cloudflare/APO
    }
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);

    // Optional debug: uncomment for a few publishes
    // error_log('[SCW CF Gate] Pre-linkage no-cache for post ' . $post->ID);
}
add_action('template_redirect', 'scw_dcfp_prelinkage_nocache', 0);

/** Optional: admin notice if not configured */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $creds = scw_dcfp_get_creds();
    if ($creds['zone_id'] === '' || $creds['api_token'] === '') {
        echo '<div class="notice notice-error"><p><strong>SCW Discourse → Cloudflare:</strong> Missing credentials. Define <code>SCW_CF_ZONE_ID</code> and <code>SCW_CF_API_TOKEN</code> in <code>wp-config.php</code>.</p></div>';
    }
});
