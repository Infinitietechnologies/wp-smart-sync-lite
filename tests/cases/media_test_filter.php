<?php
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Models\SMARL_CloudConfig;

smartsync_test('temporary media filter queues selected formats only and leaves CDN eligibility unchanged', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    $root = wp_upload_dir()['basedir'];
    file_put_contents($root . '/test-format.jpg', 'jpg');
    file_put_contents($root . '/test-format.png', 'png');
    file_put_contents($root . '/test-format.svg', '<svg/>');
    $_POST = ['type' => 'media', 'mode' => 'full', 'media_test_extensions' => ['png', 'svg']];
    $start = smartsync_capture_json(fn() => $handler->syncLocalToAws());
    smartsync_assert_true($start['success']);
    smartsync_assert_same(2, $start['data']['total_files']);
    smartsync_assert_same(['png', 'svg'], smartsync_progress()->extensions);
    smartsync_run_jobs($handler);
    smartsync_assert_same('completed', smartsync_progress()->s3SyncStatus);
    smartsync_assert_same(2, count($storage->uploads));
    smartsync_assert_true(!SMARL_CloudConfig::load()->canEnableMediaCDN);
    $status = smartsync_capture_json(fn() => $handler->checkSyncStatus())['data'];
    smartsync_assert_same(true, $status['filtered_media_upload']);
    smartsync_assert_same(false, $status['media_cdn_sync_ready']);
    $_POST = ['type' => 'media'];
    $all = smartsync_capture_json(fn() => $handler->syncLocalToAws());
    smartsync_assert_same(1, $all['data']['total_files']);
    smartsync_assert_same(2, $all['data']['skipped_files']);
    smartsync_run_jobs($handler);
    smartsync_assert_true(SMARL_CloudConfig::load()->canEnableMediaCDN);
    $_POST = ['type' => 'media', 'useCdn' => 'true'];
    smartsync_assert_same(true, smartsync_capture_json(fn() => $handler->toggleCdn())['success']);
});

smartsync_test('successful selected-format sync enables destination-bound temporary CDN testing and keeps local copies', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    file_put_contents(wp_upload_dir()['basedir'] . '/cdn-test.png', 'png');
    $_POST = ['type' => 'media', 'media_test_extensions' => ['png']];
    smartsync_capture_json(fn() => $handler->syncLocalToAws()); smartsync_run_jobs($handler);
    $_POST = ['type' => 'media', 'useCdn' => 'true'];
    $response = smartsync_capture_json(fn() => $handler->toggleCdn());
    smartsync_assert_same(true, $response['success']);
    smartsync_assert_same(['png'], $response['data']['cdn_test_extensions']);
    $config = SMARL_CloudConfig::load();
    smartsync_assert_true($config->useCdnForMedia && !$config->canEnableMediaCDN && !$config->mediaAutoDelete && $config->hasMediaCdnTestScope());
    $_POST = ['state' => 'true'];
    smartsync_assert_same(false, smartsync_capture_json(fn() => $handler->toggleMediaAutoDelete())['success']);
    $_POST = ['type' => 'media', 'useCdn' => 'false'];
    smartsync_assert_same(true, smartsync_capture_json(fn() => $handler->toggleCdn())['success']);
    smartsync_assert_same([], SMARL_CloudConfig::load()->mediaCdnTestExtensions);
});

smartsync_test('temporary CDN delivery rewrites only confirmed unchanged selected files and disables cleanup', function () {
    $storage = new SmartSyncCoreStorage(); $storage->mediaCdnTestExtensions = ['png'];
    $storage->mediaCdnTestTarget = SMARL_Helper::storageTargetFingerprint($storage);
    $core = smartsync_core_instance($storage);
    $root = wp_upload_dir()['basedir'];
    foreach (['confirmed.png', 'untracked.png', 'other.jpg'] as $file) file_put_contents($root . '/' . $file, 'original');
    foreach (['confirmed.png', 'other.jpg'] as $file) {
        $path = $root . '/' . $file;
        \SmartSyncS3\Models\SMARL_UploadHistory::record($storage, SMARL_Helper::relativeKeyForPath($path), \SmartSyncS3\Models\SMARL_UploadHistory::signature($path));
    }
    $url = wp_upload_dir()['baseurl'] . '/confirmed.png';
    smartsync_assert_true(str_starts_with($core->getAttachmentUrl($url), 'https://cdn.example.test/bucket/'));
    foreach (['untracked.png', 'other.jpg'] as $file) {
        $local = wp_upload_dir()['baseurl'] . '/' . $file;
        smartsync_assert_same($local, $core->getAttachmentUrl($local));
    }
    file_put_contents($root . '/confirmed.png', 'modified');
    smartsync_assert_same($url, $core->getAttachmentUrl($url));
    smartsync_assert_same(false, (new ReflectionMethod($core, 'canCleanLocalMedia'))->invoke($core));
    file_put_contents($root . '/confirmed.png', 'original');
    $storage->bucket = 'another-destination';
    smartsync_assert_same($url, $core->getAttachmentUrl($url));
});

smartsync_test('temporary media filter rejects unsupported formats and non-media requests before changing progress', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    foreach ([['exe'], [], 'png', [['png']], ['PNG']] as $bad) {
        $_POST = ['type' => 'media', 'media_test_extensions' => $bad];
        $result = smartsync_capture_json(fn() => $handler->syncLocalToAws());
        smartsync_assert_same(false, $result['success']);
        smartsync_assert_same(400, $result['status']);
        smartsync_assert_same('', smartsync_progress()->runId);
    }
    $_POST = ['type' => 'assets', 'media_test_extensions' => ['png']];
    smartsync_assert_same(false, smartsync_capture_json(fn() => $handler->syncLocalToAws())['success']);
    $_POST = ['type' => 'media', 'media_test_extensions' => ['png']];
    smartsync_assert_same(false, smartsync_capture_json(fn() => $handler->syncAwsToLocal())['success']);
    smartsync_assert_same('', smartsync_progress()->runId);
});
