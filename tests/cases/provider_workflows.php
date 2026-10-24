<?php
use SmartSyncS3\Ajax\SMARL_AwsConfigAjax;
use SmartSyncS3\Models\SMARL_CloudConfig;

function smartsync_bunny_api_zone(array $overrides = []): array {
    return array_replace([
        'Id' => 42, 'Name' => 'workflow-zone', 'Region' => 'DE', 'Password' => 'fixture-zone-password',
        'StorageHostname' => 'storage.bunnycdn.com', 'Deleted' => false,
        'PullZones' => [[
            'Id' => 7, 'Enabled' => true, 'Suspended' => false, 'ZoneSecurityEnabled' => false,
            'Hostnames' => [['Value' => 'workflow.b-cdn.net', 'IsSystemHostname' => true, 'HasCertificate' => false]],
        ]],
    ], $overrides);
}
function smartsync_bunny_list(SMARL_AwsConfigAjax $ajax): array {
    $_POST = ['service_provider' => 'bunny', 'iam_secret' => 'fixture-account-key'];
    return smartsync_capture_json(fn() => $ajax->listS3Buckets());
}
function smartsync_bunny_save(SMARL_AwsConfigAjax $ajax): array {
    $_POST = ['service_provider' => 'bunny', 'bucket_type' => 'existing', 'bucket_name' => 'workflow-zone',
        'bucket_region' => 'DE', 'iam_key' => 'workflow.b-cdn.net', 'iam_secret' => 'fixture-account-key'];
    return smartsync_capture_json(fn() => $ajax->configureAws());
}

smartsync_test('Bunny connection accepts official paginated account responses and follows every page', function () {
    $pages = [];
    $GLOBALS['smartsync_test_http'] = function ($url, $args) use (&$pages) {
        smartsync_assert_same('fixture-account-key', $args['headers']['AccessKey']);
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $page = (int) ($query['page'] ?? 0); $pages[] = $page;
        smartsync_assert_true($page >= 1, 'Use the documented positive page range');
        $zone = smartsync_bunny_api_zone(['Name' => 'workflow-zone-' . $page]);
        return ['response' => ['code' => 200], 'body' => json_encode(['Items' => [$zone], 'CurrentPage' => $page, 'TotalItems' => 2, 'HasMoreItems' => $page === 1])];
    };
    $response = smartsync_bunny_list(new SMARL_AwsConfigAjax(null));
    smartsync_assert_same(true, $response['success']);
    smartsync_assert_same([1, 2], $pages);
    smartsync_assert_same(['workflow-zone-1', 'workflow-zone-2'], array_column($response['data']['buckets'], 'name'));
    smartsync_assert_true(!str_contains(json_encode($response), 'fixture-zone-password'));
});

smartsync_test('Bunny connection offers HTTPS-ready active pull zones instead of unusable hostnames', function () {
    $ready = smartsync_bunny_api_zone()['PullZones'][0];
    array_unshift($ready['Hostnames'], ['Value' => 'unissued.example.test', 'IsSystemHostname' => false, 'HasCertificate' => false]);
    $zone = smartsync_bunny_api_zone(['PullZones' => [
        array_replace($ready, ['Enabled' => false]), array_replace($ready, ['Suspended' => true]),
        array_replace($ready, ['ZoneSecurityEnabled' => true]), $ready,
    ]]);
    $GLOBALS['smartsync_test_http'] = fn() => ['response' => ['code' => 200], 'body' => json_encode([$zone])];
    $response = smartsync_bunny_list(new SMARL_AwsConfigAjax(null));
    smartsync_assert_same(true, $response['success']);
    smartsync_assert_same(['workflow.b-cdn.net'], $response['data']['buckets'][0]['pullzones']);
});

smartsync_test('Bunny connection explains an account key rejection without exposing credentials', function () {
    $GLOBALS['smartsync_test_http'] = fn() => ['response' => ['code' => 401], 'body' => 'fixture-account-key'];
    $response = smartsync_bunny_list(new SMARL_AwsConfigAjax(null));
    smartsync_assert_same(false, $response['success']);
    smartsync_assert_true(str_contains(strtolower($response['data']), 'account api key'));
    smartsync_assert_true(!str_contains($response['data'], 'fixture-account-key'));
});

smartsync_test('Bunny cannot report a saved connection when the storage endpoint rejects its credentials', function () {
    $old = ['service_provider' => 'aws', 'bucket' => 'original-bucket']; update_option('smarl_settings', $old);
    $GLOBALS['smartsync_test_http'] = static function ($url) {
        return str_starts_with($url, 'https://api.bunny.net/')
            ? ['response' => ['code' => 200], 'body' => json_encode([smartsync_bunny_api_zone()])]
            : ['response' => ['code' => 401], 'body' => 'Storage unauthorized'];
    };
    $ajax = new SMARL_AwsConfigAjax(null);
    smartsync_assert_same(true, smartsync_bunny_list($ajax)['success']);
    $response = smartsync_bunny_save($ajax);
    smartsync_assert_same(false, $response['success']);
    smartsync_assert_same($old, get_option('smarl_settings'));
    smartsync_assert_same(false, get_option('smarl_sync_lock'));
});

smartsync_test('Bunny uses its provider-reported storage hostname and rejects foreign credential destinations', function () {
    $zone = smartsync_bunny_api_zone(['Region' => 'AZ', 'StorageHostname' => 'az.storage.bunnycdn.com']);
    $GLOBALS['smartsync_test_http'] = static function ($url, $args) use ($zone) {
        if (str_starts_with($url, 'https://api.bunny.net/')) return ['response' => ['code' => 200], 'body' => json_encode([$zone])];
        smartsync_assert_same('https://az.storage.bunnycdn.com/workflow-zone/', $url);
        smartsync_assert_same('fixture-zone-password', $args['headers']['AccessKey']);
        return ['response' => ['code' => 200], 'body' => '[]'];
    };
    $ajax = new SMARL_AwsConfigAjax(null);
    smartsync_assert_same(true, smartsync_bunny_list($ajax)['success']);
    smartsync_assert_same(true, smartsync_bunny_save($ajax)['success']);
    smartsync_assert_same('https://az.storage.bunnycdn.com', SMARL_CloudConfig::load()->getBunnyStorageEndpoint());
    $zone['StorageHostname'] = 'https://attacker.example.test';
    $GLOBALS['smartsync_test_http'] = fn() => ['response' => ['code' => 200], 'body' => json_encode([$zone])];
    smartsync_assert_same(false, smartsync_bunny_list(new SMARL_AwsConfigAjax(null))['success']);
});

smartsync_test('Bunny complete workflow connects uploads serves restores and deletes using the saved provider', function () {
    $objects = []; $storageRequests = [];
    $GLOBALS['smartsync_test_http'] = function ($url, $args) use (&$objects, &$storageRequests) {
        if (str_starts_with($url, 'https://api.bunny.net/')) {
            smartsync_assert_same('fixture-account-key', $args['headers']['AccessKey']);
            return ['response' => ['code' => 200], 'body' => json_encode(['Items' => [smartsync_bunny_api_zone()], 'CurrentPage' => 1, 'HasMoreItems' => false])];
        }
        smartsync_assert_same('storage.bunnycdn.com', parse_url($url, PHP_URL_HOST));
        smartsync_assert_same('fixture-zone-password', $args['headers']['AccessKey']);
        $key = rawurldecode(substr(parse_url($url, PHP_URL_PATH), strlen('/workflow-zone/')));
        $method = $args['method'] ?? 'GET'; $storageRequests[] = [$method, $key];
        if ($method === 'PUT') {
            $objects[$key] = $args['body'];
            return ['response' => ['code' => 201], 'body' => '{"HttpCode":201,"Message":"File uploaded."}'];
        }
        if ($method === 'DELETE') { unset($objects[$key]); return ['response' => ['code' => 200], 'body' => '']; }
        if (!str_ends_with($url, '/')) {
            smartsync_assert_true(array_key_exists($key, $objects), 'Requested object must exist');
            smartsync_assert_true(!empty($args['stream']), 'Restore must stream to its atomic destination');
            file_put_contents($args['filename'], $objects[$key]);
            return ['response' => ['code' => 200], 'body' => ''];
        }
        $entries = [];
        foreach ($objects as $objectKey => $bytes) {
            if (!str_starts_with($objectKey, $key)) continue;
            $tail = substr($objectKey, strlen($key)); $slash = strpos($tail, '/');
            $name = $slash === false ? $tail : substr($tail, 0, $slash);
            $entries[$name] = ['ObjectName' => $name, 'IsDirectory' => $slash !== false, 'Length' => $slash === false ? strlen($bytes) : 0];
        }
        return ['response' => ['code' => 200], 'body' => json_encode(array_values($entries))];
    };
    $ajax = new SMARL_AwsConfigAjax(null);
    smartsync_assert_same(true, smartsync_bunny_list($ajax)['success']);
    smartsync_assert_same(true, smartsync_bunny_save($ajax)['success']);
    $config = SMARL_CloudConfig::load();
    $handler = new \SmartSyncS3\Ajax\SMARL_AjaxHandler($config);
    $files = [];
    for ($i = 0; $i < 16; $i++) {
        $key = 'wp-content/uploads/2026/10/bunny workflow-' . $i . '.jpg';
        $files[$key] = 'complete-workflow-bytes-' . $i;
        file_put_contents(ABSPATH . $key, $files[$key]);
    }
    $_POST = ['type' => 'media'];
    smartsync_assert_same(true, smartsync_capture_json(fn() => $handler->syncLocalToAws())['success']);
    smartsync_run_jobs($handler);
    smartsync_assert_same('completed', smartsync_progress()->s3SyncStatus);
    ksort($files); ksort($objects);
    smartsync_assert_same($files, $objects);
    $_POST = ['type' => 'media', 'useCdn' => 'true'];
    smartsync_assert_same(true, smartsync_capture_json(fn() => $handler->toggleCdn())['success']);
    $core = new \SmartSyncS3\SMARL_SmartSync(dirname(__DIR__, 2) . '/aws-s3-smartsync-wp.php');
    $key = array_key_first($files);
    smartsync_assert_same('https://workflow.b-cdn.net/' . str_replace(' ', '%20', $key), $core->getAttachmentUrl(site_url(str_replace(' ', '%20', $key))));
    foreach ($files as $key => $bytes) unlink(ABSPATH . $key);
    $_POST = ['type' => 'media'];
    smartsync_assert_same(true, smartsync_capture_json(fn() => $handler->syncAwsToLocal())['success']);
    smartsync_run_jobs($handler);
    smartsync_assert_same('completed', smartsync_progress()->s3SyncStatus);
    foreach ($files as $key => $bytes) smartsync_assert_same($bytes, file_get_contents(ABSPATH . $key));
    $config->deleteObject(array_key_first($files));
    smartsync_assert_same(15, count($objects));
    smartsync_assert_true(count($storageRequests) > 35, 'Exercise actual storage dispatch across every phase');
});

smartsync_test('Bunny missing listing fields are recovered from zone details and unusable zones explain setup', function () {
    $GLOBALS['smartsync_test_http'] = function ($url) {
        $zone = smartsync_bunny_api_zone();
        if (str_contains($url, '/storagezone/42')) return ['response' => ['code' => 200], 'body' => json_encode($zone)];
        $zone['Password'] = null; $zone['PullZones'] = null;
        return ['response' => ['code' => 200], 'body' => json_encode([$zone])];
    };
    $response = smartsync_bunny_list(new SMARL_AwsConfigAjax(null));
    smartsync_assert_same(true, $response['success']);
    smartsync_assert_same(['workflow.b-cdn.net'], $response['data']['buckets'][0]['pullzones']);
    $GLOBALS['smartsync_test_http'] = fn() => ['response' => ['code' => 200], 'body' => json_encode([smartsync_bunny_api_zone(['PullZones' => []])])];
    $response = smartsync_bunny_list(new SMARL_AwsConfigAjax(null));
    smartsync_assert_same([], $response['data']['buckets'][0]['pullzones']);
    smartsync_assert_true(str_contains($response['data']['buckets'][0]['issue'], 'pull zone'));
});

smartsync_test('Bunny malformed pagination cannot loop or retain a stale account selection', function () {
    set_transient('smarl_bunny_zones_1', ['key_hash' => hash('sha256', 'fixture-account-key'), 'zones' => ['old' => []]], 900);
    $GLOBALS['smartsync_test_http'] = fn() => ['response' => ['code' => 200], 'body' => '{"Items":[],"CurrentPage":1,"HasMoreItems":true}'];
    $response = smartsync_bunny_list(new SMARL_AwsConfigAjax(null));
    smartsync_assert_same(false, $response['success']);
    smartsync_assert_same(false, get_transient('smarl_bunny_zones_1'));
    smartsync_assert_same(1, count($GLOBALS['smartsync_test_http_requests']));
});

smartsync_test('CDN and cleanup settings preserve newer provider configuration and eligibility', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    $new = SMARL_CloudConfig::load(); $new->bucket = 'new-target'; $new->iamSecret = 'new-fixture-secret';
    $new->canEnableMediaCDN = true; $new->useCdnForAssets = true; $new->save();
    $_POST = ['type' => 'media', 'useCdn' => 'true'];
    smartsync_assert_same(true, smartsync_capture_json(fn() => $handler->toggleCdn())['success']);
    $saved = SMARL_CloudConfig::load();
    smartsync_assert_same('new-target', $saved->bucket);
    smartsync_assert_same('new-fixture-secret', $saved->iamSecret);
    smartsync_assert_true($saved->useCdnForMedia && $saved->useCdnForAssets);
    $saved->useCdnForAssets = false; $saved->save();
    $_POST = ['state' => 'true'];
    smartsync_assert_same(true, smartsync_capture_json(fn() => $handler->toggleMediaAutoDelete())['success']);
    smartsync_assert_same(false, SMARL_CloudConfig::load()->useCdnForAssets);
});

smartsync_test('CDN and cleanup writes cannot race a locked provider change or worker', function () {
    [$storage, $handler] = smartsync_sync_fixture(); $original = get_option('smarl_settings');
    $lock = \SmartSyncS3\Helpers\SMARL_Helper::acquireSyncLock();
    $_POST = ['type' => 'assets', 'useCdn' => 'false'];
    smartsync_assert_same(409, smartsync_capture_json(fn() => $handler->toggleCdn())['status']);
    $_POST = ['state' => 'true'];
    smartsync_assert_same(409, smartsync_capture_json(fn() => $handler->toggleMediaAutoDelete())['status']);
    smartsync_assert_same($original, get_option('smarl_settings'));
    smartsync_assert_same(null, \SmartSyncS3\Helpers\SMARL_Helper::acquireSyncLock());
    \SmartSyncS3\Helpers\SMARL_Helper::releaseSyncLock($lock);
});

smartsync_test('Bunny legacy regional-host repair preserves delivery of remote-only media', function () {
    $old = SMARL_CloudConfig::fromArray(['service_provider' => 'bunny', 'bucket' => 'workflow-zone', 'region' => 'US', 'iam_key' => 'workflow.b-cdn.net',
        'iam_secret' => 'fixture-account-key', 'bunnyStoragePassword' => 'old-fixture-password', 'url' => 'workflow.b-cdn.net',
        'use_cdn_for_media' => true, 'canEnableMediaCDN' => true, 'mediaAutoDelete' => true]);
    $old->save(); update_post_meta(9876, '_smarl_offloaded_files', ['wp-content/uploads/missing-local.jpg']);
    $GLOBALS['smartsync_test_http'] = static function ($url) {
        if (str_starts_with($url, 'https://api.bunny.net/')) return ['response' => ['code' => 200], 'body' => json_encode([smartsync_bunny_api_zone(['Region' => 'US', 'StorageHostname' => 'la.storage.bunnycdn.com'])])];
        smartsync_assert_same('https://la.storage.bunnycdn.com/workflow-zone/', $url);
        return ['response' => ['code' => 200], 'body' => '[]'];
    };
    $ajax = new SMARL_AwsConfigAjax($old);
    smartsync_assert_same(true, smartsync_bunny_list($ajax)['success']);
    smartsync_assert_same(true, smartsync_bunny_save($ajax)['success']);
    $saved = SMARL_CloudConfig::load();
    smartsync_assert_same('https://la.storage.bunnycdn.com', $saved->getBunnyStorageEndpoint());
    smartsync_assert_true($saved->useCdnForMedia && $saved->canEnableMediaCDN && $saved->mediaAutoDelete);
});

smartsync_test('AWS dotted-bucket URL correction preserves CDN settings when local media is absent', function () {
    $old = smartsync_provider_config('aws'); $old->bucket = 'workflow.dotted.bucket'; $old->region = 'eu-west-1';
    $old->url = 'workflow.dotted.bucket.s3.eu-west-1.amazonaws.com'; $old->useCdnForMedia = true; $old->canEnableMediaCDN = true; $old->save();
    update_post_meta(9877, '_smarl_offloaded_files', ['wp-content/uploads/missing-local.jpg']);
    $requested = smartsync_provider_config('aws'); $requested->fakeS3 = smartsync_provider_s3(fn() => ['@metadata' => ['statusCode' => 200]]);
    $old->fakeS3 = $requested->fakeS3;
    $ajax = new SmartSyncProviderAjax($old); $ajax->nextConfig = $requested;
    $_POST = ['service_provider' => 'aws', 'bucket_type' => 'existing', 'bucket_name' => $old->bucket, 'bucket_region' => $old->region,
        'iam_key' => 'new-fixture-access', 'iam_secret' => 'new-fixture-secret', 'use_cloudfront' => 'false'];
    smartsync_assert_same(true, smartsync_capture_json(fn() => $ajax->configureAws())['success']);
    $saved = SMARL_CloudConfig::load();
    smartsync_assert_same('https://s3.eu-west-1.amazonaws.com/workflow.dotted.bucket', $saved->getPublicBaseUrl());
    smartsync_assert_true($saved->useCdnForMedia && $saved->canEnableMediaCDN);
});

foreach (['aws' => 'eu-west-1', 'digitalocean' => 'nyc3', 'wasabi' => 'eu-central-1', 'cloudflare' => 'auto'] as $provider => $region) {
    foreach (['existing', 'new'] as $type) {
        smartsync_test('complete provider configuration and object operations: ' . $provider . ' ' . $type, function () use ($provider, $region, $type) {
            $config = smartsync_provider_config($provider); $commands = []; $objects = [];
            $config->fakeS3 = smartsync_provider_s3(function ($command) use (&$commands, &$objects, $region) {
                $name = $command->getName(); $commands[] = $name;
                if ($name === 'ListBuckets') return ['Buckets' => [['Name' => 'workflow-bucket']]];
                if ($name === 'GetBucketLocation') return ['LocationConstraint' => $region];
                if ($name === 'HeadBucket') return ['@metadata' => ['statusCode' => 200]];
                if ($name === 'GetBucketPolicy') return ['Policy' => '{"Version":"2012-10-17","Statement":[]}'];
                if ($name === 'PutObject') $objects[$command['Key']] = (string) $command['Body'];
                if ($name === 'GetObject') file_put_contents($command['@http']['sink'], $objects[$command['Key']]);
                if ($name === 'DeleteObject') unset($objects[$command['Key']]);
                if ($name === 'ListObjectsV2') return ['Contents' => array_map(fn($key) => ['Key' => $key, 'Size' => strlen($objects[$key])], array_keys($objects)), 'IsTruncated' => false];
                return [];
            });
            $ajax = new SmartSyncProviderAjax(null); $ajax->nextConfig = $config;
            $_POST = ['service_provider' => $provider, 'iam_key' => 'fixture-access', 'iam_secret' => 'fixture-secret', 'bucket_region' => $region,
                'cloudflare_endpoint' => $config->cloudflareEndpoint];
            $listing = smartsync_capture_json(fn() => $ajax->listS3Buckets());
            smartsync_assert_same(true, $listing['success']);
            smartsync_assert_same('workflow-bucket', $listing['data']['buckets'][0]['name']);
            $_POST += ['bucket_type' => $type, 'bucket_name' => 'workflow-bucket', 'use_cloudfront' => 'false', 'cloudflare_url' => 'workflow.example.test'];
            smartsync_assert_same(true, smartsync_capture_json(fn() => $ajax->configureAws())['success']);
            $saved = SMARL_CloudConfig::load();
            smartsync_assert_same($provider, $saved->serviceProvider);
            smartsync_assert_same($region, $saved->region);
            smartsync_assert_true(str_starts_with($saved->getPublicBaseUrl(), 'https://'));
            smartsync_assert_same($type === 'new', in_array('CreateBucket', $commands, true));
            $source = ABSPATH . 'provider-workflow.jpg'; $destination = ABSPATH . 'provider-restored.jpg';
            file_put_contents($source, 'real-provider-workflow-bytes');
            $config->uploadObject('nested/photo.jpg', $source);
            smartsync_assert_same([['key' => 'nested/photo.jpg', 'size' => 28]], $config->listObjects());
            $config->downloadObject('nested/photo.jpg', $destination);
            smartsync_assert_same(file_get_contents($source), file_get_contents($destination));
            $config->deleteObject('nested/photo.jpg'); smartsync_assert_same([], $config->listObjects());
        });
    }
}

foreach (['existing', 'new'] as $type) {
    smartsync_test('Google configuration uses uniform bucket access and merges public IAM membership: ' . $type, function () use ($type) {
        $credentialPath = ABSPATH . 'workflow-google.json';
        file_put_contents($credentialPath, json_encode(['type' => 'service_account', 'project_id' => 'fixture-project', 'client_email' => 'fixture@fixture-project.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nfixture\n-----END PRIVATE KEY-----", 'token_uri' => 'https://oauth2.googleapis.com/token']));
        set_transient('smarl_google_credentials_1', $credentialPath, 3600);
        $config = smartsync_provider_config('google'); $requests = []; $policyBody = null;
        $config->fakeGoogle = new \Google\Cloud\Storage\StorageClient(['projectId' => 'fixture-project', 'credentialsFetcher' => new \Google\Cloud\Core\AnonymousCredentials(),
            'httpHandler' => function ($request) use (&$requests, &$policyBody) {
                $method = $request->getMethod(); $uri = (string) $request->getUri(); $body = (string) $request->getBody(); $requests[] = [$method, $uri, $body];
                if ($method === 'GET' && str_contains($uri, '/iam')) return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"version":1,"etag":"fixture-etag","bindings":[{"role":"roles/storage.objectViewer","members":["user:existing@example.test"]}]}');
                if ($method === 'PUT' && str_contains($uri, '/iam')) { $policyBody = json_decode($body, true); return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], $body); }
                return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"name":"workflow-google","location":"US","iamConfiguration":{"uniformBucketLevelAccess":{"enabled":true}}}');
            }]);
        $ajax = new SmartSyncProviderAjax(null); $ajax->nextConfig = $config;
        $_POST = ['service_provider' => 'google', 'bucket_type' => $type, 'bucket_name' => 'workflow-google', 'bucket_region' => 'US', 'use_cloudfront' => 'false'];
        $response = smartsync_capture_json(fn() => $ajax->configureAws());
        smartsync_assert_same(true, $response['success']);
        smartsync_assert_same('https://storage.googleapis.com/workflow-google', SMARL_CloudConfig::load()->getPublicBaseUrl());
        smartsync_assert_same(1, count($policyBody['bindings']));
        smartsync_assert_same(['user:existing@example.test', 'allUsers'], $policyBody['bindings'][0]['members']);
        smartsync_assert_same('fixture-etag', $policyBody['etag']);
        $creation = array_values(array_filter($requests, fn($request) => $request[0] === 'POST'));
        smartsync_assert_same($type === 'new' ? 1 : 0, count($creation));
        if ($creation) smartsync_assert_same(true, json_decode($creation[0][2], true)['iamConfiguration']['uniformBucketLevelAccess']['enabled']);
    });
}
