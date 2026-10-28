const test = require('node:test');
const assert = require('node:assert/strict');
const { boot } = require('./harness.cjs');

test('one shared estimate uses explicit units, stays steady between batches and marks stale progress', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    app.window.Date.now = () => 2000000;
    let timing;
    app.$(app.window.document).on('smartsync:timing', (_, value) => { timing = value; });
    const run = { status: 'processing', run_id: 'estimate-run', type: 'media', direction: 'upload', total_files: 13020,
        processed_files: 0, started_at: 900, server_time: 1000 };
    app.latest('check_sync_status').resolve(run);
    assert.equal(app.$('#progress-time').text(), 'Calculating…');
    app.runTimer(); app.latest('check_sync_status').resolve({ ...run, processed_files: 10, server_time: 1010 });
    assert.equal(timing.eta, 13010);
    assert.equal(app.$('#progress-time').text(), '~3 hr 37 min');
    assert.equal(app.$('#sync-widget-eta').text(), '~3 hr 37 min');
    assert.equal(app.$('#progress-time').parent().find('.smartsync-progress-stat-label').text(), 'Time remaining');
    app.runTimer(); app.latest('check_sync_status').resolve({ ...run, processed_files: 10, server_time: 1020 });
    assert.equal(timing.eta, 13010, 'unchanged progress must not make the ETA grow');
    app.runTimer(); app.latest('check_sync_status').resolve({ ...run, processed_files: 10, server_time: 1080 });
    assert.equal(timing.eta, null);
    assert.equal(app.$('#progress-time').text(), 'Updating…');
    assert.equal(app.$('#sync-widget-eta').text(), 'Updating…');
    app.runTimer(); app.latest('check_sync_status').resolve({ ...run, processed_files: 20, server_time: 1090 });
    assert.equal(typeof timing.eta, 'number');
    assert.equal(app.$('#progress-time').text(), app.$('#sync-widget-eta').text());
    app.runTimer(); app.latest('check_sync_status').fail();
    assert.equal(app.$('#progress-time').text(), 'Updating…');
});

test('ETA units cover short waits, days and localized labels without HTML interpretation', async t => {
    for (const [remaining, expected] of [[30, '<1 min'], [130, '~3 min'], [90000, '~1 d 1 hr']]) {
        const app = await boot('settings'); t.after(() => app.close());
        app.window.Date.now = () => 2000000;
        const run = { status: 'processing', run_id: 'eta-' + remaining, type: 'assets', direction: 'upload', total_files: remaining + 10, processed_files: 0, server_time: 1000 };
        app.latest('check_sync_status').resolve(run);
        app.runTimer(); app.latest('check_sync_status').resolve({ ...run, processed_files: 10, server_time: 1010 });
        assert.equal(app.$('#progress-time').text(), expected);
    }
    const app = await boot('settings', { translations: { eta_minutes: '<b>~%s minutes</b>' } }); t.after(() => app.close());
    app.window.Date.now = () => 2000000;
    const run = { status: 'processing', run_id: 'localized-eta', type: 'media', direction: 'upload', total_files: 140, processed_files: 0, server_time: 1000 };
    app.latest('check_sync_status').resolve(run);
    app.runTimer(); app.latest('check_sync_status').resolve({ ...run, processed_files: 10, server_time: 1010 });
    assert.equal(app.$('#progress-time').text(), '<b>~3 minutes</b>');
    assert.equal(app.$('#progress-time b, #sync-widget-eta b').length, 0);
});
