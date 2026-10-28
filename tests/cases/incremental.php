<?php
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Models\SMARL_UploadHistory;
use SmartSyncS3\Models\SMARL_CloudConfig;

function smartsync_incremental_start($handler, string $type = 'media', string $mode = 'incremental'): array {
    $_POST = ['type' => $type, 'mode' => $mode];
    return smartsync_capture_json(fn() => $handler->syncLocalToAws());
}

smartsync_test('1784 successful uploads plus 10 new files produce exactly 10 uploads and 1784 skipped', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    $root = wp_upload_dir()['basedir'];
    for ($i = 0; $i < 1784; $i++) file_put_contents($root . '/baseline-' . $i . '.jpg', 'bytes-' . $i);
    smartsync_incremental_start($handler);
    for ($i = 0; $i < 150 && $GLOBALS['smartsync_test_jobs']; $i++) {
        $job = array_shift($GLOBALS['smartsync_test_jobs']); $handler->processS3Batch(...array_values($job['args']));
    }
    smartsync_assert_same([], $GLOBALS['smartsync_test_jobs']); smartsync_assert_same(1784, count($storage->uploads));
    for ($i = 0; $i < 10; $i++) file_put_contents($root . '/added-' . $i . '.jpg', 'new-' . $i);
    $next = smartsync_incremental_start($handler);
    smartsync_assert_same(10, $next['data']['total_files']); smartsync_assert_same(1784, $next['data']['skipped_files']);
    smartsync_run_jobs($handler); smartsync_assert_same(1794, count($storage->uploads));
});

foreach (['aws', 'digitalocean', 'wasabi', 'cloudflare', 'google', 'bunny'] as $provider) {
    smartsync_test('incremental skips unchanged bytes and queues only new/modified files: ' . $provider, function () use ($provider) {
        [$storage, $handler] = smartsync_sync_fixture($provider);
        $root = wp_upload_dir()['basedir'];
        for ($i = 0; $i < 16; $i++) file_put_contents($root . '/incremental-' . $i . '.jpg', 'old-bytes');
        $first = smartsync_incremental_start($handler);
        smartsync_assert_same(16, $first['data']['total_files']);
        smartsync_assert_same(0, $first['data']['skipped_files']);
        smartsync_run_jobs($handler);
        $noop = smartsync_incremental_start($handler);
        smartsync_assert_same(0, $noop['data']['total_files']);
        smartsync_assert_same(16, $noop['data']['skipped_files']);
        smartsync_assert_same('completed', smartsync_progress()->s3SyncStatus);
        smartsync_assert_same([], $GLOBALS['smartsync_test_jobs']);
        // Same size and restored modification time still require a content comparison.
        $file = $root . '/incremental-0.jpg';
        $mtime = filemtime($file);
        file_put_contents($file, 'new-bytes'); touch($file, $mtime);
        file_put_contents($root . '/new.jpg', 'new');
        $changed = smartsync_incremental_start($handler);
        smartsync_assert_same(2, $changed['data']['total_files']);
        smartsync_assert_same(15, $changed['data']['skipped_files']);
        smartsync_assert_same(17, $changed['data']['scanned_files']);
        smartsync_run_jobs($handler);
        smartsync_assert_same(18, count($storage->uploads));
        smartsync_assert_same('new-bytes', $storage->objects['wp-content/uploads/incremental-0.jpg']);
        $status = smartsync_capture_json(fn() => $handler->checkSyncStatus());
        smartsync_assert_same(15, $status['data']['skipped_files']);
        smartsync_assert_true(SMARL_CloudConfig::load()->canEnableMediaCDN);
    });
}

smartsync_test('incremental retries only failures after partial success and never trusts a cloud object size', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    foreach (['good', 'bad'] as $name) file_put_contents(wp_upload_dir()['basedir'] . '/' . $name . '.jpg', $name);
    // Existing remote bytes are deliberately different: an untracked equal-size object is not trusted.
    $storage->objects['wp-content/uploads/good.jpg'] = 'evil';
    $storage->failKey = 'wp-content/uploads/bad.jpg';
    smartsync_incremental_start($handler); smartsync_run_jobs($handler);
    smartsync_assert_same('failed', smartsync_progress()->s3SyncStatus);
    $storage->failKey = '';
    $retry = smartsync_incremental_start($handler);
    smartsync_assert_same(1, $retry['data']['total_files']); smartsync_assert_same(1, $retry['data']['skipped_files']);
    smartsync_run_jobs($handler);
    smartsync_assert_same('good', $storage->objects['wp-content/uploads/good.jpg']);
    smartsync_assert_same('completed', smartsync_progress()->s3SyncStatus);
});

smartsync_test('full resync repairs missing objects and a failed replacement invalidates an old receipt', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    $key = 'wp-content/uploads/repair.jpg'; file_put_contents(ABSPATH . $key, 'bytes');
    smartsync_incremental_start($handler); smartsync_run_jobs($handler);
    unset($storage->objects[$key]);
    smartsync_assert_same(1, smartsync_incremental_start($handler)['data']['total_files']);
    smartsync_run_jobs($handler);
    smartsync_assert_same('bytes', $storage->objects[$key]);
    unset($storage->objects[$key]);
    $full = smartsync_incremental_start($handler, 'media', 'full');
    smartsync_assert_same(1, $full['data']['total_files']); smartsync_assert_same(0, $full['data']['skipped_files']);
    smartsync_run_jobs($handler); smartsync_assert_same('bytes', $storage->objects[$key]);
    $storage->failKey = $key;
    smartsync_incremental_start($handler, 'media', 'full'); smartsync_run_jobs($handler);
    $storage->failKey = '';
    smartsync_assert_same(1, smartsync_incremental_start($handler)['data']['total_files']);
    smartsync_run_jobs($handler);
    smartsync_assert_same(0, smartsync_incremental_start($handler)['data']['total_files']);
});

smartsync_test('stopping an in-flight full replacement and changing bytes during upload do not create receipts', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    $key = 'wp-content/uploads/interrupted.jpg'; file_put_contents(ABSPATH . $key, 'old');
    smartsync_incremental_start($handler); smartsync_run_jobs($handler);
    $storage->duringTransfer = fn() => smartsync_capture_json(fn() => $handler->stopAllProcesses());
    smartsync_incremental_start($handler, 'media', 'full'); smartsync_run_jobs($handler);
    $storage->duringTransfer = fn() => file_put_contents(ABSPATH . $key, 'new');
    smartsync_assert_same(1, smartsync_incremental_start($handler)['data']['total_files']);
    smartsync_run_jobs($handler); smartsync_assert_same('failed', smartsync_progress()->s3SyncStatus);
    smartsync_assert_true(str_contains(implode(' ', smartsync_progress()->s3SyncErrors), 'changed or became unreadable'));
    $storage->duringTransfer = null;
    smartsync_assert_same(1, smartsync_incremental_start($handler)['data']['total_files']);
    smartsync_run_jobs($handler); smartsync_assert_same('new', $storage->objects[$key]);
});

smartsync_test('history isolates stable storage addresses and survives credential/CDN changes', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    $key = 'wp-content/uploads/target.jpg'; file_put_contents(ABSPATH . $key, 'bytes');
    smartsync_incremental_start($handler); smartsync_run_jobs($handler);
    $storage->iamSecret = 'rotated-secret'; $storage->url = 'new-cdn.example.test'; $storage->save();
    smartsync_assert_same(0, smartsync_incremental_start($handler)['data']['total_files']);
    $storage->bucket = 'different-bucket'; $storage->save();
    smartsync_assert_same(1, smartsync_incremental_start($handler)['data']['total_files']); smartsync_run_jobs($handler);
    $storage->serviceProvider = 'wasabi'; $storage->save();
    smartsync_assert_same(1, smartsync_incremental_start($handler)['data']['total_files']); smartsync_run_jobs($handler);
    $storage->serviceProvider = 'aws'; $storage->bucket = 'sync-fixture'; $storage->save();
    smartsync_assert_same(0, smartsync_incremental_start($handler)['data']['total_files']);
    $storage->serviceProvider = 'cloudflare'; $storage->cloudflareEndpoint = 'https://' . str_repeat('a', 32) . '.r2.cloudflarestorage.com';
    SMARL_UploadHistory::record($storage, $key, SMARL_UploadHistory::signature(ABSPATH . $key));
    $storage->cloudflareEndpoint = 'https://' . str_repeat('b', 32) . '.r2.cloudflarestorage.com';
    smartsync_assert_same(1, count(SMARL_UploadHistory::plan([['path' => ABSPATH . $key, 'relative_path' => $key]], $storage, false)['files']));
    smartsync_assert_true(!str_contains(serialize($GLOBALS['wpdb']->get_results('SELECT * FROM wp_smarl_upload_history', ARRAY_A)), 'rotated-secret'));
});

smartsync_test('automatic media uploads share receipts with manual sync and intentional cloud deletion invalidates them', function () {
    $storage = new SmartSyncCoreStorage(); $storage->mediaAutoDelete = false;
    $core = smartsync_core_instance($storage);
    [$metadata, $paths] = smartsync_core_attachment(871);
    $core->completeAttachmentUpload($metadata, 871);
    $files = array_map(static fn(string $path): array => ['path' => $path, 'relative_path' => SMARL_Helper::relativeKeyForPath($path)], $paths);
    smartsync_assert_same(2, SMARL_UploadHistory::plan($files, $storage, false)['skipped']);
    $core->deleteAttachmentObjects(871);
    smartsync_assert_same(0, SMARL_UploadHistory::plan($files, $storage, false)['skipped']);
});

smartsync_test('assets use the same content history and invalid sync modes fail before state mutation', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    file_put_contents(ABSPATH . 'theme.css', 'body{}');
    smartsync_incremental_start($handler, 'assets'); smartsync_run_jobs($handler);
    smartsync_assert_same(1, smartsync_incremental_start($handler, 'assets')['data']['skipped_files']);
    $before = get_option('smarl_sync_settings');
    foreach (['verify', '', ['full']] as $mode) {
        $_POST = ['type' => 'assets', 'mode' => $mode];
        smartsync_assert_same(false, smartsync_capture_json(fn() => $handler->syncLocalToAws())['success']);
        smartsync_assert_same($before, get_option('smarl_sync_settings'));
    }
    $GLOBALS['wpdb']->query('DROP TABLE wp_smarl_upload_history');
    smartsync_assert_throws(fn() => SMARL_UploadHistory::plan([['path' => ABSPATH . 'theme.css', 'relative_path' => 'theme.css']], $storage, false));
});

smartsync_test('verified skipped receipts bind attachment ownership and unverified keys retain their old target', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    $key = 'wp-content/uploads/owned.jpg'; file_put_contents(ABSPATH . $key, 'bytes');
    smartsync_incremental_start($handler); smartsync_run_jobs($handler);
    foreach ([801 => [$key], 802 => [$key, 'wp-content/uploads/missing.jpg']] as $id => $keys) {
        update_post_meta($id, '_smarl_offloaded_files', $keys); update_post_meta($id, '_smarl_offload_target', 'old-target');
    }
    SMARL_Helper::bindCompletedOffloadedFiles($storage, 'different-run');
    smartsync_assert_same(SMARL_Helper::storageTargetFingerprint($storage), get_post_meta(801, '_smarl_offload_target', true));
    smartsync_assert_same('old-target', get_post_meta(802, '_smarl_offload_target', true));
});

smartsync_test('history write failures cannot report success or suppress the next retry', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    file_put_contents(wp_upload_dir()['basedir'] . '/db-failure.jpg', 'bytes');
    $GLOBALS['wpdb']->pdo->exec("CREATE TRIGGER reject_history BEFORE INSERT ON wp_smarl_upload_history BEGIN SELECT RAISE(FAIL, 'fixture history write rejected'); END");
    smartsync_incremental_start($handler); smartsync_run_jobs($handler);
    smartsync_assert_same('failed', smartsync_progress()->s3SyncStatus);
    smartsync_assert_true(str_contains(implode(' ', smartsync_progress()->s3SyncErrors), 'upload history'));
    smartsync_assert_same(false, SMARL_CloudConfig::load()->canEnableMediaCDN);
    $GLOBALS['wpdb']->pdo->exec('DROP TRIGGER reject_history');
    smartsync_assert_same(1, smartsync_incremental_start($handler)['data']['total_files']); smartsync_run_jobs($handler);
    smartsync_assert_same('completed', smartsync_progress()->s3SyncStatus);
});

smartsync_test('upload history uses the current blog table even when two blogs share a storage address', function () {
    [$storage] = smartsync_sync_fixture();
    $key = 'wp-content/uploads/same.jpg'; file_put_contents(ABSPATH . $key, 'bytes');
    $files = [['path' => ABSPATH . $key, 'relative_path' => $key]];
    SMARL_UploadHistory::record($storage, $key, SMARL_UploadHistory::signature(ABSPATH . $key));
    smartsync_assert_same(1, SMARL_UploadHistory::plan($files, $storage, false)['skipped']);
    $GLOBALS['wpdb']->pdo->exec('CREATE TABLE wp_2_smarl_upload_history AS SELECT * FROM wp_smarl_upload_history WHERE 0');
    $GLOBALS['wpdb']->prefix = 'wp_2_';
    smartsync_assert_same(0, SMARL_UploadHistory::plan($files, $storage, false)['skipped']);
    $GLOBALS['wpdb']->prefix = 'wp_';
    smartsync_assert_same(1, SMARL_UploadHistory::plan($files, $storage, false)['skipped']);
});
