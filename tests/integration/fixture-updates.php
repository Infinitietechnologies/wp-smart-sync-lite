<?php
/** Keep disposable compatibility installations independent of WordPress.org update checks. */
if (!defined('ABSPATH') || !str_contains(str_replace('\\', '/', ABSPATH), 'smartsync-wordpress-audit-fixture/wordpress/')) {
    return;
}

add_filter('pre_http_request', static function ($preempt, array $args, string $url) {
    if ($preempt !== false || wp_parse_url($url, PHP_URL_HOST) !== 'api.wordpress.org') return $preempt;
    $body = match (wp_parse_url($url, PHP_URL_PATH)) {
        '/core/version-check/1.7/' => ['offers' => [], 'translations' => []],
        '/plugins/update-check/1.1/' => ['plugins' => [], 'no_update' => [], 'translations' => []],
        '/themes/update-check/1.1/' => ['themes' => [], 'no_update' => [], 'translations' => []],
        default => null,
    };
    if ($body === null) return $preempt;
    return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
}, 1, 3);
