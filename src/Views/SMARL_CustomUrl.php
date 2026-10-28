<?php

namespace SmartSyncS3\Views;
if (!defined('ABSPATH')) exit;

class SMARL_CustomUrl
{



    public function loadView(): void
    {
        add_action('admin_menu', [$this, 'addAdminSubMenu']);
    }

    public function addAdminSubMenu(): void
    {
        add_submenu_page(
            'smarl-smart-sync',
            esc_html__('CloudFront — Pro only', 'smartsync-lite-media-offloader-and-assets-cdn'),
            esc_html__('CloudFront — Pro only', 'smartsync-lite-media-offloader-and-assets-cdn'),
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
        echo '<div class="wrap smartsync-wrap">';
        $header = require __DIR__ . '/templates/subpage-header.php';
        $header(__('Amazon CloudFront CDN', 'smartsync-lite-media-offloader-and-assets-cdn'));
        $preview = require __DIR__ . '/templates/pro-features.php';
        $preview(['cloudfront']);
        echo '</div>';
    }
}
