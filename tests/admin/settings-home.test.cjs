const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, render } = require('./harness.cjs');

test('settings overview presents one connection action and accessible native delivery switches', () => {
    const html = render('settings', { service_provider: 'bunny', bucket: 'website-media', region: 'DE', bunnyStoragePassword: 'synthetic-storage-password' });
    assert.match(html, /smartsync-settings/);
    assert.match(html, /Bunny CDN/);
    assert.doesNotMatch(html, /<table|class="tabs-content"|smartsync-contextual-description/);
    assert.equal((html.match(/id="reconfigure-aws"/g) || []).length, 1);
    assert.equal((html.match(/role="switch"/g) || []).length, 3);
    assert.doesNotMatch(html, /synthetic-storage-password/);
});

test('activity stays compact, deduplicates unchanged progress, and opens errors or requested logs', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    const details = app.$('#settings-activity-details');
    app.latest('check_sync_status').resolve({ status: 'idle' });
    assert.equal(details.prop('open'), false);
    assert.equal(app.$('#stop-all-processes').prop('hidden'), true);
    app.refreshStatus();
    const run = { status: 'processing', run_id: 'home-test', direction: 'upload', type: 'media', total_files: 10, processed_files: 2 };
    app.latest('check_sync_status').resolve(run);
    assert.equal(app.$('#stop-all-processes').prop('hidden'), false);
    assert.equal(details.prop('open'), false);
    assert.equal(app.$('#settings-progress-slot .smartsync-progress-container').length, 1);
    assert.equal(app.$('#sync-log-container p').length, 1);
    app.runTimer(); app.latest('check_sync_status').resolve(run);
    assert.equal(app.$('#sync-log-container p').length, 1);
    const requestsBeforeClear = app.requests.length;
    app.$('#clear-logs-button').trigger('click');
    assert.equal(app.requests.length, requestsBeforeClear);
    assert.equal(app.$('#sync-mode-media').prop('disabled'), true);
    assert.equal(app.$('#stop-all-processes').prop('hidden'), false);
    assert.equal(details.prop('open'), false);
    app.window.location.hash = '#sync-log-container';
    app.$(app.window).trigger('hashchange');
    assert.equal(details.prop('open'), true);
    details.prop('open', false);
    app.runTimer(); app.latest('check_sync_status').resolve({ ...run, status: 'failed', errors: ['<script>unsafe()</script>'] });
    assert.equal(details.prop('open'), true);
    assert.equal(app.$('#settings-activity-status').text(), 'Needs attention');
    assert.equal(app.$('#sync-log-container script').length, 0);
    assert.equal(app.$('#stop-all-processes').prop('hidden'), true);
});

test('upload hints follow the selected mode without opening advanced help or issuing requests', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'idle' });
    const count = app.requests.length;
    app.$('#sync-mode-media-full').prop('checked', true).trigger('change');
    assert.equal(app.$('#sync-mode-help-media').text(), 'Uploads every selected local file again.');
    app.$('#sync-mode-media-incremental').prop('checked', true).trigger('change');
    assert.equal(app.$('#sync-mode-help-media').text(), 'Skips unchanged files and retries unsuccessful uploads.');
    assert.equal(app.$('.settings-sync-help').toArray().every(element => !element.open), true);
    assert.equal(app.requests.length, count);
});
