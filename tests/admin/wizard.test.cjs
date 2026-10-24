const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, render } = require('./harness.cjs');
const credentials = { iam_key: 'fixture-key', iam_secret: 'fixture-secret' };

async function providerApp(provider, extra = {}) {
    return boot('wizard', { data: { ...credentials, service_provider: provider, ...extra } });
}

test('AWS keeps the CloudFront choice and submits the nonce and structured bucket selection', async t => {
    const app = await providerApp('aws');
    t.after(() => app.close());
    app.$('#step-1-next').trigger('click');
    const listing = app.latest('list_s3_buckets');
    assert.equal(listing.options.data.get('nonce'), 'fixture-nonce');
    listing.resolve({ buckets: [{ name: 'fixture-bucket', region: 'us-east-1' }] });
    app.$('#step-2-next').trigger('click');
    assert.equal(app.latest('configure_aws'), undefined);
    assert.equal(app.$('#wizard-step-3').prop('hidden'), false);
    app.$('#use-cloudfront-yes').trigger('click');
    const saving = app.latest('configure_aws');
    assert.equal(saving.options.data.use_cloudfront, true);
    assert.equal(saving.options.data.bucket_name, 'fixture-bucket');
    assert.equal(saving.options.data.bucket_region, 'us-east-1');
    assert.equal(saving.options.data.nonce, 'fixture-nonce');
    app.$('#use-cloudfront-yes').trigger('click');
    assert.equal(app.requests.filter(request => request.action === 'configure_aws').length, 1);
    saving.resolve();
    assert.equal(app.$('#wizard-step-final').prop('hidden'), false);
    assert.match(app.$('#complete-setup').attr('href'), /page=smarl-smart-sync/);
});

test('all six providers are available; Wasabi and Cloudflare can create buckets without CloudFront', async t => {
    const app = await providerApp('wasabi');
    t.after(() => app.close());
    assert.deepEqual(app.$('#service_provider option').toArray().map(option => option.value).sort(), ['aws', 'bunny', 'cloudflare', 'digitalocean', 'google', 'wasabi']);
    app.$('#step-1-next').trigger('click');
    assert.equal(app.latest('list_s3_buckets').options.data.get('bucket_region'), 'us-east-1');
    app.latest('list_s3_buckets').resolve({ buckets: [] });
    app.$('input[name="bucket_type"][value="new"]').prop('checked', true).trigger('change');
    app.$('#new_bucket_name').val('fixture-new-bucket');
    app.$('#wasabi_bucket_region').val('us-east-1');
    app.$('#step-2-next').trigger('click');
    assert.equal(app.latest('configure_aws').options.data.bucket_region, 'us-east-1');
    assert.equal(app.latest('configure_aws').options.data.use_cloudfront, false);
});

test('Google file is required and appended exactly once; plain object names are used without a fake region', async t => {
    const app = await providerApp('google');
    t.after(() => app.close());
    app.$('#step-1-next').trigger('click');
    assert.equal(app.requests.length, 0);
    const file = new app.window.File(['{}'], 'fixture.json', { type: 'application/json' });
    Object.defineProperty(app.$('#service-json')[0], 'files', { value: [file], configurable: true });
    app.$('#step-1-next').trigger('click');
    const listing = app.latest('list_s3_buckets');
    assert.equal(listing.options.data.getAll('service_json').length, 1);
    listing.resolve({ buckets: [{ name: 'fixture-google-bucket' }] });
    assert.doesNotMatch(app.$('#aws_bucket_list').text(), /undefined/);
    app.$('#step-2-next').trigger('click');
    assert.equal(app.latest('configure_aws').options.data.bucket_region, 'US');
    assert.equal(app.latest('configure_aws').options.data.iam_secret, '');
});

test('Google can reuse a saved service account and accepts its provider-specific new bucket names', async t => {
    const app = await providerApp('google', { googleServicePath: 'fixture-private-path.json' });
    t.after(() => app.close());
    assert.doesNotMatch(app.window.document.body.textContent, /fixture-private-path/);
    app.$('#step-1-next').trigger('click');
    const listing = app.latest('list_s3_buckets');
    assert.equal(listing.options.data.has('service_json'), false);
    listing.resolve({ buckets: [] });
    app.$('input[name="bucket_type"][value="new"]').prop('checked', true).trigger('change');
    app.$('#new_bucket_name').val('fixture_google_bucket');
    app.$('#step-2-next').trigger('click');
    assert.equal(app.latest('configure_aws').options.data.bucket_name, 'fixture_google_bucket');
    assert.equal(app.latest('configure_aws').options.data.bucket_region, 'US');
});

test('Bunny restricts pullzones to the selected bucket and never posts a storage password', async t => {
    const app = await providerApp('bunny', { iam_secret: 'fixture-api-key' });
    t.after(() => app.close());
    app.$('#step-1-next').trigger('click');
    app.latest('list_s3_buckets').resolve({ buckets: [
        { name: 'fixture-one', region: 'DE', pullzones: ['one.b-cdn.net'] },
        { name: 'fixture-two', region: 'US', pullzones: ['two.b-cdn.net'] }
    ] });
    assert.equal(app.$('#pull-zones').val(), 'one.b-cdn.net');
    app.$('#aws_bucket_list').val('1').trigger('change');
    assert.equal(app.$('#pull-zones option').length, 1);
    assert.equal(app.$('#pull-zones').val(), 'two.b-cdn.net');
    app.$('#step-2-next').trigger('click');
    const data = app.latest('configure_aws').options.data;
    assert.equal(data.bucket_name, 'fixture-two');
    assert.equal(data.iam_key, 'two.b-cdn.net');
    assert.equal(data.iam_secret, 'fixture-api-key');
    assert.ok(!JSON.stringify(data).includes('|'));
});

test('listing data is rendered as text and stale provider responses cannot replace current choices', async t => {
    const app = await providerApp('aws');
    t.after(() => app.close());
    app.$('#step-1-next').trigger('click');
    app.latest('list_s3_buckets').resolve({ buckets: [{ name: '<img src=x onerror=alert(1)>', region: 'us-east-1' }] });
    assert.equal(app.$('#aws_bucket_list img').length, 0);
    assert.match(app.$('#aws_bucket_list').text(), /<img src=x onerror=alert\(1\)>/);
    app.$('#step-2-back').trigger('click');
    app.$('#step-1-next').trigger('click');
    const old = app.latest('list_s3_buckets');
    app.$('#service_provider').val('digitalocean').trigger('change');
    old.resolve({ buckets: [{ name: 'stale-bucket', region: 'us-east-1' }] });
    assert.doesNotMatch(app.$('#aws_bucket_list').text(), /stale-bucket/);
});

test('provider, credential, endpoint, bucket and pullzone validation prevent invalid requests', async t => {
    const app = await providerApp('cloudflare', { cloudflareEndpoint: '', cloudflareUrl: '' });
    t.after(() => app.close());
    app.$('#step-1-next').trigger('click');
    assert.equal(app.requests.length, 0);
    app.$('#cloudflare-endpoint').val('https://fixture.r2.cloudflarestorage.com');
    app.$('#cloudflare-url').val('cdn.example.test');
    app.$('#step-1-next').trigger('click');
    app.latest('list_s3_buckets').resolve({ buckets: [] });
    app.$('input[name="bucket_type"][value="new"]').prop('checked', true).trigger('change');
    app.$('#new_bucket_name').val('invalid bucket');
    app.$('#step-2-next').trigger('click');
    assert.equal(app.latest('configure_aws'), undefined);
    app.$('#new_bucket_name').val('fixture-r2-bucket');
    app.$('#step-2-next').trigger('click');
    assert.equal(app.latest('configure_aws').options.data.bucket_region, 'auto');
    app.latest('configure_aws').fail('<img src=x onerror=alert(1)>');
    assert.equal(app.$('#wizard-message img').length, 0);
    assert.equal(app.$('#step-2-next').prop('disabled'), false);
});

test('persisted endpoint/public URL and password inputs are escaped in rendered markup', () => {
    const html = render('wizard', { ...credentials, service_provider: 'cloudflare', cloudflareEndpoint: '"><img src=x>', cloudflareUrl: '" onfocus="alert(1)' });
    assert.doesNotMatch(html, /value=""><img src=x>/);
    assert.doesNotMatch(html, /value="" onfocus=/);
    assert.match(html, /&quot;/);
    const bunny = render('wizard', { service_provider: 'bunny', iam_secret: 'fixture-api|fixture-password' });
    assert.doesNotMatch(bunny, /fixture-password/);
});
