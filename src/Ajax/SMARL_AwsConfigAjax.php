<?php
namespace SmartSyncS3\Ajax;
if (!defined('ABSPATH')) exit;

use Aws\Exception\AwsException;
use InvalidArgumentException;
use RuntimeException;
use SmartSyncS3\Helpers\SMARL_AwsHelper;
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Models\SMARL_CloudConfig;
use SmartSyncS3\Models\SMARL_CustomUrl;
use SmartSyncS3\Models\SMARL_S3SyncSettings;
use SmartSyncS3\Services\SMARLI_GoogleService;
use SmartSyncS3\Exceptions\SMARL_ProviderException;

/** Privileged provider provisioning and CloudFront custom domain endpoints. */
class SMARL_AwsConfigAjax
{
    private ?SMARL_CloudConfig $appConfig;
    public function __construct(?SMARL_CloudConfig $configuration) { $this->appConfig = $configuration ?? new SMARL_CloudConfig(); }

    /** Refresh registered callbacks after WordPress changes the current blog. */
    public function setConfiguration(SMARL_CloudConfig $configuration): void { $this->appConfig = $configuration; }

    public function initializeActions(): void
    {
        add_action('wp_ajax_check_dns_record', [$this, 'checkDnsRecord']);
        add_action('wp_ajax_generate_ssl_certificate', [$this, 'generateSslCertificate']);
        add_action('wp_ajax_list_s3_buckets', [$this, 'listS3Buckets']);
        add_action('wp_ajax_configure_aws', [$this, 'configureAws']);
        add_action('wp_ajax_attach_to_cloudfront', [$this, 'updateCloudFrontAlternateDomain']);
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
        $message = $error instanceof InvalidArgumentException || $error instanceof SMARL_ProviderException ? $error->getMessage()
            : __('The cloud provider request failed. Check the credentials, permissions, region, and provider settings, then try again.', 'smartsync-lite-media-offloader-and-assets-cdn');
        if ($error instanceof AwsException && $error->getAwsErrorCode()) $message .= ' (' . preg_replace('/[^a-zA-Z0-9_-]/', '', $error->getAwsErrorCode()) . ')';
        wp_send_json_error($message, in_array($error->getCode(), [404, 409], true) ? $error->getCode() : 400);
    }

    private function requireCloudFront(): void
    {
        if (!$this->appConfig->isConfigured() || $this->appConfig->serviceProvider !== 'aws'
            || !$this->appConfig->useCloudFront || $this->appConfig->distributionId === '' || $this->appConfig->cloudfrontUrl === '') {
            throw new InvalidArgumentException('Configure an AWS CloudFront distribution before managing its custom domain.');
        }
        if ($this->appConfig->customUrl === null) $this->appConfig->customUrl = SMARL_CustomUrl::load();
    }

    /** Called under the sync lock before any domain or provider write. */
    private function requireFreshCloudFront(): void
    {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'smarl_settings'));
        $data = maybe_unserialize($raw);
        if (!is_array($data) || !$data) throw new InvalidArgumentException('The cloud configuration no longer exists. Reload the page and configure CloudFront.', 404);
        if (SMARL_CloudConfig::fromArray($data)->toArray() !== $this->appConfig->toArray()) throw new InvalidArgumentException('Cloud settings changed in another request. Reload the page before managing its domain.', 409);
        $rawCustom = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", SMARL_CustomUrl::$optionName));
        $customData = maybe_unserialize($rawCustom);
        $current = SMARL_CustomUrl::fromArray(is_array($customData) ? $customData : []);
        if ($this->appConfig->customUrl !== null && $this->appConfig->customUrl->toArray() !== $current->toArray()) throw new InvalidArgumentException('Custom domain settings changed in another request. Reload the page before continuing.', 409);
        $this->appConfig->customUrl = $current;
        $this->requireCloudFront();
    }

    private function validateDomain(string $domain): string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($domain, '.')
            || filter_var($domain, FILTER_VALIDATE_IP) || strlen($domain) > 253) {
            throw new InvalidArgumentException('Enter a hostname, such as cdn.example.com, without a URL scheme or path.');
        }
        return $domain;
    }

    private function validateAwsConfiguration($bucketName, $provider, $secret, $key, $type, $region): void
    {
        if (!in_array($provider, SMARL_CloudConfig::PROVIDERS, true)) throw new InvalidArgumentException('Select a supported service provider.');
        if (!in_array($type, ['new', 'existing'], true)) throw new InvalidArgumentException('Select a new or existing bucket.');
        $pattern = $provider === 'bunny' ? '/^[a-zA-Z0-9-]{2,100}$/'
            : ($provider === 'google' ? '/^[a-z0-9][a-z0-9._-]{1,220}[a-z0-9]$/' : '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/');
        if (!preg_match($pattern, $bucketName) || str_contains($bucketName, '..') || filter_var($bucketName, FILTER_VALIDATE_IP)
            || ($provider !== 'google' && (str_contains($bucketName, '.-') || str_contains($bucketName, '-.')))) throw new InvalidArgumentException('Enter a valid bucket or storage zone name.');
        if ($provider !== 'google' && ($secret === '' || $key === '')) throw new InvalidArgumentException('Provider credentials are required.');
        if ($region === '' || !preg_match('/^[a-zA-Z0-9-]+$/', $region)) throw new InvalidArgumentException('Select a valid storage region.');
        if ($type === 'new' && $provider === 'bunny') throw new InvalidArgumentException('Create a Bunny storage zone and connected pull zone in Bunny first, then select it here.');
    }

    private function createBucket(SMARL_CloudConfig $config): void
    {
        if ($config->serviceProvider === 'google') {
            $config->createGoogleBucket();
            return;
        }
        $client = $config->getS3Client();
        $args = ['Bucket' => $config->bucket];
        if ($config->serviceProvider === 'aws' && $config->region !== 'us-east-1') $args['CreateBucketConfiguration'] = ['LocationConstraint' => $config->region];
        if (in_array($config->serviceProvider, ['digitalocean', 'wasabi'], true)) $args['ACL'] = 'public-read';
        $client->createBucket($args);
        $client->waitUntil('BucketExists', ['Bucket' => $config->bucket]);
    }

    private function readBucketPolicy($client, string $bucket): array
    {
        $policy = ['Version' => '2012-10-17', 'Statement' => []];
        try {
            $result = $client->getBucketPolicy(['Bucket' => $bucket]);
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
    private function setBucketPolicy($client, string $bucket, array $statement): void
    {
        $policy = $this->readBucketPolicy($client, $bucket);
        $policy['Statement'] = array_values(array_filter($policy['Statement'], static fn($item) => !in_array($item['Sid'] ?? '', ['SmartSyncPublicRead', 'SmartSyncCloudFrontRead'], true)));
        $policy['Statement'][] = $statement;
        $client->putBucketPolicy(['Bucket' => $bucket, 'Policy' => json_encode($policy, JSON_THROW_ON_ERROR)]);
    }

    private function configurePublicBucket(SMARL_CloudConfig $config): void
    {
        if ($config->serviceProvider === 'google') {
            $config->configureGooglePublicAccess();
        } elseif ($config->serviceProvider === 'aws') {
            $client = $config->getS3Client();
            $partition = str_starts_with($config->region, 'cn-') ? 'aws-cn' : (str_starts_with($config->region, 'us-gov-') ? 'aws-us-gov' : 'aws');
            $client->putPublicAccessBlock(['Bucket' => $config->bucket, 'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => true, 'IgnorePublicAcls' => true, 'BlockPublicPolicy' => false, 'RestrictPublicBuckets' => false,
            ]]);
            $this->setBucketPolicy($client, $config->bucket, ['Sid' => 'SmartSyncPublicRead', 'Effect' => 'Allow', 'Principal' => '*', 'Action' => 's3:GetObject', 'Resource' => 'arn:' . $partition . ':s3:::' . $config->bucket . '/*']);
        }
    }

    /** No credentials are stored in this receipt; it survives a failed later provisioning step. */
    private function saveCloudFrontCheckpoint(array $checkpoints): void
    {
        global $wpdb;
        update_option('smarl_cloudfront_pending', $checkpoints, false);
        $saved = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'smarl_cloudfront_pending')));
        if ($saved !== $checkpoints) throw new RuntimeException('Unable to save the CloudFront recovery checkpoint.');
    }

    private function configureCloudFront(SMARL_CloudConfig $config): void
    {
        global $wpdb;
        // Fail read permissions and account discovery before allocating either paid resource.
        $account = $config->getStsClient('us-east-1')->getCallerIdentity()['Account'] ?? '';
        if (!is_string($account) || !preg_match('/^\d{12}$/', $account)) throw new RuntimeException('AWS did not return a valid account identity.');
        $client = $config->getS3Client();
        $this->readBucketPolicy($client, $config->bucket);
        $cloudFront = $config->getCloudfrontClient();
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'smarl_cloudfront_pending'));
        $checkpoints = $raw === null ? [] : maybe_unserialize($raw);
        if (!is_array($checkpoints)) throw new InvalidArgumentException(__('The CloudFront recovery records are invalid. Review the saved AWS resources before retrying setup.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        $key = hash('sha256', $account . ':' . $config->region . ':' . $config->bucket);
        if (isset($checkpoints[$key])) {
            $entry = $checkpoints[$key];
            if (!is_array($entry) || ($entry['account'] ?? '') !== $account || ($entry['bucket'] ?? '') !== $config->bucket || ($entry['region'] ?? '') !== $config->region
                || !is_string($entry['oac_name'] ?? null) || !is_string($entry['caller_reference'] ?? null)
                || !is_string($entry['oac_id'] ?? null) || !is_string($entry['distribution_id'] ?? null)) {
                throw new InvalidArgumentException(__('The CloudFront recovery record does not match this storage target. Review its AWS resources before retrying.', 'smartsync-lite-media-offloader-and-assets-cdn'));
            }
        } else {
            $entry = ['account' => $account, 'bucket' => $config->bucket, 'region' => $config->region,
                'oac_name' => SMARL_AwsHelper::generateOACArray($config->bucket)['OriginAccessControlConfig']['Name'],
                'caller_reference' => bin2hex(random_bytes(16)), 'oac_id' => '', 'distribution_id' => '', 'domain' => ''];
            $checkpoints[$key] = $entry;
            try { $this->saveCloudFrontCheckpoint($checkpoints); }
            catch (\Throwable $error) { throw new SMARL_ProviderException(__('Unable to save the CloudFront recovery record. No CloudFront resources were created. Check the WordPress database before retrying.', 'smartsync-lite-media-offloader-and-assets-cdn')); }
        }
        if ($entry['oac_id'] !== '') {
            $oac = $cloudFront->getOriginAccessControl(['Id' => $entry['oac_id']])['OriginAccessControl'] ?? [];
            $oacConfig = $oac['OriginAccessControlConfig'] ?? [];
            if (($oac['Id'] ?? '') !== $entry['oac_id'] || ($oacConfig['Name'] ?? '') !== $entry['oac_name']
                || ($oacConfig['OriginAccessControlOriginType'] ?? '') !== 's3' || ($oacConfig['SigningBehavior'] ?? '') !== 'always' || ($oacConfig['SigningProtocol'] ?? '') !== 'sigv4') {
                throw new InvalidArgumentException(__('The saved CloudFront origin access control changed. Review the recovery resources before retrying.', 'smartsync-lite-media-offloader-and-assets-cdn'));
            }
        } else {
            $oacArgs = SMARL_AwsHelper::generateOACArray($config->bucket);
            $oacArgs['OriginAccessControlConfig']['Name'] = $entry['oac_name'];
            $entry['oac_id'] = $cloudFront->createOriginAccessControl($oacArgs)['OriginAccessControl']['Id'];
            $checkpoints[$key] = $entry;
            try { $this->saveCloudFrontCheckpoint($checkpoints); }
            catch (\Throwable $error) {
                /* translators: %s: AWS origin access control ID. */
                throw new SMARL_ProviderException(sprintf(__('CloudFront created origin access control %s, but its recovery record could not be saved. Review that AWS resource before retrying setup.', 'smartsync-lite-media-offloader-and-assets-cdn'), $entry['oac_id']));
            }
        }
        if ($entry['distribution_id'] !== '') {
            $distribution = $cloudFront->getDistribution(['Id' => $entry['distribution_id']])['Distribution'] ?? [];
            $distributionConfig = $distribution['DistributionConfig'] ?? [];
            $origins = $distributionConfig['Origins']['Items'] ?? [];
            if (($distribution['Id'] ?? '') !== $entry['distribution_id'] || ($distributionConfig['CallerReference'] ?? '') !== $entry['caller_reference']
                || count($origins) !== 1 || ($origins[0]['DomainName'] ?? '') !== $config->bucket . '.s3.' . $config->region . '.amazonaws.com'
                || ($origins[0]['OriginAccessControlId'] ?? '') !== $entry['oac_id']) {
                throw new InvalidArgumentException(__('The saved CloudFront distribution changed. Review the recovery resources before retrying.', 'smartsync-lite-media-offloader-and-assets-cdn'));
            }
        } else {
            $distributionArgs = SMARL_AwsHelper::createCloudfrontArray($config->bucket, $config->region, $entry['oac_id']);
            $distributionArgs['DistributionConfig']['CallerReference'] = $entry['caller_reference'];
            $distribution = $cloudFront->createDistribution($distributionArgs)['Distribution'];
            $entry['distribution_id'] = $distribution['Id'];
            $entry['domain'] = $distribution['DomainName'];
            $checkpoints[$key] = $entry;
            try { $this->saveCloudFrontCheckpoint($checkpoints); }
            catch (\Throwable $error) {
                /* translators: 1: AWS distribution ID, 2: AWS origin access control ID. */
                throw new SMARL_ProviderException(sprintf(__('CloudFront created distribution %1$s with origin access control %2$s, but its recovery record could not be saved. Review those AWS resources before retrying setup.', 'smartsync-lite-media-offloader-and-assets-cdn'), $entry['distribution_id'], $entry['oac_id']));
            }
        }
        $config->distributionId = $entry['distribution_id'];
        $config->cloudfrontUrl = $distribution['DomainName'];
        $config->url = $config->cloudfrontUrl;
        $this->setBucketPolicy($client, $config->bucket, [
            'Sid' => 'SmartSyncCloudFrontRead', 'Effect' => 'Allow', 'Principal' => ['Service' => 'cloudfront.amazonaws.com'],
            'Action' => 's3:GetObject', 'Resource' => 'arn:aws:s3:::' . $config->bucket . '/*',
            'Condition' => ['StringEquals' => ['AWS:SourceArn' => 'arn:aws:cloudfront::' . $account . ':distribution/' . $config->distributionId]],
        ]);
        $client->putPublicAccessBlock(['Bucket' => $config->bucket, 'PublicAccessBlockConfiguration' => [
            'BlockPublicAcls' => true, 'IgnorePublicAcls' => true, 'BlockPublicPolicy' => true, 'RestrictPublicBuckets' => true,
        ]]);
    }

    private function directPublicUrl(SMARL_CloudConfig $config): string
    {
        return match ($config->serviceProvider) {
            'aws' => str_contains($config->bucket, '.')
                ? 's3.' . $config->region . (str_starts_with($config->region, 'cn-') ? '.amazonaws.com.cn/' : '.amazonaws.com/') . $config->bucket
                : $config->bucket . '.s3.' . $config->region . (str_starts_with($config->region, 'cn-') ? '.amazonaws.com.cn' : '.amazonaws.com'),
            'digitalocean' => $config->bucket . '.' . $config->region . '.digitaloceanspaces.com',
            'wasabi' => ($config->region === 'us-east-1' ? 's3.wasabisys.com' : 's3.' . $config->region . '.wasabisys.com') . '/' . $config->bucket,
            'google' => 'storage.googleapis.com/' . $config->bucket,
            'cloudflare' => $config->cloudflareUrl,
            'bunny' => $config->iamKey,
            default => throw new InvalidArgumentException('Select a supported service provider.'),
        };
    }

    /** Repairing credentials must not change the location of already-offloaded objects. */
    private function sameExistingTarget(SMARL_CloudConfig $requested, SMARL_CloudConfig $current, string $type): bool
    {
        if ($type !== 'existing' || $current->bucket === '' || $requested->serviceProvider !== $current->serviceProvider
            || $requested->bucket !== $current->bucket || $requested->useCloudFront !== $current->useCloudFront) return false;
        if ($requested->serviceProvider !== 'cloudflare' && strcasecmp($requested->region, $current->region) !== 0) return false;
        if ($requested->serviceProvider === 'bunny' && $requested->getBunnyStorageEndpoint() !== $current->getBunnyStorageEndpoint()) {
            // Older settings guessed a host from region and had no saved endpoint. Trust verified metadata
            // from the same account/zone when correcting that guess, preserving remote-only delivery.
            if ($current->bunnyStorageEndpoint !== '' || !hash_equals($current->iamSecret, $requested->iamSecret)) return false;
        }
        if ($requested->serviceProvider === 'cloudflare' && SMARL_CloudConfig::validateCloudflareEndpoint($requested->cloudflareEndpoint) !== SMARL_CloudConfig::validateCloudflareEndpoint($current->cloudflareEndpoint)) return false;
        if ($requested->useCloudFront) return $current->distributionId !== '' && $current->cloudfrontUrl !== '' && $current->url !== '';
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
            $config->region = $this->input('bucket_region', $config->serviceProvider === 'google' ? 'US' : 'us-east-1');
            $config->iamKey = $this->input('iam_key');
            $config->iamSecret = $this->input('iam_secret');
            $type = $this->input('bucket_type');
            $config->useCloudFront = filter_var($this->input('use_cloudfront', 'false'), FILTER_VALIDATE_BOOLEAN);
            $this->validateAwsConfiguration($config->bucket, $config->serviceProvider, $config->iamSecret, $config->iamKey, $type, $config->region);
            if ($config->useCloudFront && $config->serviceProvider !== 'aws') throw new InvalidArgumentException('CloudFront setup is available only for AWS S3.');
            if ($config->useCloudFront && preg_match('/^(?:cn-|us-gov-)/', $config->region)) {
                throw new InvalidArgumentException(__('Automatic CloudFront setup requires commercial AWS credentials and regions. Choose direct S3 delivery for China or GovCloud buckets.', 'smartsync-lite-media-offloader-and-assets-cdn'));
            }
            if ($config->serviceProvider === 'google') {
                $selectionToken = $this->input('google_selection_token');
                if ($selectionToken !== '') {
                    $config->googleServicePath = SMARLI_GoogleService::getTempPath($selectionToken);
                    if ($config->googleServicePath === '') throw new InvalidArgumentException(__('The Google credential selection expired. List buckets again before saving.', 'smartsync-lite-media-offloader-and-assets-cdn'));
                } elseif ($current->serviceProvider === 'google') $config->googleServicePath = $current->googleServicePath;
                elseif (!SMARLI_GoogleService::hasPendingSelections()) $config->googleServicePath = SMARLI_GoogleService::getTempPath();
                if (SMARLI_GoogleService::getServiceJSON($config->googleServicePath) === false) throw new InvalidArgumentException('Upload a valid Google service account JSON file first.');
            } elseif ($config->serviceProvider === 'cloudflare') {
                $config->cloudflareEndpoint = SMARL_CloudConfig::validateCloudflareEndpoint($this->input('cloudflare_endpoint'));
                $config->cloudflareUrl = substr(SMARL_CloudConfig::normalizePublicBaseUrl($this->input('cloudflare_url')), 8);
                $config->region = 'auto';
            } elseif ($config->serviceProvider === 'bunny') {
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
                if ($config->serviceProvider === 'google') $config->googleServicePath = $credentials->googleServicePath;
                if ($config->serviceProvider === 'bunny') {
                    $config->bunnyStoragePassword = $credentials->bunnyStoragePassword;
                    $config->bunnyStorageEndpoint = $credentials->bunnyStorageEndpoint;
                }
                if ($config->serviceProvider === 'aws' && !$config->useCloudFront) $config->url = substr(SMARL_CloudConfig::normalizePublicBaseUrl($this->directPublicUrl($credentials)), 8);
                if ($config->serviceProvider === 'google') $config->verifyGoogleConnection();
                if (!in_array($config->serviceProvider, ['google', 'bunny'], true)) $config->getS3Client()->headBucket(['Bucket' => $config->bucket]);
            } else {
                if ($type === 'new') $this->createBucket($config);
                elseif (!in_array($config->serviceProvider, ['google', 'bunny'], true)) $config->getS3Client()->headBucket(['Bucket' => $config->bucket]);
                if ($config->useCloudFront) {
                    $this->configureCloudFront($config);
                    $config->urlType = 'cloudfront';
                } else {
                    $this->configurePublicBucket($config);
                    $config->urlType = 's3';
                    $config->url = $this->directPublicUrl($config);
                }
                $config->url = substr($config->getPublicBaseUrl(), 8);
            }
            $previousGooglePath = $current->googleServicePath;
            $sync = get_option('smarl_sync_settings', []);
            if (is_array($sync) && ($sync['s3SyncStatus'] ?? '') === 'processing') throw new InvalidArgumentException('Stop the current sync before changing its cloud configuration.', 409);
            SMARL_AjaxHandler::resetSyncState(new SMARL_S3SyncSettings());
            $config->save();
            $this->appConfig = $config;
            if (!$repair) SMARL_CustomUrl::load()->reset();
            delete_transient('smarl_google_credentials_' . get_current_user_id());
            delete_transient('smarl_bunny_zones_' . get_current_user_id());
            if ($previousGooglePath !== '' && $previousGooglePath !== $config->googleServicePath) SMARLI_GoogleService::deleteCredentialFile($previousGooglePath);
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
                        try { $name = $this->validateDomain($host['Value']); }
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

    public function listS3Buckets(): void
    {
        $this->authorize();
        try {
            $provider = $this->input('service_provider');
            if (!in_array($provider, SMARL_CloudConfig::PROVIDERS, true)) throw new InvalidArgumentException('Select a supported service provider.');
            $config = $this->newConfiguration();
            $config->serviceProvider = $provider;
            $config->iamKey = $this->input('iam_key');
            $config->iamSecret = $this->input('iam_secret');
            $buckets = [];
            $selectionToken = '';
            if ($provider === 'google') {
                $selection = SMARLI_GoogleService::moveFileTemp(true);
                $selectionToken = $selection['selectionToken'] ?? '';
                $path = $selection['path'] ?? '';
                if ($path === '' && $this->appConfig->serviceProvider === 'google') $path = $this->appConfig->googleServicePath;
                if ($path === '' && !SMARLI_GoogleService::hasPendingSelections()) $path = SMARLI_GoogleService::getTempPath();
                $buckets = (new SMARLI_GoogleService($path))->getBuckets();
            } elseif ($provider === 'bunny') {
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
                $config->region = $this->input('bucket_region', match ($provider) { 'digitalocean' => 'nyc3', 'cloudflare' => 'auto', default => 'us-east-1' });
                if ($provider === 'cloudflare') $config->cloudflareEndpoint = SMARL_CloudConfig::validateCloudflareEndpoint($this->input('cloudflare_endpoint'));
                $client = $config->getS3Client();
                $result = $client->listBuckets();
                foreach ($result['Buckets'] ?? [] as $bucket) {
                    $region = $config->region;
                    if ($provider !== 'cloudflare') {
                        $location = $client->getBucketLocation(['Bucket' => $bucket['Name']]);
                        $region = ($location['LocationConstraint'] ?? '') ?: ($provider === 'aws' ? 'us-east-1' : $config->region);
                        if ($region === 'EU') $region = 'eu-west-1';
                    }
                    $buckets[] = ['name' => $bucket['Name'], 'region' => $region];
                }
            }
        } catch (\Throwable $error) { $this->sendError($error); return; }
        wp_send_json_success(['buckets' => $buckets, 'selectionToken' => $selectionToken]);
    }

    private function certificateRecords(array $certificate, string $domain): array
    {
        $records = [];
        foreach ($certificate['DomainValidationOptions'] ?? [] as $option) {
            if (empty($option['ResourceRecord'])) continue;
            $records[] = ['DomainName' => $option['DomainName'], 'Type' => $option['ResourceRecord']['Type'],
                'Name' => $option['ResourceRecord']['Name'], 'Value' => $option['ResourceRecord']['Value'],
                'ValidationStatus' => $option['ValidationStatus'] ?? 'PENDING_VALIDATION', 'systemGenerated' => false];
        }
        $records[] = ['DomainName' => $domain, 'Type' => 'CNAME', 'Name' => $domain,
            'Value' => $this->appConfig->cloudfrontUrl, 'systemGenerated' => true];
        return $records;
    }

    public function generateSslCertificate(): void
    {
        $this->authorize();
        $lock = null;
        $failure = null;
        $arn = '';
        $records = [];
        try {
            $lock = SMARL_Helper::acquireSyncLock();
            if ($lock === null) throw new InvalidArgumentException('Another sync or configuration request is running. Try again shortly.', 409);
            $this->requireFreshCloudFront();
            $domain = $this->validateDomain($this->input('domain'));
            $custom = $this->appConfig->customUrl;
            $acm = $this->appConfig->getAcmClient();
            $certificate = null;
            if ($custom->domain === $domain && $custom->certificateArn !== '') {
                try {
                    $existing = $acm->describeCertificate(['CertificateArn' => $custom->certificateArn])['Certificate'];
                    if (!in_array($existing['Status'] ?? '', ['FAILED', 'REVOKED', 'EXPIRED', 'VALIDATION_TIMED_OUT'], true)) {
                        $arn = $custom->certificateArn;
                        $certificate = $existing;
                    }
                } catch (AwsException $error) {
                    if ($error->getAwsErrorCode() !== 'ResourceNotFoundException') throw $error;
                }
            }
            if ($arn === '') $arn = $acm->requestCertificate(['DomainName' => $domain, 'ValidationMethod' => 'DNS', 'IdempotencyToken' => bin2hex(random_bytes(16))])['CertificateArn'];
            $custom->domain = $domain;
            $custom->certificateArn = $arn;
            // Save immediately so asynchronous ACM record creation never loses the certificate ARN.
            $custom->save();
            $certificate ??= $acm->describeCertificate(['CertificateArn' => $arn])['Certificate'];
            $records = $this->certificateRecords($certificate, $domain);
            $custom->records = $records;
            $custom->save();
        } catch (\Throwable $error) { $failure = $error; }
        finally { if ($lock !== null) SMARL_Helper::releaseSyncLock($lock); }
        if ($failure !== null) { $this->sendError($failure); return; }
        wp_send_json_success(['message' => __('Certificate requested. DNS records may take a moment to become available.', 'smartsync-lite-media-offloader-and-assets-cdn'), 'certificateArn' => $arn, 'records' => $records]);
    }

    private function domainMatchesCertificate(array $certificate, string $domain): bool
    {
        foreach (array_merge([$certificate['DomainName'] ?? ''], $certificate['SubjectAlternativeNames'] ?? []) as $name) {
            $name = strtolower($name);
            if ($name === $domain) return true;
            if (str_starts_with($name, '*.') && substr($domain, strpos($domain, '.') + 1) === substr($name, 2)) return true;
        }
        return false;
    }

    private function distributionDnsVerified(string $domain): bool
    {
        $target = SMARL_Helper::checkCNAME($domain);
        return is_string($target) && strtolower(rtrim($target, '.')) === strtolower(rtrim($this->appConfig->cloudfrontUrl, '.'));
    }

    public function checkDnsRecord(): void
    {
        $this->authorize();
        $lock = null;
        $failure = null;
        try {
            $lock = SMARL_Helper::acquireSyncLock();
            if ($lock === null) throw new InvalidArgumentException('Another sync or configuration request is running. Try again shortly.', 409);
            $this->requireFreshCloudFront();
            $arn = $this->input('certificateArn');
            $custom = $this->appConfig->customUrl;
            if ($arn === '' || $custom->certificateArn === '' || !hash_equals($custom->certificateArn, $arn)) throw new InvalidArgumentException('Select the certificate requested for this custom domain.');
            $domain = $this->validateDomain($custom->domain);
            $certificate = $this->appConfig->getAcmClient()->describeCertificate(['CertificateArn' => $arn])['Certificate'];
            $records = $this->certificateRecords($certificate, $domain);
            $dnsVerified = $this->distributionDnsVerified($domain);
            $verified = ($certificate['Status'] ?? '') === 'ISSUED' && $this->domainMatchesCertificate($certificate, $domain) && $dnsVerified;
            $records[count($records) - 1]['ValidationStatus'] = $dnsVerified ? 'SUCCESS' : 'PENDING_VALIDATION';
            $custom->records = $records;
            $custom->save();
        } catch (\Throwable $error) { $failure = $error; }
        finally { if ($lock !== null) SMARL_Helper::releaseSyncLock($lock); }
        if ($failure !== null) { $this->sendError($failure); return; }
        wp_send_json_success(['verified' => $verified, 'records' => $records]);
    }

    public function updateCloudFrontAlternateDomain(): void
    {
        $this->authorize();
        $lock = null;
        $failure = null;
        try {
            $lock = SMARL_Helper::acquireSyncLock();
            if ($lock === null) throw new InvalidArgumentException('Another sync or configuration request is running. Try again shortly.', 409);
            $this->requireFreshCloudFront();
            $custom = $this->appConfig->customUrl;
            $domain = $this->validateDomain($custom->domain);
            if ($custom->certificateArn === '') throw new InvalidArgumentException('Request and validate a certificate before attaching the domain.');
            $certificate = $this->appConfig->getAcmClient()->describeCertificate(['CertificateArn' => $custom->certificateArn])['Certificate'];
            if (($certificate['Status'] ?? '') !== 'ISSUED' || !$this->domainMatchesCertificate($certificate, $domain) || !$this->distributionDnsVerified($domain)) throw new InvalidArgumentException('Validate the certificate and all DNS records before attaching the domain.');
            $client = $this->appConfig->getCloudfrontClient();
            $result = $client->getDistributionConfig(['Id' => $this->appConfig->distributionId]);
            $config = $result['DistributionConfig'];
            $aliases = array_values(array_unique(array_merge($config['Aliases']['Items'] ?? [], [$domain])));
            foreach ($aliases as $alias) {
                if (!$this->domainMatchesCertificate($certificate, $alias)) throw new InvalidArgumentException('The certificate must cover every existing distribution alias before it can replace the current certificate.');
            }
            $config['Aliases'] = ['Quantity' => count($aliases), 'Items' => $aliases];
            $config['ViewerCertificate'] = ['ACMCertificateArn' => $custom->certificateArn, 'SSLSupportMethod' => 'sni-only', 'MinimumProtocolVersion' => 'TLSv1.2_2021'];
            $client->updateDistribution(['Id' => $this->appConfig->distributionId, 'IfMatch' => $result['ETag'], 'DistributionConfig' => $config]);
            $this->appConfig->url = $domain;
            $this->appConfig->save();
        } catch (\Throwable $error) { $failure = $error; }
        finally { if ($lock !== null) SMARL_Helper::releaseSyncLock($lock); }
        if ($failure !== null) { $this->sendError($failure); return; }
        wp_send_json_success(__('CloudFront custom domain and certificate updated successfully.', 'smartsync-lite-media-offloader-and-assets-cdn'));
    }
}
