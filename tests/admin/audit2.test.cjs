const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { JSDOM } = require('jsdom');
const { boot, render, chooseGoogleFile } = require('./harness.cjs');

const credentials = { service_provider: 'aws', iam_key: 'fixture-key', iam_secret: 'fixture-secret' };
const certificateArn = 'arn:aws:acm:us-east-1:123456789012:certificate/audit2';
const certificate = { certificate_arn: certificateArn, domain: 'cdn.example.test', records: [] };
function idle(app) { app.latest('check_sync_status').resolve({ status: 'idle', processed_files: 0, total_files: 0 }); }
function lifecycle(app, name) {
    const event = new app.window.Event(name);
    Object.defineProperty(event, 'persisted', { value: true });
    app.window.dispatchEvent(event);
}

test('UI-001 edited credentials invalidate an outstanding discovery response and permit a fresh retry', async t => {
    const app = await boot('wizard', { data: credentials });
    t.after(() => app.close());
    app.$('#step-1-next').trigger('click');
    const stale = app.latest('list_s3_buckets');
    app.$('#aws_iam_key').val('other-fixture-account').trigger('input');
    stale.resolve({ buckets: [{ name: 'old-account-bucket', region: 'us-east-1' }] });
    assert.equal(app.$('#wizard-step-1').prop('hidden'), false);
    assert.equal(app.$('#step-1-next').prop('disabled'), false);
    assert.doesNotMatch(app.$('#aws_bucket_list').text(), /old-account-bucket/);
    app.$('#step-1-next').trigger('click');
    app.latest('list_s3_buckets').resolve({ buckets: [{ name: 'current-bucket', region: 'us-east-1' }] });
    assert.match(app.$('#aws_bucket_list').text(), /current-bucket/);
});

test('UI-002 saving freezes the complete wizard form and restores controls on error', async t => {
    const app = await boot('wizard', { data: { ...credentials, service_provider: 'digitalocean' } });
    t.after(() => app.close());
    app.$('#step-1-next').trigger('click');
    app.latest('list_s3_buckets').resolve({ buckets: [{ name: 'fixture-bucket', region: 'nyc3' }] });
    app.$('#step-2-next').trigger('click');
    assert.equal(app.$('#aws_bucket_list').prop('disabled'), true);
    assert.equal(app.$('#aws_iam_key').prop('disabled'), true);
    assert.equal(app.$('#aws-wizard').attr('aria-busy'), 'true');
    app.latest('configure_aws').reject('Fixture failure');
    assert.equal(app.$('#aws_bucket_list').prop('disabled'), false);
    assert.equal(app.$('#aws-wizard').attr('aria-busy'), 'false');
});

test('UI-003 transient status failure retains the last known running lock and retries polling', async t => {
    const app = await boot('settings');
    t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'processing', type: 'media', direction: 'upload', total_files: 5, processed_files: 1 });
    app.runTimer();
    app.latest('check_sync_status').fail({ message: 'Temporary status failure' });
    assert.equal(app.pollingTimers.size, 1);
    assert.ok(app.$('.sync-button').toArray().every(button => button.disabled));
    assert.equal(app.$('.smartsync-progress-container').prop('hidden'), false);
    app.runTimer();
    idle(app);
    assert.ok(app.$('.sync-button').toArray().every(button => !button.disabled));
    assert.equal(app.$('.smartsync-progress-container').prop('hidden'), true);
});

test('UI-004 an uncertain failed start rediscovers a server job that actually began', async t => {
    const app = await boot('settings');
    t.after(() => app.close());
    idle(app);
    app.$('#sync-local-to-aws').trigger('click');
    app.latest('sync_local_to_aws').fail({ message: 'The response was lost' });
    assert.equal(app.requests.filter(request => request.action === 'check_sync_status').length, 2);
    app.latest('check_sync_status').resolve({ status: 'processing', type: 'media', direction: 'upload', total_files: 3, processed_files: 1 });
    assert.equal(app.$('#current-sync-state').val(), 'media');
    assert.equal(app.pollingTimers.size, 1);
});

test('UI-005 BFCache restoration resumes status polling and ignores pre-navigation responses', async t => {
    const app = await boot('settings');
    t.after(() => app.close());
    const old = app.latest('check_sync_status');
    lifecycle(app, 'pagehide');
    lifecycle(app, 'pageshow');
    assert.equal(app.requests.filter(request => request.action === 'check_sync_status').length, 2);
    old.resolve({ status: 'completed', direction: 'upload', successes: ['stale-file'], errors: [] });
    assert.doesNotMatch(app.$('#sync-log-container').text(), /stale-file/);
    idle(app);
    assert.ok(app.$('.sync-button').toArray().every(button => !button.disabled));
});


test('UI-007 settings transport errors preserve actionable server messages safely', async t => {
    const app = await boot('settings', { data: { use_cdn_for_media: true } });
    t.after(() => app.close());
    idle(app);
    app.$('#use-cloudfront-media').prop('checked', false).trigger('change');
    app.latest('toggle_cdn').fail({ message: 'Restore locally removed files before disabling CDN. <img src=x>' });
    assert.match(app.$('#sync-log-container').text(), /Restore locally removed files/);
    assert.equal(app.$('#sync-log-container img').length, 0);
    assert.equal(app.$('#use-cloudfront-media').prop('checked'), true);
});

test('UI-008 resumed ETA uses progress observed since this page opened', async t => {
    const app = await boot('settings');
    t.after(() => app.close());
    let now = 100000;
    app.window.Date.now = () => now;
    app.latest('check_sync_status').resolve({ status: 'processing', type: 'media', direction: 'upload', total_files: 100, processed_files: 90 });
    now += 5000;
    app.runTimer();
    app.latest('check_sync_status').resolve({ status: 'processing', type: 'media', direction: 'upload', total_files: 100, processed_files: 90 });
    assert.equal(app.$('#progress-time').text(), 'Calculating…');
    now += 5000;
    app.runTimer();
    app.latest('check_sync_status').resolve({ status: 'processing', type: 'media', direction: 'upload', total_files: 100, processed_files: 93 });
    assert.equal(app.$('#progress-time').text(), '<1 min');
});

test('UI-009 docs retain a manually opened mobile menu on same-breakpoint resizes and restore focus when hiding', () => {
    const docs = path.resolve(__dirname, '../../Documentation');
    const dom = new JSDOM(fs.readFileSync(path.join(docs, 'index.html'), 'utf8'), { runScripts: 'outside-only', url: 'https://example.test/docs/' });
    try {
        const { window } = dom;
        const source = path.join(docs, 'assets/js/docs.js');
        window.eval(fs.readFileSync(source, 'utf8') + '\n//# sourceURL=' + pathToFileURL(source).href);
        const toggler = window.document.getElementById('docs-sidebar-toggler');
        toggler.click();
        window.dispatchEvent(new window.Event('resize'));
        assert.equal(toggler.getAttribute('aria-expanded'), 'true');
        window.innerWidth = 1280;
        window.dispatchEvent(new window.Event('resize'));
        window.document.querySelector('#docs-sidebar a').focus();
        window.innerWidth = 375;
        window.dispatchEvent(new window.Event('resize'));
        assert.equal(window.document.activeElement, toggler);
    } finally { dom.window.close(); }
});



test('UI-012 discovery announces its loading state and clears it when credentials change', async t => {
    const app = await boot('wizard', { data: credentials, translations: { loading_buckets: 'Loading buckets...' } });
    t.after(() => app.close());
    app.$('#step-1-next').trigger('click');
    assert.equal(app.$('#aws-wizard').attr('aria-busy'), 'true');
    assert.equal(app.$('#step-1-next').prop('disabled'), true);
    assert.match(app.$('#step-1-next').text(), /Connecting/);
    app.$('#aws_iam_secret').val('new-fixture-secret').trigger('input');
    assert.equal(app.$('#aws-wizard').attr('aria-busy'), 'false');
});

test('UI-013 the active background indicator remains visible beside WordPress default hidden spinners', async t => {
    const app = await boot('settings', { baseStyles: '.spinner { visibility: hidden; float: right; }' });
    t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'processing', type: 'media', direction: 'upload', total_files: 2, processed_files: 1 });
    const indicator = app.$('.smartsync-background-indicator')[0];
    assert.equal(indicator.hidden, false);
    assert.notEqual(app.window.getComputedStyle(indicator).visibility, 'hidden');
});


test('reviewed wizard back buttons and BFCache ignore a pre-navigation save completion', async t => {
    const app = await boot('wizard', { data: credentials });
    t.after(() => app.close());
    app.$('#step-1-next').trigger('click');
    app.latest('list_s3_buckets').resolve({ buckets: [{ name: 'fixture-bucket', region: 'us-east-1' }] });
    app.$('#step-2-next').trigger('click');
    const stale = app.latest('configure_aws');
    assert.equal(stale.options.data.use_cloudfront, undefined);
    lifecycle(app, 'pagehide');
    lifecycle(app, 'pageshow');
    stale.resolve();
    assert.equal(app.$('#wizard-step-1').prop('hidden'), false);
    assert.equal(app.$('#service_provider').prop('disabled'), false);
});



test('reviewed view constructors/menu hooks enforce registration capability and deny unauthorized rendering before secrets', () => {
    for (const view of ['settings', 'wizard', 'domain']) {
        const menu = JSON.parse(render(view, { __inspect_menu: true }));
        assert.equal(menu.capability || menu[3], 'manage_options');
        let denied;
        try { render(view, { ...credentials, __can_manage: false }); }
        catch (error) { denied = error; }
        assert.ok(denied, 'View must reject an unauthorized render: ' + view);
        assert.doesNotMatch(String(denied.stdout), /fixture-secret|id="aws-wizard"|id="sync-log-container"/);
    }
});

test('reviewed optional highlighter supports inactive, modern, legacy and missing-library cases locally', () => {
    const source = path.resolve(__dirname, '../../Documentation/assets/js/highlight-custom.js');
    for (const mode of ['absent', 'inactive', 'modern', 'legacy']) {
        const dom = new JSDOM(mode === 'inactive' ? '<code>inline</code>' : '<pre><code>const value = 1;</code></pre>', { runScripts: 'outside-only' });
        try {
            let calls = 0;
            if (mode === 'modern' || mode === 'inactive') dom.window.hljs = { highlightAll() { calls++; } };
            if (mode === 'legacy') dom.window.hljs = { initHighlighting() { calls++; } };
            dom.window.eval(fs.readFileSync(source, 'utf8') + '\n//# sourceURL=' + pathToFileURL(source).href);
            assert.equal(calls, mode === 'modern' || mode === 'legacy' ? 1 : 0);
        } finally { dom.window.close(); }
    }
});
