<?php
namespace SmartSyncS3\Models;
if (!defined('ABSPATH')) { exit; }

/** Small, validated progress snapshot; work items live in the durable SQL queue. */
class SMARL_S3SyncSettings {
    public array $s3SyncSuccesses = [];
    public array $s3SyncErrors = [];
    public string $syncState = 'default';
    public string $s3SyncStatus = 'idle';
    public int $s3SyncProcessedFiles = 0;
    public string $s3SyncDirection = '';
    public int $s3SyncTotalFiles = 0;
    public array $s3SyncAllFiles = [];
    public array $extensions = [];
    public string $syncType = 'media';
    public string $runId = '';
    public int $batchNumber = 1;
    public string $cloudFingerprint = '';
    private string $optionName = 'smarl_sync_settings';

    public function load(): void {
        wp_cache_delete($this->optionName, 'options');
        wp_cache_delete('notoptions', 'options');
        $data = get_option($this->optionName, []);
        $fresh = self::fromArray(is_array($data) ? $data : []);
        $legacyRun = $fresh->s3SyncStatus === 'processing' && $fresh->runId === '';
        if ($legacyRun) {
            $fresh->s3SyncStatus = 'failed';
            $fresh->syncState = 'default';
            $fresh->s3SyncErrors[] = __('A sync from an older plugin version was interrupted. Start a new sync.', 'smartsync-lite-media-offloader-and-assets-cdn');
        }
        foreach ($fresh->toArray() as $key => $value) { $this->$key = $value; }
        if ($legacyRun) { $this->save(); }
    }
    public function save(): void { update_option($this->optionName, $this->toArray(), false); }
    /** Compare-and-swap prevents a stopped run from being resurrected by an in-flight worker. */
    public function saveForRun(string $runId): bool {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->optionName));
        $current = maybe_unserialize($raw);
        if (!is_array($current) || ($current['runId'] ?? '') !== $runId || ($current['s3SyncStatus'] ?? '') !== 'processing') { return false; }
        $updated = maybe_serialize($this->toArray());
        $changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $updated, $this->optionName, $raw));
        wp_cache_delete($this->optionName, 'options');
        wp_cache_delete('notoptions', 'options');
        return $changed !== false && ($changed > 0 || $updated === $raw);
    }
    public function reset(): void {
        foreach ((new self())->toArray() as $key => $value) { $this->$key = $value; }
        $this->save();
    }
    public static function fromArray(array $data): self {
        $instance = new self();
        foreach (['s3SyncSuccesses', 's3SyncErrors', 'extensions'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) { $instance->$key = array_values(array_filter($data[$key], 'is_string')); }
        }
        foreach (['s3SyncProcessedFiles', 's3SyncTotalFiles', 'batchNumber'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) { $instance->$key = max($key === 'batchNumber' ? 1 : 0, (int) $data[$key]); }
        }
        foreach (['runId', 'cloudFingerprint'] as $key) { if (isset($data[$key]) && is_string($data[$key])) { $instance->$key = $data[$key]; } }
        foreach (['syncState' => ['default', 'syncing'], 's3SyncStatus' => ['idle', 'processing', 'completed', 'failed'], 's3SyncDirection' => ['', 'upload', 'download'], 'syncType' => ['media', 'assets']] as $key => $allowed) {
            if (isset($data[$key]) && in_array($data[$key], $allowed, true)) { $instance->$key = $data[$key]; }
        }
        // Old full manifests are deliberately not reloaded into progress polling responses.
        return $instance;
    }
    public function toArray(): array { $data = get_object_vars($this); unset($data['optionName']); return $data; }
}
