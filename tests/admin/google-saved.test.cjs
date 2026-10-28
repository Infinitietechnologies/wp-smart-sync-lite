const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, render, googleKey, chooseGoogleFile } = require('./harness.cjs');
const credentials = JSON.parse(googleKey({ private_key_id: 'saved-private-id', extra_secret: 'saved-extra-secret' }));
const data = { service_provider: 'google', __google_credentials: credentials };

test('saved account is prominent on load, with metadata and no sensitive values anywhere in the initial HTML', async t => {
    const html = render('wizard', data);
    for (const secret of ['fixture-private-value', 'saved-private-id', 'saved-extra-secret', 'fixture-client-id']) assert.ok(!html.includes(secret));
    assert.ok(html.includes('example-project') && html.includes('media@example-project.iam.gserviceaccount.com'));
    const app = await boot('wizard', { data }); t.after(() => app.close());
    assert.equal(app.$('#google-saved-account').prop('hidden'), false);
    assert.equal(app.$('#google-upload').prop('hidden'), true);
    assert.equal(app.$('#google-key-help').prop('hidden'), true);
    assert.match(app.$('#google-saved-account').text(), /Ready to reuse/);
    assert.equal(JSON.parse(app.$('#google-saved-code').text()).private_key, '[Hidden]');
    assert.equal(app.requests.length, 0);
    app.$('#step-1-next').trigger('click');
    assert.equal(app.latest('list_s3_buckets').options.data.has('service_json'), false);
});

test('saved secrets load only on explicit reveal, use safe text rendering, and hide without another request', async t => {
    const app = await boot('wizard', { data }); t.after(() => app.close());
    app.$('#google-saved-details').prop('open', true);
    app.$('#google-saved-reveal').trigger('click');
    const request = app.latest('get_saved_google_credentials');
    assert.equal(request.options.data.credential_reference, app.$('#google-saved-account').attr('data-reference'));
    assert.equal(app.$('#google-saved-reveal').prop('disabled'), true);
    request.resolve({ reference: request.options.data.credential_reference, credentials: { ...credentials, extra_secret: '<img src=x onerror=alert(1)>' } });
    assert.equal(app.$('#google-saved-reveal').attr('aria-pressed'), 'true');
    assert.match(app.$('#google-saved-code').text(), /fixture-private-value/);
    assert.match(app.$('#google-saved-code').text(), /<img src=x/);
    assert.equal(app.$('#google-saved-code img').length, 0);
    app.$('#google-saved-reveal').trigger('click');
    assert.equal(app.requests.length, 1);
    assert.equal(JSON.parse(app.$('#google-saved-code').text()).private_key_id, '[Hidden]');
    assert.equal(app.$('#google-saved-reveal').attr('aria-pressed'), 'false');
});

test('navigation, replacement and late reveal responses cannot leave saved secrets visible', async t => {
    const app = await boot('wizard', { data }); t.after(() => app.close());
    app.$('#google-saved-details').prop('open', true);
    app.$('#google-saved-reveal').trigger('click');
    const stale = app.latest('get_saved_google_credentials');
    app.$('#service_provider').val('aws').trigger('change');
    stale.resolve({ reference: stale.options.data.credential_reference, credentials });
    assert.doesNotMatch(app.$('#google-saved-code').text(), /fixture-private-value/);
    app.$('#service_provider').val('google').trigger('change');
    app.$('#google-saved-reveal').trigger('click');
    const current = app.latest('get_saved_google_credentials');
    current.resolve({ reference: current.options.data.credential_reference, credentials });
    assert.match(app.$('#google-saved-code').text(), /fixture-private-value/);
    await chooseGoogleFile(app);
    assert.equal(app.$('#google-saved-account').prop('hidden'), true);
    assert.equal(app.$('#google-upload').prop('hidden'), false);
    assert.doesNotMatch(app.$('#google-saved-code').text(), /fixture-private-value/);
    app.$('#google-file-remove').trigger('click');
    assert.equal(app.$('#google-saved-account').prop('hidden'), false);
    assert.equal(app.$('#google-upload').prop('hidden'), true);
});

test('failed reveals recover their control and a mismatched saved-account reference is never rendered', async t => {
    const app = await boot('wizard', { data }); t.after(() => app.close());
    app.$('#google-saved-reveal').trigger('click');
    app.latest('get_saved_google_credentials').reject('The saved account changed. Reload the page.');
    assert.match(app.$('#wizard-message').text(), /account changed/);
    assert.equal(app.$('#google-saved-reveal').prop('disabled'), false);
    app.$('#google-saved-reveal').trigger('click');
    app.latest('get_saved_google_credentials').resolve({ reference: 'different-account', credentials });
    assert.doesNotMatch(app.$('#google-saved-code').text(), /fixture-private-value/);
});
