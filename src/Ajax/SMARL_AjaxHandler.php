<?php
namespace SmartSyncS3\Ajax;
if (!defined('ABSPATH')) { exit; }

use SmartSyncS3\Constants\SMARL_Constant;
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Models\SMARL_CloudConfig;
use SmartSyncS3\Models\SMARL_S3SyncSettings;
use SmartSyncS3\Models\SMARL_UploadHistory;
use SmartSyncS3\Exceptions\SMARL_ProviderException;
use Throwable;

/** Authorized AJAX operations and run-scoped, idempotent background synchronization. */
class SMARL_AjaxHandler {
    private ?SMARL_CloudConfig $appConfig;
    private SMARL_S3SyncSettings $s3SyncSettings;
    public function __construct(?SMARL_CloudConfig $configuration) {
        $this->appConfig = $configuration;
        $this->s3SyncSettings = new SMARL_S3SyncSettings();
    }
    /** Refresh registered callbacks after WordPress changes the current blog. */
    public function setConfiguration(SMARL_CloudConfig $configuration): void {
        $this->appConfig = $configuration;
        $this->s3SyncSettings = new SMARL_S3SyncSettings();
    }
    public function initializeActions(): void {
        foreach (['clear_cloudfront_cache' => 'clearCloudFrontCache', 'toggle_cdn' => 'toggleCdn', 'toggle_media_auto_delete' => 'toggleMediaAutoDelete', 'sync_local_to_aws' => 'syncLocalToAws', 'stop_all_processes' => 'stopAllProcesses', 'sync_aws_to_local' => 'syncAwsToLocal', 'set_sync_state' => 'setSyncState', 'get_sync_state' => 'getSyncState', 'check_sync_status' => 'checkSyncStatus', 'fetch_cloud_analytics' => 'fetchCloudAnalytics', 'fetch_aws_analytics' => 'fetchAwsAnalytics'] as $action => $method) {
            add_action('wp_ajax_' . $action, [$this, $method]);
        }
        add_action('process_s3_sync_batch', [$this, 'processS3Batch'], 10, 5);
    }
    private function authorize(): void {
        if (!current_user_can('manage_options')) { wp_send_json_error(esc_html__('You do not have permission to manage SmartSync.', 'smartsync-lite-media-offloader-and-assets-cdn'), 403); }
        check_ajax_referer('smarl_nonce', 'nonce');
    }
    private function configured(): SMARL_CloudConfig {
        if (!$this->appConfig || !$this->appConfig->isConfigured()) { throw new \RuntimeException(__('Cloud storage is not configured.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
        return $this->appConfig;
    }
    private function requestType(): string {
        $type = isset($_POST['type']) && is_string($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
        if (!in_array($type, ['media', 'assets'], true)) { throw new \InvalidArgumentException(__('A supported sync type is required.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
        return $type;
    }
    private function requestBoolean(string $field): bool {
        if (!isset($_POST[$field]) || !is_scalar($_POST[$field])) { throw new \InvalidArgumentException(__('A valid boolean setting is required.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
        $value = filter_var(wp_unslash((string) $_POST[$field]), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) { throw new \InvalidArgumentException(__('A valid boolean setting is required.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
        return $value;
    }
    private function fingerprint(SMARL_CloudConfig $config): string {
        $data = $config->toArray();
        return hash('sha256', wp_json_encode(array_intersect_key($data, array_flip(['bucket', 'region', 'service_provider', 'iam_key', 'iam_secret', 'cloudflareEndpoint', 'googleServicePath', 'bunnyStoragePassword', 'bunnyStorageEndpoint']))));
    }
    /** @phpstan-impure A different request can stop the run between calls. */
    private function isCurrentRun(string $runId): bool {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'smarl_sync_settings'));
        $data = maybe_unserialize($raw);
        return is_array($data) && ($data['s3SyncStatus'] ?? '') === 'processing' && ($data['runId'] ?? '') === $runId;
    }
    public function fetchCloudAnalytics(): void {
        $this->authorize();
        try {
            $usage = $this->configured()->getStorageUsage();
            $result = ['total_files' => $usage['total_files'], 'total_storage' => SMARL_Helper::formatBytes($usage['total_size']), 'total_size' => $usage['total_size'], 'usage_source' => $usage['usage_source'], 'price_in_dollars' => null, 'estimated_cost_available' => false];
        } catch (SMARL_ProviderException $e) { wp_send_json_error($e->getMessage(), 502); }
        catch (Throwable $e) { wp_send_json_error(esc_html__('Unable to fetch cloud storage analytics. Check the provider configuration and permissions.', 'smartsync-lite-media-offloader-and-assets-cdn'), 400); }
        wp_send_json_success($result);
    }
    /** Compatibility for cached scripts and integrations using the former action name. */
    public function fetchAwsAnalytics(): void {
        $this->fetchCloudAnalytics();
    }
    public function checkSyncStatus(): void {
        $this->authorize();
        $this->s3SyncSettings->load();
        $s = $this->s3SyncSettings;
        if ($s->s3SyncStatus === 'processing' && $s->runId !== '' && function_exists('as_has_scheduled_action') && !as_has_scheduled_action('process_s3_sync_batch', null, 'smartsync')) {
            try { $this->scheduleBatch($s->batchNumber, $s->s3SyncDirection, $s->runId, time()); }
            catch (Throwable $e) { $s->s3SyncErrors[] = __('Unable to resume the background sync.', 'smartsync-lite-media-offloader-and-assets-cdn'); $s->s3SyncStatus = 'failed'; $s->finishedAt = time(); $s->syncState = 'default'; $s->saveForRun($s->runId); }
        }
        $data = $s->statusData($this->appConfig);
        // Polling clients need counters during work, and request diagnostics once terminal.
        if (($_POST['summary'] ?? '') === '1' && $s->s3SyncStatus === 'processing') {
            $data['successes'] = [];
            $data['errors'] = [];
        }
        $lock = SMARL_Helper::syncLockStatus();
        $data['operation_busy'] = $lock['busy'];
        $data['retry_after'] = $lock['retry_after'];
        if ($s->runId !== '') {
            $counts = SMARL_Helper::queueStatusCounts($s->runId);
            $data['failed_files'] = $counts['failed'];
            if ($s->s3SyncStatus === 'processing') {
                global $wpdb;
                $file = $wpdb->get_var($wpdb->prepare("SELECT relative_path FROM {$wpdb->prefix}smarl_file_sync_queue WHERE run_id = %s AND status = %s ORDER BY id ASC LIMIT 1", $s->runId, 'in_progress'));
                $data['current_file'] = is_string($file) ? $file : '';
            }
        }
        wp_send_json_success($data);
    }
    public function getSyncState(): void { $this->authorize(); $this->s3SyncSettings->load(); wp_send_json_success($this->s3SyncSettings->syncState); }
    public function setSyncState(): void {
        $this->authorize();
        $state = isset($_POST['state']) && is_string($_POST['state']) ? sanitize_text_field(wp_unslash($_POST['state'])) : '';
        if (!in_array($state, ['default', 'syncing'], true)) { wp_send_json_error(__('Invalid sync state.', 'smartsync-lite-media-offloader-and-assets-cdn'), 400); }
        $this->s3SyncSettings->load();
        // State is derived from execution; the browser cannot create or hide a running job.
        $actual = $this->s3SyncSettings->s3SyncStatus === 'processing' ? 'syncing' : 'default';
        if ($state !== $actual) { wp_send_json_error(__('Sync state is controlled by the background job.', 'smartsync-lite-media-offloader-and-assets-cdn'), 409); }
        wp_send_json_success();
    }
    public function syncLocalToAws(): void { $this->startSync('upload'); }
    public function syncAwsToLocal(): void { $this->startSync('download'); }
    private function startSync(string $direction): void {
        $this->authorize();
        try {
            $type = $this->requestType();
            $mode = $direction === 'upload' ? ($_POST['mode'] ?? 'incremental') : 'incremental';
            if (!is_string($mode) || !in_array($mode, ['incremental', 'full'], true)) {
                throw new \InvalidArgumentException(__('Choose incremental sync or full resync.', 'smartsync-lite-media-offloader-and-assets-cdn'));
            }
            // TEMP: A per-request allowlist for manually testing media upload formats.
            $selectedExtensions = null;
            if (array_key_exists('media_test_extensions', $_POST)) {
                $requested = wp_unslash($_POST['media_test_extensions']);
                $allowed = SMARL_Helper::getExtension('media');
                if ($direction !== 'upload' || $type !== 'media' || !is_array($requested) || !$requested || count($requested) > count($allowed)) {
                    throw new \InvalidArgumentException(__('Choose one or more supported media file types.', 'smartsync-lite-media-offloader-and-assets-cdn'));
                }
                foreach ($requested as $extension) {
                    if (!is_string($extension) || !in_array($extension, $allowed, true)) {
                        throw new \InvalidArgumentException(__('Choose one or more supported media file types.', 'smartsync-lite-media-offloader-and-assets-cdn'));
                    }
                }
                $selectedExtensions = array_values(array_unique($requested));
            }
            if ($direction === 'download' && $type === 'assets' && is_multisite() && !current_user_can('manage_network_options')) {
                throw new \InvalidArgumentException(__('Network administrator permission is required to restore shared assets.', 'smartsync-lite-media-offloader-and-assets-cdn'), 403);
            }
            $config = $this->configured();
        } catch (Throwable $e) { wp_send_json_error($e->getMessage(), $e->getCode() === 403 ? 403 : 400); }
        $lock = SMARL_Helper::acquireSyncLock();
        if ($lock === null) { wp_send_json_error(__('Another SmartSync operation is still finishing. Please wait and try again.', 'smartsync-lite-media-offloader-and-assets-cdn'), 409); }
        $error = null;
        $publicError = null;
        $errorStatus = 400;
        try {
            global $wpdb;
            $rawConfig = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'smarl_settings')));
            $persisted = SMARL_CloudConfig::fromArray(is_array($rawConfig) ? $rawConfig : []);
            if ($this->fingerprint($persisted) !== $this->fingerprint($config)) { $this->appConfig = $persisted; $config = $this->configured(); }
            $this->s3SyncSettings->load();
            if ($this->s3SyncSettings->s3SyncStatus === 'processing') { $publicError = __('A sync operation is already running.', 'smartsync-lite-media-offloader-and-assets-cdn'); $errorStatus = 409; throw new \RuntimeException($publicError); }
            if ($direction === 'upload' && SMARL_Helper::hasMissingOffloadedFiles()) { $publicError = __('Restore offloaded media locally before starting another upload sync.', 'smartsync-lite-media-offloader-and-assets-cdn'); throw new \RuntimeException($publicError); }
            if (!function_exists('as_schedule_single_action')) { throw new \RuntimeException(__('The background scheduler is unavailable.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
            as_unschedule_all_actions('process_s3_sync_batch');
            $this->s3SyncSettings->reset();
            $s = $this->s3SyncSettings;
            $s->runId = wp_generate_uuid4();
            $s->startedAt = time();
            $s->syncProvider = $config->serviceProvider;
            $s->syncStorage = $config->bucket;
            $s->failedFiles = 0;
            $s->syncType = $type;
            $s->syncMode = $mode;
            $s->s3SyncDirection = $direction;
            $s->syncState = 'syncing';
            $s->s3SyncStatus = 'processing';
            $s->extensions = $selectedExtensions ?? SMARL_Helper::getExtension($type);
            if ($direction === 'download' && is_multisite() && !current_user_can('manage_network_options')) {
                $allowedMimes = get_allowed_mime_types();
                $s->extensions = array_values(array_filter($s->extensions, static fn(string $extension): bool => wp_check_filetype('smartsync.' . $extension, $allowedMimes)['ext'] !== false));
            }
            $s->cloudFingerprint = $this->fingerprint($config);
            $s->save();
            if ($direction === 'upload') {
                $files = SMARL_Helper::scanSyncFiles($type, $s->extensions);
                $plan = SMARL_UploadHistory::plan($files, $config, $mode === 'full', true);
                $files = $plan['files'];
                $s->skippedFiles = $plan['skipped'];
                $s->scannedFiles = $plan['scanned'];
            } else {
                $files = [];
                foreach ($config->listObjects() as $object) {
                    $key = (string) ($object['key'] ?? '');
                    if (!in_array(strtolower(pathinfo($key, PATHINFO_EXTENSION)), $s->extensions, true)) { continue; }
                    if ($type === 'media' && !SMARL_Helper::isCurrentSiteMediaKey($key)) { continue; }
                    $files[] = ['path' => SMARL_Helper::resolveLocalPath($key), 'relative_path' => $key, 'size' => (int) ($object['size'] ?? -1)];
                }
            }
            if (!$this->isCurrentRun($s->runId)) { throw new \RuntimeException(__('The sync operation was stopped.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
            SMARL_Helper::replaceQueue($files, $s->runId);
            $s->s3SyncTotalFiles = count($files);
            if ($direction === 'download') $s->scannedFiles = count($files);
            if (!$s->saveForRun($s->runId)) { throw new \RuntimeException(__('The sync operation was stopped.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
            if (!$files) { $this->finishRun(); }
            else { $this->scheduleBatch(1, $direction, $s->runId, time()); }
        } catch (Throwable $e) {
            $error = $publicError ?? ($e instanceof SMARL_ProviderException ? $e->getMessage() : __('Unable to start sync. Check the cloud configuration, selected files, and database access.', 'smartsync-lite-media-offloader-and-assets-cdn'));
            if (isset($s) && $this->isCurrentRun($s->runId)) {
                $s->s3SyncStatus = 'failed'; $s->finishedAt = time(); $s->syncState = 'default'; $s->s3SyncErrors[] = $e instanceof SMARL_ProviderException ? $error : __('Unable to start sync. Check the cloud configuration and local file permissions.', 'smartsync-lite-media-offloader-and-assets-cdn'); $s->saveForRun($s->runId);
            }
        } finally { SMARL_Helper::releaseSyncLock($lock); }
        if ($error !== null) { wp_send_json_error($error, $errorStatus); }
        wp_send_json_success(['message' => $this->s3SyncSettings->s3SyncTotalFiles === 0 ? __('No files need transferring.', 'smartsync-lite-media-offloader-and-assets-cdn') : __('Sync started', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'total_files' => $this->s3SyncSettings->s3SyncTotalFiles, 'skipped_files' => $this->s3SyncSettings->skippedFiles,
            'scanned_files' => $this->s3SyncSettings->scannedFiles, 'mode' => $mode]);
    }
    private function scheduleBatch(int $number, string $direction, string $runId, int $timestamp): void {
        $id = as_schedule_single_action($timestamp, 'process_s3_sync_batch', ['batch_number' => $number, 'settings' => [], 'files' => [], 'direction' => $direction, 'run_id' => $runId], 'smartsync');
        if (!$id) { throw new \RuntimeException(__('Unable to schedule the background sync.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
    }
    public function processS3Batch(int $batch_number, array $settings, array $batch_files, string $direction, string $run_id = ''): void {
        // Four-argument jobs from older releases cannot act on a new run.
        if ($run_id === '' || !in_array($direction, ['upload', 'download'], true) || !$this->isCurrentRun($run_id)) { return; }
        $lock = SMARL_Helper::acquireSyncLock();
        if ($lock === null) { $this->scheduleBatch($batch_number, $direction, $run_id, time() + 5); return; }
        try {
            $this->s3SyncSettings->load();
            $s = $this->s3SyncSettings;
            if (!$this->isCurrentRun($run_id) || $s->batchNumber !== $batch_number || $s->s3SyncDirection !== $direction) { return; }
            $config = $this->configured();
            if ($s->cloudFingerprint !== $this->fingerprint($config)) { throw new \RuntimeException(__('Cloud configuration changed. Start a new sync.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
            global $wpdb;
            $table = $wpdb->prefix . 'smarl_file_sync_queue';
            // A previous worker can only have left these rows after losing/expiring its lock.
            if ($wpdb->update($table, ['status' => 'pending'], ['run_id' => $run_id, 'status' => 'in_progress']) === false) { throw new \RuntimeException(__('Unable to recover the sync queue.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
            $size = $config->serviceProvider === 'google' ? 2 : max(1, SMARL_Constant::$processBatchSize);
            $files = SMARL_Helper::getFileSyncQueueData($size, 0, $run_id);
            $started = microtime(true);
            foreach ($files as $file) {
                if (!$this->isCurrentRun($run_id)) { return; }
                if (microtime(true) - $started >= 20) { break; }
                if ($wpdb->update($table, ['status' => 'in_progress'], ['id' => $file['id'], 'run_id' => $run_id, 'status' => 'pending']) !== 1) { throw new \RuntimeException(__('Unable to claim a queued file.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
                $stage = 'validation';
                try {
                    $key = (string) $file['relative_path'];
                    $path = SMARL_Helper::resolveLocalPath($key);
                    if ($s->syncType === 'media' && !SMARL_Helper::isCurrentSiteMediaKey($key)) { throw new \RuntimeException(__('The media file belongs outside the current site uploads directory.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
                    if (!in_array(strtolower(pathinfo($key, PATHINFO_EXTENSION)), $s->extensions, true)) { throw new \RuntimeException(__('File extension is not selected for this sync.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
                    if ($direction === 'upload') {
                        $stage = 'history';
                        SMARL_UploadHistory::forget($config, $key);
                        $stage = 'source';
                        if (!is_file($path) || !is_readable($path)) { throw new \RuntimeException(__('Source file is missing or unreadable.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
                        $signature = SMARL_UploadHistory::signature($path);
                        $stage = 'provider';
                        $config->uploadObject($key, $path);
                        $stage = 'source_changed';
                        if ($signature !== SMARL_UploadHistory::signature($path)) { throw new \RuntimeException('Source changed during upload.'); }
                    } else { $stage = 'download'; $this->downloadFile($file, $path, $config); }
                    if (!$this->isCurrentRun($run_id)) { return; }
                    $stage = 'history';
                    if ($direction === 'upload') SMARL_UploadHistory::record($config, $key, $signature);
                    $stage = 'queue';
                    if ($wpdb->update($table, ['status' => 'completed'], ['id' => $file['id'], 'run_id' => $run_id]) === false) { throw new \RuntimeException(__('Unable to record file completion.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
                    $s->s3SyncSuccesses[] = $key;
                    $s->s3SyncSuccesses = array_slice($s->s3SyncSuccesses, -500);
                } catch (Throwable $e) {
                    if (!$this->isCurrentRun($run_id)) { return; }
                    if ($direction === 'upload' && isset($key)) SMARL_UploadHistory::forget($config, $key);
                    $wpdb->update($table, ['status' => 'failed'], ['id' => $file['id'], 'run_id' => $run_id]);
                    // Only our deliberately public provider messages and allowlisted categories are logged.
                    /* translators: 1: transfer direction, 2: cloud object key, 3: safe failure reason. */
                    $s->s3SyncErrors[] = sprintf(__('Failed to %1$s: %2$s. %3$s', 'smartsync-lite-media-offloader-and-assets-cdn'), $direction === 'upload' ? __('upload', 'smartsync-lite-media-offloader-and-assets-cdn') : __('download', 'smartsync-lite-media-offloader-and-assets-cdn'), (string) $file['relative_path'], $this->transferFailureReason($e, $stage));
                    $s->s3SyncErrors = array_slice($s->s3SyncErrors, -200);
                }
                if (!$this->isCurrentRun($run_id)) { return; }
                $counts = SMARL_Helper::queueStatusCounts($run_id);
                $s->s3SyncProcessedFiles = $counts['completed'] + $counts['failed'];
                $s->failedFiles = $counts['failed'];
                if (!$s->saveForRun($run_id)) { return; }
            }
            if (!$this->isCurrentRun($run_id)) { return; }
            $counts = SMARL_Helper::queueStatusCounts($run_id);
            if ($counts['pending'] > 0 || $counts['in_progress'] > 0) {
                $s->batchNumber++;
                if (!$s->saveForRun($run_id)) { return; }
                // Keep the continuation due so the current runner can claim it. A future
                // timestamp can strand it until another cron/admin queue wake-up.
                $this->scheduleBatch($s->batchNumber, $direction, $run_id, time());
            } else { $this->finishRun(); }
        } catch (Throwable $e) {
            if ($this->isCurrentRun($run_id)) {
                $this->s3SyncSettings->s3SyncErrors[] = __('The background sync failed. Check scheduler, storage configuration, and database access.', 'smartsync-lite-media-offloader-and-assets-cdn');
                $this->s3SyncSettings->s3SyncStatus = 'failed';
                $this->s3SyncSettings->finishedAt = time();
                $this->s3SyncSettings->syncState = 'default';
                if ($this->s3SyncSettings->saveForRun($run_id)) { $this->setEligibility(false); }
            }
        } finally { SMARL_Helper::releaseSyncLock($lock); }
    }
    private function transferFailureReason(Throwable $error, string $stage): string {
        if ($error instanceof SMARL_ProviderException) return $error->getMessage();
        $status = $error instanceof \Aws\Exception\AwsException ? (int) $error->getStatusCode() : (int) $error->getCode();
        if ($error instanceof \GuzzleHttp\Exception\ResponseException) $status = $error->getResponse()->getStatusCode();
        if (in_array($status, [401, 403], true)) return __('The provider denied access. Check the storage credentials and permissions.', 'smartsync-lite-media-offloader-and-assets-cdn');
        if ($status === 429) return __('The provider rate limit was reached. Wait briefly and retry the failed files.', 'smartsync-lite-media-offloader-and-assets-cdn');
        if ($status >= 500 && $status <= 599) return __('The provider reported a server error. Retry the failed files shortly.', 'smartsync-lite-media-offloader-and-assets-cdn');
        if ($status === 404) return __('The storage destination was not found. Check the bucket or zone and region.', 'smartsync-lite-media-offloader-and-assets-cdn');
        return match ($stage) {
            'source' => __('The local file could not be read. Check that it still exists and is readable by PHP.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'source_changed' => __('The local file changed or became unreadable during upload. Retry after it stops changing.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'history' => __('The upload history could not be updated. Check database access before retrying.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'queue' => __('Transfer completion could not be saved. Check database access before retrying.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'validation' => __('The file path or extension is not allowed for this sync.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'download' => __('The downloaded file could not be verified or saved locally. Check the connection and local write access.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            default => __('The provider request failed. Check connectivity and storage access, then retry the failed files.', 'smartsync-lite-media-offloader-and-assets-cdn'),
        };
    }
    private function downloadFile(array $file, string $destination, SMARL_CloudConfig $config): void {
        if (!wp_mkdir_p(dirname($destination))) { throw new \RuntimeException(__('Unable to create the restore directory.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
        SMARL_Helper::resolveLocalPath((string) $file['relative_path']);
        $temporary = tempnam(dirname($destination), '.smartsync-');
        if ($temporary === false) { throw new \RuntimeException(__('Unable to create a temporary restore file.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
        try {
            $config->downloadObject((string) $file['relative_path'], $temporary);
            clearstatcache(true, $temporary);
            $expected = (int) ($file['size'] ?? -1);
            if (!is_file($temporary) || ($expected >= 0 && filesize($temporary) !== $expected)) { throw new \RuntimeException(__('The restored object is incomplete.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
            if (!rename($temporary, $destination)) { throw new \RuntimeException(__('Unable to replace the local file.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }
    private function finishRun(): void {
        $s = $this->s3SyncSettings;
        $counts = SMARL_Helper::queueStatusCounts($s->runId);
        $s->s3SyncProcessedFiles = $counts['completed'] + $counts['failed'];
        $ok = $counts['pending'] === 0 && $counts['in_progress'] === 0 && $counts['failed'] === 0 && $counts['completed'] === $s->s3SyncTotalFiles && !$s->s3SyncErrors;
        if ($ok && $s->s3SyncDirection === 'upload' && $s->syncType === 'media' && !$this->isFilteredMediaUpload()) SMARL_Helper::bindCompletedOffloadedFiles($this->configured(), $s->runId);
        $s->syncState = 'default';
        $s->s3SyncStatus = $ok ? 'completed' : 'failed';
        $s->failedFiles = $counts['failed'];
        $s->finishedAt = time();
        if ($s->saveForRun($s->runId) && $s->s3SyncDirection === 'upload') { $this->setEligibility($ok); }
    }
    private function setEligibility(bool $eligible): void {
        if (!$this->appConfig || $this->s3SyncSettings->s3SyncDirection !== 'upload') { return; }
        // A successful format test cannot establish that the entire Media library is ready for CDN delivery.
        if ($this->isFilteredMediaUpload()) { return; }
        $config = SMARL_CloudConfig::load();
        if ($this->fingerprint($config) !== $this->s3SyncSettings->cloudFingerprint) { return; }
        if ($this->s3SyncSettings->syncType === 'media') { $config->canEnableMediaCDN = $eligible; $config->mediaCdnTestExtensions = []; $config->mediaCdnTestTarget = ''; if (!$eligible) { $config->useCdnForMedia = false; } }
        else { $config->canEnableAssetCDN = $eligible; if (!$eligible) { $config->useCdnForAssets = false; } }
        $config->save();
    }
    private function isFilteredMediaUpload(): bool {
        $s = $this->s3SyncSettings;
        return $s->s3SyncDirection === 'upload' && $s->syncType === 'media'
            && (bool) array_diff(SMARL_Helper::getExtension('media'), $s->extensions);
    }
    public function stopAllProcesses(): void {
        $this->authorize();
        try { self::resetSyncState($this->s3SyncSettings); }
        catch (Throwable $e) { wp_send_json_error(esc_html__('Unable to confirm the sync has stopped. Check the WordPress database and try again.', 'smartsync-lite-media-offloader-and-assets-cdn'), 400); }
        wp_send_json_success(['message' => esc_html__('Stopped all queued sync processes. An active file transfer may finish before stopping.', 'smartsync-lite-media-offloader-and-assets-cdn')]);
    }
    public static function resetSyncState(SMARL_S3SyncSettings $s3SyncSettings): void {
        try { $s3SyncSettings->reset(); }
        finally { if (function_exists('as_unschedule_all_actions')) { as_unschedule_all_actions('process_s3_sync_batch'); } }
    }
    public function toggleCdn(): void {
        $this->authorize();
        try {
            $type = $this->requestType();
            $enabled = $this->requestBoolean('useCdn');
            $this->changeConfiguration(function (SMARL_CloudConfig $config) use ($type, $enabled): void {
                if (!$enabled && $type === 'media' && SMARL_Helper::hasMissingOffloadedFiles()) { throw new \RuntimeException(__('Restore offloaded media locally before disabling its CDN.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
                $eligible = $type === 'media' ? $config->canEnableMediaCDN : $config->canEnableAssetCDN;
                if ($enabled && !$eligible && $type === 'media' && $config->getPublicBaseUrl() !== '') {
                    $this->s3SyncSettings->load();
                    if ($this->s3SyncSettings->s3SyncStatus === 'completed' && $this->isFilteredMediaUpload()
                        && $this->s3SyncSettings->cloudFingerprint === $this->fingerprint($config) && !$this->s3SyncSettings->s3SyncErrors) {
                        if (SMARL_Helper::hasMissingOffloadedFiles()) throw new \RuntimeException(__('Restore offloaded media locally before enabling temporary CDN testing.', 'smartsync-lite-media-offloader-and-assets-cdn'));
                        $config->mediaCdnTestExtensions = array_values(array_intersect(SMARL_Helper::getExtension('media'), $this->s3SyncSettings->extensions));
                        if (!$config->mediaCdnTestExtensions) throw new \RuntimeException(__('Complete a selected-format Media upload before enabling temporary CDN testing.', 'smartsync-lite-media-offloader-and-assets-cdn'));
                        $config->mediaCdnTestTarget = SMARL_Helper::storageTargetFingerprint($config);
                        $config->mediaAutoDelete = false;
                        $eligible = true;
                    }
                }
                if ($enabled && (!$eligible || $config->getPublicBaseUrl() === '')) { throw new \RuntimeException(__('Complete a successful sync and configure a public URL before enabling CDN.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
                if ($type === 'media') {
                    $config->useCdnForMedia = $enabled;
                    if (!$enabled || $config->canEnableMediaCDN) { $config->mediaCdnTestExtensions = []; $config->mediaCdnTestTarget = ''; }
                }
                else { $config->useCdnForAssets = $enabled; }
            });
        } catch (Throwable $e) { wp_send_json_error($e->getMessage(), $e->getCode() === 409 ? 409 : 400); }
        wp_send_json_success(['message' => esc_html__('CDN setting updated successfully.', 'smartsync-lite-media-offloader-and-assets-cdn'), 'cdn_test_extensions' => $type === 'media' && $enabled ? $this->appConfig->mediaCdnTestExtensions : []]);
    }
    public function toggleMediaAutoDelete(): void {
        $this->authorize();
        try {
            $enabled = $this->requestBoolean('state');
            $this->changeConfiguration(static function (SMARL_CloudConfig $config) use ($enabled): void {
                if ($enabled && ($config->mediaCdnTestExtensions !== [] || $config->mediaCdnTestTarget !== '')) throw new \RuntimeException(__('Local copies are kept during temporary CDN testing.', 'smartsync-lite-media-offloader-and-assets-cdn'));
                $config->mediaAutoDelete = $enabled;
            });
        } catch (Throwable $e) { wp_send_json_error($e->getMessage(), $e->getCode() === 409 ? 409 : 400); }
        wp_send_json_success(['message' => esc_html__('Automatic local cleanup setting updated.', 'smartsync-lite-media-offloader-and-assets-cdn')]);
    }
    private function changeConfiguration(callable $change): void {
        $lock = SMARL_Helper::acquireSyncLock();
        if ($lock === null) { throw new \RuntimeException(__('Another sync or configuration request is running. Try again shortly.', 'smartsync-lite-media-offloader-and-assets-cdn'), 409); }
        try {
            $config = SMARL_CloudConfig::load();
            if (!$config->isConfigured()) { throw new \RuntimeException(__('Cloud storage is not configured.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
            $change($config);
            $config->save();
            $this->appConfig = $config;
        } finally { SMARL_Helper::releaseSyncLock($lock); }
    }
    public function clearCloudFrontCache(): void {
        $this->authorize();
        try { $this->purgeCloudFrontCache(['/*']); }
        catch (Throwable $e) { wp_send_json_error(__('Unable to invalidate CloudFront. Verify the AWS configuration and permissions.', 'smartsync-lite-media-offloader-and-assets-cdn'), 400); }
        wp_send_json_success(esc_html__('Cache invalidation requested successfully.', 'smartsync-lite-media-offloader-and-assets-cdn'));
    }
    public function purgeCloudFrontCache(array $paths): void {
        $config = $this->configured();
        if ($config->serviceProvider !== 'aws' || !$config->useCloudFront || $config->distributionId === '') { throw new \RuntimeException(__('CloudFront is not configured.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
        $paths = array_values(array_filter($paths, static fn($path) => is_string($path) && str_starts_with($path, '/') && !preg_match('/[\x00-\x1f]/', $path)));
        if (!$paths) { throw new \InvalidArgumentException(__('At least one invalidation path is required.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
        $config->getCloudfrontClient()->createInvalidation(['DistributionId' => $config->distributionId, 'InvalidationBatch' => ['CallerReference' => wp_generate_uuid4(), 'Paths' => ['Quantity' => count($paths), 'Items' => $paths]]]);
    }
    public function uploadToBunnyCDN($filePath, $storageZone = '', $fileName = null): array {
        $config = $this->configured();
        if ($config->serviceProvider !== 'bunny' || ($storageZone !== '' && $storageZone !== $config->bucket)) { throw new \RuntimeException(__('Bunny storage zone is not configured.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
        $key = $fileName ?? SMARL_Helper::relativeKeyForPath($filePath);
        $config->uploadObject($key, $filePath);
        return ['success' => true, 'message' => 'File uploaded successfully'];
    }
    public function noConfigurationFound(): void { wp_send_json_error(esc_html__('Cloud configuration not found.', 'smartsync-lite-media-offloader-and-assets-cdn'), 400); }
}
