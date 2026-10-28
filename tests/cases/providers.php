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



smartsync_test('all storage operations reject traversal keys before touching a provider', function () {
    $config = smartsync_provider_config();
    foreach (['../secret', '/secret', 'a/../../secret', 'a\\secret', 'C:/secret', "a\0b", 'a/./b'] as $key) {
        smartsync_assert_throws(fn() => $config->deleteObject($key), InvalidArgumentException::class);
    }
    smartsync_assert_same('wp-content/uploads/photo.jpg', SMARL_CloudConfig::validateObjectKey('wp-content/uploads/photo.jpg'));
});



smartsync_test('S3 upload ACLs are only sent to DigitalOcean and Wasabi', function () {
    $path = ABSPATH . 'provider-upload.txt'; file_put_contents($path, 'fixture');
    foreach (['aws', 'digitalocean', 'wasabi'] as $provider) {
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



smartsync_test('every provider configuration endpoint rejects insufficient capabilities and invalid nonces', function () {
    foreach (['configureAws', 'listS3Buckets', 'proOnly'] as $method) {
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







smartsync_test('configuration rechecks sync state before committing provider changes', function () {
    $config = smartsync_provider_config('digitalocean');
    $config->fakeS3 = smartsync_provider_s3(function () {
        update_option('smarl_sync_settings', ['s3SyncStatus' => 'processing']); return [];
    });
    $ajax = new SmartSyncProviderAjax(null); $ajax->nextConfig = $config;
    $_POST = ['service_provider' => 'digitalocean', 'bucket_type' => 'existing', 'bucket_name' => 'fixture-bucket', 'bucket_region' => 'nyc3', 'iam_key' => 'fixture-access', 'iam_secret' => 'fixture-secret', 'cloudflare_endpoint' => $config->cloudflareEndpoint, 'cloudflare_url' => 'cdn.example.test'];
    smartsync_assert_same(409, smartsync_capture_json(fn() => $ajax->configureAws())['status']);
    smartsync_assert_same(false, get_option('smarl_settings')); smartsync_assert_same(false, get_option('smarl_sync_lock'));
});


}
