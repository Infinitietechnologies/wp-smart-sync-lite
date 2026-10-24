<?php
namespace SmartSyncS3\Models;
if (!defined('ABSPATH')) exit;

use Aws\Acm\AcmClient;
use Aws\CloudFront\CloudFrontClient;
use Aws\S3\S3Client;
use Aws\Sts\StsClient;
use InvalidArgumentException;
use RuntimeException;
use Google\Cloud\Storage\StorageClient;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use SmartSyncS3\Services\SMARLI_GoogleService;
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Exceptions\SMARL_ProviderException;

/** Persisted configuration and common storage operations for media and sync jobs. */
class SMARL_CloudConfig
{
    public const PROVIDERS = ['aws', 'digitalocean', 'cloudflare', 'wasabi', 'google', 'bunny'];
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
        // A long-running request can outlive a configuration change in another worker.
        wp_cache_delete('smarl_settings', 'options');
        wp_cache_delete('notoptions', 'options');
        $data = get_option('smarl_settings', []);
        $config = self::fromArray(is_array($data) ? $data : []);
        $config->customUrl = SMARL_CustomUrl::load();
        try { $needsMigration = $config->serviceProvider === 'google' && $config->googleServicePath !== '' && SMARLI_GoogleService::credentialsNeedMigration($config->googleServicePath); }
        catch (\Throwable $error) { $needsMigration = false; }
        if ($needsMigration) {
            $lock = SMARL_Helper::acquireSyncLock();
            if ($lock === null) return $config;
            try {
                global $wpdb;
                $current = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'smarl_settings')));
                $fresh = self::fromArray(is_array($current) ? $current : []);
                $fresh->customUrl = $config->customUrl;
                $config = $fresh;
                if ($config->serviceProvider !== 'google' || !SMARLI_GoogleService::credentialsNeedMigration($config->googleServicePath)) return $config;
                $oldPath = $config->googleServicePath;
                $path = SMARLI_GoogleService::migrateCredentials($oldPath, false);
                if ($path !== $config->googleServicePath) {
                    $config->googleServicePath = $path;
                    try { $config->save(); }
                    catch (\Throwable $error) { /* Verify durable state even when an option hook fails after writing. */ }
                    $saved = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'smarl_settings')));
                    if (is_array($saved) && ($saved['service_provider'] ?? '') === 'google' && ($saved['googleServicePath'] ?? '') === $path) {
                        SMARLI_GoogleService::deleteLegacyCredentialFile($oldPath);
                        if (get_option('service_json_path_temp') === $oldPath) delete_option('service_json_path_temp');
                    } else {
                        $config->googleServicePath = $oldPath;
                        SMARLI_GoogleService::deleteCredentialFile($path);
                    }
                }
            } catch (\Throwable $e) {
                // Configuration remains inspectable so an administrator can replace invalid credentials.
                if (isset($oldPath)) $config->googleServicePath = $oldPath;
            } finally { SMARL_Helper::releaseSyncLock($lock); }
        }
        return $config;
    }
    public static function delete(): void { delete_option('smarl_settings'); }
    public static function init(): void { add_option('smarl_settings', [], '', false); }

    public function isConfigured(): bool
    {
        if ($this->bucket === '' || !in_array($this->serviceProvider, self::PROVIDERS, true)) return false;
        if ($this->serviceProvider === 'google') return $this->googleServicePath !== '' && is_readable($this->googleServicePath);
        if ($this->serviceProvider === 'bunny') return $this->bunnyStoragePassword !== '' && $this->region !== '';
        return $this->iamKey !== '' && $this->iamSecret !== '' && $this->region !== ''
            && ($this->serviceProvider !== 'cloudflare' || $this->cloudflareEndpoint !== '');
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

    public function getPublicBaseUrl(): string { return self::normalizePublicBaseUrl($this->url); }

    public static function validateCloudflareEndpoint(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment']) || !empty(trim($parts['path'] ?? '', '/'))
            || !preg_match('/^[a-f0-9]{32}(?:\.(?:eu|fedramp))?\.r2\.cloudflarestorage\.com$/i', $parts['host'] ?? '')) {
            throw new InvalidArgumentException('Enter the HTTPS Cloudflare R2 account endpoint.');
        }
        return 'https://' . strtolower($parts['host']);
    }

    public function getS3Client(string $region = ''): ?S3Client
    {
        if (in_array($this->serviceProvider, ['google', 'bunny'], true)) return null;
        if (!in_array($this->serviceProvider, self::PROVIDERS, true)) throw new InvalidArgumentException('Unsupported storage provider.');
        $region = $region !== '' ? $region : $this->region;
        if (!preg_match('/^[a-z0-9-]+$/', $region)) throw new InvalidArgumentException('A valid storage region is required.');
        $options = ['region' => $region, 'version' => 'latest', 'credentials' => $this->credentials(), 'http' => ['connect_timeout' => 10, 'timeout' => 120]];
        if ($this->serviceProvider === 'cloudflare') {
            $options['region'] = 'auto';
            $options['endpoint'] = self::validateCloudflareEndpoint($this->cloudflareEndpoint);
            $options['use_path_style_endpoint'] = true;
        } elseif ($this->serviceProvider === 'digitalocean') {
            // The endpoint chooses the Spaces datacenter. AWS regions avoid an invalid CreateBucket payload.
            $options['region'] = 'us-east-1';
            $options['endpoint'] = "https://$region.digitaloceanspaces.com";
        }
        elseif ($this->serviceProvider === 'wasabi') $options['endpoint'] = $region === 'us-east-1' ? 'https://s3.wasabisys.com' : "https://s3.$region.wasabisys.com";
        return new S3Client($options);
    }

    private function credentials(): array { return ['key' => $this->iamKey, 'secret' => $this->iamSecret]; }
    public function getCloudfrontClient(): CloudFrontClient { return new CloudFrontClient(['region' => 'us-east-1', 'version' => 'latest', 'credentials' => $this->credentials(), 'http' => ['connect_timeout' => 10, 'timeout' => 120]]); }
    public function getStsClient(string $region = ''): StsClient { return new StsClient(['region' => $region ?: $this->region, 'version' => 'latest', 'credentials' => $this->credentials(), 'http' => ['connect_timeout' => 10, 'timeout' => 120]]); }
    public function getAcmClient(): AcmClient { return new AcmClient(['region' => 'us-east-1', 'version' => 'latest', 'credentials' => $this->credentials(), 'http' => ['connect_timeout' => 10, 'timeout' => 120]]); }

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
        if ($key === '' || $key[0] === '/' || str_contains($key, '\\') || preg_match('/[\x00-\x1f\x7f]/', $key)
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $key) || preg_match('~^[a-z]:~i', $key)) throw new InvalidArgumentException('Invalid storage object path.');
        return $key;
    }

    public function uploadObject(string $key, string $path): void
    {
        self::validateObjectKey($key);
        if (!$this->isConfigured()) throw new RuntimeException('Storage is not configured.');
        if (!is_file($path) || !is_readable($path)) throw new RuntimeException('The local upload file cannot be read.');
        if ($this->serviceProvider === 'google') {
            $stream = fopen($path, 'rb');
            try { $this->googleBucket()->upload($stream, ['name' => $key]); }
            finally { if (is_resource($stream)) fclose($stream); }
        } elseif ($this->serviceProvider === 'bunny') {
            $size = filesize($path);
            if ($size === false) throw new RuntimeException('The local upload file cannot be read.');
            $options = ['headers' => ['Content-Type' => 'application/octet-stream']];
            if ($size > 1048576) {
                $options['smartsync_source_file'] = $path;
            } else {
                $body = file_get_contents($path);
                if ($body === false) throw new RuntimeException('The local upload file cannot be read.');
                $options['body'] = $body;
            }
            $this->bunnyRequest('PUT', $key, $options);
        } else {
            $args = ['Bucket' => $this->bucket, 'Key' => $key, 'SourceFile' => $path];
            // R2 has no object ACLs; AWS uses its bucket policy or OAC.
            if (in_array($this->serviceProvider, ['digitalocean', 'wasabi'], true)) $args['ACL'] = 'public-read';
            $this->getS3Client()->putObject($args);
        }
    }

    public function putS3Object($file_name, $file_path): void { $this->uploadObject($file_name, $file_path); }

    public function downloadObject(string $key, string $destination): void
    {
        self::validateObjectKey($key);
        if (!$this->isConfigured()) throw new RuntimeException('Storage is not configured.');
        if ($this->serviceProvider === 'google') $this->googleBucket()->object($key)->downloadToFile($destination);
        elseif ($this->serviceProvider === 'bunny') $this->bunnyRequest('GET', $key, ['stream' => true, 'filename' => $destination]);
        else $this->getS3Client()->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'SaveAs' => $destination]);
    }

    public function deleteObject(string $key): void
    {
        self::validateObjectKey($key);
        if (!$this->isConfigured()) throw new RuntimeException('Storage is not configured.');
        if ($this->serviceProvider === 'google') {
            $object = $this->googleBucket()->object($key);
            if ($object->exists()) $object->delete();
        } elseif ($this->serviceProvider === 'bunny') $this->bunnyRequest('DELETE', $key, [], true);
        else $this->getS3Client()->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
    }

    public function listObjects(): array
    {
        if (!$this->isConfigured()) throw new RuntimeException('Storage is not configured.');
        $files = [];
        if ($this->serviceProvider === 'google') {
            foreach ($this->googleBucket()->objects() as $object) {
                $info = $object->info();
                $files[] = ['key' => $object->name(), 'size' => (int) ($info['size'] ?? 0)];
            }
        } elseif ($this->serviceProvider === 'bunny') {
            $directories = [''];
            $seen = [];
            while ($directories) {
                $directory = array_pop($directories);
                if (isset($seen[$directory])) continue;
                $seen[$directory] = true;
                $response = $this->bunnyRequest('GET', $directory);
                $items = json_decode(wp_remote_retrieve_body($response), true);
                if (!is_array($items) || !array_is_list($items)) throw new RuntimeException('Invalid storage directory response.');
                foreach ($items as $item) {
                    $name = $item['ObjectName'] ?? '';
                    if (!is_string($name) || $name === '' || str_contains($name, '/') || str_contains($name, '\\') || $name === '.' || $name === '..') throw new RuntimeException('Invalid storage directory entry.');
                    $key = $directory . $name;
                    if (!empty($item['IsDirectory'])) $directories[] = $key . '/';
                    else $files[] = ['key' => $key, 'size' => (int) ($item['Length'] ?? 0)];
                }
            }
        } else {
            $client = $this->getS3Client();
            $args = ['Bucket' => $this->bucket];
            $seenTokens = [];
            do {
                $result = $client->listObjectsV2($args);
                foreach ($result['Contents'] ?? [] as $object) $files[] = ['key' => (string) $object['Key'], 'size' => (int) $object['Size']];
                $token = $result['NextContinuationToken'] ?? '';
                if (!empty($result['IsTruncated']) && (!is_string($token) || $token === '' || isset($seenTokens[$token]))) throw new RuntimeException('Invalid storage pagination response.');
                if (is_string($token)) $seenTokens[$token] = true;
                $args['ContinuationToken'] = $token;
            } while (!empty($result['IsTruncated']));
        }
        return $files;
    }

    protected function getGoogleStorageClient(): StorageClient { return (new SMARLI_GoogleService($this->googleServicePath))->storage; }
    private function googleBucket() { return $this->getGoogleStorageClient()->bucket($this->bucket); }

    public function createGoogleBucket(): void
    {
        $this->getGoogleStorageClient()->createBucket($this->bucket, ['location' => $this->region,
            'iamConfiguration' => ['uniformBucketLevelAccess' => ['enabled' => true]]]);
    }

    public function configureGooglePublicAccess(): void
    {
        $bucket = $this->googleBucket();
        if (!$bucket->exists()) throw new InvalidArgumentException('The selected Google bucket does not exist.');
        $iam = $bucket->iam();
        $policy = $iam->policy(['requestedPolicyVersion' => 3]);
        foreach ($policy['bindings'] ?? [] as $index => $binding) {
            if (($binding['role'] ?? '') !== 'roles/storage.objectViewer' || isset($binding['condition'])) continue;
            if (in_array('allUsers', $binding['members'] ?? [], true)) return;
            $policy['bindings'][$index]['members'][] = 'allUsers';
            $iam->setPolicy($policy);
            return;
        }
        $policy['bindings'][] = ['role' => 'roles/storage.objectViewer', 'members' => ['allUsers']];
        $iam->setPolicy($policy);
    }

    /** A credentials-only repair must prove read access without changing existing IAM policy. */
    public function verifyGoogleConnection(): void
    {
        if ($this->serviceProvider !== 'google' || !$this->isConfigured()) throw new InvalidArgumentException(__('Google storage is not configured.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        if (!$this->googleBucket()->exists()) throw new InvalidArgumentException('The selected Google bucket does not exist.');
    }

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

    private function bunnyRequest(string $method, string $key, array $options = [], bool $allowMissing = false): array
    {
        if (!preg_match('/^[a-zA-Z0-9-]+$/', $this->bucket)) throw new InvalidArgumentException('Invalid Bunny storage zone.');
        if ($key !== '') self::validateObjectKey($key);
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
        $url = $this->getBunnyStorageEndpoint() . '/' . rawurlencode($this->bucket) . '/' . $encodedKey;
        $options['method'] = $method;
        $options['timeout'] = 120;
        $options['redirection'] = 0;
        $options['headers'] = array_merge($options['headers'] ?? [], ['AccessKey' => $this->bunnyStoragePassword]);
        if (isset($options['smartsync_source_file'])) {
            $stream = @fopen($options['smartsync_source_file'], 'rb');
            if ($stream === false) throw new RuntimeException('The local upload file cannot be read.');
            try {
                $http = $this->getBunnyStreamClient()->request($method, $url, ['headers' => $options['headers'], 'body' => $stream,
                    'connect_timeout' => 10, 'timeout' => 120, 'allow_redirects' => false, 'http_errors' => false]);
                $response = ['response' => ['code' => $http->getStatusCode()], 'body' => (string) $http->getBody()];
            } catch (SMARL_ProviderException $error) { throw $error; }
            catch (\Throwable $error) { throw new SMARL_ProviderException(__('Unable to contact Bunny storage. Check outbound HTTPS, DNS, and the selected storage region.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
            finally { if (is_resource($stream)) fclose($stream); }
        } else {
            $response = wp_safe_remote_request($url, $options);
        }
        if (is_wp_error($response)) throw new SMARL_ProviderException(__('Unable to contact Bunny storage. Check outbound HTTPS, DNS, and the selected storage region.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        $status = (int) wp_remote_retrieve_response_code($response);
        if (($status < 200 || $status >= 300) && !($allowMissing && $status === 404)) {
            $message = in_array($status, [401, 403], true)
                ? __('Bunny storage rejected access. Refresh the storage zones and check the zone password, region, and permissions.', 'smartsync-lite-media-offloader-and-assets-cdn')
                /* translators: %d: HTTP response status code. */
                : sprintf(__('Bunny storage request failed (HTTP %d). Try again or check the storage zone status.', 'smartsync-lite-media-offloader-and-assets-cdn'), $status);
            throw new SMARL_ProviderException($message);
        }
        if ($method === 'PUT') {
            $result = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($result) && (($result['Success'] ?? null) === false || ($result['success'] ?? null) === false)) throw new RuntimeException('Bunny storage did not accept the uploaded file.');
        }
        return $response;
    }
}
