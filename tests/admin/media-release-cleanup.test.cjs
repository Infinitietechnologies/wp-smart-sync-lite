const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, render } = require('./harness.cjs');

test('Media renders normal sync controls without temporary testing UI', () => {
    const html = render('settings');
    assert.doesNotMatch(html, /media-test-filter|media-cdn-test-status|Temporary testing|Reset to all/);
    assert.match(html, /id="sync-mode-media-incremental"/);
    assert.match(html, /id="sync-mode-media-full"/);
});

test('normal Media upload submits no temporary file selection', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    app.window.smartsyncConfirm = (options, approve) => approve();
    app.latest('check_sync_status').resolve({ status: 'idle' });
    app.$('#sync-local-to-aws').trigger('click');
    const request = app.latest('sync_local_to_aws');
    assert.equal(request.options.data.type, 'media');
    assert.equal(request.options.data.mode, 'incremental');
    assert.equal(Object.hasOwn(request.options.data, 'media_test_extensions'), false);
});

test('old partial-run status no longer displays temporary CDN instructions', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'completed', run_id: 'legacy', type: 'media', direction: 'upload', total_files: 1, processed_files: 1, filtered_media_upload: true, media_cdn_sync_ready: false, cdn_test_extensions: ['png'] });
    const log = app.$('#sync-log-container').text();
    assert.doesNotMatch(log, /Selected file types|to test these file types|Temporary CDN/);
    assert.equal(app.$('#media-cdn-test-status').length, 0);
});
