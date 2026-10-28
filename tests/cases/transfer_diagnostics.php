<?php
use SmartSyncS3\Ajax\SMARL_AjaxHandler;
use SmartSyncS3\Exceptions\SMARL_ProviderException;

foreach ([28 => 'timed out', 6 => 'DNS', 7 => 'outbound HTTPS', 60 => 'TLS', 99 => 'Unable to contact'] as $errno => $expected) {
    smartsync_test('Bunny transport errors preserve a safe category without raw request data: ' . $errno, function () use ($errno, $expected) {
        $config = smartsync_provider_config('bunny'); $config->region = 'DE';
        $path = wp_upload_dir()['basedir'] . '/diagnostic.png'; file_put_contents($path, 'tiny');
        $GLOBALS['smartsync_test_http'] = static fn() => new WP_Error('http_request_failed', 'cURL error ' . $errno . ': fixture-secret AccessKey signed-url=https://private.example.test/?secret=fixture-secret');
        $error = smartsync_assert_throws(fn() => $config->uploadObject('diagnostic.png', $path), SMARL_ProviderException::class);
        smartsync_assert_true(str_contains($error->getMessage(), $expected));
        smartsync_assert_true(!str_contains($error->getMessage(), 'fixture-secret') && !str_contains($error->getMessage(), 'private.example.test'));
    });
}

smartsync_test('bulk Bunny timeout records its real safe reason and remains eligible for incremental retry', function () {
    $config = smartsync_provider_config('bunny'); $config->region = 'DE'; $config->save(); $handler = new SMARL_AjaxHandler($config);
    $path = wp_upload_dir()['basedir'] . '/diagnostic.png'; file_put_contents($path, 'tiny');
    $GLOBALS['smartsync_test_http'] = static fn() => new WP_Error('http_request_failed', 'cURL error 28: fixture-secret signed-url');
    $_POST = ['type' => 'media']; smartsync_capture_json(fn() => $handler->syncLocalToAws()); smartsync_run_jobs($handler);
    $errors = implode(' ', smartsync_progress()->s3SyncErrors);
    smartsync_assert_true(str_contains($errors, 'timed out'));
    smartsync_assert_true(!str_contains($errors, 'fixture-secret'));
    $GLOBALS['smartsync_test_http'] = static fn($url, $args) => ($args['method'] ?? '') === 'GET'
        ? ['response' => ['code' => 200], 'body' => json_encode([['ObjectName' => 'diagnostic.png', 'Length' => 4, 'IsDirectory' => false]])]
        : ['response' => ['code' => 201], 'body' => ''];
    smartsync_assert_same(1, smartsync_incremental_start($handler)['data']['total_files']);
    smartsync_run_jobs($handler); smartsync_assert_same('completed', smartsync_progress()->s3SyncStatus);
    smartsync_assert_same(0, smartsync_incremental_start($handler)['data']['total_files']);
});

foreach ([403 => 'rejected access', 429 => 'rate limit', 503 => 'HTTP 503'] as $status => $expected) {
    smartsync_test('bulk Bunny HTTP failures preserve status-specific safe explanations: ' . $status, function () use ($status, $expected) {
        $config = smartsync_provider_config('bunny'); $config->region = 'DE'; $config->save(); $handler = new SMARL_AjaxHandler($config);
        file_put_contents(wp_upload_dir()['basedir'] . '/diagnostic.png', 'tiny');
        $GLOBALS['smartsync_test_http'] = static fn() => ['response' => ['code' => $status], 'body' => 'fixture-secret provider debug body'];
        $_POST = ['type' => 'media']; smartsync_capture_json(fn() => $handler->syncLocalToAws()); smartsync_run_jobs($handler);
        $errors = implode(' ', smartsync_progress()->s3SyncErrors);
        smartsync_assert_true(str_contains($errors, $expected));
        smartsync_assert_true(!str_contains($errors, 'fixture-secret') && !str_contains($errors, 'provider debug body'));
    });
}

smartsync_test('raw SDK exceptions use only an HTTP category and never expose vendor error bodies', function () {
    $storage = new class extends SmartSyncSyncStorage {
        public function uploadObject(string $key, string $path): void {
            throw new \Aws\Exception\AwsException('fixture-secret signed-url and provider response body', new \Aws\Command('PutObject'), ['response' => new \GuzzleHttp\Psr7\Response(403)]);
        }
    };
    $storage->save(); $handler = new SMARL_AjaxHandler($storage);
    file_put_contents(wp_upload_dir()['basedir'] . '/diagnostic.png', 'tiny');
    $_POST = ['type' => 'media']; smartsync_capture_json(fn() => $handler->syncLocalToAws()); smartsync_run_jobs($handler);
    $errors = implode(' ', smartsync_progress()->s3SyncErrors);
    smartsync_assert_true(str_contains($errors, 'provider denied access'));
    smartsync_assert_true(!str_contains($errors, 'fixture-secret') && !str_contains($errors, 'provider response body'));
});
