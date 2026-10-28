<?php
use SmartSyncS3\SMARL_Edition;
if (!defined('ABSPATH')) exit;

return static function (array $features = ['google', 'cloudflare', 'cloudfront', 'assets']): void {
    $titles = [
        'google' => __('Google Cloud Storage integration', 'smartsync-lite-media-offloader-and-assets-cdn'),
        'cloudflare' => __('Cloudflare R2 integration', 'smartsync-lite-media-offloader-and-assets-cdn'),
        'cloudfront' => __('Amazon CloudFront CDN integration', 'smartsync-lite-media-offloader-and-assets-cdn'),
        'assets' => __('JS and CSS file synchronization', 'smartsync-lite-media-offloader-and-assets-cdn'),
    ];
    ?>
    <section class="smartsync-pro-previews" aria-label="<?php esc_attr_e('Pro features', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>">
        <?php foreach ($features as $feature): if (!isset($titles[$feature])) continue; ?>
        <article class="smartsync-pro-preview" data-pro-feature="<?php echo esc_attr($feature); ?>">
            <div class="smartsync-pro-heading"><h2><?php echo esc_html($titles[$feature]); ?></h2><span class="smartsync-pro-badge"><?php esc_html_e('Pro only', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></span></div>
            <p><?php esc_html_e('Preview only. Available with SmartSync Pro.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p>
            <fieldset disabled inert class="smartsync-pro-controls" aria-label="<?php echo esc_attr($titles[$feature]); ?>">
                <?php if ($feature === 'assets'): ?>
                    <label><input type="checkbox" disabled> <?php esc_html_e('Serve JS and CSS files from cloud', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label>
                    <button type="button" disabled class="button"><?php esc_html_e('Synchronize JS and CSS', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
                <?php elseif ($feature === 'google'): ?>
                    <label><?php esc_html_e('Service-account key', 'smartsync-lite-media-offloader-and-assets-cdn'); ?><input type="file" disabled tabindex="-1"></label>
                    <button type="button" disabled class="button"><?php esc_html_e('Connect Google Cloud Storage', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
                <?php elseif ($feature === 'cloudflare'): ?>
                    <label><?php esc_html_e('R2 account endpoint', 'smartsync-lite-media-offloader-and-assets-cdn'); ?><input type="text" disabled placeholder="https://ACCOUNT_ID.r2.cloudflarestorage.com"></label>
                    <button type="button" disabled class="button"><?php esc_html_e('Connect Cloudflare R2', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
                <?php else: ?>
                    <label><?php esc_html_e('Custom CDN domain', 'smartsync-lite-media-offloader-and-assets-cdn'); ?><input type="text" disabled placeholder="cdn.example.com"></label>
                    <button type="button" disabled class="button"><?php esc_html_e('Configure CloudFront', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
                <?php endif; ?>
            </fieldset>
            <a class="button button-secondary smartsync-pro-upgrade" href="<?php echo esc_url(SMARL_Edition::PRO_URL); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Get SmartSync Pro', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></a>
        </article>
        <?php endforeach; ?>
    </section>
    <?php
};
