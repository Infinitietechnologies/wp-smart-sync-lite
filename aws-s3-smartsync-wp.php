<?php
/**
 * Plugin Name: SmartSync
 * Description: Offload WordPress media and assets to cloud storage and serve them through a CDN.
 * Version: 1.8.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Infinitietech
 * Author URI: https://infinitietech.com/
 * Text Domain: smartsync-lite-media-offloader-and-assets-cdn
 * Domain Path: /languages/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package SmartSync
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SMARL_TEXT_DOMAIN')) define('SMARL_TEXT_DOMAIN', 'smartsync-lite-media-offloader-and-assets-cdn');
if (!defined('SMARL_VERSION')) define('SMARL_VERSION', '1.8.0');
if (!defined('SMARL_ASSET_VERSION_CODE')) define('SMARL_ASSET_VERSION_CODE', SMARL_VERSION);
if (!defined('SMARL_DB_VERSION')) define('SMARL_DB_VERSION', '3');

/** Keep activation and updates on the same versioned schema path. */
function smarl_file_sync_queue_create_table(): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'smarl_file_sync_queue';
    $collation = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        run_id varchar(64) NOT NULL DEFAULT '',
        path text NOT NULL,
        relative_path text NOT NULL,
        size bigint(20) NOT NULL DEFAULT -1,
        status varchar(20) NOT NULL DEFAULT 'pending',
        created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY run_status (run_id,status,id)
    ) {$collation};";
    if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    dbDelta($sql);
    $queueCreated = empty($wpdb->last_error);
    $history = $wpdb->prefix . 'smarl_upload_history';
    dbDelta("CREATE TABLE {$history} (
        target_hash char(64) NOT NULL,
        key_hash char(64) NOT NULL,
        relative_path text NOT NULL,
        content_hash char(64) NOT NULL,
        size bigint(20) NOT NULL,
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (target_hash,key_hash)
    ) {$collation};");
    if ($queueCreated && empty($wpdb->last_error)) {
        update_option('smarl_db_version', SMARL_DB_VERSION, false);
    }
}

function smarl_maybe_upgrade_schema(): void
{
    if (get_option('smarl_db_version', '') !== SMARL_DB_VERSION) {
        smarl_file_sync_queue_create_table();
    }
}

$smarlAutoload = __DIR__ . '/vendor/autoload.php';
$smarlScheduler = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
if (PHP_VERSION_ID < 80100 || !is_readable($smarlAutoload) || !is_readable($smarlScheduler)) {
    register_activation_hook(__FILE__, static function (): void {
        wp_die(esc_html__('SmartSync requires PHP 8.1 or later and its Composer dependencies. Install the complete plugin package before activating it.', 'smartsync-lite-media-offloader-and-assets-cdn'));
    });
    add_action('admin_notices', static function (): void {
        if (current_user_can('activate_plugins')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('SmartSync requires PHP 8.1 or later and its Composer dependencies. Install the complete plugin package before activating it.', 'smartsync-lite-media-offloader-and-assets-cdn') . '</p></div>';
        }
    });
    return;
}

require_once $smarlAutoload;
require_once $smarlScheduler;
unset($smarlAutoload, $smarlScheduler);

$smartSync = new \SmartSyncS3\SMARL_SmartSync(__FILE__);
$smartSync->initializeHooks();
$smartSync->initializeActions();
$smartSync->initializeFilters();
$smartSync->initializeViews();
add_action('plugins_loaded', 'smarl_maybe_upgrade_schema', 5);

