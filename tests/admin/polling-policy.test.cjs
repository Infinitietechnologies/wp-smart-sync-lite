const test = require('node:test');
const assert = require('node:assert/strict');
const { boot } = require('./harness.cjs');
const running = { status: 'processing', run_id: 'poll-run', direction: 'upload', type: 'media', total_files: 10, processed_files: 2 };
const count = app => app.requests.filter(request => request.action === 'check_sync_status').length;

for (const view of ['settings', 'wizard', 'domain']) {
    test(view + ' lets a queued local-server status request wait without overlap or losing the active run', async t => {
        const app = await boot(view, { statusTimeout: 180000 }); t.after(() => app.close());
        if (view === 'wizard') app.runTimer();
        assert.equal(app.latest('check_sync_status').options.timeout, 180000);
        app.latest('check_sync_status').resolve(running);
        app.runTimer();
        const pending = app.latest('check_sync_status');
        const before = count(app);
        for (let i = 0; i < 10; i++) app.refreshStatus();
        assert.equal(count(app), before, 'queued local requests must not overlap');
        pending.deferred.reject({ status: 0 }, 'timeout');
        if (view === 'settings') {
            assert.equal(app.$('.sync-button').toArray().every(button => button.disabled), true);
            assert.match(app.$('#sync-log-container').text(), /taking longer to respond/);
            assert.equal(app.$('#sync-log-container p.error').length, 0);
        }
        app.runTimer();
        app.latest('check_sync_status').resolve({ ...running, processed_files: 3 });
        assert.equal(app.$('.smartsync-status-detail').text().includes('3 / 10'), true);
    });
}

for (const view of ['settings', 'wizard', 'domain']) {
    test(view + ' retains a hidden cross-tab refresh until visible', async t => {
        const app = await boot(view); t.after(() => app.close());
        if (view === 'wizard') app.runTimer();
        app.latest('check_sync_status').resolve({ status: 'idle' });
        let hidden = true;
        Object.defineProperty(app.window.document, 'hidden', { configurable: true, get: () => hidden });
        const before = count(app);
        app.refreshStatus();
        assert.equal(count(app), before);
        hidden = false; app.$(app.window.document).trigger('visibilitychange');
        assert.equal(count(app), before + 1);
    });
}

for (const view of ['settings', 'wizard', 'domain']) {
    test(view + ' does not check sync status when form controls gain focus', async t => {
        const app = await boot(view, { data: view === 'domain' ? { service_provider: 'aws', use_cloudfront: true } : {} });
        t.after(() => app.close());
        if (view === 'wizard') app.runTimer();
        app.latest('check_sync_status').resolve({ status: 'idle', operation_busy: false });
        const before = count(app);
        const control = app.$(view === 'settings' ? '#tab-button-media' : view === 'wizard' ? '#aws_iam_key' : '#custom-domain');
        for (let i = 0; i < 5; i++) { control.trigger('blur'); control.trigger('focus'); }
        assert.equal(count(app), before);
    });
}

for (const view of ['settings', 'wizard', 'domain']) {
    test(view + ' stops automatic sync-status checks after idle, completion and failure', async t => {
        for (const status of ['idle', 'completed', 'failed']) {
            const app = await boot(view); t.after(() => app.close());
            if (view === 'wizard') app.runTimer();
            app.latest('check_sync_status').resolve({ ...running, status });
            const before = count(app);
            for (let i = 0; i < 12; i++) app.runTimer();
            assert.equal(count(app), before, 'terminal state must not schedule more HTTP requests');
            for (let i = 0; i < 5; i++) {
                app.window.dispatchEvent(new app.window.Event('focus'));
                app.window.dispatchEvent(new app.window.Event('online'));
                app.$(app.window.document).trigger('visibilitychange');
            }
            assert.equal(count(app), before, 'known inactive runs must stay quiet when the window returns');
            app.refreshStatus();
            assert.equal(count(app), before + 1, 'an explicit refresh still works');
        }
    });
}

for (const view of ['settings', 'wizard', 'domain']) {
    test(view + ' backs off failed status requests and stops after five failures', async t => {
        const app = await boot(view); t.after(() => app.close());
        if (view === 'wizard') app.runTimer();
        const delays = []; const schedule = app.window.setTimeout;
        app.window.setTimeout = (callback, delay, ...args) => { if (delay >= 1000) delays.push(delay); return schedule(callback, delay, ...args); };
        for (let i = 0; i < 5; i++) {
            app.latest('check_sync_status').fail('Connection unavailable');
            if (i < 4) app.runTimer();
        }
        assert.deepEqual(delays, [5000, 10000, 20000, 40000]);
        const before = count(app);
        for (let i = 0; i < 10; i++) app.runTimer();
        assert.equal(count(app), before);
        app.refreshStatus();
        assert.equal(count(app), before + 1);
        app.latest('check_sync_status').resolve(running);
        app.runTimer();
        assert.equal(count(app), before + 2);
    });
}

test('hidden and offline pages stop checking; returning coalesces overlapping requests', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    let hidden = false; let online = true;
    Object.defineProperty(app.window.document, 'hidden', { configurable: true, get: () => hidden });
    Object.defineProperty(app.window.navigator, 'onLine', { configurable: true, get: () => online });
    app.latest('check_sync_status').resolve(running);
    hidden = true; app.$(app.window.document).trigger('visibilitychange');
    app.runTimer(); assert.equal(count(app), 1);
    online = false; hidden = false; app.$(app.window.document).trigger('visibilitychange');
    assert.equal(count(app), 1);
    online = true; app.window.dispatchEvent(new app.window.Event('online'));
    assert.equal(count(app), 2);
    app.window.dispatchEvent(new app.window.Event('focus'));
    app.refreshStatus(); app.refreshStatus();
    assert.equal(count(app), 2);
    assert.equal(app.latest('check_sync_status').options.timeout, 15000);
    app.latest('check_sync_status').resolve({ ...running, status: 'failed' });
    app.runTimer(); assert.equal(count(app), 2);
    app.window.dispatchEvent(new app.window.StorageEvent('storage', { key: 'smartsync.sync-signal.fixture-site' }));
    assert.equal(count(app), 3, 'a same-site cross-tab signal performs one refresh');
});

test('authentication failure pauses immediately and the Check status button recovers safely', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    app.latest('check_sync_status').deferred.reject({ status: 403, responseJSON: { data: 'Session expired' } });
    app.runTimer(); assert.equal(count(app), 1);
    assert.equal(app.$('#retry-sync-status').prop('hidden'), false);
    assert.equal(app.$('.sync-button').toArray().every(button => button.disabled), true);
    app.$('#retry-sync-status').trigger('click');
    assert.equal(count(app), 2);
    app.latest('check_sync_status').resolve({ status: 'idle' });
    assert.equal(app.$('#retry-sync-status').prop('hidden'), true);
    assert.equal(app.$('.sync-button').toArray().every(button => !button.disabled), true);
});
