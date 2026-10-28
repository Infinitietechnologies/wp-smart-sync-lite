const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, render } = require('./harness.cjs');

test('every editable text credential and storage field has an example or entry hint', async t => {
    const app = await boot('wizard', { data: { service_provider: 'aws' } });
    t.after(() => app.close());
    const fields = app.$('#aws-wizard input[type="text"], #aws-wizard input[type="password"], #aws-wizard input[type="url"]');
    assert.ok(fields.length >= 9);
    for (const field of fields.toArray()) {
        assert.ok((field.getAttribute('placeholder') || '').trim(), `Missing placeholder: ${field.id}`);
    }
    assert.match(app.$('#aws_iam_key').attr('placeholder'), /AWS IAM/);
    assert.match(app.$('#aws_iam_secret').attr('placeholder'), /secret access key/);
    assert.equal(app.$('#manual_bucket_region').attr('placeholder'), 'e.g. us-east-1');
    assert.equal(app.$('#new_bucket_name').attr('placeholder'), 'e.g. my-website-media');
    assert.equal(app.$('#aws_iam_key').val(), '');
});

test('credential and region placeholders follow the provider without changing draft values', async t => {
    const app = await boot('wizard', { data: { service_provider: 'aws' } });
    t.after(() => app.close());
    for (const [provider, key, region] of [
        ['digitalocean', 'Spaces access key ID', 'e.g. nyc3'],
        ['wasabi', 'Wasabi access key ID', 'e.g. us-east-1'],
        ['cloudflare', 'R2 Access Key ID', 'auto'],
        ['aws', 'AWS IAM', 'e.g. us-east-1']
    ]) {
        app.$('#service_provider').val(provider).trigger('change');
        assert.match(app.$('#aws_iam_key').attr('placeholder'), new RegExp(key));
        assert.equal(app.$('#manual_bucket_region').attr('placeholder'), region);
        assert.ok(app.$('#aws_iam_secret').attr('placeholder').trim());
    }
    assert.equal(app.$('#aws_iam_key').val(), '');
    assert.match(app.$('#bunny-api-key').attr('placeholder'), /Bunny/);
});

test('custom CloudFront hostname has a scheme-free example', () => {
    const html = render('domain', { service_provider: 'aws', use_cloudfront: true });
    assert.match(html, /id="custom-domain"[^>]*placeholder="e\.g\. cdn\.example\.com"/);
});
