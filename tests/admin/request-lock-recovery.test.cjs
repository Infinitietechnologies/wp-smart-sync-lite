const test = require('node:test');
const assert = require('node:assert/strict');
const { boot } = require('./harness.cjs');

test('idle busy state offers recovery and unlocks controls only after a fresh idle response', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'idle', operation_busy: true, retry_after: 509 });
    assert.equal(app.$('#recover-sync-operation').prop('hidden'), false);
    assert.equal(app.$('#stop-all-processes').prop('hidden'), true);
    app.$('#recover-sync-operation').trigger('click').trigger('click');
    assert.equal(app.requests.filter(r => r.action === 'recover_sync_operation').length, 1);
    app.latest('recover_sync_operation').resolve({ message: 'The operation is unlocked.' });
    assert.equal(app.$('#sync-local-to-aws').prop('disabled'), true);
    app.latest('check_sync_status').resolve({ status: 'idle', operation_busy: false });
    assert.equal(app.$('#recover-sync-operation').prop('hidden'), true);
    assert.equal(app.$('#sync-local-to-aws').prop('disabled'), false);
    assert.equal(app.$('#settings-activity-status').text(), 'Ready');
    assert.equal(app.pollingTimers.size, 0);
});

test('recovery rejection preserves the busy state and shows the server explanation', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'idle', operation_busy: true });
    app.$('#recover-sync-operation').trigger('click');
    app.latest('recover_sync_operation').reject('Another request still owns this operation.');
    app.latest('check_sync_status').resolve({ status: 'idle', operation_busy: true });
    assert.match(app.$('#sync-log-container').text(), /Another request still owns/);
    assert.equal(app.$('#recover-sync-operation').prop('disabled'), false);
    assert.equal(app.$('#sync-local-to-aws').prop('disabled'), true);
});

test('stopping a transfer retains recovery control until its current request releases the lease', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    app.window.smartsyncConfirm = (options, approve) => approve();
    app.latest('check_sync_status').resolve({ status: 'processing', run_id: 'running', type: 'media', direction: 'upload', operation_busy: true });
    assert.equal(app.$('#recover-sync-operation').prop('hidden'), true);
    assert.equal(app.$('#stop-all-processes').prop('hidden'), false);
    app.$('#stop-all-processes').trigger('click');
    app.latest('stop_all_processes').resolve({ message: 'Queued work stopped.', operation_busy: true });
    assert.equal(app.$('#recover-sync-operation').prop('hidden'), false);
    assert.equal(app.$('#sync-local-to-aws').prop('disabled'), true);
    app.runTimer();
    app.latest('check_sync_status').resolve({ status: 'idle', operation_busy: false });
    assert.equal(app.$('#recover-sync-operation').prop('hidden'), true);
    assert.equal(app.$('#sync-local-to-aws').prop('disabled'), false);
});
