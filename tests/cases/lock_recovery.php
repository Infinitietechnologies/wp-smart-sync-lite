<?php
use SmartSyncS3\Helpers\SMARL_Helper;

smartsync_test('built-in server recovers previous-request leases without releasing its current or another worker lease', function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $database = ABSPATH . 'local-server-lock.sqlite';
    $log = ABSPATH . 'local-server-lock.log';
    $environment = getenv(); $environment['SMARTSYNC_LOCK_FIXTURE_DATABASE'] = $database;
    $process = proc_open([PHP_BINARY, '-S', $address, dirname(__DIR__) . '/fixtures/local-server-lock.php'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $environment);
    smartsync_assert_true(is_resource($process)); fclose($pipes[0]);
    try {
        $ready = false;
        for ($i = 0; $i < 100; $i++) {
            $connection = @stream_socket_client('tcp://' . $address, $errorNumber, $errorMessage, .1);
            if ($connection) { fclose($connection); $ready = true; break; }
            usleep(20000);
        }
        smartsync_assert_true($ready, 'Fixture server did not start');
        $request = static fn(string $mode): array => json_decode(file_get_contents('http://' . $address . '/' . $mode), true, 512, JSON_THROW_ON_ERROR);
        smartsync_assert_same(['acquired' => true, 'owned_busy' => true], $request('abandon'));
        smartsync_assert_same(['busy' => false, 'retry_after' => 0], $request('status'));
        smartsync_assert_same(['acquired' => true, 'owned_busy' => true], $request('abandon'));
        smartsync_assert_same(['acquired' => true], $request('acquire'));
        smartsync_assert_same(true, $request('foreign')['busy']);
        smartsync_assert_same(true, $request('status')['busy']);
    } finally { proc_terminate($process); proc_close($process); }
});

foreach (['exit', 'fatal', 'fatal-handler', 'successor', 'switched-blog'] as $mode) {
    smartsync_test('request shutdown releases only its own original-blog lease: ' . $mode, function () use ($mode) {
        $database = ABSPATH . 'shutdown-lock.sqlite';
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', dirname(__DIR__) . '/fixtures/lock-shutdown.php', $database, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        smartsync_assert_true(is_resource($process));
        fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($process);
        smartsync_assert_same(str_starts_with($mode, 'fatal') ? 255 : 0, $exit);
        if (str_starts_with($mode, 'fatal')) smartsync_assert_true(str_contains($error, 'Fixture interrupted worker'));
        $pdo = new PDO('sqlite:' . $database);
        $lock = $pdo->query("SELECT option_value FROM wp_options WHERE option_name = 'smarl_sync_lock'")->fetchColumn();
        if ($mode === 'successor') smartsync_assert_same('another-request', unserialize($lock)['token']);
        else smartsync_assert_same(false, $lock);
        if ($mode === 'switched-blog') {
            $other = $pdo->query("SELECT option_value FROM wp_2_options WHERE option_name = 'smarl_sync_lock'")->fetchColumn();
            smartsync_assert_same('another-blog-request', unserialize($other)['token']);
        }
        $pdo = null;
    });
}

smartsync_test('idle progress reports a live shared operation lease without exposing or clearing it', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    $token = SMARL_Helper::acquireSyncLock(60);
    $data = smartsync_capture_json(fn() => $handler->checkSyncStatus())['data'];
    smartsync_assert_same('idle', $data['status']);
    smartsync_assert_same(true, $data['operation_busy']);
    smartsync_assert_true($data['retry_after'] > 0 && $data['retry_after'] <= 60);
    smartsync_assert_true(!str_contains(json_encode($data), $token));
    smartsync_assert_same($token, get_option('smarl_sync_lock')['token']);
    SMARL_Helper::releaseSyncLock($token);
    $data = smartsync_capture_json(fn() => $handler->checkSyncStatus())['data'];
    smartsync_assert_same(false, $data['operation_busy']); smartsync_assert_same(0, $data['retry_after']);
    update_option('smarl_sync_lock', ['token' => 'expired-request', 'expires' => time() - 1], false);
    smartsync_assert_same(['busy' => false, 'retry_after' => 0], SMARL_Helper::syncLockStatus());
});

smartsync_test('stopping during a file transfer reports finishing until the owning worker returns', function () {
    [$storage, $handler] = smartsync_sync_fixture();
    file_put_contents(wp_upload_dir()['basedir'] . '/finishing.jpg', 'fixture');
    $_POST = ['type' => 'media']; smartsync_capture_json(fn() => $handler->syncLocalToAws());
    $storage->duringTransfer = function () use ($handler) {
        smartsync_capture_json(fn() => $handler->stopAllProcesses());
        $data = smartsync_capture_json(fn() => $handler->checkSyncStatus())['data'];
        smartsync_assert_same('idle', $data['status']);
        smartsync_assert_same(true, $data['operation_busy']);
    };
    smartsync_run_jobs($handler);
    smartsync_assert_same(false, SMARL_Helper::syncLockStatus()['busy']);
});
