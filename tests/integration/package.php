<?php
/** Verify a release loaded from a separate extracted package in the provider audit fixture. */
if (!defined('WP_CLI') || !WP_CLI || !str_contains(str_replace('\\', '/', ABSPATH), 'smartsync-provider-workflows/fresh/smartsync-wordpress-audit-fixture/wordpress/')) {
    throw new RuntimeException('Package smoke requires the separate disposable provider workflow fixture.');
}
add_filter('pre_http_request', static fn() => new WP_Error('package_fixture_http_blocked', 'External HTTP is blocked in the package smoke test.'), PHP_INT_MAX);
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$slug = 'smartsync-packaged/aws-s3-smartsync-wp.php';
$root = realpath(WP_PLUGIN_DIR . '/smartsync-packaged');
if (!$root || !str_contains(str_replace('\\', '/', $root), '/smartsync-package-smoke-')) throw new RuntimeException('The packaged plugin must resolve to a separate temporary extraction.');
$root = rtrim(str_replace('\\', '/', $root), '/') . '/';
$metadata = get_plugin_data(WP_PLUGIN_DIR . '/' . $slug, false, false);
if (($metadata['Version'] ?? '') !== '1.8.0' || !defined('SMARL_VERSION') || SMARL_VERSION !== '1.8.0') throw new RuntimeException('The packaged metadata/runtime version must both be 1.8.0.');
if (get_option('active_plugins') !== [$slug]) throw new RuntimeException('Only the extracted package should be active during package smoke.');
if (!is_readable($root . 'vendor/autoload.php')) throw new RuntimeException('Runtime Composer autoload is absent from the package.');
foreach ([\SmartSyncS3\SMARL_SmartSync::class, \SmartSyncS3\Models\SMARL_CloudConfig::class, \Aws\S3\S3Client::class, \Google\Cloud\Storage\StorageClient::class, \GuzzleHttp\Client::class, \ActionScheduler::class] as $class) {
    if (!class_exists($class)) throw new RuntimeException('Required packaged class missing: ' . $class);
    $file = str_replace('\\', '/', (new ReflectionClass($class))->getFileName());
    if (!str_starts_with($file, $root)) throw new RuntimeException('A packaged runtime class loaded outside the extracted package: ' . $class);
}
$core = null;
foreach (($GLOBALS['wp_filter']['wp_get_attachment_url']->callbacks ?? []) as $callbacks) foreach ($callbacks as $callback) {
    if (is_array($callback['function']) && $callback['function'][0] instanceof \SmartSyncS3\SMARL_SmartSync) {
        $core = $callback['function'][0];
        if ($callback['accepted_args'] !== 2) throw new RuntimeException('Packaged attachment URL hook has the wrong argument contract.');
    }
}
if (!$core) throw new RuntimeException('Packaged attachment filters did not initialize.');
foreach (['shutdown' => 'flushAttachmentUploads', 'add_attachment' => 's3UploadNewMedia', 'delete_attachment' => 'deleteAttachmentObjects'] as $hook => $method) {
    if (has_action($hook, [$core, $method]) === false) throw new RuntimeException('Packaged core action missing: ' . $hook);
}
foreach (['list_s3_buckets', 'configure_aws', 'sync_local_to_aws', 'sync_aws_to_local', 'toggle_cdn'] as $action) {
    if (!has_action('wp_ajax_' . $action)) throw new RuntimeException('Packaged AJAX handler missing: ' . $action);
}
$worker = false;
foreach (($GLOBALS['wp_filter']['process_s3_sync_batch']->callbacks ?? []) as $callbacks) foreach ($callbacks as $callback) {
    if (is_array($callback['function']) && $callback['function'][0] instanceof \SmartSyncS3\Ajax\SMARL_AjaxHandler && $callback['accepted_args'] === 5) $worker = true;
}
if (!$worker || get_option('smarl_db_version') !== SMARL_DB_VERSION) throw new RuntimeException('Packaged scheduler or activation schema did not initialize.');
WP_CLI::success('Package 1.8.0 activation, Composer SDK origins, core hooks, AJAX, scheduler and schema verified from ' . $root);
