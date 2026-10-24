<?php
declare(strict_types=1);

use SmartSyncS3\Models\SMARL_CloudConfig;
use SmartSyncS3\SMARL_SmartSync;

final class SmartSyncCoreStorage extends SMARL_CloudConfig
{
    public array $uploads = [];
    public array $uploadCalls = [];
    public array $deletes = [];
    public string $failName = '';
    public function __construct()
    {
        $this->url = 'https://cdn.example.test/bucket';
        $this->useCdnForMedia = $this->useCdnForAssets = $this->mediaAutoDelete = true;
    }
    public function isConfigured(): bool { return true; }
    public function uploadObject(string $key, string $path): void
    {
        $this->uploadCalls[] = $key;
        if ($this->failName !== '' && basename($path) === $this->failName) throw new RuntimeException('Mock upload failed');
        $this->uploads[$key] = file_get_contents($path);
    }
    public function deleteObject(string $key): void { $this->deletes[] = $key; }
}

function smartsync_core_instance(SmartSyncCoreStorage $config): SMARL_SmartSync
{
    $core = new SMARL_SmartSync(dirname(__DIR__, 2) . '/aws-s3-smartsync-wp.php');
    (new ReflectionProperty($core, 'config'))->setValue($core, $config);
    $core->initializeFilters();
    return $core;
}

function smartsync_core_attachment(int $id, array $extra = []): array
{
    $relative = '2020/01/core-' . $id . '.jpg';
    $path = wp_upload_dir()['basedir'] . '/' . $relative;
    wp_mkdir_p(dirname($path));
    $metadata = ['file' => $relative, 'sizes' => ['thumbnail' => ['file' => 'core-' . $id . '-150x150.jpg']]] + $extra;
    $paths = [$path, dirname($path) . '/core-' . $id . '-150x150.jpg'];
    foreach ($paths as $file) file_put_contents($file, basename($file));
    update_post_meta($id, '_wp_attached_file', $relative);
    update_post_meta($id, '_wp_attachment_metadata', $metadata);
    return [$metadata, $paths];
}

smartsync_test('media cleanup retains remote original and old-month thumbnail', function (): void {
    $storage = new SmartSyncCoreStorage();
    $core = smartsync_core_instance($storage);
    [$metadata, $paths] = smartsync_core_attachment(101);
    smartsync_assert_same($metadata, apply_filters('wp_generate_attachment_metadata', $metadata, 101));
    smartsync_assert_true(is_file($paths[0]), 'Cleanup must wait for generation to finish');
    $core->flushAttachmentUploads();
    smartsync_assert_same(2, count($storage->uploads));
    smartsync_assert_same([], $storage->deletes);
    foreach ($paths as $path) smartsync_assert_true(!is_file($path));
    smartsync_assert_true(isset($storage->uploads['wp-content/uploads/2020/01/core-101-150x150.jpg']));
    $core->deleteAttachmentObjects(101);
    smartsync_assert_same(array_keys($storage->uploads), $storage->deletes);
    $core->deleteFromS3($paths[0]);
    smartsync_assert_same(2, count($storage->deletes), 'Normal file deletion must not repeat remote manifest deletions');
});

smartsync_test('partial attachment upload retains every local file', function (): void {
    $storage = new SmartSyncCoreStorage();
    $storage->failName = 'core-102-150x150.jpg';
    $core = smartsync_core_instance($storage);
    [$metadata, $paths] = smartsync_core_attachment(102);
    $core->queueAttachmentUpload($metadata, 102);
    $core->flushAttachmentUploads();
    foreach ($paths as $path) smartsync_assert_true(is_file($path));
    smartsync_assert_same([], $storage->deletes);
    smartsync_assert_true(get_post_meta(102, '_smarl_offload_error', true) !== '');
});

smartsync_test('metadata regeneration coalesces intermediate updates and includes backup originals', function (): void {
    $storage = new SmartSyncCoreStorage();
    $storage->mediaAutoDelete = false;
    $core = smartsync_core_instance($storage);
    [$metadata, $paths] = smartsync_core_attachment(103, ['original_image' => 'core-103-original.jpg']);
    file_put_contents(dirname($paths[0]) . '/core-103-original.jpg', 'unscaled');
    file_put_contents(dirname($paths[0]) . '/core-103-backup.jpg', 'backup');
    update_post_meta(103, '_wp_attachment_backup_sizes', ['full-orig' => ['file' => 'core-103-backup.jpg']]);
    $core->queueAttachmentUpload([], 103);
    $core->queueAttachmentUpload($metadata, 103);
    $core->flushAttachmentUploads();
    smartsync_assert_same(4, count($storage->uploads));
    smartsync_assert_true(is_file($paths[0]));
    $core->flushAttachmentUploads();
    smartsync_assert_same(4, count($storage->uploads));
});

smartsync_test('disabled or invalid media CDN prevents destructive local cleanup', function (): void {
    $storage = new SmartSyncCoreStorage();
    $storage->useCdnForMedia = false;
    $core = smartsync_core_instance($storage);
    [$metadata, $paths] = smartsync_core_attachment(104);
    $core->queueAttachmentUpload($metadata, 104);
    $core->flushAttachmentUploads();
    smartsync_assert_true(is_file($paths[0]));
    $storage->useCdnForMedia = true;
    $storage->url = '';
    $core->queueAttachmentUpload($metadata, 104);
    $core->flushAttachmentUploads();
    smartsync_assert_true(is_file($paths[0]));
});

smartsync_test('malicious attachment size metadata cannot upload or delete outside its directory', function (): void {
    $storage = new SmartSyncCoreStorage();
    $core = smartsync_core_instance($storage);
    [$metadata, $paths] = smartsync_core_attachment(105);
    $metadata['sizes']['thumbnail']['file'] = '../outside.jpg';
    update_post_meta(105, '_wp_attachment_metadata', $metadata);
    $core->queueAttachmentUpload($metadata, 105);
    $core->flushAttachmentUploads();
    smartsync_assert_same([], $storage->uploads);
    smartsync_assert_same([], $storage->deletes);
    smartsync_assert_true(is_file($paths[0]));
});

smartsync_test('CDN rewriting preserves first-party root-relative keys and external URLs', function (): void {
    $storage = new SmartSyncCoreStorage();
    $core = smartsync_core_instance($storage);
    smartsync_assert_same('https://cdn.example.test/bucket/wp-includes/js/core.js?v=1#ready', $core->replaceBaseUrl('https://example.test/blog/wp-includes/js/core.js?v=1#ready'));
    smartsync_assert_same('https://cdn.example.test/bucket/wp-content/uploads/photo.JPG', $core->getAttachmentUrl('https://example.test/blog/wp-content/uploads/photo.JPG'));
    foreach (['https://other.test/blog/script.js', 'https://example.test.evil.test/blog/script.js', 'https://example.test/other/script.js', 'https://example.test:8443/blog/script.js', 'data:text/javascript,alert(1)', '/blog/wp-content/theme.js'] as $url) {
        smartsync_assert_same($url, $core->replaceBaseUrl($url));
    }
    smartsync_assert_same('https://cdn.example.test/bucket', $core->generateUrlFromBase('https://cdn.example.test/bucket'));
    smartsync_assert_same('https://cdn.example.test/bucket', $core->generateUrlFromBase('cdn.example.test/bucket'));
});

smartsync_test('admin assets and request objects are scoped and localized coherently', function (): void {
    $core = smartsync_core_instance(new SmartSyncCoreStorage());
    $core->enqueueScripts();
    smartsync_assert_same([], $GLOBALS['smartsync_test_assets']);
    $_GET['page'] = 'smarl-smart-sync';
    $GLOBALS['smartsync_test_can_manage'] = false;
    $core->enqueueScripts();
    smartsync_assert_same([], $GLOBALS['smartsync_test_assets']);
    $GLOBALS['smartsync_test_can_manage'] = true;
    $core->enqueueScripts();
    $localized = $GLOBALS['smartsync_test_assets']['localized'];
    smartsync_assert_same(admin_url('admin.php?page=smarl-configuration-wizard'), $localized['ajaxObject']['wizard_url']);
    smartsync_assert_same(admin_url('admin.php?page=smarl-smart-sync'), $localized['ajax_object']['settings_url']);
    smartsync_assert_true(isset($localized['ajaxObject']['settings_strings']['cost_unavailable']));
    smartsync_assert_true(isset($localized['ajax_object']['strings']['select_bucket']));
});

smartsync_test('deactivation cancels sync jobs and retains cloud configuration', function (): void {
    $config = ['bucket' => 'kept-bucket', 'iam_secret' => 'fixture-secret'];
    update_option('smarl_settings', $config);
    as_schedule_single_action(time(), 'process_s3_sync_batch', [], 'smartsync');
    as_schedule_single_action(time(), 'unrelated_plugin_job');
    $lock = \SmartSyncS3\Helpers\SMARL_Helper::acquireSyncLock();
    SMARL_SmartSync::deActivateHook();
    smartsync_assert_same($config, get_option('smarl_settings'));
    smartsync_assert_same('unrelated_plugin_job', $GLOBALS['smartsync_test_jobs'][0]['hook']);
    smartsync_assert_same('idle', get_option('smarl_sync_settings')['s3SyncStatus']);
    smartsync_assert_same($lock, get_option('smarl_sync_lock')['token'], 'Deactivation must not release a running worker\'s lease');
});

smartsync_test('non-image media with empty metadata still offloads its original', function (): void {
    $storage = new SmartSyncCoreStorage();
    $core = smartsync_core_instance($storage);
    $relative = '2020/01/core-audio.mp3';
    $file = wp_upload_dir()['basedir'] . '/' . $relative;
    wp_mkdir_p(dirname($file));
    file_put_contents($file, 'audio fixture');
    update_post_meta(106, '_wp_attached_file', $relative);
    $core->queueAttachmentUpload([], 106);
    $core->flushAttachmentUploads();
    smartsync_assert_same(['wp-content/uploads/' . $relative => 'audio fixture'], $storage->uploads);
    smartsync_assert_true(!is_file($file));
    smartsync_assert_same([], $storage->deletes);
});

smartsync_test('cleanup filter exception restores cloud deletion context', function (): void {
    $storage = new SmartSyncCoreStorage();
    $core = smartsync_core_instance($storage);
    [$metadata, $paths] = smartsync_core_attachment(107);
    $throw = static function ($file) { throw new RuntimeException('Another plugin rejected cleanup'); };
    add_filter('wp_delete_file', $throw, 20);
    $core->queueAttachmentUpload($metadata, 107);
    $core->flushAttachmentUploads();
    smartsync_assert_same([], $storage->deletes);
    smartsync_assert_true(is_file($paths[0]));
    remove_filter('wp_delete_file', $throw, 20);
    $core->deleteFromS3($paths[0]);
    smartsync_assert_same(['wp-content/uploads/2020/01/core-107.jpg'], $storage->deletes);
});

smartsync_test('unrelated local deletion and unsafe stored keys do not delete cloud objects', function (): void {
    $storage = new SmartSyncCoreStorage();
    $core = smartsync_core_instance($storage);
    $unrelated = ABSPATH . 'unrelated-plugin-cache.js';
    file_put_contents($unrelated, 'fixture');
    smartsync_assert_same($unrelated, $core->deleteFromS3($unrelated));
    update_post_meta(108, '_smarl_offloaded_files', ['../outside.jpg', '/absolute.jpg']);
    $core->deleteAttachmentObjects(108);
    smartsync_assert_same([], $storage->deletes);
});

smartsync_test('attachment and responsive URLs stay local while pending or failed then switch after success', function (): void {
    $storage = new SmartSyncCoreStorage();
    $core = smartsync_core_instance($storage);
    [$metadata, $paths] = smartsync_core_attachment(109);
    $url = wp_upload_dir()['baseurl'] . '/2020/01/core-109.jpg';
    $sources = [150 => ['url' => wp_upload_dir()['baseurl'] . '/2020/01/core-109-150x150.jpg', 'descriptor' => 'w', 'value' => 150], 800 => ['url' => $url, 'descriptor' => 'w', 'value' => 800]];
    $core->queueAttachmentUpload($metadata, 109);
    smartsync_assert_same($url, $core->getAttachmentUrl($url, 109));
    smartsync_assert_same($sources, $core->rewriteImageSources($sources, [], $url, $metadata, 109));
    $storage->failName = basename($paths[1]);
    $core->flushAttachmentUploads();
    smartsync_assert_same($url, $core->getAttachmentUrl($url, 109));
    smartsync_assert_same($sources, $core->rewriteImageSources($sources, [], $url, $metadata, 109));
    $storage->failName = '';
    $core->queueAttachmentUpload($metadata, 109);
    $core->flushAttachmentUploads();
    smartsync_assert_same('https://cdn.example.test/bucket/wp-content/uploads/2020/01/core-109.jpg', $core->getAttachmentUrl($url, 109));
    $rewritten = $core->rewriteImageSources($sources, [], $url, $metadata, 109);
    smartsync_assert_same('https://cdn.example.test/bucket/wp-content/uploads/2020/01/core-109-150x150.jpg', $rewritten[150]['url']);
    smartsync_assert_same(150, $rewritten[150]['value']);
    smartsync_assert_true(is_file($paths[0]), 'A local URL already returned in this request must remain available');
    foreach ($paths as $path) @unlink($path);
    update_post_meta(109, '_smarl_offload_error', 'An older error marker');
    smartsync_assert_same('https://cdn.example.test/bucket/wp-content/uploads/2020/01/core-109.jpg', $core->getAttachmentUrl($url, 109), 'Legacy remote-only attachments must retain CDN delivery');
});

smartsync_test('final metadata upload makes response URLs ready before deferred cleanup', function (): void {
    $storage = new SmartSyncCoreStorage();
    $core = smartsync_core_instance($storage);
    [$metadata, $paths] = smartsync_core_attachment(110);
    $url = wp_upload_dir()['baseurl'] . '/2020/01/core-110.jpg';
    smartsync_assert_same($metadata, apply_filters('wp_generate_attachment_metadata', $metadata, 110));
    smartsync_assert_same(2, count($storage->uploadCalls));
    smartsync_assert_true(is_file($paths[0]), 'File cleanup must wait until shutdown');
    smartsync_assert_same('https://cdn.example.test/bucket/wp-content/uploads/2020/01/core-110.jpg', $core->getAttachmentUrl($url, 110));
    apply_filters('wp_update_attachment_metadata', $metadata, 110);
    $core->flushAttachmentUploads();
    smartsync_assert_same(2, count($storage->uploadCalls), 'Persisting final metadata must not duplicate provider uploads');
    smartsync_assert_true(!is_file($paths[0]));
});

smartsync_test('blog switches refresh registered callbacks and isolate pending attachment IDs', function (): void {
    $first = SMARL_CloudConfig::fromArray(['bucket' => 'fixture-one', 'iam_key' => 'fixture', 'iam_secret' => 'fixture', 'url' => 'cdn-one.example.test', 'use_cdn_for_media' => true]);
    $first->save();
    $core = new SMARL_SmartSync(dirname(__DIR__, 2) . '/aws-s3-smartsync-wp.php');
    $core->initializeActions();
    $core->initializeFilters();
    $core->queueAttachmentUpload(['file' => 'first.jpg'], 501);
    $second = SMARL_CloudConfig::fromArray(['bucket' => 'fixture-two', 'iam_key' => 'fixture', 'iam_secret' => 'fixture', 'url' => 'cdn-two.example.test', 'use_cdn_for_media' => true, 'use_cdn_for_assets' => true]);
    $second->save();
    $GLOBALS['smartsync_test_blog_id'] = 2;
    do_action('switch_blog', 2, 1, 'switch');
    smartsync_assert_same([], (new ReflectionProperty($core, 'pendingAttachments'))->getValue($core));
    $core->queueAttachmentUpload(['file' => 'second.jpg'], 501);
    smartsync_assert_same('https://cdn-two.example.test/wp-includes/js/test.js', apply_filters('script_loader_src', site_url('/wp-includes/js/test.js'), 'fixture'));
    $handler = (new ReflectionProperty($core, 'ajaxHandlers'))->getValue($core);
    smartsync_assert_same('fixture-two', (new ReflectionProperty($handler, 'appConfig'))->getValue($handler)->bucket);
    $first->save();
    $GLOBALS['smartsync_test_blog_id'] = 1;
    do_action('switch_blog', 1, 2, 'restore');
    smartsync_assert_same([501 => ['file' => 'first.jpg']], (new ReflectionProperty($core, 'pendingAttachments'))->getValue($core));
    smartsync_assert_same('fixture-one', (new ReflectionProperty($handler, 'appConfig'))->getValue($handler)->bucket);
});
