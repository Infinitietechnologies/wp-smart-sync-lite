const test = require('node:test');
const assert = require('node:assert/strict');
const { boot } = require('./harness.cjs');
const running = { status: 'processing', run_id: 'run-one', type: 'media', direction: 'upload', total_files: 100, processed_files: 10,
    provider: 'bunny', storage: 'website-media', started_at: 900, server_time: 1000, failed_files: 1, current_file: 'wp-content/uploads/photo.jpg' };
function snapshot(app) {
    const storage = {};
    for (const kind of ['localStorage', 'sessionStorage']) {
        storage[kind] = {};
        for (let index = 0; index < app.window[kind].length; index++) {
            const key = app.window[kind].key(index); storage[kind][key] = app.window[kind].getItem(key);
        }
    }
    return storage;
}


test('expanded preference, elapsed time and an observed ETA survive navigation with site isolation', async t => {
    const first = await boot('settings'); t.after(() => first.close());
    first.latest('check_sync_status').resolve(running);
    first.$('.smartsync-widget-toggle').trigger('click');
    first.runTimer();
    first.latest('check_sync_status').resolve({ ...running, processed_files: 20, server_time: 1010 });
    assert.equal(first.$('#sync-widget-eta').text(), '~2 min');
    const stored = snapshot(first);
    const next = await boot('domain', { data: { service_provider: 'bunny' }, storage: stored }); t.after(() => next.close());
    next.latest('check_sync_status').resolve({ ...running, processed_files: 30, server_time: 1020 });
    assert.equal(next.$('.smartsync-widget-toggle').attr('aria-expanded'), 'true');
    assert.equal(next.$('#sync-widget-elapsed').text(), '02:00');
    assert.equal(next.$('#sync-widget-eta').text(), '~2 min');
    const other = await boot('domain', { data: { service_provider: 'bunny' }, storage: stored, widget: { scope: 'another-site' } }); t.after(() => other.close());
    other.latest('check_sync_status').resolve(running);
    assert.equal(other.$('.smartsync-widget-toggle').attr('aria-expanded'), 'false');
});

test('failure remains until dismissed and filenames/errors are rendered safely across navigation', async t => {
    const app = await boot('domain', { data: { service_provider: 'bunny' } }); t.after(() => app.close());
    const failure = { ...running, status: 'failed', finished_at: 1020, current_file: '<img src=x onerror=alert(1)>.jpg', errors: ['<script>bad()</script>'] };
    app.latest('check_sync_status').resolve(failure);
    app.$('.smartsync-widget-toggle').trigger('click');
    assert.equal(app.$('.smartsync-background-indicator img, .smartsync-background-indicator script').length, 0);
    assert.equal(app.$('.smartsync-widget-error').text(), '<script>bad()</script>');
    assert.equal(app.$('#sync-widget-elapsed').text(), '02:00');
    app.$('.smartsync-widget-terminal button').trigger('click');
    assert.equal(app.$('.smartsync-background-indicator').prop('hidden'), true);
    const next = await boot('domain', { data: { service_provider: 'bunny' }, storage: snapshot(app) }); t.after(() => next.close());
    next.latest('check_sync_status').resolve(failure);
    assert.equal(next.$('.smartsync-background-indicator').prop('hidden'), true);
    next.refreshStatus(); next.latest('check_sync_status').resolve({ ...running, run_id: 'new-run' });
    assert.equal(next.$('.smartsync-background-indicator').prop('hidden'), false);
});

test('stop confirmation, failure/retry and late status responses preserve the running lock correctly', async t => {
    const app = await boot('domain', { data: { service_provider: 'bunny' } }); t.after(() => app.close());
    app.latest('check_sync_status').resolve(running);
    app.$('.smartsync-widget-toggle').trigger('click');
    app.confirm(false); app.$('.smartsync-widget-stop').trigger('click');
    assert.equal(app.latest('stop_all_processes'), undefined);
    app.confirm(true); app.runTimer(); const stale = app.latest('check_sync_status');
    app.$('.smartsync-widget-stop').trigger('click');
    assert.equal(app.latest('stop_all_processes').options.data.nonce, 'fixture-nonce');
    app.latest('stop_all_processes').reject('Stop denied.');
    assert.match(app.$('.smartsync-widget-error').text(), /Stop denied/);
    app.latest('check_sync_status').resolve(running);
    assert.equal(app.$('.smartsync-widget-error').prop('hidden'), false);
    app.$('.smartsync-widget-stop').trigger('click');
    app.latest('stop_all_processes').resolve({ message: 'Stopped' });
    stale.resolve({ ...running, processed_files: 99 });
    assert.equal(app.$('.smartsync-background-indicator').hasClass('is-stopped'), true);
    app.latest('check_sync_status').resolve({ status: 'idle' });
    assert.equal(app.$('.smartsync-background-indicator').hasClass('is-stopped'), true);
});

test('Escape collapses into its header, storage corruption is tolerated, and completion expires once', async t => {
    const app = await boot('domain', { data: { service_provider: 'bunny' }, storage: { localStorage: { 'smartsync.widget.fixture-site': 'true' } } }); t.after(() => app.close());
    app.latest('check_sync_status').resolve(running);
    app.$('.smartsync-widget-toggle').trigger('click');
    app.$('.smartsync-widget-stop').trigger('keydown', { key: 'Escape' });
    const event = new app.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true });
    app.$('.smartsync-widget-stop')[0].dispatchEvent(event);
    assert.equal(app.$('.smartsync-widget-toggle').attr('aria-expanded'), 'false');
    assert.equal(app.window.document.activeElement, app.$('.smartsync-widget-toggle')[0]);
    app.runTimer(); app.latest('check_sync_status').resolve({ ...running, status: 'completed', finished_at: 1010, processed_files: 100 });
    assert.equal(app.$('.smartsync-widget-terminal').prop('hidden'), false);
    app.runTimer();
    assert.equal(app.$('.smartsync-background-indicator').prop('hidden'), true);
});

test('one footer switches between logs, results and settings without duplicating actions', async t => {
    const app = await boot('domain', { data: { service_provider: 'bunny' } }); t.after(() => app.close());
    const cases = [['processing', 'View logs', 'Stop sync'], ['completed', 'View results', 'Dismiss notification'],
        ['failed', 'View logs', 'Dismiss notification'], ['stopped', 'Sync settings', 'Dismiss notification']];
    for (const [status, link, button] of cases) {
        app.$(app.window.document).trigger('smartsync:status', [{ ...running, status, run_id: 'footer-' + status, errors: [] }]);
        const footer = app.$('.smartsync-widget-actions');
        assert.equal(footer.length, 1); assert.equal(footer.find('a').length, 1); assert.equal(footer.find('button').length, 1);
        assert.equal(footer.find('a').text(), link); assert.equal(footer.find('button').text(), button);
        app.$('.smartsync-widget-toggle').trigger('click');
        assert.equal(app.$('#smartsync-widget-details').attr('aria-hidden'), app.$('.smartsync-widget-toggle').attr('aria-expanded') === 'true' ? 'false' : 'true');
    }
});
