<?php
use SmartSyncS3\Ajax\SMARL_AwsConfigAjax;

smartsync_test('Wasabi location XML survives the real SDK parser and reaches the UI as a plain region', function () {
    $config = smartsync_provider_config('wasabi'); $config->region = 'ap-southeast-1';
    $location = '<?xml version="1.0" encoding="UTF-8"?><LocationConstraint xmlns="http://s3.amazonaws.com/doc/2006-03-01/">ap-southeast-1</LocationConstraint>';
    $config->fakeS3 = new \Aws\S3\S3Client([
        'region' => 'ap-southeast-1', 'version' => 'latest', 'endpoint' => 'https://s3.ap-southeast-1.wasabisys.com',
        'credentials' => ['key' => 'fixture-key', 'secret' => 'fixture-secret'],
        'http_handler' => static function ($request) use ($location) {
            $body = str_contains($request->getUri()->getQuery(), 'location') ? $location
                : '<ListAllMyBucketsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Buckets><Bucket><Name>smart-sync-bucket</Name></Bucket></Buckets></ListAllMyBucketsResult>';
            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/xml'], $body));
        },
    ]);
    $handler = new SmartSyncProviderAjax(null); $handler->nextConfig = $config;
    $_POST = ['service_provider' => 'wasabi', 'bucket_region' => 'ap-southeast-1', 'iam_key' => 'fixture-key', 'iam_secret' => 'fixture-secret'];
    $result = smartsync_capture_json(fn() => $handler->listS3Buckets());
    smartsync_assert_same(true, $result['success']);
    smartsync_assert_same([['name' => 'smart-sync-bucket', 'region' => 'ap-southeast-1']], $result['data']['buckets']);
});

smartsync_test('bucket locations normalize XML and aliases while rejecting malformed or unsafe responses', function () {
    $method = new ReflectionMethod(SMARL_AwsConfigAjax::class, 'normalizeBucketLocation');
    $handler = new SMARL_AwsConfigAjax(null);
    foreach ([
        'ap-southeast-1' => 'ap-southeast-1',
        'EU' => 'eu-west-1',
        '' => 'ap-southeast-1',
        '<LocationConstraint xmlns="http://s3.amazonaws.com/doc/2006-03-01/">ap-southeast-1' => 'ap-southeast-1',
        "<?xml version=\"1.0\"?>\n<LocationConstraint>eu-north-1</LocationConstraint>" => 'eu-north-1',
        '<LocationConstraint/>' => 'ap-southeast-1',
    ] as $input => $expected) smartsync_assert_same($expected, $method->invoke($handler, $input, 'ap-southeast-1'));
    foreach ([['region'], '<Wrong>ap-southeast-1</Wrong>', '<LocationConstraint>broken', '<LocationConstraint>ap south 1</LocationConstraint>', '<!DOCTYPE x [<!ENTITY file SYSTEM "file:///private">]><LocationConstraint>&file;</LocationConstraint>'] as $input) {
        smartsync_assert_throws(fn() => $method->invoke($handler, $input, 'ap-southeast-1'), InvalidArgumentException::class);
    }
});
