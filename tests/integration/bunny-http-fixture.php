<?php
/**
 * Test-only Bunny HTTP fixtures for a native WordPress admin AJAX/browser run.
 * A disposable site's temporary MU wrapper must explicitly opt in.
 */
if (!defined('ABSPATH') || !defined('SMARTSYNC_BUNNY_HTTP_FIXTURE') || SMARTSYNC_BUNNY_HTTP_FIXTURE !== true
    || !str_contains(str_replace('\\', '/', ABSPATH), '/smartsync-wordpress-audit-fixture/wordpress/')) {
    return;
}

add_filter('pre_http_request', static function ($pre, $args, $url) {
    $parts = wp_parse_url($url);
    $host = strtolower($parts['host'] ?? '');
    if (!in_array($host, ['api.bunny.net', 'la.storage.bunnycdn.com'], true)) return $pre;

    $headers = $args['headers'] ?? [];
    $accessKey = '';
    foreach ($headers as $name => $value) {
        if (strtolower((string) $name) === 'accesskey') $accessKey = (string) $value;
    }
    $path = $parts['path'] ?? '/';
    $calls = get_option('smarl_bunny_browser_http_calls', []);
    if (!is_array($calls)) $calls = [];
    $calls[] = ['host' => $host, 'path' => $path, 'query' => $parts['query'] ?? '', 'method' => $args['method'] ?? 'GET',
        'credential' => $host === 'api.bunny.net'
            ? ($accessKey === 'fixture-bunny-account-key' ? 'account-valid' : 'account-invalid')
            : ($accessKey === 'fixture-bunny-storage-password' ? 'storage-valid' : 'storage-invalid')];
    update_option('smarl_bunny_browser_http_calls', array_slice($calls, -100), false);

    $respond = static fn(int $status, array $data): array => [
        'headers' => ['content-type' => 'application/json'],
        'body' => wp_json_encode($data), 'response' => ['code' => $status, 'message' => $status === 200 ? 'OK' : 'Unauthorized'],
        'cookies' => [], 'filename' => null,
    ];
    if ($host === 'api.bunny.net') {
        if ($accessKey !== 'fixture-bunny-account-key') return $respond(401, ['Message' => 'Invalid fixture account key.']);
        if ($path === '/storagezone') {
            parse_str($parts['query'] ?? '', $query);
            $page = (int) ($query['page'] ?? 0);
            if ($page === 1) return $respond(200, [
                'Items' => [['Id' => 701, 'Name' => 'fixture-browser-zone', 'Region' => 'US', 'StorageHostname' => 'la.storage.bunnycdn.com']],
                'CurrentPage' => 1, 'TotalItems' => 2, 'HasMoreItems' => true,
            ]);
            if ($page === 2) return $respond(200, [
                'Items' => [['Id' => 702, 'Name' => 'fixture-no-public-zone', 'Region' => 'DE', 'Password' => 'fixture-unusable-password',
                    'StorageHostname' => 'storage.bunnycdn.com', 'PullZones' => [['Enabled' => true, 'ZoneSecurityEnabled' => true,
                        'Hostnames' => [['Value' => 'token-only.b-cdn.net', 'IsSystemHostname' => true, 'HasCertificate' => true]]]]]],
                'CurrentPage' => 2, 'TotalItems' => 2, 'HasMoreItems' => false,
            ]);
            return $respond(400, ['Message' => 'Unexpected fixture account page.']);
        }
        if ($path === '/storagezone/701') return $respond(200, [
            'Id' => 701, 'Name' => 'fixture-browser-zone', 'Region' => 'US', 'StorageHostname' => 'la.storage.bunnycdn.com',
            'Password' => 'fixture-bunny-storage-password',
            'PullZones' => [
                ['Enabled' => true, 'Suspended' => false, 'ZoneSecurityEnabled' => false, 'Hostnames' => [
                    ['Value' => 'unissued.example.test', 'IsSystemHostname' => false, 'HasCertificate' => false],
                    ['Value' => 'ready.example.test', 'IsSystemHostname' => false, 'HasCertificate' => true],
                    ['Value' => 'fixture-browser-zone.b-cdn.net', 'IsSystemHostname' => true, 'HasCertificate' => true],
                ]],
                ['Enabled' => false, 'Hostnames' => [['Value' => 'disabled.b-cdn.net', 'IsSystemHostname' => true]]],
                ['Enabled' => true, 'Suspended' => true, 'Hostnames' => [['Value' => 'suspended.b-cdn.net', 'IsSystemHostname' => true]]],
                ['Enabled' => true, 'ZoneSecurityEnabled' => true, 'Hostnames' => [['Value' => 'token.b-cdn.net', 'IsSystemHostname' => true]]],
            ],
        ]);
        return $respond(404, ['Message' => 'Unknown fixture account route.']);
    }
    if ($accessKey !== 'fixture-bunny-storage-password') return $respond(401, ['Message' => 'Invalid fixture storage password.']);
    if (($args['method'] ?? 'GET') !== 'GET') return $respond(405, ['Message' => 'Only connection/listing reads are supported by this browser fixture.']);
    if ($path === '/fixture-browser-zone/') return $respond(200, [
        ['ObjectName' => 'readme.txt', 'IsDirectory' => false, 'Length' => 12],
        ['ObjectName' => 'assets', 'IsDirectory' => true, 'Length' => 0],
    ]);
    if ($path === '/fixture-browser-zone/assets/') return $respond(200, [
        ['ObjectName' => 'fixture.css', 'IsDirectory' => false, 'Length' => 5],
    ]);
    return $respond(404, ['Message' => 'Unknown fixture storage route.']);
}, PHP_INT_MAX, 3);
