<?php
use SmartSyncS3\Ajax\SMARL_AjaxHandler;
use SmartSyncS3\Exceptions\SMARL_ProviderException;

smartsync_test('Bunny usage follows pages and fetches missing totals from the selected zone details', function () {
    $config = smartsync_provider_config('bunny'); $calls = [];
    $GLOBALS['smartsync_test_http'] = function ($url, $args) use (&$calls) {
        $calls[] = $url;
        smartsync_assert_true($args['timeout'] > 0 && $args['timeout'] <= 8);
        smartsync_assert_same(0, $args['redirection']);
        smartsync_assert_same('fixture-secret', $args['headers']['AccessKey']);
        if (str_ends_with($url, '/42')) $data = ['Id' => 42, 'Name' => 'fixture-bucket', 'FilesStored' => '3', 'StorageUsed' => '8192'];
        elseif (str_contains($url, 'page=1&')) $data = ['CurrentPage' => 1, 'HasMoreItems' => true, 'Items' => [['Id' => 1, 'Name' => 'other-zone']]];
        else $data = ['CurrentPage' => 2, 'HasMoreItems' => false, 'Items' => [['Id' => 42, 'Name' => 'fixture-bucket']]];
        return ['response' => ['code' => 200], 'body' => json_encode($data)];
    };
    smartsync_assert_same(['total_files' => 3, 'total_size' => 8192, 'usage_source' => 'provider'], $config->getStorageUsage());
    smartsync_assert_same(3, count($calls));
    smartsync_assert_same('https://api.bunny.net/storagezone/42', $calls[2]);
});

smartsync_test('Bunny reported totals preserve zero and reject missing, negative, fractional or boolean metrics', function () {
    $config = smartsync_provider_config('bunny');
    foreach ([null, -1, true, 1.5, 'not-a-count'] as $badCount) {
        $GLOBALS['smartsync_test_http'] = fn() => ['response' => ['code' => 200], 'body' => json_encode([['Name' => 'fixture-bucket', 'FilesStored' => $badCount, 'StorageUsed' => 0]])];
        smartsync_assert_throws(fn() => $config->getStorageUsage(), SMARL_ProviderException::class);
    }
    $GLOBALS['smartsync_test_http'] = fn() => ['response' => ['code' => 200], 'body' => '[{"Name":"fixture-bucket","FilesStored":0,"StorageUsed":0}]'];
    smartsync_assert_same(['total_files' => 0, 'total_size' => 0, 'usage_source' => 'provider'], $config->getStorageUsage());
});

smartsync_test('Bunny usage refuses mismatched zone details instead of returning another targets totals', function () {
    $config = smartsync_provider_config('bunny');
    $GLOBALS['smartsync_test_http'] = fn($url) => ['response' => ['code' => 200], 'body' => json_encode(str_contains($url, '?') ? [['Id' => 42, 'Name' => 'fixture-bucket']] : ['Name' => 'wrong-zone', 'FilesStored' => 10, 'StorageUsed' => 100])];
    smartsync_assert_throws(fn() => $config->getStorageUsage(), SMARL_ProviderException::class);
});

smartsync_test('analytics transport failures return a safe actionable JSON error before the PHP execution limit', function () {
    $config = smartsync_provider_config('bunny'); $calls = 0;
    $GLOBALS['smartsync_test_http'] = function ($url, $args) use (&$calls) {
        $calls++;
        smartsync_assert_true($args['timeout'] <= 8);
        return new WP_Error('http_request_failed', 'cURL timeout: fixture-secret must-not-leak');
    };
    $response = smartsync_capture_json(fn() => (new SMARL_AjaxHandler($config))->fetchCloudAnalytics());
    smartsync_assert_same(false, $response['success']); smartsync_assert_same(502, $response['status']);
    smartsync_assert_true(str_contains($response['data'], 'time limit'));
    smartsync_assert_true(!str_contains($response['data'], 'fixture-secret') && !str_contains($response['data'], 'must-not-leak'));
    smartsync_assert_same(1, $calls);
});

smartsync_test('analytics distinguishes Bunny authentication and rate limits without exposing provider response bodies', function () {
    foreach ([401 => 'account API key', 403 => 'account API key', 429 => 'rate limit', 503 => 'temporarily unavailable'] as $status => $expected) {
        $GLOBALS['smartsync_test_http'] = fn() => ['response' => ['code' => $status], 'body' => '{"Message":"must-not-leak"}'];
        $response = smartsync_capture_json(fn() => (new SMARL_AjaxHandler(smartsync_provider_config('bunny')))->fetchCloudAnalytics());
        smartsync_assert_same(false, $response['success']); smartsync_assert_same(502, $response['status']);
        smartsync_assert_true(str_contains($response['data'], $expected));
        smartsync_assert_true(!str_contains($response['data'], 'must-not-leak'));
    }
});

smartsync_test('Bunny usage rejects malformed responses, missing zones and cyclic pagination without a directory-scan fallback', function () {
    $config = smartsync_provider_config('bunny');
    foreach (['broken json', '[]', '{"Items":[],"HasMoreItems":true,"CurrentPage":1}', '{"Items":[],"CurrentPage":2}'] as $body) {
        $GLOBALS['smartsync_test_http'] = fn() => ['response' => ['code' => 200], 'body' => $body];
        smartsync_assert_throws(fn() => $config->getStorageUsage(), SMARL_ProviderException::class);
    }
    $calls = 0;
    $GLOBALS['smartsync_test_http'] = function ($url) use (&$calls) {
        $calls++;
        smartsync_assert_true(str_starts_with($url, 'https://api.bunny.net/'));
        return ['response' => ['code' => 200], 'body' => json_encode(['Items' => [['Name' => 'other-zone']], 'CurrentPage' => $calls, 'HasMoreItems' => true])];
    };
    smartsync_assert_throws(fn() => $config->getStorageUsage(), SMARL_ProviderException::class);
    smartsync_assert_same(2, $calls);
});

smartsync_test('Bunny usage reserves time before a short PHP limit rather than making an unfinishable HTTP request', function () {
    $config = smartsync_provider_config('bunny'); $calls = 0;
    $oldLimit = ini_get('max_execution_time'); $oldStart = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
    $GLOBALS['smartsync_test_http'] = function () use (&$calls) { $calls++; throw new RuntimeException('HTTP should not start after the deadline.'); };
    try {
        ini_set('max_execution_time', '5'); $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        smartsync_assert_throws(fn() => $config->getStorageUsage(), SMARL_ProviderException::class);
        smartsync_assert_same(0, $calls);
    } finally {
        ini_set('max_execution_time', (string) $oldLimit);
        if ($oldStart === null) unset($_SERVER['REQUEST_TIME_FLOAT']); else $_SERVER['REQUEST_TIME_FLOAT'] = $oldStart;
    }
});

smartsync_test('non-Bunny analytics retains exact common-provider listing totals', function () {
    $config = smartsync_provider_config('wasabi');
    $config->fakeS3 = smartsync_provider_s3(fn() => ['Contents' => [['Key' => 'a.jpg', 'Size' => 3], ['Key' => 'b.jpg', 'Size' => 2]], 'IsTruncated' => false]);
    smartsync_assert_same(['total_files' => 2, 'total_size' => 5, 'usage_source' => 'listing'], $config->getStorageUsage());
});

smartsync_test('Bunny usage has a bounded pagination cap even when every returned page is distinct', function () {
    $config = smartsync_provider_config('bunny'); $calls = 0;
    $GLOBALS['smartsync_test_http'] = function () use (&$calls) {
        $calls++;
        return ['response' => ['code' => 200], 'body' => json_encode(['Items' => [['Name' => 'other-' . $calls]], 'CurrentPage' => $calls, 'HasMoreItems' => true])];
    };
    smartsync_assert_throws(fn() => $config->getStorageUsage(), SMARL_ProviderException::class);
    smartsync_assert_same(50, $calls);
});

smartsync_test('Bunny usage requires the account key without substituting the separate storage password', function () {
    $config = smartsync_provider_config('bunny'); $config->iamSecret = '';
    smartsync_assert_throws(fn() => $config->getStorageUsage(), SMARL_ProviderException::class);
    smartsync_assert_same([], $GLOBALS['smartsync_test_http_requests']);
});
