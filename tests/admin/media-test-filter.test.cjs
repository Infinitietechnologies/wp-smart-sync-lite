const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, render } = require('./harness.cjs');

test('a successful format test explains the remaining CDN step in logs and notification', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'completed', run_id: 'png-test', type: 'media', direction: 'upload', total_files: 38, processed_files: 38, filtered_media_upload: true, media_cdn_sync_ready: false });
    assert.match(app.$('#sync-log-container').text(), /Selected file types synced successfully/);
    assert.match(app.$('.smartsync-notice-message').text(), /enable media cloud delivery to test these file types/);
    assert.match(app.$('.smartsync-status-title').text(), /File-type test complete/);
    app.$('#media-test-filter input[value="png"]').prop('checked', true).trigger('change');
    assert.equal(app.$('#media-test-cdn-note').prop('hidden'), false);
    app.$('#media-test-reset').trigger('click');
    assert.equal(app.$('#media-test-cdn-note').prop('hidden'), true);
});

test('enabling limited CDN delivery shows its scope and disables local cleanup', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'idle' });
    app.$('#use-cloudfront-media').prop('checked', true).trigger('change');
    app.latest('toggle_cdn').resolve({ cdn_test_extensions: ['png'] });
    assert.match(app.$('#media-cdn-test-status').text(), /Temporary CDN test: PNG/);
    assert.equal(app.$('#media-cdn-test-status').prop('hidden'), false);
    assert.equal(app.$('#auto-delete-media').prop('disabled'), true);
    assert.equal(app.$('#auto-delete-media').prop('checked'), false);
    app.$('#use-cloudfront-media').prop('checked', false).trigger('change');
    app.latest('toggle_cdn').resolve({ cdn_test_extensions: [] });
    assert.equal(app.$('#media-cdn-test-status').prop('hidden'), true);
    assert.equal(app.$('#auto-delete-media').prop('disabled'), false);
});

test('temporary file types appear only on Media and default to all', async t => {
    const html = render('settings');
    assert.match(html, /id="media-test-filter"/);
    assert.match(html, /value="svg"/);
    assert.match(html, /value="png"/);
    const app = await boot('settings'); t.after(() => app.close());
    assert.equal(app.$('#media-test-filter').prop('disabled'), true);
    app.latest('check_sync_status').resolve({ status: 'idle' });
    assert.equal(app.$('#media-test-filter').prop('disabled'), false);
    assert.equal(app.$('#media-test-selection').text(), 'All supported media');
    app.$('#sync-local-to-aws').trigger('click');
    assert.equal(Object.hasOwn(app.latest('sync_local_to_aws').options.data, 'media_test_extensions'), false);
});

test('selected Media formats go only to the manual upload and reset without saving', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'idle' });
    app.$('#media-test-filter input[value="svg"]').prop('checked', true).trigger('change');
    app.$('#media-test-filter input[value="png"]').prop('checked', true).trigger('change');
    assert.equal(app.$('#media-test-selection').text(), 'PNG, SVG');
    app.$('#sync-local-to-aws').trigger('click');
    const request = app.latest('sync_local_to_aws');
    assert.deepEqual(request.options.data.media_test_extensions, ['png', 'svg']);
    assert.equal(app.$('#media-test-filter').prop('disabled'), true);
    request.resolve({ total_files: 2, skipped_files: 0 });
    app.latest('check_sync_status').resolve({ status: 'completed', type: 'media', direction: 'upload', total_files: 2, processed_files: 2 });
    app.$('#media-test-reset').trigger('click');
    assert.equal(app.$('#media-test-selection').text(), 'All supported media');
    app.$('#sync-local-to-aws-plugin').trigger('click');
    assert.equal(Object.hasOwn(app.latest('sync_local_to_aws').options.data, 'media_test_extensions'), false);
});
