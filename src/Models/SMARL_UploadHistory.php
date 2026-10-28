<?php
namespace SmartSyncS3\Models;
if (!defined('ABSPATH')) { exit; }

use SmartSyncS3\Helpers\SMARL_Helper;

/** Successful byte receipts, scoped to the current blog and stable storage address. */
class SMARL_UploadHistory {
    /** Hash streams from disk; timestamps and file sizes alone cannot prove unchanged contents. */
    public static function signature(string $path): array {
        clearstatcache(true, $path);
        $hash = is_file($path) && is_readable($path) ? hash_file('sha256', $path) : false;
        if ($hash === false) { throw new \RuntimeException('Unable to verify local file contents.'); }
        $size = filesize($path);
        if ($size === false) { throw new \RuntimeException('Unable to verify local file contents.'); }
        return ['content_hash' => $hash, 'size' => $size];
    }

    /** Manual uploads also confirm cloud presence before trusting a local receipt. */
    public static function plan(array $files, SMARL_CloudConfig $config, bool $full, bool $verifyCloud = false): array {
        if ($full) return ['files' => $files, 'skipped' => 0, 'scanned' => count($files)];
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT key_hash, relative_path, content_hash, size FROM {$wpdb->prefix}smarl_upload_history WHERE target_hash = %s", SMARL_Helper::storageTargetFingerprint($config)), ARRAY_A);
        if ($wpdb->last_error !== '') { throw new \RuntimeException('Unable to read upload history.'); }
        $history = [];
        foreach ($rows ?: [] as $row) $history[$row['key_hash']] = $row;
        $unchanged = [];
        foreach ($files as $file) {
            $key = (string) $file['relative_path'];
            $receipt = $history[hash('sha256', $key)] ?? null;
            try {
                if ($receipt && $receipt['relative_path'] === $key) {
                    $signature = self::signature((string) $file['path']);
                    if ($receipt['content_hash'] === $signature['content_hash'] && (int) $receipt['size'] === $signature['size']) {
                        $unchanged[$key] = $signature['size'];
                        continue;
                    }
                }
            } catch (\Throwable $error) { /* Let the worker report an unreadable or unstable source. */ }
        }
        if ($verifyCloud && $unchanged) {
            $remote = $config->verifyUploadCandidates(array_keys($unchanged));
            foreach ($unchanged as $key => $size) {
                if (!isset($remote[$key]) || $remote[$key] !== $size) {
                    self::forget($config, $key);
                    unset($unchanged[$key]);
                }
            }
        }
        $pending = array_values(array_filter($files, static fn(array $file): bool => !isset($unchanged[(string) $file['relative_path']])));
        return ['files' => $pending, 'skipped' => count($unchanged), 'scanned' => count($files)];
    }

    /** Invalidate before a write/delete so failed or interrupted replacements are retried. */
    public static function forget(SMARL_CloudConfig $config, string $key): void {
        global $wpdb;
        if ($wpdb->delete($wpdb->prefix . 'smarl_upload_history', ['target_hash' => SMARL_Helper::storageTargetFingerprint($config), 'key_hash' => hash('sha256', $key)]) === false) {
            throw new \RuntimeException('Unable to invalidate upload history.');
        }
    }

    /** Caller holds the coordination lease and has checked the source again after upload. */
    public static function record(SMARL_CloudConfig $config, string $key, array $signature): void {
        global $wpdb;
        self::forget($config, $key);
        if ($wpdb->insert($wpdb->prefix . 'smarl_upload_history', [
            'target_hash' => SMARL_Helper::storageTargetFingerprint($config), 'key_hash' => hash('sha256', $key),
            'relative_path' => $key, 'content_hash' => $signature['content_hash'], 'size' => $signature['size']
        ]) !== 1) { throw new \RuntimeException('Unable to record successful upload contents.'); }
    }
}
