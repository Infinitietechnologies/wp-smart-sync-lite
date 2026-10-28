const test = require('node:test');
const assert = require('node:assert/strict');
const { boot } = require('./harness.cjs');


test('setup guidance, terminology and numbered progress follow all four Lite providers', async t => {
    const app = await boot('wizard', { data: { service_provider: 'bunny' } });
    t.after(() => app.close());
    for (const provider of ['bunny', 'aws', 'digitalocean', 'wasabi']) {
        app.$('#service_provider').val(provider).trigger('change');
        const guides = app.$('.setup-provider-guide').toArray().filter(element => !element.hidden);
        assert.equal(guides.length, 1);
        assert.equal(guides[0].dataset.provider, provider);
        assert.equal(app.$(guides[0]).find('.setup-guide-steps li').length, 3);
        for (const link of app.$(guides[0]).find('a').toArray()) {
            assert.equal(new URL(link.href).protocol, 'https:');
            assert.equal(link.rel, 'noopener noreferrer');
        }
        const steps = app.$('.stepper .step').toArray().filter(element => !element.hidden);
        assert.equal(steps.length, 3);
        assert.deepEqual(steps.map(element => app.$(element).find('.step-icon').text()), ['1', '2', '3']);
        assert.equal(app.$('.step[aria-current="step"]').length, 1);
    }
    app.$('#service_provider').val('bunny').trigger('change');
    assert.match(app.$('#setup-connect-title').text(), /Bunny/);
    assert.match(app.$('#step-2-indicator .step-label').text(), /Storage & delivery/);
    assert.equal(app.$('#step-3-indicator').length, 0);
});

test('provider changes isolate credential drafts and remask secrets', async t => {
    const app = await boot('wizard', { data: { service_provider: 'aws', iam_key: 'aws-id', iam_secret: 'aws-secret' } });
    t.after(() => app.close());
    const toggle = app.$('.setup-reveal[data-target="aws_iam_secret"]');
    toggle.trigger('click');
    assert.equal(app.$('#aws_iam_secret').attr('type'), 'text');
    assert.equal(toggle.attr('aria-pressed'), 'true');
    app.$('#service_provider').val('digitalocean').trigger('change');
    assert.equal(app.$('#aws_iam_key').val(), '');
    assert.equal(app.$('#aws_iam_secret').val(), '');
    app.$('#aws_iam_key').val('r2-id');
    app.$('#aws_iam_secret').val('r2-secret');
    app.$('#service_provider').val('aws').trigger('change');
    assert.equal(app.$('#aws_iam_key').val(), 'aws-id');
    assert.equal(app.$('#aws_iam_secret').val(), 'aws-secret');
    assert.equal(app.$('#aws_iam_secret').attr('type'), 'password');
    assert.equal(toggle.attr('aria-pressed'), 'false');
    app.$('#service_provider').val('digitalocean').trigger('change');
    assert.equal(app.$('#aws_iam_key').val(), 'r2-id');
});

test('invalid credentials focus the missing field and announce a provider-specific error', async t => {
    const app = await boot('wizard', { data: { service_provider: 'bunny' } });
    t.after(() => app.close());
    app.$('#step-1-next').trigger('click');
    assert.equal(app.requests.length, 0);
    assert.equal(app.window.document.activeElement.id, 'bunny-api-key');
    assert.equal(app.$('#bunny-api-key').attr('aria-invalid'), 'true');
    assert.equal(app.$('#wizard-message').attr('role'), 'alert');
    assert.match(app.$('#wizard-message').text(), /Bunny account settings/);
    app.$('#bunny-api-key').val('fixture-account-key').trigger('input');
    assert.equal(app.$('#bunny-api-key').attr('aria-invalid'), undefined);
    app.$('#step-1-next').trigger('click');
    assert.equal(app.$('#step-1-next').prop('disabled'), true);
    assert.equal(app.$('#wizard-message').prop('hidden'), true);
    assert.equal(app.$('#step-1-next').attr('data-busy'), 'true');
    assert.match(app.$('#step-1-next').text(), /Connecting/);
    app.latest('list_s3_buckets').fail('Please check the account key.');
    assert.equal(app.$('#step-1-next').prop('disabled'), false);
    assert.match(app.$('#step-1-next').text(), /Connect and find storage zones/);
});

test('empty storage explains the next action and refresh restores discoverable zones', async t => {
    const app = await boot('wizard', { data: { service_provider: 'bunny', iam_secret: 'fixture-key' } });
    t.after(() => app.close());
    app.$('#step-1-next').trigger('click');
    app.latest('list_s3_buckets').resolve({ buckets: [] });
    assert.equal(app.$('#storage-empty').prop('hidden'), false);
    assert.match(app.$('#storage-empty').text(), /Create a storage zone in Bunny/);
    app.$('#refresh-buckets').trigger('click');
    assert.equal(app.requests.length, 2);
    assert.equal(app.$('#refresh-buckets').prop('disabled'), true);
    app.latest('list_s3_buckets').resolve({ buckets: [{ name: 'new-zone', region: 'DE', pullzones: ['new-zone.b-cdn.net'] }] });
    assert.equal(app.$('#storage-empty').prop('hidden'), true);
    assert.equal(app.$('#refresh-buckets').prop('disabled'), false);
    assert.equal(app.$('#pull-zones').val(), 'new-zone.b-cdn.net');
});

test('Bunny completion reflects the saved target, completed steps, and an explicit unsynced next action', async t => {
    const app = await boot('wizard', { data: { service_provider: 'bunny', iam_secret: 'fixture-key' } });
    t.after(() => app.close());
    app.$('#step-1-next').trigger('click');
    app.latest('list_s3_buckets').resolve({ buckets: [{ name: 'website-media', region: 'DE', pullzones: ['media.b-cdn.net'] }] });
    assert.equal(app.$('#step-1-indicator').hasClass('completed'), true);
    assert.match(app.$('#step-2-next').text(), /Save connection/);
    app.$('#step-2-next').trigger('click');
    assert.equal(app.$('#wizard-message').prop('hidden'), true);
    assert.equal(app.$('#step-2-next').attr('data-busy'), 'true');
    app.latest('configure_aws').resolve();
    assert.equal(app.$('#setup-summary-provider').text(), 'Bunny CDN');
    assert.equal(app.$('#setup-summary-storage').text(), 'website-media');
    assert.equal(app.$('#setup-summary-region').text(), 'DE');
    assert.equal(app.$('#setup-summary-delivery').text(), 'media.b-cdn.net');
    assert.equal(app.$('#step-final-indicator .step-icon').text(), '3');
    assert.equal(app.$('#step-final-indicator').attr('aria-current'), 'step');
    assert.match(app.$('#wizard-step-final').text(), /have not been moved or synced/);
    assert.equal(app.$('#wizard-message').prop('hidden'), true);
    assert.doesNotMatch(app.$('#wizard-step-final').text(), /fixture-key/);
});
