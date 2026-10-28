<?php
use SmartSyncS3\Ajax\SMARL_AwsConfigAjax;
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Models\SMARL_CloudConfig;

function smartsync_inline_cloudfront_fixture(): array {
    $state = (object) ['calls' => [], 'deployed' => false, 'failPolicy' => false, 'oac' => [], 'distribution' => [],
        'policy' => ['Version' => '2012-10-17', 'Statement' => [
            ['Sid' => 'SmartSyncPublicRead', 'Effect' => 'Allow', 'Principal' => '*', 'Action' => 's3:GetObject', 'Resource' => 'arn:aws:s3:::website-media/*'],
            ['Sid' => 'Unrelated', 'Effect' => 'Allow', 'Principal' => ['AWS' => 'arn:aws:iam::123456789012:root'], 'Action' => 's3:ListBucket', 'Resource' => 'arn:aws:s3:::website-media'],
        ]]];
    $config = new SmartSyncAudit2CloudFrontConfig();
    $config->serviceProvider = 'aws'; $config->bucket = 'website-media'; $config->region = 'eu-north-1';
    $config->iamKey = 'fixture-inline-key'; $config->iamSecret = 'fixture-inline-secret';
    $config->url = 'website-media.s3.eu-north-1.amazonaws.com'; $config->urlType = 's3';
    $config->useCdnForMedia = $config->canEnableMediaCDN = $config->mediaAutoDelete = true;
    $config->fakeS3 = smartsync_provider_s3(function ($command) use ($state) {
        $name = $command->getName(); $state->calls[] = $name;
        if ($name === 'GetBucketPolicy') return ['Policy' => json_encode($state->policy)];
        if ($name === 'PutBucketPolicy') {
            if ($state->failPolicy) throw new RuntimeException('private provider response fixture-inline-secret');
            $state->policy = json_decode($command['Policy'], true);
        }
        return [];
    });
    $options = ['region' => 'us-east-1', 'version' => 'latest', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture']];
    $config->fakeCloudFront = new \Aws\CloudFront\CloudFrontClient($options + ['handler' => function ($command) use ($state) {
        $name = $command->getName(); $state->calls[] = $name;
        smartsync_assert_same(10, $command['@http']['timeout']);
        if ($name === 'CreateOriginAccessControl') $state->oac = ['Id' => 'INLINEOAC', 'OriginAccessControlConfig' => $command['OriginAccessControlConfig']];
        if ($name === 'CreateDistribution') $state->distribution = ['Id' => 'EINLINE123', 'DomainName' => 'dinline.cloudfront.net', 'DistributionConfig' => $command['DistributionConfig']];
        $distribution = $state->distribution + ['Status' => $state->deployed ? 'Deployed' : 'InProgress'];
        return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result(str_contains($name, 'OriginAccessControl') ? ['OriginAccessControl' => $state->oac] : ['Distribution' => $distribution]));
    }]);
    $config->fakeSts = new \Aws\Sts\StsClient($options + ['handler' => function ($command) use ($state) {
        $state->calls[] = $command->getName(); return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result(['Account' => '123456789012']));
    }]);
    $config->save();
    return [$config, new SMARL_AwsConfigAjax($config), $state];
}

function smartsync_inline_cloudfront_request(SMARL_AwsConfigAjax $handler, SMARL_CloudConfig $config, string $operation = 'setup'): array {
    $_POST = ['configuration_reference' => $config->setupReference(), 'operation' => $operation];
    return smartsync_capture_json(fn() => $handler->setupCustomCloudFront());
}

smartsync_test('inline CloudFront resumes deployment then unlocks custom domains without interrupting S3 delivery or resetting sync', function () {
    [$config, $handler, $state] = smartsync_inline_cloudfront_fixture(); $before = $config->toArray();
    update_option('smarl_sync_settings', ['s3SyncStatus' => 'failed', 's3SyncErrors' => ['existing result']]);
    $first = smartsync_inline_cloudfront_request($handler, $config);
    smartsync_assert_same('deploying', $first['data']['state']);
    smartsync_assert_same($before, SMARL_CloudConfig::load()->toArray());
    smartsync_assert_true(!in_array('PutBucketPolicy', $state->calls, true));
    smartsync_assert_same('deploying', smartsync_inline_cloudfront_request($handler, $config, 'check')['data']['state']);
    $state->deployed = true;
    $last = smartsync_inline_cloudfront_request($handler, $config, 'check');
    smartsync_assert_same('ready', $last['data']['state']);
    $saved = SMARL_CloudConfig::load();
    smartsync_assert_true($saved->useCloudFront && $saved->useCdnForMedia && $saved->canEnableMediaCDN && $saved->mediaAutoDelete);
    smartsync_assert_same($before['url'], $saved->url);
    smartsync_assert_same('EINLINE123', $saved->distributionId);
    smartsync_assert_same('dinline.cloudfront.net', $saved->cloudfrontUrl);
    smartsync_assert_same(['SmartSyncPublicRead', 'Unrelated', 'SmartSyncCloudFrontRead'], array_column($state->policy['Statement'], 'Sid'));
    smartsync_assert_true(!in_array('PutPublicAccessBlock', $state->calls, true));
    smartsync_assert_same(1, count(array_filter($state->calls, fn($name) => $name === 'CreateDistribution')));
    smartsync_assert_same(1, count(array_filter($state->calls, fn($name) => $name === 'CreateOriginAccessControl')));
    smartsync_assert_same('failed', get_option('smarl_sync_settings')['s3SyncStatus']);
    smartsync_assert_same(false, get_option('smarl_sync_lock'));
    smartsync_assert_true(!str_contains(json_encode($last), 'fixture-inline-secret'));
    $count = count($state->calls);
    smartsync_assert_same('ready', smartsync_inline_cloudfront_request($handler, $saved, 'check')['data']['state']);
    smartsync_assert_same($count, count($state->calls));
});

foreach (['policy', 'database'] as $failure) {
    smartsync_test('inline CloudFront retries after ' . $failure . ' failure with recorded resources and working S3 access retained', function () use ($failure) {
        [$config, $handler, $state] = smartsync_inline_cloudfront_fixture(); $state->deployed = true;
        if ($failure === 'policy') $state->failPolicy = true;
        else $GLOBALS['smartsync_provider_failed_option'] = 'smarl_settings';
        try { $response = smartsync_inline_cloudfront_request($handler, $config); }
        finally { unset($GLOBALS['smartsync_provider_failed_option']); }
        smartsync_assert_same(false, $response['success']);
        smartsync_assert_true(!SMARL_CloudConfig::load()->useCloudFront);
        smartsync_assert_true(in_array('SmartSyncPublicRead', array_column($state->policy['Statement'], 'Sid'), true));
        smartsync_assert_true(!str_contains(json_encode($response), 'fixture-inline-secret'));
        $state->failPolicy = false;
        smartsync_assert_same('ready', smartsync_inline_cloudfront_request($handler, $config)['data']['state']);
        smartsync_assert_same(1, count(array_filter($state->calls, fn($name) => $name === 'CreateDistribution')));
    });
}

smartsync_test('inline CloudFront rejects stale pages, active sync, another lease, unsupported providers and check-only creation', function () {
    [$config, $handler, $state] = smartsync_inline_cloudfront_fixture();
    foreach (['stale', 'processing', 'lease', 'provider', 'region', 'check'] as $scenario) {
        $current = clone $config; $lock = null;
        if ($scenario === 'provider') $current->serviceProvider = 'digitalocean';
        if ($scenario === 'region') $current->region = 'cn-north-1';
        $current->save();
        update_option('smarl_sync_settings', ['s3SyncStatus' => $scenario === 'processing' ? 'processing' : 'idle']);
        if ($scenario === 'lease') $lock = SMARL_Helper::acquireSyncLock();
        $_POST = ['configuration_reference' => $scenario === 'stale' ? 'outdated' : $current->setupReference(), 'operation' => $scenario === 'check' ? 'check' : 'setup'];
        $response = smartsync_capture_json(fn() => $handler->setupCustomCloudFront());
        if ($lock !== null) SMARL_Helper::releaseSyncLock($lock);
        smartsync_assert_same(false, $response['success'], $scenario);
    }
    smartsync_assert_true(!in_array('CreateDistribution', $state->calls, true) && !in_array('PutBucketPolicy', $state->calls, true));
});
