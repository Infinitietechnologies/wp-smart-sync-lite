<?php
foreach (['BucketAlreadyExists', 'BucketAlreadyOwnedByYou'] as $code) {
    smartsync_test('bucket conflict preserves configuration and returns actionable safe details: ' . $code, function () use ($code) {
        $config = smartsync_provider_config();
        $config->fakeS3 = smartsync_provider_s3(static function ($command) use ($code) {
            smartsync_assert_same('CreateBucket', $command->getName());
            throw new \Aws\Exception\AwsException('fixture-secret private provider body', $command, ['code' => $code, 'response' => new \GuzzleHttp\Psr7\Response(409)]);
        });
        $handler = new SmartSyncProviderAjax(new \SmartSyncS3\Models\SMARL_CloudConfig()); $handler->nextConfig = $config;
        $_POST = ['service_provider' => 'aws', 'bucket_type' => 'new', 'bucket_name' => 'fixture-conflict-bucket', 'bucket_region' => 'eu-north-1', 'iam_key' => 'fixture-key', 'iam_secret' => 'fixture-secret', 'use_cloudfront' => 'false'];
        $before = get_option('smarl_settings', []);
        $result = smartsync_capture_json(fn() => $handler->configureAws());
        smartsync_assert_same(false, $result['success']);
        smartsync_assert_same(409, $result['status']);
        smartsync_assert_same($code, $result['data']['code']);
        smartsync_assert_true(str_contains($result['data']['message'], $code === 'BucketAlreadyExists' ? 'already taken' : 'Use existing storage'));
        smartsync_assert_true(!str_contains(json_encode($result), 'fixture-secret'));
        smartsync_assert_same($before, get_option('smarl_settings', []));
        smartsync_assert_same(false, \SmartSyncS3\Helpers\SMARL_Helper::syncLockStatus()['busy']);
    });
}
