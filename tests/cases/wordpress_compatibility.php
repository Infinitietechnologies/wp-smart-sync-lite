<?php
use SmartSyncS3\Ajax\SMARL_AjaxHandler;

smartsync_test('WordPress compatibility defers legacy progress recovery until an authorized operational request', function () {
    $legacy = ['s3SyncStatus' => 'processing', 'runId' => '', 's3SyncDirection' => '', 'syncState' => 'syncing'];
    update_option('smarl_sync_settings', $legacy);
    $handler = new SMARL_AjaxHandler(smartsync_provider_config());
    smartsync_assert_same($legacy, get_option('smarl_sync_settings'), 'Bootstrap must not translate or persist the legacy-recovery diagnostic');
    $GLOBALS['smartsync_test_can_manage'] = false;
    smartsync_assert_same(403, smartsync_capture_json(fn() => $handler->checkSyncStatus())['status']);
    smartsync_assert_same($legacy, get_option('smarl_sync_settings'), 'Authorization must precede recovery work');
    $GLOBALS['smartsync_test_can_manage'] = true;
    $response = smartsync_capture_json(fn() => $handler->checkSyncStatus());
    smartsync_assert_same(true, $response['success']);
    smartsync_assert_same('failed', $response['data']['status']);
    smartsync_assert_same('An incomplete or older sync was interrupted. Start a new sync.', $response['data']['errors'][0]);
    smartsync_assert_same('failed', get_option('smarl_sync_settings')['s3SyncStatus']);
});

smartsync_test('WordPress compatibility refreshes switched-blog progress only when an operation requests it', function () {
    $handler = new SMARL_AjaxHandler(smartsync_provider_config());
    $legacy = ['s3SyncStatus' => 'processing', 'runId' => '', 's3SyncDirection' => '', 'syncState' => 'syncing'];
    update_option('smarl_sync_settings', $legacy);
    $handler->setConfiguration(smartsync_provider_config());
    smartsync_assert_same($legacy, get_option('smarl_sync_settings'), 'Changing callback configuration must not run an early translated migration');
    smartsync_assert_same('default', smartsync_capture_json(fn() => $handler->getSyncState())['data']);
    smartsync_assert_same('failed', get_option('smarl_sync_settings')['s3SyncStatus']);
});
