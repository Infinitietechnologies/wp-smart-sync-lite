// Real PHP views and maintained assets on a mocked origin; never contacts a provider.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { chromium } = require('playwright-core');
const { render } = require('./harness.cjs');
const root = path.resolve(__dirname, '../..');
const output = path.join(os.tmpdir(), 'smartsync-shared-widget-preview');
const executablePath = [process.env.SMARTSYNC_BROWSER, 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    '/usr/bin/chromium', '/usr/bin/google-chrome'].find(file => file && fs.existsSync(file));
if (!executablePath) throw new Error('Set SMARTSYNC_BROWSER to a Chromium/Edge executable.');
const css = ['wizard.css', 'ui-improvements.css', 'enhanced-ui.css', 'cloud-setup.css', 'settings-home.css', 'sync-widget.css', 'notifications.css', 'confirmations.css'].map(file => fs.readFileSync(path.join(root, 'assets/css', file), 'utf8')).join('\n');
const origin = 'http://smartsync-widget.test';
const pages = { 'smarl-smart-sync': 'settings', 'smarl-configuration-wizard': 'wizard', 'custom-cloudfront-url': 'domain' };
const stamp = Math.floor(Date.now() / 1000);
let status = { status: 'processing', run_id: 'browser-shared-run', provider: 'bunny', storage: 'website-media', direction: 'upload', type: 'media',
    total_files: 1784, processed_files: 431, failed_files: 2, current_file: 'wp-content/uploads/2026/10/product-image.jpg', started_at: stamp - 120, server_time: stamp };

(async () => {
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ executablePath, headless: true });
    try {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
        let stopCalls = 0;
        let uploadCalls = 0;
        let uploadMode = '';
        const failures = [];
        await context.route('**/*', async route => {
            const url = new URL(route.request().url());
            if (url.origin !== origin) return route.abort();
            if (url.pathname === '/jquery.js') return route.fulfill({ contentType: 'text/javascript', body: fs.readFileSync(require.resolve('jquery'), 'utf8') });
            if (url.pathname.startsWith('/assets/js/')) return route.fulfill({ contentType: 'text/javascript', body: fs.readFileSync(path.join(root, url.pathname.slice(1)), 'utf8') });
            if (url.pathname.endsWith('admin-ajax.php')) {
                const data = new URLSearchParams(route.request().postData());
                assert.equal(data.get('nonce'), 'fixture-nonce');
                if (data.get('action') === 'stop_all_processes') { stopCalls++; status = { status: 'idle' }; }
                else if (data.get('action') === 'sync_local_to_aws') {
                    uploadCalls++; uploadMode = data.get('mode');
                    assert.ok(['full', 'incremental'].includes(uploadMode));
                    const count = uploadMode === 'full' ? 1784 : 0;
                    status = { ...status, run_id: 'browser-upload-' + uploadCalls, status: 'completed', direction: 'upload', type: data.get('type'),
                        total_files: count, processed_files: count, skipped_files: uploadMode === 'full' ? 0 : 1784, scanned_files: 1784, failed_files: 0, errors: [] };
                } else assert.equal(data.get('action'), 'check_sync_status');
                return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: status }) });
            }
            const view = pages[url.searchParams.get('page')];
            if (!view) return route.abort();
            const config = { ajax_url: origin + '/wp-admin/admin-ajax.php', nonce: 'fixture-nonce', settings_url: origin + '/wp-admin/admin.php?page=smarl-smart-sync',
                settings_strings: {}, widget: { scope: 'browser-site-user', providers: { bunny: 'Bunny CDN' }, seed: status } };
            const scripts = (view === 'settings' ? ['confirmations.js', 'notifications.js', 'settings.js', 'ui-improvements.js', 'sync-widget.js'] : ['confirmations.js', 'notifications.js', 'wizard.js', 'ui-improvements.js', 'sync-widget.js'])
                .map(file => '<script src="/assets/js/' + file + '"></script>').join('');
            const body = render(view, { service_provider: 'bunny', bucket: 'website-media', iam_secret: '', __sync_running: status.status === 'processing' });
            return route.fulfill({ contentType: 'text/html', body: '<!doctype html><html lang="en"><head><meta charset="utf-8"><style>body{margin:20px;background:#f0f0f1;font-family:system-ui}button,input,select{font:inherit}.screen-reader-text{position:absolute;clip-path:inset(50%);width:1px;height:1px;overflow:hidden}' + css + '</style></head><body>' + body + '<script src="/jquery.js"></script><script>window.ajaxObject=' + JSON.stringify(config) + ';window.ajax_object={ajax_url:window.ajaxObject.ajax_url,nonce:"fixture-nonce",strings:{}};</script>' + scripts + '</body></html>' });
        });
        const page = await context.newPage();
        page.on('pageerror', error => failures.push(error.message));
        page.on('dialog', dialog => { failures.push('Unexpected native dialog'); dialog.dismiss(); });
        const visit = async slug => { await page.goto(origin + '/wp-admin/admin.php?page=' + slug); await page.locator('.smartsync-background-indicator').waitFor({ state: 'visible' }); };
        await visit('smarl-smart-sync');
        assert.equal(await page.locator('.smartsync-widget-toggle').getAttribute('aria-expanded'), 'false');
        await page.locator('.smartsync-widget-toggle').focus();
        await page.keyboard.press('Enter');
        assert.equal(await page.locator('#sync-widget-provider').textContent(), 'Bunny CDN');
        await page.locator('.smartsync-background-indicator').screenshot({ path: path.join(output, 'expanded-settings.png') });
        status = { ...status, processed_files: 446, server_time: stamp + 10 };
        await visit('smarl-configuration-wizard');
        assert.equal(await page.locator('.smartsync-widget-toggle').getAttribute('aria-expanded'), 'true');
        assert.notEqual(await page.locator('#sync-widget-eta').textContent(), 'Calculating…');
        assert.equal(await page.locator('#service_provider').isDisabled(), true);
        await page.locator('.smartsync-background-indicator').screenshot({ path: path.join(output, 'expanded-wizard.png') });
        await visit('custom-cloudfront-url');
        assert.equal(await page.locator('.smartsync-widget-toggle').getAttribute('aria-expanded'), 'true');
        const card = page.locator('.smartsync-background-indicator');
        const widths = [320, 390, 768, 1440];
        for (const width of widths) {
            await page.setViewportSize({ width, height: 600 });
            const box = await card.boundingBox();
            assert.ok(box.x >= 0 && box.y >= 0 && box.x + box.width <= width && box.y + box.height <= 600, 'Widget leaves viewport at ' + width);
            await page.screenshot({ path: path.join(output, 'expanded-' + width + '.png'), fullPage: true });
        }
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.locator('.smartsync-widget-stop').focus();
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('.smartsync-widget-toggle').getAttribute('aria-expanded'), 'false');
        await card.screenshot({ path: path.join(output, 'collapsed.png') });
        await page.locator('.smartsync-widget-toggle').click();
        await page.locator('.smartsync-widget-stop').click();
        await page.locator('.smartsync-confirm-cancel').click();
        assert.equal(stopCalls, 0);
        await page.locator('.smartsync-widget-stop').click();
        await page.locator('.smartsync-confirm-accept').click();
        await page.locator('.smartsync-background-indicator.is-stopped').waitFor();
        assert.equal(stopCalls, 1);
        status = { ...status, ...{ run_id: 'failed-run', status: 'failed', type: 'media', direction: 'upload', total_files: 10, processed_files: 10, failed_files: 1,
            provider: 'bunny', storage: 'website-media', started_at: stamp - 120, finished_at: stamp + 10, server_time: stamp + 10, errors: ['One file could not be uploaded. Open the logs for details.'] } };
        await visit('custom-cloudfront-url');
        await page.locator('.smartsync-background-indicator.is-failed').waitFor();
        await card.screenshot({ path: path.join(output, 'failed.png') });
        await page.locator('.smartsync-widget-terminal button').click();
        await page.reload();
        await page.waitForFunction(() => document.querySelector('.smartsync-background-indicator'));
        assert.equal(await card.isVisible(), false);
        status = { ...status, run_id: 'completed-run', status: 'completed', failed_files: 0, errors: [] };
        await visit('smarl-smart-sync');
        await page.locator('.smartsync-background-indicator.is-completed').waitFor();
        await card.screenshot({ path: path.join(output, 'completed.png') });
        await page.locator('#tab-button-media').click();
        await page.waitForFunction(() => !document.querySelector('#sync-mode-media').disabled);
        assert.equal(await page.locator('#sync-mode-media-incremental').isChecked(), true);
        for (const width of [320, 390, 768, 1440, 1920]) {
            await page.setViewportSize({ width, height: 1000 });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Upload controls overflow at ' + width);
            await page.screenshot({ path: path.join(output, 'incremental-settings-' + width + '.png'), fullPage: true });
        }
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.locator('label[for="sync-mode-media-full"]').click();
        await page.locator('#sync-local-to-aws').click();
        await page.locator('.smartsync-confirm-cancel').click();
        assert.equal(uploadCalls, 0);
        await page.locator('#sync-local-to-aws').click();
        await page.locator('.smartsync-confirm-accept').click();
        await page.waitForFunction(() => !document.querySelector('#sync-mode-media').disabled);
        assert.equal(uploadMode, 'full'); assert.equal(uploadCalls, 1);
        await page.locator('label[for="sync-mode-media-incremental"]').click();
        await page.locator('#sync-local-to-aws').click();
        await page.locator('.smartsync-confirm-accept').click();
        await page.waitForFunction(() => document.querySelector('.smartsync-status-detail').textContent.includes('1784 unchanged'));
        assert.equal(uploadMode, 'incremental'); assert.equal(uploadCalls, 2);
        assert.equal(await page.locator('.smartsync-status-detail').textContent(), 'No transfers needed · 1784 unchanged');
        await card.screenshot({ path: path.join(output, 'incremental-noop.png') });
        assert.deepEqual(failures, []);
        fs.writeFileSync(path.join(output, 'report.json'), JSON.stringify({ pages: Object.keys(pages), widths, keyboard: true, navigationPersistence: true,
            elapsedAndEta: true, stopConfirmation: true, failureAcknowledgement: true, completion: true, uploadModes: true, fullResyncConfirmation: true, unchangedSummary: true, fluidUploadWidths: [320, 390, 768, 1440, 1920], errors: failures }, null, 2));
        console.log('Shared widget browser passed: all three plugin pages, navigation preferences/timing, four widths, keyboard, stop, failure acknowledgement and completion. Screenshots: ' + output);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
