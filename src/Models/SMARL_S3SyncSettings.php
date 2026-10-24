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
        global $wpdb;
        wp_cache_delete($this->optionName, 'options');
        wp_cache_delete('notoptions', 'options');
        // Legacy versions autoloaded progress; alloptions can outlive another request's stop.
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->optionName));
        $data = maybe_unserialize($raw);
        $fresh = self::fromArray(is_array($data) ? $data : []);
        $invalidRun = $fresh->s3SyncStatus === 'processing' && ($fresh->runId === '' || !in_array($fresh->s3SyncDirection, ['upload', 'download'], true));
        if ($invalidRun) {
            $fresh->s3SyncStatus = 'failed';
            $fresh->syncState = 'default';
            $fresh->s3SyncErrors[] = __('An incomplete or older sync was interrupted. Start a new sync.', 'smartsync-lite-media-offloader-and-assets-cdn');
            $fresh->s3SyncErrors = array_slice($fresh->s3SyncErrors, -200);
            // A new run may have started since the original SQL snapshot was read.
            $changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize($fresh->toArray()), $this->optionName, $raw));
            wp_cache_delete($this->optionName, 'options');
            wp_cache_delete('notoptions', 'options');
            if ($changed === 0) {
                $latest = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->optionName)));
                $fresh = self::fromArray(is_array($latest) ? $latest : []);
            }
        }
        foreach ($fresh->toArray() as $key => $value) { $this->$key = $value; }
    }
    public function save(): void {
        global $wpdb;
        $data = $this->toArray();
        update_option($this->optionName, $data, false);
        $stored = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->optionName));
        if ($stored !== maybe_serialize($data)) {
            throw new \RuntimeException(__('Unable to save sync progress. Check the WordPress database and try again.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        }
    }
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
        if ($changed === false) return false;
        if ($changed > 0) return true;
        // A no-op UPDATE may have lost its predicate to a simultaneous stop/restart.
        return $updated === $raw && $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->optionName)) === $updated;
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
        $instance->s3SyncErrors = array_slice($instance->s3SyncErrors, -200);
        $instance->s3SyncSuccesses = array_slice($instance->s3SyncSuccesses, -500);
        foreach (['s3SyncProcessedFiles', 's3SyncTotalFiles', 'batchNumber'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) { $instance->$key = max($key === 'batchNumber' ? 1 : 0, (int) $data[$key]); }
        }
        foreach (['runId', 'cloudFingerprint'] as $key) { if (isset($data[$key]) && is_string($data[$key])) { $instance->$key = $data[$key]; } }
        foreach (['syncState' => ['default', 'syncing'], 's3SyncStatus' => ['idle', 'processing', 'completed', 'failed'], 's3SyncDirection' => ['', 'upload', 'download'], 'syncType' => ['media', 'assets']] as $key => $allowed) {
            if (isset($data[$key]) && in_array($data[$key], $allowed, true)) { $instance->$key = $data[$key]; }
        }
        $instance->syncState = $instance->s3SyncStatus === 'processing' ? 'syncing' : 'default';
        // Old full manifests are deliberately not reloaded into progress polling responses.
        return $instance;
    }
    public function toArray(): array { $data = get_object_vars($this); unset($data['optionName']); return $data; }
}
