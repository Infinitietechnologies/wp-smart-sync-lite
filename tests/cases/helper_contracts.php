<?php
use SmartSyncS3\Helpers\SMARL_Helper;

smartsync_test('compatibility notices handle unlimited short and normal execution limits without hiding functionality', function () {
    $old = ini_get('max_execution_time');
    try {
        ini_set('max_execution_time', '0'); smartsync_assert_same(true, SMARL_Helper::checkPluginCompatibility()['compatible']);
        ini_set('max_execution_time', '15'); $warning = SMARL_Helper::checkPluginCompatibility();
        smartsync_assert_same(false, $warning['compatible']); smartsync_assert_same('max_execution_time', $warning['error'][0]['error_key']);
        ini_set('max_execution_time', '30'); smartsync_assert_same(true, SMARL_Helper::checkPluginCompatibility()['compatible']);
    } finally { ini_set('max_execution_time', $old); }
});

smartsync_test('legacy URL and storage display helpers retain paths queries fragments and sensible boundary values', function () {
    smartsync_assert_same('https://cdn.example.test/bucket/old/path.jpg?v=2#view', SMARL_Helper::replaceBaseUrl('https://site.example.test/old/path.jpg?v=2#view', 'https://cdn.example.test/bucket/'));
    smartsync_assert_same('https://site.example.test/x', SMARL_Helper::replaceBaseUrl('https://site.example.test/x', ''));
    smartsync_assert_same('jpg', SMARL_Helper::getFileExtensionFromUrl('https://site.example.test/file.JPG?v=.css#test'));
    smartsync_assert_same('', SMARL_Helper::getFileExtensionFromUrl('https://site.example.test/no-extension'));
    smartsync_assert_same('0 B', SMARL_Helper::formatBytes(-1)); smartsync_assert_same('1 KB', SMARL_Helper::formatBytes(1024));
    smartsync_assert_true(!str_contains(SMARL_Helper::formatBytes(PHP_INT_MAX), 'INF'));
    // These retained helpers are historical estimates; this checks shape/monotonicity, not current billing accuracy.
    $prices = SMARL_Helper::getRegionPrices(); smartsync_assert_true((bool) $prices);
    foreach ($prices as $region => $price) smartsync_assert_true(is_string($region) && is_numeric($price) && $price >= 0);
    smartsync_assert_same(SMARL_Helper::calculateStorageDigitalOceanPrice(0), SMARL_Helper::calculateStorageDigitalOceanPrice(250 * 1024 ** 3));
    smartsync_assert_true(SMARL_Helper::calculateStorageDigitalOceanPrice(251 * 1024 ** 3) > SMARL_Helper::calculateStorageDigitalOceanPrice(0));
});

smartsync_test('legacy queue updater returns the same contained ordered manifest that it persists', function () {
    file_put_contents(ABSPATH . 'second.jpg', 'two'); file_put_contents(ABSPATH . 'first.JPG', 'one'); file_put_contents(ABSPATH . 'ignored.php', 'private');
    $files = SMARL_Helper::updateLocalFilesDB(ABSPATH, ['jpg'], 'legacy-run');
    smartsync_assert_same(['first.JPG', 'second.jpg'], array_column($files, 'relative_path'));
    smartsync_assert_same(['first.JPG', 'second.jpg'], array_column(SMARL_Helper::getFileSyncQueueData(10, 0, 'legacy-run'), 'relative_path'));
});

smartsync_test('legacy switch rendering keeps attribute values escaped and rejects executable attribute names', function () {
    ob_start();
    SMARL_Helper::renderSwitch('delivery"bad', 'enabled', 'disabled', ['aria-label' => 'Cloud "delivery"', 'data-state' => '<state>', 'onclick' => 'alert(1)'], false);
    echo SMARL_Helper::renderProModal();
    $html = ob_get_clean();
    smartsync_assert_true(!str_contains($html, 'onclick'));
    smartsync_assert_true(str_contains($html, 'aria-label="Cloud &quot;delivery&quot;"'));
    smartsync_assert_true(str_contains($html, 'data-state="&lt;state&gt;"'));
    smartsync_assert_true(!str_contains($html, ' checked'));
});

smartsync_test('legacy S3 listing preserves zero-size objects across ordinary pages', function () {
    $requests = [];
    $client = smartsync_provider_s3(function ($command) use (&$requests) {
        $requests[] = $command->toArray();
        return count($requests) === 1 ? ['Contents' => [['Key' => 'nested/empty.jpg', 'Size' => 0]], 'IsTruncated' => true, 'NextContinuationToken' => 'next']
            : ['Contents' => [['Key' => 'nested/full.jpg', 'Size' => 5]], 'IsTruncated' => false];
    });
    smartsync_assert_same([['key' => 'nested/empty.jpg', 'size' => 0], ['key' => 'nested/full.jpg', 'size' => 5]], SMARL_Helper::getS3Files($client, 'fixture-bucket'));
    smartsync_assert_same('next', $requests[1]['ContinuationToken']);
});

smartsync_test('public legacy Lite helpers validate configuration and keep Bunny uploads scoped', function () {
    $handler = new \SmartSyncS3\Ajax\SMARL_AjaxHandler(new \SmartSyncS3\Models\SMARL_CloudConfig());
    smartsync_assert_same(400, smartsync_capture_json(fn() => $handler->noConfigurationFound())['status']);
    $bunny = smartsync_provider_config('bunny'); $bunny->region = 'DE'; $bunnyHandler = new \SmartSyncS3\Ajax\SMARL_AjaxHandler($bunny);
    $source = ABSPATH . 'legacy-upload.jpg'; file_put_contents($source, 'legacy');
    $GLOBALS['smartsync_test_http'] = function ($url, $options) { smartsync_assert_true(str_ends_with($url, '/nested/photo.jpg')); return ['response' => ['code' => 201], 'body' => '']; };
    smartsync_assert_same(true, $bunnyHandler->uploadToBunnyCDN($source, $bunny->bucket, 'nested/photo.jpg')['success']);
    smartsync_assert_throws(fn() => $bunnyHandler->uploadToBunnyCDN($source, 'other-zone'), RuntimeException::class);
});
