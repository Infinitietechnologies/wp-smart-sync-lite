const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, render } = require('./harness.cjs');

function idle(app) { app.latest('check_sync_status').resolve({ status: 'idle', total_files: 0, processed_files: 0 }); }

test('settings renders working navigation and usable controls under a short or unlimited PHP timeout', () => {
    const html = render('settings');
    assert.match(html, /id="sync-local-to-aws"/);
    assert.match(html, /href="[^"]*page=smarl-configuration-wizard"/);
    assert.doesNotMatch(html, /page=aws-configuration-wizard/);
    assert.doesNotMatch(html, /https:\/\/example.com\/buy/);
});

test('settings uses its own localized AJAX config; canceled confirmation never acquires the sync lock', async t => {
    const app = await boot('settings');
    t.after(() => app.close());
    delete app.window.ajax_object;
    idle(app);
    app.confirm(false);
    app.$('#sync-local-to-aws').trigger('click');
    assert.equal(app.latest('sync_local_to_aws'), undefined);
    assert.equal(app.$('#current-sync-state').val(), '');
    app.confirm(true);
    app.$('#sync-local-to-aws').trigger('click');
    const request = app.latest('sync_local_to_aws');
    assert.equal(request.options.data.nonce, 'fixture-nonce');
    assert.equal(request.options.data.type, 'media');
    assert.ok(app.$('.sync-button').toArray().every(button => button.disabled));
    request.reject({ message: 'Permission denied' });
    idle(app);
    assert.equal(app.$('#current-sync-state').val(), '');
    assert.ok(app.$('.sync-button').toArray().every(button => !button.disabled));
    assert.match(app.$('#sync-log-container').text(), /Permission denied/);
    assert.equal(app.$('#sync-local-to-aws-plugin').length, 0);
});

test('real status polling drives localized progress, timestamps, completion, and safe filename logs', async t => {
    const app = await boot('settings', { translations: { progress: 'Fortschritt', files: 'Dateien', uploaded: 'Uploaded' } });
    t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'processing', type: 'media', direction: 'upload', total_files: 4, processed_files: 1 });
    assert.equal(app.$('.smartsync-progress-container').prop('hidden'), false);
    assert.equal(app.$('[role="progressbar"]').attr('aria-valuenow'), '25.0');
    assert.equal(app.$('#progress-remaining').text(), '3');
    assert.equal(app.$('.smartsync-background-indicator').hasClass('active'), true);
    assert.equal(app.$('#sync-log-container .log-timestamp').length, 1);
    assert.equal(app.pollingTimers.size, 1);
    app.runTimer();
    app.latest('check_sync_status').resolve({
        status: 'completed', type: 'media', direction: 'upload', total_files: 4, processed_files: 4,
        successes: ['<img src=x onerror=alert(1)>.jpg'], errors: []
    });
    assert.equal(app.$('#sync-log-container img').length, 0);
    assert.match(app.$('#sync-log-container').text(), /<img src=x onerror=alert\(1\)>.jpg/);
    assert.equal(app.$('.smartsync-progress-container').prop('hidden'), true);
    assert.equal(app.$('.smartsync-background-indicator').hasClass('is-completed'), true);
    assert.equal(app.pollingTimers.size, 0);
    assert.ok(app.$('.sync-button').toArray().every(button => !button.disabled));
});

test('zero-total status stays finite and stop ignores late polling responses', async t => {
    const app = await boot('settings', { translations: { stopped: 'Stopped' } });
    t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'processing', type: 'media', direction: 'download', total_files: 0, processed_files: 0 });
    assert.equal(app.$('[role="progressbar"]').attr('aria-valuenow'), '0.0');
    assert.doesNotMatch(app.$('#sync-log-container').text(), /NaN|Infinity/);
    app.runTimer();
    const staleStatus = app.latest('check_sync_status');
    app.$('#stop-all-processes').trigger('click');
    app.latest('stop_all_processes').resolve({ message: 'Stopped' });
    staleStatus.resolve({ status: 'processing', type: 'media', direction: 'download', total_files: 5, processed_files: 1 });
    assert.equal(app.pollingTimers.size, 0);
    assert.equal(app.$('#current-sync-state').val(), '');
    assert.equal(app.$('.smartsync-background-indicator').hasClass('is-stopped'), true);
    assert.ok(app.$('.sync-button').toArray().every(button => !button.disabled));
});

test('status and start transport failures recheck server state before restoring usable buttons', async t => {
    const app = await boot('settings');
    t.after(() => app.close());
    app.latest('check_sync_status').fail();
    assert.ok(app.$('.sync-button').toArray().every(button => button.disabled));
    app.runTimer();
    idle(app);
    assert.ok(app.$('.sync-button').toArray().every(button => !button.disabled));
    app.$('#sync-aws-to-local').trigger('click');
    app.latest('sync_aws_to_local').fail();
    idle(app);
    assert.equal(app.$('#current-sync-state').val(), '');
    assert.ok(app.$('.sync-button').toArray().every(button => !button.disabled));
});

test('pending stop prevents a newer start and failure restores the existing idle state', async t => {
    const app = await boot('settings');
    t.after(() => app.close());
    idle(app);
    app.$('#stop-all-processes').trigger('click');
    assert.ok(app.$('.sync-button').toArray().every(button => button.disabled));
    app.$('#sync-local-to-aws').trigger('click');
    assert.equal(app.latest('sync_local_to_aws'), undefined);
    app.latest('stop_all_processes').fail();
    assert.ok(app.$('.sync-button').toArray().every(button => !button.disabled));
    app.$('#sync-local-to-aws').trigger('click');
    assert.ok(app.latest('sync_local_to_aws'));
});

test('toggle errors restore both previous on and previous off state without injecting HTML', async t => {
    const app = await boot('settings', { data: { use_cdn_for_media: true } });
    t.after(() => app.close());
    idle(app);
    const toggle = app.$('#use-cloudfront-media');
    toggle.prop('checked', false).trigger('change');
    assert.equal(toggle.prop('disabled'), true);
    app.latest('toggle_cdn').reject({ message: '<svg onload=alert(1)>bad</svg>' });
    assert.equal(toggle.prop('checked'), true);
    assert.equal(toggle.prop('disabled'), false);
    assert.equal(app.$('#sync-log-container svg').length, 0);
    const autoDelete = app.$('#auto-delete-media').prop('checked', true).trigger('change');
    app.latest('toggle_media_auto_delete').fail();
    assert.equal(autoDelete.prop('checked'), false);
    assert.equal(autoDelete.prop('disabled'), false);
});

test('analytics distinguishes unknown billing from zero and resets every field on errors', async t => {
    const app = await boot('settings', { translations: { cost_unavailable: 'Unavailable', error: 'Error' } });
    t.after(() => app.close());
    idle(app);
    app.$('#fetch-analytics-button').trigger('click');
    app.latest('fetch_cloud_analytics').resolve({ total_files: 0, total_storage: '0 B', price_in_dollars: null });
    assert.equal(app.$('#total-price').text(), 'Unavailable');
    app.$('#fetch-analytics-button').trigger('click');
    app.latest('fetch_cloud_analytics').resolve({ total_files: 1, total_storage: '1 B', price_in_dollars: 0 });
    assert.equal(app.$('#total-price').text(), '~ 0.00 USD');
    app.$('#fetch-analytics-button').trigger('click');
    app.latest('fetch_cloud_analytics').fail();
    ['#total-files', '#total-storage'].forEach(field => assert.equal(app.$(field).text(), 'Error'));
    assert.equal(app.$('#total-price').text(), 'Unavailable');
    assert.equal(app.$('#fetch-analytics-button').prop('disabled'), false);
});

test('actual completion logs are capped, decorated once, and clear without removing controls', async t => {
    const app = await boot('settings');
    t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'completed', direction: 'upload', successes: Array.from({ length: 150 }, (_, i) => 'file-' + i), errors: [] });
    assert.equal(app.$('#sync-log-container p').length, 100);
    assert.equal(app.$('#sync-log-container .log-timestamp').length, 100);
    app.$('#clear-logs-button').trigger('click');
    assert.equal(app.$('#sync-log-container p').length, 1);
    assert.equal(app.$('#sync-log-placeholder').length, 1);
    assert.equal(app.$('.sync-button').length, 2);
});

test('tabs support click and keyboard selection with matching accessibility state', async t => {
    const app = await boot('settings');
    t.after(() => app.close());
    app.$('#tab-button-media').trigger('click');
    assert.equal(app.$('#tab-media').prop('hidden'), false);
    assert.equal(app.$('#tab-general').prop('hidden'), true);
    assert.equal(app.window.getComputedStyle(app.$('#tab-general')[0]).display, 'none');
    assert.equal(app.$('#tab-button-media').attr('aria-selected'), 'true');
    app.$('#tab-button-media').trigger(app.$.Event('keydown', { key: 'ArrowRight' }));
    assert.equal(app.window.document.activeElement.id, 'tab-button-assets');
    assert.equal(app.$('#tab-assets').prop('hidden'), false);
    app.$('#tab-button-assets').trigger(app.$.Event('keydown', { key: 'Home' }));
    assert.equal(app.window.document.activeElement.id, 'tab-button-general');
});
