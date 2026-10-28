<?php
namespace SmartSyncS3\Services;
if (!defined('ABSPATH')) exit;
use RuntimeException;

/** Local uninstall cleanup only; no authentication, key decryption or storage client. */
final class SMARL_LegacyCredentials
{
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
}
