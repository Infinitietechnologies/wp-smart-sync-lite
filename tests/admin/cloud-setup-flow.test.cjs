const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, render } = require('./harness.cjs');

test('failed discovery stays on credentials and edits clear the manual fallback', async t => {
    for (const provider of ['aws', 'digitalocean', 'wasabi']) {
        const app = await boot('wizard', { data: { service_provider: provider, iam_key: 'test-id', iam_secret: 'test-secret', cloudflareEndpoint: 'https://' + 'a'.repeat(32) + '.r2.cloudflarestorage.com', cloudflareUrl: 'cdn.example.test' } });
        t.after(() => app.close());
        app.$('#step-1-next').trigger('click');
        app.latest('list_s3_buckets').fail('Provider request failed.');
        assert.equal(app.$('#wizard-step-1').prop('hidden'), false, provider);
        assert.equal(app.$('#step-2-indicator button').prop('disabled'), true, provider);
        assert.equal(app.$('#setup-manual-continue').prop('hidden'), false, provider);
        app.$('#aws_iam_key').val('replacement-id').trigger('input');
        assert.equal(app.$('#setup-manual-continue').prop('hidden'), true, provider);
        app.$('#setup-manual-continue').trigger('click');
        assert.equal(app.$('#wizard-step-1').prop('hidden'), false, provider);
    }
});
const credentials = { service_provider: 'bunny', bucket: 'current-zone', iam_secret: 'fixture-key' };
const zones = [
    { name: 'first-zone', region: 'DE', pullzones: ['first.b-cdn.net'] },
    { name: 'second-zone', region: 'DE', pullzones: ['second.b-cdn.net', 'second-custom.b-cdn.net'] }
];
const step = (app, value) => app.$('[data-setup-step="' + value + '"]');

test('discovery and refresh freeze all controls, show one loading action, preserve selections and recover on failure', async t => {
    const app = await boot('wizard', { data: credentials });
    t.after(() => app.close());
    app.$('#step-1-next').trigger('click');
    assert.ok(app.$('#aws-wizard :input, #wizard-stepper button').toArray().every(element => element.disabled));
    assert.equal(app.$('#wizard-message').prop('hidden'), true);
    assert.equal(app.$('button[data-busy="true"]').length, 1);
    assert.match(app.$('#step-1-next').text(), /Connecting/);
    step(app, 1).trigger('click');
    app.$('#refresh-buckets').trigger('click');
    assert.equal(app.requests.length, 1);
    app.latest('list_s3_buckets').resolve({ buckets: zones });
    assert.equal(app.$('#bucket-type-selector').prop('hidden'), true);
    app.$('#aws_bucket_list').val('1').trigger('change');
    app.$('#pull-zones').val('second-custom.b-cdn.net');
    app.$('#refresh-buckets').trigger('click');
    assert.match(app.$('#refresh-buckets').text(), /Refreshing/);
    assert.doesNotMatch(app.$('#step-1-next').text(), /Connecting/);
    assert.ok(app.$('#aws-wizard :input, #wizard-stepper button').toArray().every(element => element.disabled));
    assert.equal(app.$('#wizard-message').prop('hidden'), true);
    app.latest('list_s3_buckets').resolve({ buckets: [...zones].reverse() });
    assert.equal(app.$('#aws_bucket_list').val(), '0');
    assert.equal(app.$('#pull-zones').val(), 'second-custom.b-cdn.net');
    assert.equal(app.$('#refresh-buckets').text(), 'Refresh list');
    assert.equal(app.$('#aws_bucket_list').prop('disabled'), false);
    app.$('#refresh-buckets').trigger('click');
    app.latest('list_s3_buckets').fail('Account API unavailable.');
    assert.equal(app.$('#service_provider').prop('disabled'), false);
    assert.equal(app.$('#aws-wizard').attr('aria-busy'), 'false');
    assert.equal(app.$('button[data-busy="true"]').length, 0);
    assert.match(app.$('#wizard-message').text(), /Account API unavailable/);
});

test('step buttons revisit validated steps, invalidate on edits, and cannot skip discovery or save', async t => {
    const app = await boot('wizard', { data: { service_provider: 'aws', iam_key: 'fixture-id', iam_secret: 'fixture-secret' } });
    t.after(() => app.close());
    assert.equal(step(app, 2).prop('disabled'), true);
    assert.equal(step(app, 3).length, 0);
    step(app, 2).trigger('click');
    assert.equal(app.$('#wizard-step-1').prop('hidden'), false);
    app.$('#step-1-next').trigger('click');
    app.latest('list_s3_buckets').resolve({ buckets: [{ name: 'aws-zone', region: 'us-east-1' }] });
    step(app, 1).trigger('click');
    assert.equal(app.window.document.activeElement.id, 'setup-connect-title');
    step(app, 2).trigger('click');
    assert.equal(app.$('#wizard-step-2').prop('hidden'), false);
    assert.equal(app.requests.length, 1);
    assert.equal(step(app, 'final').prop('disabled'), true);
    step(app, 'final').trigger('click');
    assert.equal(app.latest('configure_aws'), undefined);
    step(app, 1).trigger('click');
    app.$('#aws_iam_secret').val('new-secret').trigger('input');
    assert.equal(step(app, 2).prop('disabled'), true);
    assert.equal(step(app, 3).length, 0);
});

test('current provider stays separate from the setup draft and only updates after a successful save', async t => {
    const app = await boot('wizard', { data: credentials });
    t.after(() => app.close());
    assert.equal(app.$('#setup-active-provider').text(), 'Bunny CDN');
    assert.equal(app.$('#setup-active-storage').text(), 'current-zone');
    app.$('#service_provider').val('digitalocean').trigger('change');
    assert.equal(app.$('#service_provider option:selected').text(), 'DigitalOcean Spaces');
    assert.equal(app.$('#setup-active-provider').text(), 'Bunny CDN');
    app.$('#aws_iam_key').val('spaces-id');
    app.$('#aws_iam_secret').val('spaces-secret');
    app.$('#step-1-next').trigger('click');
    app.latest('list_s3_buckets').resolve({ buckets: [{ name: 'spaces-zone', region: 'nyc3' }] });
    app.$('#step-2-next').trigger('click');
    app.latest('configure_aws').reject('Save rejected.');
    assert.equal(app.$('#setup-active-provider').text(), 'Bunny CDN');
    app.$('#step-2-next').trigger('click');
    app.latest('configure_aws').resolve();
    assert.equal(app.$('#setup-active-provider').text(), 'DigitalOcean Spaces');
    assert.equal(app.$('#setup-active-storage').text(), 'spaces-zone');
    assert.ok(app.$('#wizard-stepper button').toArray().every(button => button.disabled));
    assert.equal(app.$('#wizard-message').prop('hidden'), true);
});

test('active sync locks server-rendered controls and polling retains the lock through errors until completion', async t => {
    assert.match(render('wizard', { ...credentials, __sync_running: true }), /id="setup-controls" disabled/);
    const app = await boot('wizard', { data: { ...credentials, __sync_running: true } });
    t.after(() => app.close());
    assert.equal(app.$('#setup-sync-lock').prop('hidden'), false);
    assert.equal(app.$('#service_provider').is(':disabled'), true);
    app.$('#service_provider').val('aws').trigger('change');
    assert.equal(app.$('#service_provider').val(), 'bunny');
    app.$('#step-1-next').trigger('click');
    assert.equal(app.latest('list_s3_buckets'), undefined);
    app.runTimer();
    app.latest('check_sync_status').fail();
    assert.equal(app.$('#service_provider').is(':disabled'), true);
    app.runTimer();
    app.latest('check_sync_status').resolve({ status: 'completed' });
    assert.equal(app.$('#setup-sync-lock').prop('hidden'), true);
    assert.equal(app.$('#service_provider').is(':disabled'), false);
    app.$('#step-1-next').trigger('click');
    app.latest('list_s3_buckets').resolve({ buckets: zones });
    app.runTimer(); // Dismiss the completed-run notice before the next status poll.
    app.refreshStatus();
    app.latest('check_sync_status').resolve({ status: 'processing' });
    assert.equal(app.$('#pull-zones').is(':disabled'), true);
    assert.ok(app.$('#wizard-stepper button').toArray().every(button => button.disabled));
    app.$('#step-2-next').trigger('click');
    assert.equal(app.latest('configure_aws'), undefined);
    app.runTimer();
    app.latest('check_sync_status').resolve({ status: 'idle' });
    assert.equal(app.$('#pull-zones').is(':disabled'), false);
});

test('finishing discovery cannot release a sync lock received while the request was pending', async t => {
    const app = await boot('wizard', { data: credentials });
    t.after(() => app.close());
    app.runTimer();
    app.$('#step-1-next').trigger('click');
    app.latest('check_sync_status').resolve({ status: 'processing' });
    app.latest('list_s3_buckets').resolve({ buckets: zones });
    assert.equal(app.$('#aws-wizard').attr('aria-busy'), 'false');
    assert.equal(app.$('#service_provider').is(':disabled'), true);
    assert.equal(app.$('#aws_bucket_list').is(':disabled'), true);
    assert.equal(step(app, 1).prop('disabled'), true);
    app.runTimer();
    app.latest('check_sync_status').resolve({ status: 'idle' });
    assert.equal(app.$('#service_provider').is(':disabled'), false);
    assert.equal(app.$('#aws_bucket_list').is(':disabled'), false);
    assert.equal(step(app, 1).prop('disabled'), false);
});

test('status checks wait for pending provider operations and resume once afterward', async t => {
    const app = await boot('wizard', { data: credentials }); t.after(() => app.close());
    app.$('#step-1-next').trigger('click');
    app.runTimer();
    app.refreshStatus();
    app.window.dispatchEvent(new app.window.Event('focus'));
    assert.equal(app.latest('check_sync_status'), undefined);
    app.latest('list_s3_buckets').resolve({ buckets: zones });
    app.runTimer();
    assert.equal(app.requests.filter(request => request.action === 'check_sync_status').length, 1);
    app.latest('check_sync_status').resolve({ status: 'idle' });
    for (let i = 0; i < 5; i++) app.runTimer();
    assert.equal(app.requests.filter(request => request.action === 'check_sync_status').length, 1);
    app.$('#refresh-buckets').trigger('click');
    app.refreshStatus();
    assert.equal(app.requests.filter(request => request.action === 'check_sync_status').length, 1);
    app.latest('list_s3_buckets').fail('Provider unavailable');
    app.runTimer();
    assert.equal(app.requests.filter(request => request.action === 'check_sync_status').length, 2);
});
