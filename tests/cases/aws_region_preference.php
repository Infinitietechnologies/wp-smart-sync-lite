<?php
use SmartSyncS3\Models\SMARL_CloudConfig;

smartsync_test('AWS discovery remembers its optional region independently from the active storage target', function () {
    $current = smartsync_provider_config('aws'); $current->region = 'eu-west-1'; $current->save();
    $before = $current->toArray();
    $probe = smartsync_provider_config('aws');
    $probe->fakeS3 = smartsync_provider_s3(fn() => ['Buckets' => []]);
    $handler = new SmartSyncProviderAjax($current); $handler->nextConfig = $probe;
    foreach (['eu-north-1', ''] as $preference) {
        $_POST = ['service_provider' => 'aws', 'iam_key' => 'fixture', 'iam_secret' => 'fixture'];
        if ($preference !== '') $_POST['bucket_region'] = $preference;
        smartsync_assert_same(true, smartsync_capture_json(fn() => $handler->listS3Buckets())['success']);
        smartsync_assert_same($preference, get_option('smarl_aws_discovery_region'));
        smartsync_assert_same($before, SMARL_CloudConfig::load()->toArray());
    }
    $_POST['bucket_region'] = '<invalid-region>';
    smartsync_assert_same(false, smartsync_capture_json(fn() => $handler->listS3Buckets())['success']);
    smartsync_assert_same('', get_option('smarl_aws_discovery_region'));
});
