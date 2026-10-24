<?php
declare(strict_types=1);

use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Models\SMARL_CloudConfig;
use SmartSyncS3\SMARL_SmartSync;

final class SmartSyncAudit2Storage extends SMARL_CloudConfig {
    public array $objects = [];
    public array $uploadCalls = [];
    public array $deletes = [];
    public mixed $afterUpload = null;
    public function __construct() {
        $this->bucket = 'audit-two'; $this->iamKey = 'fixture'; $this->iamSecret = 'fixture';
        $this->url = 'cdn.example.test/bucket';
        $this->useCdnForMedia = $this->useCdnForAssets = $this->mediaAutoDelete = true;
    }
    public function uploadObject(string $key, string $path): void {
        $this->uploadCalls[] = $key;
        $this->objects[$key] = file_get_contents($path);
        if ($this->afterUpload) ($this->afterUpload)($key, $path);
    }
    public function deleteObject(string $key): void { $this->deletes[] = $key; unset($this->objects[$key]); }
}
function smartsync_audit2_core(SmartSyncAudit2Storage $storage): SMARL_SmartSync {
    $storage->save();
    $core = new SMARL_SmartSync(dirname(__DIR__, 2) . '/aws-s3-smartsync-wp.php');
    (new ReflectionProperty($core, 'config'))->setValue($core, $storage);
    $core->initializeFilters();
    return $core;
}
function smartsync_audit2_attachment(int $id): array {
    $relative = '2020/01/audit2-' . $id . '.jpg';
    $path = wp_upload_dir()['basedir'] . '/' . $relative;
    wp_mkdir_p(dirname($path)); file_put_contents($path, 'original-before-edit');
    update_post_meta($id, '_wp_attached_file', $relative);
    $metadata = ['file' => $relative, 'width' => 100, 'height' => 100, 'sizes' => []];
    update_post_meta($id, '_wp_attachment_metadata', $metadata);
    return [$metadata, $path, SMARL_Helper::relativeKeyForPath($path)];
}

smartsync_test('CORE-001 dynamic asset endpoints remain local while static asset URLs use CDN', function (): void {
    $core = smartsync_audit2_core(new SmartSyncAudit2Storage());
    foreach (['https://example.test/blog/wp-admin/load-scripts.php?c=1&load=jquery', 'https://example.test/blog/wp-admin/load-styles.php?load=common', 'https://example.test/blog/?generated=theme.css'] as $url) smartsync_assert_same($url, $core->replaceBaseUrl($url));
    smartsync_assert_same('https://cdn.example.test/bucket/wp-includes/js/jquery.js?ver=3', $core->replaceBaseUrl('https://example.test/blog/wp-includes/js/jquery.js?ver=3'));
});

smartsync_test('CORE-002 configured uploads origin and path map to physical keys before cleanup', function (): void {
    $GLOBALS['smartsync_test_upload_dir'] = ['baseurl' => 'https://media.example.test/photos'];
    $storage = new SmartSyncAudit2Storage(); $core = smartsync_audit2_core($storage);
    [$metadata, $path, $key] = smartsync_audit2_attachment(201);
    $core->completeAttachmentUpload($metadata, 201);
    smartsync_assert_same('https://cdn.example.test/bucket/' . $key . '?v=2#photo', $core->getAttachmentUrl('https://media.example.test/photos/2020/01/audit2-201.jpg?v=2#photo', 201));
    smartsync_assert_same('https://unrelated.example.test/photos/2020/01/audit2-201.jpg', $core->getAttachmentUrl('https://unrelated.example.test/photos/2020/01/audit2-201.jpg', 201));
    $core->flushAttachmentUploads(); smartsync_assert_true(!is_file($path));
});

smartsync_test('CORE-003 deferred cleanup retains local bytes changed after successful upload', function (): void {
    $storage = new SmartSyncAudit2Storage(); $core = smartsync_audit2_core($storage);
    [$metadata, $path, $key] = smartsync_audit2_attachment(202);
    $core->completeAttachmentUpload($metadata, 202);
    file_put_contents($path, 'edited-after-final-upload');
    $core->flushAttachmentUploads();
    smartsync_assert_true(is_file($path), 'New local bytes must not be deleted based on the old upload.');
    smartsync_assert_same('original-before-edit', $storage->objects[$key]);
    smartsync_assert_true(get_post_meta(202, '_smarl_offload_error', true) !== '');
    smartsync_assert_same('https://example.test/blog/wp-content/uploads/2020/01/audit2-202.jpg', $core->getAttachmentUrl('https://example.test/blog/wp-content/uploads/2020/01/audit2-202.jpg', 202));
});

smartsync_test('CORE-004 identical metadata does not suppress upload of edited file bytes', function (): void {
    $storage = new SmartSyncAudit2Storage(); $core = smartsync_audit2_core($storage);
    [$metadata, $path, $key] = smartsync_audit2_attachment(203);
    $core->completeAttachmentUpload($metadata, 203);
    file_put_contents($path, 'new-edited-image-bytes');
    $core->completeAttachmentUpload($metadata, 203);
    smartsync_assert_same('new-edited-image-bytes', $storage->objects[$key]);
    smartsync_assert_same(2, count($storage->uploadCalls));
    $core->flushAttachmentUploads(); smartsync_assert_true(!is_file($path));
});

smartsync_test('CORE-005 changed delivery target cancels cleanup of files uploaded to the old target', function (): void {
    $storage = new SmartSyncAudit2Storage(); $core = smartsync_audit2_core($storage);
    [$metadata, $path] = smartsync_audit2_attachment(204);
    $core->completeAttachmentUpload($metadata, 204);
    $new = clone $storage; $new->bucket = 'changed-target'; $new->url = 'new-cdn.example.test'; $new->save();
    $core->flushAttachmentUploads();
    smartsync_assert_true(is_file($path), 'Deferred cleanup must not strand media on an old cloud target.');
    smartsync_assert_true(get_post_meta(204, '_smarl_offload_error', true) !== '');
});

smartsync_test('CORE-005 media upload honors a concurrent configuration lock and retries safely', function (): void {
    $storage = new SmartSyncAudit2Storage(); $core = smartsync_audit2_core($storage);
    [$metadata, $path] = smartsync_audit2_attachment(205);
    $token = SMARL_Helper::acquireSyncLock();
    $core->completeAttachmentUpload($metadata, 205);
    smartsync_assert_same([], $storage->uploadCalls, 'Media must not bypass an active configuration/worker lease.');
    smartsync_assert_true(is_file($path));
    SMARL_Helper::releaseSyncLock($token);
    $storage->afterUpload = static function (): void { smartsync_assert_same(null, SMARL_Helper::acquireSyncLock(), 'The media upload must hold the shared coordination lease.'); };
    $core->completeAttachmentUpload($metadata, 205); $core->flushAttachmentUploads();
    smartsync_assert_same(1, count($storage->uploadCalls)); smartsync_assert_true(!is_file($path));
});

smartsync_test('CORE-006 a vetoed WordPress file deletion cannot remove the remote copy', function (): void {
    $storage = new SmartSyncAudit2Storage(); $storage->mediaAutoDelete = false; $core = smartsync_audit2_core($storage);
    [$metadata, $path, $key] = smartsync_audit2_attachment(206);
    $core->completeAttachmentUpload($metadata, 206);
    $veto = static fn($file) => '';
    add_filter('wp_delete_file', $veto, 20);
    wp_delete_file($path); $core->flushAttachmentUploads();
    smartsync_assert_true(is_file($path) && isset($storage->objects[$key]));
    smartsync_assert_same([], $storage->deletes, 'A pre-delete filter must not claim the file was actually deleted.');
    remove_filter('wp_delete_file', $veto, 20);
    wp_delete_file($path); $core->flushAttachmentUploads();
    smartsync_assert_same([$key], $storage->deletes);
});

smartsync_test('CORE-007 an attachment manifest cannot delete matching keys in a replacement cloud target', function (): void {
    $storage = new SmartSyncAudit2Storage(); $storage->serviceProvider = 'bunny'; $storage->bucket = 'old-target';
    $storage->region = 'DE'; $storage->bunnyStoragePassword = 'fixture-password'; $storage->mediaAutoDelete = false;
    $core = smartsync_audit2_core($storage);
    [$metadata] = smartsync_audit2_attachment(207);
    $core->completeAttachmentUpload($metadata, 207); $core->flushAttachmentUploads();
    $replacement = clone $storage; $replacement->bucket = 'replacement-target'; $replacement->url = 'replacement.b-cdn.net'; $replacement->save();
    $GLOBALS['smartsync_test_http'] = static fn() => ['response' => ['code' => 200], 'body' => '{"Success":true}'];
    $core->deleteAttachmentObjects(207);
    smartsync_assert_same([], $GLOBALS['smartsync_test_http_requests'], 'Keys offloaded to the old target cannot authorize deletion in the new target.');
});

smartsync_test('CORE-008 metadata source and animated media companions are offloaded with the attachment', function (): void {
    $storage = new SmartSyncAudit2Storage(); $core = smartsync_audit2_core($storage);
    [$metadata, $path] = smartsync_audit2_attachment(208);
    $companions = ['source_image' => 'audit2-source.heic', 'animated_video' => 'audit2-motion.mp4', 'animated_video_poster' => 'audit2-poster.jpg'];
    foreach ($companions as $field => $name) { $metadata[$field] = $name; file_put_contents(dirname($path) . '/' . $name, 'fixture-' . $field); }
    update_post_meta(208, '_wp_attachment_metadata', $metadata);
    $core->completeAttachmentUpload($metadata, 208);
    foreach ($companions as $field => $name) smartsync_assert_same('fixture-' . $field, $storage->objects['wp-content/uploads/2020/01/' . $name] ?? null, 'A referenced companion must exist before its URL is rewritten to CDN.');
    $core->flushAttachmentUploads();
    foreach ($companions as $name) smartsync_assert_true(!is_file(dirname($path) . '/' . $name));
    $core->deleteAttachmentObjects(208); smartsync_assert_same(4, count($storage->deletes));
});

smartsync_test('CORE-003 bytes changed during provider upload fail safely and can be retried', function (): void {
    $storage = new SmartSyncAudit2Storage(); $core = smartsync_audit2_core($storage);
    [$metadata, $path] = smartsync_audit2_attachment(209);
    $storage->afterUpload = static fn($key, $file) => file_put_contents($file, 'changed-during-upload');
    $core->completeAttachmentUpload($metadata, 209); $core->flushAttachmentUploads();
    smartsync_assert_true(is_file($path) && get_post_meta(209, '_smarl_offload_error', true) !== '');
    $storage->afterUpload = null;
    $core->completeAttachmentUpload($metadata, 209); $core->flushAttachmentUploads();
    smartsync_assert_true(!is_file($path));
});

smartsync_test('CORE-007 partial replacement-target uploads retain the prior manifest ownership', function (): void {
    $storage = new SmartSyncAudit2Storage(); $storage->mediaAutoDelete = false; $core = smartsync_audit2_core($storage);
    [$metadata, $path] = smartsync_audit2_attachment(210);
    $core->completeAttachmentUpload($metadata, 210); $core->flushAttachmentUploads();
    $keys = get_post_meta(210, '_smarl_offloaded_files', true); $target = get_post_meta(210, '_smarl_offload_target', true);
    $replacement = clone $storage; $replacement->bucket = 'replacement-target'; $replacement->save();
    $core->switchBlogConfiguration(1); (new ReflectionProperty($core, 'config'))->setValue($core, $replacement);
    $metadata['sizes'] = ['thumbnail' => ['file' => 'not-generated.jpg']];
    $core->completeAttachmentUpload($metadata, 210);
    smartsync_assert_same($keys, get_post_meta(210, '_smarl_offloaded_files', true));
    smartsync_assert_same($target, get_post_meta(210, '_smarl_offload_target', true));
    smartsync_assert_true(is_file($path) && get_post_meta(210, '_smarl_offload_error', true) !== '');
});

smartsync_test('CORE-007 untagged legacy manifests cannot authorize ambiguous cloud deletion', function (): void {
    $storage = new SmartSyncAudit2Storage(); $storage->mediaAutoDelete = false; $core = smartsync_audit2_core($storage);
    [, $path, $key] = smartsync_audit2_attachment(211);
    update_post_meta(211, '_smarl_offloaded_files', [$key]); $storage->objects[$key] = 'legacy';
    wp_delete_file($path); $core->flushAttachmentUploads(); $core->deleteAttachmentObjects(211);
    smartsync_assert_same([], $storage->deletes); smartsync_assert_same('legacy', $storage->objects[$key]);
});

smartsync_test('CORE-009 confirmed deletion drops obsolete manifest keys so restore guards remain usable', function (): void {
    $storage = new SmartSyncAudit2Storage(); $storage->mediaAutoDelete = false; $core = smartsync_audit2_core($storage);
    [$metadata, $path, $key] = smartsync_audit2_attachment(212);
    $thumbnail = dirname($path) . '/audit2-212-old-size.jpg'; file_put_contents($thumbnail, 'old-size');
    $metadata['sizes'] = ['thumbnail' => ['file' => basename($thumbnail)]]; update_post_meta(212, '_wp_attachment_metadata', $metadata);
    $core->completeAttachmentUpload($metadata, 212); $core->flushAttachmentUploads();
    $metadata['sizes'] = []; update_post_meta(212, '_wp_attachment_metadata', $metadata);
    wp_delete_file($thumbnail); $core->flushAttachmentUploads();
    smartsync_assert_same([$key], get_post_meta(212, '_smarl_offloaded_files', true));
    smartsync_assert_true(!SMARL_Helper::hasMissingOffloadedFiles(), 'A deliberately removed obsolete object cannot require an impossible restore.');
});

smartsync_test('CORE-010 deleting one attachment preserves a cloud key still owned by another attachment', function (): void {
    $storage = new SmartSyncAudit2Storage(); $storage->mediaAutoDelete = false; $core = smartsync_audit2_core($storage);
    [$metadata, $path, $key] = smartsync_audit2_attachment(213);
    $core->completeAttachmentUpload($metadata, 213); $core->flushAttachmentUploads();
    update_post_meta(214, '_wp_attached_file', get_post_meta(213, '_wp_attached_file', true));
    update_post_meta(214, '_wp_attachment_metadata', $metadata);
    $core->completeAttachmentUpload($metadata, 214); $core->flushAttachmentUploads();
    $core->deleteAttachmentObjects(213);
    delete_post_meta(213, '_smarl_offloaded_files'); delete_post_meta(213, '_smarl_offload_target');
    wp_delete_file($path); $core->flushAttachmentUploads();
    smartsync_assert_same([], $storage->deletes); smartsync_assert_true(isset($storage->objects[$key]));
    $core->deleteAttachmentObjects(214); smartsync_assert_same([$key], $storage->deletes);
});
