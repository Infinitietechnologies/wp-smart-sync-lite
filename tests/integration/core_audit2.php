<?php
/** Second-pass core audit regressions; only the separate disposable provider fixture is allowed. */
if (!defined('WP_CLI') || !WP_CLI || !str_contains(str_replace('\\', '/', ABSPATH), 'smartsync-provider-workflows/fresh/smartsync-wordpress-audit-fixture/wordpress/')) throw new RuntimeException('Use the separate fresh provider audit fixture.');

use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Models\SMARL_CloudConfig;

$GLOBALS['smarl_core_audit2_http_attempts'] = 0;
add_filter('pre_http_request', static function () {
    $GLOBALS['smarl_core_audit2_http_attempts']++;
    return new WP_Error('audit2_http_blocked', 'External HTTP is blocked.');
}, PHP_INT_MAX);
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
final class SmartSyncAudit2IntegrationStorage extends SMARL_CloudConfig {
    public array $objects = [];
    public array $deletes = [];
    public function __construct() {
        $this->bucket = 'audit-two'; $this->iamKey = 'fixture'; $this->iamSecret = 'fixture'; $this->url = 'cdn.audit2.example.test';
        $this->useCdnForMedia = $this->useCdnForAssets = $this->mediaAutoDelete = true;
    }
    public function uploadObject(string $key, string $path): void { $this->objects[$key] = file_get_contents($path); }
    public function deleteObject(string $key): void { $this->deletes[] = $key; unset($this->objects[$key]); }
    public function getS3Client(string $region = ''): ?\Aws\S3\S3Client { throw new RuntimeException('Real cloud clients are forbidden in core audit integration.'); }
}
function smarl_core_audit2_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function smarl_core_audit2_bind($core, SmartSyncAudit2IntegrationStorage $storage): void {
    $storage->save(); $core->switchBlogConfiguration(get_current_blog_id());
    (new ReflectionProperty($core, 'config'))->setValue($core, $storage);
}
function smarl_core_audit2_image(string $directory, array &$attachments): array {
    $path = $directory . '/photo-' . wp_generate_password(8, false) . '.jpg';
    $image = imagecreatetruecolor(800, 600); imagefill($image, 0, 0, imagecolorallocate($image, 60, 110, 150)); imagejpeg($image, $path, 90); imagedestroy($image);
    $id = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'Core audit2 fixture', 'post_status' => 'inherit'], $path, 0, true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    $attachments[] = $id;
    $metadata = wp_generate_attachment_metadata($id, $path); wp_update_attachment_metadata($id, $metadata);
    $files = [$path]; foreach ($metadata['sizes'] ?? [] as $size) $files[] = dirname($path) . '/' . $size['file'];
    $GLOBALS['smarl_core_audit2_created_files'] = array_merge($GLOBALS['smarl_core_audit2_created_files'] ?? [], $files);
    return [$id, $metadata, $files];
}

global $wpdb;
wp_set_current_user(1);
$core = null;
foreach (($GLOBALS['wp_filter']['wp_delete_file']->callbacks ?? []) as $callbacks) foreach ($callbacks as $callback) if (is_array($callback['function']) && $callback['function'][0] instanceof \SmartSyncS3\SMARL_SmartSync) $core = $callback['function'][0];
smarl_core_audit2_assert($core instanceof \SmartSyncS3\SMARL_SmartSync, 'The actual core bootstrap is required.');
$savedOptions = []; foreach (['smarl_settings', 'smarl_sync_settings', 'smarl_db_version', 'smarl_sync_lock'] as $name) $savedOptions[$name] = get_option($name, null);
$originalConfig = (new ReflectionProperty($core, 'config'))->getValue($core);
$directory = wp_upload_dir()['basedir'] . '/smarl-core-audit2-' . bin2hex(random_bytes(6)); wp_mkdir_p($directory);
$storage = new SmartSyncAudit2IntegrationStorage();
$attachments = []; $passed = 0; $uploadFilter = null; $veto = null;
try {
    smarl_core_audit2_bind($core, $storage);
    $dynamic = site_url('wp-admin/load-scripts.php?c=1&load=jquery');
    smarl_core_audit2_assert(apply_filters('script_loader_src', $dynamic, 'fixture') === $dynamic, 'Dynamic WordPress assets were redirected to an unuploaded CDN object.');
    $uploadFilter = static function (array $uploads): array {
        $uploads['baseurl'] = 'https://media.audit2.example.test/photos';
        $uploads['url'] = $uploads['baseurl'] . $uploads['subdir']; return $uploads;
    };
    add_filter('upload_dir', $uploadFilter);
    [$id, $metadata, $files] = smarl_core_audit2_image($directory, $attachments);
    $url = wp_get_attachment_url($id);
    smarl_core_audit2_assert($url === 'https://cdn.audit2.example.test/' . SMARL_Helper::relativeKeyForPath($files[0]), 'Actual customized uploads URL did not map to the physical uploaded key.');
    $core->flushAttachmentUploads();
    foreach ($files as $file) smarl_core_audit2_assert(!is_file($file) && isset($storage->objects[SMARL_Helper::relativeKeyForPath($file)]), 'Customized uploads cleanup failed to preserve deliverable remote bytes.');
    remove_filter('upload_dir', $uploadFilter); $uploadFilter = null;
    WP_CLI::log('PASS CORE-001/002 actual dynamic asset URLs remain local and custom uploads origins remain deliverable after cleanup'); $passed++;

    [$id, $metadata, $files] = smarl_core_audit2_image($directory, $attachments);
    file_put_contents($files[0], 'edited-after-upload', FILE_APPEND);
    file_put_contents($files[1], 'edited-thumbnail-after-upload', FILE_APPEND);
    $core->flushAttachmentUploads();
    foreach ($files as $file) smarl_core_audit2_assert(is_file($file), 'A changed attachment member caused deletion of the attachment or another size.');
    smarl_core_audit2_assert(get_post_meta($id, '_smarl_offload_error', true) !== '', 'Changed bytes did not produce recoverable local delivery.');
    apply_filters('wp_generate_attachment_metadata', $metadata, $id); wp_update_attachment_metadata($id, $metadata);
    foreach ($files as $file) smarl_core_audit2_assert($storage->objects[SMARL_Helper::relativeKeyForPath($file)] === file_get_contents($file), 'Identical metadata suppressed changed actual image bytes.');
    $core->flushAttachmentUploads();
    foreach ($files as $file) smarl_core_audit2_assert(!is_file($file), 'The verified replacement upload could not finish cleanup.');
    WP_CLI::log('PASS CORE-003/004 actual GD originals and sizes edited after upload stay local until the same metadata uploads their new bytes'); $passed++;

    [$id, $metadata, $files] = smarl_core_audit2_image($directory, $attachments);
    $changed = clone $storage; $changed->url = 'changed.audit2.example.test'; $changed->save();
    $core->flushAttachmentUploads();
    foreach ($files as $file) smarl_core_audit2_assert(is_file($file), 'An older request cleaned local files after the persisted delivery target changed.');
    smarl_core_audit2_assert(get_post_meta($id, '_smarl_offload_error', true) !== '', 'A target change was not retained as a recoverable media failure.');
    $replacement = new SMARL_CloudConfig(); $replacement->serviceProvider = 'bunny'; $replacement->bucket = 'replacement-target';
    $replacement->region = 'DE'; $replacement->bunnyStoragePassword = 'fixture-replacement-password'; $replacement->url = 'replacement.b-cdn.net'; $replacement->save();
    $core->switchBlogConfiguration(get_current_blog_id());
    $httpBefore = $GLOBALS['smarl_core_audit2_http_attempts']; wp_delete_attachment($id, true);
    smarl_core_audit2_assert($GLOBALS['smarl_core_audit2_http_attempts'] === $httpBefore, 'Old attachment ownership authorized native Bunny DELETE in a replacement target.');
    smarl_core_audit2_bind($core, $storage);
    $storage->mediaAutoDelete = false; smarl_core_audit2_bind($core, $storage);
    [$id, $metadata, $files] = smarl_core_audit2_image($directory, $attachments); $core->flushAttachmentUploads();
    $veto = static fn($file) => ''; add_filter('wp_delete_file', $veto, 20);
    $deleteCount = count($storage->deletes); wp_delete_file($files[0]); $core->flushAttachmentUploads();
    smarl_core_audit2_assert(is_file($files[0]) && count($storage->deletes) === $deleteCount, 'A later WordPress deletion veto lost the remote copy.');
    remove_filter('wp_delete_file', $veto, 20); $veto = null;
    wp_delete_file($files[0]); $core->flushAttachmentUploads();
    smarl_core_audit2_assert(!is_file($files[0]) && count($storage->deletes) === $deleteCount + 1, 'Confirmed actual local deletion was not mirrored once.');
    WP_CLI::log('PASS CORE-005/006/007 target changes prevent stale cleanup and wrong-target deletion; later deletion filters cannot prematurely remove cloud copies'); $passed++;

    $storage->mediaAutoDelete = true; smarl_core_audit2_bind($core, $storage);
    [$id, $metadata, $files] = smarl_core_audit2_image($directory, $attachments);
    foreach (['source_image' => 'source.heic', 'animated_video' => 'motion.mp4', 'animated_video_poster' => 'poster.jpg'] as $field => $suffix) {
        $name = $id . '-' . $suffix; $metadata[$field] = $name; $file = $directory . '/' . $name;
        file_put_contents($file, 'fixture-companion-' . $field); $files[] = $file; $GLOBALS['smarl_core_audit2_created_files'][] = $file;
    }
    apply_filters('wp_generate_attachment_metadata', $metadata, $id); wp_update_attachment_metadata($id, $metadata);
    foreach ($files as $file) smarl_core_audit2_assert($storage->objects[SMARL_Helper::relativeKeyForPath($file)] === file_get_contents($file), 'A source-format/video companion was omitted from native metadata upload.');
    $core->flushAttachmentUploads();
    foreach ($files as $file) smarl_core_audit2_assert(!is_file($file), 'A verified companion was omitted from deferred local cleanup.');
    WP_CLI::log('PASS CORE-008 real WordPress metadata source-format, animated video and poster companions upload and clean up with their original'); $passed++;

    $storage->mediaAutoDelete = false; smarl_core_audit2_bind($core, $storage);
    [$first, $metadata, $files] = smarl_core_audit2_image($directory, $attachments); $core->flushAttachmentUploads();
    $obsolete = $files[1]; $obsoleteKey = SMARL_Helper::relativeKeyForPath($obsolete);
    foreach ($metadata['sizes'] as $size => $info) if ($info['file'] === basename($obsolete)) unset($metadata['sizes'][$size]);
    update_post_meta($first, '_wp_attachment_metadata', $metadata);
    wp_delete_file($obsolete); $core->flushAttachmentUploads();
    smarl_core_audit2_assert(!in_array($obsoleteKey, get_post_meta($first, '_smarl_offloaded_files', true), true), 'An obsolete confirmed-deleted key remained in the restore manifest.');
    $second = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'Shared core audit2 fixture', 'post_status' => 'inherit'], $files[0], 0, true);
    smarl_core_audit2_assert(!is_wp_error($second), 'Shared attachment insertion failed.'); $attachments[] = $second;
    apply_filters('wp_generate_attachment_metadata', $metadata, $second); wp_update_attachment_metadata($second, $metadata); $core->flushAttachmentUploads();
    $sharedKeys = get_post_meta($second, '_smarl_offloaded_files', true);
    wp_delete_attachment($first, true); $core->flushAttachmentUploads();
    foreach ($sharedKeys as $key) smarl_core_audit2_assert(isset($storage->objects[$key]), 'Deleting one actual attachment removed another attachment shared cloud object.');
    smarl_core_audit2_assert(str_starts_with(wp_get_attachment_url($second), 'https://cdn.audit2.example.test/'), 'The remaining shared attachment lost cloud delivery.');
    wp_delete_attachment($second, true); $core->flushAttachmentUploads();
    foreach ($sharedKeys as $key) smarl_core_audit2_assert(!isset($storage->objects[$key]), 'The final shared owner could not intentionally delete its cloud object.');
    WP_CLI::log('PASS CORE-009/010 obsolete keys leave real restore manifests and deleting one of two attachment owners preserves shared cloud delivery'); $passed++;

    $core->registerAutoload(); $core->loadPluginTextDomain();
    $settings = get_option('smarl_settings');
    $core->onPluginActivation('unrelated/plugin.php'); $core->onPluginDeactivation('unrelated/plugin.php');
    smarl_core_audit2_assert(get_option('smarl_settings') === $settings, 'Legacy unrelated-plugin wrappers changed cloud configuration.');
    $slug = 'smartsync/aws-s3-smartsync-wp.php';
    $core->onPluginActivation($slug);
    $core->onPluginDeactivation($slug);
    $object = (object) ['fixture' => true];
    delete_option('smarl_db_version');
    smarl_core_audit2_assert($core->onPluginUpdate($object, ['type' => 'plugin', 'plugins' => [$slug]]) === $object && get_option('smarl_db_version') === SMARL_DB_VERSION, 'Legacy update wrapper failed schema refresh or changed the upgrader result.');
    \SmartSyncS3\SMARL_SmartSync::onPluginUninstall();
    smarl_core_audit2_assert(get_option('smarl_settings') === $settings, 'The inert legacy uninstall wrapper performed cleanup outside uninstall.php.');
    WP_CLI::log('PASS legacy autoload, translation, activation/deactivation/update wrappers and inert uninstall wrapper execute safely in real WordPress'); $passed++;
} finally {
    if ($uploadFilter) remove_filter('upload_dir', $uploadFilter);
    if ($veto) remove_filter('wp_delete_file', $veto, 20);
    smarl_core_audit2_bind($core, $storage);
    foreach ($attachments as $id) if (get_post($id)) wp_delete_attachment($id, true);
    $core->flushAttachmentUploads();
    foreach ($GLOBALS['smarl_core_audit2_created_files'] ?? [] as $file) if (str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $directory) . '/') && is_file($file)) unlink($file);
    foreach ($savedOptions as $name => $value) { if ($value === null) delete_option($name); else update_option($name, $value, false); }
    (new ReflectionProperty($core, 'config'))->setValue($core, $originalConfig);
    if (is_dir($directory)) rmdir($directory);
}
WP_CLI::success($passed . ' second-pass core WordPress integration groups passed; no cloud calls were made.');
