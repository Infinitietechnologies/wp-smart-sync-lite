<?php
namespace SmartSyncS3\Services;
if (!defined('ABSPATH')) exit;

use Google\Cloud\Storage\StorageClient;
use Google\Auth\Credentials\ServiceAccountCredentials;
use SmartSyncS3\Helpers\SMARL_Helper;
use RuntimeException;

class SMARLI_GoogleService
{
    public StorageClient $storage;

    public function __construct($path)
    {
        $credentials = self::getServiceJSON($path);
        if ($credentials === false) throw new RuntimeException('Upload a valid Google service account JSON file.');
        // Explicit credentials avoid changing authentication for other Google plugins.
        $fetcher = new ServiceAccountCredentials([StorageClient::FULL_CONTROL_SCOPE, 'https://www.googleapis.com/auth/iam'], $credentials);
        $this->storage = new StorageClient(['credentialsFetcher' => $fetcher, 'projectId' => $credentials['project_id'], 'requestTimeout' => 120]);
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

    public static function moveFileTemp(): void
    {
        if (empty($_FILES['service_json']['name'])) return;
        $file = $_FILES['service_json'];
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])
            || strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'json'
            || (int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 1048576) throw new RuntimeException('Upload a service account JSON file smaller than 1 MB.');
        $credentials = self::getServiceJSON($file['tmp_name']);
        if ($credentials === false) throw new RuntimeException('Invalid Google service account JSON.');
        $lock = SMARL_Helper::acquireSyncLock();
        if ($lock === null) throw new RuntimeException('Another cloud configuration request is running. Try the upload again shortly.');
        try {
            global $wpdb;
            $previous = get_transient('smarl_google_credentials_' . get_current_user_id());
            $path = self::storeCredentials($credentials);
            set_transient('smarl_google_credentials_' . get_current_user_id(), $path, 3600);
            $settings = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'smarl_settings')));
            $active = is_array($settings) && is_string($settings['googleServicePath'] ?? null) ? $settings['googleServicePath'] : '';
            $previousReal = is_string($previous) ? realpath($previous) : false;
            $activeReal = $active !== '' ? realpath($active) : false;
            if (is_string($previous) && $previous !== $path && ($previousReal === false || $previousReal !== $activeReal)) self::deleteCredentialFile($previous);
        } finally { SMARL_Helper::releaseSyncLock($lock); }
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

    public static function getTempPath(): string
    {
        $path = get_transient('smarl_google_credentials_' . get_current_user_id());
        if (is_string($path) && self::getServiceJSON($path) !== false) return $path;
        return '';
    }

    /** Migrate the older web-accessible upload only after validating its contents. */
    public static function migrateCredentials(string $path): string
    {
        if ($path === '' || !is_file($path)) return $path;
        $directory = str_replace('\\', '/', self::getCredentialsDirectory()) . '/';
        $real = str_replace('\\', '/', realpath($path) ?: $path);
        $envelope = json_decode((string) file_get_contents($path), true);
        if (strncasecmp($real, $directory, strlen($directory)) === 0 && is_array($envelope) && ($envelope['smartsync_encrypted'] ?? null) === 1) return $path;
        $credentials = self::getServiceJSON($path);
        if ($credentials === false) throw new RuntimeException('Stored Google credentials are invalid. Upload a service account JSON file again.');
        $privatePath = self::storeCredentials($credentials);
        $uploads = wp_upload_dir();
        $uploadRoot = rtrim(str_replace('\\', '/', realpath($uploads['basedir']) ?: $uploads['basedir']), '/') . '/';
        $oldTemporaryRoot = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/smartsync-credentials-' . substr(hash('sha256', ABSPATH), 0, 20) . '/';
        if ((strncasecmp($real, $uploadRoot, strlen($uploadRoot)) === 0 || strncasecmp($real, $oldTemporaryRoot, strlen($oldTemporaryRoot)) === 0 || strncasecmp($real, $directory, strlen($directory)) === 0)
            && strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'json') @unlink($path);
        return $privatePath;
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
        $policy = $iam->policy();
        $policy['bindings'][] = ['role' => 'roles/storage.objectViewer', 'members' => ['allUsers']];
        $iam->setPolicy($policy);
        return true;
    }
}
