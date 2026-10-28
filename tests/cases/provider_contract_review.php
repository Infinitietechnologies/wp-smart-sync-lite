<?php
use SmartSyncS3\Ajax\SMARL_AwsConfigAjax;
use SmartSyncS3\Models\SMARL_CloudConfig;

smartsync_test('DigitalOcean bucket creation serializes without an AWS-specific location constraint', function () {
    $config = SMARL_CloudConfig::fromArray(['service_provider' => 'digitalocean', 'bucket' => 'contract-space', 'region' => 'nyc3', 'iam_key' => 'fixture-access', 'iam_secret' => 'fixture-secret']);
    $client = $config->getS3Client();
    $request = \Aws\serialize($client->getCommand('CreateBucket', ['Bucket' => $config->bucket, 'ACL' => 'public-read']));
    smartsync_assert_same('contract-space.nyc3.digitaloceanspaces.com', $request->getUri()->getHost());
    smartsync_assert_same('', (string) $request->getBody(), 'DO uses the endpoint region and requires an empty CreateBucket payload');
});

smartsync_test('AWS public URLs for dotted buckets use a TLS-compatible host just like the actual SDK', function () {
    $config = SMARL_CloudConfig::fromArray(['service_provider' => 'aws', 'bucket' => 'contract.dotted.bucket', 'region' => 'eu-west-1', 'iam_key' => 'fixture-access', 'iam_secret' => 'fixture-secret']);
    $request = \Aws\serialize($config->getS3Client()->getCommand('GetObject', ['Bucket' => $config->bucket, 'Key' => 'photo.jpg']));
    $urlBuilder = new ReflectionMethod(SMARL_AwsConfigAjax::class, 'directPublicUrl');
    $publicBase = SMARL_CloudConfig::normalizePublicBaseUrl($urlBuilder->invoke(new SMARL_AwsConfigAjax(null), $config));
    smartsync_assert_same($request->getUri()->getHost(), parse_url($publicBase, PHP_URL_HOST), 'A dotted bucket cannot use the wildcard S3 HTTPS certificate in virtual-host form');
    smartsync_assert_same('/' . $config->bucket, parse_url($publicBase, PHP_URL_PATH));
});

smartsync_test('AWS China direct public URLs retain the real SDK partition DNS suffix', function () {
    $config = SMARL_CloudConfig::fromArray(['service_provider' => 'aws', 'bucket' => 'contract-china-bucket', 'region' => 'cn-north-1', 'iam_key' => 'fixture-access', 'iam_secret' => 'fixture-secret']);
    $request = \Aws\serialize($config->getS3Client()->getCommand('GetObject', ['Bucket' => $config->bucket, 'Key' => 'photo.jpg']));
    $builder = new ReflectionMethod(SMARL_AwsConfigAjax::class, 'directPublicUrl');
    $publicBase = SMARL_CloudConfig::normalizePublicBaseUrl($builder->invoke(new SMARL_AwsConfigAjax(null), $config));
    smartsync_assert_same($request->getUri()->getHost(), parse_url($publicBase, PHP_URL_HOST));
});

foreach (['cn-north-1', 'us-gov-west-1'] as $region) {

}

foreach (['cn-north-1' => 'aws-cn', 'us-gov-west-1' => 'aws-us-gov'] as $region => $partition) {
    smartsync_test('direct S3 public policies use the bucket partition: ' . $partition, function () use ($region, $partition) {
        $policy = null; $config = smartsync_provider_config();
        $config->fakeS3 = smartsync_provider_s3(function ($command) use (&$policy) {
            if ($command->getName() === 'GetBucketPolicy') return ['Policy' => '{"Version":"2012-10-17","Statement":[]}'];
            if ($command->getName() === 'PutBucketPolicy') $policy = json_decode($command['Policy'], true);
            return [];
        });
        $ajax = new SmartSyncProviderAjax(null); $ajax->nextConfig = $config;
        $_POST = ['service_provider' => 'aws', 'bucket_type' => 'existing', 'bucket_name' => 'contract-bucket', 'bucket_region' => $region,
            'iam_key' => 'fixture-access', 'iam_secret' => 'fixture-secret', 'use_cloudfront' => 'false'];
        smartsync_assert_same(true, smartsync_capture_json(fn() => $ajax->configureAws())['success']);
        smartsync_assert_same('arn:' . $partition . ':s3:::contract-bucket/*', $policy['Statement'][0]['Resource']);
    });
}
