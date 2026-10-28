<?php
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Ajax\SMARL_AjaxHandler;

foreach (['recover', 'successor'] as $scenario) {
    smartsync_test('request guard preserves a live worker and recovers after hard process death: ' . $scenario, function () use ($scenario) {
        global $wpdb;
        $database = ABSPATH . 'guard-owner.sqlite';
        $wpdb->pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $wpdb->pdo->exec('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)');
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/lock-owner.php', $database], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        smartsync_assert_true(is_resource($process));
        try {
            smartsync_assert_same("ready\n", fgets($pipes[1]));
            $owner = get_option('smarl_sync_lock');
            smartsync_assert_true($owner['expires'] > time() + 800);
            smartsync_assert_same(true, SMARL_Helper::syncLockStatus()['busy']);
            smartsync_assert_same(null, SMARL_Helper::acquireSyncLock());
            $handler = new SMARL_AjaxHandler(null);
            $response = smartsync_capture_json(fn() => $handler->recoverSyncOperation());
            smartsync_assert_same(409, $response['status']);
            smartsync_assert_same($owner, get_option('smarl_sync_lock'));
            proc_terminate($process);
            foreach ($pipes as $pipe) fclose($pipe);
            proc_close($process); $process = null;
            if ($scenario === 'successor') {
                $replace = true;
                $GLOBALS['smartsync_test_sql_read_hook'] = static function (string $sql) use (&$replace): void {
                    if ($replace && str_contains($sql, 'smarl_sync_lock')) {
                        $replace = false;
                        update_option('smarl_sync_lock', ['token' => 'successor-owner', 'expires' => time() + 900]);
                    }
                };
                smartsync_assert_same(true, SMARL_Helper::syncLockStatus()['busy']);
                smartsync_assert_same('successor-owner', get_option('smarl_sync_lock')['token']);
            } else {
                $response = smartsync_capture_json(fn() => $handler->recoverSyncOperation());
                smartsync_assert_same(true, $response['success']);
                smartsync_assert_same(false, get_option('smarl_sync_lock'));
                $token = SMARL_Helper::acquireSyncLock();
                smartsync_assert_true(is_string($token));
                SMARL_Helper::releaseSyncLock($token);
            }
        } finally {
            $GLOBALS['smartsync_test_sql_read_hook'] = null;
            if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); }
            // Windows cannot remove a SQLite file while the parent connection is open.
            $wpdb = new SmartSyncTestDatabase();
            if (isset($owner)) @unlink(rtrim(sys_get_temp_dir(), '/\\') . '/smartsync-request-locks/' . $owner['token'] . '.lock');
        }
    });
}

smartsync_test('guard recovery rejects foreign hosts and missing proof and enforces administrator authorization', function () {
    $handler = new SMARL_AjaxHandler(null);
    foreach (['foreign-host', hash('sha256', php_uname('n') . '|' . str_replace('\\', '/', sys_get_temp_dir()))] as $host) {
        $lease = ['token' => bin2hex(random_bytes(16)), 'expires' => time() + 900, 'request_guard' => $host];
        update_option('smarl_sync_lock', $lease);
        smartsync_assert_same(true, SMARL_Helper::syncLockStatus()['busy']);
        smartsync_assert_same(false, smartsync_capture_json(fn() => $handler->recoverSyncOperation())['success']);
        smartsync_assert_same($lease, get_option('smarl_sync_lock'));
    }
    $GLOBALS['smartsync_test_can_manage'] = false;
    smartsync_assert_same(403, smartsync_capture_json(fn() => $handler->recoverSyncOperation())['status']);
    $GLOBALS['smartsync_test_can_manage'] = true;
    $GLOBALS['smartsync_test_valid_nonce'] = false;
    smartsync_assert_same(403, smartsync_capture_json(fn() => $handler->recoverSyncOperation())['status']);
});

smartsync_test('recovery refuses a processing run without resetting its progress or queued jobs', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    file_put_contents(wp_upload_dir()['basedir'] . '/guard.png', 'guard');
    $_POST = ['type' => 'media'];
    smartsync_assert_true(smartsync_capture_json(fn() => $handler->syncLocalToAws())['success']);
    $progress = get_option('smarl_sync_settings'); $jobs = $GLOBALS['smartsync_test_jobs'];
    smartsync_assert_same(409, smartsync_capture_json(fn() => $handler->recoverSyncOperation())['status']);
    smartsync_assert_same($progress, get_option('smarl_sync_settings'));
    smartsync_assert_same($jobs, $GLOBALS['smartsync_test_jobs']);
});
