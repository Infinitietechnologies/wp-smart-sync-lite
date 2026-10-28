<?php
require dirname(__DIR__) . '/bootstrap.php';
use SmartSyncS3\Helpers\SMARL_Helper;
$database = getenv('SMARTSYNC_LOCK_FIXTURE_DATABASE');
if (!$database || !str_contains(str_replace('\\', '/', $database), '/smartsync-tests-')) exit(2);
$GLOBALS['wpdb']->pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$GLOBALS['wpdb']->pdo->exec('CREATE TABLE IF NOT EXISTS wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)');
$mode = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
header('Content-Type: application/json');
if ($mode === 'abandon') {
    // Simulate an earlier shutdown callback exiting before request-lock cleanup.
    register_shutdown_function(static function () { exit; });
    $token = SMARL_Helper::acquireSyncLock(900);
    echo json_encode(['acquired' => is_string($token), 'owned_busy' => SMARL_Helper::syncLockStatus()['busy']]);
} elseif ($mode === 'foreign') {
    update_option('smarl_sync_lock', ['token' => 'other-worker', 'expires' => time() + 900, 'server_pid' => getmypid() + 1], false);
    echo json_encode(SMARL_Helper::syncLockStatus());
} elseif ($mode === 'acquire') {
    echo json_encode(['acquired' => is_string(SMARL_Helper::acquireSyncLock())]);
} else echo json_encode(SMARL_Helper::syncLockStatus());
