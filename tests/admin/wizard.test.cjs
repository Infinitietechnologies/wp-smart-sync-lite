const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, render, chooseGoogleFile } = require('./harness.cjs');
const credentials = { iam_key: 'fixture-key', iam_secret: 'fixture-secret' };

async function providerApp(provider, extra = {}) {
    return boot('wizard', { data: { ...credentials, service_provider: provider, ...extra } });
}





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

test('listing data is rendered as text and stale credential responses cannot replace current choices', async t => {
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
    app.$('#aws_iam_key').val('replacement-key').trigger('input');
    old.resolve({ buckets: [{ name: 'stale-bucket', region: 'us-east-1' }] });
    assert.doesNotMatch(app.$('#aws_bucket_list').text(), /stale-bucket/);
});

test('provider, credential, endpoint, bucket and pullzone validation prevent invalid requests', async t => {
    const app = await providerApp('aws', { iam_key: '' });
    t.after(() => app.close());
    app.$('#step-1-next').trigger('click');
    assert.equal(app.requests.length, 0);
    app.$('#aws_iam_key').val('fixture-id');
    app.$('#step-1-next').trigger('click');
    app.latest('list_s3_buckets').resolve({ buckets: [] });
    app.$('input[name="bucket_type"][value="new"]').prop('checked', true).trigger('change');
    app.$('#new_bucket_name').val('invalid bucket');
    app.$('#step-2-next').trigger('click');
    assert.equal(app.latest('configure_aws'), undefined);
    app.$('#new_bucket_name').val('fixture-r2-bucket');
    app.$('#step-2-next').trigger('click');
    assert.equal(app.latest('configure_aws').options.data.bucket_region, 'us-east-1');
    app.latest('configure_aws').fail('<img src=x onerror=alert(1)>');
    assert.equal(app.$('#wizard-message img').length, 0);
    assert.equal(app.$('#step-2-next').prop('disabled'), false);
});

test('persisted endpoint/public URL and password inputs are escaped in rendered markup', () => {
    const html = render('wizard', { ...credentials, service_provider: 'aws', iam_key: '"><img src=x>', iam_secret: '" onfocus="alert(1)' });
    assert.doesNotMatch(html, /value=""><img src=x>/);
    assert.doesNotMatch(html, /value="" onfocus=/);
    assert.match(html, /&quot;/);
    const bunny = render('wizard', { service_provider: 'bunny', iam_secret: 'fixture-api|fixture-password' });
    assert.doesNotMatch(bunny, /fixture-password/);
});


test('Bunny connection failures return to credentials and unusable pull zones explain the fix safely', async t => {
    const app = await providerApp('bunny', { iam_secret: 'fixture-account-key' });
    t.after(() => app.close());
    app.$('#step-1-next').trigger('click');
    app.latest('list_s3_buckets').fail('Use your account API key, not the storage password');
    assert.equal(app.$('#wizard-step-1').prop('hidden'), false);
    app.$('#step-1-next').trigger('click');
    app.latest('list_s3_buckets').resolve({ buckets: [{ name: 'workflow-zone', region: 'DE', pullzones: [], issue: 'Connect a public pull zone <script>unsafe</script>' }] });
    assert.equal(app.$('#bunny-pull-zone-help').prop('hidden'), false);
    assert.match(app.$('#bunny-pull-zone-help').text(), /Connect a public pull zone/);
    assert.equal(app.$('#bunny-pull-zone-help script').length, 0);
    app.$('#step-2-next').trigger('click');
    assert.equal(app.latest('configure_aws'), undefined);
});
