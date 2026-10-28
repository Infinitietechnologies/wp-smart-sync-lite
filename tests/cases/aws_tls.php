<?php
use SmartSyncS3\Models\SMARL_CloudConfig;

smartsync_test('SDK clients use WordPress CA bundle without disabling verification and honor explicit trust configuration', function () {
    $bundle = ABSPATH . 'wp-includes/certificates/ca-bundle.crt';
    mkdir(dirname($bundle), 0777, true); file_put_contents($bundle, 'fixture CA bundle; no network');
    $config = new SMARL_CloudConfig(); $config->serviceProvider = 'aws'; $config->region = 'eu-north-1';
    $config->iamKey = 'fixture-key'; $config->iamSecret = 'fixture-secret';
    $unconfigured = !ini_get('curl.cainfo') && !ini_get('openssl.cafile') && !ini_get('openssl.capath') && !getenv('SSL_CERT_FILE') && !getenv('SSL_CERT_DIR');
    foreach ([[$config->getS3Client(), 'ListBuckets'], [$config->getCloudfrontClient(), 'ListDistributions'], [$config->getStsClient(), 'GetCallerIdentity'], [$config->getAcmClient(), 'ListCertificates']] as [$client, $operation]) {
        $http = $client->getCommand($operation)['@http'];
        smartsync_assert_same($unconfigured ? $bundle : null, $http['verify'] ?? null);
        smartsync_assert_true(($http['verify'] ?? true) !== false);
    }
    $original = getenv('SSL_CERT_FILE');
    try {
        putenv('SSL_CERT_FILE=fixture-explicit-ca');
        smartsync_assert_true(!isset($config->getS3Client()->getCommand('ListBuckets')['@http']['verify']));
    } finally { putenv($original === false ? 'SSL_CERT_FILE' : 'SSL_CERT_FILE=' . $original); }
});

foreach ([60 => 'TLS certificate', 6 => 'DNS', 7 => 'outbound HTTPS', 28 => 'timed out'] as $errno => $expected) {
    smartsync_test('discovery exposes a safe transport category without credentials: ' . $errno, function () use ($errno, $expected) {
        $config = smartsync_provider_config();
        $config->fakeS3 = smartsync_provider_s3(static function () use ($errno) {
            throw new RuntimeException('private-provider-body fixture-secret', 0, new RuntimeException('cURL error ' . $errno . ': signed-private-url fixture-key'));
        });
        $handler = new SmartSyncProviderAjax($config); $handler->nextConfig = $config;
        $_POST = ['service_provider' => 'aws', 'iam_key' => 'fixture-key', 'iam_secret' => 'fixture-secret', 'bucket_region' => 'eu-north-1'];
        $result = smartsync_capture_json(fn() => $handler->listS3Buckets());
        smartsync_assert_same(false, $result['success']);
        smartsync_assert_true(str_contains($result['data'], $expected));
        foreach (['fixture-secret', 'fixture-key', 'signed-private-url', 'private-provider-body'] as $private) smartsync_assert_true(!str_contains($result['data'], $private));
    });
}
