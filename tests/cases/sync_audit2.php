<?php
use SmartSyncS3\Helpers\SMARL_Helper;

smartsync_test('coordination acquisition bypasses stale request-local lock cache after another request releases it', function () {
    $GLOBALS['smartsync_test_cache_reads']['smarl_sync_lock'] = true;
    wp_cache_set('smarl_sync_lock', ['token' => 'already-released', 'expires' => time() + 900], 'options');
    $token = SMARL_Helper::acquireSyncLock();
    smartsync_assert_true(is_string($token), 'A stale local option cache must not invent a durable lock');
    SMARL_Helper::releaseSyncLock($token);
    smartsync_assert_same(false, get_option('smarl_sync_lock'));
});

smartsync_test('coordination release uses its durable owner even when the local cache holds a different lease', function () {
    $token = SMARL_Helper::acquireSyncLock();
    $GLOBALS['smartsync_test_cache_reads']['smarl_sync_lock'] = true;
    wp_cache_set('smarl_sync_lock', ['token' => 'previous-owner', 'expires' => time() + 900], 'options');
    SMARL_Helper::releaseSyncLock($token);
    smartsync_assert_same(false, get_option('smarl_sync_lock'));
});

smartsync_test('coordination recovery compares exact corrupt serialized data instead of reserializing it', function () {
    $GLOBALS['wpdb']->insert('wp_options', ['option_name' => 'smarl_sync_lock', 'option_value' => 'b:0;', 'autoload' => 'no']);
    smartsync_assert_true(is_string(SMARL_Helper::acquireSyncLock()), 'Serialized false must be reclaimable');
});

smartsync_test('coordination release tolerates malformed stored owner tokens without warnings', function () {
    update_option('smarl_sync_lock', ['token' => [], 'expires' => time() + 900], false);
    SMARL_Helper::releaseSyncLock('unrelated-owner');
    smartsync_assert_same([], get_option('smarl_sync_lock')['token']);
    smartsync_assert_true(is_string(SMARL_Helper::acquireSyncLock()));
});

smartsync_test('coordination rejects nonpositive or overflowing lease durations', function () {
    foreach ([0, -1, PHP_INT_MAX] as $ttl) smartsync_assert_throws(fn() => SMARL_Helper::acquireSyncLock($ttl), InvalidArgumentException::class);
    smartsync_assert_same(false, get_option('smarl_sync_lock'));
});

smartsync_test('stable storage ownership changes with targets but not credentials or delivery settings', function () {
    $config = smartsync_provider_config('aws'); $original = SMARL_Helper::storageTargetFingerprint($config);
    $config->iamKey = 'rotated-fixture-key'; $config->iamSecret = 'rotated-fixture-secret'; $config->url = 'different-public.example.test';
    $config->useCdnForMedia = !$config->useCdnForMedia; $config->mediaAutoDelete = !$config->mediaAutoDelete;
    smartsync_assert_same($original, SMARL_Helper::storageTargetFingerprint($config));
    $config->bucket = 'other-bucket'; smartsync_assert_true($original !== SMARL_Helper::storageTargetFingerprint($config));
    $bunny = smartsync_provider_config('bunny'); $bunny->region = 'US'; $ny = SMARL_Helper::storageTargetFingerprint($bunny);
    $bunny->region = 'NY'; smartsync_assert_same($ny, SMARL_Helper::storageTargetFingerprint($bunny));
    $r2 = smartsync_provider_config('cloudflare'); $r2Target = SMARL_Helper::storageTargetFingerprint($r2);
    $r2->region = 'auto'; smartsync_assert_same($r2Target, SMARL_Helper::storageTargetFingerprint($r2));
    $r2->cloudflareEndpoint = 'https://' . str_repeat('b', 32) . '.r2.cloudflarestorage.com';
    smartsync_assert_true($r2Target !== SMARL_Helper::storageTargetFingerprint($r2));
});

smartsync_test('legacy ownership binding records the old target without replacing known provenance', function () {
    $config = smartsync_provider_config();
    update_post_meta(101, '_smarl_offloaded_files', ['wp-content/uploads/old.jpg']);
    update_post_meta(102, '_smarl_offloaded_files', ['wp-content/uploads/older.jpg']);
    update_post_meta(102, '_smarl_offload_target', 'known-older-target');
    update_post_meta(103, '_smarl_offloaded_files', []);
    SMARL_Helper::bindLegacyOffloadedFiles($config);
    smartsync_assert_same(SMARL_Helper::storageTargetFingerprint($config), get_post_meta(101, '_smarl_offload_target', true));
    smartsync_assert_same('known-older-target', get_post_meta(102, '_smarl_offload_target', true));
    smartsync_assert_same('', get_post_meta(103, '_smarl_offload_target', true));
    SMARL_Helper::bindLegacyOffloadedFiles($config);
    smartsync_assert_same('known-older-target', get_post_meta(102, '_smarl_offload_target', true));
});

smartsync_test('failed provenance persistence is detected before migration may continue', function () {
    update_post_meta(104, '_smarl_offloaded_files', ['wp-content/uploads/old.jpg']);
    $GLOBALS['smartsync_test_meta_write_failures'][104]['_smarl_offload_target'] = true;
    smartsync_assert_throws(fn() => SMARL_Helper::bindLegacyOffloadedFiles(smartsync_provider_config()), RuntimeException::class);
    smartsync_assert_same('', get_post_meta(104, '_smarl_offload_target', true));
});

smartsync_test('bulk ownership transfers only after every exact manifest key succeeds in the current run', function () {
    $config = smartsync_provider_config();
    foreach ([201 => ['a.jpg', 'b.jpg'], 202 => ['a.jpg', 'missing.jpg'], 203 => ['Photo.JPG'], 204 => ['a.jpg']] as $id => $keys) {
        update_post_meta($id, '_smarl_offloaded_files', $keys); update_post_meta($id, '_smarl_offload_target', 'old-target');
    }
    foreach ([['a.jpg', 'new', 'completed'], ['b.jpg', 'new', 'completed'], ['photo.jpg', 'new', 'completed'], ['missing.jpg', 'other-run', 'completed']] as [$key, $run, $status]) {
        $GLOBALS['wpdb']->insert('wp_smarl_file_sync_queue', ['path' => ABSPATH . $key, 'relative_path' => $key, 'run_id' => $run, 'status' => $status]);
    }
    SMARL_Helper::bindCompletedOffloadedFiles($config, 'new');
    $target = SMARL_Helper::storageTargetFingerprint($config);
    smartsync_assert_same($target, get_post_meta(201, '_smarl_offload_target', true));
    smartsync_assert_same($target, get_post_meta(204, '_smarl_offload_target', true));
    smartsync_assert_same('old-target', get_post_meta(202, '_smarl_offload_target', true));
    smartsync_assert_same('old-target', get_post_meta(203, '_smarl_offload_target', true));
});

smartsync_test('legacy S3 listing rejects cyclic continuation tokens before repeating a page', function () {
    $calls = 0;
    $client = smartsync_provider_s3(function () use (&$calls) {
        if (++$calls > 3) throw new RuntimeException('Mock guard stopped an unbounded listing');
        return ['Contents' => [], 'IsTruncated' => true, 'NextContinuationToken' => $calls === 2 ? 'B' : 'A'];
    });
    smartsync_assert_throws(fn() => SMARL_Helper::getS3Files($client, 'fixture-bucket'), RuntimeException::class);
    smartsync_assert_same(3, $calls, 'A repeated token must be rejected before another provider request');
});

smartsync_test('legacy progress recovery cannot overwrite a newer run created after the read', function () {
    update_option('smarl_sync_settings', ['s3SyncStatus' => 'processing', 'runId' => '', 'syncState' => 'syncing']);
    $GLOBALS['smartsync_test_sql_read_hook'] = static function ($sql) {
        if (!str_contains($sql, "option_name = 'smarl_sync_settings'")) return;
        $GLOBALS['smartsync_test_sql_read_hook'] = null;
        $replacement = new \SmartSyncS3\Models\SMARL_S3SyncSettings();
        $replacement->runId = 'new-valid-run'; $replacement->s3SyncStatus = 'processing'; $replacement->syncState = 'syncing';
        $replacement->s3SyncDirection = 'upload'; $replacement->extensions = ['jpg']; $replacement->cloudFingerprint = 'new-target'; $replacement->save();
    };
    $reader = new \SmartSyncS3\Models\SMARL_S3SyncSettings(); $reader->load();
    smartsync_assert_same('new-valid-run', get_option('smarl_sync_settings')['runId']);
    smartsync_assert_same('processing', get_option('smarl_sync_settings')['s3SyncStatus']);
    smartsync_assert_same('new-valid-run', $reader->runId);
});

smartsync_test('invalid persisted processing direction becomes restartable instead of rescheduling an inert callback forever', function () {
    update_option('smarl_sync_settings', ['s3SyncStatus' => 'processing', 'runId' => 'invalid-direction', 's3SyncDirection' => 'invalid', 'syncState' => 'syncing']);
    $settings = new \SmartSyncS3\Models\SMARL_S3SyncSettings(); $settings->load();
    smartsync_assert_same('failed', $settings->s3SyncStatus);
    smartsync_assert_same('default', $settings->syncState);
    smartsync_assert_true((bool) $settings->s3SyncErrors);
});

smartsync_test('legacy progress log deserialization preserves bounded recent diagnostics', function () {
    $settings = \SmartSyncS3\Models\SMARL_S3SyncSettings::fromArray(['s3SyncErrors' => array_map(fn($i) => 'error-' . $i, range(1, 1000)),
        's3SyncSuccesses' => array_map(fn($i) => 'success-' . $i, range(1, 1000))]);
    smartsync_assert_same(200, count($settings->s3SyncErrors));
    smartsync_assert_same('error-1000', $settings->s3SyncErrors[199]);
    smartsync_assert_same(500, count($settings->s3SyncSuccesses));
    smartsync_assert_same('success-1000', $settings->s3SyncSuccesses[499]);
});

smartsync_test('stop cannot report success when durable cancellation fails and still cancels pending jobs', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    $settings = new \SmartSyncS3\Models\SMARL_S3SyncSettings(); $settings->runId = 'running'; $settings->s3SyncDirection = 'upload';
    $settings->s3SyncStatus = 'processing'; $settings->syncState = 'syncing'; $settings->save();
    as_schedule_single_action(time(), 'process_s3_sync_batch', [], 'smartsync');
    $GLOBALS['wpdb']->pdo->exec("CREATE TRIGGER block_progress_write BEFORE UPDATE OF option_value ON wp_options WHEN OLD.option_name = 'smarl_sync_settings' BEGIN SELECT RAISE(FAIL, 'fixture progress write failure'); END");
    $response = smartsync_capture_json(fn() => $handler->stopAllProcesses());
    smartsync_assert_same(false, $response['success']);
    smartsync_assert_same('processing', get_option('smarl_sync_settings')['s3SyncStatus']);
    smartsync_assert_same([], $GLOBALS['smartsync_test_jobs']);
});

smartsync_test('progress refresh bypasses stale legacy alloptions cache after a durable stop', function () {
    $old = new \SmartSyncS3\Models\SMARL_S3SyncSettings(); $old->runId = 'obsolete'; $old->s3SyncStatus = 'processing';
    $old->syncState = 'syncing'; $old->s3SyncDirection = 'upload';
    (new \SmartSyncS3\Models\SMARL_S3SyncSettings())->save();
    $GLOBALS['smartsync_test_cache_reads']['alloptions'] = true;
    wp_cache_set('alloptions', ['smarl_sync_settings' => maybe_serialize($old->toArray())], 'options');
    $reader = new \SmartSyncS3\Models\SMARL_S3SyncSettings(); $reader->load();
    smartsync_assert_same('idle', $reader->s3SyncStatus);
    smartsync_assert_same('', $reader->runId);
});

smartsync_test('a no-op conditional save cannot claim success after a concurrent stop changes durable state', function () {
    $worker = new \SmartSyncS3\Models\SMARL_S3SyncSettings(); $worker->runId = 'stopping';
    $worker->s3SyncStatus = 'processing'; $worker->s3SyncDirection = 'upload'; $worker->save();
    $idle = $GLOBALS['wpdb']->pdo->quote(maybe_serialize((new \SmartSyncS3\Models\SMARL_S3SyncSettings())->toArray()));
    $GLOBALS['wpdb']->pdo->exec("CREATE TRIGGER concurrent_stop BEFORE UPDATE OF option_value ON wp_options WHEN OLD.option_name = 'smarl_sync_settings' BEGIN UPDATE wp_options SET option_value = $idle WHERE option_name = 'smarl_sync_settings'; SELECT RAISE(IGNORE); END");
    smartsync_assert_same(false, $worker->saveForRun('stopping'));
    smartsync_assert_same('idle', get_option('smarl_sync_settings')['s3SyncStatus']);
});
