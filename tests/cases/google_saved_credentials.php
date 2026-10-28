<?php
use SmartSyncS3\Ajax\SMARL_AwsConfigAjax;
use SmartSyncS3\Models\SMARL_CloudConfig;
use SmartSyncS3\Services\SMARLI_GoogleService;

smartsync_test('saved Google preview exposes account metadata but masks every non-public value', function () {
    $credentials = array_replace(smartsync_audit2_credentials(), ['private_key_id' => 'fixture-private-key-id', 'client_id' => 'fixture-client-id',
        'extra_sensitive' => ['secret' => 'fixture-nested-secret'], 'unexpected_field' => 'fixture-unknown-secret']);
    $preview = SMARLI_GoogleService::redactedPreview($credentials);
    smartsync_assert_same('fixture-project', $preview['project_id']);
    smartsync_assert_same($credentials['client_email'], $preview['client_email']);
    foreach (['private_key', 'private_key_id', 'client_id', 'extra_sensitive', 'unexpected_field'] as $field) smartsync_assert_same('[Hidden]', $preview[$field]);
});

smartsync_test('explicit Google credential reveal reads only the active encrypted key and prevents cached or stale-account responses', function () {
    $original = ABSPATH . 'saved-google-preview.json';
    $credentials = array_replace(smartsync_audit2_credentials(), ['private_key_id' => 'fixture-key-id']);
    file_put_contents($original, json_encode($credentials));
    $private = SMARLI_GoogleService::migrateCredentials($original);
    $config = smartsync_provider_config('google'); $config->googleServicePath = $private; $config->save();
    $reference = SMARLI_GoogleService::credentialReference($private);
    $handler = new SMARL_AwsConfigAjax(null);
    try {
        $GLOBALS['smartsync_test_nocache'] = false;
        $_POST = ['credential_reference' => $reference];
        $response = smartsync_capture_json(fn() => $handler->getSavedGoogleCredentials());
        smartsync_assert_same(true, $response['success']);
        smartsync_assert_same($credentials, $response['data']['credentials']);
        smartsync_assert_same($reference, $response['data']['reference']);
        smartsync_assert_same(true, $GLOBALS['smartsync_test_nocache']);
        smartsync_assert_true(!str_contains(json_encode($response), $private));
        foreach (['', 'stale-account-reference', ['bad-reference']] as $submitted) {
            $_POST = ['credential_reference' => $submitted];
            $response = smartsync_capture_json(fn() => $handler->getSavedGoogleCredentials());
            smartsync_assert_same(409, $response['status']);
            smartsync_assert_true(!str_contains(json_encode($response), 'BEGIN PRIVATE KEY'));
        }
        $config->serviceProvider = 'aws'; $config->save();
        $_POST = ['credential_reference' => $reference];
        smartsync_assert_same(404, smartsync_capture_json(fn() => $handler->getSavedGoogleCredentials())['status']);
        smartsync_assert_same([], $GLOBALS['smartsync_test_http_requests']);
    } finally { SMARLI_GoogleService::deleteCredentialFile($private); }
});
