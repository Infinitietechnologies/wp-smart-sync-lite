const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, render } = require('./harness.cjs');
const aws = { service_provider: 'aws', bucket: 'website-media', region: 'eu-north-1', iam_key: 'fixture-inline-key', iam_secret: 'fixture-inline-secret', url: 'website-media.s3.eu-north-1.amazonaws.com' };

test('existing AWS storage offers inline CloudFront setup and gates certificate actions without exposing saved credentials', async t => {
    const html = render('domain', aws);
    assert.doesNotMatch(html, /fixture-inline-key|fixture-inline-secret/);
    const app = await boot('domain', { data: aws }); t.after(() => app.close());
    assert.equal(app.$('#cloudfront-setup-card').length, 1);
    assert.equal(app.$('#custom-url-flow').prop('hidden'), true);
    assert.equal(app.$('#custom-domain-fields').prop('disabled'), true);
    app.$('#generate-certificate').trigger('click');
    assert.equal(app.requests.filter(request => request.action !== 'check_sync_status').length, 0);
    app.$('#setup-custom-cloudfront').trigger('click');
    const setup = app.latest('setup_custom_cloudfront');
    assert.equal(setup.options.data.nonce, 'fixture-nonce');
    assert.equal(setup.options.data.operation, 'setup');
    assert.equal(setup.options.data.iam_secret, undefined);
    assert.equal(app.$('#setup-custom-cloudfront').prop('disabled'), true);
    setup.resolve({ state: 'deploying', distribution_id: 'EINLINE123', domain: 'dinline.cloudfront.net', configuration_reference: setup.options.data.configuration_reference });
    assert.equal(app.$('#continue-custom-domain').prop('hidden'), true);
    assert.match(app.$('#setup-custom-cloudfront').text(), /Check deployment/);
    assert.equal(app.timers.size, 0, 'Deployment checking must not create recurring polling');
    app.$('#setup-custom-cloudfront').trigger('click');
    const check = app.latest('setup_custom_cloudfront');
    assert.equal(check.options.data.operation, 'check');
    check.resolve({ state: 'ready', distribution_id: 'EINLINE123', domain: 'dinline.cloudfront.net', configuration_reference: 'new-reference' });
    assert.equal(app.$('#custom-url-flow').prop('hidden'), true);
    assert.equal(app.$('#continue-custom-domain').prop('hidden'), false);
    app.$('#continue-custom-domain').trigger('click');
    assert.equal(app.$('#custom-url-flow').prop('hidden'), false);
    assert.equal(app.$('#cloudfront-setup-card').prop('hidden'), true);
    assert.equal(app.$('#custom-domain-fields').prop('disabled'), false);
    assert.equal(app.window.document.activeElement.id, 'custom-domain');
    app.$('#custom-domain').val('cdn.example.test');
    app.$('#generate-certificate').trigger('click');
    assert.equal(app.latest('generate_ssl_certificate').options.data.domain, 'cdn.example.test');
});

test('inline setup failures are recoverable and active sync prevents setup', async t => {
    const app = await boot('domain', { data: aws }); t.after(() => app.close());
    app.$('#setup-custom-cloudfront').trigger('click');
    app.latest('setup_custom_cloudfront').fail({ message: 'Fixture AWS permission denied' });
    assert.match(app.$('#cloudfront-setup-message').text(), /permission denied/);
    assert.equal(app.$('#setup-custom-cloudfront').prop('disabled'), false);
    assert.equal(app.$('#custom-url-flow').prop('hidden'), true);
    app.$(app.window.document).trigger('smartsync:status', [{ status: 'processing' }]);
    assert.equal(app.$('#setup-custom-cloudfront').prop('disabled'), true);
    app.$(app.window.document).trigger('smartsync:status', [{ status: 'completed' }]);
    assert.equal(app.$('#setup-custom-cloudfront').prop('disabled'), false);
});

test('remembered AWS region is restored separately from the configured bucket region', async t => {
    const app = await boot('wizard', { data: { ...aws, region: 'eu-west-1', __aws_discovery_region: 'eu-north-1' } }); t.after(() => app.close());
    assert.equal(app.$('#aws_discovery_region').val(), 'eu-north-1');
    assert.equal(app.$('#manual_bucket_region').val(), 'eu-west-1');
    app.$('#step-1-next').trigger('click');
    assert.equal(app.latest('list_s3_buckets').options.data.get('bucket_region'), 'eu-north-1');
});
