const test = require('node:test');
const assert = require('node:assert/strict');
const { boot } = require('./harness.cjs');
const notice = (app, data) => app.$(app.window.document).trigger('smartsync:notice', [data]);

test('errors expire after five seconds with a countdown that pauses on hover', async t => {
    const app = await boot('settings', { scripts: [] }); t.after(() => app.close());
    app.window.smarlNotices = {};
    app.window.eval(require('node:fs').readFileSync(require('node:path').join(__dirname, '../../assets/js/notifications.js'), 'utf8'));
    await new Promise(resolve => setTimeout(resolve, 20));
    let now = 10000; app.window.Date.now = () => now;
    notice(app, { type: 'error', message: 'Connection failed' });
    const item = app.$('.smartsync-notice-item');
    const bar = item.find('.smartsync-notice-countdown');
    assert.equal(bar.prop('hidden'), false);
    assert.match(bar[0].style.transition, /5000ms/);
    now += 2000; item.trigger('mouseenter');
    assert.equal(app.timers.size, 0);
    assert.equal(bar[0].style.transform, 'scaleX(0.6)');
    item.trigger('mouseleave');
    assert.match(bar[0].style.transition, /3000ms/);
    app.runTimer();
    assert.equal(app.$('.smartsync-notice-item').length, 0);
});

test('CDN prerequisites appear prominently and in the log, with rollback, deduplication and safe text', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    app.latest('check_sync_status').resolve({ status: 'idle' });
    const message = 'Complete a successful sync and configure a public URL before enabling CDN.';
    for (let index = 0; index < 2; index++) {
        app.$('#use-cloudfront-media').prop('checked', true).trigger('change');
        app.latest('toggle_cdn').reject(message);
    }
    assert.equal(app.$('#use-cloudfront-media').prop('checked'), false);
    assert.equal(app.$('.smartsync-notice-item').length, 1);
    assert.equal(app.$('.smartsync-notice-message').text(), message);
    assert.equal(app.$('.smartsync-notice-message').attr('role'), 'alert');
    assert.match(app.$('#sync-log-container').text(), /Complete a successful sync/);
    assert.equal(app.$('.smartsync-notifications').css('position'), 'fixed');
    notice(app, { type: 'error', message: '<img src=x onerror=alert(1)><script>unsafe()</script>', key: 'unsafe' });
    assert.equal(app.$('.smartsync-notifications img, .smartsync-notifications script').length, 0);
    assert.match(app.$('.smartsync-notifications').text(), /<script>unsafe/);
});

test('notification severity, bounded stacking, keyed replacement and dismissal keep critical messages visible', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    notice(app, { type: 'error', message: 'Error', key: 'error' });
    notice(app, { type: 'warning', message: 'Warning', key: 'warning' });
    notice(app, { type: 'info', message: 'Information', key: 'status' });
    assert.equal(app.$('.smartsync-notice-item').length, 3);
    assert.equal(app.$('[data-type="info"] .smartsync-notice-message').attr('role'), 'status');
    notice(app, { type: 'success', message: 'Updated', key: 'status' });
    assert.equal(app.$('.smartsync-notice-item').length, 3);
    assert.equal(app.$('[data-type="success"] .smartsync-notice-message').text(), 'Updated');
    notice(app, { type: 'info', message: 'New information' });
    assert.equal(app.$('.smartsync-notice-item').length, 3);
    assert.equal(app.$('[data-type="error"], [data-type="warning"]').length, 2);
    app.$('[data-type="error"] .smartsync-notice-dismiss').trigger('click');
    assert.equal(app.$('.smartsync-notice-item').length, 2);
    notice(app, { type: 'error', message: 'Error', key: 'error' });
    assert.equal(app.$('.smartsync-notice-item').length, 2); // New critical feedback replaces obsolete transient information.
    assert.equal(app.$('[data-type="info"]').length, 0);
});

test('notifications preserve focus, return it after keyboard dismissal, and never take page space', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    const origin = app.$('#tab-button-media')[0]; origin.focus();
    notice(app, { type: 'warning', message: 'Action needed' });
    assert.equal(app.window.document.activeElement, origin);
    const close = app.$('.smartsync-notice-dismiss')[0]; close.focus(); close.click();
    assert.equal(app.window.document.activeElement, origin);
    assert.equal(app.$('.smartsync-notice-item').length, 0);
    assert.equal(app.$('.smartsync-wrap .smartsync-notifications').length, 0);
});

test('progress and file details do not flood notifications, while a new failed run is announced once', async t => {
    const app = await boot('settings'); t.after(() => app.close());
    const run = { status: 'processing', run_id: 'run-one', direction: 'upload', type: 'media', total_files: 10, processed_files: 2 };
    app.latest('check_sync_status').resolve(run);
    assert.equal(app.$('.smartsync-notice-item').length, 0);
    app.runTimer();
    app.latest('check_sync_status').resolve({ ...run, status: 'failed', errors: ['First file failed', 'Second file failed'] });
    assert.equal(app.$('.smartsync-notice-item').length, 1);
    assert.match(app.$('#sync-log-container').text(), /First file failed/);
    app.$('.smartsync-notice-dismiss').trigger('click');
    app.refreshStatus(); app.latest('check_sync_status').resolve({ ...run, status: 'failed', errors: [] });
    assert.equal(app.$('.smartsync-notice-item').length, 0);
    app.refreshStatus(); app.latest('check_sync_status').resolve({ ...run, run_id: 'run-two', status: 'failed', errors: [] });
    assert.equal(app.$('.smartsync-notice-item').length, 1);
});

test('wizard and custom-domain validation use the same notification bars', async t => {
    const wizard = await boot('wizard', { data: { service_provider: 'bunny' } }); t.after(() => wizard.close());
    wizard.$('#step-1-next').trigger('click');
    assert.equal(wizard.$('.smartsync-notice-item[data-type="error"]').length, 1);
    const domain = await boot('domain', { data: { service_provider: 'aws', use_cloudfront: true } }); t.after(() => domain.close());
    domain.$('#custom-domain').val('https://invalid.example/path'); domain.$('#generate-certificate').trigger('click');
    assert.equal(domain.$('.smartsync-notice-item[data-type="warning"]').length, 1);
    domain.$('#custom-domain').val('cdn.example.test'); domain.$('#generate-certificate').trigger('click');
    domain.latest('generate_ssl_certificate').reject('Certificate request failed.');
    assert.equal(domain.$('.smartsync-notice-item').length, 1);
    assert.equal(domain.$('.smartsync-notice-message').text(), 'Certificate request failed.');
});
