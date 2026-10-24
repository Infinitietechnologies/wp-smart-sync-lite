<?php


namespace SmartSyncS3;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Throwable;
use SmartSyncS3\Ajax\SMARL_AjaxHandler;
use SmartSyncS3\Ajax\SMARL_AwsConfigAjax;
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Models\SMARL_CloudConfig;
use SmartSyncS3\Views\SMARL_AWSWizard;
use SmartSyncS3\Views\SMARL_CustomUrl;
use SmartSyncS3\Views\SMARL_SettingsPage;


/**
 * Class SmartSync
 *
 * The SmartSync class serves as the core of the AWS S3 SmartSync plugin. It manages the initialization
 * of the plugin, handles hooks, filters, and AJAX interactions, and facilitates integration with AWS S3
 * and CloudFront. The class is designed to provide a seamless experience for synchronizing and managing
 * assets with AWS services.
 *
 * Features:
 * - Initializes filters for asset and media URL modifications.
 * - Sets up actions for plugin lifecycle events (activation, deactivation, update).
 * - Registers hooks for WordPress plugin lifecycle events.
 * - Manages AJAX requests related to AWS configuration and general plugin interactions.
 * - Enqueues admin scripts and styles for the plugin's interface.
 *
 * @package SmartSyncS3
 * @author Infinitie Technologies
 * @developer Jay Parmar
 *
 * Properties:
 * @property SMARL_CloudConfig|null $config Holds the configuration for AWS S3 and CloudFront settings.
 * @property SMARL_AwsConfigAjax|null $awsConfigAjax Manages AJAX interactions for AWS configuration.
 * @property SMARL_AjaxHandler|null $ajaxHandlers Handles general AJAX requests for plugin operations.
 * @property string $pluginRoot The root directory of the plugin.
 *
 * Usage:
 * Instantiate the class and call `initialize_actions`, `initialize_filters`, and `initialize_hooks` to set up the plugin.
 *
 * Example:
 * ```php
 * $smartSync = new SmartSync(plugin_root: plugin_dir_path(__FILE__));
 * $smartSync->initialize_actions();
 * $smartSync->initialize_filters();
 * $smartSync->initialize_hooks();
 * ```
 */
class SMARL_SmartSync
{

    /**
     * @var SMARL_CloudConfig $config Holds the configuration for AWS S3 and CloudFront settings.
     * @access private
     */
    private SMARL_CloudConfig $config;

    /**
     * @var SMARL_AwsConfigAjax $awsConfigAjax Handles AJAX requests related to AWS configuration.
     * @access private
     */
    private SMARL_AwsConfigAjax $awsConfigAjax;

    /**
     * @var SMARL_AjaxHandler $ajaxHandlers Handles general AJAX requests for the plugin.
     * @access private
     */
    private SMARL_AjaxHandler $ajaxHandlers;

    /**
     * @var string $pluginRoot The root directory of the plugin.
     * @access private
     */
    private string $pluginRoot;

    private array $pendingAttachments = [];
    private array $pendingCleanups = [];
    private array $completedAttachmentMetadata = [];
    private array $completedAttachmentFiles = [];
    private array $pendingCloudDeletions = [];
    private array $cloudKeyTargets = [];
    private array $sharedCloudKeys = [];
    private array $configurationSnapshot = [];
    private array $localDeliveryUsed = [];
    private int $localCleanupDepth = 0;
    private array $deletedCloudKeys = [];
    private int $currentBlogId;
    private array $blogRuntimeStates = [];
    private const BLOG_RUNTIME_FIELDS = ['pendingAttachments', 'pendingCleanups', 'completedAttachmentMetadata', 'completedAttachmentFiles', 'pendingCloudDeletions', 'cloudKeyTargets', 'sharedCloudKeys', 'localDeliveryUsed', 'deletedCloudKeys', 'localCleanupDepth'];

    /**
     * Constructor for the SmartSync class.
     *
     * @param string $plugin_root The root directory of the plugin.
     * @developer Jay Parmar
     */
    public function __construct(string $plugin_root)
    {
        $this->config = SMARL_CloudConfig::load();
        $this->configurationSnapshot = $this->config->toArray();
        $this->currentBlogId = get_current_blog_id();
        $this->pluginRoot = $plugin_root;
        $this->awsConfigAjax = new SMARL_AwsConfigAjax(configuration: $this->config);
        $this->ajaxHandlers = new SMARL_AjaxHandler(configuration: $this->config);
    }


    /**
     * Initializes filters for modifying asset and attachment URLs.
     * @developer Jay Parmar
     */
    public function initializeFilters(): void
    {
        add_filter('script_loader_src', [$this, 'replaceBaseUrl']);
        add_filter('style_loader_src', [$this, 'replaceBaseUrl']);
        add_filter('wp_get_attachment_url', [$this, 'getAttachmentUrl'], 10, 2);
        add_filter('wp_calculate_image_srcset', [$this, 'rewriteImageSources'], 10, 5);
        add_filter('wp_delete_file', [$this, 'deleteFromS3']);
        add_filter('wp_generate_attachment_metadata', [$this, 'completeAttachmentUpload'], PHP_INT_MAX, 2);
        add_filter('wp_update_attachment_metadata', [$this, 'queueAttachmentUpload'], 20, 2);
        add_filter('the_content', [$this, 'rewriteContentMedia'], 20);
        add_filter('wp_content_img_tag', [$this, 'rewriteContentImageTag'], 20, 3);
    }
    /**
     * Initializes actions for plugin lifecycle events and admin scripts.
     * @developer Jay Parmar
     */
    public function initializeActions(): void{
        add_action('plugins_loaded', [$this, "loadPluginTextDomain"]);
        add_action('shutdown', [$this, 'flushAttachmentUploads']);
        add_action('add_attachment', [$this, 's3UploadNewMedia']);
        add_action('switch_blog', [$this, 'switchBlogConfiguration'], 10, 3);
        add_action('delete_attachment', [$this, 'deleteAttachmentObjects']);
        $this->awsConfigAjax->initializeActions();
        $this->ajaxHandlers->initializeActions();
        add_action('admin_enqueue_scripts', [$this, 'enqueueScripts']);


    }

    public function loadPluginTextDomain(): void {
        load_plugin_textdomain('smartsync-lite-media-offloader-and-assets-cdn', false, dirname(plugin_basename($this->pluginRoot)) . '/languages');
    }

    /** Keep registered callbacks bound to the currently selected blog, including nested switches. */
    public function switchBlogConfiguration(int $newBlogId, int $previousBlogId = 0, string $context = 'switch'): void
    {
        if ($newBlogId !== $this->currentBlogId) {
            foreach (self::BLOG_RUNTIME_FIELDS as $field) {
                $this->blogRuntimeStates[$this->currentBlogId][$field] = $this->$field;
            }
            $state = $this->blogRuntimeStates[$newBlogId] ?? [];
            foreach (self::BLOG_RUNTIME_FIELDS as $field) {
                $this->$field = $state[$field] ?? ($field === 'localCleanupDepth' ? 0 : []);
            }
            $this->currentBlogId = $newBlogId;
        }
        $this->config = SMARL_CloudConfig::load();
        $this->configurationSnapshot = $this->config->toArray();
        $this->awsConfigAjax->setConfiguration($this->config);
        $this->ajaxHandlers->setConfiguration($this->config);
    }

    public function initializeViews(): void
    {

        add_action('plugins_loaded', function () {
            $cloudConfig = SMARL_CloudConfig::load();
            $settingsPage = new SMARL_SettingsPage($cloudConfig);
            $settingsPage->loadView();
            $awsWizardPage = new SMARL_AWSWizard($cloudConfig);
            $awsWizardPage->loadView();
            $customUrlPage = new SMARL_CustomUrl($cloudConfig);
            $customUrlPage->loadView();
        });
    }

    public function registerAutoload(): void
    {
        if (file_exists(plugin_dir_path($this->pluginRoot) . 'vendor/autoload.php')) {
            require_once plugin_dir_path($this->pluginRoot) . 'vendor/autoload.php';
        }
    }

    /**
     * Registers hooks for activation, deactivation, and uninstallation of the plugin.
     * @developer Jay Parmar
     */
    public function initializeHooks(): void
    {
        register_activation_hook($this->pluginRoot, [self::class, 'activateRegisterHook']);
        register_deactivation_hook($this->pluginRoot, [self::class, 'deActivateHook']);

    }

    /**
     * Handles plugin uninstallation.
     * @developer Jay Parmar
     */
    public static function onPluginUninstall(): void {
        // WordPress executes uninstall.php without bootstrapping cloud clients.
    }

    /**
     * Handles plugin updates.
     *
     * @param mixed $upgrader_object The upgrader object.
     * @param array $options Details about the update process.
     * @developer Jay Parmar
     */
    public function onPluginUpdate(mixed $upgrader_object, array $options): mixed {
        if (($options['type'] ?? '') === 'plugin' && in_array(plugin_basename($this->pluginRoot), $options['plugins'] ?? [], true)) {
            smarl_maybe_upgrade_schema();
        }
        return $upgrader_object;
    }

    /**
     * Handles plugin deactivation.
     *
     * @param string $plugin The plugin being deactivated.
     * @developer Jay Parmar
     */
    public function onPluginDeactivation(string $plugin): void {

        if ($plugin === plugin_basename($this->pluginRoot)) {
            self::deActivateHook();
        }
    }

    /**
     * Handles plugin activation.
     *
     * @param string $plugin The plugin being activated.
     * @developer Jay Parmar
     */
    public function onPluginActivation(string $plugin): void {

        if ($plugin === plugin_basename($this->pluginRoot)) {
            self::activateRegisterHook();
        }
    }


    /**
     * Replaces the attachment URL with the CloudFront URL if enabled.
     *
     * @param string $url The original attachment URL.
     * @return string The modified attachment URL or the original URL on failure.
     * @developer Jay Parmar
     */
    public function getAttachmentUrl(string $url, int $attachmentId = 0): string
    {
        try {
            if ($this->shouldUseLocalAttachment($attachmentId)) {
                return $url;
            }
            if ($this->config->useCdnForMedia && in_array(strtolower(SMARL_Helper::getFileExtensionFromUrl($url)), SMARL_Helper::getExtension('media'), true)) {
                return $this->rewriteLocalUrl($url, true);
            }
        } catch (Throwable $error) {
            return $url;
        }
        return $url;
    }

    public function rewriteImageSources($sources, array $sizeArray = [], string $imageSrc = '', array $imageMeta = [], int $attachmentId = 0)
    {
        if (!is_array($sources) || !$this->config->useCdnForMedia || $this->shouldUseLocalAttachment($attachmentId)) {
            return $sources;
        }
        foreach ($sources as $width => $source) {
            if (is_array($source) && is_string($source['url'] ?? null)) {
                $sources[$width]['url'] = $this->getAttachmentUrl($source['url'], $attachmentId);
            }
        }
        return $sources;
    }

    private function shouldUseLocalAttachment(int $attachmentId): bool
    {
        if ($attachmentId <= 0 || (!isset($this->pendingAttachments[$attachmentId]) && get_post_meta($attachmentId, '_smarl_offload_error', true) === '')) {
            return false;
        }
        $local = get_attached_file($attachmentId, true);
        if (is_string($local) && is_file($local)) {
            $this->localDeliveryUsed[$attachmentId] = true;
            return true;
        }
        return false;
    }

    public function rewriteContentImageTag(string $html, string $context = '', int $attachmentId = 0): string
    {
        return $this->rewriteContentMedia($html, $attachmentId);
    }

    /** Edit only known media URL attributes; text, scripts, and third-party markup stay intact. */
    public function rewriteContentMedia(string $html, int $attachmentId = 0): string
    {
        if (!$this->config->useCdnForMedia || !class_exists(\WP_HTML_Tag_Processor::class)) {
            return $html;
        }
        $processor = new \WP_HTML_Tag_Processor($html);
        while ($processor->next_tag()) {
            $tag = $processor->get_tag();
            $attributes = match ($tag) {
                'IMG' => ['src', 'srcset'],
                'A' => ['href'],
                'AUDIO', 'SOURCE' => ['src', 'srcset'],
                'VIDEO' => ['src', 'poster'],
                default => [],
            };
            $tagAttachmentId = $attachmentId;
            $classes = $processor->get_attribute('class');
            if (is_string($classes) && preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $classes, $match)) {
                $tagAttachmentId = (int) $match[1];
            }
            foreach ($attributes as $attribute) {
                $value = $processor->get_attribute($attribute);
                if (!is_string($value) || $value === '') {
                    continue;
                }
                if ($attribute === 'srcset') {
                    $rewritten = preg_replace_callback('/(^|,)(\s*)([^\s,]+)([^,]*)/', function (array $candidate) use ($tagAttachmentId): string {
                        return $candidate[1] . $candidate[2] . $this->rewriteContentMediaUrl($candidate[3], $tagAttachmentId) . $candidate[4];
                    }, $value);
                } else {
                    $rewritten = $this->rewriteContentMediaUrl($value, $tagAttachmentId);
                }
                if ($rewritten !== $value) {
                    $processor->set_attribute($attribute, $rewritten);
                }
            }
        }
        return $processor->get_updated_html();
    }

    private function rewriteContentMediaUrl(string $url, int $attachmentId): string
    {
        $originalUrl = $url;
        if (str_starts_with($url, '/') && !str_starts_with($url, '//') && strpos($url, '\\') === false) {
            $site = wp_parse_url(site_url('/'));
            if (!$site || empty($site['host'])) return $originalUrl;
            $url = ($site['scheme'] ?? 'https') . '://' . $site['host'] . (isset($site['port']) ? ':' . $site['port'] : '') . $url;
        }
        $uploads = wp_upload_dir();
        $uploadPath = wp_parse_url($uploads['baseurl'], PHP_URL_PATH);
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!is_string($uploadPath) || !is_string($path) || strpos($path, rtrim($uploadPath, '/') . '/') !== 0 || $this->getAttachmentUrl($url) === $url) {
            return $originalUrl;
        }
        if ($attachmentId <= 0) {
            $lookup = preg_replace('/[?#].*$/', '', $url);
            $attachmentId = attachment_url_to_postid($lookup);
            if ($attachmentId <= 0) {
                $originalLookup = preg_replace('/-\d+x\d+(\.[^\/.]+)$/', '$1', $lookup);
                if ($originalLookup !== $lookup) {
                    $attachmentId = attachment_url_to_postid($originalLookup);
                }
            }
        }
        $rewritten = $this->getAttachmentUrl($url, $attachmentId);
        return $rewritten === $url ? $originalUrl : $rewritten;
    }

    public function generateUrlFromBase($baseUrl): string{
        $baseUrl = trim((string) $baseUrl);
        if ($baseUrl === '') {
            return '';
        }
        if (strpos($baseUrl, '//') === 0) {
            $baseUrl = 'https:' . $baseUrl;
        } elseif (!preg_match('~^https?://~i', $baseUrl)) {
            $baseUrl = 'https://' . $baseUrl;
        }
        $parts = wp_parse_url($baseUrl);
        if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }
        return rtrim($baseUrl, '/');
    }

    /**
     * Replaces the base URL of scripts and styles with the CloudFront URL.
     *
     * @param string $src The original source URL.
     * @return string The modified source URL or the original on failure.
     * @developer Jay Parmar
     */
    public function replaceBaseUrl(string $src): string
    {
        try {
            return $this->config->useCdnForAssets && in_array(strtolower(SMARL_Helper::getFileExtensionFromUrl($src)), SMARL_Helper::getExtension('assets'), true) ? $this->rewriteLocalUrl($src) : $src;
        }catch (Throwable $error){
            return $src;
        }

    }

    private function rewriteLocalUrl(string $url, bool $media = false): string
    {
        $source = wp_parse_url($url);
        if (!$source || empty($source['host']) || isset($source['user']) || isset($source['pass'])) {
            return $url;
        }
        $path = $source['path'] ?? '';
        $origins = [wp_parse_url(site_url('/'))];
        if (is_multisite()) $origins[] = wp_parse_url(network_site_url('/'));
        $keyPath = null;
        // The configured uploads URL is a trusted first-party origin even with a custom host/path.
        if ($media) {
            $uploads = wp_upload_dir();
            $upload = wp_parse_url($uploads['baseurl']);
            if ($upload && !empty($upload['host']) && strcasecmp($source['host'], $upload['host']) === 0) {
                $sourceScheme = strtolower($source['scheme'] ?? ($upload['scheme'] ?? 'https'));
                $uploadScheme = strtolower($upload['scheme'] ?? 'https');
                $sourcePort = $source['port'] ?? ($sourceScheme === 'https' ? 443 : 80);
                $uploadPort = $upload['port'] ?? ($uploadScheme === 'https' ? 443 : 80);
                $uploadPath = rtrim($upload['path'] ?? '', '/');
                if (in_array($sourceScheme, ['http', 'https'], true) && in_array($uploadScheme, ['http', 'https'], true)
                    && ($sourcePort === $uploadPort || (!isset($source['port']) && !isset($upload['port'])))
                    && strpos($path, $uploadPath . '/') === 0) {
                    $tail = rawurldecode(substr($path, strlen($uploadPath) + 1));
                    $key = SMARL_Helper::relativeKeyForPath(rtrim($uploads['basedir'], '/\\') . '/' . $tail);
                    if (!SMARL_Helper::isCurrentSiteMediaKey($key)) return $url;
                    $keyPath = implode('/', array_map('rawurlencode', explode('/', $key)));
                }
            }
        }
        foreach ($origins as $site) {
            if ($keyPath !== null) break;
            if (!$site || empty($site['host']) || strcasecmp($source['host'], $site['host']) !== 0) continue;
            $sourceScheme = strtolower($source['scheme'] ?? ($site['scheme'] ?? 'https'));
            if (!in_array($sourceScheme, ['http', 'https'], true)) continue;
            $sourcePort = $source['port'] ?? ($sourceScheme === 'https' ? 443 : 80);
            $siteScheme = strtolower($site['scheme'] ?? 'https');
            $sitePort = $site['port'] ?? ($siteScheme === 'https' ? 443 : 80);
            if ($sourcePort !== $sitePort && (isset($source['port']) || isset($site['port']))) continue;
            $sitePath = rtrim($site['path'] ?? '', '/');
            if ($sitePath !== '' && strpos($path, $sitePath . '/') !== 0) continue;
            $keyPath = ltrim(substr($path, strlen($sitePath)), '/');
            break;
        }
        if ($keyPath === null) {
            return $url;
        }
        if ($media && is_multisite()) {
            $uploads = wp_upload_dir();
            $uploadPath = rtrim((string) wp_parse_url($uploads['baseurl'], PHP_URL_PATH), '/');
            if ($uploadPath === '' || strpos($path, $uploadPath . '/') !== 0) return $url;
            $tail = rawurldecode(substr($path, strlen($uploadPath) + 1));
            $key = SMARL_Helper::relativeKeyForPath(rtrim($uploads['basedir'], '/\\') . '/' . $tail);
            if (!SMARL_Helper::isCurrentSiteMediaKey($key)) return $url;
            $keyPath = implode('/', array_map('rawurlencode', explode('/', $key)));
        }
        if ($keyPath === '' || preg_match('~(?:^|/)[.]{1,2}(?:/|$)~', rawurldecode($keyPath)) || strpos(rawurldecode($keyPath), '\\') !== false || preg_match('/[\x00-\x1f\x7f]/', rawurldecode($keyPath))) {
            return $url;
        }
        $base = $this->generateUrlFromBase($this->config->getPublicBaseUrl());
        if ($base === '') {
            return $url;
        }
        if (strpos($url, $base . '/') === 0) {
            return $url;
        }
        return $base . '/' . $keyPath . (isset($source['query']) ? '?' . $source['query'] : '') . (isset($source['fragment']) ? '#' . $source['fragment'] : '');
    }


    /**
     * Handles plugin deactivation hook.
     * @developer Jay Parmar
     */
    public static function deActivateHook(bool $networkWide = false): void
    {
        if ($networkWide && is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                try {
                    self::deActivateHook();
                } finally {
                    restore_current_blog();
                }
            }
            return;
        }
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('process_s3_sync_batch');
        }
        $settings = new \SmartSyncS3\Models\SMARL_S3SyncSettings();
        $settings->reset();
    }

    /**
     * Handles plugin activation hook.
     * @developer Jay Parmar
     */
    public static function activateRegisterHook(bool $networkWide = false): void
    {
        if ($networkWide && is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                try {
                    self::activateRegisterHook();
                } finally {
                    restore_current_blog();
                }
            }
            return;
        }
        SMARL_CloudConfig::init();
        smarl_file_sync_queue_create_table();
    }

    /**
     * Enqueues admin scripts and styles for the plugin.
     * @developer Jay Parmar
     */
    public function enqueueScripts(string $hookSuffix = ''): void
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (!current_user_can('manage_options') || !in_array($page, ['smarl-smart-sync', 'smarl-configuration-wizard', 'custom-cloudfront-url'], true)) {
            return;
        }
        // Enqueue Dashicons for UI improvements
        wp_enqueue_style('dashicons');

        // Enqueue main CSS
        wp_enqueue_style(
            'aws-admin-css',
            plugin_dir_url($this->pluginRoot) . 'assets/css/wizard.css',
            [],
            SMARL_ASSET_VERSION_CODE
        );

        // Enqueue UI improvements CSS
        wp_enqueue_style(
            'smartsync-ui-improvements-css',
            plugin_dir_url($this->pluginRoot) . 'assets/css/ui-improvements.css',
            ['aws-admin-css'],
            SMARL_ASSET_VERSION_CODE
        );

        // Enqueue enhanced UI CSS for better logs and typography
        wp_enqueue_style(
            'smartsync-enhanced-ui-css',
            plugin_dir_url($this->pluginRoot) . 'assets/css/enhanced-ui.css',
            ['smartsync-ui-improvements-css'],
            SMARL_ASSET_VERSION_CODE
        );
        wp_enqueue_script(
            'aws-admin-js',
            plugin_dir_url($this->pluginRoot) . 'assets/js/wizard.js',
            ['jquery'],
            SMARL_ASSET_VERSION_CODE,
            true
        );
        wp_localize_script('aws-admin-js', 'ajax_object', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('smarl_nonce'),
            'settings_url' => admin_url('admin.php?page=smarl-smart-sync'),
            'wizard_url' => admin_url('admin.php?page=smarl-configuration-wizard'),
            "isLite" => false,
            'strings' => [
                'loading' => __('Loading...', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'invalid_provider' => __('Select a supported storage provider.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'select_service_json' => __('Select a Google service account JSON file.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'endpoint_required' => __('Enter a valid storage endpoint.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'public_url_required' => __('Enter a valid public CDN URL.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'select_pull_zone' => __('Select a pull zone.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'invalid_bucket_response' => __('The storage provider returned an invalid bucket response.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'configuration_error' => __('Configuration could not be saved. Check the settings and try again.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'invalid_bucket_name' => __('Enter a valid bucket name.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'invalid_domain' => __('Enter a valid domain name.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'no_buckets' => __('No storage buckets were found.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'save_configuration' => __('Save Configuration', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'attaching' => __('Attaching...', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'select_bucket' => __('Select a bucket.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_us_east_1' => __('US East (N. Virginia)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_us_east_2' => __('US East (Ohio)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_us_west_1' => __('US West (N. California)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_us_west_2' => __('US West (Oregon)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_ca_central_1' => __('Canada (Central)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_eu_west_1' => __('Europe (Ireland)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_eu_west_2' => __('Europe (London)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_eu_west_3' => __('Europe (Paris)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_eu_central_1' => __('Europe (Frankfurt)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_eu_south_1' => __('Europe (Milan)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_eu_south_2' => __('Europe (Spain)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_me_south_1' => __('Middle East (Bahrain)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_me_central_1' => __('Middle East (UAE)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_af_south_1' => __('Africa (Cape Town)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_ap_northeast_1' => __('Asia Pacific (Tokyo)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_ap_northeast_2' => __('Asia Pacific (Seoul)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_ap_northeast_3' => __('Asia Pacific (Osaka)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_ap_southeast_1' => __('Asia Pacific (Singapore)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_ap_southeast_2' => __('Asia Pacific (Sydney)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_ap_southeast_3' => __('Asia Pacific (Jakarta)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_ap_south_1' => __('Asia Pacific (Mumbai)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_ap_south_2' => __('Asia Pacific (Hyderabad)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_sa_east_1' => __('South America (São Paulo)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_cn_north_1' => __('China (Beijing)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_cn_northwest_1' => __('China (Ningxia)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_us_gov_east_1' => __('AWS GovCloud (US-East)', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'aws_region_us_gov_west_1' => __('AWS GovCloud (US-West)', 'smartsync-lite-media-offloader-and-assets-cdn'),

                'digitalocean_region_nyc1' => __('New York City 1', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'digitalocean_region_nyc2' => __('New York City 2', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'digitalocean_region_nyc3' => __('New York City 3', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'digitalocean_region_ams3' => __('Amsterdam 3', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'digitalocean_region_sfo2' => __('San Francisco 2', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'digitalocean_region_sfo3' => __('San Francisco 3', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'digitalocean_region_sgp1' => __('Singapore 1', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'digitalocean_region_lon1' => __('London 1', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'digitalocean_region_fra1' => __('Frankfurt 1', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'digitalocean_region_tor1' => __('Toronto 1', 'smartsync-lite-media-offloader-and-assets-cdn'),

                // General User-Facing Messages
                'enter_domain' => __('Please enter a domain.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'certificate_generated' => __('Certificate generated successfully!', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'no_dns_records' => __('No DNS records available. Please wait and try again later.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'certificate_generation_failed' => __('Certificate generation failed', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'certificate_generation_error' => __('An error occurred while generating the certificate. Please try again.', 'smartsync-lite-media-offloader-and-assets-cdn'),

                // DNS Validation
                'dns_validation_records' => __('DNS Validation Records', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'checking' => __('Checking...', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'domain' => __('Domain', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'type' => __('Type', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'name' => __('Name', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'value' => __('Value', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'verified' => __('Verified', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'pending_validation' => __('Pending Validation', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'all_dns_verified' => __('All DNS records have been verified!', 'smartsync-lite-media-offloader-and-assets-cdn'),

                // Error Handling
                'error' => __('Error', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'dns_check_error' => __('An error occurred while checking DNS records.', 'smartsync-lite-media-offloader-and-assets-cdn'),

                // Certificate Attachment
                'certificate_attached' => __('Certificate attached successfully!', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'attachment_failed' => __('Attachment failed', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'attachment_error' => __('An error occurred while attaching the certificate. Please try again.', 'smartsync-lite-media-offloader-and-assets-cdn'),

                // IAM and Bucket Configuration
                'iam_required' => __('IAM Key and Secret are required.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'bucket_required' => __('Bucket name is required.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'configuration_saved' => __('Configuration saved successfully!', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'configuration_failed' => __('Configuration failed', 'smartsync-lite-media-offloader-and-assets-cdn'),

                // Bucket Loading
                'loading_buckets' => __('Loading buckets...', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'load_buckets_failed' => __('Failed to load buckets. Check your IAM credentials or permissions.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'load_buckets_error' => __('An error occurred while loading buckets. Please try again.', 'smartsync-lite-media-offloader-and-assets-cdn'),

                // Navigation Steps
                'step_1_next' => __('Next: Validate IAM Credentials', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'step_2_next' => __('Next: Configure Bucket', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'step_2_back' => __('Back to Step 1', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'step_3_back' => __('Back to Step 2', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'step_3_finalize' => __('Finalize Configuration', 'smartsync-lite-media-offloader-and-assets-cdn'),

                // Tabs Navigation
                'tab_general' => __('General Settings', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'tab_advanced' => __('Advanced Settings', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'tab_help' => __('Help', 'smartsync-lite-media-offloader-and-assets-cdn'),
            ]
        ]);

        wp_enqueue_script(
            'aws-settings-js',
            plugin_dir_url($this->pluginRoot) . 'assets/js/settings.js',
            ['jquery'],
            SMARL_ASSET_VERSION_CODE,
            true
        );

        // Enqueue UI improvements JS
        wp_enqueue_script(
            'smartsync-ui-improvements-js',
            plugin_dir_url($this->pluginRoot) . 'assets/js/ui-improvements.js',
            ['jquery', 'aws-admin-js', 'aws-settings-js'],
            SMARL_ASSET_VERSION_CODE,
            true
        );

        // Enqueue enhanced logs JS for better log display
        wp_enqueue_script(
            'smartsync-enhanced-logs-js',
            plugin_dir_url($this->pluginRoot) . 'assets/js/enhanced-logs.js',
            ['jquery', 'smartsync-ui-improvements-js'],
            SMARL_ASSET_VERSION_CODE,
            true
        );

        wp_localize_script('aws-settings-js', 'ajaxObject', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('smarl_nonce'),
            'wizard_url' => admin_url('admin.php?page=smarl-configuration-wizard'),
            "isLite" => false,
            'settings_strings' => [
                'breadcrumb' => __('Breadcrumb', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'dashboard' => __('Dashboard', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'settings_title' => __('SmartSync Settings', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'cost_unavailable' => __('Unavailable', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'stopped' => __('Sync stopped.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'status_error' => __('Unable to check sync status.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'request_error' => __('The request failed. Please try again.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'clear_logs' => __('Clear logs', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'no_logs' => __('No log messages yet.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'processed' => __('Processed', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'remaining' => __('Remaining', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'estimated_time' => __('Estimated time', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'sync_in_progress' => __('A sync is already in progress.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'syncing' => __('Syncing...', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'sync' => __('Sync', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'progress' => __('Progress', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'sync_completed' => __('Sync completed successfully.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'sync_failed' => __('Sync failed. Check errors below.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'starting_sync' => __('Starting sync process...', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'starting_sync_of' => __('Starting sync of', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'files' => __('files', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'uploaded' => __('Uploaded', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'downloaded' => __('Downloaded', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'confirm_sync_local_to_aws' => __('Are you sure you want to sync local files to cloud storage?', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'confirm_sync_aws_to_local' => __('Are you sure you want to restore cloud files locally?', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'failed_to_start_sync' => __('Failed to start sync. Please try again.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'confirm_stop_processes' => __('Are you sure you want to stop all the running processes?', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'stopping' => __('Stopping...', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'stopping_processes' => __('Stopping all processes...', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'failed_to_stop_processes' => __('Failed to stop processes.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'clearing_cache' => __('Clearing CloudFront cache...', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'cache_cleared' => __('CloudFront cache cleared successfully.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'failed_to_clear_cache' => __('Failed to clear CloudFront cache.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'setting_up_cdn' => __('Setting up CDN...', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'removing_cdn' => __('Removing CDN...', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'cdn_setup_success' => __('CDN setup successfully.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'cdn_setup_failed' => __('Failed to set up CDN.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'enabling_auto_delete' => __('Enabling auto-delete...', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'disabling_auto_delete' => __('Disabling auto-delete...', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'auto_delete_enabled' => __('Auto-delete enabled successfully.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'auto_delete_disabled' => __('Auto-delete disabled successfully.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'auto_delete_failed' => __('Failed to toggle auto-delete.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'loading' => __('Loading...', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'error' => __('Error', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'failed_to_fetch_analytics' => __('Failed to fetch analytics. Please try again.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'running' => __('Sync is running.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'not_running' => __('No sync is running.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'sync_in_process' => __('sync in process. Please try again after some time.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'to_aws' => __('to cloud storage', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'from_aws' => __('from cloud storage', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'confirm_clear_cache' => __('Are you sure you want to clear all the cache from AWS CloudFront?', 'smartsync-lite-media-offloader-and-assets-cdn'),
            ]
        ]);
    }

    public function s3UploadNewMedia($post_id): void
    {
        $this->queueAttachmentUpload(wp_get_attachment_metadata((int) $post_id), (int) $post_id);
    }

    /** Defer cleanup until all intermediate image sizes and metadata have been saved. */
    public function queueAttachmentUpload($metadata, $postId)
    {
        if ($this->config->isConfigured() && (int) $postId > 0) {
            $data = is_array($metadata) ? $metadata : [];
            if (!$this->hasUnchangedAttachment((int) $postId, $data)) {
                $this->pendingAttachments[(int) $postId] = $data;
                unset($this->pendingCleanups[(int) $postId]);
            }
        }
        return $metadata;
    }

    /** Make cloud objects ready before REST/editor responses choose their delivery URLs. */
    public function completeAttachmentUpload($metadata, $postId)
    {
        $this->queueAttachmentUpload($metadata, $postId);
        if (isset($this->pendingAttachments[(int) $postId])) {
            $data = $this->pendingAttachments[(int) $postId];
            unset($this->pendingAttachments[(int) $postId]);
            $this->uploadAttachment((int) $postId, $data);
        }
        return $metadata;
    }

    public function flushAttachmentUploads(): void
    {
        $ids = [];
        if ($this->pendingAttachments || $this->pendingCleanups || $this->pendingCloudDeletions) $ids[] = $this->currentBlogId;
        foreach ($this->blogRuntimeStates as $id => $state) {
            if ($id !== $this->currentBlogId && (!empty($state['pendingAttachments']) || !empty($state['pendingCleanups']) || !empty($state['pendingCloudDeletions']))) $ids[] = $id;
        }
        foreach (array_unique($ids) as $id) {
            if ($id === get_current_blog_id()) {
                $this->flushCurrentBlogUploads();
                continue;
            }
            switch_to_blog($id);
            try { $this->flushCurrentBlogUploads(); }
            finally { restore_current_blog(); }
        }
    }

    private function flushCurrentBlogUploads(): void
    {
        $pending = $this->pendingAttachments;
        $this->pendingAttachments = [];
        foreach ($pending as $postId => $metadata) {
            $latest = wp_get_attachment_metadata($postId, true);
            $metadata = is_array($latest) ? $latest : $metadata;
            $this->uploadAttachment($postId, $metadata);
        }
        $cleanups = $this->pendingCleanups;
        $this->pendingCleanups = [];
        foreach ($cleanups as $postId => $cleanup) {
            $lock = SMARL_Helper::acquireSyncLock();
            if ($lock === null) continue;
            try {
                $this->refreshMediaConfiguration();
                if ($cleanup['target'] !== $this->mediaTargetFingerprint()) {
                    $this->attachmentUploadFailed($postId);
                    continue;
                }
                if (isset($this->localDeliveryUsed[$postId]) || !$this->canCleanLocalMedia()) continue;
                $files = $cleanup['files'];
                if ($this->attachmentFiles($postId, (array) wp_get_attachment_metadata($postId, true)) !== $files) throw new \RuntimeException('Attachment files changed after upload.');
                // Verify the complete attachment before deleting any local member.
                foreach ($files as $file) {
                    $this->localObjectKey($file);
                    if (is_file($file) && ($cleanup['hashes'][$file] ?? null) !== $this->attachmentFileHash($file)) throw new \RuntimeException('Local media changed after upload.');
                }
                $this->localCleanupDepth++;
                try {
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            wp_delete_file($file);
                        }
                    }
                } finally { $this->localCleanupDepth--; }
            } catch (Throwable $error) {
                unset($this->completedAttachmentMetadata[$postId], $this->completedAttachmentFiles[$postId]);
                update_post_meta($postId, '_smarl_offload_error', __('Local media cleanup could not finish. Cloud copies were retained.', 'smartsync-lite-media-offloader-and-assets-cdn'));
            } finally {
                SMARL_Helper::releaseSyncLock($lock);
            }
        }
        $deletions = $this->pendingCloudDeletions;
        $this->pendingCloudDeletions = [];
        if ($deletions) {
            $lock = SMARL_Helper::acquireSyncLock();
            if ($lock !== null) {
                try {
                    $this->refreshMediaConfiguration();
                    foreach ($deletions as $key => $deletion) {
                        try {
                            $this->localObjectKey($deletion['path']);
                            if (!file_exists($deletion['path']) && !is_link($deletion['path']) && $deletion['target'] === $this->mediaTargetFingerprint()) $this->deleteCloudKey($key);
                        } catch (Throwable $error) { /* A changed or unsafe path cannot authorize cloud deletion. */ }
                    }
                } finally { SMARL_Helper::releaseSyncLock($lock); }
            }
        }
    }

    /** Reload settings changed by another request without replacing injected clients for unchanged settings. */
    private function refreshMediaConfiguration(): void
    {
        $fresh = SMARL_CloudConfig::load();
        if ($fresh->toArray() !== $this->configurationSnapshot) {
            $this->config = $fresh;
            $this->configurationSnapshot = $fresh->toArray();
            $this->awsConfigAjax->setConfiguration($fresh);
            $this->ajaxHandlers->setConfiguration($fresh);
        }
    }

    private function mediaTargetFingerprint(): string
    {
        return hash('sha256', SMARL_Helper::storageTargetFingerprint($this->config) . serialize([$this->config->url, $this->config->cloudfrontUrl, $this->config->useCloudFront]));
    }

    private function attachmentFileHash(string $file): string
    {
        clearstatcache(true, $file);
        $hash = is_readable($file) ? hash_file('sha256', $file) : false;
        if ($hash === false) throw new \RuntimeException('Unable to verify local media bytes.');
        return $hash;
    }

    private function hasUnchangedAttachment(int $postId, array $metadata): bool
    {
        if (($this->completedAttachmentMetadata[$postId] ?? null) !== md5(serialize($metadata))) return false;
        try {
            $files = $this->attachmentFiles($postId, $metadata);
            $hashes = $this->completedAttachmentFiles[$postId] ?? [];
            if ($files !== array_keys($hashes)) return false;
            foreach ($files as $file) if (is_file($file) && $this->attachmentFileHash($file) !== $hashes[$file]) return false;
            return true;
        } catch (Throwable $error) { return false; }
    }

    private function attachmentUploadFailed(int $postId): void
    {
        unset($this->pendingCleanups[$postId], $this->completedAttachmentMetadata[$postId], $this->completedAttachmentFiles[$postId]);
        update_post_meta($postId, '_smarl_offload_error', __('Media upload failed. Local files were retained; check your storage configuration and retry.', 'smartsync-lite-media-offloader-and-assets-cdn'));
    }

    private function uploadAttachment(int $postId, array $metadata): void
    {
        $attachment = get_post($postId);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return;
        }
        $lock = SMARL_Helper::acquireSyncLock();
        if ($lock === null) { $this->attachmentUploadFailed($postId); return; }
        try {
            $this->refreshMediaConfiguration();
            if (!$this->config->isConfigured()) { $this->attachmentUploadFailed($postId); return; }
            $target = $this->mediaTargetFingerprint();
            $this->completedAttachmentMetadata[$postId] = md5(serialize($metadata));
            $previousKeys = get_post_meta($postId, '_smarl_offloaded_files', true);
            $previousKeys = is_array($previousKeys) ? array_values(array_filter($previousKeys, 'is_string')) : [];
            $storageTarget = SMARL_Helper::storageTargetFingerprint($this->config);
            $sameTarget = get_post_meta($postId, '_smarl_offload_target', true) === $storageTarget;
            $uploadedKeys = $sameTarget ? $previousKeys : [];
            $files = [];
            $hashes = [];
            $failed = false;
            try {
                $files = $this->attachmentFiles($postId, $metadata);
                foreach ($files as $file) {
                    $key = $this->localObjectKey($file);
                    $hashes[$file] = null;
                    if (!is_file($file)) {
                        if (!in_array($key, $uploadedKeys, true)) $failed = true;
                        continue;
                    }
                    try {
                        $before = $this->attachmentFileHash($file);
                        $this->config->uploadObject($key, $file);
                        unset($this->deletedCloudKeys[$key]);
                        $uploadedKeys[] = $key;
                        $hashes[$file] = $before;
                        if ($before !== $this->attachmentFileHash($file)) $failed = true;
                    } catch (Throwable $error) {
                        $failed = true;
                    }
                }
            } catch (Throwable $error) {
                $failed = true;
            }
            $this->refreshMediaConfiguration();
            if ($target !== $this->mediaTargetFingerprint()) $failed = true;
            // Preserve previous ownership after a partial migration; only a verified full upload replaces it.
            if (!$failed || $sameTarget || !$previousKeys) {
                update_post_meta($postId, '_smarl_offloaded_files', array_values(array_unique($uploadedKeys)));
                update_post_meta($postId, '_smarl_offload_target', $storageTarget);
                foreach ($uploadedKeys as $key) $this->cloudKeyTargets[$key] = $storageTarget;
            }
            if ($failed) {
                $this->attachmentUploadFailed($postId);
                return;
            }
            delete_post_meta($postId, '_smarl_offload_error');
            $this->completedAttachmentFiles[$postId] = $hashes;
            $this->pendingCleanups[$postId] = ['files' => $files, 'hashes' => $hashes, 'target' => $target];
        } catch (Throwable $error) {
            $this->attachmentUploadFailed($postId);
        } finally { SMARL_Helper::releaseSyncLock($lock); }
    }

    private function canCleanLocalMedia(): bool
    {
        if (!$this->config->mediaAutoDelete || !$this->config->useCdnForMedia) {
            return false;
        }
        try {
            return $this->generateUrlFromBase($this->config->getPublicBaseUrl()) !== '';
        } catch (Throwable $error) {
            return false;
        }
    }

    private function attachmentFiles(int $postId, array $metadata): array
    {
        $original = get_attached_file($postId, true);
        if (!is_string($original) || $original === '') {
            throw new \RuntimeException('Attachment has no local path.');
        }
        $files = [$original];
        $directory = dirname($original);
        $sizes = is_array($metadata['sizes'] ?? null) ? $metadata['sizes'] : [];
        $backups = get_post_meta($postId, '_wp_attachment_backup_sizes', true);
        if (is_array($backups)) {
            $sizes = array_merge($sizes, $backups);
        }
        foreach (['original_image', 'source_image', 'animated_video', 'animated_video_poster'] as $companion) {
            if (!empty($metadata[$companion])) $sizes[] = ['file' => $metadata[$companion]];
        }
        foreach ($sizes as $size) {
            $name = is_array($size) ? ($size['file'] ?? '') : '';
            if (!is_string($name) || $name === '' || preg_match('~[/\\\\]~', $name) || $name === '.' || $name === '..') {
                throw new \RuntimeException('Invalid attachment size path.');
            }
            $files[] = $directory . '/' . $name;
        }
        foreach ($files as $file) {
            $this->localObjectKey($file);
        }
        return array_values(array_unique($files));
    }

    /** Resolve existing ancestors too, so missing local files cannot escape through a symlink. */
    private function localObjectKey(string $file): string
    {
        $key = SMARL_Helper::relativeKeyForPath($file);
        if (is_multisite() && !SMARL_Helper::isCurrentSiteMediaKey($key)) {
            throw new \RuntimeException('Media path is outside the current site uploads directory.');
        }
        return $key;
    }

    public function deleteAttachmentObjects($postId): void
    {
        unset($this->pendingAttachments[(int) $postId], $this->pendingCleanups[(int) $postId]);
        $keys = get_post_meta((int) $postId, '_smarl_offloaded_files', true);
        if (!$this->config->isConfigured() || !is_array($keys)) {
            return;
        }
        $lock = SMARL_Helper::acquireSyncLock();
        if ($lock === null) return;
        try {
            $this->refreshMediaConfiguration();
            $target = SMARL_Helper::storageTargetFingerprint($this->config);
            if (get_post_meta((int) $postId, '_smarl_offload_target', true) !== $target) return;
            foreach ($keys as $key) {
                if (is_string($key) && $key !== '') {
                    try {
                        SMARL_Helper::resolveLocalPath($key);
                        if (is_multisite() && !SMARL_Helper::isCurrentSiteMediaKey($key)) continue;
                        if ($this->hasOtherCloudOwner($key, (int) $postId)) {
                            $this->sharedCloudKeys[$key] = true;
                            continue;
                        }
                        $this->deleteCloudKey($key);
                    } catch (Throwable $error) {
                        // Stored metadata cannot expand the local object-key boundary.
                    }
                }
            }
        } catch (Throwable $error) {
            // Missing or changed provenance cannot authorize deleting another storage target.
        } finally { SMARL_Helper::releaseSyncLock($lock); }
    }

    /** Generic file filters can only remove objects with attachment ownership proven for this target. */
    private function hasCloudKeyProvenance(string $key): bool
    {
        $target = SMARL_Helper::storageTargetFingerprint($this->config);
        if (isset($this->cloudKeyTargets[$key]) && $this->cloudKeyTargets[$key] === $target) return true;
        for ($page = 1; ; $page++) {
            $ids = $this->cloudKeyOwners($key, $page);
            foreach ($ids as $id) {
                $owner = get_post_meta((int) $id, '_smarl_offload_target', true);
                $keys = get_post_meta((int) $id, '_smarl_offloaded_files', true);
                if ($owner !== $target || !is_array($keys)) continue;
                foreach ($keys as $ownedKey) if (is_string($ownedKey)) $this->cloudKeyTargets[$ownedKey] = $owner;
                if (isset($this->cloudKeyTargets[$key]) && $this->cloudKeyTargets[$key] === $target) return true;
            }
            if (count($ids) < 100) return false;
        }
    }

    private function cloudKeyOwners(string $key, int $page): array
    {
        return get_posts(['post_type' => 'attachment', 'post_status' => ['inherit', 'private', 'publish', 'trash'], 'fields' => 'ids', 'posts_per_page' => 100, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'meta_key' => '_smarl_offloaded_files', 'meta_value' => serialize($key), 'meta_compare' => 'LIKE', 'no_found_rows' => true]);
    }

    private function hasOtherCloudOwner(string $key, int $excludedId): bool
    {
        $target = SMARL_Helper::storageTargetFingerprint($this->config);
        for ($page = 1; ; $page++) {
            $ids = $this->cloudKeyOwners($key, $page);
            foreach ($ids as $id) {
                $keys = get_post_meta((int) $id, '_smarl_offloaded_files', true);
                if ((int) $id !== $excludedId && get_post_meta((int) $id, '_smarl_offload_target', true) === $target && is_array($keys) && in_array($key, $keys, true)) return true;
            }
            if (count($ids) < 100) return false;
        }
    }

    private function forgetCloudKeyOwnership(string $key): void
    {
        $target = SMARL_Helper::storageTargetFingerprint($this->config);
        // Gather owners first: changing manifests can alter the paginated LIKE query.
        $owners = [];
        for ($page = 1; ; $page++) {
            $ids = $this->cloudKeyOwners($key, $page);
            foreach ($ids as $id) $owners[] = (int) $id;
            if (count($ids) < 100) break;
        }
        foreach ($owners as $id) {
            $keys = get_post_meta($id, '_smarl_offloaded_files', true);
            if (get_post_meta($id, '_smarl_offload_target', true) === $target && is_array($keys) && in_array($key, $keys, true)) update_post_meta($id, '_smarl_offloaded_files', array_values(array_filter($keys, static fn($ownedKey) => $ownedKey !== $key)));
        }
        unset($this->cloudKeyTargets[$key], $this->sharedCloudKeys[$key]);
    }

    private function deleteCloudKey(string $key): void
    {
        if (isset($this->deletedCloudKeys[$key])) {
            return;
        }
        try {
            $this->config->deleteObject($key);
            $this->deletedCloudKeys[$key] = true;
            $this->forgetCloudKeyOwnership($key);
        } catch (Throwable $error) {
            // Keep WordPress deletion behavior; a provider failure must not crash the request.
        }
    }

    public function deleteFromS3($file) {
        if ($this->localCleanupDepth > 0 || !is_string($file) || !$this->config->isConfigured()) {
            return $file;
        }
        try {
            $uploads = wp_upload_dir();
            if (!empty($uploads['basedir'])) {
                SMARL_Helper::relativeKeyForPath($file, $uploads['basedir']);
                $key = $this->localObjectKey($file);
                if (!isset($this->sharedCloudKeys[$key]) && $this->hasCloudKeyProvenance($key)) $this->pendingCloudDeletions[$key] = ['path' => $file, 'target' => $this->mediaTargetFingerprint()];
            }
        } catch (Throwable $error) {
            // Invalid or unrelated local paths do not identify cloud objects.
        }
        return $file;
    }
}
