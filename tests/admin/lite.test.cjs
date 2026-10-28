const test = require('node:test');
const assert = require('node:assert/strict');
const {boot, render} = require('./harness.cjs');
const upgrade = 'https://codecanyon.net/item/wp-smartsync-wordpress-plugin-for-media-assets-cdn-cloud-storage/56335604';

test('Lite overview brands the edition and shows all four Pro features as disabled previews', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    assert.equal(app.$('h1').text(), 'SmartSync Lite');
    const cards = app.$('#tab-general [data-pro-feature]');
    assert.equal(cards.length, 4);
    assert.deepEqual(cards.map((_, element) => app.$(element).attr('data-pro-feature')).get(), ['google', 'cloudflare', 'cloudfront', 'assets']);
    cards.each((_, element) => {
        const card = app.$(element);
        assert.equal(card.find('.smartsync-pro-badge').text(), 'Pro only');
        assert.equal(card.find('fieldset').prop('disabled'), true);
        assert.equal(card.find('fieldset').attr('inert'), '');
        assert.equal(card.find('a').attr('href'), upgrade);
        assert.equal(card.find('[name]').length, 0);
    });
    app.latest('check_sync_status').resolve({status:'idle'});
    assert.equal(app.$('#tab-assets fieldset').prop('disabled'), true);
    assert.equal(app.$('#use-cloudfront-asset, #sync-local-to-aws-plugin, #sync-aws-to-local-plugin').length, 0);
});

test('Lite setup disables Pro providers and cannot issue their discovery requests', async t => {
    const app = await boot('wizard'); t.after(() => app.close());
    for (const provider of ['google', 'cloudflare']) {
        assert.equal(app.$('#service_provider option[value="' + provider + '"]').prop('disabled'), true);
        app.$('#service_provider option[value="' + provider + '"]').prop('disabled', false);
        app.$('#service_provider').val(provider).trigger('change');
        app.$('#step-1-next').trigger('click');
        assert.equal(app.latest('list_s3_buckets'), undefined);
    }
    assert.equal(app.$('#cloudflare-endpoint, #google-json-file, #use-cloudfront-yes').length, 0);
});

test('Lite CloudFront page contains an inert preview without provisioning controls or secrets', async t => {
    const app = await boot('domain', {data: {service_provider:'aws', use_cloudfront:true, iam_secret:'do-not-render', __custom:{certificate_arn:'private-certificate'}}});
    t.after(() => app.close());
    assert.equal(app.$('[data-pro-feature="cloudfront"]').length, 1);
    assert.equal(app.$('fieldset').prop('disabled'), true);
    assert.equal(app.$('#generate-certificate, #cloudfront-attachment-button, #custom-domain').length, 0);
    assert.doesNotMatch(app.window.document.body.innerHTML, /do-not-render|private-certificate/);
    assert.ok(app.requests.every(request => request.action === 'check_sync_status'));
});

test('Lite masks saved Pro provider settings in the setup form without invoking credential preview code', () => {
    const html = render('wizard', {service_provider:'cloudflare', iam_key:'do-not-render-pro-key', iam_secret:'do-not-render-pro-secret', bucket:'private-pro-bucket'});
    assert.match(html, /Pro only/);
    assert.doesNotMatch(html, /do-not-render-pro-key|do-not-render-pro-secret|private-pro-bucket/);
});
