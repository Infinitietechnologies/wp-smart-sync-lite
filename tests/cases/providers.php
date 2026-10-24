<?php
namespace SmartSyncS3\Helpers {
    function dns_get_record($domain, $type) { return $GLOBALS['smartsync_test_dns'][$domain] ?? []; }
}
namespace SmartSyncS3\Services {
    function is_uploaded_file($path) { return !empty($GLOBALS['smartsync_test_uploaded_files'][$path]) && \is_file($path); }
}
namespace {
if (!function_exists('wp_salt')) { function wp_salt($scheme = 'auth') { return 'fixture-only-wordpress-authentication-salt'; } }
use Aws\Acm\AcmClient;
use Aws\CloudFront\CloudFrontClient;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Google\Cloud\Core\AnonymousCredentials;
use Google\Cloud\Storage\StorageClient;
use GuzzleHttp\Psr7\Response;
use SmartSyncS3\Ajax\SMARL_AwsConfigAjax;
use SmartSyncS3\Helpers\SMARL_AwsHelper;
use SmartSyncS3\Models\SMARL_CloudConfig;
use SmartSyncS3\Models\SMARL_CustomUrl;
use SmartSyncS3\Services\SMARLI_GoogleService;

class SmartSyncProviderConfig extends SMARL_CloudConfig
{
    public ?S3Client $fakeS3 = null;
    public ?AcmClient $fakeAcm = null;
    public ?CloudFrontClient $fakeCloudFront = null;
    public ?StorageClient $fakeGoogle = null;
    public function getS3Client(string $region = ''): ?S3Client { return $this->fakeS3 ?? parent::getS3Client($region); }
    public function getAcmClient(): AcmClient { return $this->fakeAcm ?? parent::getAcmClient(); }
    public function getCloudfrontClient(): CloudFrontClient { return $this->fakeCloudFront ?? parent::getCloudfrontClient(); }
    protected function getGoogleStorageClient(): StorageClient { return $this->fakeGoogle ?? parent::getGoogleStorageClient(); }
}
class SmartSyncProviderAjax extends SMARL_AwsConfigAjax
{
    public SMARL_CloudConfig $nextConfig;
    protected function newConfiguration(): SMARL_CloudConfig { return $this->nextConfig; }
}
function smartsync_provider_config(string $provider = 'aws'): SmartSyncProviderConfig {
    $config = new SmartSyncProviderConfig();
    $config->serviceProvider = $provider; $config->bucket = 'fixture-bucket'; $config->region = 'us-east-1';
    $config->iamKey = 'fixture-access'; $config->iamSecret = 'fixture-secret'; $config->url = 'cdn.example.test';
    $config->cloudflareEndpoint = 'https://' . str_repeat('a', 32) . '.r2.cloudflarestorage.com';
    $config->bunnyStoragePassword = 'fixture-storage-password'; $config->customUrl = new SMARL_CustomUrl();
    return $config;
}
function smartsync_provider_s3(callable $handler): S3Client {
    return new S3Client(['region' => 'us-east-1', 'version' => 'latest', 'credentials' => ['key' => 'fixture-access', 'secret' => 'fixture-secret'],
        'handler' => function ($command, $request) use ($handler) { return \GuzzleHttp\Promise\Create::promiseFor(new Result($handler($command, $request))); }]);
}

smartsync_test('cloud configuration initializes every persisted field and round trips legacy Bunny secrets', function () {
    $config = new SMARL_CloudConfig();
    smartsync_assert_same('', $config->toArray()['bucket']);
    smartsync_assert_same(false, $config->isConfigured());
    $config = SMARL_CloudConfig::fromArray(['service_provider' => 'bunny', 'iam_secret' => 'account|zone-secret', 'use_cdn_for_media' => 'false', 'mediaAutoDelete' => 'true']);
    smartsync_assert_same('account', $config->iamSecret);
    smartsync_assert_same('zone-secret', $config->bunnyStoragePassword);
    smartsync_assert_same(false, $config->useCdnForMedia);
    smartsync_assert_same(true, $config->mediaAutoDelete);
    $config->save();
    smartsync_assert_same($config->toArray(), SMARL_CloudConfig::load()->toArray());
});

smartsync_test('malformed persisted configuration and custom URL options have safe defaults', function () {
    update_option('smarl_settings', 'invalid'); update_option('custom_url_option', false);
    smartsync_assert_same('', SMARL_CloudConfig::load()->bucket);
    smartsync_assert_same([], SMARL_CustomUrl::load()->records);
    smartsync_assert_same('', SMARL_CustomUrl::fromArray(['certificate_arn' => [], 'domain' => [], 'records' => 'bad'])->domain);
});

smartsync_test('cloud configuration reload discards stale positive and negative option caches', function () {
    $config = smartsync_provider_config(); $config->save();
    wp_cache_set('smarl_settings', ['bucket' => 'outdated-bucket'], 'options');
    wp_cache_set('notoptions', ['smarl_settings' => true], 'options');
    smartsync_assert_same('fixture-bucket', SMARL_CloudConfig::load()->bucket);
    smartsync_assert_same(false, wp_cache_get('smarl_settings', 'options')); smartsync_assert_same(false, wp_cache_get('notoptions', 'options'));
});

smartsync_test('public URL normalization preserves bucket prefixes and refuses malformed bases', function () {
    smartsync_assert_same('https://storage.googleapis.com/fixture-bucket', SMARL_CloudConfig::normalizePublicBaseUrl('https://storage.googleapis.com/fixture-bucket/'));
    smartsync_assert_same('https://cdn.example.test/prefix', SMARL_CloudConfig::normalizePublicBaseUrl('cdn.example.test/prefix/'));
    foreach (['', 'javascript:alert(1)', 'https://user:secret@cdn.example.test', 'https://cdn.example.test/?x=1', 'https://cdn.example.test/#x', 'https://127.0.0.1', 'https://cdn.example.test\\evil'] as $url) {
        smartsync_assert_throws(fn() => SMARL_CloudConfig::normalizePublicBaseUrl($url), InvalidArgumentException::class);
    }
});

smartsync_test('Cloudflare endpoint validation restricts API credentials to the R2 account endpoint', function () {
    $endpoint = 'https://' . str_repeat('a', 32) . '.r2.cloudflarestorage.com';
    smartsync_assert_same($endpoint, SMARL_CloudConfig::validateCloudflareEndpoint($endpoint . '/'));
    foreach (['http://example.com', 'https://example.com', $endpoint . '/bucket', $endpoint . '?q=1', $endpoint . ':443'] as $url) smartsync_assert_throws(fn() => SMARL_CloudConfig::validateCloudflareEndpoint($url));
});

smartsync_test('all storage operations reject traversal keys before touching a provider', function () {
    $config = smartsync_provider_config();
    foreach (['../secret', '/secret', 'a/../../secret', 'a\\secret', 'C:/secret', "a\0b", 'a/./b'] as $key) {
        smartsync_assert_throws(fn() => $config->deleteObject($key), InvalidArgumentException::class);
    }
    smartsync_assert_same('wp-content/uploads/photo.jpg', SMARL_CloudConfig::validateObjectKey('wp-content/uploads/photo.jpg'));
});

smartsync_test('S3 provider endpoint selection includes regional Wasabi and Cloudflare auto', function () {
    $config = smartsync_provider_config('wasabi'); $config->region = 'eu-central-1';
    smartsync_assert_same('https://s3.eu-central-1.wasabisys.com', (string) $config->getS3Client()->getEndpoint());
    $config->serviceProvider = 'digitalocean'; $config->region = 'sgp1';
    smartsync_assert_same('https://sgp1.digitaloceanspaces.com', (string) $config->getS3Client()->getEndpoint());
    $config->serviceProvider = 'cloudflare';
    smartsync_assert_same('auto', $config->getS3Client()->getRegion());
    $config->serviceProvider = 'unsupported'; smartsync_assert_throws(fn() => $config->getS3Client());
});

smartsync_test('S3 upload ACLs are only sent to DigitalOcean and Wasabi', function () {
    $path = ABSPATH . 'provider-upload.txt'; file_put_contents($path, 'fixture');
    foreach (['aws', 'digitalocean', 'wasabi', 'cloudflare'] as $provider) {
        $commands = []; $config = smartsync_provider_config($provider);
        $config->fakeS3 = smartsync_provider_s3(function ($command) use (&$commands) { $commands[] = $command->toArray(); return []; });
        $config->uploadObject('wp-content/uploads/fixture.txt', $path);
        smartsync_assert_same(in_array($provider, ['digitalocean', 'wasabi'], true), isset($commands[0]['ACL']));
        smartsync_assert_same('wp-content/uploads/fixture.txt', $commands[0]['Key']);
    }
    unlink($path);
});

smartsync_test('S3 listing handles empty pages and follows continuation tokens exactly once', function () {
    $calls = []; $config = smartsync_provider_config();
    $config->fakeS3 = smartsync_provider_s3(function ($command) use (&$calls) {
        $calls[] = $command->toArray();
        return count($calls) === 1 ? ['IsTruncated' => true, 'NextContinuationToken' => 'page-two']
            : ['Contents' => [['Key' => 'nested/image.jpg', 'Size' => 7]], 'IsTruncated' => false];
    });
    smartsync_assert_same([['key' => 'nested/image.jpg', 'size' => 7]], $config->listObjects());
    smartsync_assert_same('page-two', $calls[1]['ContinuationToken']);
    $config->fakeS3 = smartsync_provider_s3(fn() => ['IsTruncated' => true]);
    smartsync_assert_throws(fn() => $config->listObjects(), RuntimeException::class);
});

smartsync_test('S3 restore and deletion preserve exact cloud object keys', function () {
    $calls = []; $config = smartsync_provider_config();
    $config->fakeS3 = smartsync_provider_s3(function ($command) use (&$calls) { $calls[] = [$command->getName(), $command->toArray()]; return []; });
    $config->downloadObject('nested/image.jpg', ABSPATH . 'image.jpg');
    $config->deleteObject('nested/image.jpg');
    smartsync_assert_same('GetObject', $calls[0][0]); smartsync_assert_same('nested/image.jpg', $calls[0][1]['Key']);
    smartsync_assert_same('DeleteObject', $calls[1][0]);
});

smartsync_test('Bunny HTTP uploads encode paths and failures cannot count as success', function () {
    $config = smartsync_provider_config('bunny'); $config->region = 'SG';
    $path = ABSPATH . 'bunny-fixture.txt'; file_put_contents($path, 'fixture-content');
    $GLOBALS['smartsync_test_http'] = function ($url, $args) {
        smartsync_assert_same('https://sg.storage.bunnycdn.com/fixture-bucket/nested/a%20b.txt', $url);
        smartsync_assert_same('fixture-storage-password', $args['headers']['AccessKey']);
        smartsync_assert_same('fixture-content', $args['body']); smartsync_assert_same(0, $args['redirection']);
        return ['response' => ['code' => 201], 'body' => ''];
    };
    $config->uploadObject('nested/a b.txt', $path);
    $GLOBALS['smartsync_test_http'] = fn() => ['response' => ['code' => 403], 'body' => '{"Success":false}'];
    smartsync_assert_throws(fn() => $config->uploadObject('nested/a b.txt', $path), RuntimeException::class);
    $GLOBALS['smartsync_test_http'] = fn() => ['response' => ['code' => 200], 'body' => '{"Success":false}'];
    smartsync_assert_throws(fn() => $config->uploadObject('nested/a b.txt', $path), RuntimeException::class);
    $GLOBALS['smartsync_test_http'] = fn() => ['response' => ['code' => 404], 'body' => ''];
    $config->deleteObject('missing.txt');
    unlink($path);
});

smartsync_test('Bunny lists nested files using storage zone credentials and streams downloads', function () {
    $config = smartsync_provider_config('bunny'); $config->region = 'DE';
    $GLOBALS['smartsync_test_http'] = function ($url, $args) {
        $items = str_ends_with($url, '/nested/') ? [['ObjectName' => 'photo.jpg', 'IsDirectory' => false, 'Length' => 12]] : [['ObjectName' => 'nested', 'IsDirectory' => true]];
        if (!empty($args['stream'])) { file_put_contents($args['filename'], 'download'); return ['response' => ['code' => 200], 'body' => '']; }
        return ['response' => ['code' => 200], 'body' => json_encode($items)];
    };
    smartsync_assert_same([['key' => 'nested/photo.jpg', 'size' => 12]], $config->listObjects());
    $path = ABSPATH . 'bunny-download.txt'; $config->downloadObject('nested/photo.jpg', $path);
    smartsync_assert_same('download', file_get_contents($path)); unlink($path);
    $GLOBALS['smartsync_test_http'] = fn() => ['response' => ['code' => 200], 'body' => '[{"ObjectName":"..","IsDirectory":true}]'];
    smartsync_assert_throws(fn() => $config->listObjects());
});

smartsync_test('Google object upload and listing work with uniform bucket access and no per-object ACL', function () {
    $calls = []; $config = smartsync_provider_config('google');
    $path = ABSPATH . 'google-fixture.txt'; file_put_contents($path, 'fixture-google'); $config->googleServicePath = $path;
    $config->fakeGoogle = new StorageClient(['projectId' => 'fixture-project', 'credentialsFetcher' => new AnonymousCredentials(), 'httpHandler' => function ($request, $options = []) use (&$calls) {
        $calls[] = [(string) $request->getUri(), $request->getMethod(), (string) $request->getBody()];
        if ($request->getMethod() === 'GET') return new Response(200, ['Content-Type' => 'application/json'], '{"items":[{"name":"nested/photo.jpg","size":"12","generation":"1"}]}');
        return new Response(200, ['Content-Type' => 'application/json'], '{"name":"nested/photo.jpg","size":"12","generation":"1"}');
    }]);
    $config->uploadObject('nested/photo.jpg', $path);
    smartsync_assert_true(!str_contains($calls[0][0], 'predefinedAcl'));
    smartsync_assert_same([['key' => 'nested/photo.jpg', 'size' => 12]], $config->listObjects());
    unlink($path);
});

smartsync_test('every provider configuration endpoint rejects insufficient capabilities and invalid nonces', function () {
    foreach (['configureAws', 'listS3Buckets', 'generateSslCertificate', 'checkDnsRecord', 'updateCloudFrontAlternateDomain'] as $method) {
        $handler = new SMARL_AwsConfigAjax(new SMARL_CloudConfig());
        $GLOBALS['smartsync_test_can_manage'] = false;
        $response = smartsync_capture_json(fn() => $handler->$method()); smartsync_assert_same(403, $response['status']);
        $GLOBALS['smartsync_test_can_manage'] = true; $GLOBALS['smartsync_test_valid_nonce'] = false;
        $response = smartsync_capture_json(fn() => $handler->$method()); smartsync_assert_same(403, $response['status']);
        $GLOBALS['smartsync_test_valid_nonce'] = true;
    }
    smartsync_assert_same([], $GLOBALS['smartsync_test_http_requests']);
});

smartsync_test('AWS bucket choices normalize the default and legacy EU region without leaking secrets', function () {
    $config = smartsync_provider_config();
    $config->fakeS3 = smartsync_provider_s3(function ($command) {
        if ($command->getName() === 'ListBuckets') return ['Buckets' => [['Name' => 'fixture-east'], ['Name' => 'fixture-europe']]];
        return ['LocationConstraint' => $command['Bucket'] === 'fixture-east' ? null : 'EU'];
    });
    $ajax = new SmartSyncProviderAjax(new SMARL_CloudConfig()); $ajax->nextConfig = $config;
    $_POST = ['service_provider' => 'aws', 'iam_key' => 'fixture-access', 'iam_secret' => 'fixture-secret'];
    $response = smartsync_capture_json(fn() => $ajax->listS3Buckets());
    smartsync_assert_same([['name' => 'fixture-east', 'region' => 'us-east-1'], ['name' => 'fixture-europe', 'region' => 'eu-west-1']], $response['data']['buckets']);
});

smartsync_test('Bunny bucket choices expose no passwords and bind server-side secrets to the account key', function () {
    $GLOBALS['smartsync_test_http'] = fn() => ['response' => ['code' => 200], 'body' => json_encode([['Name' => 'fixture-zone', 'Region' => '', 'Password' => 'private-zone-password', 'PullZones' => [['Hostnames' => [['Value' => 'fixture.b-cdn.net']]]]]])];
    $_POST = ['service_provider' => 'bunny', 'iam_secret' => 'private-account-key'];
    $ajax = new SMARL_AwsConfigAjax(new SMARL_CloudConfig());
    $response = smartsync_capture_json(fn() => $ajax->listS3Buckets());
    smartsync_assert_same([['name' => 'fixture-zone', 'region' => 'DE', 'pullzones' => ['fixture.b-cdn.net']]], $response['data']['buckets']);
    smartsync_assert_true(!str_contains(json_encode($response), 'password'));
    $_POST = ['service_provider' => 'bunny', 'bucket_type' => 'existing', 'bucket_name' => 'fixture-zone', 'bucket_region' => 'DE', 'iam_key' => 'fixture.b-cdn.net', 'iam_secret' => 'wrong-key'];
    smartsync_assert_same(false, smartsync_capture_json(fn() => $ajax->configureAws())['success']);
    $_POST['iam_secret'] = 'private-account-key';
    smartsync_assert_same(true, smartsync_capture_json(fn() => $ajax->configureAws())['success']);
    $saved = get_option('smarl_settings');
    smartsync_assert_same('private-zone-password', $saved['bunnyStoragePassword']);
    smartsync_assert_same('private-account-key', $saved['iam_secret']);
    smartsync_assert_same('fixture.b-cdn.net', $saved['url']);
});

smartsync_test('configuration validates provider and CloudFront restrictions before touching storage', function () {
    foreach (['unknown', 'digitalocean', 'google', 'cloudflare', 'bunny'] as $provider) {
        $_POST = ['service_provider' => $provider, 'bucket_type' => 'existing', 'bucket_name' => 'fixture-bucket', 'bucket_region' => 'us-east-1', 'iam_key' => 'fixture-access', 'iam_secret' => 'fixture-secret', 'use_cloudfront' => 'true'];
        $response = smartsync_capture_json(fn() => (new SMARL_AwsConfigAjax(null))->configureAws());
        smartsync_assert_same(false, $response['success']);
        smartsync_assert_same(false, get_option('smarl_settings'));
    }
});

smartsync_test('Cloudflare settings persist API endpoint and public URL on the actual saved model', function () {
    $config = smartsync_provider_config('cloudflare');
    $config->fakeS3 = smartsync_provider_s3(fn() => []);
    $ajax = new SmartSyncProviderAjax(new SMARL_CloudConfig()); $ajax->nextConfig = $config;
    $_POST = ['service_provider' => 'cloudflare', 'bucket_type' => 'existing', 'bucket_name' => 'fixture-bucket', 'bucket_region' => 'auto', 'iam_key' => 'fixture-access', 'iam_secret' => 'fixture-secret', 'cloudflare_endpoint' => $config->cloudflareEndpoint, 'cloudflare_url' => 'https://cdn.example.test/'];
    smartsync_assert_same(true, smartsync_capture_json(fn() => $ajax->configureAws())['success']);
    $saved = SMARL_CloudConfig::load();
    smartsync_assert_same('cdn.example.test', $saved->cloudflareUrl);
    smartsync_assert_same($config->cloudflareEndpoint, $saved->cloudflareEndpoint);
});

smartsync_test('certificate requests preserve prior state for invalid domains and reject non-CloudFront setups', function () {
    $config = smartsync_provider_config(); $config->useCloudFront = true; $config->distributionId = 'fixture-id'; $config->cloudfrontUrl = 'fixture.cloudfront.net';
    $config->customUrl = new SMARL_CustomUrl('existing-arn', 'old.example.test'); $config->customUrl->save();
    $_POST = ['domain' => 'https://new.example.test/path'];
    smartsync_assert_same(false, smartsync_capture_json(fn() => (new SMARL_AwsConfigAjax($config))->generateSslCertificate())['success']);
    smartsync_assert_same('existing-arn', SMARL_CustomUrl::load()->certificateArn);
    $config->serviceProvider = 'digitalocean'; $_POST = ['domain' => 'new.example.test'];
    smartsync_assert_same(false, smartsync_capture_json(fn() => (new SMARL_AwsConfigAjax($config))->generateSslCertificate())['success']);
});

smartsync_test('ACM pending records save the certificate immediately without sleeps or duplicate requests', function () {
    $calls = []; $config = smartsync_provider_config(); $config->useCloudFront = true; $config->distributionId = 'fixture-id'; $config->cloudfrontUrl = 'fixture.cloudfront.net';
    $config->save();
    $config->fakeAcm = new AcmClient(['region' => 'us-east-1', 'version' => 'latest', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'handler' => function ($command) use (&$calls) {
        $calls[] = $command->getName(); return \GuzzleHttp\Promise\Create::promiseFor(new Result($command->getName() === 'RequestCertificate' ? ['CertificateArn' => 'arn:aws:acm:us-east-1:123456789012:certificate/fixture'] : ['Certificate' => ['Status' => 'PENDING_VALIDATION', 'DomainName' => 'cdn.example.test', 'DomainValidationOptions' => []]]));
    }]);
    $_POST = ['domain' => 'CDN.example.test']; $ajax = new SMARL_AwsConfigAjax($config);
    $response = smartsync_capture_json(fn() => $ajax->generateSslCertificate());
    smartsync_assert_same(true, $response['success']); smartsync_assert_same('cdn.example.test', SMARL_CustomUrl::load()->domain);
    smartsync_capture_json(fn() => $ajax->generateSslCertificate());
    smartsync_assert_same(['RequestCertificate', 'DescribeCertificate', 'DescribeCertificate'], $calls);
    smartsync_assert_same(false, get_option('smarl_sync_lock'));
});

smartsync_test('DNS verification rejects certificates not bound to the configured domain', function () {
    $config = smartsync_provider_config(); $config->useCloudFront = true; $config->distributionId = 'fixture-id'; $config->cloudfrontUrl = 'fixture.cloudfront.net';
    $config->customUrl = new SMARL_CustomUrl('fixture-arn', 'cdn.example.test'); $_POST = ['certificateArn' => 'different-arn'];
    $config->save(); $config->customUrl->save();
    smartsync_assert_same(false, smartsync_capture_json(fn() => (new SMARL_AwsConfigAjax($config))->checkDnsRecord())['success']);
});

smartsync_test('CloudFront payloads use unique caller references and preserve asset version query strings', function () {
    $first = SMARL_AwsHelper::createCloudfrontArray('fixture-bucket', 'us-east-1', 'fixture-oac')['DistributionConfig'];
    $second = SMARL_AwsHelper::createCloudfrontArray('fixture-bucket', 'us-east-1', 'fixture-oac')['DistributionConfig'];
    smartsync_assert_true(is_string($first['CallerReference']) && $first['CallerReference'] !== $second['CallerReference']);
    smartsync_assert_same(true, $first['DefaultCacheBehavior']['ForwardedValues']['QueryString']);
    smartsync_assert_true(class_exists(\SmartSyncS3\Services\SMARLI_WasabiBucketManager::class));
});

smartsync_test('Google credential validation rejects arbitrary JSON and external token endpoints', function () {
    $path = ABSPATH . 'google-key-fixture.json';
    file_put_contents($path, '{"type":"authorized_user"}'); smartsync_assert_same(false, SMARLI_GoogleService::getServiceJSON($path));
    $credentials = ['type' => 'service_account', 'project_id' => 'fixture-project', 'client_email' => 'fixture@fixture-project.iam.gserviceaccount.com', 'private_key' => "-----BEGIN PRIVATE KEY-----\nfixture\n-----END PRIVATE KEY-----", 'token_uri' => 'https://attacker.example.test/token'];
    file_put_contents($path, json_encode($credentials)); smartsync_assert_same(false, SMARLI_GoogleService::getServiceJSON($path));
    $credentials['token_uri'] = 'https://oauth2.googleapis.com/token'; file_put_contents($path, json_encode($credentials));
    smartsync_assert_same('fixture-project', SMARLI_GoogleService::getProjectId($path)); unlink($path);
});

smartsync_test('Google credential migration removes the web upload and cleans only owned private files', function () {
    $path = wp_upload_dir()['basedir'] . '/fixture-service-key.json';
    $credentials = ['type' => 'service_account', 'project_id' => 'fixture-project', 'client_email' => 'fixture@fixture-project.iam.gserviceaccount.com', 'private_key' => "-----BEGIN PRIVATE KEY-----\nfixture\n-----END PRIVATE KEY-----", 'token_uri' => 'https://oauth2.googleapis.com/token'];
    file_put_contents($path, json_encode($credentials));
    $private = SMARLI_GoogleService::migrateCredentials($path);
    smartsync_assert_true(is_file($private)); smartsync_assert_true(!is_file($path));
    smartsync_assert_true(str_contains(str_replace('\\', '/', $private), '/smartsync-private/'));
    smartsync_assert_true(!str_contains(file_get_contents($private), 'BEGIN PRIVATE KEY'));
    smartsync_assert_same('fixture-project', SMARLI_GoogleService::getServiceJSON($private)['project_id']);
    $envelope = json_decode(file_get_contents($private), true);
    $ciphertext = base64_decode($envelope['ciphertext']); $ciphertext[0] = chr(ord($ciphertext[0]) ^ 1);
    $envelope['ciphertext'] = base64_encode($ciphertext); file_put_contents($private, json_encode($envelope));
    smartsync_assert_same(false, SMARLI_GoogleService::getServiceJSON($private));
    $unrelated = SMARLI_GoogleService::getCredentialsDirectory() . '/unrelated.json'; file_put_contents($unrelated, '{}');
    SMARLI_GoogleService::deleteCredentialFile($unrelated); smartsync_assert_true(is_file($unrelated));
    SMARLI_GoogleService::deleteCredentialFile($private); smartsync_assert_true(!is_file($private));
    unlink($unrelated); SMARLI_GoogleService::cleanupCredentials();
});

smartsync_test('DNS status requires an issued matching certificate and normalized distribution CNAME', function () {
    $config = smartsync_provider_config(); $config->useCloudFront = true; $config->distributionId = 'fixture-id'; $config->cloudfrontUrl = 'fixture.cloudfront.net';
    $config->customUrl = new SMARL_CustomUrl('arn:aws:acm:us-east-1:123456789012:certificate/fixture', 'cdn.example.test');
    $config->save(); $config->customUrl->save();
    $GLOBALS['smartsync_test_dns'] = ['cdn.example.test' => [['target' => 'FIXTURE.cloudfront.net.']]];
    $status = 'PENDING_VALIDATION';
    $config->fakeAcm = new AcmClient(['region' => 'us-east-1', 'version' => 'latest', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'handler' => function () use (&$status) {
        return \GuzzleHttp\Promise\Create::promiseFor(new Result(['Certificate' => ['Status' => $status, 'DomainName' => 'cdn.example.test', 'SubjectAlternativeNames' => ['cdn.example.test'], 'DomainValidationOptions' => []]]));
    }]);
    $_POST = ['certificateArn' => $config->customUrl->certificateArn]; $ajax = new SMARL_AwsConfigAjax($config);
    smartsync_assert_same(false, smartsync_capture_json(fn() => $ajax->checkDnsRecord())['data']['verified']);
    $status = 'ISSUED'; smartsync_assert_same(true, smartsync_capture_json(fn() => $ajax->checkDnsRecord())['data']['verified']);
    $GLOBALS['smartsync_test_dns'] = []; smartsync_assert_same(false, smartsync_capture_json(fn() => $ajax->checkDnsRecord())['data']['verified']);
});

smartsync_test('CloudFront alternate domain attachment is idempotent and checks certificate coverage', function () {
    $config = smartsync_provider_config(); $config->useCloudFront = true; $config->distributionId = 'fixture-id'; $config->cloudfrontUrl = 'fixture.cloudfront.net';
    $config->customUrl = new SMARL_CustomUrl('arn:aws:acm:us-east-1:123456789012:certificate/fixture', 'cdn.example.test');
    $config->save(); $config->customUrl->save();
    $GLOBALS['smartsync_test_dns'] = ['cdn.example.test' => [['target' => 'fixture.cloudfront.net.']]];
    $config->fakeAcm = new AcmClient(['region' => 'us-east-1', 'version' => 'latest', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'handler' => function () {
        return \GuzzleHttp\Promise\Create::promiseFor(new Result(['Certificate' => ['Status' => 'ISSUED', 'DomainName' => 'cdn.example.test', 'SubjectAlternativeNames' => ['cdn.example.test']]]));
    }]);
    $updates = [];
    $distribution = SMARL_AwsHelper::createCloudfrontArray('fixture-bucket', 'us-east-1', 'fixture-oac')['DistributionConfig'];
    $distribution['Aliases'] = ['Quantity' => 1, 'Items' => ['cdn.example.test']];
    $config->fakeCloudFront = new CloudFrontClient(['region' => 'us-east-1', 'version' => 'latest', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'handler' => function ($command) use (&$updates, &$distribution) {
        if ($command->getName() === 'GetDistributionConfig') return \GuzzleHttp\Promise\Create::promiseFor(new Result(['DistributionConfig' => $distribution, 'ETag' => 'fixture-etag']));
        $updates[] = $command->toArray(); return \GuzzleHttp\Promise\Create::promiseFor(new Result());
    }]);
    $ajax = new SMARL_AwsConfigAjax($config);
    smartsync_assert_same(true, smartsync_capture_json(fn() => $ajax->updateCloudFrontAlternateDomain())['success']);
    smartsync_assert_same(1, $updates[0]['DistributionConfig']['Aliases']['Quantity']);
    smartsync_assert_same('fixture-etag', $updates[0]['IfMatch']);
    smartsync_assert_same('cdn.example.test', get_option('smarl_settings')['url']);
    $distribution['Aliases'] = ['Quantity' => 1, 'Items' => ['other.example.test']];
    smartsync_assert_same(false, smartsync_capture_json(fn() => $ajax->updateCloudFrontAlternateDomain())['success']);
    smartsync_assert_same(1, count($updates));
    smartsync_assert_same(false, get_option('smarl_sync_lock'));
});

smartsync_test('all custom domain endpoints respect the shared configuration lock', function () {
    $config = smartsync_provider_config(); $config->useCloudFront = true; $config->distributionId = 'fixture-id'; $config->cloudfrontUrl = 'fixture.cloudfront.net'; $config->save();
    $ajax = new SMARL_AwsConfigAjax($config);
    $lock = \SmartSyncS3\Helpers\SMARL_Helper::acquireSyncLock();
    foreach (['generateSslCertificate', 'checkDnsRecord', 'updateCloudFrontAlternateDomain'] as $method) {
        smartsync_assert_same(409, smartsync_capture_json(fn() => $ajax->$method())['status']);
        smartsync_assert_same($lock, get_option('smarl_sync_lock')['token']);
    }
    \SmartSyncS3\Helpers\SMARL_Helper::releaseSyncLock($lock);
    smartsync_assert_same([], $GLOBALS['smartsync_test_http_requests']);
});

smartsync_test('stale custom domain requests cannot restore a previous provider configuration', function () {
    $config = smartsync_provider_config(); $config->useCloudFront = true; $config->distributionId = 'fixture-id'; $config->cloudfrontUrl = 'fixture.cloudfront.net'; $config->save();
    $ajax = new SMARL_AwsConfigAjax($config);
    $replacement = SMARL_CloudConfig::fromArray($config->toArray()); $replacement->bucket = 'replacement-bucket'; $replacement->save();
    foreach (['generateSslCertificate', 'checkDnsRecord', 'updateCloudFrontAlternateDomain'] as $method) {
        smartsync_assert_same(409, smartsync_capture_json(fn() => $ajax->$method())['status']);
        smartsync_assert_same('replacement-bucket', get_option('smarl_settings')['bucket']);
        smartsync_assert_same(false, get_option('smarl_sync_lock'));
    }
    delete_option('smarl_settings');
    foreach (['generateSslCertificate', 'checkDnsRecord', 'updateCloudFrontAlternateDomain'] as $method) smartsync_assert_same(404, smartsync_capture_json(fn() => $ajax->$method())['status']);
    smartsync_assert_same([], $GLOBALS['smartsync_test_http_requests']);
});

smartsync_test('stale certificate requests cannot overwrite a newer custom domain', function () {
    $config = smartsync_provider_config(); $config->useCloudFront = true; $config->distributionId = 'fixture-id'; $config->cloudfrontUrl = 'fixture.cloudfront.net'; $config->save();
    $config->customUrl = new SMARL_CustomUrl('old-certificate-arn', 'old.example.test'); $config->customUrl->save();
    $ajax = new SMARL_AwsConfigAjax($config);
    (new SMARL_CustomUrl('new-certificate-arn', 'new.example.test'))->save();
    foreach (['generateSslCertificate', 'checkDnsRecord', 'updateCloudFrontAlternateDomain'] as $method) {
        smartsync_assert_same(409, smartsync_capture_json(fn() => $ajax->$method())['status']);
        smartsync_assert_same('new.example.test', SMARL_CustomUrl::load()->domain);
        smartsync_assert_same(false, get_option('smarl_sync_lock'));
    }
});

smartsync_test('AWS direct hosting keeps unrelated policy statements and blocks public ACLs', function () {
    $calls = []; $config = smartsync_provider_config();
    $config->fakeS3 = smartsync_provider_s3(function ($command) use (&$calls) {
        $calls[] = [$command->getName(), $command->toArray()];
        if ($command->getName() === 'GetBucketPolicy') return ['Policy' => json_encode(['Version' => '2012-10-17', 'Statement' => [['Sid' => 'ExistingGrant', 'Effect' => 'Allow', 'Principal' => ['AWS' => 'arn:aws:iam::123456789012:root'], 'Action' => 's3:GetObject', 'Resource' => 'arn:aws:s3:::fixture-bucket/*']]])];
        return [];
    });
    $ajax = new SmartSyncProviderAjax(new SMARL_CloudConfig()); $ajax->nextConfig = $config;
    $_POST = ['service_provider' => 'aws', 'bucket_type' => 'existing', 'bucket_name' => 'fixture-bucket', 'bucket_region' => 'us-east-1', 'iam_key' => 'fixture-access', 'iam_secret' => 'fixture-secret'];
    smartsync_assert_same(true, smartsync_capture_json(fn() => $ajax->configureAws())['success']);
    $policyCall = array_values(array_filter($calls, fn($item) => $item[0] === 'PutBucketPolicy'))[0][1];
    $policy = json_decode($policyCall['Policy'], true);
    smartsync_assert_same('ExistingGrant', $policy['Statement'][0]['Sid']);
    smartsync_assert_same('SmartSyncPublicRead', $policy['Statement'][1]['Sid']);
    $publicAccess = array_values(array_filter($calls, fn($item) => $item[0] === 'PutPublicAccessBlock'))[0][1]['PublicAccessBlockConfiguration'];
    smartsync_assert_same(true, $publicAccess['BlockPublicAcls']); smartsync_assert_same(true, $publicAccess['IgnorePublicAcls']);
});

smartsync_test('cloud configuration rejects active syncs and always releases its coordination lock', function () {
    $ajax = new SMARL_AwsConfigAjax(null);
    update_option('smarl_sync_settings', ['s3SyncStatus' => 'processing']);
    smartsync_assert_same(409, smartsync_capture_json(fn() => $ajax->configureAws())['status']);
    delete_option('smarl_sync_settings');
    $lock = \SmartSyncS3\Helpers\SMARL_Helper::acquireSyncLock();
    smartsync_assert_same(409, smartsync_capture_json(fn() => $ajax->configureAws())['status']);
    \SmartSyncS3\Helpers\SMARL_Helper::releaseSyncLock($lock);
    $_POST = ['service_provider' => 'invalid'];
    smartsync_assert_same(false, smartsync_capture_json(fn() => $ajax->configureAws())['success']);
    smartsync_assert_same(false, get_option('smarl_sync_lock'));
});

smartsync_test('provider reconfiguration requires restoring tracked offloaded media before external changes', function () {
    $GLOBALS['smartsync_test_posts'][992] = (object) ['ID' => 992, 'post_type' => 'attachment'];
    update_post_meta(992, '_smarl_offloaded_files', ['wp-content/uploads/missing-offloaded-original.jpg']);
    $_POST = ['service_provider' => 'aws', 'bucket_type' => 'existing', 'bucket_name' => 'fixture-zone', 'bucket_region' => 'us-east-1', 'iam_key' => 'fixture-access-key', 'iam_secret' => 'fixture-account-key'];
    $response = smartsync_capture_json(fn() => (new SMARL_AwsConfigAjax(null))->configureAws());
    smartsync_assert_same(false, $response['success']);
    smartsync_assert_same('Restore offloaded media locally before reconfiguring cloud storage.', $response['data']);
    smartsync_assert_same([], $GLOBALS['smartsync_test_http_requests']); smartsync_assert_same(false, get_option('smarl_settings')); smartsync_assert_same(false, get_option('smarl_sync_lock'));
});

smartsync_test('credentials-only CloudFront repair preserves remote-only media delivery and existing resources', function () {
    $config = smartsync_provider_config(); $config->useCloudFront = true; $config->cloudfrontUrl = 'fixture.cloudfront.net'; $config->url = 'media.example.test'; $config->distributionId = 'fixture-existing-distribution';
    $config->canEnableMediaCDN = $config->useCdnForMedia = $config->mediaAutoDelete = true; $config->canEnableAssetCDN = $config->useCdnForAssets = true; $config->urlType = 'cloudfront';
    $config->customUrl = new SMARL_CustomUrl('fixture-existing-certificate', 'media.example.test'); $config->save(); $config->customUrl->save();
    $GLOBALS['smartsync_test_posts'][993] = (object) ['ID' => 993, 'post_type' => 'attachment']; update_post_meta(993, '_smarl_offloaded_files', ['wp-content/uploads/remote-only-repair.jpg']);
    $calls = [];
    $config->fakeS3 = smartsync_provider_s3(function ($command) use (&$calls) {
        $calls[] = $command->getName();
        if ($command->getName() !== 'HeadBucket') throw new RuntimeException('Credential repair attempted to provision cloud resources.');
        return [];
    });
    $before = $config->toArray(); $before['iam_key'] = 'replacement-access'; $before['iam_secret'] = 'replacement-secret';
    $_POST = ['service_provider' => 'aws', 'bucket_type' => 'existing', 'bucket_name' => $config->bucket, 'bucket_region' => $config->region, 'iam_key' => 'replacement-access', 'iam_secret' => 'replacement-secret', 'use_cloudfront' => 'true'];
    $response = smartsync_capture_json(fn() => (new SMARL_AwsConfigAjax($config))->configureAws());
    smartsync_assert_same(true, $response['success']); smartsync_assert_same(['HeadBucket'], $calls);
    smartsync_assert_same($before, get_option('smarl_settings')); smartsync_assert_same('fixture-existing-certificate', SMARL_CustomUrl::load()->certificateArn);
    smartsync_assert_same(false, get_option('smarl_sync_lock'));
});

smartsync_test('credentials-only R2 repair keeps CDN flags but a changed public target still requires restore', function () {
    $config = smartsync_provider_config('cloudflare'); $config->region = 'auto'; $config->cloudflareUrl = $config->url = 'cdn.example.test';
    $config->canEnableMediaCDN = $config->useCdnForMedia = true; $config->save();
    $GLOBALS['smartsync_test_posts'][994] = (object) ['ID' => 994, 'post_type' => 'attachment']; update_post_meta(994, '_smarl_offloaded_files', ['wp-content/uploads/remote-only-r2.jpg']);
    $calls = []; $config->fakeS3 = smartsync_provider_s3(function ($command) use (&$calls) { $calls[] = $command->getName(); return []; });
    $_POST = ['service_provider' => 'cloudflare', 'bucket_type' => 'existing', 'bucket_name' => $config->bucket, 'bucket_region' => 'auto', 'iam_key' => 'new-access', 'iam_secret' => 'new-secret', 'cloudflare_endpoint' => $config->cloudflareEndpoint, 'cloudflare_url' => $config->url];
    $ajax = new SMARL_AwsConfigAjax($config);
    smartsync_assert_same(true, smartsync_capture_json(fn() => $ajax->configureAws())['success']);
    smartsync_assert_same(true, SMARL_CloudConfig::load()->useCdnForMedia); smartsync_assert_same(['HeadBucket'], $calls);
    $_POST['cloudflare_url'] = 'different.example.test';
    smartsync_assert_same(false, smartsync_capture_json(fn() => $ajax->configureAws())['success']);
    smartsync_assert_same(['HeadBucket'], $calls); smartsync_assert_same('cdn.example.test', get_option('smarl_settings')['url']);
});

smartsync_test('replacing a pending Google upload never deletes the active credential file', function () {
    $credentials = ['type' => 'service_account', 'project_id' => 'fixture-project', 'client_email' => 'fixture@fixture-project.iam.gserviceaccount.com', 'private_key' => "-----BEGIN PRIVATE KEY-----\nfixture\n-----END PRIVATE KEY-----", 'token_uri' => 'https://oauth2.googleapis.com/token'];
    $upload = wp_upload_dir()['basedir'] . '/active-google-upload.json'; file_put_contents($upload, json_encode($credentials));
    $active = SMARLI_GoogleService::migrateCredentials($upload);
    update_option('smarl_settings', ['service_provider' => 'google', 'googleServicePath' => $active]);
    set_transient('smarl_google_credentials_' . get_current_user_id(), $active, 3600);
    $temporary = ABSPATH . 'replacement-google-upload.json'; file_put_contents($temporary, json_encode($credentials));
    $GLOBALS['smartsync_test_uploaded_files'] = [$temporary => true];
    $_FILES['service_json'] = ['name' => 'service-account.json', 'tmp_name' => $temporary, 'error' => UPLOAD_ERR_OK, 'size' => filesize($temporary)];
    SMARLI_GoogleService::moveFileTemp(); $firstPending = SMARLI_GoogleService::getTempPath();
    smartsync_assert_true($firstPending !== $active && is_file($firstPending) && is_file($active));
    smartsync_assert_same($active, get_option('smarl_settings')['googleServicePath']); smartsync_assert_same('fixture-project', SMARLI_GoogleService::getProjectId($active));
    SMARLI_GoogleService::moveFileTemp(); $secondPending = SMARLI_GoogleService::getTempPath();
    smartsync_assert_true(is_file($active) && is_file($secondPending) && !is_file($firstPending));
    smartsync_assert_same(false, get_option('smarl_sync_lock'));
    SMARLI_GoogleService::deleteCredentialFile($active); SMARLI_GoogleService::deleteCredentialFile($secondPending); unlink($temporary);
    unset($GLOBALS['smartsync_test_uploaded_files']);
});

smartsync_test('configuration rechecks sync state before committing provider changes', function () {
    $config = smartsync_provider_config('cloudflare');
    $config->fakeS3 = smartsync_provider_s3(function () {
        update_option('smarl_sync_settings', ['s3SyncStatus' => 'processing']); return [];
    });
    $ajax = new SmartSyncProviderAjax(null); $ajax->nextConfig = $config;
    $_POST = ['service_provider' => 'cloudflare', 'bucket_type' => 'existing', 'bucket_name' => 'fixture-bucket', 'bucket_region' => 'auto', 'iam_key' => 'fixture-access', 'iam_secret' => 'fixture-secret', 'cloudflare_endpoint' => $config->cloudflareEndpoint, 'cloudflare_url' => 'cdn.example.test'];
    smartsync_assert_same(409, smartsync_capture_json(fn() => $ajax->configureAws())['status']);
    smartsync_assert_same(false, get_option('smarl_settings')); smartsync_assert_same(false, get_option('smarl_sync_lock'));
});

smartsync_test('Google restore writes its destination and deletion tolerates missing objects', function () {
    $config = smartsync_provider_config('google');
    $credential = ABSPATH . 'google-fake-config.txt'; file_put_contents($credential, 'fixture'); $config->googleServicePath = $credential;
    $calls = [];
    $config->fakeGoogle = new StorageClient(['projectId' => 'fixture-project', 'credentialsFetcher' => new AnonymousCredentials(), 'httpHandler' => function ($request) use (&$calls) {
        $uri = (string) $request->getUri(); $method = $request->getMethod(); $calls[] = [$method, $uri];
        if ($method === 'DELETE') return new Response(204);
        if (str_contains($uri, 'alt=media')) return new Response(200, [], 'restored-google');
        if (str_contains($uri, 'missing')) throw new \GuzzleHttp\Exception\ClientException('Not found', $request, new Response(404, ['Content-Type' => 'application/json'], '{"error":{"code":404,"message":"Not found"}}'));
        return new Response(200, ['Content-Type' => 'application/json'], '{"name":"nested/photo.jpg","generation":"1"}');
    }]);
    $destination = ABSPATH . 'google-restored.txt'; $config->downloadObject('nested/photo.jpg', $destination);
    smartsync_assert_same('restored-google', file_get_contents($destination));
    $config->deleteObject('nested/photo.jpg'); $config->deleteObject('missing.jpg');
    smartsync_assert_same(1, count(array_filter($calls, fn($call) => $call[0] === 'DELETE')));
    unlink($destination); unlink($credential);
});
}
