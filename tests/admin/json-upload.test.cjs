const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, googleKey, chooseGoogleFile } = require('./harness.cjs');
const google = { service_provider: 'google' };

test('JSON selection shows formatted metadata without transmitting credentials or exposing sensitive values', async t => {
    const app = await boot('wizard', { data: google });
    t.after(() => app.close());
    await chooseGoogleFile(app, googleKey({ extra_secret: 'hidden-extra-value' }), 'my-key.json');
    assert.equal(app.requests.length, 0);
    assert.equal(app.$('#google-upload').attr('data-state'), 'ready');
    assert.equal(app.$('#google-file-name').text(), 'my-key.json');
    assert.match(app.$('#google-file-meta').text(), /B/);
    assert.equal(app.$('#google-file-project').text(), 'example-project');
    assert.equal(app.$('#google-file-account').text(), 'media@example-project.iam.gserviceaccount.com');
    const preview = app.$('#google-json-code').text();
    assert.match(preview, /\n  "type": "service_account"/);
    assert.doesNotMatch(preview, /fixture-private-value|fixture-key-id|fixture-client-id|hidden-extra-value/);
    assert.equal(JSON.parse(preview).private_key, '[Hidden]');
    app.$('#step-1-next').trigger('click');
    const listing = app.latest('list_s3_buckets');
    assert.equal(listing.options.data.getAll('service_json').length, 1);
    assert.equal(listing.options.data.get('service_json').name, 'my-key.json');
    assert.equal(app.$('#google-file-remove').prop('disabled'), true);
    assert.equal(app.$('#google-json-reveal').prop('disabled'), true);
});

test('sensitive JSON is opt-in, safely rendered, and remasked on provider or step navigation', async t => {
    const app = await boot('wizard', { data: google });
    t.after(() => app.close());
    await chooseGoogleFile(app, googleKey({ private_key: '-----BEGIN PRIVATE KEY-----\n<img src=x onerror=alert(1)>' }));
    app.$('#google-json-reveal').trigger('click');
    assert.equal(app.$('#google-json-reveal').attr('aria-pressed'), 'true');
    assert.match(app.$('#google-json-code').text(), /<img src=x/);
    assert.equal(app.$('#google-file-preview img').length, 0);
    app.$('#service_provider').val('aws').trigger('change');
    assert.equal(app.$('#google-json-reveal').attr('aria-pressed'), 'false');
    assert.doesNotMatch(app.$('#google-json-code').text(), /<img src=x/);
    app.$('#service_provider').val('google').trigger('change');
    app.$('#google-json-reveal').trigger('click');
    app.runTimer();
    app.latest('check_sync_status').resolve({ status: 'processing' });
    assert.equal(app.$('#google-json-reveal').attr('aria-pressed'), 'false');
    assert.doesNotMatch(app.$('#google-json-code').text(), /<img src=x/);
    app.runTimer();
    app.latest('check_sync_status').resolve({ status: 'idle' });
    app.$('#google-json-reveal').trigger('click');
    app.$('#step-1-next').trigger('click');
    assert.equal(app.$('#google-json-reveal').attr('aria-pressed'), 'false');
});

test('a local file read failure reports an actionable error and restores connection controls', async t => {
    const app = await boot('wizard', { data: google });
    t.after(() => app.close());
    let reader;
    app.window.FileReader = class {
        constructor() { reader = this; }
        abort() {}
        readAsText() {}
    };
    Object.defineProperty(app.$('#service-json')[0], 'files', { value: [new app.window.File(['unreadable'], 'locked.json')], configurable: true });
    app.$('#service-json').trigger('change');
    assert.equal(app.$('#step-1-next').prop('disabled'), true);
    reader.onerror();
    assert.equal(app.$('#google-upload').attr('data-state'), 'error');
    assert.match(app.$('#google-file-error').text(), /could not be read/);
    assert.equal(app.$('#step-1-next').prop('disabled'), false);
    app.$('#step-1-next').trigger('click');
    assert.equal(app.requests.length, 0);
});

test('malformed JSON, incorrect credential type and disallowed token endpoints fail before network access', async t => {
    const app = await boot('wizard', { data: google });
    t.after(() => app.close());
    for (const [contents, expected] of [['{broken', /invalid JSON/], [googleKey({ type: 'authorized_user' }), /not a service-account/], [googleKey({ token_uri: 'https://attacker.example/token' }), /not a service-account/]]) {
        await chooseGoogleFile(app, contents);
        app.$('#step-1-next').trigger('click');
        assert.equal(app.$('#google-upload').attr('data-state'), 'error');
        assert.match(app.$('#google-file-error').text(), expected);
        assert.equal(app.$('#google-file-preview').prop('hidden'), true);
        assert.equal(app.requests.length, 0);
        assert.equal(app.window.document.activeElement.id, 'google-file-replace');
    }
});

test('replacement ignores a late old FileReader and removal invalidates discovery while preserving saved-key reuse', async t => {
    const app = await boot('wizard', { data: { ...google, googleServicePath: 'fixture-saved-key' } });
    t.after(() => app.close());
    const readers = [];
    app.window.FileReader = class {
        constructor() { readers.push(this); }
        abort() {}
        readAsText(file) { this.file = file; }
    };
    const select = name => {
        Object.defineProperty(app.$('#service-json')[0], 'files', { value: [new app.window.File(['unused'], name)], configurable: true });
        app.$('#service-json').trigger('change');
    };
    select('old.json');
    assert.equal(app.$('#step-1-next').prop('disabled'), true);
    select('new.json');
    readers[1].result = googleKey({ project_id: 'new-project' }); readers[1].onload();
    readers[0].result = googleKey({ project_id: 'old-project' }); readers[0].onload();
    assert.equal(app.$('#google-file-project').text(), 'new-project');
    app.$('#step-1-next').trigger('click');
    app.latest('list_s3_buckets').resolve({ buckets: [{ name: 'google-zone', region: 'US' }], selectionToken: 'new-token' });
    app.$('[data-setup-step="1"]').trigger('click');
    app.$('#google-file-remove').trigger('click');
    assert.equal(app.$('#google-upload').attr('data-state'), 'empty');
    assert.equal(app.$('#google-json-code').text(), '');
    assert.equal(app.$('#google-file-name').text(), '');
    assert.equal(app.$('[data-setup-step="2"]').prop('disabled'), true);
    app.$('#step-1-next').trigger('click');
    assert.equal(app.latest('list_s3_buckets').options.data.has('service_json'), false);
});

test('drag-and-drop accepts a single JSON file, rejects multiple files, and stays locked during requests', async t => {
    const app = await boot('wizard', { data: google });
    t.after(() => app.close());
    const drop = files => app.$('#google-upload').trigger(app.$.Event('drop', { originalEvent: { dataTransfer: { files }, preventDefault() {} } }));
    drop([new app.window.File([googleKey()], 'dropped.json')]);
    for (let tries = 0; tries < 100 && app.$('#google-upload').attr('data-state') === 'reading'; tries++) await new Promise(resolve => setTimeout(resolve, 10));
    assert.equal(app.$('#google-file-name').text(), 'dropped.json');
    assert.equal(app.$('#google-upload').attr('data-state'), 'ready');
    drop([new app.window.File(['{}'], 'a.json'), new app.window.File(['{}'], 'b.json')]);
    assert.match(app.$('#google-file-error').text(), /one JSON or P12 file/);
    assert.equal(app.$('#google-file-name').text(), 'dropped.json');
    app.$('#step-1-next').trigger('click');
    drop([new app.window.File([googleKey()], 'blocked.json')]);
    assert.equal(app.$('#google-file-name').text(), 'dropped.json');
    assert.equal(app.latest('list_s3_buckets').options.data.get('service_json').name, 'dropped.json');
});

test('successful Google setup clears the local key and raw preview after saving', async t => {
    const app = await boot('wizard', { data: google });
    t.after(() => app.close());
    await chooseGoogleFile(app);
    app.$('#step-1-next').trigger('click');
    app.latest('list_s3_buckets').resolve({ buckets: [{ name: 'google-zone', region: 'US' }], selectionToken: 'accepted-token' });
    app.runTimer();
    app.$('#step-2-next').trigger('click');
    assert.equal(app.latest('configure_aws').options.data.google_selection_token, 'accepted-token');
    app.latest('check_sync_status').resolve({ status: 'processing' });
    app.latest('configure_aws').resolve();
    assert.equal(app.$('#wizard-step-final').prop('hidden'), false);
    assert.equal(app.$('#google-upload').attr('data-state'), 'empty');
    assert.equal(app.$('#google-json-code').text(), '');
    assert.equal(app.$('#service-json').attr('data-has-credentials'), '1');
    assert.equal(app.$('#setup-controls').prop('disabled'), true);
});
