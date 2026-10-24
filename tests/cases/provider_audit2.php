<?php
namespace SmartSyncS3\Models {
    /** Emulate WordPress's false return on a failed option write, without changing shared stubs. */
    function update_option($name, $value, $autoload = null) {
        if (($GLOBALS['smartsync_provider_failed_option'] ?? '') === $name) return false;
        return \update_option($name, $value, $autoload);
    }
}
namespace SmartSyncS3\Ajax {
    function update_option($name, $value, $autoload = null) {
        if (($GLOBALS['smartsync_provider_failed_option'] ?? '') === $name) return false;
        if ($name === 'smarl_cloudfront_pending' && isset($GLOBALS['smartsync_provider_checkpoint_writes'])) {
            if (++$GLOBALS['smartsync_provider_checkpoint_writes'] === 2) return false;
        }
        return \update_option($name, $value, $autoload);
    }
}
namespace {
use SmartSyncS3\Models\SMARL_CloudConfig;
use SmartSyncS3\Models\SMARL_CustomUrl;
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Services\SMARLI_GoogleService;
use SmartSyncS3\Services\SMARLI_WasabiBucketManager;
use SmartSyncS3\Exceptions\SMARL_ProviderException;

class SmartSyncAudit2StreamingConfig extends SMARL_CloudConfig {
    public \GuzzleHttp\ClientInterface $streamClient;
    protected function getBunnyStreamClient(): \GuzzleHttp\ClientInterface { return $this->streamClient; }
}
class SmartSyncAudit2CloudFrontConfig extends SMARL_CloudConfig {
    public \Aws\S3\S3Client $fakeS3;
    public \Aws\CloudFront\CloudFrontClient $fakeCloudFront;
    public \Aws\Sts\StsClient $fakeSts;
    public function getS3Client(string $region = ''): ?\Aws\S3\S3Client { return $this->fakeS3; }
    public function getCloudfrontClient(): \Aws\CloudFront\CloudFrontClient { return $this->fakeCloudFront; }
    public function getStsClient(string $region = ''): \Aws\Sts\StsClient { return $this->fakeSts; }
}
function smartsync_audit2_credentials(bool $realKey = false): array {
    $key = "-----BEGIN PRIVATE KEY-----\nfixture\n-----END PRIVATE KEY-----";
    if ($realKey) {
        $config = ABSPATH . 'audit2-openssl.cnf';
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n");
        $resource = openssl_pkey_new(['config' => $config, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        smartsync_assert_true($resource !== false && openssl_pkey_export($resource, $key, null, ['config' => $config]));
        unlink($config);
    }
    return ['type' => 'service_account', 'project_id' => 'fixture-project', 'client_email' => 'fixture@fixture.test',
        'private_key' => $key, 'token_uri' => 'https://oauth2.googleapis.com/token'];
}
function smartsync_audit2_legacy_credentials(bool $realKey = false): string {
    $path = wp_upload_dir()['basedir'] . '/audit2-legacy.json';
    file_put_contents($path, json_encode(smartsync_audit2_credentials($realKey)));
    return $path;
}
function smartsync_audit2_uploaded_selection(string $project): array {
    $path = ABSPATH . 'audit2-upload.json';
    file_put_contents($path, json_encode(array_replace(smartsync_audit2_credentials(), ['project_id' => $project])));
    $GLOBALS['smartsync_test_uploaded_files'][$path] = true;
    $_FILES = ['service_json' => ['name' => 'credentials.json', 'tmp_name' => $path, 'size' => filesize($path), 'error' => UPLOAD_ERR_OK]];
    return SMARLI_GoogleService::moveFileTemp(true);
}
function smartsync_audit2_google_config(callable $handler): SmartSyncProviderConfig {
    $config = smartsync_provider_config('google'); $config->region = 'US';
    $config->fakeGoogle = new \Google\Cloud\Storage\StorageClient(['projectId' => 'fixture-project', 'credentialsFetcher' => new \Google\Cloud\Core\AnonymousCredentials(), 'httpHandler' => $handler]);
    return $config;
}

smartsync_test('PRV-001 Bunny streams a 64 MiB file with bounded memory and closes the source', function () {
    $config = new SmartSyncAudit2StreamingConfig();
    $config->serviceProvider = 'bunny'; $config->bucket = 'fixture-zone'; $config->region = 'DE'; $config->bunnyStoragePassword = 'fixture-password';
    $path = wp_upload_dir()['basedir'] . '/large-audit2.bin';
    $file = fopen($path, 'wb'); ftruncate($file, 64 * 1024 * 1024); fclose($file);
    $body = null; $requests = 0;
    $config->streamClient = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create(function ($request, $options) use (&$body, &$requests) {
        $requests++; $body = $request->getBody();
        smartsync_assert_same('https://storage.bunnycdn.com/fixture-zone/media/large%20video.bin', (string) $request->getUri());
        smartsync_assert_same('fixture-password', $request->getHeaderLine('AccessKey'));
        smartsync_assert_same((string) (64 * 1024 * 1024), $request->getHeaderLine('Content-Length'));
        smartsync_assert_same(64 * 1024 * 1024, $body->getSize());
        smartsync_assert_same(false, $options['allow_redirects']);
        smartsync_assert_same("\0\0\0\0", $body->read(4));
        return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(201, [], '{"HttpCode":201}'));
    })]);
    $memory = memory_get_usage(true);
    $config->uploadObject('media/large video.bin', $path);
    smartsync_assert_same(1, $requests);
    smartsync_assert_true(memory_get_usage(true) - $memory < 8 * 1024 * 1024, 'Do not allocate a file-sized PHP string');
    smartsync_assert_true(!is_resource((new ReflectionProperty($body, 'stream'))->getValue($body)), 'Always close the original source handle');
});

smartsync_test('PRV-001 Bunny streaming rejects provider errors and sanitizes transport failures', function () {
    $config = new SmartSyncAudit2StreamingConfig();
    $config->serviceProvider = 'bunny'; $config->bucket = 'fixture-zone'; $config->region = 'DE'; $config->bunnyStoragePassword = 'fixture-secret';
    $path = wp_upload_dir()['basedir'] . '/large-audit2.bin';
    $file = fopen($path, 'wb'); ftruncate($file, 2 * 1024 * 1024); fclose($file);
    foreach ([403, 503, 302, 'transport', 'body'] as $failure) {
        $body = null;
        $config->streamClient = new \GuzzleHttp\Client(['handler' => function ($request) use ($failure, &$body) {
            $body = $request->getBody();
            if ($failure === 'transport') throw new RuntimeException('fixture-secret signed-url.example.test');
            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(is_int($failure) ? $failure : 200, [], '{"Success":false}'));
        }]);
        $error = smartsync_assert_throws(fn() => $config->uploadObject('large.bin', $path), RuntimeException::class);
        smartsync_assert_true(!str_contains($error->getMessage(), 'fixture-secret'));
        smartsync_assert_true(!is_resource((new ReflectionProperty($body, 'stream'))->getValue($body)));
    }
});

smartsync_test('PRV-002 Google migration preserves active legacy credentials when persistence fails', function () {
    $path = smartsync_audit2_legacy_credentials();
    update_option('smarl_settings', ['service_provider' => 'google', 'googleServicePath' => $path]);
    $GLOBALS['wpdb']->pdo->exec("CREATE TRIGGER fixture_reject_migration BEFORE UPDATE ON wp_options WHEN OLD.option_name='smarl_settings' BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
    $config = SMARL_CloudConfig::load();
    smartsync_assert_same($path, $config->googleServicePath);
    smartsync_assert_same($path, get_option('smarl_settings')['googleServicePath']);
    smartsync_assert_true(is_file($path));
    smartsync_assert_same(false, get_option('smarl_sync_lock'));
    smartsync_assert_same([], glob(SMARLI_GoogleService::getCredentialsDirectory() . '/smarl-*.json'));
});

smartsync_test('PRV-002 Google migration durably switches paths before deleting the readable source', function () {
    $path = smartsync_audit2_legacy_credentials();
    update_option('smarl_settings', ['service_provider' => 'google', 'googleServicePath' => $path]);
    $config = SMARL_CloudConfig::load();
    smartsync_assert_same($config->googleServicePath, get_option('smarl_settings')['googleServicePath']);
    smartsync_assert_true($path !== $config->googleServicePath && !is_file($path));
    smartsync_assert_same('fixture-project', SMARLI_GoogleService::getProjectId($config->googleServicePath));
    smartsync_assert_true(!str_contains(file_get_contents($config->googleServicePath), 'BEGIN PRIVATE KEY'));
    smartsync_assert_same(false, SMARLI_GoogleService::credentialsNeedMigration($config->googleServicePath));
});

smartsync_test('PRV-003 Google migration respects another configuration or worker lock', function () {
    $path = smartsync_audit2_legacy_credentials();
    update_option('smarl_settings', ['service_provider' => 'google', 'googleServicePath' => $path]);
    $lock = SMARL_Helper::acquireSyncLock();
    smartsync_assert_true(is_string($lock));
    $config = SMARL_CloudConfig::load();
    smartsync_assert_same($path, $config->googleServicePath);
    smartsync_assert_true(is_file($path));
    smartsync_assert_same($lock, get_option('smarl_sync_lock')['token']);
    SMARL_Helper::releaseSyncLock($lock);
    smartsync_assert_true(SMARL_CloudConfig::load()->googleServicePath !== $path);
});

smartsync_test('PRV-004 non-adjacent S3 continuation cycles fail after the first repeated token', function () {
    $config = smartsync_provider_config(); $calls = 0;
    $config->fakeS3 = smartsync_provider_s3(function () use (&$calls) {
        $calls++; if ($calls > 3) throw new LogicException('Listing would loop without cycle detection.');
        return ['Contents' => [], 'IsTruncated' => true, 'NextContinuationToken' => $calls % 2 ? 'page-A' : 'page-B'];
    });
    $error = smartsync_assert_throws(fn() => $config->listObjects(), RuntimeException::class);
    smartsync_assert_same('Invalid storage pagination response.', $error->getMessage());
    smartsync_assert_same(3, $calls);
});

smartsync_test('PRV-005 failed model option writes are errors while unchanged saves remain valid', function () {
    $config = smartsync_provider_config(); $config->save(); $config->save();
    $custom = new SMARL_CustomUrl('fixture-arn', 'cdn.example.test'); $custom->save(); $custom->save();
    foreach (['smarl_settings', SMARL_CustomUrl::$optionName] as $option) {
        $GLOBALS['smartsync_provider_failed_option'] = $option;
        try {
            if ($option === 'smarl_settings') { $config->iamSecret = 'new-fixture-secret'; smartsync_assert_throws(fn() => $config->save(), RuntimeException::class); }
            else { $custom->domain = 'new.example.test'; smartsync_assert_throws(fn() => $custom->save(), RuntimeException::class); }
        } finally { unset($GLOBALS['smartsync_provider_failed_option']); }
    }
    smartsync_assert_same('fixture-secret', get_option('smarl_settings')['iam_secret']);
    smartsync_assert_same('cdn.example.test', get_option(SMARL_CustomUrl::$optionName)['domain']);
});

smartsync_test('PRV-005 failed configuration persistence cannot delete still-active Google credentials', function () {
    $path = SMARLI_GoogleService::migrateCredentials(smartsync_audit2_legacy_credentials());
    $original = ['service_provider' => 'google', 'googleServicePath' => $path]; update_option('smarl_settings', $original);
    $config = smartsync_provider_config('cloudflare'); $config->fakeS3 = smartsync_provider_s3(fn() => []);
    $ajax = new SmartSyncProviderAjax(null); $ajax->nextConfig = $config;
    $_POST = ['service_provider' => 'cloudflare', 'bucket_type' => 'existing', 'bucket_name' => 'fixture-bucket',
        'iam_key' => 'fixture-key', 'iam_secret' => 'fixture-secret', 'bucket_region' => 'auto',
        'cloudflare_endpoint' => $config->cloudflareEndpoint, 'cloudflare_url' => 'public.example.test'];
    $GLOBALS['smartsync_provider_failed_option'] = 'smarl_settings';
    try { $response = smartsync_capture_json(fn() => $ajax->configureAws()); }
    finally { unset($GLOBALS['smartsync_provider_failed_option']); }
    smartsync_assert_same(false, $response['success']);
    smartsync_assert_same($original, get_option('smarl_settings'));
    smartsync_assert_true(is_file($path));
    smartsync_assert_same(false, get_option('smarl_sync_lock'));
});

smartsync_test('PRV-006 overlapping Google discoveries keep independently selected credentials alive', function () {
    $first = smartsync_audit2_uploaded_selection('project-a');
    $second = smartsync_audit2_uploaded_selection('project-b');
    smartsync_assert_true($first['selectionToken'] !== $second['selectionToken']);
    smartsync_assert_same('project-a', SMARLI_GoogleService::getProjectId(SMARLI_GoogleService::getTempPath($first['selectionToken'])));
    smartsync_assert_same('project-b', SMARLI_GoogleService::getProjectId(SMARLI_GoogleService::getTempPath($second['selectionToken'])));
    $config = smartsync_audit2_google_config(function ($request) {
        $body = str_ends_with($request->getUri()->getPath(), '/iam') ? ['version' => 1, 'bindings' => []] : ['name' => 'fixture-bucket'];
        return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($body));
    });
    $ajax = new SmartSyncProviderAjax(null); $ajax->nextConfig = $config;
    $_POST = ['service_provider' => 'google', 'bucket_type' => 'existing', 'bucket_name' => 'fixture-bucket', 'bucket_region' => 'US', 'google_selection_token' => $first['selectionToken']];
    $response = smartsync_capture_json(fn() => $ajax->configureAws());
    smartsync_assert_same(true, $response['success']);
    smartsync_assert_same($first['path'], get_option('smarl_settings')['googleServicePath']);
    smartsync_assert_true(!str_contains(json_encode($response), $first['path']));
    smartsync_assert_true(is_file($second['path']), 'A different valid discovery remains selectable');
});

smartsync_test('PRV-006 expired or missing Google selection cannot substitute the latest pending upload', function () {
    $selection = smartsync_audit2_uploaded_selection('project-a');
    $selections = get_transient('smarl_google_selections_' . get_current_user_id());
    $selections[$selection['selectionToken']]['expires'] = time() - 1;
    set_transient('smarl_google_selections_' . get_current_user_id(), $selections);
    smartsync_audit2_uploaded_selection('project-b');
    $config = smartsync_audit2_google_config(function () { throw new LogicException('Do not contact Google for invalid selections'); });
    $ajax = new SmartSyncProviderAjax(null); $ajax->nextConfig = $config;
    foreach ([$selection['selectionToken'], '', '../bad-token'] as $token) {
        $_POST = ['service_provider' => 'google', 'bucket_type' => 'existing', 'bucket_name' => 'fixture-bucket', 'bucket_region' => 'US', 'google_selection_token' => $token];
        smartsync_assert_same(false, smartsync_capture_json(fn() => $ajax->configureAws())['success']);
        smartsync_assert_same(false, get_option('smarl_settings'));
    }
});

smartsync_test('PRV-006 saved Google account reuse ignores an unrelated newer pending upload', function () {
    $active = smartsync_audit2_uploaded_selection('active-project');
    $config = smartsync_audit2_google_config(fn() => new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"name":"fixture-bucket"}'));
    $config->googleServicePath = $active['path']; $config->url = 'storage.googleapis.com/fixture-bucket'; $config->save();
    smartsync_audit2_uploaded_selection('pending-project');
    $ajax = new SmartSyncProviderAjax($config); $ajax->nextConfig = clone $config;
    $_POST = ['service_provider' => 'google', 'bucket_type' => 'existing', 'bucket_name' => 'fixture-bucket', 'bucket_region' => 'US'];
    smartsync_assert_same(true, smartsync_capture_json(fn() => $ajax->configureAws())['success']);
    smartsync_assert_same($active['path'], get_option('smarl_settings')['googleServicePath']);
});

smartsync_test('PRV-007 Google credential repair verifies bucket access before replacing working credentials', function () {
    $active = SMARLI_GoogleService::migrateCredentials(smartsync_audit2_legacy_credentials());
    $calls = [];
    $config = smartsync_audit2_google_config(function ($request) use (&$calls) {
        $calls[] = [$request->getMethod(), $request->getUri()->getPath()];
        throw new \GuzzleHttp\Exception\ClientException('Forbidden', $request, new \GuzzleHttp\Psr7\Response(403, ['Content-Type' => 'application/json'], '{"error":{"code":403,"message":"Forbidden"}}'));
    });
    $config->googleServicePath = $active; $config->url = 'storage.googleapis.com/fixture-bucket'; $config->save();
    $selected = smartsync_audit2_uploaded_selection('replacement-project');
    $ajax = new SmartSyncProviderAjax($config); $ajax->nextConfig = clone $config;
    $_POST = ['service_provider' => 'google', 'bucket_type' => 'existing', 'bucket_name' => 'fixture-bucket', 'bucket_region' => 'US', 'google_selection_token' => $selected['selectionToken']];
    smartsync_assert_same(false, smartsync_capture_json(fn() => $ajax->configureAws())['success']);
    smartsync_assert_same($active, get_option('smarl_settings')['googleServicePath']);
    smartsync_assert_true(is_file($active));
    smartsync_assert_same([['GET', '/storage/v1/b/fixture-bucket']], $calls);
});

smartsync_test('provider audit provisions CloudFront through actual SDK command validation and preserves unrelated policy', function () {
    $config = new SmartSyncAudit2CloudFrontConfig(); $calls = [];
    $config->fakeS3 = smartsync_provider_s3(function ($command) use (&$calls) {
        $calls[] = $command->getName();
        if ($command->getName() === 'GetBucketPolicy') return ['Policy' => '{"Version":"2012-10-17","Statement":[{"Sid":"Unrelated","Effect":"Deny","Action":"s3:DeleteObject","Resource":"arn:aws:s3:::fixture-bucket/*","Principal":"*"}]}'];
        if ($command->getName() === 'PutBucketPolicy') {
            $policy = json_decode($command['Policy'], true);
            smartsync_assert_same('Unrelated', $policy['Statement'][0]['Sid']);
            smartsync_assert_same('arn:aws:cloudfront::123456789012:distribution/FIXTURE123', $policy['Statement'][1]['Condition']['StringEquals']['AWS:SourceArn']);
        }
        return ['@metadata' => ['statusCode' => 200]];
    });
    $options = ['region' => 'us-east-1', 'version' => 'latest', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture']];
    $config->fakeCloudFront = new \Aws\CloudFront\CloudFrontClient($options + ['handler' => function ($command) use (&$calls) {
        $calls[] = $command->getName();
        $data = $command->getName() === 'CreateOriginAccessControl' ? ['OriginAccessControl' => ['Id' => 'FIXTUREOAC']]
            : ['Distribution' => ['Id' => 'FIXTURE123', 'DomainName' => 'fixture.cloudfront.net']];
        return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result($data));
    }]);
    $config->fakeSts = new \Aws\Sts\StsClient($options + ['handler' => function ($command) use (&$calls) {
        $calls[] = $command->getName(); return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result(['Account' => '123456789012']));
    }]);
    $ajax = new SmartSyncProviderAjax(null); $ajax->nextConfig = $config;
    $_POST = ['service_provider' => 'aws', 'bucket_type' => 'existing', 'bucket_name' => 'fixture-bucket', 'bucket_region' => 'us-east-1', 'iam_key' => 'fixture-key', 'iam_secret' => 'fixture-secret', 'use_cloudfront' => 'true'];
    smartsync_assert_same(true, smartsync_capture_json(fn() => $ajax->configureAws())['success']);
    smartsync_assert_same('FIXTURE123', get_option('smarl_settings')['distribution_id']);
    smartsync_assert_same(['HeadBucket', 'GetCallerIdentity', 'GetBucketPolicy', 'CreateOriginAccessControl', 'CreateDistribution', 'GetBucketPolicy', 'PutBucketPolicy', 'PutPublicAccessBlock'], $calls);
});

smartsync_test('PRV-008 failed CloudFront policy setup retains resource IDs and retries without duplicate provisioning', function () {
    $config = new SmartSyncAudit2CloudFrontConfig(); $calls = []; $failPolicy = true; $oac = $distribution = [];
    $original = smartsync_provider_config()->toArray(); $original['bucket'] = 'previous-working-bucket';
    update_option('smarl_settings', $original);
    $config->fakeS3 = smartsync_provider_s3(function ($command) use (&$calls, &$failPolicy) {
        $calls[] = $command->getName();
        if ($command->getName() === 'GetBucketPolicy') return ['Policy' => '{"Version":"2012-10-17","Statement":[]}'];
        if ($command->getName() === 'PutBucketPolicy' && $failPolicy) throw new RuntimeException('Fixture policy permission failure after resource creation');
        return [];
    });
    $options = ['region' => 'us-east-1', 'version' => 'latest', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture']];
    $config->fakeCloudFront = new \Aws\CloudFront\CloudFrontClient($options + ['handler' => function ($command) use (&$calls, &$oac, &$distribution) {
        $calls[] = $command->getName();
        if ($command->getName() === 'CreateOriginAccessControl') $oac = ['Id' => 'FIXTUREOAC', 'OriginAccessControlConfig' => $command['OriginAccessControlConfig']];
        if ($command->getName() === 'CreateDistribution') $distribution = ['Id' => 'FIXTURE123', 'DomainName' => 'fixture.cloudfront.net', 'DistributionConfig' => $command['DistributionConfig']];
        return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result(str_contains($command->getName(), 'OriginAccessControl') ? ['OriginAccessControl' => $oac] : ['Distribution' => $distribution]));
    }]);
    $config->fakeSts = new \Aws\Sts\StsClient($options + ['handler' => fn() => \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result(['Account' => '123456789012']))]);
    $ajax = new SmartSyncProviderAjax(null); $ajax->nextConfig = $config;
    $_POST = ['service_provider' => 'aws', 'bucket_type' => 'existing', 'bucket_name' => 'fixture-bucket', 'bucket_region' => 'us-east-1', 'iam_key' => 'fixture-key', 'iam_secret' => 'fixture-secret', 'use_cloudfront' => 'true'];
    smartsync_assert_same(false, smartsync_capture_json(fn() => $ajax->configureAws())['success']);
    smartsync_assert_same($original, get_option('smarl_settings'));
    smartsync_assert_true(in_array('CreateDistribution', $calls, true));
    $pending = array_values(get_option('smarl_cloudfront_pending'))[0];
    smartsync_assert_same('FIXTUREOAC', $pending['oac_id']);
    smartsync_assert_same('FIXTURE123', $pending['distribution_id']);
    smartsync_assert_true(!str_contains(json_encode($pending), 'fixture-secret'));
    smartsync_assert_same(false, get_option('smarl_sync_lock'));
    $failPolicy = false;
    smartsync_assert_same(true, smartsync_capture_json(fn() => $ajax->configureAws())['success']);
    smartsync_assert_same(1, count(array_filter($calls, fn($name) => $name === 'CreateOriginAccessControl')));
    smartsync_assert_same(1, count(array_filter($calls, fn($name) => $name === 'CreateDistribution')));
    smartsync_assert_true(in_array('GetOriginAccessControl', $calls, true) && in_array('GetDistribution', $calls, true));
    smartsync_assert_same('FIXTURE123', get_option('smarl_settings')['distribution_id']);
});

smartsync_test('PRV-008 CloudFront checkpoint failures abort creation or report already-created resource IDs', function () {
    foreach (['before', 'after-oac', 'preflight-policy', 'preflight-account'] as $failure) {
        delete_option('smarl_cloudfront_pending');
        $config = new SmartSyncAudit2CloudFrontConfig(); $calls = [];
        $config->fakeS3 = smartsync_provider_s3(function ($command) use ($failure) {
            if ($failure === 'preflight-policy' && $command->getName() === 'GetBucketPolicy') throw new RuntimeException('Fixture denied policy preflight');
            return ['Policy' => '{"Statement":[]}'];
        });
        $options = ['region' => 'us-east-1', 'version' => 'latest', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture']];
        $config->fakeCloudFront = new \Aws\CloudFront\CloudFrontClient($options + ['handler' => function ($command) use (&$calls) {
            $calls[] = $command->getName();
            return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result(['OriginAccessControl' => ['Id' => 'FIXTUREOAC']]));
        }]);
        $config->fakeSts = new \Aws\Sts\StsClient($options + ['handler' => function () use ($failure) {
            if ($failure === 'preflight-account') throw new RuntimeException('Fixture denied account preflight');
            return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result(['Account' => '123456789012']));
        }]);
        $ajax = new SmartSyncProviderAjax(null); $ajax->nextConfig = $config;
        $_POST = ['service_provider' => 'aws', 'bucket_type' => 'existing', 'bucket_name' => 'fixture-bucket', 'bucket_region' => 'us-east-1', 'iam_key' => 'fixture-key', 'iam_secret' => 'fixture-secret', 'use_cloudfront' => 'true'];
        if ($failure === 'before') $GLOBALS['smartsync_provider_failed_option'] = 'smarl_cloudfront_pending';
        elseif ($failure === 'after-oac') $GLOBALS['smartsync_provider_checkpoint_writes'] = 0;
        try { $response = smartsync_capture_json(fn() => $ajax->configureAws()); }
        finally { unset($GLOBALS['smartsync_provider_failed_option'], $GLOBALS['smartsync_provider_checkpoint_writes']); }
        smartsync_assert_same(false, $response['success']);
        smartsync_assert_same(false, get_option('smarl_settings'));
        smartsync_assert_same($failure === 'after-oac' ? ['CreateOriginAccessControl'] : [], $calls);
        if ($failure === 'after-oac') smartsync_assert_true(str_contains($response['data'], 'FIXTUREOAC'));
    }
});

smartsync_test('PRV-009 CloudFront OAC names fit the official limit for the longest valid S3 bucket', function () {
    $bucket = str_repeat('a', 63);
    $first = \SmartSyncS3\Helpers\SMARL_AwsHelper::generateOACArray($bucket);
    $second = \SmartSyncS3\Helpers\SMARL_AwsHelper::generateOACArray($bucket);
    smartsync_assert_same(64, strlen($first['OriginAccessControlConfig']['Name']));
    smartsync_assert_true($first['OriginAccessControlConfig']['Name'] !== $second['OriginAccessControlConfig']['Name']);
    $config = SMARL_CloudConfig::fromArray(['iam_key' => 'fixture', 'iam_secret' => 'fixture']);
    $request = \Aws\serialize($config->getCloudfrontClient()->getCommand('CreateOriginAccessControl', $first));
    smartsync_assert_true(str_contains((string) $request->getBody(), $first['OriginAccessControlConfig']['Name']));
});

smartsync_test('provider audit constructs real AWS and Google SDK factories without making requests', function () {
    $config = smartsync_provider_config();
    $config = SMARL_CloudConfig::fromArray($config->toArray());
    smartsync_assert_same('us-east-1', $config->getCloudfrontClient()->getRegion());
    smartsync_assert_same('us-east-1', $config->getAcmClient()->getRegion());
    smartsync_assert_same('eu-west-1', $config->getStsClient('eu-west-1')->getRegion());
    smartsync_assert_same('us-east-1', $config->getStsClient()->getRegion());
    $path = smartsync_audit2_legacy_credentials(true);
    $service = new SMARLI_GoogleService($path);
    smartsync_assert_true($service->storage instanceof \Google\Cloud\Storage\StorageClient);
    $config->serviceProvider = 'google'; $config->googleServicePath = $path;
    $factory = new ReflectionMethod(SMARL_CloudConfig::class, 'getGoogleStorageClient');
    smartsync_assert_true($factory->invoke($config) instanceof \Google\Cloud\Storage\StorageClient);
    $factory = new ReflectionMethod(SMARL_CloudConfig::class, 'getBunnyStreamClient');
    if (extension_loaded('curl')) smartsync_assert_true($factory->invoke($config) instanceof \GuzzleHttp\ClientInterface);
    else smartsync_assert_throws(fn() => $factory->invoke($config), SMARL_ProviderException::class);
    smartsync_assert_same([], $GLOBALS['smartsync_test_http_requests']);
});

smartsync_test('provider audit executes the Google bucket list SDK iterator and location metadata', function () {
    $service = new SMARLI_GoogleService(smartsync_audit2_legacy_credentials(true));
    $requests = [];
    $service->storage = new \Google\Cloud\Storage\StorageClient(['projectId' => 'fixture-project', 'credentialsFetcher' => new \Google\Cloud\Core\AnonymousCredentials(),
        'httpHandler' => function ($request) use (&$requests) {
            $requests[] = (string) $request->getUri();
            parse_str($request->getUri()->getQuery(), $query);
            $next = isset($query['pageToken']);
            return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
                'items' => [['name' => $next ? 'second-bucket' : 'first-bucket', 'location' => $next ? 'EU' : 'US']],
            ] + ($next ? [] : ['nextPageToken' => 'page2'])));
        }]);
    smartsync_assert_same([['name' => 'first-bucket', 'region' => 'US'], ['name' => 'second-bucket', 'region' => 'EU']], $service->getBuckets());
    smartsync_assert_same(2, count($requests));
});

smartsync_test('provider audit executes legacy Google public bucket creation through real SDK serialization', function () {
    $service = new SMARLI_GoogleService(smartsync_audit2_legacy_credentials(true)); $requests = [];
    $service->storage = new \Google\Cloud\Storage\StorageClient(['projectId' => 'fixture-project', 'credentialsFetcher' => new \Google\Cloud\Core\AnonymousCredentials(),
        'httpHandler' => function ($request) use (&$requests) {
            $requests[] = [$request->getMethod(), (string) $request->getUri()];
            if ($request->getMethod() === 'POST') {
                $body = json_decode((string) $request->getBody(), true);
                smartsync_assert_same('EU', $body['location']);
                smartsync_assert_same(true, $body['iamConfiguration']['uniformBucketLevelAccess']['enabled']);
                return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"name":"legacy-bucket"}');
            }
            if ($request->getMethod() === 'GET') {
                parse_str($request->getUri()->getQuery(), $query); smartsync_assert_same('3', $query['optionsRequestedPolicyVersion']);
                return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"version":3,"etag":"fixture-etag","bindings":[]}');
            }
            $body = json_decode((string) $request->getBody(), true);
            smartsync_assert_same(['allUsers'], $body['bindings'][0]['members']);
            smartsync_assert_same('fixture-etag', $body['etag']);
            return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($body));
        }]);
    smartsync_assert_same(true, $service->createPublicBucket('legacy-bucket', 'EU'));
    smartsync_assert_same(['POST', 'GET', 'PUT'], array_column($requests, 0));
});

smartsync_test('provider audit covers legacy wrappers without changing the active wizard routing', function () {
    SMARL_CloudConfig::init(); smartsync_assert_same([], get_option('smarl_settings'));
    $path = wp_upload_dir()['basedir'] . '/legacy.txt'; file_put_contents($path, 'fixture bytes');
    $config = smartsync_provider_config('digitalocean'); $calls = [];
    $config->fakeS3 = smartsync_provider_s3(function ($command) use (&$calls) { $calls[] = $command->getName(); return []; });
    smartsync_assert_same(['success' => true, 'code' => 200], $config->createDigitalOceanSpace('fixture-space'));
    $config->putS3Object('legacy.txt', $path);
    smartsync_assert_same(['CreateBucket', 'PutObject'], $calls);
    $config->fakeS3 = smartsync_provider_s3(function () { throw new RuntimeException('fixture-secret'); });
    smartsync_assert_same(false, $config->createDigitalOceanSpace('fixture-space')['success']);
    smartsync_assert_throws(fn() => SMARLI_WasabiBucketManager::createBucket($config, 'fixture-bucket'), InvalidArgumentException::class);
    $config->serviceProvider = 'wasabi';
    smartsync_assert_same(true, SMARLI_WasabiBucketManager::createBucket($config, 'fixture-bucket')['error']);
    $config->fakeS3 = smartsync_provider_s3(fn() => ['@metadata' => ['statusCode' => 200]]);
    smartsync_assert_same(false, SMARLI_WasabiBucketManager::createBucket($config, 'fixture-bucket')['error']);
    SMARL_CloudConfig::delete(); smartsync_assert_same(false, get_option('smarl_settings'));
});
}
