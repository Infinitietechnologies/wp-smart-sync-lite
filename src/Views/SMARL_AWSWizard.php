<?php
namespace SmartSyncS3\Views;

use SmartSyncS3\Models\SMARL_CloudConfig;
use SmartSyncS3\Services\SMARLI_GoogleService;

if (!defined('ABSPATH')) exit;

class SMARL_AWSWizard
{
    public function __construct(private SMARL_CloudConfig $config) {}

    public function loadView(): void
    {
        add_action('admin_menu', [$this, 'addAdminSubMenu']);
    }

    public function addAdminSubMenu(): void
    {
        add_submenu_page(
            parent_slug: 'smarl-smart-sync',
            page_title: esc_html__('Cloud Configuration Wizard', 'smartsync-lite-media-offloader-and-assets-cdn'),
            menu_title: esc_html__('Cloud Config', 'smartsync-lite-media-offloader-and-assets-cdn'),
            capability: 'manage_options',
            menu_slug: 'smarl-configuration-wizard',
            callback: [$this, 'renderAWSWizardPage']
        );
    }

    public function renderAWSWizardPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage SmartSync settings.', 'smartsync-lite-media-offloader-and-assets-cdn'));
        }
        $cloudConfig = $this->config;
        $providerGuides = SMARL_ProviderGuides::all();
        $selectedProvider = isset($providerGuides[$cloudConfig->serviceProvider]) ? $cloudConfig->serviceProvider : 'aws';
        $sync = get_option('smarl_sync_settings', []);
        $syncRunning = is_array($sync) && ($sync['s3SyncStatus'] ?? '') === 'processing';
        $savedGoogle = [];
        if ($cloudConfig->serviceProvider === 'google' && $cloudConfig->googleServicePath !== '') {
            $credentials = SMARLI_GoogleService::getServiceJSON($cloudConfig->googleServicePath);
            if (is_array($credentials)) $savedGoogle = [
                'preview' => SMARLI_GoogleService::redactedPreview($credentials),
                'reference' => SMARLI_GoogleService::credentialReference($cloudConfig->googleServicePath),
            ];
        }
        $render = require __DIR__ . '/templates/cloud-setup.php';
        $regionPreference = get_option('smarl_aws_discovery_region', '');
        $regionPreference = is_string($regionPreference) && preg_match('/^[a-z]{2}(?:-[a-z]+)+-\d+$/', $regionPreference) ? $regionPreference : '';
        $render($cloudConfig, $providerGuides, $selectedProvider, $syncRunning, $savedGoogle, $regionPreference);
    }
}
