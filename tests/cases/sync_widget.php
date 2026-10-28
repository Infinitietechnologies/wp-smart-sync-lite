<?php
smartsync_test('summary status polls omit intermediate diagnostics but retain final and legacy details', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    $progress = new \SmartSyncS3\Models\SMARL_S3SyncSettings();
    $progress->runId = 'summary-run'; $progress->s3SyncStatus = 'processing'; $progress->s3SyncDirection = 'upload';
    $progress->s3SyncSuccesses = ['wp-content/uploads/complete.jpg']; $progress->s3SyncErrors = ['Safe failed-file reason']; $progress->save();
    $_POST = ['summary' => '1'];
    $data = smartsync_capture_json(fn() => $handler->checkSyncStatus())['data'];
    smartsync_assert_same([], $data['successes']); smartsync_assert_same([], $data['errors']);
    $_POST = [];
    $data = smartsync_capture_json(fn() => $handler->checkSyncStatus())['data'];
    smartsync_assert_same(['wp-content/uploads/complete.jpg'], $data['successes']);
    $progress->s3SyncStatus = 'failed'; $progress->save(); $_POST = ['summary' => '1'];
    $data = smartsync_capture_json(fn() => $handler->checkSyncStatus())['data'];
    smartsync_assert_same(['Safe failed-file reason'], $data['errors']);
    smartsync_assert_same(['wp-content/uploads/complete.jpg'], $data['successes']);
});
use SmartSyncS3\Models\SMARL_S3SyncSettings;
use SmartSyncS3\Ajax\SMARL_AjaxHandler;

smartsync_test('widget timestamps and target context survive progress serialization without credentials', function () {
    [$storage, $handler] = smartsync_sync_fixture('bunny');
    $storage->objects = ['wp-content/uploads/test.jpg' => 'bytes'];
    $_POST = ['type' => 'media'];
    smartsync_capture_json(fn() => $handler->syncAwsToLocal());
    $started = smartsync_progress();
    smartsync_assert_true($started->startedAt > 0);
    smartsync_assert_same('bunny', $started->syncProvider);
    smartsync_assert_same('sync-fixture', $started->syncStorage);
    smartsync_run_jobs($handler);
    $finished = smartsync_progress();
    smartsync_assert_true($finished->finishedAt >= $started->startedAt);
    smartsync_assert_same($started->startedAt, $finished->startedAt);
    $data = smartsync_capture_json(fn() => $handler->checkSyncStatus())['data'];
    smartsync_assert_same($started->runId, $data['run_id']);
    smartsync_assert_same(0, $data['failed_files']);
    smartsync_assert_same('bunny', $data['provider']);
    smartsync_assert_true(!str_contains(json_encode($data), 'fixture-secret') && !str_contains(json_encode($data), 'fixture-key'));
});

smartsync_test('widget status exposes only the active relative file and exact failed queue count', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    $progress = new SMARL_S3SyncSettings(); $progress->runId = 'widget-run'; $progress->s3SyncStatus = 'processing'; $progress->s3SyncDirection = 'upload'; $progress->s3SyncTotalFiles = 2; $progress->save();
    $GLOBALS['wpdb']->insert('wp_smarl_file_sync_queue', ['path' => ABSPATH . 'private-absolute.jpg', 'relative_path' => 'wp-content/uploads/active.jpg', 'run_id' => 'widget-run', 'status' => 'in_progress']);
    $GLOBALS['wpdb']->insert('wp_smarl_file_sync_queue', ['path' => 'unused', 'relative_path' => 'failed.jpg', 'run_id' => 'widget-run', 'status' => 'failed']);
    $data = smartsync_capture_json(fn() => $handler->checkSyncStatus())['data'];
    smartsync_assert_same('wp-content/uploads/active.jpg', $data['current_file']);
    smartsync_assert_same(1, $data['failed_files']);
    smartsync_assert_same(0, $data['started_at']);
    smartsync_assert_true(!str_contains(json_encode($data), 'private-absolute'));
});

smartsync_test('legacy widget timing and terminal context stay explicitly unknown instead of invented', function () {
    $model = SMARL_S3SyncSettings::fromArray(['s3SyncStatus' => 'failed', 'runId' => 'legacy']);
    $data = $model->statusData(smartsync_provider_config('bunny'));
    smartsync_assert_same(0, $data['started_at']); smartsync_assert_same('', $data['provider']); smartsync_assert_same('', $data['storage']);
    smartsync_assert_same(null, $data['failed_files']);
});
