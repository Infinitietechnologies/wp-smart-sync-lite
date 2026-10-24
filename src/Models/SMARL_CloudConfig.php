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
use SmartSyncS3\Services\SMARLI_GoogleService;

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
    public ?SMARL_CustomUrl $customUrl = null;

    private const FIELDS = [
        'bucket' => 'bucket', 'region' => 'region', 'cloudfrontUrl' => 'cloudfront_url',
        'url' => 'url', 'iamKey' => 'iam_key', 'iamSecret' => 'iam_secret',
        'distributionId' => 'distribution_id', 'urlType' => 'url_type',
        'serviceProvider' => 'service_provider', 'googleServicePath' => 'googleServicePath',
        'cloudflareEndpoint' => 'cloudflareEndpoint', 'cloudflareUrl' => 'cloudflareUrl',
        'bunnyStoragePassword' => 'bunnyStoragePassword',
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

    public function save(): void { update_option('smarl_settings', $this->toArray(), false); }
    public static function load(): ?SMARL_CloudConfig
    {
        // A long-running request can outlive a configuration change in another worker.
        wp_cache_delete('smarl_settings', 'options');
        wp_cache_delete('notoptions', 'options');
        $data = get_option('smarl_settings', []);
        $config = self::fromArray(is_array($data) ? $data : []);
        $config->customUrl = SMARL_CustomUrl::load();
        if ($config->serviceProvider === 'google' && $config->googleServicePath !== '') {
            try {
                $path = SMARLI_GoogleService::migrateCredentials($config->googleServicePath);
                if ($path !== $config->googleServicePath) {
                    $config->googleServicePath = $path;
                    $config->save();
                    delete_option('service_json_path_temp');
                }
            } catch (\Throwable $e) {
                // Configuration remains inspectable so an administrator can replace invalid credentials.
            }
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
        } elseif ($this->serviceProvider === 'digitalocean') $options['endpoint'] = "https://$region.digitaloceanspaces.com";
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
            $body = file_get_contents($path);
            if ($body === false) throw new RuntimeException('The local upload file cannot be read.');
            $this->bunnyRequest('PUT', $key, ['body' => $body, 'headers' => ['Content-Type' => 'application/octet-stream']]);
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
            do {
                $result = $client->listObjectsV2($args);
                foreach ($result['Contents'] ?? [] as $object) $files[] = ['key' => (string) $object['Key'], 'size' => (int) $object['Size']];
                $token = $result['NextContinuationToken'] ?? '';
                if (!empty($result['IsTruncated']) && ($token === '' || $token === ($args['ContinuationToken'] ?? null))) throw new RuntimeException('Invalid storage pagination response.');
                $args['ContinuationToken'] = $token;
            } while (!empty($result['IsTruncated']));
        }
        return $files;
    }

    protected function getGoogleStorageClient(): StorageClient { return (new SMARLI_GoogleService($this->googleServicePath))->storage; }
    private function googleBucket() { return $this->getGoogleStorageClient()->bucket($this->bucket); }

    public function getBunnyStorageEndpoint(): string
    {
        $endpoints = ['DE' => 'storage.bunnycdn.com', 'UK' => 'uk.storage.bunnycdn.com', 'US' => 'ny.storage.bunnycdn.com', 'NY' => 'ny.storage.bunnycdn.com', 'LA' => 'la.storage.bunnycdn.com', 'SG' => 'sg.storage.bunnycdn.com', 'SE' => 'se.storage.bunnycdn.com', 'BR' => 'br.storage.bunnycdn.com', 'SA' => 'jh.storage.bunnycdn.com', 'JH' => 'jh.storage.bunnycdn.com', 'SYD' => 'syd.storage.bunnycdn.com'];
        $region = strtoupper($this->region ?: 'DE');
        if (!isset($endpoints[$region])) throw new InvalidArgumentException('Unsupported Bunny storage region.');
        return 'https://' . $endpoints[$region];
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
        $response = wp_safe_remote_request($url, $options);
        if (is_wp_error($response)) throw new RuntimeException('Unable to contact Bunny storage.');
        $status = (int) wp_remote_retrieve_response_code($response);
        if (($status < 200 || $status >= 300) && !($allowMissing && $status === 404)) throw new RuntimeException('Bunny storage request failed (HTTP ' . $status . ').');
        if ($method === 'PUT') {
            $result = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($result) && (($result['Success'] ?? null) === false || ($result['success'] ?? null) === false)) throw new RuntimeException('Bunny storage did not accept the uploaded file.');
        }
        return $response;
    }
}
