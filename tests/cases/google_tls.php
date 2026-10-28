<?php
use SmartSyncS3\Helpers\SMARL_Helper;
use SmartSyncS3\Services\SMARLI_GoogleService;

smartsync_test('Google OAuth and Storage requests share verified WordPress CA transport', function () {
    $bundle = ABSPATH . 'wp-includes/certificates/ca-bundle.crt';
    if (!is_dir(dirname($bundle))) mkdir(dirname($bundle), 0777, true);
    file_put_contents($bundle, 'fixture CA bundle; all requests intercepted');
    $path = ABSPATH . 'google-tls-fixture.json';
    file_put_contents($path, json_encode(smartsync_audit2_credentials(true)));
    try {
        $service = new SMARLI_GoogleService($path);
        $connection = (new ReflectionProperty($service->storage, 'connection'))->getValue($service->storage);
        $wrapper = $connection->requestWrapper();
        $handler = (new ReflectionProperty($wrapper, 'httpHandler'))->getValue($wrapper);
        smartsync_assert_same($handler, (new ReflectionProperty($wrapper, 'authHttpHandler'))->getValue($wrapper));
        $client = (new ReflectionProperty(\Google\Auth\HttpHandler\Guzzle6HttpHandler::class, 'client'))->getValue($handler);
        $expectedVerify = SMARL_Helper::cloudHttpOptions()['verify'] ?? true;
        $requests = [];
        $client->getConfig('handler')->setHandler(function ($request, $options) use (&$requests, $expectedVerify) {
            $host = $request->getUri()->getHost();
            $requests[] = $host;
            smartsync_assert_same($expectedVerify, $options['verify']);
            smartsync_assert_true($options['verify'] !== false);
            smartsync_assert_same(10, $options['connect_timeout']);
            smartsync_assert_true($options['timeout'] > 0 && $options['timeout'] <= 120);
            if ($host === 'oauth2.googleapis.com') {
                smartsync_assert_same('POST', $request->getMethod());
                $body = ['access_token' => 'fixture-token', 'expires_in' => 3600, 'token_type' => 'Bearer'];
            } else {
                smartsync_assert_same('storage.googleapis.com', $host);
                smartsync_assert_same('Bearer fixture-token', $request->getHeaderLine('Authorization'));
                $body = ['items' => [['name' => 'fixture-bucket', 'location' => 'EU']]];
            }
            return new \GuzzleHttp\Promise\FulfilledPromise(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($body)));
        });
        smartsync_assert_same([['name' => 'fixture-bucket', 'region' => 'EU']], $service->getBuckets());
        smartsync_assert_same(['oauth2.googleapis.com', 'storage.googleapis.com'], $requests);
    } finally { unlink($path); }
});

smartsync_test('Google transport preserves explicit server trust configuration', function () {
    $original = getenv('SSL_CERT_FILE');
    $path = ABSPATH . 'google-explicit-tls-fixture.json';
    file_put_contents($path, json_encode(smartsync_audit2_credentials()));
    try {
        putenv('SSL_CERT_FILE=fixture-explicit-ca');
        $service = new SMARLI_GoogleService($path);
        $connection = (new ReflectionProperty($service->storage, 'connection'))->getValue($service->storage);
        $wrapper = $connection->requestWrapper();
        $handler = (new ReflectionProperty($wrapper, 'httpHandler'))->getValue($wrapper);
        $client = (new ReflectionProperty(\Google\Auth\HttpHandler\Guzzle6HttpHandler::class, 'client'))->getValue($handler);
        smartsync_assert_same(true, $client->getConfig('verify'));
        smartsync_assert_true(!isset(SMARL_Helper::cloudHttpOptions()['verify']));
    } finally {
        putenv($original === false ? 'SSL_CERT_FILE' : 'SSL_CERT_FILE=' . $original);
        unlink($path);
    }
});

smartsync_test('missing WordPress CA bundle never disables SDK certificate verification', function () {
    $bundle = ABSPATH . 'wp-includes/certificates/ca-bundle.crt';
    if (is_file($bundle)) unlink($bundle);
    smartsync_assert_true(!isset(SMARL_Helper::cloudHttpOptions()['verify']));
});
