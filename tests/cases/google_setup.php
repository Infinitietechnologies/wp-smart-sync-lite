<?php
use SmartSyncS3\Ajax\SMARL_AwsConfigAjax;
use SmartSyncS3\Models\SMARL_CloudConfig;

foreach ([
    ['metadata', 403, 'private-provider-body fixture-secret', 'storage.buckets.get'],
    ['read IAM', 403, 'private-provider-body fixture-secret', 'storage.buckets.getIamPolicy'],
    ['write IAM', 403, 'private-provider-body fixture-secret', 'storage.buckets.setIamPolicy'],
    ['write IAM', 412, 'Public access prevention is enforced fixture-secret', 'Public access prevention'],
    ['write IAM', 400, 'constraints/iam.allowedPolicyMemberDomains fixture-secret', 'organization policy'],
    ['metadata', 401, 'private-provider-body fixture-secret', 'credentials'],
    ['enforced prevention', 200, '', 'Public access prevention'],
] as [$stage, $code, $providerMessage, $expected]) {
    smartsync_test('Google save explains its failed setup stage without changing saved configuration: ' . $stage . ' ' . $code, function () use ($stage, $code, $providerMessage, $expected) {
        $previous = smartsync_provider_config('wasabi'); $previous->save();
        $credentialPath = ABSPATH . 'google-setup-fixture.json';
        file_put_contents($credentialPath, json_encode(smartsync_audit2_credentials()));
        set_transient('smarl_google_credentials_1', $credentialPath, 3600);
        $config = smartsync_provider_config('google'); $writes = 0;
        $config->fakeGoogle = new \Google\Cloud\Storage\StorageClient([
            'projectId' => 'fixture-project', 'credentialsFetcher' => new \Google\Cloud\Core\AnonymousCredentials(),
            'httpHandler' => function ($request) use ($stage, $code, $providerMessage, &$writes) {
                $isIam = str_contains($request->getUri()->getPath(), '/iam');
                $isWrite = $request->getMethod() === 'PUT';
                if ($isWrite) $writes++;
                $fail = ($stage === 'metadata' && !$isIam) || ($stage === 'read IAM' && $isIam && !$isWrite) || ($stage === 'write IAM' && $isWrite);
                if ($fail) throw \GuzzleHttp\Exception\RequestException::create($request,
                    new \GuzzleHttp\Psr7\Response($code, ['Content-Type' => 'application/json'], json_encode(['error' => ['code' => $code, 'message' => $providerMessage]])));
                $data = $isIam ? ['version' => 3, 'etag' => 'fixture', 'bindings' => []]
                    : ['name' => 'fixture-bucket', 'location' => 'US', 'iamConfiguration' => ['publicAccessPrevention' => $stage === 'enforced prevention' ? 'enforced' : 'inherited']];
                return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($data));
            },
        ]);
        try {
            $ajax = new SmartSyncProviderAjax(null); $ajax->nextConfig = $config;
            $_POST = ['service_provider' => 'google', 'bucket_type' => 'existing', 'bucket_name' => 'fixture-bucket', 'bucket_region' => 'US', 'use_cloudfront' => 'false'];
            $response = smartsync_capture_json(fn() => $ajax->configureAws());
            smartsync_assert_same(false, $response['success']);
            smartsync_assert_true(str_contains($response['data'], $expected));
            foreach (['fixture-secret', 'private-provider-body', $credentialPath] as $private) smartsync_assert_true(!str_contains($response['data'], $private));
            smartsync_assert_same($previous->toArray(), SMARL_CloudConfig::load()->toArray());
            if ($stage !== 'write IAM') smartsync_assert_same(0, $writes);
        } finally { unlink($credentialPath); }
    });
}
