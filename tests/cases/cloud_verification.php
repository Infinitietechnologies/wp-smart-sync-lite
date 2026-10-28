<?php
use SmartSyncS3\Models\SMARL_UploadHistory;
use SmartSyncS3\Helpers\SMARL_Helper;

foreach (['aws', 'digitalocean', 'wasabi', 'bunny'] as $provider) {
    smartsync_test('incremental repairs external deletions and size changes while preserving real skips: ' . $provider, function () use ($provider) {
        [$storage, $handler] = smartsync_sync_fixture($provider);
        foreach (['kept', 'deleted', 'changed'] as $name) file_put_contents(wp_upload_dir()['basedir'] . '/' . $name . '.png', 'data');
        smartsync_incremental_start($handler); smartsync_run_jobs($handler);
        unset($storage->objects['wp-content/uploads/deleted.png']);
        $storage->objects['wp-content/uploads/changed.png'] = 'different size';
        $repair = smartsync_incremental_start($handler);
        smartsync_assert_same(2, $repair['data']['total_files']);
        smartsync_assert_same(1, $repair['data']['skipped_files']);
        smartsync_run_jobs($handler);
        smartsync_assert_same(5, count($storage->uploads));
        $noop = smartsync_incremental_start($handler);
        smartsync_assert_same(0, $noop['data']['total_files']);
        smartsync_assert_same(3, $noop['data']['skipped_files']);
        $storage->objects = [];
        $empty = smartsync_incremental_start($handler);
        smartsync_assert_same(3, $empty['data']['total_files']);
        smartsync_assert_same(0, $empty['data']['skipped_files']);
    });
}

smartsync_test('failed cloud verification cannot become a successful zero-upload run or leak provider errors', function () {
    $storage = new class extends SmartSyncSyncStorage {
        public function listObjects(): array { throw new RuntimeException('fixture-secret private provider response'); }
    };
    $storage->save(); $handler = new \SmartSyncS3\Ajax\SMARL_AjaxHandler($storage);
    $path = wp_upload_dir()['basedir'] . '/verify.png'; file_put_contents($path, 'data');
    SMARL_UploadHistory::record($storage, SMARL_Helper::relativeKeyForPath($path), SMARL_UploadHistory::signature($path));
    $result = smartsync_incremental_start($handler);
    smartsync_assert_same(false, $result['success']);
    smartsync_assert_true(str_contains($result['data'], 'Cloud files could not be verified'));
    smartsync_assert_true(!str_contains($result['data'], 'fixture-secret'));
    smartsync_assert_same('failed', smartsync_progress()->s3SyncStatus);
    smartsync_assert_true(str_contains(implode(' ', smartsync_progress()->s3SyncErrors), 'Cloud files could not be verified'));
    smartsync_assert_same([], $GLOBALS['smartsync_test_jobs']);
    $full = smartsync_incremental_start($handler, 'media', 'full');
    smartsync_assert_same(1, $full['data']['total_files']);
    smartsync_run_jobs($handler);
    smartsync_assert_same('completed', smartsync_progress()->s3SyncStatus);
});

smartsync_test('S3 verification uses bounded paginated listings for candidates and never per-file requests', function () {
    $storage = smartsync_provider_config('wasabi');
    $calls = [];
    $storage->fakeS3 = smartsync_provider_s3(static function ($command) use (&$calls) {
        $calls[] = $command->getName();
        smartsync_assert_same('ListObjectsV2', $command->getName());
        smartsync_assert_same('wp-content/uploads/', $command['Prefix']);
        smartsync_assert_same(0, $command['@retries']);
        smartsync_assert_true($command['@http']['timeout'] <= 10);
        if (empty($command['ContinuationToken'])) return ['Contents' => [['Key' => 'wp-content/uploads/kept.png', 'Size' => 4]], 'IsTruncated' => true, 'NextContinuationToken' => 'page-two'];
        return ['Contents' => [], 'IsTruncated' => false];
    });
    $found = $storage->verifyUploadCandidates(['wp-content/uploads/kept.png', 'wp-content/uploads/deleted.png']);
    smartsync_assert_same(['wp-content/uploads/kept.png' => 4], $found);
    smartsync_assert_same(['ListObjectsV2', 'ListObjectsV2'], $calls);
});

smartsync_test('Bunny verification limits directory access to the selected prefix and accepts an absent directory', function () {
    $storage = smartsync_provider_config('bunny'); $storage->region = 'DE';
    $GLOBALS['smartsync_test_http'] = static function ($url, $args) {
        smartsync_assert_true(str_ends_with($url, '/wp-content/uploads/2026/'));
        smartsync_assert_same('GET', $args['method']);
        smartsync_assert_true($args['timeout'] <= 10);
        return ['response' => ['code' => 404], 'body' => ''];
    };
    smartsync_assert_same([], $storage->verifyUploadCandidates(['wp-content/uploads/2026/deleted.png']));
    smartsync_assert_same(1, count($GLOBALS['smartsync_test_http_requests']));
});
