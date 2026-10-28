<?php
namespace SmartSyncS3\Ajax;
if (!defined('ABSPATH')) exit;

use Aws\Exception\AwsException;
use InvalidArgumentException;
use RuntimeException;
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Models\SMARL_CloudConfig;
use SmartSyncS3\Models\SMARL_CustomUrl;
use SmartSyncS3\Models\SMARL_S3SyncSettings;
use SmartSyncS3\Exceptions\SMARL_ProviderException;

/** Lite provider setup; legacy Pro endpoints return a permission-checked preview response. */
class SMARL_AwsConfigAjax
{
    private SMARL_CloudConfig $appConfig;
    public function __construct(?SMARL_CloudConfig $configuration) { $this->appConfig = $configuration ?? new SMARL_CloudConfig(); }

    /** Refresh registered callbacks after WordPress changes the current blog. */
    public function setConfiguration(SMARL_CloudConfig $configuration): void { $this->appConfig = $configuration; }

    public function initializeActions(): void
    {
        add_action('wp_ajax_check_dns_record', [$this, 'proOnly']);
        add_action('wp_ajax_generate_ssl_certificate', [$this, 'proOnly']);
        add_action('wp_ajax_list_s3_buckets', [$this, 'listS3Buckets']);
        add_action('wp_ajax_configure_aws', [$this, 'configureAws']);
        add_action('wp_ajax_setup_custom_cloudfront', [$this, 'proOnly']);
        add_action('wp_ajax_get_saved_google_credentials', [$this, 'proOnly']);
        add_action('wp_ajax_attach_to_cloudfront', [$this, 'proOnly']);
    }

    public function proOnly(): void {
        $this->authorize();
        wp_send_json_error(\SmartSyncS3\SMARL_Edition::proMessage(), 403);
    }

    private function authorize(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to manage SmartSync settings.', 'smartsync-lite-media-offloader-and-assets-cdn'), 403);
        }
        check_ajax_referer('smarl_nonce', 'nonce');
    }

    private function input(string $key, string $default = ''): string
    {
        if (!isset($_POST[$key])) return $default;
        if (!is_string($_POST[$key])) throw new InvalidArgumentException('Invalid input field: ' . $key . '.');
        return trim(wp_unslash($_POST[$key]));
    }

    protected function newConfiguration(): SMARL_CloudConfig { return new SMARL_CloudConfig(); }


    private function sendError(\Throwable $error): void
    {
        $errno = 0;
        for ($cause = $error, $depth = 0; $cause !== null && $depth < 5; $cause = $cause->getPrevious(), $depth++) {
            if (preg_match('/\bcURL error (\d+)\b/i', $cause->getMessage(), $match)) { $errno = (int) $match[1]; break; }
        }
        $message = $error instanceof InvalidArgumentException || $error instanceof SMARL_ProviderException ? $error->getMessage()
            : __('The cloud provider request failed. Check the credentials, permissions, region, and provider settings, then try again.', 'smartsync-lite-media-offloader-and-assets-cdn');
        if (!($error instanceof InvalidArgumentException) && !($error instanceof SMARL_ProviderException)) {
            $message = match ($errno) {
                35, 51, 58, 60, 77 => __('PHP could not verify the cloud provider TLS certificate. Check the server CA certificate configuration and the WordPress CA bundle. Certificate verification must remain enabled.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                6 => __('The cloud provider hostname could not be resolved. Check the server DNS configuration.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                7 => __('Unable to connect to the cloud provider. Check outbound HTTPS access and the selected region.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                28 => __('The cloud provider request timed out. Check outbound HTTPS access and try again.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                default => $message,
            };
        }
        if ($error instanceof AwsException && in_array($error->getAwsErrorCode(), ['BucketAlreadyExists', 'BucketAlreadyOwnedByYou'], true)) {
            $code = $error->getAwsErrorCode();
            $message = $code === 'BucketAlreadyExists'
                ? __('That bucket name is already taken. Choose another name, such as your site name plus a random suffix, and try again.', 'smartsync-lite-media-offloader-and-assets-cdn')
                : __('You already own a bucket with that name. Choose Use existing storage and select it, or enter a different name for a new bucket.', 'smartsync-lite-media-offloader-and-assets-cdn');
            wp_send_json_error(['message' => $message, 'code' => $code], 409);
        }
        if ($error instanceof AwsException && $error->getAwsErrorCode()) $message .= ' (' . preg_replace('/[^a-zA-Z0-9_-]/', '', $error->getAwsErrorCode()) . ')';
        wp_send_json_error($message, in_array($error->getCode(), [403, 404, 409], true) ? $error->getCode() : 400);
    }

    private function validateAwsConfiguration($bucketName, $provider, $secret, $key, $type, $region): void
    {
        \SmartSyncS3\SMARL_Edition::requireProvider($provider);
        if (!in_array($type, ['new', 'existing'], true)) throw new InvalidArgumentException('Select a new or existing bucket.');
        $pattern = $provider === 'bunny' ? '/^[a-zA-Z0-9-]{2,100}$/' : '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/';
        if (!preg_match($pattern, $bucketName) || str_contains($bucketName, '..') || filter_var($bucketName, FILTER_VALIDATE_IP)
            || (str_contains($bucketName, '.-') || str_contains($bucketName, '-.'))) throw new InvalidArgumentException('Enter a valid bucket or storage zone name.');
        if ($secret === '' || $key === '') throw new InvalidArgumentException('Provider credentials are required.');
        if ($region === '' || !preg_match('/^[a-zA-Z0-9-]+$/', $region)) throw new InvalidArgumentException('Select a valid storage region.');
        if ($type === 'new' && $provider === 'bunny') throw new InvalidArgumentException('Create a Bunny storage zone and connected pull zone in Bunny first, then select it here.');
    }

    private function createBucket(SMARL_CloudConfig $config): void
    {
        $client = $config->getS3Client();
        $args = ['Bucket' => $config->bucket];
        if ($config->serviceProvider === 'aws' && $config->region !== 'us-east-1') $args['CreateBucketConfiguration'] = ['LocationConstraint' => $config->region];
        if (in_array($config->serviceProvider, ['digitalocean', 'wasabi'], true)) $args['ACL'] = 'public-read';
        $client->createBucket($args);
        $client->waitUntil('BucketExists', ['Bucket' => $config->bucket]);
    }

    private function readBucketPolicy($client, string $bucket, array $requestOptions = []): array
    {
        $policy = ['Version' => '2012-10-17', 'Statement' => []];
        try {
            $result = $client->getBucketPolicy(['Bucket' => $bucket] + $requestOptions);
            $existing = json_decode((string) ($result['Policy'] ?? ''), true);
            if (!is_array($existing) || !isset($existing['Statement']) || !is_array($existing['Statement'])) throw new RuntimeException('Invalid existing bucket policy.');
            $policy = $existing;
            if (isset($policy['Statement']['Effect'])) $policy['Statement'] = [$policy['Statement']];
        } catch (AwsException $error) {
            if (!in_array($error->getAwsErrorCode(), ['NoSuchBucketPolicy', 'NoSuchPolicy'], true)) throw $error;
        }
        return $policy;
    }

    /** Preserve unrelated bucket statements rather than replacing an existing policy. */
    private function setBucketPolicy($client, string $bucket, array $statement, bool $preservePublic = false, array $requestOptions = []): void
    {
        $policy = $this->readBucketPolicy($client, $bucket, $requestOptions);
        $replace = ['SmartSyncPublicRead'];
        $policy['Statement'] = array_values(array_filter($policy['Statement'], static fn($item) => !in_array($item['Sid'] ?? '', $replace, true)));
        $policy['Statement'][] = $statement;
        $client->putBucketPolicy(['Bucket' => $bucket, 'Policy' => json_encode($policy, JSON_THROW_ON_ERROR)] + $requestOptions);
    }

    private function configurePublicBucket(SMARL_CloudConfig $config): void
    {
        if ($config->serviceProvider === 'aws') {
            $client = $config->getS3Client();
            $partition = str_starts_with($config->region, 'cn-') ? 'aws-cn' : (str_starts_with($config->region, 'us-gov-') ? 'aws-us-gov' : 'aws');
            $client->putPublicAccessBlock(['Bucket' => $config->bucket, 'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => true, 'IgnorePublicAcls' => true, 'BlockPublicPolicy' => false, 'RestrictPublicBuckets' => false,
            ]]);
            $this->setBucketPolicy($client, $config->bucket, ['Sid' => 'SmartSyncPublicRead', 'Effect' => 'Allow', 'Principal' => '*', 'Action' => 's3:GetObject', 'Resource' => 'arn:' . $partition . ':s3:::' . $config->bucket . '/*']);
        }
    }

    /** No credentials are stored in this receipt; it survives a failed later provisioning step. */

    /** Prepare the current S3 bucket for the custom-domain flow without replacing its working delivery URL. */

    private function directPublicUrl(SMARL_CloudConfig $config): string
    {
        return match ($config->serviceProvider) {
            'aws' => str_contains($config->bucket, '.')
                ? 's3.' . $config->region . (str_starts_with($config->region, 'cn-') ? '.amazonaws.com.cn/' : '.amazonaws.com/') . $config->bucket
                : $config->bucket . '.s3.' . $config->region . (str_starts_with($config->region, 'cn-') ? '.amazonaws.com.cn' : '.amazonaws.com'),
            'digitalocean' => $config->bucket . '.' . $config->region . '.digitaloceanspaces.com',
            'wasabi' => ($config->region === 'us-east-1' ? 's3.wasabisys.com' : 's3.' . $config->region . '.wasabisys.com') . '/' . $config->bucket,
            'bunny' => $config->iamKey,
            default => throw new InvalidArgumentException('Select a supported service provider.'),
        };
    }

    /** Repairing credentials must not change the location of already-offloaded objects. */
    private function sameExistingTarget(SMARL_CloudConfig $requested, SMARL_CloudConfig $current, string $type): bool
    {
        if ($type !== 'existing' || $current->bucket === '' || $requested->serviceProvider !== $current->serviceProvider
            || $requested->bucket !== $current->bucket || $requested->useCloudFront !== $current->useCloudFront) return false;
        if (strcasecmp($requested->region, $current->region) !== 0) return false;
        if ($requested->serviceProvider === 'bunny' && $requested->getBunnyStorageEndpoint() !== $current->getBunnyStorageEndpoint()) {
            // Older settings guessed a host from region and had no saved endpoint. Trust verified metadata
            // from the same account/zone when correcting that guess, preserving remote-only delivery.
            if ($current->bunnyStorageEndpoint !== '' || !hash_equals($current->iamSecret, $requested->iamSecret)) return false;
        }
        if (SMARL_CloudConfig::normalizePublicBaseUrl($this->directPublicUrl($requested)) === $current->getPublicBaseUrl()) return true;
        // Correct older invalid TLS/partition URLs without stranding this same bucket's offloaded files.
        return $requested->serviceProvider === 'aws'
            && $current->getPublicBaseUrl() === 'https://' . $requested->bucket . '.s3.' . $requested->region . '.amazonaws.com';
    }

    public function configureAws(): void
    {
        $this->authorize();
        $sync = get_option('smarl_sync_settings', []);
        if (is_array($sync) && ($sync['s3SyncStatus'] ?? '') === 'processing') {
            wp_send_json_error(__('Stop the current sync before changing its cloud configuration.', 'smartsync-lite-media-offloader-and-assets-cdn'), 409);
        }
        $lock = SMARL_Helper::acquireSyncLock();
        if ($lock === null) {
            wp_send_json_error(__('Another sync or configuration request is running. Try again shortly.', 'smartsync-lite-media-offloader-and-assets-cdn'), 409);
        }
        $failure = null;
        try {
            global $wpdb;
            $rawCurrent = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'smarl_settings')));
            $current = SMARL_CloudConfig::fromArray(is_array($rawCurrent) ? $rawCurrent : []);
            $sync = get_option('smarl_sync_settings', []);
            if (is_array($sync) && ($sync['s3SyncStatus'] ?? '') === 'processing') throw new InvalidArgumentException('Stop the current sync before changing its cloud configuration.', 409);
            $missingLocalFiles = SMARL_Helper::hasMissingOffloadedFiles();
            $config = $this->newConfiguration();
            $config->serviceProvider = $this->input('service_provider');
            $config->bucket = $this->input('bucket_name');
            $config->region = $this->input('bucket_region', 'us-east-1');
            $config->iamKey = $this->input('iam_key');
            $config->iamSecret = $this->input('iam_secret');
            $type = $this->input('bucket_type');
            $config->useCloudFront = filter_var($this->input('use_cloudfront', 'false'), FILTER_VALIDATE_BOOLEAN);
            $this->validateAwsConfiguration($config->bucket, $config->serviceProvider, $config->iamSecret, $config->iamKey, $type, $config->region);
            if ($config->useCloudFront) throw new InvalidArgumentException(\SmartSyncS3\SMARL_Edition::proMessage(), 403);
            if ($config->serviceProvider === 'bunny') {
                $cached = get_transient('smarl_bunny_zones_' . get_current_user_id());
                if (!is_array($cached) || !hash_equals($cached['key_hash'] ?? '', hash('sha256', $config->iamSecret)) || !isset($cached['zones'][$config->bucket])) throw new InvalidArgumentException('List Bunny storage zones again before saving.');
                $zone = $cached['zones'][$config->bucket];
                if (!in_array($config->iamKey, $zone['pullzones'], true)) throw new InvalidArgumentException('Choose a pull zone connected to the selected Bunny storage zone.');
                $config->region = $zone['region'];
                $config->bunnyStoragePassword = $zone['password'];
                $config->bunnyStorageEndpoint = $zone['endpoint'] ?? '';
                $config->verifyBunnyConnection();
            }
            $repair = $this->sameExistingTarget($config, $current, $type);
            if ($missingLocalFiles && !$repair) throw new InvalidArgumentException('Restore offloaded media locally before reconfiguring cloud storage.');
            if ($current->bucket !== '' && SMARL_Helper::storageTargetFingerprint($config) !== SMARL_Helper::storageTargetFingerprint($current)) {
                SMARL_Helper::bindLegacyOffloadedFiles($current);
            }
            if ($repair) {
                $credentials = $config;
                $config = clone ($this->appConfig->toArray() === $current->toArray() ? $this->appConfig : $current);
                $config->iamKey = $credentials->iamKey;
                $config->iamSecret = $credentials->iamSecret;
                if ($config->serviceProvider === 'bunny') {
                    $config->bunnyStoragePassword = $credentials->bunnyStoragePassword;
                    $config->bunnyStorageEndpoint = $credentials->bunnyStorageEndpoint;
                }
                if ($config->serviceProvider === 'aws' && !$config->useCloudFront) $config->url = substr(SMARL_CloudConfig::normalizePublicBaseUrl($this->directPublicUrl($credentials)), 8);
                if ($config->serviceProvider !== 'bunny') $config->getS3Client()->headBucket(['Bucket' => $config->bucket]);
            } else {
                if ($type === 'new') $this->createBucket($config);
                elseif ($config->serviceProvider !== 'bunny') $config->getS3Client()->headBucket(['Bucket' => $config->bucket]);
                $this->configurePublicBucket($config);
                $config->urlType = 's3';
                $config->url = $this->directPublicUrl($config);
                $config->url = substr($config->getPublicBaseUrl(), 8);
            }
            $sync = get_option('smarl_sync_settings', []);
            if (is_array($sync) && ($sync['s3SyncStatus'] ?? '') === 'processing') throw new InvalidArgumentException('Stop the current sync before changing its cloud configuration.', 409);
            SMARL_AjaxHandler::resetSyncState(new SMARL_S3SyncSettings());
            $config->save();
            $this->appConfig = $config;
            if (!$repair) SMARL_CustomUrl::load()->reset();
            delete_transient('smarl_bunny_zones_' . get_current_user_id());
        } catch (\Throwable $error) { $failure = $error; }
        finally { SMARL_Helper::releaseSyncLock($lock); }
        if ($failure !== null) { $this->sendError($failure); return; }
        wp_send_json_success(__('Cloud configuration saved successfully.', 'smartsync-lite-media-offloader-and-assets-cdn'));
    }

    private function bunnyZones(string $apiKey): array
    {
        $zones = [];
        for ($page = 1; $page <= 1000; $page++) {
            $data = $this->bunnyAccountGet('/storagezone?page=' . $page . '&perPage=1000&includeDeleted=false', $apiKey);
            $items = array_is_list($data) ? $data : ($data['Items'] ?? null);
            if (!is_array($items) || !array_is_list($items)) throw new InvalidArgumentException(__('Bunny returned an invalid storage zone list. Try listing the zones again.', 'smartsync-lite-media-offloader-and-assets-cdn'));
            $hasMore = !array_is_list($data) && ($data['HasMoreItems'] ?? false) === true;
            if ((!array_is_list($data) && isset($data['CurrentPage']) && (int) $data['CurrentPage'] !== $page) || ($hasMore && !$items)) {
                throw new InvalidArgumentException(__('Bunny returned invalid storage zone pagination. Try again.', 'smartsync-lite-media-offloader-and-assets-cdn'));
            }
            foreach ($items as $item) {
                if (!is_array($item) || !empty($item['Deleted']) || !is_string($item['Name'] ?? null) || $item['Name'] === '') continue;
                // List responses may omit sensitive/detail fields. Fetch details only when needed.
                if ((!is_string($item['Password'] ?? null) || $item['Password'] === '' || !isset($item['PullZones'])) && !empty($item['Id'])) {
                    $detail = $this->bunnyAccountGet('/storagezone/' . (int) $item['Id'], $apiKey);
                    if (($detail['Name'] ?? null) !== $item['Name']) throw new InvalidArgumentException(__('Bunny returned inconsistent storage zone details. Try again.', 'smartsync-lite-media-offloader-and-assets-cdn'));
                    $item = array_replace($item, $detail);
                }
                $region = strtoupper(($item['Region'] ?? '') ?: 'DE');
                $endpoint = '';
                if (!empty($item['StorageHostname'])) $endpoint = SMARL_CloudConfig::validateBunnyStorageEndpoint($item['StorageHostname']);
                $systemHosts = $customHosts = [];
                foreach ($item['PullZones'] ?? [] as $zone) {
                    if (!is_array($zone) || ($zone['Enabled'] ?? true) === false || !empty($zone['Suspended']) || !empty($zone['ZoneSecurityEnabled'])) continue;
                    foreach ($zone['Hostnames'] ?? [] as $host) {
                        if (!is_array($host) || !is_string($host['Value'] ?? null) || $host['Value'] === '') continue;
                        try { $name = $this->validatePublicHostname($host['Value']); }
                        catch (InvalidArgumentException $error) { continue; }
                        $system = !empty($host['IsSystemHostname']) || str_ends_with($name, '.b-cdn.net');
                        if (!$system && array_key_exists('HasCertificate', $host) && !$host['HasCertificate']) continue;
                        if ($system) $systemHosts[] = $name;
                        else $customHosts[] = $name;
                    }
                }
                $password = is_string($item['Password'] ?? null) ? $item['Password'] : '';
                $hosts = $password !== '' ? array_values(array_unique(array_merge($systemHosts, $customHosts))) : [];
                $zones[$item['Name']] = ['region' => $region, 'password' => $password, 'endpoint' => $endpoint, 'pullzones' => $hosts];
                if (!$hosts) $zones[$item['Name']]['issue'] = $password === ''
                    ? __('This account API key cannot read the storage zone credentials. Check its permissions.', 'smartsync-lite-media-offloader-and-assets-cdn')
                    : __('Connect an active public pull zone to this storage zone. Use its Bunny hostname or a custom hostname with an issued SSL certificate; token-protected zones need signed URLs.', 'smartsync-lite-media-offloader-and-assets-cdn');
            }
            if (!$hasMore) return $zones;
        }
        throw new InvalidArgumentException(__('Bunny returned too many pages of storage zones. Try a more restricted account key.', 'smartsync-lite-media-offloader-and-assets-cdn'));
    }

    private function bunnyAccountGet(string $path, string $apiKey): array
    {
        $response = wp_safe_remote_get('https://api.bunny.net' . $path, [
            'timeout' => 30, 'redirection' => 0, 'headers' => ['AccessKey' => $apiKey, 'Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) throw new InvalidArgumentException(__('Unable to reach the Bunny account API. Check outbound HTTPS and DNS on the WordPress server.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        $status = (int) wp_remote_retrieve_response_code($response);
        if (in_array($status, [401, 403], true)) throw new InvalidArgumentException(__('Bunny rejected the account API key or its permissions. Use your account API key, not a storage zone password or Stream API key.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        if ($status === 429) throw new InvalidArgumentException(__('Bunny API rate limit reached. Wait a moment, then try again.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        /* translators: %d: HTTP response status code. */
        if ($status !== 200) throw new InvalidArgumentException(sprintf(__('Bunny account API request failed (HTTP %d). Try again or check the account status.', 'smartsync-lite-media-offloader-and-assets-cdn'), $status));
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) throw new InvalidArgumentException(__('Bunny returned an invalid account API response. Try again.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        return $data;
    }

    private function validatePublicHostname(string $value): string
    {
        $value = strtolower(rtrim(trim($value), '.'));
        if (!filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($value, '.')
            || filter_var($value, FILTER_VALIDATE_IP) || strlen($value) > 253) throw new InvalidArgumentException('Enter a public storage hostname.');
        return $value;
    }

    public function listS3Buckets(): void
    {
        $this->authorize();
        $sync = get_option('smarl_sync_settings', []);
        if (is_array($sync) && ($sync['s3SyncStatus'] ?? '') === 'processing') {
            wp_send_json_error(__('Wait for the current sync to finish, or stop it before discovering cloud storage.', 'smartsync-lite-media-offloader-and-assets-cdn'), 409);
        }
        try {
            $provider = $this->input('service_provider');
            \SmartSyncS3\SMARL_Edition::requireProvider($provider);
            $config = $this->newConfiguration();
            $config->serviceProvider = $provider;
            $config->iamKey = $this->input('iam_key');
            $config->iamSecret = $this->input('iam_secret');
            $buckets = [];
            if ($provider === 'bunny') {
                if ($config->iamSecret === '') throw new InvalidArgumentException('A Bunny account API key is required.');
                delete_transient('smarl_bunny_zones_' . get_current_user_id());
                $zones = $this->bunnyZones($config->iamSecret);
                set_transient('smarl_bunny_zones_' . get_current_user_id(), ['key_hash' => hash('sha256', $config->iamSecret), 'zones' => $zones], 900);
                foreach ($zones as $name => $zone) {
                    $bucket = ['name' => $name, 'region' => $zone['region'], 'pullzones' => $zone['pullzones']];
                    if (isset($zone['issue'])) $bucket['issue'] = $zone['issue'];
                    $buckets[] = $bucket;
                }
            } else {
                if ($config->iamKey === '' || $config->iamSecret === '') throw new InvalidArgumentException('Provider credentials are required.');
                $config->region = $this->input('bucket_region', match ($provider) { 'digitalocean' => 'nyc3', default => 'us-east-1' });
                if ($provider === 'aws') {
                    $preference = $this->input('bucket_region');
                    if ($preference !== '' && !preg_match('/^[a-z]{2}(?:-[a-z]+)+-\d+$/', $preference)) throw new InvalidArgumentException(__('Enter a valid AWS region, such as ap-south-1.', 'smartsync-lite-media-offloader-and-assets-cdn'));
                    $config->region = $preference !== '' ? $preference : 'us-east-1';
                    update_option('smarl_aws_discovery_region', $preference, false);
                    if (get_option('smarl_aws_discovery_region', null) !== $preference) throw new SMARL_ProviderException(__('The AWS discovery region could not be saved. Check the WordPress database and try again.', 'smartsync-lite-media-offloader-and-assets-cdn'));
                }
                $client = $config->getS3Client();
                $result = $client->listBuckets();
                foreach ($result['Buckets'] ?? [] as $bucket) {
                    $region = $config->region;
                    {
                        $location = $client->getBucketLocation(['Bucket' => $bucket['Name']]);
                        $region = $this->normalizeBucketLocation($location['LocationConstraint'] ?? '', $provider === 'aws' ? 'us-east-1' : $config->region);
                    }
                    $buckets[] = ['name' => $bucket['Name'], 'region' => $region];
                }
            }
        } catch (\Throwable $error) { $this->sendError($error); return; }
        wp_send_json_success(['buckets' => $buckets]);
    }

    /** Normalize SDK values, including an XML wrapper retained by its S3 location mutator. */
    private function normalizeBucketLocation($location, string $fallback): string {
        $invalid = __('The provider returned an invalid bucket region. Refresh the storage list or enter the bucket and its region manually.', 'smartsync-lite-media-offloader-and-assets-cdn');
        if (!is_string($location)) throw new InvalidArgumentException($invalid);
        $region = trim($location);
        if (str_contains($region, '<')) {
            if (strlen($region) > 4096 || stripos($region, '<!DOCTYPE') !== false || stripos($region, '<!ENTITY') !== false) throw new InvalidArgumentException($invalid);
            // Some SDK versions capture from the XML declaration's closing >,
            // retaining the opening LocationConstraint tag but removing its closing tag.
            if (!str_ends_with($region, '/>') && preg_match('/^<LocationConstraint\b[^<>]*>\s*[a-zA-Z0-9-]*\s*$/D', $region)) $region .= '</LocationConstraint>';
            $xml = simplexml_load_string($region, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if ($xml === false || $xml->getName() !== 'LocationConstraint' || count($xml->children()) !== 0) throw new InvalidArgumentException($invalid);
            $region = trim((string) $xml);
        }
        if ($region === '') $region = $fallback;
        if ($region === 'EU') $region = 'eu-west-1';
        if (!preg_match('/^(?:[a-z]+(?:-[a-z0-9]+)+-\d+|[a-z]{3}\d+)$/D', $region)) throw new InvalidArgumentException($invalid);
        return $region;
    }

}
