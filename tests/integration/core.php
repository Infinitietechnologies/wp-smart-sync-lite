<?php
/** Run with wp eval-file against the disposable SmartSync audit fixture only. */
if (!defined('WP_CLI') || !WP_CLI || strpos(str_replace('\\', '/', ABSPATH), 'smartsync-wordpress-audit-fixture/wordpress/') === false) {
    throw new RuntimeException('This destructive lifecycle test requires the disposable SmartSync WordPress audit fixture.');
}

use SmartSyncS3\Models\SMARL_CloudConfig;
use SmartSyncS3\Services\SMARL_LegacyCredentials;

add_filter('pre_http_request', static function () {
    return new WP_Error('smarl_fixture_http_blocked', 'External HTTP is blocked in this integration test.');
}, PHP_INT_MAX);

final class SmartSyncIntegrationStorage extends SMARL_CloudConfig
{
    public array $uploads = [];
    public array $deletes = [];
    public string $failName = '';
    public bool $failThumbnails = false;
    public function __construct()
    {
        $this->serviceProvider = 'aws';
        $this->url = 'https://cdn.example.test/bucket';
        $this->useCdnForMedia = $this->useCdnForAssets = $this->mediaAutoDelete = true;
    }
    public function isConfigured(): bool { return true; }
    public function uploadObject(string $key, string $path): void
    {
        if (($this->failName !== '' && basename($path) === $this->failName) || ($this->failThumbnails && preg_match('/-\d+x\d+\.[^.]+$/', $path))) throw new RuntimeException('Fixture upload failure');
        $this->uploads[$key] = hash_file('sha256', $path);
    }
    public function deleteObject(string $key): void { $this->deletes[] = $key; }
    public function getS3Client(string $region = ''): ?\Aws\S3\S3Client { throw new RuntimeException('Core must use the provider-neutral API.'); }
}

function smarl_integration_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function smarl_integration_image(string $suffix): array
{
    $uploads = wp_upload_dir();
    $directory = $uploads['basedir'] . '/2020/01';
    wp_mkdir_p($directory);
    $file = $directory . '/smarl-audit-' . $suffix . '-' . wp_generate_password(8, false) . '.jpg';
    $image = imagecreatetruecolor(800, 600);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 80, 130));
    imagejpeg($image, $file, 90);
    unset($image);
    $id = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'SmartSync audit fixture', 'post_status' => 'inherit'], $file, 0, true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    $metadata = wp_generate_attachment_metadata($id, $file);
    wp_update_attachment_metadata($id, $metadata);
    $files = [$file];
    foreach ($metadata['sizes'] ?? [] as $size) $files[] = dirname($file) . '/' . $size['file'];
    $GLOBALS['smarl_integration_created_files'] = array_merge($GLOBALS['smarl_integration_created_files'] ?? [], $files);
    return [$id, $metadata, $files];
}

global $wpdb;
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user(1);
add_image_size('smarl-audit-small', 64, 64, true);
$core = null;
foreach (($GLOBALS['wp_filter']['wp_delete_file']->callbacks ?? []) as $callbacks) {
    foreach ($callbacks as $callback) {
        $callable = $callback['function'] ?? null;
        if (is_array($callable) && ($callable[0] ?? null) instanceof \SmartSyncS3\SMARL_SmartSync) {
            $core = $callable[0];
            break 2;
        }
    }
}
smarl_integration_assert($core instanceof \SmartSyncS3\SMARL_SmartSync, 'The actual plugin bootstrap must create its core instance.');
$property = new ReflectionProperty($core, 'config');
$originalConfig = $property->getValue($core);
$storage = new SmartSyncIntegrationStorage();
$property->setValue($core, $storage);
$plugin = 'smartsync/aws-s3-smartsync-wp.php';
$table = $wpdb->prefix . 'smarl_file_sync_queue';
$attachments = [];
$passed = 0;

try {
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    $wpdb->query("CREATE TABLE `{$table}` (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, path varchar(255) NOT NULL, relative_path varchar(255) NOT NULL, status varchar(20) NOT NULL DEFAULT 'pending', created_at timestamp DEFAULT CURRENT_TIMESTAMP, updated_at timestamp DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))");
    delete_option('smarl_db_version');
    smarl_maybe_upgrade_schema();
    $columns = $wpdb->get_col("DESCRIBE `{$table}`", 0);
    foreach (['id', 'run_id', 'path', 'relative_path', 'size', 'status', 'created_at', 'updated_at'] as $column) {
        smarl_integration_assert(in_array($column, $columns, true), 'Schema upgrade omitted ' . $column);
    }
    smarl_integration_assert(get_option('smarl_db_version') === SMARL_DB_VERSION, 'Schema version was not persisted.');
    $historyColumns = $wpdb->get_col('DESCRIBE `' . $wpdb->prefix . 'smarl_upload_history`', 0);
    foreach (['target_hash', 'key_hash', 'relative_path', 'content_hash', 'size', 'updated_at'] as $column) {
        smarl_integration_assert(in_array($column, $historyColumns, true), 'Upload history schema upgrade omitted ' . $column);
    }
    smarl_maybe_upgrade_schema();
    smarl_integration_assert($wpdb->last_error === '', 'Repeated schema upgrade must be harmless.');
    WP_CLI::log('PASS actual WordPress dbDelta upgrades the legacy queue and is repeatable');
    $passed++;

    [$id, $metadata, $files] = smarl_integration_image('success');
    $attachments[] = $id;
    smarl_integration_assert(count($files) > 1, 'Real WordPress image generation must produce thumbnail metadata.');
    foreach ($files as $file) smarl_integration_assert(is_file($file), 'Local files disappeared before metadata generation finished.');
    smarl_integration_assert(count($storage->uploads) === count($files), 'Final metadata must upload every object before response URLs are generated.');
    smarl_integration_assert(strpos(wp_get_attachment_url($id), 'https://cdn.example.test/bucket/') === 0, 'REST/editor response URLs must point to already uploaded cloud objects before deferred cleanup.');
    $core->flushAttachmentUploads();
    $keys = get_post_meta($id, '_smarl_offloaded_files', true);
    smarl_integration_assert(count($keys) === count($files), 'Original and every generated size must be tracked.');
    foreach ($files as $file) smarl_integration_assert(!is_file($file), 'Local cleanup did not remove ' . basename($file));
    foreach ($keys as $key) smarl_integration_assert(isset($storage->uploads[$key]) && strpos($key, 'wp-content/uploads/2020/01/') === 0, 'Old-month object key did not preserve the original directory.');
    smarl_integration_assert($storage->deletes === [], 'Automatic local cleanup deleted remote objects.');
    $imageHtml = wp_get_attachment_image($id, 'medium');
    $srcset = wp_get_attachment_image_srcset($id, 'medium');
    smarl_integration_assert(strpos(wp_get_attachment_url($id), 'https://cdn.example.test/bucket/wp-content/uploads/2020/01/') === 0, 'Offloaded attachment src must use the CDN.');
    smarl_integration_assert(is_string($srcset) && substr_count($srcset, 'https://cdn.example.test/bucket/wp-content/uploads/2020/01/') >= 2, 'Every real responsive image candidate must use its CDN object key.');
    smarl_integration_assert(strpos($srcset, wp_upload_dir()['baseurl']) === false && strpos($imageHtml, wp_upload_dir()['baseurl']) === false, 'Responsive output retained local URLs after local media was removed.');
    WP_CLI::log('PASS real responsive image src and srcset switch from local delivery to CDN after successful cleanup');
    $passed++;
    $localUrl = wp_upload_dir()['baseurl'] . '/' . $metadata['file'];
    $localThumbnail = wp_upload_dir()['baseurl'] . '/2020/01/' . $metadata['sizes']['medium']['file'];
    $content = '<p>Literal ' . esc_html($localUrl) . '</p><img class="wp-image-' . $id . '" src="' . esc_url($localUrl) . '" srcset="' . esc_attr($localThumbnail . ' 300w, ' . $localUrl . ' 800w') . '"><a href="' . esc_url($localUrl . '?download=1') . '">Download</a><video src="' . esc_url($localUrl) . '" poster="' . esc_url($localThumbnail) . '"></video><audio src="' . esc_url($localUrl) . '"></audio><picture><source srcset="' . esc_attr($localThumbnail . ' 300w') . '"></picture><img src="https://other.example.test/image.jpg">';
    $relativeUrl = wp_parse_url($localUrl, PHP_URL_PATH);
    $relativeThumbnail = wp_parse_url($localThumbnail, PHP_URL_PATH);
    $content .= '<img class="fixture-root-relative wp-image-' . $id . '" src="' . esc_attr($relativeUrl . '?v=1#ready') . '" srcset="' . esc_attr($relativeThumbnail . ' 300w, ' . $relativeUrl . ' 800w') . '"><a href="' . esc_attr($relativeUrl . '?download=1#file') . '">Relative download</a>';
    $rendered = apply_filters('the_content', $content);
    smarl_integration_assert(strpos($rendered, '<p>Literal ' . esc_html($localUrl)) !== false, 'Content rewriting altered plain text.');
    smarl_integration_assert(strpos($rendered, 'https://other.example.test/image.jpg') !== false, 'Content rewriting altered an external image.');
    $tags = new WP_HTML_Tag_Processor($rendered);
    while ($tags->next_tag()) {
        foreach (['src', 'srcset', 'href', 'poster'] as $attribute) {
            $value = $tags->get_attribute($attribute);
            if (is_string($value)) smarl_integration_assert(strpos($value, wp_upload_dir()['baseurl']) === false, 'Rendered media retained local ' . $attribute . ' after cleanup.');
        }
    }
    $relativeTag = new WP_HTML_Tag_Processor($rendered);
    smarl_integration_assert($relativeTag->next_tag(['class_name' => 'fixture-root-relative']) && str_starts_with($relativeTag->get_attribute('src'), 'https://cdn.example.test/bucket/') && str_ends_with($relativeTag->get_attribute('src'), '?v=1#ready'), 'Stored root-relative media src did not use its cloud key and retain query/fragment.');
    smarl_integration_assert(substr_count($relativeTag->get_attribute('srcset'), 'https://cdn.example.test/bucket/') === 2, 'Stored root-relative srcset candidates did not use the CDN.');
    smarl_integration_assert($relativeTag->next_tag('A') && str_ends_with($relativeTag->get_attribute('href'), '?download=1#file') && str_starts_with($relativeTag->get_attribute('href'), 'https://cdn.example.test/bucket/'), 'Stored root-relative attachment link did not use the CDN.');
    WP_CLI::log('PASS actual stored content rewrites media attributes and file links without changing text or external URLs');
    $passed++;
    wp_delete_attachment($id, true);
    $deleted = $storage->deletes;
    sort($deleted);
    sort($keys);
    smarl_integration_assert($deleted === $keys, 'Actual attachment deletion must remove every tracked remote-only object exactly once.');
    WP_CLI::log('PASS real generated image metadata retains cloud copies during cleanup and deletes them on attachment deletion');
    $passed++;

    $storage->failThumbnails = true;
    [$id, $metadata, $files] = smarl_integration_image('failure');
    $attachments[] = $id;
    $storage->failName = basename($files[1]);
    $core->flushAttachmentUploads();
    foreach ($files as $file) smarl_integration_assert(is_file($file), 'A partial upload failure removed a local file.');
    smarl_integration_assert(get_post_meta($id, '_smarl_offload_error', true) !== '', 'A failed attachment needs a recoverable error marker.');
    $failedSrcset = wp_get_attachment_image_srcset($id, 'medium');
    smarl_integration_assert(strpos(wp_get_attachment_url($id), wp_upload_dir()['baseurl'] . '/') === 0, 'Failed attachment src must use the retained local original.');
    smarl_integration_assert(is_string($failedSrcset) && strpos($failedSrcset, 'cdn.example.test') === false && strpos($failedSrcset, wp_upload_dir()['baseurl']) !== false, 'Failed responsive image candidates must use retained local sizes.');
    $failedContent = '<img class="wp-image-' . $id . '" src="' . esc_url(wp_upload_dir()['baseurl'] . '/' . $metadata['file']) . '">';
    smarl_integration_assert(strpos(apply_filters('the_content', $failedContent), 'cdn.example.test') === false, 'Stored failed attachment markup must keep local delivery.');
    $failedRelative = wp_parse_url(wp_upload_dir()['baseurl'] . '/' . $metadata['file'], PHP_URL_PATH);
    $failedRelativeHtml = $core->rewriteContentImageTag('<img src="' . esc_attr($failedRelative . '?v=2#local') . '">', '', $id);
    $failedRelativeTag = new WP_HTML_Tag_Processor($failedRelativeHtml);
    smarl_integration_assert($failedRelativeTag->next_tag('IMG') && $failedRelativeTag->get_attribute('src') === $failedRelative . '?v=2#local', 'A failed root-relative attachment must retain the original local URL.');
    $storage->failName = '';
    $storage->failThumbnails = false;
    wp_delete_attachment($id, true);
    WP_CLI::log('PASS a real attachment with a mocked thumbnail upload failure retains every local file');
    $passed++;

    $source = site_url('/wp-includes/js/fixture.js?v=1#ready');
    smarl_integration_assert(apply_filters('script_loader_src', $source, 'fixture') === $source, 'Lite rewrote a script URL.');
    smarl_integration_assert(!method_exists($core, 'replaceBaseUrl'), 'The Pro asset rewriter remains in Lite.');
    WP_CLI::log('PASS actual WordPress site URLs preserve object paths and external asset origins');
    $passed++;

    update_option('smarl_settings', ['bucket' => 'fixture-kept']);
    as_schedule_single_action(time() + 3600, 'process_s3_sync_batch', [1, [], [], 'upload', 'fixture-run'], 'smartsync');
    deactivate_plugins($plugin, false);
    smarl_integration_assert(!is_plugin_active($plugin), 'Actual WordPress deactivation did not run.');
    smarl_integration_assert(get_option('smarl_settings')['bucket'] === 'fixture-kept', 'Deactivation erased configuration.');
    smarl_integration_assert(!as_has_scheduled_action('process_s3_sync_batch'), 'Deactivation left sync jobs queued.');
    $activation = activate_plugin($plugin);
    smarl_integration_assert(!is_wp_error($activation), 'Reactivation failed.');
    WP_CLI::log('PASS actual activation/deactivation hooks retain settings and cancel sync jobs');
    $passed++;

    $uploads = wp_upload_dir();
    $legacyCredential = $uploads['basedir'] . '/smarl-audit-service-account.json';
    $unrelatedFile = $uploads['basedir'] . '/smarl-audit-unrelated.json';
    file_put_contents($legacyCredential, wp_json_encode(['type' => 'service_account', 'client_email' => 'fixture@example.test', 'private_key' => 'fixture-only-no-real-key']));
    file_put_contents($unrelatedFile, wp_json_encode(['keep' => true]));
    $credentialDirectory = SMARL_LegacyCredentials::getCredentialsDirectory();
    wp_mkdir_p($credentialDirectory);
    $privateCredential = $credentialDirectory . '/smarl-1-' . bin2hex(random_bytes(16)) . '.json';
    file_put_contents($privateCredential, '{}');
    update_option('smarl_settings', ['googleServicePath' => $legacyCredential]);
    foreach (['custom_url_option', 'service_json_path_temp', 'smarl_sync_lock', 'smarl_queue_generation', 'aws_s3_cloudfront_settings', 'use_aws'] as $option) update_option($option, $option === 'service_json_path_temp' ? $legacyCredential : 'fixture');
    set_transient('smarl_google_credentials_1', $privateCredential, 3600);
    set_transient('smarl_bunny_zones_1', ['fixture' => true], 900);
    $post = wp_insert_attachment(['post_title' => 'SmartSync uninstall metadata fixture', 'post_status' => 'inherit']);
    $attachments[] = $post;
    update_post_meta($post, '_smarl_offloaded_files', ['fixture/key.jpg']);
    update_post_meta($post, '_smarl_offload_error', 'fixture');
    update_post_meta($post, '_smarl_offload_target', 'fixture-owned-target');
    update_option('smarl_cloudfront_pending', ['fixture-target' => ['distribution_id' => 'fixture-no-cloud']], false);
    as_schedule_single_action(time() + 3600, 'process_s3_sync_batch', [1, [], [], 'upload', 'fixture-run'], 'smartsync');
    as_schedule_single_action(time() + 3600, 'smarl_fixture_unrelated_job', [], 'fixture');
    deactivate_plugins($plugin, false);
    uninstall_plugin($plugin);
    foreach (['smarl_settings', 'smarl_sync_settings', 'custom_url_option', 'smarl_cloudfront_pending', 'service_json_path_temp', 'smarl_db_version', 'smarl_sync_lock', 'smarl_queue_generation', 'aws_s3_cloudfront_settings', 'use_aws'] as $option) smarl_integration_assert(get_option($option, null) === null, 'Uninstall left option ' . $option);
    smarl_integration_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === null, 'Uninstall left the queue table.');
    smarl_integration_assert(!is_file($legacyCredential) && !is_file($privateCredential), 'Uninstall left owned credential files.');
    smarl_integration_assert(is_file($unrelatedFile), 'Uninstall removed an unrelated JSON file.');
    smarl_integration_assert(get_transient('smarl_google_credentials_1') === false && get_transient('smarl_bunny_zones_1') === false, 'Uninstall left cached provider credentials.');
    smarl_integration_assert(get_post_meta($post, '_smarl_offloaded_files', true) === '' && get_post_meta($post, '_smarl_offload_error', true) === '' && get_post_meta($post, '_smarl_offload_target', true) === '', 'Uninstall left attachment offload metadata.');
    smarl_integration_assert(!as_has_scheduled_action('process_s3_sync_batch') && as_has_scheduled_action('smarl_fixture_unrelated_job'), 'Uninstall affected the wrong scheduler jobs.');
    @unlink($unrelatedFile);
    as_unschedule_all_actions('smarl_fixture_unrelated_job');
    $activation = activate_plugin($plugin);
    smarl_integration_assert(!is_wp_error($activation) && is_plugin_active($plugin), 'Plugin was not reactivated after the destructive fixture test.');
    smarl_integration_assert(get_option('smarl_db_version') === SMARL_DB_VERSION, 'Reactivation did not recreate the schema.');
    WP_CLI::log('PASS actual uninstall removes only plugin data/credentials/jobs and reactivation recreates the schema');
    $passed++;
} finally {
    $storage->failName = '';
    $storage->failThumbnails = false;
    foreach ($attachments as $attachment) {
        if (get_post($attachment)) wp_delete_attachment($attachment, true);
    }
    $core->flushAttachmentUploads();
    $property->setValue($core, $originalConfig);
    $fixtureUploads = rtrim(str_replace('\\', '/', wp_upload_dir()['basedir']), '/') . '/';
    foreach ($GLOBALS['smarl_integration_created_files'] ?? [] as $file) if (str_starts_with(str_replace('\\', '/', $file), $fixtureUploads) && is_file($file)) unlink($file);
    if (!is_plugin_active($plugin)) activate_plugin($plugin);
    remove_image_size('smarl-audit-small');
}
WP_CLI::success($passed . ' core WordPress integration cases passed; cloud calls were mocked.');
