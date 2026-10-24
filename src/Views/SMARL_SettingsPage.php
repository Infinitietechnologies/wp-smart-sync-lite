<?php

namespace SmartSyncS3\Views;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
use SmartSyncS3\Constants\Strings;
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
            menu_title: esc_html__('SmartSync Settings', 'smartsync-lite-media-offloader-and-assets-cdn'),
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
        $cloud_enabled_svg = '<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-brand-onedrive"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18.456 10.45a6.45 6.45 0 0 0 -12 -2.151a4.857 4.857 0 0 0 -4.44 5.241a4.856 4.856 0 0 0 5.236 4.444h10.751a3.771 3.771 0 0 0 3.99 -3.54a3.772 3.772 0 0 0 -3.538 -3.992z" /></svg>';
        $cloud_disabled_svg = '<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-cloud-x"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M13 18.004h-6.343c-2.572 -.004 -4.657 -2.011 -4.657 -4.487c0 -2.475 2.085 -4.482 4.657 -4.482c.393 -1.762 1.794 -3.2 3.675 -3.773c1.88 -.572 3.956 -.193 5.444 1c1.488 1.19 2.162 3.007 1.77 4.769h.99c1.37 0 2.556 .8 3.117 1.964" /><path d="M22 22l-5 -5" /><path d="M17 22l5 -5" /></svg>';

        $deleteEnabledSvg = '<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-trash"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" /></svg>';
        $deleteDisabledSvg = '<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-trash-x"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7h16" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" /><path d="M10 12l4 4m0 -4l-4 4" /></svg>';
        $config = $this->config;
        $compatibility = SMARL_Helper::checkPluginCompatibility();
        $notConfigured = __('Not configured', 'smartsync-lite-media-offloader-and-assets-cdn');
        ?>
            <div class="smartsync-wrap">
                <div class="wrap aws-settings">
                    <h1 class="page-title"><?php esc_html_e('Cloud Settings', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h1>
                    <!-- Plugin Compatibility Check -->
                    <?php if (!$compatibility["compatible"]): ?>
                        <div class="notice notice-error">
                            <h2><?php esc_html_e('Errors Detected', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h2>
                            <ul>
                                <?php foreach ($compatibility["error"] as $error): ?>
                                    <li>
                                        <strong><?php echo esc_html($error["error_key"]); ?></strong>: <?php echo esc_html($error["error_message"]); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                        <!-- Navigation Tabs -->
                        <div class="tabs-container" role="tablist" aria-label="<?php esc_attr_e('Cloud settings', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>">
                            <button type="button" id="tab-button-general" class="tab-button active" data-tab="general" role="tab" aria-controls="tab-general" aria-selected="true"><?php esc_html_e('General', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
                            <button type="button" id="tab-button-media" class="tab-button" data-tab="media" role="tab" aria-controls="tab-media" aria-selected="false" tabindex="-1"><?php esc_html_e('Media', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
                            <button type="button" id="tab-button-assets" class="tab-button" data-tab="assets" role="tab" aria-controls="tab-assets" aria-selected="false" tabindex="-1"><?php esc_html_e('Assets', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
                            <div class="tab-indicator"></div>
                        </div>
                        <!-- Tab Content -->
                        <div class="tabs-content">
                            <!-- General Tab -->
                            <div id="tab-general" class="tab-panel active" role="tabpanel" aria-labelledby="tab-button-general">
                                <div class="settings-container">
                                    <!-- AWS Configuration -->
                                    <input type="hidden" value="" id="current-sync-state">
                                    <div class="settings-section">
                                        <div class="section-title">
                                            <span class="dashicons dashicons-cloud"></span>
                                            <h2><?php esc_html_e('Cloud Configuration', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h2>
                                        </div>
                                        <table class="settings-table">
                                            <tr>
                                                <th scope="row"><?php esc_html_e('Provider:', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></th>
                                                <td><?php echo esc_html((($config->serviceProvider == "bunny" ? "BunnyCDN" : $config->serviceProvider)) ?: $notConfigured); ?></td>
                                            </tr>
                                            <tr>
                                                <th scope="row"><?php esc_html_e('Bucket:', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></th>
                                                <td><?php echo esc_html(($config->bucket) ?: $notConfigured); ?></td>
                                            </tr>
                                            <tr>
                                                <th scope="row"><?php esc_html_e('Region:', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></th>
                                                <td><?php echo esc_html(($config->region) ?: $notConfigured); ?></td>
                                            </tr>
                                            <tr>
                                                <th scope="row"><?php esc_html_e('URL:', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></th>
                                                <td><?php echo esc_html(($config->url) ?: $notConfigured); ?></td>
                                            </tr>
                                        </table>
                                        <a id="reconfigure-aws" href="<?php echo esc_url(admin_url('admin.php?page=smarl-configuration-wizard')); ?>" class="primary-btn"><?php esc_html_e('Reconfigure Cloud', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></a>
                                        <p class="description mt-2"><?php esc_html_e('Click to re-launch the Cloud Configuration Wizard.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p>
                                    </div>
                                    <!-- S3 Analytics -->
                                    <div class="settings-section">
                                        <div class="section-title">
                                            <span class="dashicons dashicons-chart-bar"></span>
                                            <h2><?php esc_html_e('Cloud Analytics', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h2>
                                        </div>
                                        <ul class="analytics-list">
                                            <li><strong><?php esc_html_e('Total Files:', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></strong> <span id="total-files"><?php esc_html_e('No Data', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></span></li>
                                            <li><strong><?php esc_html_e('Total Storage Used:', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></strong> <span id="total-storage"><?php esc_html_e('No Data', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></span></li>
                                            <li><strong><?php esc_html_e('Monthly Cost:', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></strong> <span id="total-price"><?php esc_html_e('No Data', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></span></li>
                                        </ul>
                                        <button id="fetch-analytics-button" class="primary-btn"><?php esc_html_e('Fetch Analytics', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
                                        <p class="description"><?php esc_html_e('Click to Fetch Analytics from Cloud', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p>
                                    </div>

                                </div>
                                <?php if ($config->serviceProvider === 'aws' && $config->useCloudFront && $config->distributionId !== ''): ?>
                                    <div>
                                        <button id="clear-cloudfront-cache-button" class="primary-btn"><?php esc_html_e('Clear CloudFront Cache', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
                                        <p class="description"><?php esc_html_e('Click to clear the CloudFront cache.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Media Tab -->
                            <div id="tab-media" class="tab-panel" role="tabpanel" aria-labelledby="tab-button-media" hidden>
                                <div class="section-title">
                                    <span class="dashicons dashicons-images-alt2"></span>
                                    <h3><?php esc_html_e('Media Settings', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h3>
                                </div>
                                <div class="mb-15">

                                    <label id="use-cloudfront-media-label" for="use-cloudfront-media"><?php esc_html_e('Use Cloud Storage:', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label>
                                    <?php SMARL_Helper::renderSwitch(id: 'use-cloudfront-media', additional_attr: [
                                        'data-type' => 'media',
                                        'class' => 'use-cloudfront-button',
                                        'aria-labelledby' => 'use-cloudfront-media-label'
                                    ], checked: $config->useCdnForMedia, onSvg: $cloud_enabled_svg, offSvg: $cloud_disabled_svg); ?>
                                    <span class="smartsync-input-description"><?php esc_html_e('Enable to serve media files from your cloud storage provider, improving website performance and reducing server load.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></span>

                                </div>

                                <div class="mb-15">
                                    <label id="auto-delete-media-label" for="auto-delete-media"><?php esc_html_e('Automatically delete media from local after upload?', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label>
                                    <?php SMARL_Helper::renderSwitch(id: 'auto-delete-media', additional_attr: ['aria-labelledby' => 'auto-delete-media-label'], checked: $config->mediaAutoDelete, onSvg: $deleteEnabledSvg, offSvg: $deleteDisabledSvg); ?>
                                    <span class="smartsync-input-description"><?php esc_html_e('Remove local copies after every attachment file uploads successfully and media CDN delivery is enabled.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></span>
                                </div>

                                <div>
                                    <button id="sync-local-to-aws" data-type="media" data-direction="upload" class="primary-btn sync-button"><?php esc_html_e('Sync Local to Cloud Media', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
                                    <button id="sync-aws-to-local" data-type="media" data-direction="download" class="primary-btn sync-button"><?php esc_html_e('Sync Cloud to Local', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
                                </div>
                            </div>

                            <!-- Assets Tab -->
                            <div id="tab-assets" class="tab-panel" role="tabpanel" aria-labelledby="tab-button-assets" hidden>
                                <div class="section-title">
                                    <span class="dashicons dashicons-admin-appearance"></span>
                                    <h3><?php esc_html_e('Assets Settings', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h3>
                                </div>
                                <label id="use-cloudfront-asset-label" for="use-cloudfront-asset"><?php esc_html_e('Use Cloud Storage:', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label>
                                <div class="mb-15">
                                    <?php SMARL_Helper::renderSwitch(id: 'use-cloudfront-asset', additional_attr: [
                                        'data-type' => 'assets',
                                        'class' => 'use-cloudfront-button',
                                        'aria-labelledby' => 'use-cloudfront-asset-label'
                                    ], checked: $config->useCdnForAssets, onSvg: $cloud_enabled_svg, offSvg: $cloud_disabled_svg); ?>
                                    <span class="smartsync-input-description"><?php esc_html_e('Enable to serve static assets (JS, CSS, images) from your cloud storage provider, improving page load times.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></span>
                                </div>
                                <div>
                                    <button id="sync-local-to-aws-plugin" data-type="assets" data-direction="upload" class="primary-btn sync-button"><?php esc_html_e('Sync Local to Cloud Assets', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
                                </div>
                            </div>
                        </div>
                        <!-- Sync Logs -->
                        <div class="settings-section mt-5">
                            <div class="d-flex justify-content-space-between align-center">
                                <div class="section-title">
                                    <span class="dashicons dashicons-list-view"></span>
                                    <h2><?php esc_html_e('Logs', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h2>
                                </div>
                                <div>
                                    <button class="stop-button" id="stop-all-processes">
                                        <?php esc_html_e('Stop all running processes', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>
                                    </button>
                                </div>
                            </div>
                            <div class="smartsync-contextual-description">
                                <p><?php esc_html_e('View the status and progress of your sync operations. This log shows real-time updates as files are transferred between your server and cloud storage.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p>
                            </div>
                            <div id="sync-log-container" class="sync-log" role="log" aria-live="polite" aria-relevant="additions">
                                <p id="sync-log-placeholder">
                                    <?php esc_html_e('No logs available. Start a sync operation to see logs here.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>
                                </p>
                            </div>
                        </div>
                </div>
            </div>


        <?php
    }


}
