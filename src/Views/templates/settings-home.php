<?php
use SmartSyncS3\Models\SMARL_CloudConfig;
if (!defined('ABSPATH')) exit;

return static function (SMARL_CloudConfig $config, string $providerName, bool $configured, array $compatibility): void {
$hasTarget = $config->bucket !== '';
$proPreview = require __DIR__ . '/pro-features.php';
$icon = static function (string $name, string $class = 'settings-section-icon'): void {
    $paths = [
        'brand' => 'M5 9a7 7 0 0 1 12-3l2 2M19 4v4h-4M19 15a7 7 0 0 1-12 3l-2-2M5 20v-4h4',
        'overview' => 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',
        'media' => 'M4 4h16v16H4zM4 15l5-5 4 4 3-3 4 4M15 8h.01',
        'assets' => 'M8 5l-6 7 6 7M16 5l6 7-6 7M14 3l-4 18',
        'cloud' => 'M7 18a5 5 0 0 1-.7-9.95A6 6 0 0 1 18 7.2 5.5 5.5 0 0 1 19 18H7Z',
        'usage' => 'M4 20V10h4v10M10 20V4h4v16M16 20v-7h4v7M3 20h18',
        'upload' => 'M12 16V4M7 9l5-5 5 5M4 16v4h16v-4',
        'activity' => 'M3 12h4l3-7 4 14 3-7h4',
        'clear' => 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v5M14 11v5',
        'stop' => 'M6 6h12v12H6z',
        'refresh' => 'M20 4v5h-5M4 20v-5h5M5.6 8a7 7 0 0 1 11.35-2.95L20 9M4 15l3.05 3.95A7 7 0 0 0 18.4 16',
    ];
    ?>
    <svg class="<?php echo esc_attr($class); ?>" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="<?php echo esc_attr($paths[$name] ?? $paths['cloud']); ?>"/></svg>
    <?php
};
?>
<div class="smartsync-wrap smartsync-settings">
    <header class="settings-page-header">
        <div class="settings-brand"><span class="settings-brand-mark" aria-hidden="true"><?php $icon('brand', 'settings-brand-icon'); ?></span><h1><?php esc_html_e('SmartSync Lite', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h1></div>
        <span class="settings-connection-badge" data-connected="<?php echo $configured ? 'true' : 'false'; ?>"><?php echo esc_html($configured ? __('Connected', 'smartsync-lite-media-offloader-and-assets-cdn') : ($hasTarget ? __('Needs setup', 'smartsync-lite-media-offloader-and-assets-cdn') : __('No connection', 'smartsync-lite-media-offloader-and-assets-cdn'))); ?></span>
    </header>
    <?php if (!$compatibility['compatible']): ?>
        <div class="notice notice-error"><p><?php foreach ($compatibility['error'] as $error) echo esc_html($error['error_message']) . '<br>'; ?></p></div>
    <?php endif; ?>
    <div class="settings-navigation">
    <div class="tabs-container settings-tabs" role="tablist" aria-label="<?php esc_attr_e('SmartSync settings', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>">
        <button type="button" id="tab-button-general" class="tab-button active" data-tab="general" role="tab" aria-controls="tab-general" aria-selected="true"><?php $icon('overview', 'settings-tab-icon'); ?><?php esc_html_e('Overview', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
        <button type="button" id="tab-button-media" class="tab-button" data-tab="media" role="tab" aria-controls="tab-media" aria-selected="false" tabindex="-1"><?php $icon('media', 'settings-tab-icon'); ?><?php esc_html_e('Media', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
        <button type="button" id="tab-button-assets" class="tab-button" data-tab="assets" role="tab" aria-controls="tab-assets" aria-selected="false" tabindex="-1"><?php $icon('assets', 'settings-tab-icon'); ?><?php esc_html_e('Assets', 'smartsync-lite-media-offloader-and-assets-cdn'); ?> <span class="smartsync-pro-badge"><?php esc_html_e('Pro only', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></span></button>
    </div>
    <div class="settings-destination" role="group" aria-label="<?php esc_attr_e('Sync destination', 'smartsync-lite-media-offloader-and-assets-cdn'); ?>" hidden>
        <?php $icon('cloud', 'settings-destination-icon'); ?>
        <?php if ($configured): ?>
            <span class="settings-destination-provider"><?php echo esc_html($providerName); ?></span>
            <span class="settings-destination-divider" aria-hidden="true">/</span>
            <span class="settings-destination-storage"><?php echo esc_html($config->bucket); ?></span>
        <?php else: ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=smarl-configuration-wizard')); ?>"><?php esc_html_e('Connect storage', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></a>
        <?php endif; ?>
    </div>
    </div>
    <input type="hidden" value="" id="current-sync-state">
    <div class="settings-panels">
        <div id="tab-general" class="tab-panel active" role="tabpanel" aria-labelledby="tab-button-general">
            <div class="settings-panel-grid settings-overview-grid">
                <section class="settings-card settings-connection-card" aria-labelledby="settings-connection-title">
                    <div class="settings-card-heading"><h2 id="settings-connection-title"><?php $icon('cloud'); ?><?php esc_html_e('Connection', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h2>
                        <?php if ($hasTarget): ?><a id="reconfigure-aws" class="settings-text-link" href="<?php echo esc_url(admin_url('admin.php?page=smarl-configuration-wizard')); ?>"><?php esc_html_e('Manage connection', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></a><?php endif; ?>
                    </div>
                    <?php if ($hasTarget): ?>
                        <p class="settings-provider-title"><?php echo esc_html($providerName); ?></p>
                        <dl class="settings-connection-details">
                            <div><dt><?php esc_html_e('Storage', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></dt><dd><?php echo esc_html($config->bucket); ?></dd></div>
                            <div><dt><?php esc_html_e('Region', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></dt><dd><?php echo esc_html($config->region ?: '—'); ?></dd></div>
                            <div class="settings-delivery-url"><dt><?php esc_html_e('Delivery URL', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></dt><dd><?php echo esc_html($config->url ?: __('Not set', 'smartsync-lite-media-offloader-and-assets-cdn')); ?></dd></div>
                        </dl>
                    <?php else: ?>
                        <p class="settings-empty-title"><?php esc_html_e('Connect your storage', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p>
                        <p class="settings-help"><?php esc_html_e('Choose a provider to start uploading media.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p>
                        <a id="reconfigure-aws" class="primary-btn settings-button" href="<?php echo esc_url(admin_url('admin.php?page=smarl-configuration-wizard')); ?>"><?php esc_html_e('Connect storage', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></a>
                    <?php endif; ?>
                </section>
                <section class="settings-card settings-usage-card" aria-labelledby="settings-usage-title">
                    <div class="settings-card-heading"><h2 id="settings-usage-title"><?php $icon('usage'); ?><?php esc_html_e('Storage usage', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h2>
                        <button type="button" id="fetch-analytics-button" class="settings-text-link"<?php if (!$configured) echo ' hidden'; ?>><?php $icon('refresh', 'settings-action-icon'); ?><span class="settings-action-label"><?php esc_html_e('Refresh usage', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></span></button>
                    </div>
                    <dl class="settings-usage-metrics"><div><dt><?php esc_html_e('Files', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></dt><dd id="total-files">—</dd></div><div><dt><?php esc_html_e('Storage used', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></dt><dd id="total-storage">—</dd></div></dl>
                    <p id="analytics-description" class="settings-help"><?php echo esc_html($configured ? __('Refresh to load usage from your provider.', 'smartsync-lite-media-offloader-and-assets-cdn') : __('Connect storage to view its usage.', 'smartsync-lite-media-offloader-and-assets-cdn')); ?></p>
                    <details class="settings-disclosure"<?php if (!$configured) echo ' hidden'; ?>><summary><?php esc_html_e('Usage details', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></summary><p class="settings-help"><?php esc_html_e('Estimated monthly cost:', 'smartsync-lite-media-offloader-and-assets-cdn'); ?> <span id="total-price"><?php esc_html_e('Unavailable', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></span></p></details>
                </section>
            </div>

            <?php $proPreview(); ?>
        </div>
        <?php foreach (['media', 'assets'] as $type): $media = $type === 'media';
            if (!$media): ?><div id="tab-assets" class="tab-panel" role="tabpanel" aria-labelledby="tab-button-assets" hidden><?php $proPreview(['assets']); ?></div><?php continue; endif; $toggleId = 'use-cloudfront-media'; ?>
            <div id="tab-<?php echo esc_attr($type); ?>" class="tab-panel" role="tabpanel" aria-labelledby="tab-button-<?php echo esc_attr($type); ?>" hidden>
                <div class="settings-panel-grid">
                    <section class="settings-card settings-delivery-card" aria-labelledby="settings-delivery-<?php echo esc_attr($type); ?>">
                        <div class="settings-card-heading"><h2 id="settings-delivery-<?php echo esc_attr($type); ?>"><?php $icon('cloud'); ?><?php esc_html_e('Cloud delivery', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h2></div>
                        <div class="settings-toggle-row"><div><label id="<?php echo esc_attr($toggleId); ?>-label" for="<?php echo esc_attr($toggleId); ?>"><?php echo esc_html(__('Serve media from cloud', 'smartsync-lite-media-offloader-and-assets-cdn')); ?></label><p id="<?php echo esc_attr($toggleId); ?>-help" class="settings-help"><?php echo esc_html(__('Deliver media through your connected storage URL.', 'smartsync-lite-media-offloader-and-assets-cdn')); ?></p></div>
                            <input type="checkbox" role="switch" id="<?php echo esc_attr($toggleId); ?>" class="settings-toggle use-cloudfront-button" data-type="<?php echo esc_attr($type); ?>" aria-labelledby="<?php echo esc_attr($toggleId); ?>-label" aria-describedby="<?php echo esc_attr($toggleId); ?>-help"<?php if ($config->useCdnForMedia) echo ' checked'; ?>>
                        </div>

                            <div class="settings-toggle-row"><div><label id="auto-delete-media-label" for="auto-delete-media"><?php esc_html_e('Remove local copies', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></label><p id="auto-delete-media-help" class="settings-help"><?php esc_html_e('Only after all attachment files upload successfully and cloud delivery is enabled.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p></div><input type="checkbox" role="switch" id="auto-delete-media" class="settings-toggle" aria-labelledby="auto-delete-media-label" aria-describedby="auto-delete-media-help"<?php if ($config->mediaAutoDelete) echo ' checked'; ?>></div>
</section>
                    <section class="settings-card settings-sync-card" aria-labelledby="settings-sync-<?php echo esc_attr($type); ?>">
                        <div class="settings-card-heading"><h2 id="settings-sync-<?php echo esc_attr($type); ?>"><?php $icon('upload'); ?><?php echo esc_html(__('Sync media', 'smartsync-lite-media-offloader-and-assets-cdn')); ?></h2></div>
                        <div class="smartsync-upload-mode">
                            <fieldset id="sync-mode-<?php echo esc_attr($type); ?>" class="sync-mode settings-mode-picker" aria-describedby="sync-mode-help-<?php echo esc_attr($type); ?>" disabled>
                                <legend><?php esc_html_e('Upload mode', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></legend>
                                <div class="settings-mode-options">
                                    <label class="settings-mode-option" for="sync-mode-<?php echo esc_attr($type); ?>-incremental"><input type="radio" id="sync-mode-<?php echo esc_attr($type); ?>-incremental" name="sync-mode-<?php echo esc_attr($type); ?>" value="incremental" checked><span><strong><?php esc_html_e('Incremental', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></strong><small><?php esc_html_e('Recommended', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></small></span></label>
                                    <label class="settings-mode-option" for="sync-mode-<?php echo esc_attr($type); ?>-full"><input type="radio" id="sync-mode-<?php echo esc_attr($type); ?>-full" name="sync-mode-<?php echo esc_attr($type); ?>" value="full"><span><strong><?php esc_html_e('Full resync', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></strong></span></label>
                                </div>
                            </fieldset>
                            <p id="sync-mode-help-<?php echo esc_attr($type); ?>" class="settings-help"><?php esc_html_e('Skips unchanged files and retries unsuccessful uploads.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p>
                        </div>
                        <button type="button" id="<?php echo 'sync-local-to-aws'; ?>" data-type="<?php echo esc_attr($type); ?>" data-direction="upload" class="primary-btn settings-button sync-button"><?php echo esc_html(__('Upload media', 'smartsync-lite-media-offloader-and-assets-cdn')); ?></button>
                        <details class="settings-disclosure settings-sync-help"><summary><?php esc_html_e('How syncing works', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></summary><p class="settings-help"><?php esc_html_e('Untracked files upload once to establish history. Before skipping unchanged files, SmartSync checks their cloud copies. Missing or size-mismatched copies upload again. Full resync uploads every selected local file without checking existing copies.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p></details>

                            <details class="settings-disclosure settings-restore"><summary><?php esc_html_e('Restore from cloud', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></summary><p class="settings-help"><?php esc_html_e('Download media back to this site. Existing local files are replaced only after a successful download.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p><button type="button" id="sync-aws-to-local" data-type="media" data-direction="download" class="settings-button settings-button-secondary sync-button"><?php esc_html_e('Restore local files', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button></details>
</section>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <section class="settings-card settings-activity" aria-labelledby="settings-activity-title">
        <div class="settings-activity-heading">
            <div class="settings-activity-title"><h2 id="settings-activity-title"><?php $icon('activity'); ?><?php esc_html_e('Activity', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></h2><span id="settings-activity-status" class="settings-activity-status" data-state="checking" role="status"><?php esc_html_e('Checking status…', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></span></div>
            <div class="settings-activity-actions">
                <button type="button" id="stop-all-processes" class="settings-button settings-button-danger" hidden><?php $icon('stop', 'settings-action-icon'); ?><?php esc_html_e('Stop sync', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
                <button type="button" id="recover-sync-operation" class="settings-button settings-button-secondary" hidden><?php $icon('refresh', 'settings-action-icon'); ?><?php esc_html_e('Recover operation', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
                <button type="button" id="clear-logs-button" class="settings-button settings-button-secondary"><?php $icon('clear', 'settings-action-icon'); ?><?php esc_html_e('Clear logs', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></button>
            </div>
        </div>
        <div id="settings-progress-slot"></div>
        <details id="settings-activity-details" class="settings-disclosure"><summary><?php esc_html_e('View transfer log', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></summary><div id="sync-log-container" class="sync-log" role="log" aria-live="polite" aria-relevant="additions"><p id="sync-log-placeholder"><?php esc_html_e('No transfers yet. Start an upload from Media.', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></p></div></details>
    </section>
</div>
<?php
};
