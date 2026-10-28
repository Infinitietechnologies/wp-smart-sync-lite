<?php

namespace SmartSyncS3\Views;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Models\SMARL_CloudConfig;


class SMARL_SettingsPage
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
        add_menu_page(
            page_title: esc_html__('Cloud Configuration', 'smartsync-lite-media-offloader-and-assets-cdn'),
            menu_title: esc_html__('SmartSync Lite', 'smartsync-lite-media-offloader-and-assets-cdn'),
            capability: 'manage_options',               // Capability
            menu_slug: 'smarl-smart-sync',                // Menu slug
            callback: [$this, 'renderSettingsPage'],     // Callback function
            icon_url: 'dashicons-cloud',              // Icon for the menu
            position: 75                              // Position in the menu
        );
    }

    public function renderSettingsPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage SmartSync settings.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        }
        $guides = SMARL_ProviderGuides::all();
        $providerName = $guides[$this->config->serviceProvider]['name'] ?? $this->config->serviceProvider;
        $render = require __DIR__ . '/templates/settings-home.php';
        $render($this->config, $providerName, $this->config->isConfigured(), SMARL_Helper::checkPluginCompatibility());
    }
}
