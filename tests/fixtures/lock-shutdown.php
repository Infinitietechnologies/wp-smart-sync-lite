<?php
// Standalone process: no WordPress installation, HTTP or cloud access.
require dirname(__DIR__) . '/bootstrap.php';
use SmartSyncS3\Helpers\SMARL_Helper;
if (!isset($argv[1], $argv[2]) || !str_contains(str_replace('\\', '/', $argv[1]), '/smartsync-tests-')) exit(2);
$wpdb = $GLOBALS['wpdb'];
$wpdb->pdo = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$wpdb->pdo->exec('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)');
if ($argv[2] === 'fatal-handler') {
    register_shutdown_function(static function () {
        // Model WordPress's earlier fatal handler exiting before later callbacks.
        apply_filters('wp_php_error_message', 'Fixture fatal error');
        exit(255);
    });
}
$token = SMARL_Helper::acquireSyncLock();
if (!is_string($token)) exit(3);
if ($argv[2] === 'successor') update_option('smarl_sync_lock', ['token' => 'another-request', 'expires' => time() + 60], false);
if ($argv[2] === 'switched-blog') {
    $wpdb->pdo->exec('CREATE TABLE wp_2_options AS SELECT * FROM wp_options WHERE 0');
    $wpdb->insert('wp_2_options', ['option_name' => 'smarl_sync_lock', 'option_value' => serialize(['token' => 'another-blog-request', 'expires' => time() + 60])]);
    $wpdb->options = 'wp_2_options';
}
if (in_array($argv[2], ['fatal', 'fatal-handler'], true)) trigger_error('Fixture interrupted worker', E_USER_ERROR);
// A native exit deliberately bypasses application finally blocks.
exit(0);
