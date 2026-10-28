<?php
namespace SmartSyncS3\Models;
if (!defined('ABSPATH')) exit;

use Aws\S3\S3Client;
use InvalidArgumentException;
use RuntimeException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Exceptions\SMARL_ProviderException;

/** Persisted configuration and common storage operations for media and sync jobs. */
class SMARL_CloudConfig
{
    public const PROVIDERS = \SmartSyncS3\SMARL_Edition::PROVIDERS;
    public string $bucket = '';
    public string $serviceProvider = 'aws';
    public string $region = 'us-east-1';
    public string $cloudfrontUrl = '';
    public string $url = '';
    public string $iamKey = '';
    public string $iamSecret = '';
    public string $distributionId = '';
    public bool $useCloudFront = false;
    public string $urlType = 'custom';
    public bool $canEnableMediaCDN = false;
    public bool $canEnableAssetCDN = false;
    public bool $mediaAutoDelete = false;
    public bool $useCdnForMedia = false;
    public bool $useCdnForAssets = false;
    public string $cloudflareEndpoint = '';
    public string $googleServicePath = '';
    public string $cloudflareUrl = '';
    public string $bunnyStoragePassword = '';
    public string $bunnyStorageEndpoint = '';
    public ?SMARL_CustomUrl $customUrl = null;
    public function setupReference(): string { return hash_hmac('sha256', (string) wp_json_encode($this->toArray()), wp_salt('auth')); }
    private ?array $verificationKeys = null;
    private array $verificationDirectories = [];
    private string $verificationPrefix = '';
    private float $verificationDeadline = 0;

    private const FIELDS = [
        'bucket' => 'bucket', 'region' => 'region', 'cloudfrontUrl' => 'cloudfront_url',
        'url' => 'url', 'iamKey' => 'iam_key', 'iamSecret' => 'iam_secret',
        'distributionId' => 'distribution_id', 'urlType' => 'url_type',
        'serviceProvider' => 'service_provider', 'googleServicePath' => 'googleServicePath',
        'cloudflareEndpoint' => 'cloudflareEndpoint', 'cloudflareUrl' => 'cloudflareUrl',
        'bunnyStoragePassword' => 'bunnyStoragePassword',
        'bunnyStorageEndpoint' => 'bunnyStorageEndpoint',
    ];
    private const BOOLEAN_FIELDS = [
        'useCloudFront' => 'use_cloudfront', 'useCdnForAssets' => 'use_cdn_for_assets',
        'useCdnForMedia' => 'use_cdn_for_media', 'canEnableMediaCDN' => 'canEnableMediaCDN',
        'canEnableAssetCDN' => 'canEnableAssetCDN', 'mediaAutoDelete' => 'mediaAutoDelete',
    ];

    public static function fromArray(array $data): SMARL_CloudConfig
    {
        $config = new self();
        foreach (self::FIELDS as $property => $key) {
            if (!array_key_exists($key, $data)) continue;
            if (is_scalar($data[$key])) $config->$property = (string) $data[$key];
        }
        foreach (self::BOOLEAN_FIELDS as $property => $key) {
            if (array_key_exists($key, $data)) $config->$property = filter_var($data[$key], FILTER_VALIDATE_BOOLEAN);
        }
        // Retire old restricted-delivery settings without promoting partial uploads to full-library delivery.
        if (!empty($data['mediaCdnTestExtensions']) || !empty($data['mediaCdnTestTarget'])) {
            $config->useCdnForMedia = $config->canEnableMediaCDN = $config->mediaAutoDelete = false;
        }
        if ($config->serviceProvider === 'bunny' && str_contains($config->iamSecret, '|')) {
            [$config->iamSecret, $legacyPassword] = explode('|', $config->iamSecret, 2);
            if ($config->bunnyStoragePassword === '') $config->bunnyStoragePassword = $legacyPassword;
        }
        return $config;
    }

    public function toArray(): array
    {
        $data = [];
        foreach (self::FIELDS + self::BOOLEAN_FIELDS as $property => $key) $data[$key] = $this->$property;
        return $data;
    }

    public function save(): void
    {
        global $wpdb;
        $data = $this->toArray();
        update_option('smarl_settings', $data, false);
        $saved = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'smarl_settings')));
        if ($saved !== $data) throw new SMARL_ProviderException(__('Cloud settings could not be saved. Check the WordPress database and try again.', 'smartsync-lite-media-offloader-and-assets-cdn'));
    }
    public static function load(): ?SMARL_CloudConfig
    {
        wp_cache_delete('smarl_settings', 'options');
        wp_cache_delete('notoptions', 'options');
        $data = get_option('smarl_settings', []);
        $config = self::fromArray(is_array($data) ? $data : []);
        $config->customUrl = SMARL_CustomUrl::load();
        if (is_array($data) && (array_key_exists('mediaCdnTestExtensions', $data) || array_key_exists('mediaCdnTestTarget', $data))) {
            $lock = SMARL_Helper::acquireSyncLock();
            if ($lock !== null) {
                try {
                    global $wpdb;
                    $fresh = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'smarl_settings')));
                    $config = self::fromArray(is_array($fresh) ? $fresh : []);
                    $config->save();
                } catch (\Throwable $error) { /* Keep the safe in-memory configuration; retry persistence later. */ }
                finally { SMARL_Helper::releaseSyncLock($lock); }
            }
        }
        // Pro settings remain inert and intact so switching editions cannot change storage ownership.
        return $config;
    }
    public static function delete(): void { delete_option('smarl_settings'); }
    public static function init(): void { add_option('smarl_settings', [], '', false); }

    public function supportsLiteFeatures(): bool
    {
        return in_array($this->serviceProvider, self::PROVIDERS, true) && !$this->useCloudFront
            && $this->urlType !== 'cloudfront' && !preg_match('~(?:^|[./])cloudfront\.net(?:/|$)~i', $this->url);
    }

    public function isConfigured(): bool
    {
        if (!$this->supportsLiteFeatures() || $this->bucket === '') return false;
        if ($this->serviceProvider === 'bunny') return $this->bunnyStoragePassword !== '' && $this->region !== '';
        return $this->iamKey !== '' && $this->iamSecret !== '' && $this->region !== '';
    }

    public static function normalizePublicBaseUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') throw new InvalidArgumentException('A public CDN URL is required.');
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $value)) $value = 'https://' . $value;
        $parts = parse_url($value);
        if ($parts === false || !isset($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || !filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || !str_contains($parts['host'], '.') || filter_var($parts['host'], FILTER_VALIDATE_IP)
            || preg_match('/[\x00-\x20\\\\]/', $value)) {
            throw new InvalidArgumentException('Enter a public hostname or HTTPS URL without credentials, query parameters, or a fragment.');
        }
        return 'https://' . strtolower($parts['host']) . rtrim($parts['path'] ?? '', '/');
    }

    public function getPublicBaseUrl(): string { return $this->supportsLiteFeatures() ? self::normalizePublicBaseUrl($this->url) : ''; }


    public function getS3Client(string $region = ''): ?S3Client
    {
        if (!$this->supportsLiteFeatures()) throw new InvalidArgumentException(\SmartSyncS3\SMARL_Edition::proMessage());
        if ($this->serviceProvider === 'bunny') return null;
        if (!in_array($this->serviceProvider, self::PROVIDERS, true)) throw new InvalidArgumentException('Unsupported storage provider.');
        $region = $region !== '' ? $region : $this->region;
        if (!preg_match('/^[a-z0-9-]+$/', $region)) throw new InvalidArgumentException('A valid storage region is required.');
        $options = ['region' => $region, 'version' => 'latest', 'credentials' => $this->credentials(), 'http' => $this->sdkHttpOptions()];
        if ($this->serviceProvider === 'digitalocean') {
            // The endpoint chooses the Spaces datacenter. AWS regions avoid an invalid CreateBucket payload.
            $options['region'] = 'us-east-1';
            $options['endpoint'] = "https://$region.digitaloceanspaces.com";
        }
        elseif ($this->serviceProvider === 'wasabi') $options['endpoint'] = $region === 'us-east-1' ? 'https://s3.wasabisys.com' : "https://s3.$region.wasabisys.com";
        return new S3Client($options);
    }

    private function credentials(): array { return ['key' => $this->iamKey, 'secret' => $this->iamSecret]; }
    private function sdkHttpOptions(): array {
        return SMARL_Helper::cloudHttpOptions();
    }

    public function createDigitalOceanSpace($spaceName): array
    {
        try {
            $this->getS3Client()->createBucket(['Bucket' => $spaceName, 'ACL' => 'public-read']);
            return ['success' => true, 'code' => 200];
        } catch (\Throwable $e) {
            return ['success' => false, 'code' => (int) $e->getCode(), 'error' => 'The storage provider could not create the bucket.'];
        }
    }

    public static function validateObjectKey(string $key): string
    {
        if (in_array(strtolower(pathinfo($key, PATHINFO_EXTENSION)), ['js', 'css', 'mjs', 'cjs'], true)) throw new InvalidArgumentException(\SmartSyncS3\SMARL_Edition::proMessage());
        if ($key === '' || $key[0] === '/' || str_contains($key, '\\') || preg_match('/[\x00-\x1f\x7f]/', $key)
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $key) || preg_match('~^[a-z]:~i', $key)) throw new InvalidArgumentException('Invalid storage object path.');
        return $key;
    }

    public function uploadObject(string $key, string $path): void
    {
        self::validateObjectKey($key);
        if (!$this->isConfigured()) throw new SMARL_ProviderException(__('Cloud storage is not configured.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        if (!is_file($path) || !is_readable($path)) throw new SMARL_ProviderException(__('The local upload file cannot be read.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        if ($this->serviceProvider === 'bunny') {
            $size = filesize($path);
            if ($size === false) throw new SMARL_ProviderException(__('The local upload file cannot be read.', 'smartsync-lite-media-offloader-and-assets-cdn'));
            $options = ['headers' => ['Content-Type' => 'application/octet-stream']];
            if ($size > 1048576) {
                $options['smartsync_source_file'] = $path;
            } else {
                $body = file_get_contents($path);
                if ($body === false) throw new SMARL_ProviderException(__('The local upload file cannot be read.', 'smartsync-lite-media-offloader-and-assets-cdn'));
                $options['body'] = $body;
            }
            $this->bunnyRequest('PUT', $key, $options);
        } else {
            $args = ['Bucket' => $this->bucket, 'Key' => $key, 'SourceFile' => $path];
            // AWS uses bucket policies; Spaces and Wasabi use public object ACLs.
            if (in_array($this->serviceProvider, ['digitalocean', 'wasabi'], true)) $args['ACL'] = 'public-read';
            $this->getS3Client()->putObject($args);
        }
    }

    public function putS3Object($file_name, $file_path): void { $this->uploadObject($file_name, $file_path); }

    public function downloadObject(string $key, string $destination): void
    {
        self::validateObjectKey($key);
        if (!$this->isConfigured()) throw new RuntimeException('Storage is not configured.');
        if ($this->serviceProvider === 'bunny') $this->bunnyRequest('GET', $key, ['stream' => true, 'filename' => $destination]);
        else $this->getS3Client()->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'SaveAs' => $destination]);
    }

    public function deleteObject(string $key): void
    {
        self::validateObjectKey($key);
        if (!$this->isConfigured()) throw new RuntimeException('Storage is not configured.');
        if ($this->serviceProvider === 'bunny') $this->bunnyRequest('DELETE', $key, [], true);
        else $this->getS3Client()->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
    }

    /** Analytics need totals, not a full directory traversal when the provider reports them. */
    public function getStorageUsage(): array
    {
        if (!$this->isConfigured()) throw new SMARL_ProviderException(__('Cloud storage is not configured.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        if ($this->serviceProvider === 'bunny') return $this->getBunnyReportedUsage();
        $files = $this->listObjects();
        $bytes = 0;
        foreach ($files as $file) $bytes += max(0, (int) ($file['size'] ?? 0));
        return ['total_files' => count($files), 'total_size' => $bytes, 'usage_source' => 'listing'];
    }

    private function getBunnyReportedUsage(): array
    {
        if ($this->iamSecret === '') throw new SMARL_ProviderException(__('A Bunny account API key is required to read storage usage. Reconnect the account in Cloud Config.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        $now = microtime(true);
        $deadline = $now + 20;
        $executionLimit = (int) ini_get('max_execution_time');
        if ($executionLimit > 0) $deadline = min($deadline, (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? $now) + $executionLimit - 5);
        $seen = [];
        for ($page = 1; $page <= 50; $page++) {
            $data = $this->bunnyUsageGet('/storagezone?page=' . $page . '&perPage=1000&includeDeleted=false', $deadline);
            $legacy = array_is_list($data);
            $items = $legacy ? $data : ($data['Items'] ?? null);
            $hasMore = !$legacy && ($data['HasMoreItems'] ?? false) === true;
            if (!is_array($items) || !array_is_list($items) || ($hasMore && !$items)
                || (!$legacy && isset($data['CurrentPage']) && (int) $data['CurrentPage'] !== $page)) {
                throw new SMARL_ProviderException(__('Bunny returned invalid storage usage pagination. Try again shortly.', 'smartsync-lite-media-offloader-and-assets-cdn'));
            }
            $names = [];
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $names[] = $item['Name'] ?? '';
                if (($item['Name'] ?? '') !== $this->bucket || !empty($item['Deleted'])) continue;
                if (!array_key_exists('FilesStored', $item) || !array_key_exists('StorageUsed', $item)) {
                    $id = filter_var($item['Id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($id !== false) $item = $this->bunnyUsageGet('/storagezone/' . $id, $deadline);
                }
                if (($item['Name'] ?? '') !== $this->bucket || !empty($item['Deleted'])) {
                    throw new SMARL_ProviderException(__('Bunny returned usage for a different storage zone. Refresh your cloud connection.', 'smartsync-lite-media-offloader-and-assets-cdn'));
                }
                $counts = [];
                foreach (['FilesStored', 'StorageUsed'] as $field) {
                    $value = $item[$field] ?? null;
                    $number = (is_int($value) || is_string($value)) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
                    if ($number === false) throw new SMARL_ProviderException(__('Bunny has not returned valid file and storage totals for this zone. Check the Bunny dashboard or try again shortly.', 'smartsync-lite-media-offloader-and-assets-cdn'));
                    $counts[$field] = $number;
                }
                return ['total_files' => $counts['FilesStored'], 'total_size' => $counts['StorageUsed'], 'usage_source' => 'provider'];
            }
            if (!$hasMore) throw new SMARL_ProviderException(__('The connected storage zone was not found in this Bunny account. Check the account API key and cloud configuration.', 'smartsync-lite-media-offloader-and-assets-cdn'));
            $fingerprint = hash('sha256', wp_json_encode($names));
            if (isset($seen[$fingerprint])) throw new SMARL_ProviderException(__('Bunny repeated a storage usage page. Try again shortly.', 'smartsync-lite-media-offloader-and-assets-cdn'));
            $seen[$fingerprint] = true;
        }
        throw new SMARL_ProviderException(__('Too many Bunny storage zones to check in one request. Check usage in the Bunny dashboard.', 'smartsync-lite-media-offloader-and-assets-cdn'));
    }

    private function bunnyUsageGet(string $path, float $deadline): array
    {
        $remaining = $deadline - microtime(true);
        if ($remaining < 1) throw new SMARL_ProviderException(__('The Bunny usage request took too long. Check outbound HTTPS and try again.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        $response = wp_safe_remote_get('https://api.bunny.net' . $path, [
            'timeout' => min(8, $remaining - .5), 'redirection' => 0,
            'headers' => ['AccessKey' => $this->iamSecret, 'Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) throw new SMARL_ProviderException(__('Unable to reach Bunny usage statistics within the time limit. Check outbound HTTPS and DNS, then try again.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        $status = (int) wp_remote_retrieve_response_code($response);
        if (in_array($status, [401, 403], true)) throw new SMARL_ProviderException(__('Bunny rejected the account API key or its storage-usage permissions. Reconnect with the account API key in Cloud Config.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        if ($status === 429) throw new SMARL_ProviderException(__('Bunny API rate limit reached. Wait a moment, then fetch usage again.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        if ($status !== 200) throw new SMARL_ProviderException(__('Bunny usage statistics are temporarily unavailable. Try again shortly.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) throw new SMARL_ProviderException(__('Bunny returned an invalid usage response. Try again shortly.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        return $data;
    }

    /** Bounded listing of potential skips; never infer cloud presence from local history alone. */
    public function verifyUploadCandidates(array $keys): array {
        if (!$keys) return [];
        $message = __('Cloud files could not be verified. Check the storage connection and object-listing permissions, then retry. Full resync can upload the selected local files without this check.', 'smartsync-lite-media-offloader-and-assets-cdn');
        try {
            $budget = 15.0;
            $limit = (int) ini_get('max_execution_time');
            if ($limit > 0 && isset($_SERVER['REQUEST_TIME_FLOAT'])) $budget = min($budget, $limit - (microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) - 5);
            if ($budget < 1) throw new RuntimeException('Insufficient verification time');
            $this->verificationDeadline = microtime(true) + $budget;
            $this->verificationKeys = array_fill_keys($keys, true);
            $prefix = '';
            foreach ($keys as $index => $key) {
                self::validateObjectKey($key);
                $directory = str_contains($key, '/') ? substr($key, 0, strrpos($key, '/') + 1) : '';
                if ($index === 0) $prefix = $directory;
                while ($prefix !== '' && !str_starts_with($key, $prefix)) {
                    $parent = rtrim($prefix, '/');
                    $prefix = str_contains($parent, '/') ? substr($parent, 0, strrpos($parent, '/') + 1) : '';
                }
                while ($directory !== '') {
                    $this->verificationDirectories[$directory] = true;
                    $parent = rtrim($directory, '/');
                    $directory = str_contains($parent, '/') ? substr($parent, 0, strrpos($parent, '/') + 1) : '';
                }
            }
            $this->verificationPrefix = $prefix;
            $objects = $this->listObjects();
            $this->verificationTimeout();
            $wanted = array_fill_keys($keys, true);
            $found = [];
            foreach ($objects as $object) {
                if (!is_array($object) || !is_string($object['key'] ?? null) || !is_int($object['size'] ?? null) || $object['size'] < 0) throw new RuntimeException('Invalid verification inventory');
                if (isset($wanted[$object['key']])) $found[$object['key']] = $object['size'];
            }
            return $found;
        } catch (\Throwable $error) {
            throw new SMARL_ProviderException($message);
        } finally {
            $this->verificationKeys = null; $this->verificationDirectories = []; $this->verificationPrefix = ''; $this->verificationDeadline = 0;
        }
    }
    private function verificationTimeout(): int {
        $remaining = $this->verificationDeadline - microtime(true);
        if ($remaining <= 0) throw new RuntimeException('Cloud verification time limit reached');
        return max(1, (int) min(10, ceil($remaining)));
    }
    private function collectListedObject(array &$files, string $key, int $size): bool {
        if ($this->verificationKeys === null || isset($this->verificationKeys[$key])) $files[] = ['key' => $key, 'size' => $size];
        if ($this->verificationKeys !== null) {
            unset($this->verificationKeys[$key]);
            return !$this->verificationKeys;
        }
        return false;
    }
    public function listObjects(): array
    {
        if (!$this->isConfigured()) throw new RuntimeException('Storage is not configured.');
        $files = [];
        if ($this->serviceProvider === 'bunny') {
            $directories = [$this->verificationKeys === null ? '' : $this->verificationPrefix];
            $seen = [];
            while ($directories) {
                $directory = array_pop($directories);
                if (isset($seen[$directory])) continue;
                $seen[$directory] = true;
                $response = $this->bunnyRequest('GET', $directory, $this->verificationKeys === null ? [] : ['timeout' => $this->verificationTimeout()], $this->verificationKeys !== null);
                if ($this->verificationKeys !== null && wp_remote_retrieve_response_code($response) === 404) continue;
                $items = json_decode(wp_remote_retrieve_body($response), true);
                if (!is_array($items) || !array_is_list($items)) throw new RuntimeException('Invalid storage directory response.');
                foreach ($items as $item) {
                    $name = $item['ObjectName'] ?? '';
                    if (!is_string($name) || $name === '' || str_contains($name, '/') || str_contains($name, '\\') || $name === '.' || $name === '..') throw new RuntimeException('Invalid storage directory entry.');
                    $key = $directory . $name;
                    if (!empty($item['IsDirectory'])) {
                        if ($this->verificationKeys === null || isset($this->verificationDirectories[$key . '/'])) $directories[] = $key . '/';
                    } elseif ($this->collectListedObject($files, $key, (int) ($item['Length'] ?? 0))) return $files;
                }
            }
        } else {
            $client = $this->getS3Client();
            $args = ['Bucket' => $this->bucket];
            if ($this->verificationKeys !== null) { $args['Prefix'] = $this->verificationPrefix; $args['MaxKeys'] = 1000; $args['@retries'] = 0; }
            $seenTokens = [];
            do {
                if ($this->verificationKeys !== null) $args['@http'] = ['timeout' => $this->verificationTimeout(), 'connect_timeout' => 5];
                $result = $client->listObjectsV2($args);
                foreach ($result['Contents'] ?? [] as $object) {
                    if ($this->collectListedObject($files, (string) $object['Key'], (int) $object['Size'])) return $files;
                }
                $token = $result['NextContinuationToken'] ?? '';
                if (!empty($result['IsTruncated']) && (!is_string($token) || $token === '' || isset($seenTokens[$token]))) throw new RuntimeException('Invalid storage pagination response.');
                if (is_string($token)) $seenTokens[$token] = true;
                $args['ContinuationToken'] = $token;
            } while (!empty($result['IsTruncated']));
        }
        return $files;
    }




    /** A credentials-only repair must prove read access without changing existing IAM policy. */

    public function getBunnyStorageEndpoint(): string
    {
        if ($this->bunnyStorageEndpoint !== '') return self::validateBunnyStorageEndpoint($this->bunnyStorageEndpoint);
        $endpoints = ['DE' => 'storage.bunnycdn.com', 'UK' => 'uk.storage.bunnycdn.com', 'US' => 'ny.storage.bunnycdn.com', 'NY' => 'ny.storage.bunnycdn.com', 'LA' => 'la.storage.bunnycdn.com', 'SG' => 'sg.storage.bunnycdn.com', 'SE' => 'se.storage.bunnycdn.com', 'BR' => 'br.storage.bunnycdn.com', 'SA' => 'jh.storage.bunnycdn.com', 'JH' => 'jh.storage.bunnycdn.com', 'SYD' => 'syd.storage.bunnycdn.com'];
        $region = strtoupper($this->region ?: 'DE');
        if (!isset($endpoints[$region])) throw new InvalidArgumentException('Unsupported Bunny storage region.');
        return 'https://' . $endpoints[$region];
    }

    /** Never send a storage password to a hostname outside Bunny's storage API. */
    public static function validateBunnyStorageEndpoint(string $endpoint): string
    {
        if (!str_contains($endpoint, '://')) $endpoint = 'https://' . $endpoint;
        $parts = parse_url($endpoint);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || !empty(trim($parts['path'] ?? '', '/'))
            || !preg_match('/^(?:[a-z0-9-]+\.)?storage\.bunnycdn\.com$/i', $parts['host'] ?? '')) {
            throw new InvalidArgumentException(__('Bunny returned an invalid storage hostname. Refresh the storage zones and try again.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        }
        return 'https://' . strtolower($parts['host']);
    }

    /** Check the selected storage password/region before claiming that setup succeeded. */
    public function verifyBunnyConnection(): void
    {
        if ($this->serviceProvider !== 'bunny' || !$this->isConfigured()) throw new InvalidArgumentException(__('Bunny storage is not configured.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        $response = $this->bunnyRequest('GET', '');
        $items = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($items) || !array_is_list($items)) throw new SMARL_ProviderException(__('Bunny storage returned an invalid directory response. Check the selected zone and region.', 'smartsync-lite-media-offloader-and-assets-cdn'));
    }

    /** cURL streams the source file instead of duplicating large videos in PHP memory. */
    protected function getBunnyStreamClient(): ClientInterface
    {
        if (!extension_loaded('curl')) throw new SMARL_ProviderException(__('Enable the PHP cURL extension to upload Bunny files larger than 1 MB.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        return new Client(['handler' => HandlerStack::create(new CurlHandler())]);
    }

    /** Extract only a transport category; never expose URLs, headers or provider response bodies. */
    private function bunnyTransportFailure(object $error): SMARL_ProviderException
    {
        $message = $error instanceof \WP_Error ? $error->get_error_message() : ($error instanceof \Throwable ? $error->getMessage() : '');
        $errno = preg_match('/\bcURL error (\d+)\b/i', $message, $match) ? (int) $match[1] : 0;
        $safe = match ($errno) {
            28 => __('The Bunny storage request timed out. Check the connection and retry the failed files.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            6 => __('The Bunny storage hostname could not be resolved. Check DNS and retry the failed files.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            7 => __('A connection to Bunny storage could not be opened. Check outbound HTTPS and retry.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            35, 51, 58, 60, 77 => __('The secure connection to Bunny storage could not be verified. Check the server TLS and CA certificate configuration.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            default => __('Unable to contact Bunny storage. Check outbound HTTPS, DNS, and the selected storage region.', 'smartsync-lite-media-offloader-and-assets-cdn'),
        };
        return new SMARL_ProviderException($safe, -$errno);
    }

    private function bunnyRequest(string $method, string $key, array $options = [], bool $allowMissing = false): array
    {
        if (!preg_match('/^[a-zA-Z0-9-]+$/', $this->bucket)) throw new InvalidArgumentException('Invalid Bunny storage zone.');
        if ($key !== '') self::validateObjectKey($key);
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
        $url = $this->getBunnyStorageEndpoint() . '/' . rawurlencode($this->bucket) . '/' . $encodedKey;
        $options['method'] = $method;
        $options['timeout'] = max(1, min(120, (int) ($options['timeout'] ?? 120)));
        $options['redirection'] = 0;
        $options['headers'] = array_merge($options['headers'] ?? [], ['AccessKey' => $this->bunnyStoragePassword]);
        if (isset($options['smartsync_source_file'])) {
            $stream = @fopen($options['smartsync_source_file'], 'rb');
            if ($stream === false) throw new SMARL_ProviderException(__('The local upload file cannot be read.', 'smartsync-lite-media-offloader-and-assets-cdn'));
            try {
                $http = $this->getBunnyStreamClient()->request($method, $url, ['headers' => $options['headers'], 'body' => $stream,
                    'connect_timeout' => 10, 'timeout' => 120, 'allow_redirects' => false, 'http_errors' => false]);
                $response = ['response' => ['code' => $http->getStatusCode()], 'body' => (string) $http->getBody()];
            } catch (SMARL_ProviderException $error) { throw $error; }
            catch (\Throwable $error) { throw $this->bunnyTransportFailure($error); }
            finally { if (is_resource($stream)) fclose($stream); }
        } else {
            $response = wp_safe_remote_request($url, $options);
        }
        if (is_wp_error($response)) throw $this->bunnyTransportFailure($response);
        $status = (int) wp_remote_retrieve_response_code($response);
        if (($status < 200 || $status >= 300) && !($allowMissing && $status === 404)) {
            $message = in_array($status, [401, 403], true)
                ? __('Bunny storage rejected access. Refresh the storage zones and check the zone password, region, and permissions.', 'smartsync-lite-media-offloader-and-assets-cdn')
                /* translators: %d: HTTP response status code. */
                : sprintf(__('Bunny storage request failed (HTTP %d). Try again or check the storage zone status.', 'smartsync-lite-media-offloader-and-assets-cdn'), $status);
            if ($status === 429) $message = __('Bunny storage rate limit reached. Wait briefly and retry the failed files.', 'smartsync-lite-media-offloader-and-assets-cdn');
            throw new SMARL_ProviderException($message, $status);
        }
        if ($method === 'PUT') {
            $result = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($result) && (($result['Success'] ?? null) === false || ($result['success'] ?? null) === false)) throw new RuntimeException('Bunny storage did not accept the uploaded file.');
        }
        return $response;
    }
}
