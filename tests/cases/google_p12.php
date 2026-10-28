<?php
use SmartSyncS3\Services\SMARLI_GoogleService;

/** Generate a disposable certificate/key pair. These credentials cannot authenticate to Google. */
function smartsync_p12_fixture(string $password = 'notasecret', bool $rsa = true): array {
    $config = ABSPATH . 'p12-openssl.cnf';
    file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n");
    $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => $rsa ? OPENSSL_KEYTYPE_RSA : OPENSSL_KEYTYPE_EC];
    if ($rsa) $options['private_key_bits'] = 2048; else $options['curve_name'] = 'prime256v1';
    try {
        $key = openssl_pkey_new($options);
        smartsync_assert_true($key !== false);
        $csr = openssl_csr_new(['commonName' => 'disposable-fixture'], $key, $options);
        smartsync_assert_true($csr !== false);
        $cert = openssl_csr_sign($csr, null, $key, 1, $options);
        smartsync_assert_true($cert !== false);
        smartsync_assert_true(openssl_pkcs12_export($cert, $raw, $key, $password));
        return [$raw, openssl_pkey_get_details($key)['key']];
    } finally { unlink($config); }
}

function smartsync_p12_upload(string $raw): string {
    $path = ABSPATH . 'google-p12-upload.p12'; file_put_contents($path, $raw);
    $GLOBALS['smartsync_test_uploaded_files'][$path] = true;
    $_FILES = ['service_json' => ['name' => 'service-account.P12', 'tmp_name' => $path, 'size' => strlen($raw), 'error' => UPLOAD_ERR_OK]];
    $_POST = ['google_p12_email' => 'media@fixture-project.iam.gserviceaccount.com', 'google_p12_project' => 'fixture-project'];
    return $path;
}

foreach (['notasecret', 'custom p12 password'] as $password) {
    smartsync_test('P12 uploads become encrypted reusable credentials with a bound selection: ' . ($password === 'notasecret' ? 'default' : 'custom') . ' password', function () use ($password) {
        [$raw, $public] = smartsync_p12_fixture($password);
        $upload = smartsync_p12_upload($raw);
        if ($password !== 'notasecret') $_POST['google_p12_password'] = $password;
        try {
            $selection = SMARLI_GoogleService::moveFileTemp(true);
            $path = $selection['path'];
            smartsync_assert_same($path, SMARLI_GoogleService::getTempPath($selection['selectionToken']));
            $stored = file_get_contents($path);
            smartsync_assert_true(!str_contains($stored, 'PRIVATE KEY') && !str_contains($stored, $password) && !str_contains($stored, 'fixture-project'));
            smartsync_assert_same(1, json_decode($stored, true)['smartsync_encrypted']);
            $credentials = SMARLI_GoogleService::getServiceJSON($path);
            smartsync_assert_same('fixture-project', $credentials['project_id']);
            smartsync_assert_same($_POST['google_p12_email'], $credentials['client_email']);
            smartsync_assert_same('https://oauth2.googleapis.com/token', $credentials['token_uri']);
            smartsync_assert_true(!isset($credentials['password']));
            smartsync_assert_true(openssl_sign('fixture-jwt-body', $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256));
            smartsync_assert_same(1, openssl_verify('fixture-jwt-body', $signature, $public, OPENSSL_ALGO_SHA256));
            new SMARLI_GoogleService($path);
            $_FILES = []; $_POST = [];
            smartsync_assert_same([], SMARLI_GoogleService::moveFileTemp(true), 'Saved credentials do not require another P12/password upload');
            smartsync_assert_same('fixture-project', SMARLI_GoogleService::getServiceJSON($path)['project_id']);
            SMARLI_GoogleService::deleteCredentialFile($path);
        } finally { unlink($upload); }
    });
}

smartsync_test('P12 missing or malformed account metadata fails before credential storage', function () {
    [$raw] = smartsync_p12_fixture(); $upload = smartsync_p12_upload($raw);
    try {
        foreach ([['google_p12_email', ''], ['google_p12_email', ['invalid']], ['google_p12_project', '123456789'], ['google_p12_project', 'Project display name'], ['google_p12_password', ['invalid']]] as [$field, $value]) {
            $original = $_POST; $_POST[$field] = $value;
            smartsync_assert_throws(fn() => SMARLI_GoogleService::moveFileTemp(true), RuntimeException::class);
            smartsync_assert_same([], get_transient('smarl_google_selections_' . get_current_user_id()) ?: []);
            $_POST = $original;
        }
    } finally { unlink($upload); }
});

smartsync_test('wrong P12 password and corrupt binary fail safely without leaking input or storing credentials', function () {
    [$raw] = smartsync_p12_fixture('private-fixture-password'); $upload = smartsync_p12_upload($raw);
    try {
        $_POST['google_p12_password'] = 'wrong-private-password';
        try { SMARLI_GoogleService::moveFileTemp(true); throw new LogicException('Expected password failure'); }
        catch (RuntimeException $error) {
            smartsync_assert_true(str_contains($error->getMessage(), 'password'));
            smartsync_assert_true(!str_contains($error->getMessage(), 'wrong-private-password') && !str_contains($error->getMessage(), $upload));
        }
        file_put_contents($upload, 'not a P12 file');
        smartsync_assert_throws(fn() => SMARLI_GoogleService::moveFileTemp(true), RuntimeException::class);
        smartsync_assert_same(false, get_transient('smarl_google_credentials_' . get_current_user_id()));
    } finally { unlink($upload); }
});

smartsync_test('P12 rejects a non-RSA key and actual oversized upload even when reported size is small', function () {
    [$raw] = smartsync_p12_fixture('notasecret', false); $upload = smartsync_p12_upload($raw);
    try {
        smartsync_assert_throws(fn() => SMARLI_GoogleService::moveFileTemp(true), RuntimeException::class);
        file_put_contents($upload, str_repeat('x', 1048577)); clearstatcache(true, $upload);
        $_FILES['service_json']['size'] = 100;
        smartsync_assert_throws(fn() => SMARLI_GoogleService::moveFileTemp(true), RuntimeException::class);
    } finally { unlink($upload); }
});
