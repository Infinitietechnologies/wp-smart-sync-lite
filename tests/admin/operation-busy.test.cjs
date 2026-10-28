const test = require('node:test');
const assert = require('node:assert/strict');
const { boot } = require('./harness.cjs');

test('idle with a live operation lease displays finishing and stays locked until it clears', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'idle', operation_busy: true, retry_after: 60 });
    assert.equal(app.$('#settings-activity-status').text(), 'Another operation is finishing…');
    assert.equal(app.$('#settings-activity-status').attr('data-state'), 'busy');
    assert.equal(app.$('.sync-button').toArray().every(button => button.disabled), true);
    assert.equal(app.$('.sync-mode').toArray().every(group => group.disabled), true);
    assert.equal(app.$('#stop-all-processes').prop('hidden'), true);
    app.$('#sync-local-to-aws').trigger('click');
    assert.equal(app.latest('sync_local_to_aws'), undefined);
    assert.equal(app.$('.smartsync-notice-item[data-type="warning"]').length, 1);
    app.runTimer();
    app.latest('check_sync_status').resolve({ status: 'idle', operation_busy: false, retry_after: 0 });
    assert.equal(app.$('#settings-activity-status').text(), 'Ready');
    assert.equal(app.$('.sync-button').toArray().every(button => !button.disabled), true);
    assert.equal(app.$('.sync-mode').toArray().every(group => !group.disabled), true);
});

test('confirmation cannot start a sync if another operation acquires the lease while it is open', async t => {
    const app = await boot('settings', { confirmations: 'manual' }); t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'idle' });
    app.$('#sync-local-to-aws').trigger('click');
    app.refreshStatus();
    app.latest('check_sync_status').resolve({ status: 'idle', operation_busy: true });
    app.$('.smartsync-confirm-accept')[0].click();
    assert.equal(app.latest('sync_local_to_aws'), undefined);
    assert.equal(app.$('#settings-activity-status').attr('data-state'), 'busy');
});
