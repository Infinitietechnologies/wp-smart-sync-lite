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
        ?>
        <div class="wrap smartsync-wrap">
            <h1 class="page-title"><?php esc_html_e('Set Up a Custom CloudFront URL', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h1>
            <?php if ($config->serviceProvider !== 'aws' || !$config->useCloudFront): ?>
                <div class="notice notice-error">
                    <p><?php esc_html_e('Configure AWS with CloudFront before setting up a custom domain.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p>
                    <p><a href="<?php echo esc_url(admin_url('admin.php?page=smarl-configuration-wizard')); ?>"><?php esc_html_e('Open Cloud Configuration', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></a></p>
                </div>
            <?php else: ?>
                <script type="application/json" id="initial_certificates"><?php
                    echo wp_json_encode($config->customUrl->toArray(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                ?></script>
                <div class="wizard-progress" aria-hidden="true">
                    <div class="wizard-progress-bar"><div class="wizard-progress-bar-inner" style="width: 0%;"></div></div>
                    <div class="wizard-progress-steps">
                        <div class="wizard-progress-step active" id="step-domain">
                            <div class="wizard-progress-step-number">1</div>
                            <div class="wizard-progress-step-label"><?php esc_html_e('Enter Domain', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></div>
                        </div>
                        <div class="wizard-progress-step" id="step-certificate">
                            <div class="wizard-progress-step-number">2</div>
                            <div class="wizard-progress-step-label"><?php esc_html_e('Verify Certificate', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></div>
                        </div>
                        <div class="wizard-progress-step" id="step-attach">
                            <div class="wizard-progress-step-number">3</div>
                            <div class="wizard-progress-step-label"><?php esc_html_e('Attach to CloudFront', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></div>
                        </div>
                    </div>
                </div>
                <div id="custom-domain-container">
                    <label for="custom-domain"><?php esc_html_e('Custom domain', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label>
                    <input type="text" id="custom-domain" class="regular-text" autocomplete="off" spellcheck="false" aria-describedby="custom-domain-description" value="<?php echo esc_attr($config->customUrl->domain); ?>">
                    <p id="custom-domain-description" class="smartsync-input-description"><?php esc_html_e('Enter a domain you own, such as cdn.example.com, without a scheme or path. Add the certificate validation records and point its CNAME to CloudFront.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p>
                    <button type="button" id="generate-certificate" class="primary-btn"><?php esc_html_e('Generate Certificate', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
                    <button type="button" id="cloudfront-attachment-button" class="primary-btn" disabled><?php esc_html_e('Attach to CloudFront', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
                    <button type="button" id="check-dns-records" class="secondary-btn" hidden><?php esc_html_e('Retry DNS Check', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
                    <p id="domain-message" role="status" aria-live="polite"></p>
                    <div id="records"></div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
