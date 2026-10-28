<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

/** Delete a legacy uploaded service-account file only after validating its location and shape. */
function smarl_uninstall_legacy_credential($path): void
{
    if (!is_string($path) || $path === '' || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'json') {
        return;
    }
    $uploads = wp_upload_dir();
    if (empty($uploads['basedir'])) {
        return;
    }
    $file = realpath($path);
    if (!$file || !is_file($file) || filesize($file) > 1048576) {
        return;
    }
    $normalized = str_replace('\\', '/', $file);
    $inside = false;
    $roots = [$uploads['basedir'], rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'smartsync-credentials-' . substr(hash('sha256', ABSPATH), 0, 20)];
    foreach ($roots as $candidate) {
        $root = realpath($candidate);
        if (!$root || is_link($candidate)) {
            continue;
        }
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $inside = $inside || (DIRECTORY_SEPARATOR === '\\' ? strncasecmp($normalized, $root, strlen($root)) === 0 : strncmp($normalized, $root, strlen($root)) === 0);
    }
    if (!$inside) {
        return;
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (is_array($data) && ($data['type'] ?? '') === 'service_account' && !empty($data['private_key']) && !empty($data['client_email'])) {
        // A local unlink deliberately avoids plugin cloud-deletion filters.
        @unlink($file);
    }
}

function smarl_uninstall_site(): void
{
    global $wpdb;
    $settings = get_option('smarl_settings', []);
    smarl_uninstall_legacy_credential(is_array($settings) ? ($settings['googleServicePath'] ?? '') : '');
    smarl_uninstall_legacy_credential(get_option('service_json_path_temp', ''));

    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('process_s3_sync_batch');
    }
    // Action Scheduler may not be loaded while WordPress uninstalls this plugin.
    $actions = $wpdb->prefix . 'actionscheduler_actions';
    $logs = $wpdb->prefix . 'actionscheduler_logs';
    $actionsExist = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($actions))) === $actions;
    if ($actionsExist) {
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($logs))) === $logs) {
            $wpdb->query($wpdb->prepare("DELETE l FROM `{$logs}` l INNER JOIN `{$actions}` a ON a.action_id = l.action_id WHERE a.hook = %s", 'process_s3_sync_batch'));
        }
        $wpdb->delete($actions, ['hook' => 'process_s3_sync_batch'], ['%s']);
    }
    $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}smarl_file_sync_queue`");
    $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}smarl_upload_history`");

    foreach (['smarl_settings', 'smarl_sync_settings', 'custom_url_option', 'smarl_cloudfront_pending', 'smarl_aws_discovery_region', 'service_json_path_temp', 'smarl_db_version', 'smarl_sync_lock', 'smarl_queue_generation', 'aws_s3_cloudfront_settings', 'use_aws'] as $option) {
        delete_option($option);
    }
    foreach (['_smarl_offloaded_files', '_smarl_offload_error', '_smarl_offload_target'] as $metaKey) {
        delete_post_meta_by_key($metaKey);
    }
    $transientOptions = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM `{$wpdb->options}` WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like('_transient_smarl_') . '%', $wpdb->esc_like('_transient_timeout_smarl_') . '%'));
    foreach ($transientOptions as $option) {
        delete_option($option);
    }
    // External object caches hold transients outside the options table.
    $userIds = get_users(['fields' => 'ID']);
    $userIds[] = get_current_user_id();
    foreach (array_unique($userIds) as $userId) {
        delete_transient('smarl_google_credentials_' . (int) $userId);
        delete_transient('smarl_bunny_zones_' . (int) $userId);
    }
    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('smarl_file_sync_queue');
    }
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $smarlSiteId) {
        switch_to_blog((int) $smarlSiteId);
        try {
            smarl_uninstall_site();
        } finally {
            restore_current_blog();
        }
    }
} else {
    smarl_uninstall_site();
}

// Remove only the provider helper's isolated, plugin-owned credentials directory.
$smarlAutoload = __DIR__ . '/vendor/autoload.php';
if (is_readable($smarlAutoload)) {
    require_once $smarlAutoload;
    if (class_exists(\SmartSyncS3\Services\SMARLI_GoogleService::class)) {
        \SmartSyncS3\Services\SMARLI_GoogleService::cleanupCredentials();
    }
}
