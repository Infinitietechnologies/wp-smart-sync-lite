<?php
/** Native Bunny provider pipeline; run only in the separate disposable provider fixture. */
if (!defined('WP_CLI') || !WP_CLI || !str_contains(str_replace('\\', '/', ABSPATH), 'smartsync-provider-workflows/fresh/smartsync-wordpress-audit-fixture/wordpress/')) {
    throw new RuntimeException('Provider workflow tests require the fresh, isolated SmartSync provider fixture.');
}
if (!defined('DOING_AJAX')) define('DOING_AJAX', true);

use SmartSyncS3\Ajax\SMARL_AjaxHandler;
use SmartSyncS3\Ajax\SMARL_AwsConfigAjax;
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Models\SMARL_CloudConfig;
use SmartSyncS3\Models\SMARL_S3SyncSettings;

/** This fake replaces HTTP only: production configuration, serialization and storage dispatch run unchanged. */
final class SmartSyncProviderHttpFixture {
    public const ACCOUNT_KEY = 'fixture-account-api-key';
    public const STORAGE_PASSWORD = 'fixture-storage-password';
    public const STORAGE_HOST = 'la.storage.bunnycdn.com';
    public const ZONE = 'smarl-provider-fixture';
    public const PUBLIC_HOST = 'smarl-provider-fixture.b-cdn.net';
    public array $objects = [];
    public array $requests = [];
    public array $unexpected = [];
    public bool $badStorageCredentials = false;
    public bool $badStorageHostname = false;
    public string $failUploadKey = '';

    private function response(int $status, mixed $data, bool $json = true): array {
        return ['headers' => ['content-type' => $json ? 'application/json' : 'application/octet-stream'],
            'body' => $json ? wp_json_encode($data) : $data,
            'response' => ['code' => $status, 'message' => $status < 300 ? 'OK' : 'Fixture error'], 'cookies' => []];
    }
    public function request($preempt, array $args, string $url): array|WP_Error {
        $parts = wp_parse_url($url);
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '/';
        $method = strtoupper($args['method'] ?? 'GET');
        $key = $args['headers']['AccessKey'] ?? '';
        $this->requests[] = ['host' => $host, 'path' => $path, 'query' => $parts['query'] ?? '', 'method' => $method];
        if (($parts['scheme'] ?? '') !== 'https' || ($args['redirection'] ?? null) !== 0) {
            $this->unexpected[] = $url;
            return new WP_Error('fixture_unsafe_request', 'Unexpected transport settings.');
        }
        if ($host === 'api.bunny.net') {
            if ($key !== self::ACCOUNT_KEY) return $this->response(401, ['Message' => 'Invalid account key']);
            if ($method !== 'GET') return $this->response(405, []);
            if ($path === '/storagezone') {
                parse_str($parts['query'] ?? '', $query);
                if (($query['perPage'] ?? null) !== '1000' || ($query['includeDeleted'] ?? null) !== 'false') {
                    $this->unexpected[] = $url;
                    return $this->response(400, []);
                }
                $page = (int) ($query['page'] ?? 0);
                if ($page === 1) return $this->response(200, ['Items' => [[
                    'Id' => 11, 'Name' => 'fixture-inactive', 'Region' => 'DE', 'Password' => 'unused-fixture-password',
                    'PullZones' => [['Enabled' => false, 'Hostnames' => [['Value' => 'disabled.b-cdn.net', 'IsSystemHostname' => true]]]],
                ]], 'CurrentPage' => 1, 'HasMoreItems' => true]);
                if ($page === 2) return $this->response(200, ['Items' => [['Id' => 22, 'Name' => self::ZONE, 'Region' => 'DE']], 'CurrentPage' => 2, 'HasMoreItems' => false]);
            }
            if ($path === '/storagezone/22') return $this->response(200, [
                'Id' => 22, 'Name' => self::ZONE, 'Region' => 'DE',
                'Password' => $this->badStorageCredentials ? 'fixture-rejected-password' : self::STORAGE_PASSWORD,
                'StorageHostname' => $this->badStorageHostname ? 'https://storage.example.test' : self::STORAGE_HOST,
                'PullZones' => [
                    ['Enabled' => true, 'Hostnames' => [
                        ['Value' => self::PUBLIC_HOST, 'IsSystemHostname' => true],
                        ['Value' => 'cdn.fixture.example.test', 'HasCertificate' => true],
                        ['Value' => 'uncertified.fixture.example.test', 'HasCertificate' => false],
                        ['Value' => 'https://invalid.example.test', 'HasCertificate' => true],
                    ]],
                    ['Suspended' => true, 'Hostnames' => [['Value' => 'suspended.b-cdn.net']]],
                    ['ZoneSecurityEnabled' => true, 'Hostnames' => [['Value' => 'signed-only.b-cdn.net']]],
                ],
            ]);
        } elseif ($host === self::STORAGE_HOST && str_starts_with($path, '/' . self::ZONE . '/')) {
            if ($key !== self::STORAGE_PASSWORD) return $this->response(401, ['Message' => 'Invalid storage password']);
            $objectKey = rawurldecode(substr($path, strlen('/' . self::ZONE . '/')));
            if ($method === 'PUT') {
                if ($objectKey === $this->failUploadKey) return $this->response(201, ['Success' => false, 'Message' => 'Fixture upload rejected']);
                if (!is_string($args['body'] ?? null)) throw new RuntimeException('Bunny upload did not send actual file bytes.');
                $this->objects[$objectKey] = $args['body'];
                return $this->response(201, ['HttpCode' => 201, 'Message' => 'File uploaded.', 'Success' => true]);
            }
            if ($method === 'DELETE') {
                $exists = array_key_exists($objectKey, $this->objects);
                unset($this->objects[$objectKey]);
                return $this->response($exists ? 200 : 404, ['Success' => $exists]);
            }
            if ($method === 'GET' && ($objectKey === '' || str_ends_with($objectKey, '/'))) {
                $items = [];
                foreach ($this->objects as $storedKey => $bytes) {
                    if (!str_starts_with($storedKey, $objectKey)) continue;
                    $relative = substr($storedKey, strlen($objectKey));
                    $name = explode('/', $relative, 2)[0];
                    $directory = str_contains($relative, '/');
                    $items[$name] = ['ObjectName' => $name, 'IsDirectory' => $directory, 'Length' => $directory ? 0 : strlen($bytes)];
                }
                ksort($items);
                return $this->response(200, array_values($items));
            }
            if ($method === 'GET' && array_key_exists($objectKey, $this->objects)) {
                if (!empty($args['stream'])) {
                    if (!is_string($args['filename'] ?? null) || file_put_contents($args['filename'], $this->objects[$objectKey]) === false) throw new RuntimeException('Fixture stream could not write the requested temporary file.');
                    return $this->response(200, '', false) + ['filename' => $args['filename']];
                }
                return $this->response(200, $this->objects[$objectKey], false);
            }
            return $this->response(404, []);
        }
        $this->unexpected[] = $url;
        return new WP_Error('fixture_unmocked_http', 'Unmocked requests are blocked.');
    }
}

function smarl_provider_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function smarl_provider_register(?SMARL_CloudConfig $model = null): void {
    foreach (['check_dns_record', 'generate_ssl_certificate', 'list_s3_buckets', 'configure_aws', 'attach_to_cloudfront', 'clear_cloudfront_cache', 'toggle_cdn', 'toggle_media_auto_delete', 'sync_local_to_aws', 'stop_all_processes', 'sync_aws_to_local', 'set_sync_state', 'get_sync_state', 'check_sync_status', 'fetch_aws_analytics'] as $action) remove_all_actions('wp_ajax_' . $action);
    remove_all_actions('process_s3_sync_batch');
    $model ??= SMARL_CloudConfig::load();
    smarl_provider_assert(get_class($model) === SMARL_CloudConfig::class, 'The workflow must use the native configuration/storage model.');
    (new SMARL_AwsConfigAjax($model))->initializeActions();
    (new SMARL_AjaxHandler($model))->initializeActions();
}
function smarl_provider_ajax(string $action, array $post = [], bool $fresh = true, ?string $nonce = null): array {
    if ($fresh) smarl_provider_register();
    $_POST = $post + ['nonce' => $nonce ?? wp_create_nonce('smarl_nonce')];
    $_REQUEST = $_POST;
    http_response_code(200);
    ob_start();
    try {
        $fiber = new Fiber(static fn() => do_action('wp_ajax_' . $action));
        $die = $fiber->start();
        $body = ob_get_contents();
    } finally { ob_end_clean(); }
    return ['json' => json_decode($body, true), 'body' => $body, 'status' => $die['args']['response'] ?? http_response_code(), 'die' => $die];
}
function smarl_provider_progress(): SMARL_S3SyncSettings { $progress = new SMARL_S3SyncSettings(); $progress->load(); return $progress; }
function smarl_provider_jobs(): array {
    return as_get_scheduled_actions(['hook' => 'process_s3_sync_batch', 'group' => 'smartsync', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1000, 'orderby' => 'action_id', 'order' => 'ASC'], 'ids');
}
function smarl_provider_drain(): int {
    $executed = 0;
    while ($ids = smarl_provider_jobs()) {
        foreach ($ids as $id) {
            if (++$executed > 1000) throw new RuntimeException('The native provider scheduler failed to terminate.');
            // Model and handlers are reconstructed for each persisted job, like a separate worker request.
            smarl_provider_register();
            $action = ActionScheduler::store()->fetch_action($id);
            smarl_provider_assert(count($action->get_args()) === 5 && $action->get_group() === 'smartsync', 'The stored job lost its five-argument run contract.');
            ActionScheduler_QueueRunner::instance()->process_action((int) $id, 'SmartSync Provider Integration');
            smarl_provider_assert(ActionScheduler::store()->get_status($id) === ActionScheduler_Store::STATUS_COMPLETE, 'Actual Action Scheduler execution did not complete.');
        }
    }
    return $executed;
}
function smarl_provider_configuration(): array {
    return ['service_provider' => 'bunny', 'bucket_type' => 'existing', 'bucket_name' => SmartSyncProviderHttpFixture::ZONE,
        'bucket_region' => 'DE', 'iam_key' => SmartSyncProviderHttpFixture::PUBLIC_HOST,
        'iam_secret' => SmartSyncProviderHttpFixture::ACCOUNT_KEY, 'use_cloudfront' => 'false'];
}
function smarl_provider_list(string $key = SmartSyncProviderHttpFixture::ACCOUNT_KEY): array {
    return smarl_provider_ajax('list_s3_buckets', ['service_provider' => 'bunny', 'iam_secret' => $key]);
}

global $wpdb;
wp_set_current_user(1);
smarl_provider_assert(current_user_can('manage_options'), 'The fresh fixture administrator is required.');
$http = new SmartSyncProviderHttpFixture();
add_filter('pre_http_request', [$http, 'request'], PHP_INT_MAX, 3);
$ajaxDieHandler = static fn() => static function ($message = '', $title = '', $args = []) {
    Fiber::suspend(['message' => $message, 'title' => $title, 'args' => $args]);
};
add_filter('wp_die_ajax_handler', $ajaxDieHandler, PHP_INT_MAX);
$core = null;
foreach (($GLOBALS['wp_filter']['wp_delete_file']->callbacks ?? []) as $callbacks) foreach ($callbacks as $callback) {
    if (is_array($callback['function']) && $callback['function'][0] instanceof \SmartSyncS3\SMARL_SmartSync) $core = $callback['function'][0];
}
smarl_provider_assert($core instanceof \SmartSyncS3\SMARL_SmartSync, 'The actual core bootstrap is required.');
$originalCoreConfig = (new ReflectionProperty($core, 'config'))->getValue($core);
$optionNames = ['smarl_settings', 'smarl_sync_settings', 'custom_url_option', 'smarl_sync_lock', 'smarl_queue_generation'];
$options = [];
foreach ($optionNames as $name) $options[$name] = get_option($name, null);
$transientName = 'smarl_bunny_zones_' . get_current_user_id();
$originalTransient = get_transient($transientName);
$table = $wpdb->prefix . 'smarl_file_sync_queue';
$originalQueue = $wpdb->get_results("SELECT * FROM `{$table}`", ARRAY_A);
$originalJobs = as_get_scheduled_actions(['hook' => 'process_s3_sync_batch', 'per_page' => 10000], 'ids');
smarl_provider_assert(!smarl_provider_jobs(), 'Use an idle isolated fixture so no prior pending jobs are canceled.');
$directory = wp_upload_dir()['basedir'] . '/smarl-native-bunny-' . bin2hex(random_bytes(5));
wp_mkdir_p($directory);
$files = [];
$attachmentId = 0;
$passed = 0;

try {
    SMARL_AjaxHandler::resetSyncState(new SMARL_S3SyncSettings());
    delete_option('smarl_sync_lock');
    $listing = smarl_provider_list();
    smarl_provider_assert(($listing['json']['success'] ?? false) === true, 'Native Bunny discovery failed: ' . $listing['body']);
    $buckets = $listing['json']['data']['buckets'];
    smarl_provider_assert(count($buckets) === 2 && $buckets[1]['name'] === SmartSyncProviderHttpFixture::ZONE, 'The real wizard handler failed official wrapper pagination.');
    smarl_provider_assert($buckets[1]['pullzones'] === [SmartSyncProviderHttpFixture::PUBLIC_HOST, 'cdn.fixture.example.test'], 'Discovery exposed unsafe/inactive/signed-only/uncertified hostnames.');
    smarl_provider_assert(!empty($buckets[0]['issue']) && $buckets[0]['pullzones'] === [], 'An inactive pull zone was presented as usable.');
    smarl_provider_assert(!str_contains($listing['body'], SmartSyncProviderHttpFixture::STORAGE_PASSWORD), 'Storage credentials leaked into browser discovery JSON.');
    smarl_provider_assert(get_transient($transientName)['zones'][SmartSyncProviderHttpFixture::ZONE]['password'] === SmartSyncProviderHttpFixture::STORAGE_PASSWORD, 'Detailed storage credentials were not held in the user-bound transient.');
    WP_CLI::log('PASS native Bunny discovery follows account pagination/details and exposes only usable HTTPS delivery hosts'); $passed++;

    $configured = smarl_provider_ajax('configure_aws', smarl_provider_configuration());
    smarl_provider_assert(($configured['json']['success'] ?? false) === true, 'Native Bunny configuration failed: ' . $configured['body']);
    $model = SMARL_CloudConfig::load();
    smarl_provider_assert(get_class($model) === SMARL_CloudConfig::class && $model->isConfigured(), 'Saved native Bunny configuration cannot be reloaded.');
    smarl_provider_assert($model->getBunnyStorageEndpoint() === 'https://' . SmartSyncProviderHttpFixture::STORAGE_HOST && $model->region === 'DE', 'The provider storage hostname was replaced by a guessed region endpoint.');
    smarl_provider_assert($model->getPublicBaseUrl() === 'https://' . SmartSyncProviderHttpFixture::PUBLIC_HOST && !$model->canEnableMediaCDN, 'Setup reported CDN eligibility before any verified upload.');
    smarl_provider_assert(get_transient($transientName) === false, 'Successful configuration retained the credential selection transient.');
    smarl_provider_assert(count(array_filter($http->requests, static fn($r) => $r['host'] === SmartSyncProviderHttpFixture::STORAGE_HOST && $r['path'] === '/' . SmartSyncProviderHttpFixture::ZONE . '/')) === 1, 'Configure did not verify storage root access before saving.');
    WP_CLI::log('PASS native configure AJAX verifies storage access then persists separate account/storage credentials and endpoint'); $passed++;

    for ($i = 0; $i < 16; $i++) {
        $path = $directory . '/bulk file +' . $i . '.jpg';
        file_put_contents($path, "native-bunny-fixture-{$i}\0\xFF\n" . str_repeat((string) $i, 31));
        $files[] = $path;
    }
    $expected = [];
    foreach (SMARL_Helper::scanSyncFiles('media', SMARL_Helper::getExtension('media')) as $file) $expected[$file['relative_path']] = file_get_contents($file['path']);
    $upload = smarl_provider_ajax('sync_local_to_aws', ['type' => 'media']);
    smarl_provider_assert(($upload['json']['success'] ?? false) === true && $upload['json']['data']['total_files'] === count($expected), 'Native upload did not queue the actual 16 new files plus WordPress assets.');
    $executed = smarl_provider_drain();
    $actual = $http->objects; ksort($actual); ksort($expected);
    smarl_provider_assert($actual === $expected && $executed > 1, 'Native model HTTP uploads skipped keys, changed bytes, or failed to cross a persisted batch boundary.');
    smarl_provider_assert(smarl_provider_progress()->s3SyncStatus === 'completed' && smarl_provider_progress()->s3SyncProcessedFiles === count($expected), 'Actual AS workers failed to complete every native Bunny SQL row.');
    smarl_provider_assert(SMARL_CloudConfig::load()->canEnableMediaCDN, 'Successful native provider run failed to grant delivery eligibility.');
    WP_CLI::log('PASS native Bunny uploads exact bytes for 16 new files plus ' . (count($expected) - 16) . ' WordPress assets through ' . $executed . ' stored Action Scheduler executions'); $passed++;

    $stale = new SMARL_CloudConfig(); $stale->serviceProvider = 'aws'; $stale->bucket = 'old-fixture-bucket';
    smarl_provider_register($stale);
    $cdn = smarl_provider_ajax('toggle_cdn', ['type' => 'media', 'useCdn' => 'true'], false);
    smarl_provider_assert(($cdn['json']['success'] ?? false) === true, 'A stale request did not reload current provider/eligibility before CDN toggle: ' . $cdn['body']);
    smarl_provider_register($stale);
    $cleanup = smarl_provider_ajax('toggle_media_auto_delete', ['state' => 'true'], false);
    $model = SMARL_CloudConfig::load();
    smarl_provider_assert(($cleanup['json']['success'] ?? false) === true && $model->serviceProvider === 'bunny' && $model->useCdnForMedia && $model->mediaAutoDelete && $model->bunnyStoragePassword === SmartSyncProviderHttpFixture::STORAGE_PASSWORD, 'A settings write reverted the configured provider, credentials or delivery flags.');
    $core->switchBlogConfiguration(get_current_blog_id());
    $localUrl = wp_upload_dir()['baseurl'] . '/' . basename($directory) . '/' . rawurlencode(basename($files[0]));
    $rewritten = $core->getAttachmentUrl($localUrl . '?size=full#fixture');
    $encodedKey = implode('/', array_map('rawurlencode', explode('/', SMARL_Helper::relativeKeyForPath($files[0]))));
    smarl_provider_assert($rewritten === 'https://' . SmartSyncProviderHttpFixture::PUBLIC_HOST . '/' . $encodedKey . '?size=full#fixture', 'Fresh current-provider media rewriting did not preserve the storage object key/query/fragment.');
    WP_CLI::log('PASS fresh and stale settings requests preserve Bunny configuration and rewrite media to its verified public host'); $passed++;

    foreach ($files as $path) unlink($path);
    $restore = smarl_provider_ajax('sync_aws_to_local', ['type' => 'media']);
    smarl_provider_assert(($restore['json']['success'] ?? false) === true && $restore['json']['data']['total_files'] === count($expected), 'Native Bunny directory walking failed to create the full restore manifest: ' . $restore['body']);
    $executed = smarl_provider_drain();
    smarl_provider_assert(smarl_provider_progress()->s3SyncStatus === 'completed' && smarl_provider_progress()->s3SyncProcessedFiles === count($expected), 'Native Bunny restore did not finish every SQL row.');
    foreach ($expected as $key => $bytes) smarl_provider_assert(file_get_contents(SMARL_Helper::resolveLocalPath($key)) === $bytes, 'Native streamed restore changed bytes: ' . $key);
    smarl_provider_assert($executed > 1 && !glob($directory . '/.smartsync-*'), 'Native restore left temporary files or bypassed its batch boundary.');
    WP_CLI::log('PASS native Bunny recursive listing and streamed restore replace every file with byte-exact data'); $passed++;

    require_once ABSPATH . 'wp-admin/includes/image.php';
    $imagePath = $directory . '/attachment-original.jpg';
    $files[] = $imagePath;
    $image = imagecreatetruecolor(800, 600); imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 150)); imagejpeg($image, $imagePath, 90); imagedestroy($image);
    $core->switchBlogConfiguration(get_current_blog_id());
    $attachmentId = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'Native Bunny fixture', 'post_status' => 'inherit'], $imagePath, 0, true);
    smarl_provider_assert(!is_wp_error($attachmentId), 'Fixture attachment insertion failed.');
    $metadata = wp_generate_attachment_metadata($attachmentId, $imagePath);
    wp_update_attachment_metadata($attachmentId, $metadata);
    foreach ($metadata['sizes'] ?? [] as $size) $files[] = dirname($imagePath) . '/' . $size['file'];
    $manifest = get_post_meta($attachmentId, '_smarl_offloaded_files', true);
    smarl_provider_assert(is_array($manifest) && count($manifest) > 1 && !get_post_meta($attachmentId, '_smarl_offload_error', true), 'Final actual metadata did not upload the complete attachment through native Bunny HTTP.');
    $beforeDeletes = count(array_filter($http->requests, static fn($r) => $r['method'] === 'DELETE'));
    $core->flushAttachmentUploads();
    foreach ($manifest as $key) smarl_provider_assert(!is_file(SMARL_Helper::resolveLocalPath($key)) && isset($http->objects[$key]), 'Local automatic cleanup lost its native Bunny remote copy.');
    smarl_provider_assert($beforeDeletes === count(array_filter($http->requests, static fn($r) => $r['method'] === 'DELETE')), 'Automatic local cleanup unexpectedly sent remote DELETE.');
    smarl_provider_assert(str_starts_with(wp_get_attachment_url($attachmentId), 'https://' . SmartSyncProviderHttpFixture::PUBLIC_HOST . '/'), 'Actual attachment delivery remained local after cleanup.');
    smarl_provider_assert(!str_contains(wp_get_attachment_image_srcset($attachmentId, 'full') ?: '', wp_upload_dir()['baseurl']), 'Responsive native attachment delivery retained local URLs after cleanup.');
    wp_delete_attachment($attachmentId, true); $attachmentId = 0;
    foreach ($manifest as $key) smarl_provider_assert(!isset($http->objects[$key]), 'Intentional attachment deletion retained a tracked Bunny object.');
    smarl_provider_assert(count(array_filter($http->requests, static fn($r) => $r['method'] === 'DELETE')) - $beforeDeletes === count($manifest), 'Intentional native attachment deletion skipped or duplicated remote deletion.');
    WP_CLI::log('PASS actual WordPress metadata, responsive URLs, automatic local cleanup and intentional remote deletion use native Bunny storage'); $passed++;

    $saved = SMARL_CloudConfig::load()->toArray();
    $invalidAccount = smarl_provider_list('fixture-invalid-account-key');
    smarl_provider_assert(($invalidAccount['json']['success'] ?? true) === false && str_contains($invalidAccount['body'], 'account API key'), 'Invalid account credentials did not report an actionable discovery error.');
    smarl_provider_assert(SMARL_CloudConfig::load()->toArray() === $saved && get_transient($transientName) === false, 'Invalid account discovery changed working configuration or retained stale storage credentials.');
    $http->badStorageCredentials = true; smarl_provider_list();
    $invalidStorage = smarl_provider_ajax('configure_aws', smarl_provider_configuration());
    smarl_provider_assert(($invalidStorage['json']['success'] ?? true) === false && str_contains($invalidStorage['body'], 'Bunny storage rejected access'), 'Storage credentials were not verified before configuration success.');
    smarl_provider_assert(SMARL_CloudConfig::load()->toArray() === $saved && !str_contains($invalidStorage['body'], 'fixture-rejected-password'), 'Rejected storage setup overwrote working settings or disclosed its credential.');
    $http->badStorageCredentials = false; $http->badStorageHostname = true;
    $invalidHost = smarl_provider_list();
    smarl_provider_assert(($invalidHost['json']['success'] ?? true) === false && SMARL_CloudConfig::load()->toArray() === $saved, 'An untrusted storage endpoint was accepted or changed configuration.');
    $http->badStorageHostname = false; smarl_provider_list();
    $wrongPullzone = smarl_provider_configuration(); $wrongPullzone['iam_key'] = 'unrelated.b-cdn.net';
    $invalidSelection = smarl_provider_ajax('configure_aws', $wrongPullzone);
    smarl_provider_assert(($invalidSelection['json']['success'] ?? true) === false && SMARL_CloudConfig::load()->toArray() === $saved, 'A hostname outside the selected storage zone was accepted.');
    $repaired = smarl_provider_ajax('configure_aws', smarl_provider_configuration());
    smarl_provider_assert(($repaired['json']['success'] ?? false) === true && SMARL_CloudConfig::load()->toArray() === $saved, 'Valid same-target credential repair reset delivery flags or changed the target.');
    $requestsBefore = count($http->requests);
    $invalidNonce = smarl_provider_ajax('list_s3_buckets', ['service_provider' => 'bunny', 'iam_secret' => SmartSyncProviderHttpFixture::ACCOUNT_KEY], true, 'fixture-invalid-nonce');
    smarl_provider_assert(($invalidNonce['die']['message'] ?? null) === -1 && count($http->requests) === $requestsBefore, 'Actual nonce rejection reached provider HTTP.');
    WP_CLI::log('PASS invalid account/storage/endpoint/pull-zone requests preserve working configuration and valid credential repair retains delivery'); $passed++;

    $http->failUploadKey = SMARL_Helper::relativeKeyForPath($files[0]);
    $failedUpload = smarl_provider_ajax('sync_local_to_aws', ['type' => 'media']);
    smarl_provider_assert(($failedUpload['json']['success'] ?? false) === true, 'Failure regression did not start its native upload run.');
    smarl_provider_drain();
    $failedProgress = smarl_provider_progress();
    smarl_provider_assert($failedProgress->s3SyncStatus === 'failed' && $failedProgress->s3SyncProcessedFiles === count($expected) && count($failedProgress->s3SyncErrors) === 1, 'HTTP 201 with Success:false was reported as a successful transfer or skipped other rows.');
    $failedConfig = SMARL_CloudConfig::load();
    smarl_provider_assert(!$failedConfig->canEnableMediaCDN && !$failedConfig->useCdnForMedia && $failedConfig->serviceProvider === 'bunny', 'A failed native upload granted CDN eligibility or reverted the provider.');
    $deniedCdn = smarl_provider_ajax('toggle_cdn', ['type' => 'media', 'useCdn' => 'true']);
    smarl_provider_assert(($deniedCdn['json']['success'] ?? true) === false, 'Failed native provider run could enable CDN delivery.');
    smarl_provider_assert(!$http->unexpected, 'The workflow attempted unmocked or unsafe external HTTP.');
    WP_CLI::log('PASS native Bunny application-level upload rejection fails its SQL row/run and prevents CDN enablement'); $passed++;
} finally {
    wp_set_current_user(1);
    // Remove only this fixture's explicitly tracked attachment/files/jobs and restore prior plugin state.
    if ($attachmentId) wp_delete_attachment($attachmentId, true);
    remove_all_actions('process_s3_sync_batch');
    foreach (as_get_scheduled_actions(['hook' => 'process_s3_sync_batch', 'per_page' => 10000], 'ids') as $id) if (!in_array($id, $originalJobs, false)) ActionScheduler::store()->delete_action($id);
    $wpdb->query("DELETE FROM `{$table}`");
    foreach ($originalQueue as $row) $wpdb->insert($table, $row);
    foreach ($options as $name => $value) { if ($value === null) delete_option($name); else update_option($name, $value, false); }
    if ($originalTransient === false) delete_transient($transientName); else set_transient($transientName, $originalTransient, 900);
    (new ReflectionProperty($core, 'config'))->setValue($core, $originalCoreConfig);
    foreach ($files as $path) if (str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $directory) . '/') && is_file($path)) unlink($path);
    if (is_dir($directory)) rmdir($directory);
    remove_filter('wp_die_ajax_handler', $ajaxDieHandler, PHP_INT_MAX);
}
WP_CLI::success($passed . ' native Bunny provider workflow cases passed; account/storage HTTP was stateful and local.');
