// Maintained PHP view/CSS/JS on a fully mocked origin; never contacts a cloud provider.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { chromium } = require('playwright-core');
const { render } = require('./harness.cjs');
const root = path.resolve(__dirname, '../..');
const output = path.join(os.tmpdir(), 'smartsync-settings-home-preview');
const executablePath = [process.env.SMARTSYNC_BROWSER, 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/chromium']
    .find(file => file && fs.existsSync(file));
if (!executablePath) throw new Error('Set SMARTSYNC_BROWSER to a Chromium browser.');
const css = ['wizard.css', 'ui-improvements.css', 'enhanced-ui.css', 'settings-home.css', 'sync-widget.css', 'notifications.css', 'confirmations.css']
    .map(file => fs.readFileSync(path.join(root, 'assets/css', file), 'utf8')).join('\n');
const origin = 'http://smartsync-settings.test';
let configuration = { service_provider: 'bunny', bucket: 'website-media', region: 'DE', bunnyStoragePassword: 'synthetic-password', url: 'website-media.b-cdn.net' };
let status = { status: 'idle' };
let toggleFailure = false;
let holdAnalytics = false;
let releaseAnalytics;
const calls = [];
(async () => {
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ executablePath, headless: true });
    try {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
        await context.route('**/*', async route => {
            const url = new URL(route.request().url());
            if (url.origin !== origin) return route.abort();
            if (url.pathname === '/jquery.js') return route.fulfill({ contentType: 'text/javascript', body: fs.readFileSync(require.resolve('jquery'), 'utf8') });
            if (url.pathname.startsWith('/assets/js/')) return route.fulfill({ contentType: 'text/javascript', body: fs.readFileSync(path.join(root, url.pathname.slice(1)), 'utf8') });
            if (url.pathname.endsWith('admin-ajax.php')) {
                const data = new URLSearchParams(route.request().postData()); const action = data.get('action');
                assert.equal(data.get('nonce'), 'fixture-nonce'); calls.push(action);
                let result = {}; let success = true;
                if (action === 'check_sync_status') result = status;
                else if (action === 'fetch_cloud_analytics') {
                    if (holdAnalytics) await new Promise(resolve => { releaseAnalytics = resolve; });
                    result = { total_files: 1784, total_storage: '348 MB', price_in_dollars: null, usage_source: 'provider' };
                }
                else if (action === 'toggle_cdn' || action === 'toggle_media_auto_delete') {
                    if (toggleFailure) { toggleFailure = false; success = false; result = 'Complete a successful sync and configure a public URL before enabling CDN.'; }
                } else if (action === 'sync_local_to_aws' || action === 'sync_aws_to_local') {
                    const direction = action === 'sync_local_to_aws' ? 'upload' : 'download';
                    if (direction === 'upload') assert.ok(['incremental', 'full'].includes(data.get('mode')));
                    status = { status: 'processing', run_id: 'home-' + calls.length, type: data.get('type'), direction, total_files: 10, processed_files: 2,
                        skipped_files: direction === 'upload' ? 1784 : 0, scanned_files: 1794, failed_files: 0, provider: 'bunny', storage: 'website-media', started_at: Math.floor(Date.now() / 1000) - 20 };
                    result = { total_files: 10, skipped_files: status.skipped_files, scanned_files: 1794 };
                } else if (action === 'stop_all_processes') { status = { status: 'idle' }; result = { message: 'Stopped.' }; }
                else if (action === 'clear_cloudfront_cache') result = 'Cache refresh requested.';
                else throw new Error('Unexpected fixture action: ' + action);
                return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success, data: result }) });
            }
            const config = { ajax_url: origin + '/wp-admin/admin-ajax.php', nonce: 'fixture-nonce', settings_url: origin + '/wp-admin/admin.php?page=smarl-smart-sync',
                settings_strings: { widget_upload_media: 'Uploading media', widget_upload_assets: 'Uploading assets', widget_restore_media: 'Restoring media',
                    confirm_full_resync: 'Full resync will upload every selected local file again, including unchanged files.',
                    confirm_sync_local_to_aws: 'Upload new or changed local files to your connected cloud storage. Unchanged files will be skipped.',
                    confirm_sync_aws_to_local: 'Download media back to this site. Existing local files will be replaced after a successful download.',
                    confirm_stop_processes: 'Queued transfers will stop. An active file transfer may finish before stopping.',
                    cost_unavailable: 'Unavailable', clear_logs: 'Clear log', cdn_setup_success: 'Cloud delivery setting updated.', cdn_setup_failed: 'Could not update cloud delivery.' },
                widget: { scope: 'home-browser', providers: { bunny: 'Bunny CDN' }, seed: status } };
            const scripts = ['confirmations.js', 'notifications.js', 'settings.js', 'ui-improvements.js', 'enhanced-logs.js', 'sync-widget.js'].map(file => '<script src="/assets/js/' + file + '"></script>').join('');
            return route.fulfill({ contentType: 'text/html', body: '<!doctype html><html lang="en"><head><meta charset="utf-8"><style>body{margin:0;background:#f0f0f1;font:13px system-ui}#wpcontent{margin-left:160px;padding:0 20px}@media(max-width:782px){#wpcontent{margin-left:0;padding:0 10px}}button,input,select{font:inherit}' + css + '</style></head><body><div id="wpcontent">' + render('settings', configuration) + '</div><script src="/jquery.js"></script><script>window.ajaxObject=' + JSON.stringify(config) + ';</script>' + scripts + '</body></html>' });
        });
        const page = await context.newPage(); const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', dialog => { errors.push('Unexpected native dialog'); dialog.dismiss(); });
        await page.goto(origin + '/wp-admin/admin.php?page=smarl-smart-sync');
        await page.waitForFunction(() => !document.querySelector('#sync-mode-media').disabled);
        assert.equal(await page.locator('.settings-page-header h1').textContent(), 'SmartSync');
        assert.equal(await page.locator('.smartsync-breadcrumbs, .tabs-content, .settings-table').count(), 0);
        assert.equal(await page.locator('#settings-activity-details').getAttribute('open'), null);
        assert.equal(await page.locator('#stop-all-processes').isVisible(), false);
        assert.equal(await page.locator('.settings-activity-heading #clear-logs-button').isVisible(), true);
        const settingsUrl = origin + '/wp-admin/admin.php?page=smarl-smart-sync';
        await page.goto(settingsUrl + '&tab=assets&fixture=kept#sync-log-container');
        await page.waitForFunction(() => document.querySelector('#tab-button-assets').getAttribute('aria-selected') === 'true');
        assert.equal(await page.locator('.settings-destination').isVisible(), true);
        assert.equal(await page.locator('.settings-destination-provider').textContent(), 'Bunny CDN');
        assert.equal(await page.locator('.settings-destination-storage').textContent(), 'website-media');
        assert.equal(await page.locator('#settings-activity-details').evaluate(element => element.open), true);
        await page.reload();
        await page.waitForFunction(() => document.querySelector('#tab-button-assets').getAttribute('aria-selected') === 'true');
        await page.locator('#tab-button-media').click();
        assert.equal(new URL(page.url()).searchParams.get('tab'), 'media');
        assert.equal(new URL(page.url()).searchParams.get('fixture'), 'kept');
        assert.equal(new URL(page.url()).hash, '#sync-log-container');
        await page.goBack();
        await page.waitForFunction(() => document.querySelector('#tab-button-assets').getAttribute('aria-selected') === 'true');
        await page.goForward();
        await page.waitForFunction(() => document.querySelector('#tab-button-media').getAttribute('aria-selected') === 'true');
        await page.goto(settingsUrl + '&tab=unknown');
        await page.waitForFunction(() => !document.querySelector('#sync-mode-media').disabled);
        assert.equal(await page.locator('#tab-button-general').getAttribute('aria-selected'), 'true');
        await page.goto(settingsUrl);
        await page.waitForFunction(() => !document.querySelector('#sync-mode-media').disabled);
        assert.equal(await page.locator('.settings-destination').isVisible(), false);
        status = { status: 'idle', operation_busy: true, retry_after: 60 };
        await page.reload();
        await page.waitForFunction(() => document.querySelector('#settings-activity-status').dataset.state === 'busy');
        assert.equal(await page.locator('#sync-local-to-aws').isDisabled(), true);
        assert.equal(await page.locator('#stop-all-processes').isVisible(), false);
        await page.screenshot({ path: path.join(output, 'operation-busy.png'), fullPage: true });
        status = { status: 'idle', operation_busy: false, retry_after: 0 };
        await page.reload();
        await page.waitForFunction(() => !document.querySelector('#sync-mode-media').disabled);
        const widths = [320, 390, 768, 1440, 1920];
        const capture = async name => {
            for (const width of widths) {
                await page.setViewportSize({ width, height: 1000 });
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' overflow at ' + width);
                await page.screenshot({ path: path.join(output, name + '-' + width + '.png'), fullPage: true });
            }
        };
        await capture('overview');
        const metricHeight = await page.locator('.settings-usage-metrics').evaluate(element => element.getBoundingClientRect().height);
        holdAnalytics = true;
        await page.locator('#fetch-analytics-button').click();
        await page.waitForSelector('.settings-metric-loading');
        assert.equal(await page.locator('.settings-usage-metrics').getAttribute('aria-busy'), 'true');
        assert.equal(await page.locator('#fetch-analytics-button').isDisabled(), true);
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        assert.equal(await page.locator('#total-files').evaluate(element => getComputedStyle(element, '::after').animationName), 'settings-usage-shimmer');
        await page.emulateMedia({ reducedMotion: 'reduce' });
        assert.equal(await page.locator('#total-files').evaluate(element => getComputedStyle(element, '::after').animationName), 'none');
        assert.equal(await page.locator('#total-files').evaluate(element => getComputedStyle(element).fontSize), '12px');
        assert.equal(await page.locator('.settings-usage-metrics').evaluate(element => element.getBoundingClientRect().height), metricHeight);
        await capture('usage-loading');
        holdAnalytics = false; releaseAnalytics();
        await page.waitForFunction(() => document.querySelector('#total-files').textContent === '1784');
        assert.equal(await page.locator('#total-storage').textContent(), '348 MB');
        assert.equal(await page.locator('#total-price').textContent(), 'Unavailable');
        assert.equal(await page.locator('.settings-metric-loading').count(), 0);
        assert.equal(await page.locator('#fetch-analytics-button svg').count(), 1);
        assert.equal(await page.locator('.settings-usage-metrics').getAttribute('aria-busy'), 'false');
        await capture('usage-loaded');
        await page.locator('#tab-button-media').click();
        assert.equal(await page.locator('.settings-restore').getAttribute('open'), null);
        await capture('media');
        await page.locator('.settings-test-picker > summary').click();
        await page.locator('#media-test-filter input[value="png"]').check();
        await page.locator('#media-test-filter input[value="svg"]').check();
        assert.equal(await page.locator('#media-test-selection').textContent(), 'PNG, SVG');
        await page.setViewportSize({ width: 320, height: 900 });
        await page.screenshot({ path: path.join(output, 'media-file-types-320.png'), fullPage: true });
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.screenshot({ path: path.join(output, 'media-file-types-1440.png'), fullPage: true });
        await page.locator('#media-test-reset').click();
        assert.equal(await page.locator('#media-test-selection').textContent(), 'All supported media');
        await page.locator('.settings-test-picker > summary').click();
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.locator('#sync-mode-media-incremental').focus();
        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('ArrowLeft');
        const focused = await page.locator('#sync-mode-media-incremental + span').evaluate(element => ({ outline: getComputedStyle(element).outlineStyle, shadow: getComputedStyle(element).boxShadow }));
        assert.equal(focused.outline, 'none'); assert.notEqual(focused.shadow, 'none');
        await page.screenshot({ path: path.join(output, 'media-focus.png'), fullPage: true });
        await page.emulateMedia({ forcedColors: 'active' });
        assert.notEqual(await page.locator('#sync-mode-media-incremental + span').evaluate(element => getComputedStyle(element).outlineStyle), 'none');
        await page.emulateMedia({ forcedColors: 'none' });
        await page.keyboard.press('ArrowRight');
        assert.equal(await page.locator('#sync-mode-media-full').isChecked(), true);
        assert.equal(await page.locator('#sync-mode-help-media').textContent(), 'Uploads every selected local file again.');
        await page.keyboard.press('ArrowLeft');
        assert.equal(await page.locator('#sync-mode-media-incremental').isChecked(), true);
        await page.locator('#use-cloudfront-media').focus(); await page.keyboard.press('Space');
        await page.waitForFunction(() => !document.querySelector('#use-cloudfront-media').disabled);
        assert.equal(await page.locator('#use-cloudfront-media').isChecked(), true);
        await page.locator('#use-cloudfront-media').click();
        await page.waitForFunction(() => !document.querySelector('#use-cloudfront-media').disabled);
        toggleFailure = true;
        await page.locator('#use-cloudfront-media').click();
        await page.waitForFunction(() => !document.querySelector('#use-cloudfront-media').disabled);
        assert.equal(await page.locator('#use-cloudfront-media').isChecked(), false);
        const errorNotice = page.locator('.smartsync-notice-item[data-type="error"]');
        assert.equal(await errorNotice.locator('.smartsync-notice-message').textContent(), 'Complete a successful sync and configure a public URL before enabling CDN.');
        assert.equal(await errorNotice.locator('.smartsync-notice-message').getAttribute('role'), 'alert');
        for (const width of widths) {
            await page.setViewportSize({ width, height: 1000 });
            const box = await errorNotice.boundingBox();
            assert.ok(box.x >= 0 && box.y >= 0 && box.x + box.width <= width && box.y + box.height <= 1000);
            await page.screenshot({ path: path.join(output, 'notification-error-' + width + '.png'), fullPage: true });
        }
        await errorNotice.locator('button').click();
        await page.setViewportSize({ width: 1440, height: 1000 });
        assert.equal(await page.locator('#settings-activity-details').evaluate(element => element.open), true);
        await page.locator('#settings-activity-details summary').click();
        const starts = calls.filter(action => action === 'sync_local_to_aws').length;
        await page.locator('#sync-local-to-aws').click();
        assert.equal(await page.locator('.smartsync-confirm-cancel').evaluate(element => element === document.activeElement), true);
        await page.keyboard.press('Shift+Tab');
        assert.equal(await page.locator('.smartsync-confirm-accept').evaluate(element => element === document.activeElement), true);
        await page.keyboard.press('Tab');
        assert.equal(await page.locator('.smartsync-confirm-cancel').evaluate(element => element === document.activeElement), true);
        await capture('confirm-upload');
        await page.keyboard.press('Escape');
        await page.waitForSelector('.smartsync-confirm-dialog', { state: 'detached' });
        assert.equal(await page.locator('#sync-local-to-aws').evaluate(element => element === document.activeElement), true);
        assert.equal(calls.filter(action => action === 'sync_local_to_aws').length, starts);
        await page.locator('#sync-local-to-aws').click();
        await page.locator('.smartsync-confirm-accept').click();
        await page.waitForFunction(() => document.querySelector('#settings-activity-status').dataset.state === 'processing');
        assert.equal(await page.locator('#settings-activity-details').evaluate(element => element.open), false);
        assert.equal(await page.locator('#settings-progress-slot .smartsync-progress-container').isVisible(), true);
        assert.equal(await page.locator('#sync-mode-media-incremental').isDisabled(), true);
        assert.equal(await page.locator('#stop-all-processes').isVisible(), true);
        await capture('uploading');
        await page.locator('#settings-activity-details summary').click();
        await capture('activity-log');
        await page.setViewportSize({ width: 1440, height: 1000 });
        const stopBox = await page.locator('#stop-all-processes').boundingBox();
        const clearBox = await page.locator('#clear-logs-button').boundingBox();
        assert.ok(stopBox.x + stopBox.width <= clearBox.x);
        assert.equal(await page.locator('#sync-log-container .log-message').count(), await page.locator('#sync-log-container time').count());
        assert.doesNotMatch(await page.locator('#sync-log-container .log-message').first().evaluate(element => getComputedStyle(element).fontFamily), /monospace/);
        await page.locator('.smartsync-widget-toggle').click();
        await page.locator('.smartsync-widget-actions a').click();
        assert.equal(new URL(page.url()).searchParams.get('tab'), 'media');
        assert.equal(await page.locator('#tab-button-media').getAttribute('aria-selected'), 'true');
        assert.equal(await page.locator('#settings-activity-details').evaluate(element => element.open), true);
        await page.locator('.smartsync-widget-stop').click();
        await capture('confirm-stop');
        await page.locator('.smartsync-confirm-accept').click();
        await page.waitForFunction(() => !document.querySelector('#sync-mode-media').disabled);
        await page.locator('#clear-logs-button').click();
        assert.equal(await page.locator('#sync-log-container p').count(), 1);
        await page.locator('#settings-activity-details summary').click();
        await page.locator('label[for="sync-mode-media-full"]').click();
        assert.equal(await page.locator('#sync-mode-help-media').textContent(), 'Uploads every selected local file again.');
        await page.locator('.settings-restore summary').click();
        await page.locator('#sync-aws-to-local').click();
        await capture('confirm-restore');
        await page.locator('.smartsync-confirm-accept').click();
        await page.waitForFunction(() => document.querySelector('#settings-activity-status').textContent === 'Restoring media');
        await page.locator('.smartsync-widget-toggle').click();
        await page.locator('#stop-all-processes').evaluate(element => element.scrollIntoView({ block: 'center' }));
        await page.locator('#stop-all-processes').click();
        await page.locator('.smartsync-confirm-accept').click();
        await page.waitForFunction(() => !document.querySelector('#sync-mode-media').disabled);
        await page.locator('#tab-button-assets').click();
        await capture('assets');
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        await page.mouse.move(0, 0);
        const uploadAssets = page.locator('#sync-local-to-aws-plugin');
        const restingGradient = await uploadAssets.evaluate(element => getComputedStyle(element).backgroundImage);
        await uploadAssets.evaluate(element => {
            window.hoverFrames = [];
            function sample() {
                const style = getComputedStyle(element);
                window.hoverFrames.push({ color: style.backgroundColor, image: style.backgroundImage });
                if (window.hoverFrames.length < 24) requestAnimationFrame(sample);
            }
            requestAnimationFrame(sample);
        });
        await uploadAssets.hover();
        await page.waitForFunction(() => window.hoverFrames.length === 24);
        const hoverFrames = await page.evaluate(() => window.hoverFrames);
        assert.ok(hoverFrames.every(frame => /^rgb\(/.test(frame.color)), 'button stays opaque throughout its first hover');
        assert.ok(hoverFrames.every(frame => frame.image === restingGradient), 'gradient stays present throughout its first hover');
        await page.screenshot({ path: path.join(output, 'assets-first-hover.png'), fullPage: true });
        const beforeDialogWidth = await page.locator('.smartsync-settings').evaluate(element => element.getBoundingClientRect().width);
        await uploadAssets.click();
        await page.waitForFunction(() => getComputedStyle(document.querySelector('.smartsync-confirm-dialog')).opacity === '1');
        assert.equal(await page.locator('.smartsync-settings').evaluate(element => element.getBoundingClientRect().width), beforeDialogWidth);
        await page.locator('#tab-button-assets').evaluate(element => element.focus());
        assert.equal(await page.locator('.smartsync-confirm-cancel').evaluate(element => element === document.activeElement), true);
        const uploadsBeforeCancel = calls.filter(action => action === 'sync_local_to_aws').length;
        await page.locator('.smartsync-confirm-cancel').click();
        await page.waitForSelector('.smartsync-confirm-dialog', { state: 'detached' });
        assert.equal(calls.filter(action => action === 'sync_local_to_aws').length, uploadsBeforeCancel);
        assert.equal(await uploadAssets.evaluate(element => element === document.activeElement), true);
        await page.locator('label[for="sync-mode-assets-full"]').click();
        await uploadAssets.click();
        assert.equal(await page.locator('.smartsync-confirm-dialog').getAttribute('data-tone'), 'warning');
        assert.equal(await page.locator('.smartsync-confirm-accept').textContent(), 'Full resync');
        await page.waitForFunction(() => getComputedStyle(document.querySelector('.smartsync-confirm-dialog')).opacity === '1');
        await capture('confirm-full');
        await page.keyboard.press('Escape');
        await page.waitForSelector('.smartsync-confirm-dialog', { state: 'detached' });
        await page.emulateMedia({ reducedMotion: 'reduce' });
        configuration = { service_provider: 'aws', bucket: 'website-media', region: 'us-east-1', iam_key: 'fixture-key', iam_secret: 'fixture-secret', use_cloudfront: true, distribution_id: 'D-FIXTURE', url: 'cdn.example.test' };
        await page.goto(settingsUrl);
        await page.waitForFunction(() => !document.querySelector('#sync-mode-media').disabled);
        await page.locator('.settings-maintenance summary').click();
        const cacheCalls = calls.filter(action => action === 'clear_cloudfront_cache').length;
        await page.locator('#clear-cloudfront-cache-button').click();
        assert.equal(await page.locator('.smartsync-confirm-title').textContent(), 'Refresh CloudFront cache?');
        await page.screenshot({ path: path.join(output, 'confirm-cache.png'), fullPage: true });
        await page.locator('.smartsync-confirm-cancel').click();
        assert.equal(calls.filter(action => action === 'clear_cloudfront_cache').length, cacheCalls);
        await page.locator('#clear-cloudfront-cache-button').click();
        await page.locator('.smartsync-confirm-accept').click();
        await page.waitForFunction(() => !document.querySelector('#clear-cloudfront-cache-button').disabled);
        assert.equal(calls.filter(action => action === 'clear_cloudfront_cache').length, cacheCalls + 1);
        const testTarget = require('node:crypto').createHash('sha256').update(JSON.stringify({ provider: 'aws', bucket: 'fixture-media', region: 'us-east-1', endpoint: '' })).digest('hex');
        configuration = { service_provider: 'aws', bucket: 'fixture-media', region: 'us-east-1', iam_key: 'synthetic-key', iam_secret: 'synthetic-secret', url: 'cdn.example.test', use_cdn_for_media: true, mediaCdnTestExtensions: ['png'], mediaCdnTestTarget: testTarget };
        status = { status: 'idle', cdn_test_extensions: ['png'] };
        await page.goto(origin + '/wp-admin/admin.php?page=smarl-smart-sync&tab=media');
        await page.waitForFunction(() => document.querySelector('#settings-activity-status').textContent === 'Ready');
        assert.match(await page.locator('#media-cdn-test-status').textContent(), /Temporary CDN test: PNG/);
        assert.equal(await page.locator('#auto-delete-media').isDisabled(), true);
        await capture('media-cdn-test', [320, 390, 1440]);
        configuration = {};
        status = { status: 'idle' };
        await page.goto(origin + '/wp-admin/admin.php?page=smarl-smart-sync');
        await page.waitForFunction(() => document.querySelector('#settings-activity-status').textContent === 'Ready');
        assert.equal(await page.locator('#reconfigure-aws').textContent(), 'Connect storage');
        assert.equal(await page.locator('#fetch-analytics-button').isVisible(), false);
        await page.locator('#tab-button-media').click();
        assert.equal(await page.locator('.settings-destination-provider').count(), 0);
        assert.equal(await page.locator('.settings-destination a').textContent(), 'Connect storage');
        assert.match(await page.locator('.settings-destination a').getAttribute('href'), /page=smarl-configuration-wizard/);
        await page.locator('#tab-button-general').click();
        await page.screenshot({ path: path.join(output, 'unconfigured.png'), fullPage: true });
        await page.clock.install();
        await page.evaluate(() => window.jQuery(document).trigger('smartsync:notice', [{ type: 'info', message: 'Information that closes automatically.', key: 'expiry-test' }]));
        assert.equal(await page.locator('.smartsync-notice-item[data-type="info"]').count(), 1);
        await page.clock.fastForward(6500);
        assert.equal(await page.locator('.smartsync-notice-item[data-type="info"]').count(), 0);
        await page.evaluate(() => window.jQuery(document).trigger('smartsync:notice', [{ type: 'warning', message: 'This warning closes automatically.', key: 'warning-expiry-test' }]));
        assert.equal(await page.locator('.smartsync-notice-item[data-type="warning"] .smartsync-notice-countdown').count(), 1);
        await page.clock.fastForward(6500);
        assert.equal(await page.locator('.smartsync-notice-item[data-type="warning"]').count(), 0);
        status = { status: 'failed', run_id: 'settled-run', type: 'media', direction: 'upload', total_files: 10, processed_files: 10, errors: ['Fixture failed file'], successes: [] };
        await page.evaluate(() => window.jQuery(document).trigger('smartsync:refresh-status'));
        await page.waitForFunction(() => document.querySelector('#settings-activity-status').dataset.state === 'failed');
        const settledPolls = calls.filter(action => action === 'check_sync_status').length;
        await page.evaluate(() => {
            for (let i = 0; i < 5; i++) {
                window.dispatchEvent(new Event('focus'));
                window.dispatchEvent(new Event('online'));
                document.dispatchEvent(new Event('visibilitychange'));
            }
        });
        await page.clock.fastForward(120000);
        assert.equal(calls.filter(action => action === 'check_sync_status').length, settledPolls, 'failed run must remain quiet for two minutes');
        assert.deepEqual(errors, []);
        fs.writeFileSync(path.join(output, 'report.json'), JSON.stringify({ widths, tabs: true, cleanOverview: true, focusStyles: true, forcedColors: true,
            keyboardSwitch: true, toggleRollback: true, modeHints: true, confirmation: true, syncLocks: true, collapsedLogs: true, logAnchor: true,
            clearLogs: true, restore: true, analytics: true, usageSkeleton: true, usageReducedMotion: true, usageStableHeight: true, emptyState: true, notificationBars: true, notificationExpiry: true, persistentWarning: true, errors }, null, 2));
        console.log('Settings home browser passed: overview/media/assets at five widths, focus/high contrast, switches, analytics, sync/restore/stop, log disclosure/anchor and empty state. Previews: ' + output);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
