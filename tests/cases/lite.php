<?php
use SmartSyncS3\Ajax\SMARL_AjaxHandler;
use SmartSyncS3\Ajax\SMARL_AwsConfigAjax;
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Models\SMARL_CloudConfig;
use SmartSyncS3\SMARL_Edition;

smartsync_test('Lite exposes exactly four operational storage providers and removes Pro clients', function () {
    smartsync_assert_same(['aws', 'digitalocean', 'wasabi', 'bunny'], SMARL_CloudConfig::PROVIDERS);
    foreach (['getCloudfrontClient', 'getAcmClient', 'getStsClient', 'getGoogleStorageClient', 'createGoogleBucket', 'configureGooglePublicAccess', 'validateCloudflareEndpoint'] as $method) {
        smartsync_assert_true(!method_exists(SMARL_CloudConfig::class, $method), 'Pro integration implementation remains: ' . $method);
    }
    smartsync_assert_true(!class_exists('SmartSyncS3\\Services\\SMARLI_GoogleService'));
    smartsync_assert_true(!class_exists('Google\\Cloud\\Storage\\StorageClient'));
    smartsync_assert_true(!method_exists(SMARL_AwsConfigAjax::class, 'setupCustomCloudFront'));
    smartsync_assert_true(!method_exists(SMARL_AjaxHandler::class, 'purgeCloudFrontCache'));
    smartsync_assert_true(!method_exists(SMARL_Helper::class, 'checkCNAME'));
});

foreach (['google', 'cloudflare'] as $provider) {
    smartsync_test('Lite rejects crafted provider discovery and configuration before HTTP or persistence: ' . $provider, function () use ($provider) {
        $saved = ['bucket' => 'untouched', 'service_provider' => 'aws'];
        update_option('smarl_settings', $saved);
        $handler = new SMARL_AwsConfigAjax(null);
        $_POST = ['service_provider' => $provider, 'bucket_type' => 'existing', 'bucket_name' => 'fixture-bucket', 'bucket_region' => 'us-east-1', 'iam_key' => 'fixture', 'iam_secret' => 'fixture'];
        foreach (['listS3Buckets', 'configureAws'] as $method) {
            $response = smartsync_capture_json(fn() => $handler->$method());
            smartsync_assert_same(403, $response['status']);
            smartsync_assert_true(str_contains($response['data'], 'Pro only'));
            smartsync_assert_same($saved, get_option('smarl_settings'));
        }
        smartsync_assert_same([], $GLOBALS['smartsync_test_http_requests']);
        smartsync_assert_same(false, get_option('smarl_sync_lock'));
    });
}

smartsync_test('Lite rejects CloudFront provisioning and every legacy Pro AJAX route without reading credentials', function () {
    $handler = new SMARL_AwsConfigAjax(null);
    $_POST = ['service_provider' => 'aws', 'bucket_type' => 'existing', 'bucket_name' => 'fixture-bucket', 'bucket_region' => 'us-east-1', 'iam_key' => 'fixture', 'iam_secret' => 'fixture', 'use_cloudfront' => 'true'];
    smartsync_assert_same(403, smartsync_capture_json(fn() => $handler->configureAws())['status']);
    $handler->initializeActions();
    (new SMARL_AjaxHandler(null))->initializeActions();
    foreach (['get_saved_google_credentials', 'setup_custom_cloudfront', 'generate_ssl_certificate', 'check_dns_record', 'attach_to_cloudfront', 'clear_cloudfront_cache'] as $action) {
        smartsync_assert_same(403, smartsync_capture_json(fn() => do_action('wp_ajax_' . $action))['status']);
    }
    smartsync_assert_same([], $GLOBALS['smartsync_test_http_requests']);
    smartsync_assert_same(false, get_option('smarl_settings'));
});

smartsync_test('Lite rejects all asset sync and delivery requests, including network administrators', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    $GLOBALS['smartsync_test_multisite'] = $GLOBALS['smartsync_test_can_manage_network'] = true;
    $saved = get_option('smarl_settings');
    $_POST = ['type' => 'assets', 'useCdn' => 'true'];
    foreach (['syncLocalToAws', 'syncAwsToLocal', 'toggleCdn'] as $method) {
        $response = smartsync_capture_json(fn() => $handler->$method());
        smartsync_assert_same(false, $response['success']);
        smartsync_assert_true(str_contains($response['data'], 'Pro only'));
    }
    smartsync_assert_same($saved, get_option('smarl_settings'));
    smartsync_assert_same([], $GLOBALS['smartsync_test_jobs']);
    smartsync_assert_same([], $storage->uploads);
    smartsync_assert_same([], $storage->downloads);
    smartsync_assert_same([], SMARL_Helper::getExtension('assets'));
});

smartsync_test('Lite refuses an inherited asset background job without uploading any queued files', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    $file = wp_upload_dir()['basedir'] . '/retained.jpg'; file_put_contents($file, 'keep-local');
    $_POST = ['type' => 'media']; smartsync_assert_same(true, smartsync_capture_json(fn() => $handler->syncLocalToAws())['success']);
    $progress = smartsync_progress(); $progress->syncType = 'assets'; $progress->save();
    smartsync_run_jobs($handler);
    smartsync_assert_same([], $storage->uploads);
    smartsync_assert_same('failed', smartsync_progress()->s3SyncStatus);
    smartsync_assert_same('keep-local', file_get_contents($file));
});

smartsync_test('Lite rejects injected JS queue rows even when the saved media extension list was tampered with', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    $image = wp_upload_dir()['basedir'] . '/allowed.jpg'; file_put_contents($image, 'media');
    $script = wp_upload_dir()['basedir'] . '/blocked.js'; file_put_contents($script, 'keep-script');
    $_POST = ['type' => 'media']; smartsync_capture_json(fn() => $handler->syncLocalToAws());
    $progress = smartsync_progress(); $progress->extensions[] = 'js'; $progress->s3SyncTotalFiles++;
    $GLOBALS['wpdb']->insert('wp_smarl_file_sync_queue', ['path' => $script, 'relative_path' => 'wp-content/uploads/blocked.js', 'run_id' => $progress->runId, 'status' => 'pending', 'size' => 11]);
    $progress->save(); smartsync_run_jobs($handler);
    smartsync_assert_same(['wp-content/uploads/allowed.jpg'], $storage->uploads);
    smartsync_assert_same('keep-script', file_get_contents($script));
    smartsync_assert_same('failed', smartsync_progress()->s3SyncStatus);
    foreach (['file.js', 'FILE.CSS', 'module.mjs', 'common.cjs'] as $key) smartsync_assert_throws(fn() => SMARL_CloudConfig::validateObjectKey($key), InvalidArgumentException::class);
});

foreach (['google', 'cloudflare', 'cloudfront'] as $feature) {
    smartsync_test('Lite preserves existing Pro settings while disabling clients, public delivery and workers: ' . $feature, function () use ($feature) {
        $config = SMARL_CloudConfig::fromArray(['service_provider' => $feature === 'cloudfront' ? 'aws' : $feature, 'bucket' => 'legacy-pro-bucket', 'iam_key' => 'fixture-key', 'iam_secret' => 'fixture-secret', 'url' => 'legacy.example.test', 'use_cloudfront' => $feature === 'cloudfront', 'googleServicePath' => '/unread/pro/credential.json', 'use_cdn_for_media' => true]);
        $config->save(); $saved = get_option('smarl_settings');
        smartsync_assert_same(false, $config->isConfigured());
        smartsync_assert_same('', $config->getPublicBaseUrl());
        smartsync_assert_throws(fn() => $config->getS3Client());
        smartsync_assert_throws(fn() => $config->listObjects());
        $handler = new SMARL_AjaxHandler($config);
        $_POST = ['type' => 'media']; smartsync_assert_same(false, smartsync_capture_json(fn() => $handler->syncLocalToAws())['success']);
        smartsync_assert_same($saved, get_option('smarl_settings'));
        smartsync_assert_same([], $GLOBALS['smartsync_test_http_requests']);
        smartsync_assert_same([], $GLOBALS['smartsync_test_jobs']);
    });
}

smartsync_test('Lite registers media rewriting but never registers script or style delivery filters', function () {
    $config = new SmartSyncCoreStorage(); $core = smartsync_core_instance($config);
    smartsync_assert_same([], $GLOBALS['smartsync_test_hooks']['script_loader_src'] ?? []);
    smartsync_assert_same([], $GLOBALS['smartsync_test_hooks']['style_loader_src'] ?? []);
    smartsync_assert_true(!method_exists($core, 'replaceBaseUrl'));
});
