<?php
/** Second-audit regressions against actual WordPress options, cache and Action Scheduler. */
if (!defined('WP_CLI') || !WP_CLI || !str_contains(str_replace('\\', '/', ABSPATH), 'smartsync-wordpress-audit-fixture/wordpress/')) {
    throw new RuntimeException('Use only the disposable SmartSync WordPress audit fixture.');
}
if (!defined('DOING_AJAX')) define('DOING_AJAX', true);

use SmartSyncS3\Ajax\SMARL_AjaxHandler;
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Models\SMARL_CloudConfig;
use SmartSyncS3\Models\SMARL_S3SyncSettings;

function smarl_second_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
add_filter('pre_http_request', static fn() => new WP_Error('audit_http_blocked', 'External HTTP is blocked.'), PHP_INT_MAX);
add_filter('wp_die_ajax_handler', static fn() => static function () { Fiber::suspend(); }, PHP_INT_MAX);
wp_set_current_user(1);
smarl_second_assert(current_user_can('manage_options'), 'Fixture administrator required.');
global $wpdb;
$saved = [];
foreach (['smarl_sync_settings', 'smarl_sync_lock'] as $name) $saved[$name] = get_option($name, null);
$pending = [];
foreach (as_get_scheduled_actions(['hook' => 'process_s3_sync_batch', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 10000], 'ids') as $id) {
    $action = ActionScheduler::store()->fetch_action($id);
    $pending[] = [$action->get_schedule()->get_date()->getTimestamp(), $action->get_args(), $action->get_group()];
}
$migrationFilter = null;
$denyWrite = static fn($value, $old) => $old;
try {
    delete_option('smarl_sync_lock');
    // The value remains in this request's cache after another connection removes it.
    $old = ['token' => 'old-owner', 'expires' => time() + 300];
    add_option('smarl_sync_lock', $old, '', false);
    get_option('smarl_sync_lock');
    $wpdb->delete($wpdb->options, ['option_name' => 'smarl_sync_lock']);
    $token = SMARL_Helper::acquireSyncLock();
    smarl_second_assert(is_string($token), 'A stale option cache must not block a free durable lease.');
    wp_cache_set('smarl_sync_lock', $old, 'options');
    SMARL_Helper::releaseSyncLock($token);
    smarl_second_assert($wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'smarl_sync_lock'") === null, 'The current owner must release despite stale cached ownership.');
    $wpdb->insert($wpdb->options, ['option_name' => 'smarl_sync_lock', 'option_value' => 'b:0;', 'autoload' => 'off']);
    $token = SMARL_Helper::acquireSyncLock();
    smarl_second_assert(is_string($token), 'Serialized false must be reclaimed by its original bytes.');
    SMARL_Helper::releaseSyncLock($token);
    WP_CLI::success('SYNC audit: native stale-cache ownership and corrupt-lease recovery.');

    update_option('smarl_sync_settings', ['s3SyncStatus' => 'processing', 'runId' => '', 'syncState' => 'syncing'], false);
    $migrationFilter = static function ($query) use (&$migrationFilter) {
        if (!str_starts_with($query, 'UPDATE ') || !str_contains($query, "option_name = 'smarl_sync_settings'")) return $query;
        remove_filter('query', $migrationFilter);
        $new = new SMARL_S3SyncSettings(); $new->runId = 'native-new-run'; $new->s3SyncDirection = 'upload';
        $new->s3SyncStatus = 'processing'; $new->syncState = 'syncing'; $new->save();
        return $query;
    };
    add_filter('query', $migrationFilter);
    $settings = new SMARL_S3SyncSettings(); $settings->load();
    smarl_second_assert($settings->runId === 'native-new-run' && $settings->s3SyncStatus === 'processing', 'Legacy recovery must reload a concurrently committed run.');
    smarl_second_assert(get_option('smarl_sync_settings')['runId'] === 'native-new-run', 'Migration must not overwrite the newer durable run.');
    WP_CLI::success('SYNC audit: native SQL interleaving preserves the newer run.');

    $oldProgress = get_option('smarl_sync_settings');
    $wpdb->update($wpdb->options, ['option_value' => maybe_serialize((new SMARL_S3SyncSettings())->toArray()), 'autoload' => 'yes'], ['option_name' => 'smarl_sync_settings']);
    wp_cache_set('alloptions', ['smarl_sync_settings' => maybe_serialize($oldProgress)] + wp_load_alloptions(), 'options');
    $settings->load();
    smarl_second_assert($settings->s3SyncStatus === 'idle', 'Legacy alloptions cache must not resurrect stopped progress.');
    wp_cache_delete('alloptions', 'options'); wp_cache_delete('smarl_sync_settings', 'options');
    WP_CLI::success('SYNC audit: legacy autoload cache cannot resurrect durably stopped progress.');

    update_option('smarl_sync_settings', ['s3SyncStatus' => 'processing', 'runId' => 'bad-direction', 's3SyncDirection' => 'invalid',
        's3SyncErrors' => array_fill(0, 1000, 'old error'), 's3SyncSuccesses' => array_fill(0, 1000, 'old success')], false);
    $settings->load();
    smarl_second_assert($settings->s3SyncStatus === 'failed' && $settings->syncState === 'default', 'Invalid processing state must become restartable.');
    smarl_second_assert(count($settings->s3SyncErrors) === 200 && count($settings->s3SyncSuccesses) === 500, 'Recovery must bound diagnostics.');
    $settings->save(); $settings->save();
    WP_CLI::success('SYNC audit: invalid progress recovery, bounded logs and idempotent persistence.');

    $settings->reset(); $settings->runId = 'native-running'; $settings->s3SyncDirection = 'upload';
    $settings->s3SyncStatus = 'processing'; $settings->syncState = 'syncing'; $settings->save();
    as_schedule_single_action(time() + 600, 'process_s3_sync_batch', [1, 'upload', 0, 'native-running', 1], 'smartsync');
    $handler = new SMARL_AjaxHandler(SMARL_CloudConfig::load());
    add_filter('pre_update_option_smarl_sync_settings', $denyWrite, 10, 2);
    $_POST = ['nonce' => wp_create_nonce('smarl_nonce')]; $_REQUEST = $_POST;
    ob_start();
    try { (new Fiber(static fn() => $handler->stopAllProcesses()))->start(); $body = ob_get_contents(); }
    finally { ob_end_clean(); remove_filter('pre_update_option_smarl_sync_settings', $denyWrite); }
    $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    smarl_second_assert($response['success'] === false, 'Stop must report a failed durable cancellation.');
    smarl_second_assert(get_option('smarl_sync_settings')['s3SyncStatus'] === 'processing', 'Rejected option writes must not be mistaken for persistence.');
    smarl_second_assert(!as_has_scheduled_action('process_s3_sync_batch', null, 'smartsync'), 'Stop must cancel pending jobs even when persistence fails.');
    WP_CLI::success('SYNC audit: failed native option write reports failure and cancels scheduled jobs.');
} finally {
    if ($migrationFilter) remove_filter('query', $migrationFilter);
    remove_filter('pre_update_option_smarl_sync_settings', $denyWrite);
    as_unschedule_all_actions('process_s3_sync_batch');
    foreach ($pending as [$when, $args, $group]) as_schedule_single_action($when, 'process_s3_sync_batch', $args, $group);
    foreach ($saved as $name => $value) { if ($value === null) delete_option($name); else update_option($name, $value, false); }
}
