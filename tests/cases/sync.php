<?php
use SmartSyncS3\Ajax\SMARL_AjaxHandler;
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Models\SMARL_CloudConfig;
use SmartSyncS3\Models\SMARL_S3SyncSettings;

class SmartSyncSyncStorage extends SMARL_CloudConfig {
    public array $objects = [];
    public array $uploads = [];
    public array $downloads = [];
    public string $failKey = '';
    public bool $truncate = false;
    public mixed $duringTransfer = null;
    public function __construct(string $provider = 'aws') {
        $this->serviceProvider = $provider; $this->bucket = 'sync-fixture'; $this->region = 'us-east-1';
        $this->iamKey = 'fixture-key'; $this->iamSecret = 'fixture-secret'; $this->url = 'cdn.example.test';
    }
    public function isConfigured(): bool { return true; }
    public function listObjects(): array { $rows = []; foreach ($this->objects as $key => $bytes) { $rows[] = ['key' => $key, 'size' => strlen($bytes)]; } return $rows; }
    public function uploadObject(string $key, string $path): void {
        if ($key === $this->failKey) { throw new RuntimeException('Provider rejected fixture-secret'); }
        $this->uploads[] = $key; $this->objects[$key] = file_get_contents($path);
        if ($this->duringTransfer) { ($this->duringTransfer)(); }
    }
    public function downloadObject(string $key, string $destination): void {
        if ($key === $this->failKey) { throw new RuntimeException('Provider rejected fixture-secret'); }
        $this->downloads[] = $key; file_put_contents($destination, $this->truncate ? 'truncated' : $this->objects[$key]);
        if ($this->duringTransfer) { ($this->duringTransfer)(); }
    }
}
function smartsync_sync_fixture(string $provider = 'aws'): array {
    $storage = new SmartSyncSyncStorage($provider); $storage->save();
    return [$storage, new SMARL_AjaxHandler($storage)];
}
function smartsync_run_jobs(SMARL_AjaxHandler $handler): void {
    $iterations = 0;
    while ($GLOBALS['smartsync_test_jobs']) {
        if (++$iterations > 100) { throw new RuntimeException('Background jobs failed to terminate'); }
        $job = array_shift($GLOBALS['smartsync_test_jobs']);
        $handler->processS3Batch(...array_values($job['args']));
    }
}
function smartsync_progress(): SMARL_S3SyncSettings { $s = new SMARL_S3SyncSettings(); $s->load(); return $s; }

foreach ([0, 1, 10, 11, 15, 16, 31] as $count) {
    smartsync_test('restore preserves all remote keys and bytes across batch boundaries: ' . $count, function () use ($count) {
        [$storage, $handler] = smartsync_sync_fixture();
        for ($i = 0; $i < $count; $i++) { $storage->objects['wp-content/uploads/2020/01/restore-' . $i . '.jpg'] = 'remote-bytes-' . $i; }
        // An unrelated old upload row must never become download input.
        $GLOBALS['wpdb']->insert('wp_smarl_file_sync_queue', ['path' => 'old', 'relative_path' => 'old.jpg', 'status' => 'pending', 'run_id' => 'old']);
        $_POST = ['type' => 'media'];
        smartsync_assert_true(smartsync_capture_json(fn() => $handler->syncAwsToLocal())['success']);
        smartsync_run_jobs($handler);
        $s = smartsync_progress();
        smartsync_assert_same('completed', $s->s3SyncStatus);
        smartsync_assert_same($count, $s->s3SyncTotalFiles);
        smartsync_assert_same($count, $s->s3SyncProcessedFiles);
        smartsync_assert_same($count, count(array_unique($storage->downloads)));
        foreach ($storage->objects as $key => $bytes) { smartsync_assert_same($bytes, file_get_contents(ABSPATH . $key)); }
    });
}
foreach (['aws', 'cloudflare', 'digitalocean', 'wasabi', 'google', 'bunny'] as $provider) {
    smartsync_test('bulk upload processes every queued file through provider API: ' . $provider, function () use ($provider) {
        [$storage, $handler] = smartsync_sync_fixture($provider);
        for ($i = 0; $i < 16; $i++) { file_put_contents(wp_upload_dir()['basedir'] . '/upload-' . $i . '.JPG', 'bytes-' . $i); }
        $_POST = ['type' => 'media'];
        smartsync_assert_true(smartsync_capture_json(fn() => $handler->syncLocalToAws())['success']);
        $first = $GLOBALS['smartsync_test_jobs'][0];
        // Simulate a duplicate delivery of the first action, after it advances the cursor.
        $GLOBALS['smartsync_test_jobs'][] = $first;
        smartsync_run_jobs($handler);
        smartsync_assert_same('completed', smartsync_progress()->s3SyncStatus);
        smartsync_assert_same(16, count($storage->uploads));
        smartsync_assert_same(16, count(array_unique($storage->uploads)));
        smartsync_assert_true(SMARL_CloudConfig::load()->canEnableMediaCDN);
        smartsync_assert_same(0, SMARL_Helper::queueStatusCounts(smartsync_progress()->runId)['pending']);
    });
}
smartsync_test('partial upload failure does not grant CDN eligibility or log provider secrets', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    file_put_contents(wp_upload_dir()['basedir'] . '/good.jpg', 'good'); file_put_contents(wp_upload_dir()['basedir'] . '/bad.jpg', 'bad');
    $storage->failKey = 'wp-content/uploads/bad.jpg';
    $_POST = ['type' => 'media']; smartsync_capture_json(fn() => $handler->syncLocalToAws()); smartsync_run_jobs($handler);
    $s = smartsync_progress();
    smartsync_assert_same('failed', $s->s3SyncStatus); smartsync_assert_same(2, $s->s3SyncProcessedFiles);
    smartsync_assert_same(1, count($s->s3SyncErrors)); smartsync_assert_same(1, count($s->s3SyncSuccesses));
    smartsync_assert_true(!str_contains(implode(' ', $s->s3SyncErrors), 'fixture-secret'));
    smartsync_assert_same(false, SMARL_CloudConfig::load()->canEnableMediaCDN);
});
smartsync_test('failed or truncated restore retains the previous local file atomically', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    $key = 'wp-content/uploads/existing.jpg'; file_put_contents(ABSPATH . $key, 'existing-local');
    $storage->objects[$key] = 'complete remote object payload'; $storage->truncate = true;
    $_POST = ['type' => 'media']; smartsync_capture_json(fn() => $handler->syncAwsToLocal()); smartsync_run_jobs($handler);
    smartsync_assert_same('failed', smartsync_progress()->s3SyncStatus);
    smartsync_assert_same('existing-local', file_get_contents(ABSPATH . $key));
    smartsync_assert_same([], glob(dirname(ABSPATH . $key) . '/.smartsync-*'));
});
smartsync_test('malicious cloud keys fail before creating restore jobs or writing outside WordPress', function () {
    [$storage, $handler] = smartsync_sync_fixture(); $storage->objects['../outside.jpg'] = 'danger';
    $_POST = ['type' => 'media']; $response = smartsync_capture_json(fn() => $handler->syncAwsToLocal());
    smartsync_assert_same(false, $response['success']); smartsync_assert_same([], $GLOBALS['smartsync_test_jobs']); smartsync_assert_same([], $storage->downloads);
});
smartsync_test('stop invalidates old callbacks and a later run cannot be mutated by them', function () {
    [$storage, $handler] = smartsync_sync_fixture(); $storage->objects['wp-content/uploads/a.jpg'] = 'a';
    $_POST = ['type' => 'media']; smartsync_capture_json(fn() => $handler->syncAwsToLocal()); $old = $GLOBALS['smartsync_test_jobs'][0];
    smartsync_capture_json(fn() => $handler->stopAllProcesses()); smartsync_assert_same([], $GLOBALS['smartsync_test_jobs']);
    smartsync_capture_json(fn() => $handler->syncAwsToLocal()); $newRun = smartsync_progress()->runId;
    $handler->processS3Batch(...array_values($old['args']));
    smartsync_assert_same($newRun, smartsync_progress()->runId); smartsync_assert_same([], $storage->downloads);
    $handler->processS3Batch(1, [], [], 'download'); smartsync_assert_same([], $storage->downloads);
    smartsync_run_jobs($handler); smartsync_assert_same(['wp-content/uploads/a.jpg'], $storage->downloads);
});
smartsync_test('stop during a transfer cannot overwrite the reset progress or start another file', function () {
    [$storage, $handler] = smartsync_sync_fixture(); $storage->objects = ['wp-content/uploads/a.jpg' => 'a', 'wp-content/uploads/b.jpg' => 'b'];
    $storage->duringTransfer = function () use ($handler) { smartsync_capture_json(fn() => $handler->stopAllProcesses()); };
    $_POST = ['type' => 'media']; smartsync_capture_json(fn() => $handler->syncAwsToLocal()); smartsync_run_jobs($handler);
    smartsync_assert_same('idle', smartsync_progress()->s3SyncStatus); smartsync_assert_same(1, count($storage->downloads));
});
smartsync_test('all runtime AJAX actions enforce capability and nonce before any state change', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    foreach (['fetchAwsAnalytics', 'checkSyncStatus', 'getSyncState', 'setSyncState', 'syncLocalToAws', 'syncAwsToLocal', 'stopAllProcesses', 'toggleCdn', 'toggleMediaAutoDelete', 'clearCloudFrontCache'] as $method) {
        $GLOBALS['smartsync_test_can_manage'] = false;
        smartsync_assert_same(403, smartsync_capture_json(fn() => $handler->$method())['status'], $method);
        $GLOBALS['smartsync_test_can_manage'] = true; $GLOBALS['smartsync_test_valid_nonce'] = false;
        smartsync_assert_same(403, smartsync_capture_json(fn() => $handler->$method())['status'], $method);
        $GLOBALS['smartsync_test_valid_nonce'] = true;
    }
    smartsync_assert_same([], $storage->uploads); smartsync_assert_same([], $GLOBALS['smartsync_test_jobs']);
});
smartsync_test('invalid sync types and malformed booleans are rejected without scanning or changing settings', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    foreach (['', 'all', [], 'MEDIA'] as $type) { $_POST = ['type' => $type]; smartsync_assert_same(false, smartsync_capture_json(fn() => $handler->syncLocalToAws())['success']); }
    $_POST = ['state' => 'maybe']; smartsync_assert_same(false, smartsync_capture_json(fn() => $handler->toggleMediaAutoDelete())['success']);
    smartsync_assert_same([], $GLOBALS['smartsync_test_jobs']); smartsync_assert_same(false, SMARL_CloudConfig::load()->mediaAutoDelete);
});
smartsync_test('CDN can always be disabled even if eligibility has been revoked', function () {
    [$storage, $handler] = smartsync_sync_fixture(); $storage->canEnableMediaCDN = false; $storage->useCdnForMedia = true;
    $_POST = ['type' => 'media', 'useCdn' => 'false']; smartsync_assert_true(smartsync_capture_json(fn() => $handler->toggleCdn())['success']);
    smartsync_assert_same(false, SMARL_CloudConfig::load()->useCdnForMedia);
    $_POST['useCdn'] = 'true'; smartsync_assert_same(false, smartsync_capture_json(fn() => $handler->toggleCdn())['success']);
});
smartsync_test('sync lock has atomic acquisition, owner release, and stale recovery', function () {
    $token = SMARL_Helper::acquireSyncLock(); smartsync_assert_true(is_string($token));
    smartsync_assert_same(null, SMARL_Helper::acquireSyncLock());
    SMARL_Helper::releaseSyncLock('wrong-owner'); smartsync_assert_same(null, SMARL_Helper::acquireSyncLock());
    SMARL_Helper::releaseSyncLock($token); smartsync_assert_true(is_string(SMARL_Helper::acquireSyncLock()));
    update_option('smarl_sync_lock', ['token' => 'stale', 'expires' => time() - 1], false);
    smartsync_assert_true(is_string(SMARL_Helper::acquireSyncLock()));
});
smartsync_test('live lock rejects overlapping starts and a completed run releases its lock', function () {
    [$storage, $handler] = smartsync_sync_fixture(); $token = SMARL_Helper::acquireSyncLock();
    $_POST = ['type' => 'media']; smartsync_assert_same(409, smartsync_capture_json(fn() => $handler->syncLocalToAws())['status']);
    SMARL_Helper::releaseSyncLock($token); smartsync_assert_true(smartsync_capture_json(fn() => $handler->syncLocalToAws())['success']);
    smartsync_assert_same(false, get_option('smarl_sync_lock'));
});
smartsync_test('queue reads are run-scoped, ordered, and immediately reflect replacement and row state', function () {
    $files = [['path' => ABSPATH . 'a.jpg', 'relative_path' => 'a.jpg'], ['path' => ABSPATH . 'b.jpg', 'relative_path' => 'b.jpg']];
    SMARL_Helper::replaceQueue($files, 'first');
    $rows = SMARL_Helper::getFileSyncQueueData(1, 0, 'first'); smartsync_assert_same('a.jpg', $rows[0]['relative_path']);
    $GLOBALS['wpdb']->update('wp_smarl_file_sync_queue', ['status' => 'completed'], ['id' => $rows[0]['id']]);
    smartsync_assert_same('b.jpg', SMARL_Helper::getFileSyncQueueData(1, 0, 'first')[0]['relative_path']);
    SMARL_Helper::replaceQueue([['path' => ABSPATH . 'c.jpg', 'relative_path' => 'c.jpg']], 'second');
    smartsync_assert_same([], SMARL_Helper::getFileSyncQueueData(10, 0, 'first'));
    smartsync_assert_same('c.jpg', SMARL_Helper::getFileSyncQueueData(10, 0, 'second')[0]['relative_path']);
});
smartsync_test('local path containment rejects traversal, sibling roots, and Windows absolute paths', function () {
    foreach (['../outside.jpg', '/outside.jpg', 'C:/outside.jpg', 'a/../b.jpg', 'a//b.jpg', "a\0.jpg", 'a./b.jpg'] as $key) { smartsync_assert_throws(fn() => SMARL_Helper::resolveLocalPath($key)); }
    smartsync_assert_throws(fn() => SMARL_Helper::relativeKeyForPath(rtrim(ABSPATH, '/') . '-sibling/a.jpg'));
    smartsync_assert_same('wp-content/uploads/missing.jpg', SMARL_Helper::relativeKeyForPath(ABSPATH . 'wp-content/uploads/missing.jpg'));
});
smartsync_test('scan selects extensions case insensitively and excludes private/generated directories', function () {
    file_put_contents(ABSPATH . 'keep.JPG', 'image'); file_put_contents(ABSPATH . 'skip.php', 'php');
    wp_mkdir_p(ABSPATH . '.agents'); file_put_contents(ABSPATH . '.agents/hidden.jpg', 'hidden');
    wp_mkdir_p(ABSPATH . 'smartsync-private'); file_put_contents(ABSPATH . 'smartsync-private/private.jpg', 'private');
    smartsync_assert_same(['keep.JPG'], array_column(SMARL_Helper::scanLocalFiles(ABSPATH, ['jpg']), 'relative_path'));
    smartsync_assert_throws(fn() => SMARL_Helper::scanLocalFiles(ABSPATH, []));
});
smartsync_test('malformed sync settings cannot overwrite private option names or cause typed property errors', function () {
    $s = SMARL_S3SyncSettings::fromArray(['optionName' => 'other', 's3SyncErrors' => false, 's3SyncTotalFiles' => -20, 's3SyncStatus' => [], 'syncType' => 'all', 's3SyncAllFiles' => ['huge']]);
    smartsync_assert_same('idle', $s->s3SyncStatus); smartsync_assert_same(0, $s->s3SyncTotalFiles); smartsync_assert_same([], $s->s3SyncAllFiles);
    $s->save(); smartsync_assert_true(is_array(get_option('smarl_sync_settings'))); smartsync_assert_same(false, get_option('other'));
    update_option('smarl_sync_settings', 'broken'); $s->load(); smartsync_assert_same('idle', $s->s3SyncStatus);
});
smartsync_test('analytics counts provider objects without presenting another providers pricing as billing', function () {
    [$storage, $handler] = smartsync_sync_fixture('wasabi'); $storage->objects = ['a.jpg' => 'abc', 'b.jpg' => 'de'];
    $response = smartsync_capture_json(fn() => $handler->fetchAwsAnalytics());
    smartsync_assert_same(2, $response['data']['total_files']); smartsync_assert_same(5, $response['data']['total_size']); smartsync_assert_same(null, $response['data']['price_in_dollars']);
});
smartsync_test('CDN disable requires restoring files removed by local offload cleanup', function () {
    [$storage, $handler] = smartsync_sync_fixture(); $storage->useCdnForMedia = true; $storage->save();
    update_post_meta(1001, '_smarl_offloaded_files', ['wp-content/uploads/removed.jpg']);
    $_POST = ['type' => 'media', 'useCdn' => 'false'];
    smartsync_assert_same(false, smartsync_capture_json(fn() => $handler->toggleCdn())['success']);
    smartsync_assert_true(SMARL_CloudConfig::load()->useCdnForMedia);
    file_put_contents(wp_upload_dir()['basedir'] . '/removed.jpg', 'restored');
    smartsync_assert_true(smartsync_capture_json(fn() => $handler->toggleCdn())['success']);
    smartsync_assert_same(false, SMARL_CloudConfig::load()->useCdnForMedia);
});
smartsync_test('upload resync cannot disable the CDN while older offloaded local files are absent', function () {
    [$storage, $handler] = smartsync_sync_fixture(); $storage->useCdnForMedia = true; $storage->save();
    update_post_meta(1002, '_smarl_offloaded_files', ['wp-content/uploads/remote-only.jpg']);
    $_POST = ['type' => 'media'];
    $response = smartsync_capture_json(fn() => $handler->syncLocalToAws());
    smartsync_assert_same(false, $response['success']);
    smartsync_assert_true(str_contains($response['data'], 'Restore'));
    smartsync_assert_true(SMARL_CloudConfig::load()->useCdnForMedia);
    smartsync_assert_same([], $GLOBALS['smartsync_test_jobs']);
});
smartsync_test('corrupted sync lock values do not permanently block synchronization', function () {
    foreach (['corrupt', [], false] as $value) {
        update_option('smarl_sync_lock', $value, false);
        $token = SMARL_Helper::acquireSyncLock(); smartsync_assert_true(is_string($token));
        SMARL_Helper::releaseSyncLock($token);
    }
});
smartsync_test('interrupted worker rows recover and lost scheduled callbacks resume through status polling', function () {
    [$storage, $handler] = smartsync_sync_fixture(); $storage->objects['wp-content/uploads/resume.jpg'] = 'resumed';
    $_POST = ['type' => 'media']; smartsync_capture_json(fn() => $handler->syncAwsToLocal());
    $run = smartsync_progress()->runId;
    $GLOBALS['wpdb']->update('wp_smarl_file_sync_queue', ['status' => 'in_progress'], ['run_id' => $run]);
    $GLOBALS['smartsync_test_jobs'] = [];
    smartsync_assert_same('processing', smartsync_capture_json(fn() => $handler->checkSyncStatus())['data']['status']);
    smartsync_assert_same(1, count($GLOBALS['smartsync_test_jobs']));
    smartsync_run_jobs($handler);
    smartsync_assert_same('completed', smartsync_progress()->s3SyncStatus);
    smartsync_assert_same('resumed', file_get_contents(ABSPATH . 'wp-content/uploads/resume.jpg'));
});
smartsync_test('legacy processing options without a run token migrate to a restartable failure', function () {
    update_option('smarl_sync_settings', ['s3SyncStatus' => 'processing', 'syncState' => 'syncing']);
    $s = smartsync_progress();
    smartsync_assert_same('failed', $s->s3SyncStatus); smartsync_assert_same('default', $s->syncState);
    smartsync_assert_same('failed', get_option('smarl_sync_settings')['s3SyncStatus']);
    smartsync_assert_same(1, count($s->s3SyncErrors));
});

function smartsync_multisite_upload_root(int $blogId): string {
    $GLOBALS['smartsync_test_multisite'] = true;
    $GLOBALS['smartsync_test_blog_id'] = $blogId;
    $directory = WP_CONTENT_DIR . '/uploads' . ($blogId === 1 ? '' : '/sites/' . $blogId);
    $GLOBALS['smartsync_test_upload_dir'] = ['basedir' => $directory, 'path' => $directory . '/2026/10'];
    wp_mkdir_p($directory . '/2026/10');
    return $directory;
}

smartsync_test('multisite media upload scans only the current blog and retains WordPress-root cloud keys', function () {
    $directory = smartsync_multisite_upload_root(2);
    [$storage, $handler] = smartsync_sync_fixture();
    wp_mkdir_p(WP_CONTENT_DIR . '/uploads/sites/3'); wp_mkdir_p(ABSPATH . 'wp-includes');
    file_put_contents($directory . '/2026/10/own.jpg', 'current-blog');
    file_put_contents(WP_CONTENT_DIR . '/uploads/sites/3/foreign.jpg', 'other-blog');
    file_put_contents(WP_CONTENT_DIR . '/uploads/main.jpg', 'main-blog');
    file_put_contents(ABSPATH . 'wp-includes/shared.jpg', 'shared-wordpress');
    $_POST = ['type' => 'media']; $response = smartsync_capture_json(fn() => $handler->syncLocalToAws());
    smartsync_assert_same(1, $response['data']['total_files']); smartsync_run_jobs($handler);
    smartsync_assert_same(['wp-content/uploads/sites/2/2026/10/own.jpg'], $storage->uploads);
    smartsync_assert_same('other-blog', file_get_contents(WP_CONTENT_DIR . '/uploads/sites/3/foreign.jpg'));
});

smartsync_test('main-blog media upload prunes numbered multisite directories while retaining its own named folders', function () {
    $directory = smartsync_multisite_upload_root(1);
    [$storage, $handler] = smartsync_sync_fixture();
    foreach (['sites/2', 'sites/12', 'sites/templates'] as $path) wp_mkdir_p($directory . '/' . $path);
    file_put_contents($directory . '/2026/10/main.jpg', 'main');
    file_put_contents($directory . '/sites/2/foreign.jpg', 'site-two'); file_put_contents($directory . '/sites/12/foreign.jpg', 'site-twelve');
    file_put_contents($directory . '/sites/templates/owned.jpg', 'main-template');
    $_POST = ['type' => 'media']; smartsync_capture_json(fn() => $handler->syncLocalToAws()); smartsync_run_jobs($handler);
    smartsync_assert_same(['wp-content/uploads/2026/10/main.jpg', 'wp-content/uploads/sites/templates/owned.jpg'], $storage->uploads);
});

smartsync_test('multisite site-admin restore skips foreign shared and MIME-disallowed media objects', function () {
    $directory = smartsync_multisite_upload_root(2);
    [$storage, $handler] = smartsync_sync_fixture();
    wp_mkdir_p(WP_CONTENT_DIR . '/uploads/sites/3'); wp_mkdir_p(ABSPATH . 'wp-includes');
    file_put_contents(WP_CONTENT_DIR . '/uploads/sites/3/foreign.jpg', 'keep-foreign');
    file_put_contents(ABSPATH . 'wp-includes/shared.jpg', 'keep-shared');
    $storage->objects = [
        'wp-content/uploads/sites/2/2026/10/own.jpg' => 'own-remote',
        'wp-content/uploads/sites/2/2026/10/blocked.svg' => '<svg onload="alert(1)"></svg>',
        'wp-content/uploads/sites/3/foreign.jpg' => 'overwrite-foreign',
        'wp-content/uploads/sites/20/sibling.jpg' => 'prefix-sibling',
        'wp-content/uploads/main.jpg' => 'other-main',
        'wp-includes/shared.jpg' => 'overwrite-shared',
    ];
    $GLOBALS['smartsync_test_allowed_mimes'] = ['jpg|jpeg|jpe' => 'image/jpeg'];
    $_POST = ['type' => 'media']; $response = smartsync_capture_json(fn() => $handler->syncAwsToLocal());
    smartsync_assert_same(1, $response['data']['total_files']);
    smartsync_assert_same(['wp-content/uploads/sites/2/2026/10/own.jpg'], array_column(SMARL_Helper::getFileSyncQueueData(20, 0, smartsync_progress()->runId), 'relative_path'));
    smartsync_run_jobs($handler);
    smartsync_assert_same(['wp-content/uploads/sites/2/2026/10/own.jpg'], $storage->downloads);
    smartsync_assert_same('own-remote', file_get_contents($directory . '/2026/10/own.jpg'));
    smartsync_assert_true(!file_exists($directory . '/2026/10/blocked.svg'));
    smartsync_assert_same('keep-foreign', file_get_contents(WP_CONTENT_DIR . '/uploads/sites/3/foreign.jpg'));
    smartsync_assert_same('keep-shared', file_get_contents(ABSPATH . 'wp-includes/shared.jpg'));
});

smartsync_test('main-blog media restore cannot overwrite files in numbered site namespaces', function () {
    smartsync_multisite_upload_root(1); [$storage, $handler] = smartsync_sync_fixture();
    $storage->objects = ['wp-content/uploads/main.jpg' => 'main', 'wp-content/uploads/sites/2/foreign.jpg' => 'foreign', 'wp-content/uploads/sites/templates/own.jpg' => 'template'];
    $_POST = ['type' => 'media']; smartsync_capture_json(fn() => $handler->syncAwsToLocal()); smartsync_run_jobs($handler);
    smartsync_assert_same(['wp-content/uploads/main.jpg', 'wp-content/uploads/sites/templates/own.jpg'], $storage->downloads);
    smartsync_assert_true(!file_exists(WP_CONTENT_DIR . '/uploads/sites/2/foreign.jpg'));
});

smartsync_test('multisite asset restore requires network permission before configuration or provider access', function () {
    smartsync_multisite_upload_root(2);
    $storage = new class extends SmartSyncSyncStorage {
        public int $configurationReads = 0;
        public int $providerLists = 0;
        public function isConfigured(): bool { $this->configurationReads++; return true; }
        public function listObjects(): array { $this->providerLists++; return parent::listObjects(); }
    };
    $storage->save(); $handler = new SMARL_AjaxHandler($storage); $storage->objects['wp-includes/shared.js'] = 'forbidden';
    $_POST = ['type' => 'assets']; $response = smartsync_capture_json(fn() => $handler->syncAwsToLocal());
    smartsync_assert_same(403, $response['status']); smartsync_assert_same(false, $response['success']);
    smartsync_assert_same(0, $storage->configurationReads); smartsync_assert_same(0, $storage->providerLists);
    smartsync_assert_same([], $GLOBALS['smartsync_test_jobs']); smartsync_assert_true(!file_exists(ABSPATH . 'wp-includes/shared.js'));
});

smartsync_test('multisite network administrators can restore shared assets and their current-blog SVG media', function () {
    $directory = smartsync_multisite_upload_root(2); $GLOBALS['smartsync_test_can_manage_network'] = true;
    [$storage, $handler] = smartsync_sync_fixture(); $storage->objects = ['wp-includes/shared.js' => 'network-authorized'];
    $_POST = ['type' => 'assets']; smartsync_assert_same(true, smartsync_capture_json(fn() => $handler->syncAwsToLocal())['success']); smartsync_run_jobs($handler);
    smartsync_assert_same('network-authorized', file_get_contents(ABSPATH . 'wp-includes/shared.js'));
    $storage->objects = ['wp-content/uploads/sites/2/2026/10/own.svg' => '<svg></svg>', 'wp-content/uploads/sites/3/foreign.svg' => '<svg>foreign</svg>'];
    $_POST = ['type' => 'media']; smartsync_capture_json(fn() => $handler->syncAwsToLocal()); smartsync_run_jobs($handler);
    smartsync_assert_same('<svg></svg>', file_get_contents($directory . '/2026/10/own.svg'));
    smartsync_assert_true(!file_exists(WP_CONTENT_DIR . '/uploads/sites/3/foreign.svg'));
});

foreach (['upload', 'download'] as $direction) {
    smartsync_test('multisite workers reject injected foreign media rows before ' . $direction, function () use ($direction) {
        $directory = smartsync_multisite_upload_root(2); [$storage, $handler] = smartsync_sync_fixture();
        wp_mkdir_p(WP_CONTENT_DIR . '/uploads/sites/3'); $foreignPath = WP_CONTENT_DIR . '/uploads/sites/3/foreign.jpg'; file_put_contents($foreignPath, 'keep-foreign');
        $ownKey = 'wp-content/uploads/sites/2/2026/10/own.jpg'; $foreignKey = 'wp-content/uploads/sites/3/foreign.jpg';
        if ($direction === 'upload') file_put_contents($directory . '/2026/10/own.jpg', 'own-local');
        else $storage->objects = [$ownKey => 'own-remote', $foreignKey => 'replace-foreign'];
        $_POST = ['type' => 'media']; smartsync_capture_json(fn() => $direction === 'upload' ? $handler->syncLocalToAws() : $handler->syncAwsToLocal());
        $run = smartsync_progress()->runId;
        $GLOBALS['wpdb']->insert('wp_smarl_file_sync_queue', ['path' => $foreignPath, 'relative_path' => $foreignKey, 'run_id' => $run, 'status' => 'pending', 'size' => strlen('replace-foreign')]);
        smartsync_run_jobs($handler);
        smartsync_assert_same([$ownKey], $direction === 'upload' ? $storage->uploads : $storage->downloads);
        smartsync_assert_same('keep-foreign', file_get_contents($foreignPath)); smartsync_assert_same('failed', smartsync_progress()->s3SyncStatus);
    });
}
