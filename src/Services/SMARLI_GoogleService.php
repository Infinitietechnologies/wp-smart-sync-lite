<?php
namespace SmartSyncS3\Services;
if (!defined('ABSPATH')) exit;

use Google\Cloud\Storage\StorageClient;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use GuzzleHttp\Client;
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Exceptions\SMARL_ProviderException;
use RuntimeException;

class SMARLI_GoogleService
{
    public StorageClient $storage;

    /** Opaque reference binds an explicit reveal request to the credential shown on the current page. */
    public static function credentialReference(string $path): string
    {
        return hash_hmac('sha256', 'smartsync-google-preview:' . $path, wp_salt('auth'));
    }

    public static function redactedPreview(array $credentials): array
    {
        $public = ['type', 'project_id', 'client_email', 'auth_uri', 'token_uri', 'auth_provider_x509_cert_url', 'client_x509_cert_url', 'universe_domain'];
        $preview = [];
        foreach ($credentials as $key => $value) $preview[$key] = in_array($key, $public, true) && is_string($value)
            ? $value : __('[Hidden]', 'smartsync-lite-media-offloader-and-assets-cdn');
        return $preview;
    }

    public function __construct($path)
    {
        $credentials = self::getServiceJSON($path);
        if ($credentials === false) throw new RuntimeException('Upload a valid Google service account JSON file.');
        // Explicit credentials avoid changing authentication for other Google plugins.
        $fetcher = new ServiceAccountCredentials([StorageClient::FULL_CONTROL_SCOPE, 'https://www.googleapis.com/auth/iam'], $credentials);
        // OAuth token exchange and Storage API calls must use the same trusted CA configuration.
        $httpHandler = HttpHandlerFactory::build(new Client(SMARL_Helper::cloudHttpOptions()));
        $this->storage = new StorageClient([
            'credentialsFetcher' => $fetcher,
            'projectId' => $credentials['project_id'],
            'requestTimeout' => 120,
            'httpHandler' => $httpHandler,
            'authHttpHandler' => $httpHandler,
        ]);
    }

    public function getBuckets(): array
    {
        $buckets = [];
        foreach ($this->storage->buckets() as $bucket) {
            $info = $bucket->info();
            $buckets[] = ['name' => $bucket->name(), 'region' => (string) ($info['location'] ?? 'US')];
        }
        return $buckets;
    }

    public static function getCredentialsDirectory(): string
    {
        $base = defined('SMARTSYNC_CREDENTIALS_DIR') ? SMARTSYNC_CREDENTIALS_DIR : WP_CONTENT_DIR . '/smartsync-private';
        if (!is_string($base) || $base === '' || str_contains($base, "\0") || str_contains($base, '://')
            || (!str_starts_with($base, '/') && !preg_match('/^[a-z]:[\\\\\/]/i', $base))) throw new RuntimeException('An absolute private credentials directory is required.');
        $base = rtrim($base, '/\\');
        $directory = $base . DIRECTORY_SEPARATOR . 'site-' . substr(hash('sha256', ABSPATH), 0, 20);
        if (is_link($base) || is_link($directory)) throw new RuntimeException('The private credentials directory must not be a symbolic link.');
        return $directory;
    }

    private static function encryptionKey(): string
    {
        return hash('sha256', wp_salt('auth'), true);
    }

    private static function additionalAuthenticatedData(): string
    {
        return 'smartsync-credentials-v1:' . hash('sha256', ABSPATH);
    }

    private static function storeCredentials(array $credentials): string
    {
        $directory = self::getCredentialsDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Unable to create private credentials storage.');
        @chmod($directory, 0700);
        $deny = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
        if (file_put_contents($directory . DIRECTORY_SEPARATOR . '.htaccess', $deny, LOCK_EX) === false
            || file_put_contents($directory . DIRECTORY_SEPARATOR . 'index.php', "<?php http_response_code(403); exit;\n", LOCK_EX) === false) throw new RuntimeException('Unable to protect private credentials storage.');
        $path = $directory . DIRECTORY_SEPARATOR . 'smarl-' . get_current_user_id() . '-' . bin2hex(random_bytes(16)) . '.json';
        $stream = fopen($path, 'x');
        if ($stream === false) throw new RuntimeException('Unable to save Google credentials.');
        @chmod($path, 0600);
        $raw = json_encode($credentials, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $iv = random_bytes(12);
        $ciphertext = openssl_encrypt($raw, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag, self::additionalAuthenticatedData(), 16);
        if ($ciphertext === false) { fclose($stream); @unlink($path); throw new RuntimeException('Unable to encrypt Google credentials.'); }
        $json = json_encode(['smartsync_encrypted' => 1, 'iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'ciphertext' => base64_encode($ciphertext)], JSON_THROW_ON_ERROR);
        try {
            if (fwrite($stream, $json) !== strlen($json)) throw new RuntimeException('Unable to save Google credentials.');
        } catch (\Throwable $error) { fclose($stream); @unlink($path); throw $error; }
        finally { if (is_resource($stream)) fclose($stream); }
        return $path;
    }

    /** Reserve uploads used by discovery so overlapping requests retain their exact credentials. */
    public static function moveFileTemp(bool $reserveSelection = false): array
    {
        if (empty($_FILES['service_json']['name'])) return [];
        $file = $_FILES['service_json'];
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])
            || !is_string($file['name'] ?? null) || !in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['json', 'p12'], true)
            || (int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 1048576
            || filesize($file['tmp_name']) > 1048576) throw new SMARL_ProviderException(__('Upload one service-account .json or .p12 file no larger than 1 MB.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        $credentials = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'p12'
            ? self::readP12Credentials($file['tmp_name']) : self::getServiceJSON($file['tmp_name']);
        if ($credentials === false) throw new RuntimeException('Invalid Google service account JSON.');
        $lock = SMARL_Helper::acquireSyncLock();
        if ($lock === null) throw new RuntimeException('Another cloud configuration request is running. Try the upload again shortly.');
        try {
            global $wpdb;
            $previous = get_transient('smarl_google_credentials_' . get_current_user_id());
            $settings = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'smarl_settings')));
            $active = is_array($settings) && is_string($settings['googleServicePath'] ?? null) ? $settings['googleServicePath'] : '';
            $selections = get_transient('smarl_google_selections_' . get_current_user_id());
            $selections = is_array($selections) ? $selections : [];
            foreach ($selections as $token => $selection) {
                if (!is_array($selection) || !is_string($selection['path'] ?? null) || (int) ($selection['expires'] ?? 0) <= time()) {
                    unset($selections[$token]);
                    if (is_array($selection) && is_string($selection['path'] ?? null) && $selection['path'] !== $active) self::deleteCredentialFile($selection['path']);
                }
            }
            $path = self::storeCredentials($credentials);
            $token = $reserveSelection ? bin2hex(random_bytes(16)) : '';
            if ($token !== '') $selections[$token] = ['path' => $path, 'expires' => time() + 3600];
            set_transient('smarl_google_selections_' . get_current_user_id(), $selections, 3600);
            set_transient('smarl_google_credentials_' . get_current_user_id(), $path, 3600);
            $previousReal = is_string($previous) ? realpath($previous) : false;
            $activeReal = $active !== '' ? realpath($active) : false;
            $pinned = array_column($selections, 'path');
            if (is_string($previous) && $previous !== $path && !in_array($previous, $pinned, true) && ($previousReal === false || $previousReal !== $activeReal)) self::deleteCredentialFile($previous);
            return ['path' => $path, 'selectionToken' => $token];
        } finally { SMARL_Helper::releaseSyncLock($lock); }
    }

    /** Convert a P12 key into the same encrypted credential representation used for JSON uploads. */
    private static function readP12Credentials(string $path): array
    {
        if (!function_exists('openssl_pkcs12_read')) throw new SMARL_ProviderException(__('P12 keys require the PHP OpenSSL extension. Enable it or upload a JSON key.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        $email = is_string($_POST['google_p12_email'] ?? null) ? trim(wp_unslash($_POST['google_p12_email'])) : '';
        $project = is_string($_POST['google_p12_project'] ?? null) ? trim(wp_unslash($_POST['google_p12_project'])) : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new SMARL_ProviderException(__('Enter the service-account email for this P12 key.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        if (!preg_match('/^[a-z][a-z0-9:._-]{4,126}[a-z0-9]$/D', $project)) throw new SMARL_ProviderException(__('Enter the Google Cloud project ID, not its display name or project number.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        $password = $_POST['google_p12_password'] ?? '';
        if (!is_string($password) || strlen($password) > 2048) throw new SMARL_ProviderException(__('Enter a valid P12 key password.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        $password = wp_unslash($password);
        $raw = file_get_contents($path);
        if ($raw === false) throw new SMARL_ProviderException(__('The P12 key could not be read. Choose the file again.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        self::drainOpenSslErrors();
        $parts = [];
        $read = @openssl_pkcs12_read($raw, $parts, $password === '' ? 'notasecret' : $password);
        $unsupported = str_contains(strtolower(self::drainOpenSslErrors()), 'unsupported');
        if (!$read) throw new SMARL_ProviderException($unsupported
            ? __('This P12 uses encryption unsupported by this PHP OpenSSL installation. Download a JSON key from Google Cloud, or re-export the P12 using modern encryption.', 'smartsync-lite-media-offloader-and-assets-cdn')
            : __('The P12 key could not be opened. Check its password and choose the original service-account key file. A blank password uses Google\'s default, notasecret.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        $key = isset($parts['pkey']) && is_string($parts['pkey']) ? @openssl_pkey_get_private($parts['pkey']) : false;
        $details = $key !== false ? openssl_pkey_get_details($key) : false;
        // PKCS12 decoding already exports PKCS8 PEM; re-exporting unnecessarily requires openssl.cnf on Windows.
        $private = is_string($parts['pkey'] ?? null) ? $parts['pkey'] : '';
        if ($key === false || !is_array($details) || $details['type'] !== OPENSSL_KEYTYPE_RSA
            || !isset($parts['cert']) || !is_string($parts['cert']) || !@openssl_x509_check_private_key($parts['cert'], $key)
            || !str_contains($private, '-----BEGIN PRIVATE KEY-----')) throw new SMARL_ProviderException(__('This P12 must contain a matching RSA private key and service-account certificate.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        return ['type' => 'service_account', 'project_id' => $project, 'client_email' => $email, 'private_key' => $private, 'token_uri' => 'https://oauth2.googleapis.com/token'];
    }

    private static function drainOpenSslErrors(): string
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) $errors[] = $error;
        return implode(' ', $errors);
    }

    public static function getServiceJSON($path): array|false
    {
        if (!is_string($path) || !is_file($path) || !is_readable($path) || filesize($path) > 2097152) return false;
        $json = file_get_contents($path);
        $data = $json === false ? null : json_decode($json, true);
        if (is_array($data) && isset($data['smartsync_encrypted'])) {
            if ($data['smartsync_encrypted'] !== 1 || !is_string($data['iv'] ?? null) || !is_string($data['tag'] ?? null) || !is_string($data['ciphertext'] ?? null)) return false;
            $iv = base64_decode($data['iv'], true); $tag = base64_decode($data['tag'], true); $ciphertext = base64_decode($data['ciphertext'], true);
            if ($iv === false || strlen($iv) !== 12 || $tag === false || strlen($tag) !== 16 || $ciphertext === false) return false;
            $raw = openssl_decrypt($ciphertext, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag, self::additionalAuthenticatedData());
            $data = $raw === false ? null : json_decode($raw, true);
        }
        if (!is_array($data) || ($data['type'] ?? '') !== 'service_account'
            || !is_string($data['project_id'] ?? null) || $data['project_id'] === ''
            || !filter_var($data['client_email'] ?? '', FILTER_VALIDATE_EMAIL)
            || !is_string($data['private_key'] ?? null) || !str_contains($data['private_key'], '-----BEGIN PRIVATE KEY-----')
            || !in_array($data['token_uri'] ?? '', ['https://oauth2.googleapis.com/token', 'https://accounts.google.com/o/oauth2/token'], true)) return false;
        return array_intersect_key($data, array_flip(['type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id', 'auth_uri', 'token_uri', 'auth_provider_x509_cert_url', 'client_x509_cert_url', 'universe_domain']));
    }

    public static function getProjectId($path): string|false
    {
        $data = self::getServiceJSON($path);
        return $data === false ? false : $data['project_id'];
    }

    public static function getTempPath(string $selectionToken = ''): string
    {
        if ($selectionToken !== '') {
            if (!preg_match('/^[a-f0-9]{32}$/', $selectionToken)) return '';
            $selections = get_transient('smarl_google_selections_' . get_current_user_id());
            $selection = is_array($selections) ? ($selections[$selectionToken] ?? null) : null;
            if (!is_array($selection) || (int) ($selection['expires'] ?? 0) <= time()) return '';
            $path = $selection['path'] ?? '';
        } else $path = get_transient('smarl_google_credentials_' . get_current_user_id());
        if (is_string($path) && self::getServiceJSON($path) !== false) return $path;
        return '';
    }

    public static function hasPendingSelections(): bool
    {
        $selections = get_transient('smarl_google_selections_' . get_current_user_id());
        if (!is_array($selections)) return false;
        foreach ($selections as $selection) {
            if (is_array($selection) && is_string($selection['path'] ?? null) && (int) ($selection['expires'] ?? 0) > time()) return true;
        }
        return false;
    }

    public static function credentialsNeedMigration(string $path): bool
    {
        if ($path === '' || !is_file($path)) return false;
        $directory = str_replace('\\', '/', self::getCredentialsDirectory()) . '/';
        $real = str_replace('\\', '/', realpath($path) ?: $path);
        if (strncasecmp($real, $directory, strlen($directory)) !== 0) return true;
        $envelope = json_decode((string) file_get_contents($path), true);
        return !is_array($envelope) || ($envelope['smartsync_encrypted'] ?? null) !== 1;
    }

    /** Retain the source until its new location has been durably saved by the caller. */
    public static function migrateCredentials(string $path, bool $removeOriginal = true): string
    {
        if (!self::credentialsNeedMigration($path)) return $path;
        $credentials = self::getServiceJSON($path);
        if ($credentials === false) throw new RuntimeException('Stored Google credentials are invalid. Upload a service account JSON file again.');
        $privatePath = self::storeCredentials($credentials);
        if ($removeOriginal) self::deleteLegacyCredentialFile($path);
        return $privatePath;
    }

    public static function deleteLegacyCredentialFile(string $path): void
    {
        if (!is_file($path) || is_link($path) || self::getServiceJSON($path) === false) return;
        $directory = str_replace('\\', '/', self::getCredentialsDirectory()) . '/';
        $real = str_replace('\\', '/', realpath($path) ?: $path);
        $uploads = wp_upload_dir();
        $uploadRoot = rtrim(str_replace('\\', '/', realpath($uploads['basedir']) ?: $uploads['basedir']), '/') . '/';
        $oldTemporaryRoot = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/smartsync-credentials-' . substr(hash('sha256', ABSPATH), 0, 20) . '/';
        if ((strncasecmp($real, $uploadRoot, strlen($uploadRoot)) === 0 || strncasecmp($real, $oldTemporaryRoot, strlen($oldTemporaryRoot)) === 0 || strncasecmp($real, $directory, strlen($directory)) === 0)
            && strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'json') @unlink($path);
    }

    public static function deleteCredentialFile(string $path): void
    {
        $root = realpath(self::getCredentialsDirectory());
        $file = realpath($path);
        if ($root !== false && $file !== false && dirname($file) === $root && !is_link($path)
            && preg_match('/^smarl-\d+-[a-f0-9]{32}\.json$/', basename($path))) @unlink($path);
    }

    public static function cleanupCredentials(): void
    {
        $directory = self::getCredentialsDirectory();
        if (!is_dir($directory)) return;
        foreach (glob($directory . DIRECTORY_SEPARATOR . 'smarl-*.json') ?: [] as $path) self::deleteCredentialFile($path);
        foreach (['.htaccess', 'index.php'] as $file) {
            $path = $directory . DIRECTORY_SEPARATOR . $file;
            if (is_file($path) && !is_link($path)) @unlink($path);
        }
        @rmdir($directory);
    }

    public function createPublicBucket($bucketName, string $region = 'US'): bool
    {
        $bucket = $this->storage->createBucket($bucketName, ['location' => $region, 'iamConfiguration' => ['uniformBucketLevelAccess' => ['enabled' => true]]]);
        $iam = $bucket->iam();
        $policy = $iam->policy(['requestedPolicyVersion' => 3]);
        $policy['bindings'][] = ['role' => 'roles/storage.objectViewer', 'members' => ['allUsers']];
        $iam->setPolicy($policy);
        return true;
    }
}
