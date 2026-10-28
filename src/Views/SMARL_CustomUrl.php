<?php

namespace SmartSyncS3\Views;
if (!defined('ABSPATH')) exit;
use SmartSyncS3\Models\SMARL_CloudConfig;

class SMARL_CustomUrl
{
    private SMARL_CloudConfig $config;

    public function __construct(SMARL_CloudConfig $config)
    {
        $this->config = $config;
    }

    public function loadView(): void
    {
        add_action('admin_menu', [$this, 'addAdminSubMenu']);
    }

    public function addAdminSubMenu(): void
    {
        add_submenu_page(
            'smarl-smart-sync',
            esc_html__('Custom CloudFront URL', 'smartsync-lite-media-offloader-and-assets-cdn'),
            esc_html__('Custom CloudFront URL', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'manage_options',
            'custom-cloudfront-url',
            [$this, 'renderCustomUrlPage']
        );
    }

    public function renderCustomUrlPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage SmartSync settings.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        }
        $config = $this->config;
        $config->customUrl ??= \SmartSyncS3\Models\SMARL_CustomUrl::load();
        $sync = get_option('smarl_sync_settings', []);
        $syncRunning = is_array($sync) && ($sync['s3SyncStatus'] ?? '') === 'processing';
        $render = require __DIR__ . '/templates/custom-domain.php';
        $render($config, $syncRunning);
    }
}
