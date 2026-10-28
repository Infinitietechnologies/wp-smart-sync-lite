<?php
/** Run with wp eval-file against the disposable SQLite audit fixture, never a live site. */
if (!defined('WP_CLI') || !WP_CLI || strpos(str_replace('\\', '/', ABSPATH), 'smartsync-wordpress-audit-fixture/wordpress/') === false) {
    throw new RuntimeException('These integration tests require the disposable SmartSync WordPress audit fixture.');
}
if (!defined('DOING_AJAX')) define('DOING_AJAX', true);

use SmartSyncS3\Ajax\SMARL_AjaxHandler;
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Models\SMARL_CloudConfig;
use SmartSyncS3\Models\SMARL_S3SyncSettings;

add_filter('pre_http_request', static fn() => new WP_Error('smarl_fixture_http_blocked', 'External HTTP is blocked in the sync integration test.'), PHP_INT_MAX);
add_filter('wp_die_ajax_handler', static fn() => static function ($message = '', $title = '', $args = []) {
    Fiber::suspend(['message' => $message, 'title' => $title, 'args' => $args]);
}, PHP_INT_MAX);

final class SmartSyncSqlIntegrationStorage extends SMARL_CloudConfig {
    public array $objects = [];
    public array $uploads = [];
    public array $downloads = [];
    public string $failKey = '';
    public mixed $duringTransfer = null;
    public function __construct() {
        $this->bucket = 'fixture-no-cloud'; $this->serviceProvider = 'aws'; $this->region = 'us-east-1';
        $this->iamKey = 'fixture-access-only'; $this->iamSecret = 'fixture-secret-only'; $this->url = 'cdn.example.test';
    }
    public function isConfigured(): bool { return true; }
    public function getS3Client(string $region = ''): ?\Aws\S3\S3Client { throw new RuntimeException('An integration test attempted to construct a real cloud client.'); }
    public function uploadObject(string $key, string $path): void {
        if ($key === $this->failKey) throw new RuntimeException('Fixture transfer failure.');
        $this->uploads[$key] = hash_file('sha256', $path);
        if ($this->duringTransfer !== null) ($this->duringTransfer)();
    }
    public function listObjects(): array {
        $rows = [];
        foreach ($this->objects as $key => $bytes) $rows[] = ['key' => $key, 'size' => strlen($bytes)];
        return $rows;
    }
    public function downloadObject(string $key, string $destination): void {
        if ($key === $this->failKey) throw new RuntimeException('Fixture transfer failure.');
        $this->downloads[] = $key;
        if (file_put_contents($destination, $this->objects[$key]) === false) throw new RuntimeException('Fixture could not write a restore file.');
        if ($this->duringTransfer !== null) ($this->duringTransfer)();
    }
}

function smarl_sql_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function smarl_sql_ajax(string $action, array $post = [], ?string $nonce = null): array {
    $_POST = $post + ['nonce' => $nonce ?? wp_create_nonce('smarl_nonce')];
    $_REQUEST = $_POST;
    status_header(200);
    ob_start();
    try {
        $fiber = new Fiber(static fn() => do_action('wp_ajax_' . $action));
        $die = $fiber->start();
        $body = ob_get_contents();
    } finally { ob_end_clean(); }
    $json = json_decode($body, true);
    return ['json' => $json, 'body' => $body, 'status' => $die['args']['response'] ?? http_response_code(), 'die' => $die];
}
function smarl_sql_progress(): SMARL_S3SyncSettings { $model = new SMARL_S3SyncSettings(); $model->load(); return $model; }
function smarl_sql_handler(): array {
    foreach (['clear_cloudfront_cache', 'toggle_cdn', 'toggle_media_auto_delete', 'sync_local_to_aws', 'stop_all_processes', 'sync_aws_to_local', 'set_sync_state', 'get_sync_state', 'check_sync_status', 'fetch_aws_analytics'] as $action) remove_all_actions('wp_ajax_' . $action);
    remove_all_actions('process_s3_sync_batch');
    $storage = new SmartSyncSqlIntegrationStorage(); $storage->save();
    SMARL_AjaxHandler::resetSyncState(new SMARL_S3SyncSettings());
    $handler = new SMARL_AjaxHandler($storage); $handler->initializeActions();
    return [$storage, $handler];
}
function smarl_sql_pending(): array {
    return as_get_scheduled_actions(['hook' => 'process_s3_sync_batch', 'group' => 'smartsync', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1000, 'orderby' => 'action_id', 'order' => 'ASC'], 'ids');
}
function smarl_sql_dispatch(int $id): array {
    $action = ActionScheduler::store()->fetch_action($id);
    $arguments = $action->get_args();
    ActionScheduler::store()->cancel_action($id);
    do_action_ref_array('process_s3_sync_batch', array_values($arguments));
    return $arguments;
}
function smarl_sql_drain(): void {
    for ($count = 0; $ids = smarl_sql_pending(); $count++) {
        if ($count > 500) throw new RuntimeException('The SQL scheduler queue failed to terminate.');
        foreach ($ids as $id) smarl_sql_dispatch((int) $id);
    }
}

global $wpdb;
wp_set_current_user(1);
smarl_sql_assert(current_user_can('manage_options'), 'The fixture must have an administrator at user 1.');
$table = $wpdb->prefix . 'smarl_file_sync_queue';
$columns = $wpdb->get_col("DESCRIBE `{$table}`", 0);
smarl_sql_assert(in_array('size', $columns, true) && in_array('run_id', $columns, true), 'Activate the plugin schema before running these integration tests.');
$savedOptions = [];
foreach (['smarl_settings', 'smarl_sync_settings', 'smarl_sync_lock', 'custom_url_option'] as $name) $savedOptions[$name] = get_option($name, null);
$savedQueue = $wpdb->get_results("SELECT * FROM `{$table}`", ARRAY_A);
$originalIds = as_get_scheduled_actions(['hook' => 'process_s3_sync_batch', 'per_page' => 10000], 'ids');
$pendingSnapshot = [];
foreach (as_get_scheduled_actions(['hook' => 'process_s3_sync_batch', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 10000], 'ids') as $id) {
    $action = ActionScheduler::store()->fetch_action($id);
    $pendingSnapshot[] = ['id' => $id, 'args' => $action->get_args(), 'group' => $action->get_group(), 'timestamp' => $action->get_schedule()->get_date()->getTimestamp()];
}
$directory = wp_upload_dir()['basedir'] . '/smarl-sync-integration-' . bin2hex(random_bytes(6));
wp_mkdir_p($directory);
$createdFiles = [];
$passed = 0;

try {
    foreach ([16, 31] as $total) {
        [$storage] = smarl_sql_handler();
        for ($i = 0; $i < $total; $i++) {
            $path = $directory . '/restore-' . $total . '-' . $i . '.jpg';
            $key = SMARL_Helper::relativeKeyForPath($path);
            $storage->objects[$key] = 'remote-fixture-bytes-' . $total . '-' . $i;
            $createdFiles[] = $path;
        }
        $response = smarl_sql_ajax('sync_aws_to_local', ['type' => 'media']);
        smarl_sql_assert(($response['json']['success'] ?? false) === true, 'Restore start did not emit successful actual WordPress JSON: ' . $response['body']);
        smarl_sql_assert(count(smarl_sql_pending()) === 1, 'A restore must persist its first Action Scheduler job.');
        $queuedSizes = $wpdb->get_col("SELECT size FROM `{$table}`");
        smarl_sql_assert(count($queuedSizes) === $total && min($queuedSizes) > 0, 'Remote object sizes were not persisted by the real SQL queue.');
        smarl_sql_drain();
        $progress = smarl_sql_progress();
        smarl_sql_assert($progress->s3SyncStatus === 'completed' && $progress->s3SyncProcessedFiles === $total && count($storage->downloads) === $total, 'Restore skipped or duplicated a SQL queue row.');
        foreach ($storage->objects as $key => $bytes) smarl_sql_assert(file_get_contents(ABSPATH . $key) === $bytes, 'Restored bytes differ for ' . $key);
        foreach ($createdFiles as $path) if (is_file($path)) unlink($path);
        $createdFiles = [];
        WP_CLI::log('PASS actual WordPress AJAX, SQL queue, and Action Scheduler restore ' . $total . ' files exactly'); $passed++;
    }

    foreach ([15, 16] as $total) {
        [$storage] = smarl_sql_handler();
        for ($i = 0; $i < $total; $i++) {
            $path = $directory . '/upload-' . $total . '-' . $i . '.jpg'; file_put_contents($path, 'upload-fixture-' . $i); $createdFiles[] = $path;
        }
        $expected = SMARL_Helper::scanLocalFiles(ABSPATH, SMARL_Helper::getExtension('media'));
        $expectedHashes = [];
        foreach ($expected as $file) $expectedHashes[$file['relative_path']] = hash_file('sha256', $file['path']);
        $response = smarl_sql_ajax('sync_local_to_aws', ['type' => 'media']);
        smarl_sql_assert(($response['json']['success'] ?? false) === true && $response['json']['data']['total_files'] === count($expected), 'Upload start differs from the actual WordPress-root scan.');
        $firstIds = smarl_sql_pending(); $first = smarl_sql_dispatch((int) $firstIds[0]);
        $processed = smarl_sql_progress()->s3SyncProcessedFiles;
        do_action_ref_array('process_s3_sync_batch', array_values($first));
        smarl_sql_assert(smarl_sql_progress()->s3SyncProcessedFiles === $processed, 'Duplicate Action Scheduler arguments repeated completed work.');
        do_action('process_s3_sync_batch', 1, [], [], 'upload');
        smarl_sql_assert(smarl_sql_progress()->s3SyncProcessedFiles === $processed, 'An old four-argument job changed the active run.');
        smarl_sql_drain();
        ksort($storage->uploads); ksort($expectedHashes);
        smarl_sql_assert($storage->uploads === $expectedHashes, 'Actual uploaded key/hash set does not match the root scan.');
        smarl_sql_assert(smarl_sql_progress()->s3SyncStatus === 'completed' && SMARL_CloudConfig::load()->canEnableMediaCDN, 'Successful SQL upload failed to grant CDN eligibility.');
        foreach ($createdFiles as $path) if (is_file($path)) unlink($path);
        $createdFiles = [];
        WP_CLI::log('PASS real SQL upload with ' . $total . ' new files plus ' . (count($expected) - $total) . ' WordPress media assets; duplicate and legacy jobs harmless'); $passed++;
    }

    [$storage] = smarl_sql_handler();
    $path = $directory . '/stop.jpg'; $key = SMARL_Helper::relativeKeyForPath($path); $storage->objects[$key] = 'stop-fixture'; $createdFiles[] = $path;
    smarl_sql_ajax('sync_aws_to_local', ['type' => 'media']);
    $oldId = smarl_sql_pending()[0]; $oldArgs = ActionScheduler::store()->fetch_action($oldId)->get_args();
    $worker = smarl_sql_progress();
    smarl_sql_assert((smarl_sql_ajax('stop_all_processes')['json']['success'] ?? false) === true, 'Authorized stop AJAX failed.');
    smarl_sql_assert(!$worker->saveForRun($worker->runId) && smarl_sql_progress()->s3SyncStatus === 'idle', 'A stale real-SQL progress write resurrected a stopped run.');
    smarl_sql_ajax('sync_aws_to_local', ['type' => 'media']); $newRun = smarl_sql_progress()->runId;
    do_action_ref_array('process_s3_sync_batch', array_values($oldArgs));
    smarl_sql_assert(smarl_sql_progress()->runId === $newRun && !$storage->downloads, 'An old scheduled job modified its replacement run.');
    smarl_sql_drain();
    smarl_sql_assert($storage->downloads === [$key] && smarl_sql_progress()->s3SyncStatus === 'completed', 'Replacement run did not finish exactly once.');
    WP_CLI::log('PASS actual SQL compare-and-swap rejects stopped and replaced run snapshots'); $passed++;

    [$storage] = smarl_sql_handler();
    wp_set_current_user(0);
    $denied = smarl_sql_ajax('sync_local_to_aws', ['type' => 'media']);
    smarl_sql_assert(($denied['json']['success'] ?? true) === false, 'A user without manage_options started sync.');
    wp_set_current_user(1);
    $invalid = smarl_sql_ajax('sync_local_to_aws', ['type' => 'media'], 'invalid-fixture-nonce');
    smarl_sql_assert(($invalid['die']['message'] ?? null) === -1 || $invalid['body'] === '-1', 'Actual WordPress nonce validation did not reject an invalid nonce.');
    smarl_sql_assert(!smarl_sql_pending() && !$storage->uploads, 'Rejected AJAX requests changed scheduler or storage state.');
    WP_CLI::log('PASS actual WordPress capabilities and nonce validation reject unauthorized sync'); $passed++;
} finally {
    wp_set_current_user(1);
    remove_all_actions('process_s3_sync_batch');
    foreach (as_get_scheduled_actions(['hook' => 'process_s3_sync_batch', 'per_page' => 10000], 'ids') as $id) {
        if (!in_array($id, $originalIds, false)) ActionScheduler::store()->delete_action($id);
    }
    foreach ($pendingSnapshot as $saved) {
        if (ActionScheduler::store()->get_status($saved['id']) !== ActionScheduler_Store::STATUS_PENDING) as_schedule_single_action($saved['timestamp'], 'process_s3_sync_batch', $saved['args'], $saved['group']);
    }
    $wpdb->query("DELETE FROM `{$table}`");
    foreach ($savedQueue as $row) $wpdb->insert($table, $row);
    foreach ($savedOptions as $name => $value) {
        if ($value === null) delete_option($name); else update_option($name, $value, false);
    }
    foreach ($createdFiles as $path) {
        $normalized = str_replace('\\', '/', $path);
        if (str_starts_with($normalized, rtrim(str_replace('\\', '/', $directory), '/') . '/') && is_file($path)) unlink($path);
    }
    if (is_dir($directory)) rmdir($directory);
}
WP_CLI::success($passed . ' WordPress sync/AJAX integration cases passed; all cloud operations were mocked.');
