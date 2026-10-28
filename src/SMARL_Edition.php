<?php
namespace SmartSyncS3;
if (!defined('ABSPATH')) exit;

/** Lite has no Pro integration clients or provisioning implementations. */
final class SMARL_Edition
{
    public const PRO_URL = 'https://codecanyon.net/item/wp-smartsync-wordpress-plugin-for-media-assets-cdn-cloud-storage/56335604';
    public const PROVIDERS = ['aws', 'digitalocean', 'wasabi', 'bunny'];

    public static function proMessage(): string
    {
        return __('Pro only. This feature is a preview in SmartSync Lite.', 'smartsync-lite-media-offloader-and-assets-cdn');
    }

    public static function requireProvider(string $provider): void
    {
        if (in_array($provider, ['google', 'cloudflare'], true)) throw new \InvalidArgumentException(self::proMessage(), 403);
        if (!in_array($provider, self::PROVIDERS, true)) throw new \InvalidArgumentException(__('Select a supported storage provider.', 'smartsync-lite-media-offloader-and-assets-cdn'));
    }
}
