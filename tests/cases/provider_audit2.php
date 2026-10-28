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
