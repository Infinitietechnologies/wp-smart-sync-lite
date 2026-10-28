const test = require('node:test');
const assert = require('node:assert/strict');
const { boot } = require('./harness.cjs');

test('upload modes default to incremental and stay locked through status/start/stop requests', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    assert.equal(app.$('#sync-mode-media input:checked').val(), 'incremental');
    assert.equal(app.$('#sync-mode-assets').length, 0);
    assert.ok(app.$('.sync-mode').toArray().every(control => control.disabled));
    app.latest('check_sync_status').resolve({ status: 'idle' });
    assert.ok(app.$('.sync-mode').toArray().every(control => !control.disabled));
    app.$('#sync-local-to-aws').trigger('click');
    assert.equal(app.latest('sync_local_to_aws').options.data.mode, 'incremental');
    assert.ok(app.$('.sync-mode').toArray().every(control => control.disabled));
    app.latest('sync_local_to_aws').resolve({ total_files: 10, skipped_files: 1784, scanned_files: 1794 });
    assert.match(app.$('#sync-log-container').text(), /Uploads: 10 · Unchanged: 1784/);
    app.latest('check_sync_status').resolve({ status: 'processing', direction: 'upload', type: 'media', total_files: 10, processed_files: 2, skipped_files: 1784 });
    assert.equal(app.$('.smartsync-status-detail').text(), '2 / 10 files · 1784 unchanged');
    app.$('#stop-all-processes').trigger('click');
    assert.ok(app.$('.sync-mode').toArray().every(control => control.disabled));
    app.latest('stop_all_processes').resolve({ message: 'Stopped' });
    assert.ok(app.$('.sync-mode').toArray().every(control => !control.disabled));
});

test('full resync has explicit confirmation and unchanged runs finish without a misleading background label', async t => {
    const app = await boot('settings', { translations: { confirm_full_resync: 'Upload everything again?' } }); t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'idle' });
    app.$('#sync-mode-media-full').prop('checked', true).trigger('change');
    let prompt;
    app.window.document.addEventListener('smartsync:confirmation-open', event => { prompt = event.detail.message; });
    app.confirm(false);
    app.$('#sync-local-to-aws').trigger('click');
    assert.equal(prompt, 'Upload everything again?');
    assert.equal(app.latest('sync_local_to_aws'), undefined);
    app.confirm(true);
    app.$('#sync-local-to-aws').trigger('click');
    assert.equal(app.latest('sync_local_to_aws').options.data.mode, 'full');
    app.latest('sync_local_to_aws').resolve({ total_files: 0, skipped_files: 1784, scanned_files: 1784 });
    app.latest('check_sync_status').resolve({ status: 'completed', run_id: 'noop', direction: 'upload', type: 'media', total_files: 0, processed_files: 0, skipped_files: 1784, scanned_files: 1784 });
    assert.equal(app.$('.smartsync-status-detail').text(), 'No transfers needed · 1784 unchanged');
    assert.ok(app.$('.sync-mode').toArray().every(control => !control.disabled));
    app.$('#sync-aws-to-local').trigger('click');
    assert.equal(Object.hasOwn(app.latest('sync_aws_to_local').options.data, 'mode'), false);
});
