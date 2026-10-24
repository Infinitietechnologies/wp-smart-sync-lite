<?php
namespace SmartSyncS3\Views;

use SmartSyncS3\Models\SMARL_CloudConfig;
use function add_submenu_page;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class SMARL_AWSWizard
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

    /**
     * Adds a submenu to the WordPress admin menu.
     *
     * @return void
     */
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
        ?>
        <div class="wrap smartsync-wrap ">
            <h1><?php esc_html_e('Cloud Configuration Wizard', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h1>
            <p id="wizard-message" role="status" aria-live="polite" hidden></p>
            <!-- Stepper Navigation -->
            <div id="wizard-stepper">
                <ul class="stepper">
                    <li id="step-1-indicator" class="step active" aria-current="step">
                        <div class="step-icon">
                            <span>1</span>
                        </div>
                        <div class="step-label"><?php esc_html_e('IAM Credentials', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></div>
                    </li>
                    <li id="step-2-indicator" class="step">
                        <div class="step-icon">
                            <span>2</span>
                        </div>
                        <div class="step-label"><?php esc_html_e('Select/Create Bucket', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></div>
                    </li>
                    <li id="step-3-indicator" class="step">
                        <div class="step-icon">
                            <span>3</span>
                        </div>
                        <div class="step-label"><?php esc_html_e('Configure CloudFront', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></div>
                    </li>
                    <li id="step-final-indicator" class="step">
                        <div class="step-icon">
                            <span>✔</span>
                        </div>
                        <div class="step-final"><?php esc_html_e('Complete', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></div>
                    </li>
                </ul>
            </div>

            <div id="aws-wizard">
                <!-- Step 1: IAM Credentials -->
                <div id="wizard-step-1" class="wizard-step">
                    <h2><?php esc_html_e('Step 1: Enter IAM Credentials', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h2>
                    <p><?php esc_html_e('Provide your Cloud IAM Key and Secret.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p>

                    <div>
                        <label for="service_provider"><?php esc_html_e('Provider', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label>
                        <select id="service_provider">
                            <option value="aws" <?php echo selected($cloudConfig->serviceProvider, 'aws', false); ?>>
                                <?php esc_html_e('Amazon AWS', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>
                            </option>
                            <option value="digitalocean" <?php echo selected($cloudConfig->serviceProvider, 'digitalocean', false); ?>>
                                <?php esc_html_e('Digital Ocean', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>
                            </option>
                            <option value="wasabi" <?php echo selected($cloudConfig->serviceProvider, 'wasabi', false); ?>>
                                <?php esc_html_e('Wasabi', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>
                            </option>
                            <option value="google" <?php echo selected($cloudConfig->serviceProvider, 'google', false); ?>>
                                <?php esc_html_e('Google Cloud', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>
                            </option>
                            <option value="cloudflare" <?php echo selected($cloudConfig->serviceProvider, 'cloudflare', false); ?>>
                                <?php esc_html_e('Cloudflare', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>
                            </option>
                            <option value="bunny" <?php echo selected($cloudConfig->serviceProvider, 'bunny', false); ?>>
                                <?php esc_html_e('Bunny CDN', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>
                            </option>
                        </select>
                        <br><br>
                    </div>
                    <div id="aws-sdk-inputs" class="config-inputs aws-sdk-inputs d-none">
                        <label for="aws_iam_key"><?php esc_html_e('IAM Key:', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label>
                        <input type="text" id="aws_iam_key" value="<?php echo esc_attr($cloudConfig->serviceProvider === 'bunny' || $cloudConfig->serviceProvider === 'google' ? '' : $cloudConfig->iamKey); ?>" placeholder="<?php esc_attr_e('Enter your IAM Access Key ID', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>" class="regular-text" autocomplete="off" spellcheck="false">
                        <span class="smartsync-input-description"><?php esc_html_e('Your IAM Access Key ID from your cloud provider. This is used to authenticate API requests.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></span>
                        <br><br>
                        <label for="aws_iam_secret"><?php esc_html_e('IAM Secret:', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label>
                        <input type="password" id="aws_iam_secret" value="<?php echo esc_attr($cloudConfig->serviceProvider === 'bunny' || $cloudConfig->serviceProvider === 'google' ? '' : $cloudConfig->iamSecret); ?>" placeholder="<?php esc_attr_e('Enter your IAM Secret Access Key', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>" class="regular-text" autocomplete="off">
                        <span class="smartsync-input-description"><?php esc_html_e('Your IAM Secret Access Key. Keep this secure as it provides access to your cloud resources.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></span>
                        <br><br>
                    </div>

                    <div id="wasabi-region-selector" class="config-inputs wasabi-inputs d-none">
                        <label for="wasabi_bucket_region"><?php esc_html_e('Wasabi Bucket Region', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label>
                        <input type="text" id="wasabi_bucket_region" class="regular-text" value="<?php echo esc_attr($cloudConfig->serviceProvider === 'wasabi' ? $cloudConfig->region : 'us-east-1'); ?>" autocomplete="off" spellcheck="false">
                    </div>

                    <div class="config-inputs google-inputs d-none">
                        <label for="service-json"><?php esc_html_e('Service-account JSON file', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label>
                        <input type="file" id="service-json" name="service_json" accept=".json,application/json" data-has-credentials="<?php echo $cloudConfig->serviceProvider === 'google' && $cloudConfig->googleServicePath !== '' ? '1' : '0'; ?>">
                        <?php if ($cloudConfig->serviceProvider === 'google' && $cloudConfig->googleServicePath !== ''): ?>
                            <p class="smartsync-input-description"><?php esc_html_e('Leave this blank to use the saved service account.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="config-inputs cloudflare-inputs d-none">
                        <label for="cloudflare-endpoint"><?php esc_html_e('Cloudflare Storage Endpoint', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label>
                        <input type="url" id="cloudflare-endpoint" class="regular-text" name="cloudflare-endpoint" value="<?php echo esc_attr($cloudConfig->cloudflareEndpoint); ?>" autocomplete="off" spellcheck="false">
                        <br><br>
                        <label for="cloudflare-url"><?php esc_html_e('Cloudflare Public URL', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label>
                        <input type="text" id="cloudflare-url" class="regular-text" name="cloudflare-url" value="<?php echo esc_attr($cloudConfig->cloudflareUrl ?: ($cloudConfig->serviceProvider === 'cloudflare' ? $cloudConfig->url : '')); ?>" autocomplete="off" spellcheck="false">
                    </div>
                    <div class="config-inputs bunny-inputs d-none">
                        <label for="bunny-api-key"><?php esc_html_e('Bunny API Key', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label>
                        <input type="password" id="bunny-api-key" class="regular-text" value="<?php echo esc_attr($cloudConfig->serviceProvider === 'bunny' ? explode('|', $cloudConfig->iamSecret)[0] : ''); ?>" name="bunny-api-key" autocomplete="off">
                    </div>
                    <br><br>
                    <button id="step-1-next" class="primary-btn">
                        <?php esc_html_e('Next', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>
                        <span class="button-icon dashicons dashicons-arrow-right-alt"></span>
                    </button>
                </div>

                <!-- Step 2: Bucket Selection -->
                <div id="wizard-step-2" class="wizard-step d-none" hidden>

                    <h2><?php esc_html_e('Step 2: Select or Create a Bucket', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h2>
                    <div id="bucket-type-selector">
                        <label>
                            <input type="radio" name="bucket_type" value="existing" checked>
                            <?php esc_html_e('Use Existing Bucket', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>
                        </label>
                        <br>
                        <label>
                            <input type="radio" name="bucket_type" value="new" id="new-bucket-radio">
                            <?php esc_html_e('Create New Bucket', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>
                        </label>
                    </div>
                    <br><br>

                    <div id="existing-bucket-options">
                        <label for="aws_bucket_list"><?php esc_html_e('Select Existing Bucket:', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label>
                        <select id="aws_bucket_list">
                            <option value=""><?php esc_html_e('Loading buckets...', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></option>
                        </select>
                    </div>

                    <div id="pull-zones-container" class="d-none">
                        <label for="pull-zones"><?php esc_html_e('Select Pull Zone:', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label>
                        <select id="pull-zones">
                            <option value=""><?php esc_html_e('Loading zones...', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></option>
                        </select>
                    </div>

                    <div id="new-bucket-options" class="d-none">
                        <label for="new_bucket_name"><?php esc_html_e('New Bucket Name:', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label>
                        <input type="text" id="new_bucket_name" placeholder="<?php esc_attr_e('Enter a globally unique bucket name', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>" class="regular-text">
                        <span class="smartsync-input-description"><?php esc_html_e('Use a bucket name accepted by the selected provider.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></span>
                        <br><br>

                        <div id="region-selector">
                            <label for="new_bucket_region"><?php esc_html_e('Bucket Region:', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label>
                            <select id="new_bucket_region">
                                <option value="us-east-1"><?php esc_html_e('US East (N. Virginia)', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></option>
                                <option value="us-west-1"><?php esc_html_e('US West (N. California)', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></option>
                                <option value="us-west-2"><?php esc_html_e('US West (Oregon)', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></option>
                                <option value="eu-west-1"><?php esc_html_e('EU (Ireland)', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></option>
                            </select>
                        </div>
                    </div>
                    <br><br>
                    <button id="step-2-back" class="secondary-btn">
                        <span class="button-icon dashicons dashicons-arrow-left-alt"></span>
                        <?php esc_html_e('Back', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>
                    </button>
                    <button id="step-2-next" class="primary-btn">
                        <?php esc_html_e('Next', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>
                        <span class="button-icon dashicons dashicons-arrow-right-alt"></span>
                    </button>
                </div>

                <!-- Step 3: CloudFront Configuration -->
                <div id="wizard-step-3" class="wizard-step d-none" hidden>
                    <h2><?php esc_html_e('Step 3: Configure CloudFront', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h2>
                    <p><?php esc_html_e('Do you want to use CloudFront for a CDN?', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p>
                    <button id="use-cloudfront-yes" class="primary-btn">
                        <span class="button-icon dashicons dashicons-yes"></span>
                        <?php esc_html_e('Yes', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>
                    </button>
                    <button id="use-cloudfront-no" class="secondary-btn">
                        <span class="button-icon dashicons dashicons-no"></span>
                        <?php esc_html_e('No', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>
                    </button>
                    <br><br>
                    <button id="step-3-back" class="secondary-btn">
                        <span class="button-icon dashicons dashicons-arrow-left-alt"></span>
                        <?php esc_html_e('Back', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>
                    </button>
                </div>

                <!-- Final Step -->
                <div id="wizard-step-final" class="wizard-step d-none" hidden>
                    <h2><?php esc_html_e('Configuration Complete', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h2>
                    <p><?php esc_html_e('Your Cloud configuration is complete! You can now sync your assets and manage your Cloud settings.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p>
                    <a id="complete-setup" class="primary-btn" href="<?php echo esc_url(admin_url('admin.php?page=smarl-smart-sync')); ?>">
                        <span class="button-icon dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e('Finish', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
}
