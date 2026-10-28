<?php
if (!defined('ABSPATH')) exit;
return static function (string $title, string $class = '', ?callable $afterTitle = null): void {
    ?>
    <header class="smartsync-subpage-header <?php echo esc_attr($class); ?>">
        <a class="smartsync-back-link" href="<?php echo esc_url(admin_url('admin.php?page=smarl-smart-sync')); ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m12 5-7 7 7 7M5 12h15"/></svg><?php esc_html_e('SmartSync Lite', 'smartsync-lite-media-offloader-and-assets-cdn'); ?></a>
        <h1><?php echo esc_html($title); ?></h1>
        <?php if ($afterTitle !== null) $afterTitle(); ?>
    </header>
    <?php
};
