<?php
// Isolated loopback fixture; no WordPress bootstrap, credentials, storage, or cloud calls.
if (PHP_SAPI !== 'cli-server') exit(2);
header('Content-Type: application/json');
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/hold') {
    error_log('SMARTSYNC_STATUS_HOLD_STARTED');
    sleep(17);
    echo '{"ok":true}';
} elseif (($_POST['action'] ?? '') === 'check_sync_status') {
    echo json_encode(['success' => true, 'data' => ['status' => 'processing', 'run_id' => 'fixture-delayed-run', 'type' => 'media', 'direction' => 'upload', 'total_files' => 10, 'processed_files' => 2, 'operation_busy' => true]]);
} else echo '{}';
