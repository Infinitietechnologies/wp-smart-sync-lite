const test = require('node:test');
const assert = require('node:assert/strict');
const { boot } = require('./harness.cjs');

test('custom confirmations keep supplied text literal, cancel safely and approve only once', async t => {
    const message = '<img src=x onerror=alert(1)> Upload these files?';
    const app = await boot('settings', { confirmations: 'manual', translations: { confirm_sync_local_to_aws: message } });
    t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'idle' });
    app.$('#tab-button-media').trigger('click');
    const trigger = app.$('#sync-local-to-aws')[0]; trigger.focus(); trigger.click();
    const dialog = app.$('.smartsync-confirm-dialog');
    assert.equal(dialog.prop('open'), true);
    assert.equal(dialog.find('.smartsync-confirm-title').text(), 'Upload media?');
    assert.equal(dialog.find('.smartsync-confirm-message').text(), message);
    assert.equal(dialog.find('img').length, 0);
    assert.equal(app.window.document.activeElement.className, 'smartsync-confirm-cancel');
    trigger.click();
    assert.equal(app.$('.smartsync-confirm-dialog').length, 1);
    assert.equal(app.latest('sync_local_to_aws'), undefined);
    dialog[0].dispatchEvent(new app.window.Event('cancel', { cancelable: true }));
    assert.equal(app.$('.smartsync-confirm-dialog').length, 0);
    assert.equal(app.window.document.activeElement, trigger);
    assert.equal(app.$('#sync-mode-media').prop('disabled'), false);
    trigger.click();
    const accept = app.$('.smartsync-confirm-accept')[0]; accept.click(); accept.click();
    assert.equal(app.requests.filter(request => request.action === 'sync_local_to_aws').length, 1);
    assert.equal(app.$('#sync-mode-media').prop('disabled'), true);
});

test('an upload confirmation cannot start work after a running sync is discovered', async t => {
    const app = await boot('settings', { confirmations: 'manual' }); t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'idle' });
    app.$('#sync-local-to-aws').trigger('click');
    app.refreshStatus();
    app.latest('check_sync_status').resolve({ status: 'processing', run_id: 'another-run', type: 'assets', direction: 'upload', total_files: 10, processed_files: 1 });
    app.$('.smartsync-confirm-accept')[0].click();
    assert.equal(app.latest('sync_local_to_aws'), undefined);
});

test('a stop confirmation cannot stop a newer main-page run', async t => {
    const app = await boot('settings', { confirmations: 'manual' }); t.after(() => app.close());
    const running = { status: 'processing', run_id: 'run-a', type: 'media', direction: 'upload', total_files: 10, processed_files: 1 };
    app.latest('check_sync_status').resolve(running);
    app.$('#stop-all-processes').trigger('click');
    assert.equal(app.$('.smartsync-confirm-dialog').attr('data-tone'), 'danger');
    assert.equal(app.$('.smartsync-confirm-cancel').text(), 'Keep syncing');
    app.runTimer(); app.latest('check_sync_status').resolve({ ...running, run_id: 'run-b' });
    app.$('.smartsync-confirm-accept')[0].click();
    assert.equal(app.latest('stop_all_processes'), undefined);
});

test('a standalone widget stop confirmation cannot stop a newer run', async t => {
    const running = { status: 'processing', run_id: 'run-a', type: 'media', direction: 'upload', total_files: 10, processed_files: 1 };
    const app = await boot('domain', { confirmations: 'manual', widget: { seed: running } }); t.after(() => app.close());
    app.latest('check_sync_status').resolve(running);
    app.$('.smartsync-widget-stop').trigger('click');
    app.runTimer(); app.latest('check_sync_status').resolve({ ...running, run_id: 'run-b' });
    app.$('.smartsync-confirm-accept')[0].click();
    assert.equal(app.latest('stop_all_processes'), undefined);
});

test('navigation cancels an accepted dialog while its closing animation is pending', async t => {
    const app = await boot('settings', { confirmations: 'manual' }); t.after(() => app.close());
    app.window.matchMedia = () => ({ matches: false });
    app.latest('check_sync_status').resolve({ status: 'idle' });
    app.$('#sync-local-to-aws').trigger('click');
    app.$('.smartsync-confirm-accept')[0].click();
    app.window.dispatchEvent(new app.window.Event('pagehide'));
    assert.equal(app.$('.smartsync-confirm-dialog').length, 0);
    await new Promise(resolve => setTimeout(resolve, 220));
    assert.equal(app.latest('sync_local_to_aws'), undefined);
});

test('unsupported dialogs fail closed with an actionable notification', async t => {
    const app = await boot('settings', { confirmations: 'manual' }); t.after(() => app.close());
    app.window.HTMLDialogElement.prototype.showModal = undefined;
    app.latest('check_sync_status').resolve({ status: 'idle' });
    app.$('#sync-local-to-aws').trigger('click');
    assert.equal(app.$('.smartsync-confirm-dialog').length, 0);
    assert.equal(app.latest('sync_local_to_aws'), undefined);
    assert.match(app.$('.smartsync-notice-message').text(), /Refresh the page/);
});
