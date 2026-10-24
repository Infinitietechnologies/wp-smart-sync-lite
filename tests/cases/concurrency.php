<?php
use SmartSyncS3\Ajax\SMARL_AjaxHandler;
use SmartSyncS3\Models\SMARL_CloudConfig;
use SmartSyncS3\Models\SMARL_S3SyncSettings;

smartsync_test('a stopped run cannot be resurrected by a stale progress snapshot', function () {
    $active = new SMARL_S3SyncSettings(); $active->runId = 'run-old'; $active->s3SyncDirection = 'upload'; $active->s3SyncStatus = 'processing'; $active->syncState = 'syncing'; $active->s3SyncTotalFiles = 5; $active->save();
    $worker = new SMARL_S3SyncSettings(); $worker->load();
    (new SMARL_S3SyncSettings())->reset();
    $worker->s3SyncProcessedFiles = 3;
    smartsync_assert_same(false, $worker->saveForRun('run-old'));
    $persisted = new SMARL_S3SyncSettings(); $persisted->load();
    smartsync_assert_same('idle', $persisted->s3SyncStatus); smartsync_assert_same('', $persisted->runId); smartsync_assert_same(0, $persisted->s3SyncProcessedFiles);
});

smartsync_test('a stale worker cannot replace a newer run even when both snapshots are processing', function () {
    $first = new SMARL_S3SyncSettings(); $first->runId = 'run-first'; $first->s3SyncDirection = 'upload'; $first->s3SyncStatus = 'processing'; $first->save();
    $worker = new SMARL_S3SyncSettings(); $worker->load();
    $next = new SMARL_S3SyncSettings(); $next->runId = 'run-next'; $next->s3SyncDirection = 'upload'; $next->s3SyncStatus = 'processing'; $next->s3SyncTotalFiles = 12; $next->save();
    $worker->s3SyncStatus = 'completed';
    smartsync_assert_same(false, $worker->saveForRun('run-first'));
    $persisted = new SMARL_S3SyncSettings(); $persisted->load();
    smartsync_assert_same('run-next', $persisted->runId); smartsync_assert_same('processing', $persisted->s3SyncStatus); smartsync_assert_same(12, $persisted->s3SyncTotalFiles);
});

smartsync_test('conditional progress persistence permits the current completion and rejects duplicate terminal writes', function () {
    $worker = new SMARL_S3SyncSettings(); $worker->runId = 'run-current'; $worker->s3SyncDirection = 'upload'; $worker->s3SyncStatus = 'processing'; $worker->save();
    smartsync_assert_same(true, $worker->saveForRun('run-current'));
    $worker->s3SyncStatus = 'completed'; $worker->s3SyncProcessedFiles = 4;
    smartsync_assert_same(true, $worker->saveForRun('run-current'));
    $worker->s3SyncStatus = 'failed';
    smartsync_assert_same(false, $worker->saveForRun('run-current'));
    $persisted = new SMARL_S3SyncSettings(); $persisted->load(); smartsync_assert_same('completed', $persisted->s3SyncStatus);
});

smartsync_test('progress reload and compare-and-swap clear stale option cache entries', function () {
    $worker = new SMARL_S3SyncSettings(); $worker->runId = 'run-cache'; $worker->s3SyncDirection = 'upload'; $worker->s3SyncStatus = 'processing'; $worker->save();
    wp_cache_set('smarl_sync_settings', ['runId' => 'run-obsolete'], 'options');
    $worker->load(); smartsync_assert_same(false, wp_cache_get('smarl_sync_settings', 'options'));
    wp_cache_set('smarl_sync_settings', ['runId' => 'run-obsolete'], 'options');
    $worker->s3SyncProcessedFiles = 1; smartsync_assert_same(true, $worker->saveForRun('run-cache'));
    smartsync_assert_same(false, wp_cache_get('smarl_sync_settings', 'options'));
});

smartsync_test('sync startup reloads a newer persisted provider configuration before granting eligibility', function () {
    [$storage, $handler] = smartsync_sync_fixture(); $_POST = ['type' => 'media'];
    smartsync_assert_same(true, smartsync_capture_json(fn() => $handler->syncLocalToAws())['success']);
    $oldFingerprint = smartsync_progress()->cloudFingerprint;
    $new = SMARL_CloudConfig::fromArray($storage->toArray()); $new->bucket = 'replacement-bucket'; $new->canEnableMediaCDN = false; $new->save();
    smartsync_assert_same(true, smartsync_capture_json(fn() => $handler->syncLocalToAws())['success']);
    $saved = SMARL_CloudConfig::load();
    smartsync_assert_same('replacement-bucket', $saved->bucket); smartsync_assert_same(true, $saved->canEnableMediaCDN);
    smartsync_assert_true($oldFingerprint !== smartsync_progress()->cloudFingerprint);
    smartsync_assert_same([], $storage->uploads); smartsync_assert_same([], $GLOBALS['smartsync_test_http_requests']);
});

smartsync_test('a failed resync revokes existing media CDN eligibility while preserving unrelated settings', function () {
    [$storage] = smartsync_sync_fixture(); $storage->canEnableMediaCDN = true; $storage->useCdnForMedia = true; $storage->canEnableAssetCDN = true; $storage->useCdnForAssets = true; $storage->save();
    $handler = new SMARL_AjaxHandler($storage);
    file_put_contents(wp_upload_dir()['basedir'] . '/failed-resync.jpg', 'fixture'); $storage->failKey = 'wp-content/uploads/failed-resync.jpg';
    $_POST = ['type' => 'media']; smartsync_capture_json(fn() => $handler->syncLocalToAws()); smartsync_run_jobs($handler);
    $saved = SMARL_CloudConfig::load();
    smartsync_assert_same('failed', smartsync_progress()->s3SyncStatus); smartsync_assert_same(false, $saved->canEnableMediaCDN); smartsync_assert_same(false, $saved->useCdnForMedia);
    smartsync_assert_same(true, $saved->canEnableAssetCDN); smartsync_assert_same(true, $saved->useCdnForAssets);
});
