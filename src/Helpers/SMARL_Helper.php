<?php

namespace SmartSyncS3\Helpers;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
use Aws\S3\S3Client;
use Exception;
use InvalidArgumentException;

/**
 * Helper class for various utility functions related to S3, file handling, and plugin compatibility.
 *
 * This class contains methods for getting region-specific pricing, checking plugin compatibility,
 * fetching file extensions, retrieving files from Amazon S3, and local directories, and formatting
 * file sizes into readable formats. It is designed to streamline operations in a SmartSyncS3 environment.
 *
 * @package SmartSyncS3
 * @author Infinitie Technologies
 *
 */

class SMARL_Helper
{

    /** Object ownership follows the stable storage address, not rotating credentials or CDN settings. */
    public static function storageTargetFingerprint(\SmartSyncS3\Models\SMARL_CloudConfig $config): string {
        $provider = $config->serviceProvider;
        $region = strtolower(trim($config->region));
        $endpoint = '';
        if ($provider === 'bunny') {
            $endpoint = $config->getBunnyStorageEndpoint();
            $region = ''; // US/NY aliases can describe the same canonical API host.
        } elseif ($provider === 'cloudflare') {
            $endpoint = $config->cloudflareEndpoint === '' ? '' : \SmartSyncS3\Models\SMARL_CloudConfig::validateCloudflareEndpoint($config->cloudflareEndpoint);
            $region = 'auto';
        }
        return hash('sha256', wp_json_encode(['provider' => $provider, 'bucket' => $config->bucket, 'region' => $region, 'endpoint' => $endpoint]));
    }

    /** Bind legacy manifests before switching targets, while the caller owns the configuration lease. */
    public static function bindLegacyOffloadedFiles(\SmartSyncS3\Models\SMARL_CloudConfig $config): void {
        $fingerprint = self::storageTargetFingerprint($config);
        for ($page = 1; ; $page++) {
            $ids = get_posts(['post_type' => 'attachment', 'post_status' => ['inherit', 'private', 'publish', 'trash'], 'fields' => 'ids', 'posts_per_page' => 100,
                'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'meta_key' => '_smarl_offloaded_files', 'no_found_rows' => true]);
            foreach ($ids as $id) {
                $manifest = get_post_meta((int) $id, '_smarl_offloaded_files', true);
                if (!is_array($manifest) || !$manifest || get_post_meta((int) $id, '_smarl_offload_target', true) !== '') continue;
                self::saveOffloadTarget((int) $id, $fingerprint);
            }
            if (count($ids) < 100) return;
        }
    }

    /** A bulk copy may transfer ownership only after every tracked key was copied successfully. */
    public static function bindCompletedOffloadedFiles(\SmartSyncS3\Models\SMARL_CloudConfig $config, string $runId): void {
        global $wpdb;
        $fingerprint = self::storageTargetFingerprint($config);
        $table = $wpdb->prefix . 'smarl_file_sync_queue';
        for ($page = 1; ; $page++) {
            $ids = get_posts(['post_type' => 'attachment', 'post_status' => ['inherit', 'private', 'publish', 'trash'], 'fields' => 'ids', 'posts_per_page' => 100,
                'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'meta_key' => '_smarl_offloaded_files', 'no_found_rows' => true]);
            foreach ($ids as $id) {
                if (get_post_meta((int) $id, '_smarl_offload_target', true) === $fingerprint) continue;
                $manifest = get_post_meta((int) $id, '_smarl_offloaded_files', true);
                if (!is_array($manifest) || !$manifest || count(array_filter($manifest, 'is_string')) !== count($manifest)) continue;
                $keys = array_values(array_unique($manifest));
                $placeholders = implode(',', array_fill(0, count($keys), '%s'));
                $rows = $wpdb->get_results($wpdb->prepare("SELECT relative_path FROM $table WHERE run_id = %s AND status = %s AND relative_path IN ($placeholders)", array_merge([$runId, 'completed'], $keys)), ARRAY_A);
                if ($wpdb->last_error !== '') throw new \RuntimeException(__('Unable to verify copied attachment files.', 'smartsync-lite-media-offloader-and-assets-cdn'));
                $completed = array_fill_keys(array_column($rows ?: [], 'relative_path'), true);
                if (count(array_filter($keys, static fn(string $key): bool => isset($completed[$key]))) === count($keys)) self::saveOffloadTarget((int) $id, $fingerprint);
            }
            if (count($ids) < 100) return;
        }
    }

    private static function saveOffloadTarget(int $id, string $fingerprint): void {
        update_post_meta($id, '_smarl_offload_target', $fingerprint);
        if (get_post_meta($id, '_smarl_offload_target', true) !== $fingerprint) throw new \RuntimeException(__('Unable to record the attachment storage target.', 'smartsync-lite-media-offloader-and-assets-cdn'));
    }

    /**
     * Returns the S3 pricing per region.
     *
     * @return array An associative array of AWS regions and their respective S3 prices.
     * @developer Jay Parmar <infinitie.jay@gmail.com>
     */
    public static function getRegionPrices(): array
    {
        return [
            'us-east-1' => 0.023, // US East (N. Virginia)
            'us-east-2' => 0.023, // US East (Ohio)
            'us-west-1' => 0.026, // US West (N. California)
            'us-west-2' => 0.023, // US West (Oregon)
            'ca-central-1' => 0.025, // Canada (Central)
            'eu-north-1' => 0.024, // EU (Stockholm)
            'eu-west-1' => 0.023, // EU (Ireland)
            'eu-west-2' => 0.024, // EU (London)
            'eu-west-3' => 0.024, // EU (Paris)
            'eu-central-1' => 0.0245, // EU (Frankfurt)
            'eu-south-1' => 0.024, // EU (Milan)
            'eu-south-2' => 0.024, // EU (Spain)
            'eu-central-2' => 0.024, // EU (Zurich)
            'me-south-1' => 0.025, // Middle East (Bahrain)
            'me-central-1' => 0.025, // Middle East (UAE)
            'af-south-1' => 0.025, // Africa (Cape Town)
            'ap-south-1' => 0.025, // Asia Pacific (Mumbai)
            'ap-south-2' => 0.025, // Asia Pacific (Hyderabad)
            'ap-southeast-1' => 0.025, // Asia Pacific (Singapore)
            'ap-southeast-2' => 0.025, // Asia Pacific (Sydney)
            'ap-southeast-3' => 0.025, // Asia Pacific (Jakarta)
            'ap-southeast-4' => 0.025, // Asia Pacific (Melbourne)
            'ap-northeast-1' => 0.025, // Asia Pacific (Tokyo)
            'ap-northeast-2' => 0.025, // Asia Pacific (Seoul)
            'ap-northeast-3' => 0.025, // Asia Pacific (Osaka)
            'sa-east-1' => 0.0405, // South America (São Paulo)
            'nyc1' => 0.10, // New York City 1 (Block Storage)
            'nyc2' => 0.10, // New York City 2 (Block Storage)
            'nyc3' => 0.10, // New York City 3 (Block Storage)
            'ams3' => 0.10, // Amsterdam 3 (Block Storage)
            'sfo2' => 0.10, // San Francisco 2 (Block Storage)
            'sfo3' => 0.10, // San Francisco 3 (Block Storage)
            'sgp1' => 0.10, // Singapore 1 (Block Storage)
            'lon1' => 0.10, // London 1 (Block Storage)
            'fra1' => 0.10, // Frankfurt 1 (Block Storage)
            'tor1' => 0.10 // Toronto 1 (Block Storage)
        ];
    }


    /**
     * Checks the plugin compatibility with server configurations.
     *
     * @return array An associative array containing a boolean 'compatible' flag and an array of errors (if any).
     * @developer Jay Parmar <infinitie.jay@gmail.com>
     *
     */
    public static function checkPluginCompatibility(): array
    {
        $compatible = true;

        $errors = [];

        if((int) ini_get('max_execution_time') > 0 && (int) ini_get('max_execution_time') < 30)
        {

            $compatible = false;
            $errors[] = [
                "error_key" => "max_execution_time",
                "error_message" => "The PHP execution limit is below 30 seconds. Large file transfers may require a higher limit. Current limit: " . (int) ini_get('max_execution_time')
            ];
        }

        return [
            "compatible" => $compatible,
            "error" => $errors
        ];
    }


    /**
     * Returns an array of allowed file extensions based on the provided type.
     *
     * @param string $type The type of files to filter (e.g., "media" or "assets").
     * @return array An array of file extensions allowed for the given type.
     * @developer Jay Parmar <infinitie.jay@gmail.com>
     */
    public static function getExtension(string $type): array
    {
        if ($type == "media") {
            return [
                // Images
                'jpg', 'jpeg', 'jpe', 'png', 'gif', 'ico', 'webp', 'avif', 'bmp', 'heic', 'tif', 'tiff', 'svg',

                // Documents
                'pdf', 'doc', 'docx', 'ppt', 'pptx', 'pps', 'ppsx', 'odt', 'xls', 'xlsx', 'psd',

                // Audio
                'mp3', 'm4a', 'ogg', 'wav', 'aac',

                // Video
                'mp4', 'm4v', 'mov', 'wmv', 'avi', 'mpg', 'ogv', '3gp', '3g2', 'flv', 'mkv', 'webm', 'mpeg'
            ];

        } elseif ($type == "assets") {
            return [
                'css',
                'js',
                "woff",
                "woff2",
                "ttf",
                "otf",
                "eot",
                "map"
            ];
        }
        return [];
    }

    /**
     * Function to check CNAME record for a domain
     *
     * @param string $domain The domain to check
     * @return string|null Returns the CNAME record or null if not found
     */
    public static function checkCNAME($domain)
    {
        // Use dns_get_record to fetch DNS records of type CNAME
        $records = dns_get_record($domain, DNS_CNAME);

        // Check if a CNAME record exists
        if (!empty($records) && isset($records[0]['target'])) {
            return $records[0]['target'];
        }

        return null; // No CNAME record found
    }

    /**
     * Extracts the file extension from a given URL.
     *
     * @param string $url The URL from which to extract the file extension.
     * @return string The file extension, or an empty string if none is found.
     * @developer Jay Parmar <infinitie.jay@gmail.com>
     */
    public static function getFileExtensionFromUrl(string $url): string {
        $path = wp_parse_url($url, PHP_URL_PATH);
        return is_string($path) ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
    }

    public static function getS3Files(\Aws\S3\S3Client $s3Client, string $bucket): array {
        $files = [];
        $arguments = ['Bucket' => $bucket];
        $seen = [];
        do {
            $page = $s3Client->listObjectsV2($arguments);
            foreach ($page['Contents'] ?? [] as $item) {
                if (isset($item['Key'])) { $files[] = ['key' => $item['Key'], 'size' => (int) ($item['Size'] ?? 0)]; }
            }
            if (empty($page['IsTruncated'])) break;
            $token = $page['NextContinuationToken'] ?? null;
            if (!is_string($token) || $token === '' || isset($seen[$token])) throw new \RuntimeException('Invalid storage pagination response.');
            $seen[$token] = true;
            $arguments['ContinuationToken'] = $token;
        } while (true);
        return $files;
    }

    /** Resolve a cloud key without permitting absolute paths, traversal, or escaping symlinks. */
    public static function resolveLocalPath(string $key, string $root = ABSPATH): string {
        $base = realpath($root);
        if ($base === false) { throw new \RuntimeException('The WordPress root directory does not exist.'); }
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $key = str_replace('\\', '/', $key);
        if ($key === '' || preg_match('/[\x00-\x1f\x7f:]/', $key) || str_starts_with($key, '/')) {
            throw new \InvalidArgumentException('Invalid object key.');
        }
        foreach (explode('/', $key) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || preg_match('/[. ]$/', $part)) {
                throw new \InvalidArgumentException('Invalid object key path.');
            }
        }
        $path = $base . '/' . $key;
        $ancestor = $path;
        while (!file_exists($ancestor) && !is_link($ancestor)) {
            $parent = dirname($ancestor);
            if ($parent === $ancestor) { throw new \RuntimeException('Invalid local destination.'); }
            $ancestor = $parent;
        }
        $resolved = realpath($ancestor);
        if ($resolved === false || !self::pathWithinRoot(str_replace('\\', '/', $resolved), $base)) {
            throw new \InvalidArgumentException('Object path escapes the WordPress directory.');
        }
        return $path;
    }

    public static function relativeKeyForPath(string $path, string $root = ABSPATH): string {
        $base = realpath($root);
        if ($base === false) { throw new \RuntimeException('The WordPress root directory does not exist.'); }
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $path = str_replace('\\', '/', $path);
        if (!self::pathWithinRoot($path, $base) || strlen($path) <= strlen($base)) {
            throw new \InvalidArgumentException('File is outside the WordPress directory.');
        }
        $key = substr($path, strlen($base) + 1);
        self::resolveLocalPath($key, $base);
        return $key;
    }

    private static function pathWithinRoot(string $path, string $root): bool {
        if (DIRECTORY_SEPARATOR === '\\') { $path = strtolower($path); $root = strtolower($root); }
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function currentSiteMediaDirectory(): string {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error']) || $uploads['basedir'] === '') {
            throw new \RuntimeException(__('The current site uploads directory is unavailable.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        }
        return rtrim(str_replace('\\', '/', self::resolveLocalPath(self::relativeKeyForPath($uploads['basedir']))), '/');
    }

    private static function mediaPathWithinSite(string $path, string $directory, bool $mainSite): bool {
        $path = str_replace('\\', '/', $path);
        if (!self::pathWithinRoot($path, $directory) || $path === $directory) { return false; }
        $relative = substr($path, strlen($directory) + 1);
        return !$mainSite || !preg_match('~^sites/[0-9]+(?:/|$)~i', $relative);
    }

    /** Object keys stay WordPress-root-relative while each multisite blog owns only its uploads. */
    public static function isCurrentSiteMediaKey(string $key): bool {
        $path = self::resolveLocalPath($key);
        return !is_multisite() || self::mediaPathWithinSite($path, self::currentSiteMediaDirectory(), is_main_site());
    }

    public static function scanSyncFiles(string $type, array $extensions): array {
        if ($type !== 'media' || !is_multisite()) { return self::scanLocalFiles(ABSPATH, $extensions); }
        $directory = self::currentSiteMediaDirectory();
        if (!is_dir($directory)) { return []; }
        $mainSite = is_main_site();
        $files = self::scanLocalFiles($directory, $extensions, static fn(string $path): bool => self::mediaPathWithinSite($path, $directory, $mainSite));
        foreach ($files as &$file) { $file['relative_path'] = self::relativeKeyForPath($file['path']); }
        unset($file);
        return $files;
    }

    /** Scan selected extensions, pruning symlinks, private folders, and disallowed subtrees. */
    public static function scanLocalFiles(string $root, array $extensions, ?callable $pathFilter = null): array {
        if (!$extensions) { throw new \InvalidArgumentException('A supported sync type is required.'); }
        $base = realpath($root);
        if ($base === false || !is_dir($base)) { throw new \RuntimeException('The scan directory does not exist.'); }
        $extensions = array_map('strtolower', $extensions);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            static function ($entry) use ($pathFilter): bool {
                if ($entry->isLink()) { return false; }
                $name = $entry->getFilename();
                return !str_starts_with($name, '.') && !in_array($name, ['node_modules', 'smartsync-private', 'smartsync-credentials'], true)
                    && ($pathFilter === null || $pathFilter($entry->getPathname()));
            }
        ));
        $files = [];
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || !is_readable($entry->getPathname()) || !in_array(strtolower($entry->getExtension()), $extensions, true)) { continue; }
            $path = $entry->getRealPath();
            $files[] = ['path' => $path, 'relative_path' => self::relativeKeyForPath($path, $root), 'status' => 'pending'];
        }
        usort($files, static fn($a, $b) => strcmp($a['relative_path'], $b['relative_path']));
        return $files;
    }

    public static function updateLocalFilesDB(string $uploads_dir, array $extensions = [], string $runId = ''): array {
        $files = self::scanLocalFiles($uploads_dir, $extensions);
        self::replaceQueue($files, $runId);
        return $files;
    }

    public static function replaceQueue(array $files, string $runId): void {
        global $wpdb;
        $table = $wpdb->prefix . 'smarl_file_sync_queue';
        if ($wpdb->query("DELETE FROM $table") === false) { throw new \RuntimeException('Unable to clear the sync queue.'); }
        foreach ($files as $file) {
            $result = $wpdb->insert($table, ['path' => $file['path'], 'relative_path' => $file['relative_path'], 'run_id' => $runId, 'status' => 'pending', 'size' => (int) ($file['size'] ?? -1)], ['%s', '%s', '%s', '%s', '%d']);
            if ($result === false) { throw new \RuntimeException('Unable to create the sync queue.'); }
        }
    }

    /** No cached pages: queue status changes between batches. Offset is retained for compatibility only. */
    public static function getFileSyncQueueData(int $limit = 50, int $offset = 0, string $runId = ''): array {
        global $wpdb;
        $table = $wpdb->prefix . 'smarl_file_sync_queue';
        $result = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE status = %s AND run_id = %s ORDER BY id ASC LIMIT %d OFFSET %d", 'pending', $runId, max(1, $limit), max(0, $offset)), ARRAY_A);
        if ($wpdb->last_error !== '') { throw new \RuntimeException('Unable to read the sync queue.'); }
        return is_array($result) ? $result : [];
    }

    public static function queueStatusCounts(string $runId): array {
        global $wpdb;
        $table = $wpdb->prefix . 'smarl_file_sync_queue';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) AS total FROM $table WHERE run_id = %s GROUP BY status", $runId), ARRAY_A);
        if ($wpdb->last_error !== '') { throw new \RuntimeException('Unable to count the sync queue.'); }
        $counts = ['pending' => 0, 'in_progress' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($rows ?: [] as $row) { if (isset($counts[$row['status']])) { $counts[$row['status']] = (int) $row['total']; } }
        return $counts;
    }

    /** Local cleanup must be reversed before removing the CDN that serves those copies. */
    public static function hasMissingOffloadedFiles(): bool {
        for ($page = 1; ; $page++) {
            $ids = get_posts(['post_type' => 'attachment', 'post_status' => ['inherit', 'private', 'publish', 'trash'], 'fields' => 'ids', 'posts_per_page' => 100, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'meta_key' => '_smarl_offloaded_files', 'no_found_rows' => true]);
            foreach ($ids as $id) {
                $keys = get_post_meta((int) $id, '_smarl_offloaded_files', true);
                foreach (is_array($keys) ? $keys : [] as $key) {
                    if (!is_string($key)) { continue; }
                    try { if (!is_file(self::resolveLocalPath($key))) { return true; } }
                    catch (\InvalidArgumentException $error) { continue; }
                }
            }
            if (count($ids) < 100) { return false; }
        }
    }

    /** The unique options index serializes start/batch work across web and cron requests. */
    public static function acquireSyncLock(int $ttl = 900): ?string {
        global $wpdb;
        $now = time();
        if ($ttl <= 0 || $ttl > PHP_INT_MAX - $now) { throw new \InvalidArgumentException('A positive, non-overflowing lock duration is required.'); }
        $name = 'smarl_sync_lock';
        // Request-local option caches can outlive another request's lease. The database is authoritative.
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        $existing = maybe_unserialize($raw);
        if ($raw !== null && (!is_array($existing) || !is_string($existing['token'] ?? null) || (int) ($existing['expires'] ?? 0) <= $now)) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $raw));
        }
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
        $token = bin2hex(random_bytes(16));
        return add_option($name, ['token' => $token, 'expires' => $now + $ttl], '', false) ? $token : null;
    }

    public static function releaseSyncLock(string $token): void {
        global $wpdb;
        $name = 'smarl_sync_lock';
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        $value = maybe_unserialize($raw);
        if (is_array($value) && is_string($value['token'] ?? null) && hash_equals($value['token'], $token)) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $raw));
        }
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
    }

    public static function formatBytes(int $bytes): string {
        $bytes = max(0, $bytes);
        $power = $bytes > 0 ? min(4, (int) floor(log($bytes, 1024))) : 0;
        return round($bytes / (1024 ** $power), 2) . ' ' . ['B', 'KB', 'MB', 'GB', 'TB'][$power];
    }

    public static function replaceBaseUrl(string $originalUrl, string $newBaseUrl): string {
        $parts = wp_parse_url($originalUrl);
        if (!is_array($parts) || $newBaseUrl === '') { return $originalUrl; }
        $path = $parts['path'] ?? '';
        return rtrim($newBaseUrl, '/') . $path . (isset($parts['query']) ? '?' . $parts['query'] : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    /** Historical estimate, not billing data; analytics deliberately does not use it. */
    public static function calculateStorageDigitalOceanPrice(int $totalBytes): float {
        return round(5 + max(0, $totalBytes / (1024 ** 3) - 250) * 0.02, 4);
    }

    public static function renderSwitch($id = 'toggle', $on = 'on', $off = 'off', $additional_attr = [], $checked = true, $onSvg = '', $offSvg = ''): void {
        echo '<div class="switch"><div class="icon" aria-hidden="true">';
        $paint = ['fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true];
        $svgAllowed = ['svg' => $paint + ['viewbox' => true, 'xmlns' => true, 'width' => true, 'height' => true, 'class' => true], 'path' => $paint + ['d' => true, 'fill-rule' => true, 'clip-rule' => true], 'circle' => $paint + ['cx' => true, 'cy' => true, 'r' => true], 'line' => $paint + ['x1' => true, 'y1' => true, 'x2' => true, 'y2' => true], 'polyline' => $paint + ['points' => true]];
        echo wp_kses($onSvg . $offSvg, $svgAllowed);
        echo '</div><label for="' . esc_attr($id) . '"><input type="checkbox" id="' . esc_attr($id) . '"';
        foreach ($additional_attr as $name => $value) {
            if (preg_match('/^(?:data-[a-z0-9_-]+|aria-[a-z0-9_-]+|class|name|title)$/i', (string) $name)) { echo ' ' . esc_attr($name) . '="' . esc_attr($value) . '"'; }
        }
        if (!isset($additional_attr['aria-labelledby']) && !isset($additional_attr['aria-label'])) { echo ' aria-label="' . esc_attr($id) . '"'; }
        echo ($checked ? ' checked' : '') . ' data-on="' . esc_attr($on) . '" data-off="' . esc_attr($off) . '"></label><span class="led" aria-hidden="true"></span></div>';
    }

    public static function renderProModal(): string { return ''; }
}
