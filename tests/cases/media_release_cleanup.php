<?php
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Models\SMARL_CloudConfig;

smartsync_test('retired delivery settings migrate safely and preserve ordinary provider and asset configuration', function () {
    [$storage] = smartsync_sync_fixture();
    $ordinary = $storage->toArray();
    $ordinary['use_cdn_for_media'] = $ordinary['canEnableMediaCDN'] = $ordinary['mediaAutoDelete'] = true;
    $ordinary['use_cdn_for_assets'] = $ordinary['canEnableAssetCDN'] = true;
    foreach ([['png'], []] as $extensions) {
        $legacy = $ordinary + ['mediaCdnTestExtensions' => $extensions, 'mediaCdnTestTarget' => $extensions ? 'old-target' : ''];
        update_option('smarl_settings', $legacy);
        $config = SMARL_CloudConfig::load();
        $expected = $ordinary;
        if ($extensions) $expected['use_cdn_for_media'] = $expected['canEnableMediaCDN'] = $expected['mediaAutoDelete'] = false;
        smartsync_assert_same($expected, $config->toArray());
        smartsync_assert_same($expected, get_option('smarl_settings'));
    }
});

smartsync_test('retired settings remain safe while another request owns the lease and clean up after release', function () {
    [$storage] = smartsync_sync_fixture();
    $legacy = $storage->toArray() + ['mediaCdnTestExtensions' => ['png'], 'mediaCdnTestTarget' => 'old-target'];
    $legacy['use_cdn_for_media'] = true;
    update_option('smarl_settings', $legacy);
    $lease = SMARL_Helper::acquireSyncLock();
    smartsync_assert_true($lease !== null);
    try {
        smartsync_assert_same(false, SMARL_CloudConfig::load()->useCdnForMedia);
        smartsync_assert_same($legacy, get_option('smarl_settings'));
    } finally { SMARL_Helper::releaseSyncLock($lease); }
    smartsync_assert_same(false, SMARL_CloudConfig::load()->useCdnForMedia);
    smartsync_assert_true(!array_key_exists('mediaCdnTestExtensions', get_option('smarl_settings')));
});

smartsync_test('obsolete cached upload requests are rejected without changing an active run or queue', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    file_put_contents(wp_upload_dir()['basedir'] . '/normal.png', 'png');
    $_POST = ['type' => 'media'];
    smartsync_assert_true(smartsync_capture_json(fn() => $handler->syncLocalToAws())['success']);
    $before = get_option('smarl_sync_settings'); $jobs = $GLOBALS['smartsync_test_jobs'];
    foreach ([['png'], [], 'png'] as $oldSelection) {
        $_POST = ['type' => 'media', 'media_test_extensions' => $oldSelection];
        $response = smartsync_capture_json(fn() => $handler->syncLocalToAws());
        smartsync_assert_same(false, $response['success']);
        smartsync_assert_same(400, $response['status']);
        smartsync_assert_true(str_contains($response['data'], 'removed'));
        smartsync_assert_same($before, get_option('smarl_sync_settings'));
        smartsync_assert_same($jobs, $GLOBALS['smartsync_test_jobs']);
    }
});

smartsync_test('failed retired-setting persistence stays safe and retries on the next load', function () {
    [$storage] = smartsync_sync_fixture();
    $legacy = $storage->toArray() + ['mediaCdnTestExtensions' => ['png'], 'mediaCdnTestTarget' => 'old-target'];
    $legacy['use_cdn_for_media'] = true;
    update_option('smarl_settings', $legacy);
    $GLOBALS['smartsync_provider_failed_option'] = 'smarl_settings';
    try {
        smartsync_assert_same(false, SMARL_CloudConfig::load()->useCdnForMedia);
        smartsync_assert_same($legacy, get_option('smarl_settings'));
    } finally { unset($GLOBALS['smartsync_provider_failed_option']); }
    smartsync_assert_same(false, SMARL_CloudConfig::load()->useCdnForMedia);
    smartsync_assert_true(!array_key_exists('mediaCdnTestExtensions', get_option('smarl_settings')));
    smartsync_assert_same(false, get_option('smarl_sync_lock'));
});

smartsync_test('normal media uploads cover all supported formats and incremental reruns skip confirmed files', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    foreach (['png', 'svg', 'jpg'] as $extension) file_put_contents(wp_upload_dir()['basedir'] . '/normal.' . $extension, $extension);
    $_POST = ['type' => 'media'];
    $response = smartsync_capture_json(fn() => $handler->syncLocalToAws());
    smartsync_assert_true($response['success']);
    smartsync_assert_same(3, $response['data']['total_files']);
    smartsync_assert_same(SMARL_Helper::getExtension('media'), smartsync_progress()->extensions);
    smartsync_run_jobs($handler);
    smartsync_assert_true(SMARL_CloudConfig::load()->canEnableMediaCDN);
    $response = smartsync_capture_json(fn() => $handler->syncLocalToAws());
    smartsync_assert_same(0, $response['data']['total_files']);
    smartsync_assert_same(3, $response['data']['skipped_files']);
    smartsync_assert_same(3, count($storage->uploads));
    $status = smartsync_capture_json(fn() => $handler->checkSyncStatus())['data'];
    foreach (['filtered_media_upload', 'media_cdn_sync_ready', 'cdn_test_extensions'] as $key) smartsync_assert_true(!array_key_exists($key, $status));
});

smartsync_test('an older partial-format queued run cannot grant full-library CDN eligibility', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    file_put_contents(wp_upload_dir()['basedir'] . '/legacy.png', 'png');
    $_POST = ['type' => 'media'];
    smartsync_assert_true(smartsync_capture_json(fn() => $handler->syncLocalToAws())['success']);
    $progress = smartsync_progress(); $progress->extensions = ['png']; $progress->save();
    smartsync_run_jobs($handler);
    smartsync_assert_same('completed', smartsync_progress()->s3SyncStatus);
    smartsync_assert_same(false, SMARL_CloudConfig::load()->canEnableMediaCDN);
    $_POST = ['type' => 'media', 'useCdn' => 'true'];
    smartsync_assert_same(false, smartsync_capture_json(fn() => $handler->toggleCdn())['success']);
});
