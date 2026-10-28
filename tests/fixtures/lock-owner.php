<?php
// A real process owns a real filesystem lock. No installed WordPress or cloud access.
require dirname(__DIR__) . '/bootstrap.php';
if (!isset($argv[1]) || !str_contains(str_replace('\\', '/', $argv[1]), '/smartsync-tests-')) exit(2);
$GLOBALS['wpdb']->pdo = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$token = \SmartSyncS3\Helpers\SMARL_Helper::acquireSyncLock();
if (!is_string($token) || empty(get_option('smarl_sync_lock')['request_guard'])) exit(3);
echo "ready\n";
flush();
// The parent kills this process to bypass every PHP shutdown handler.
fgets(STDIN);
