// Render the real settings view and assets, with synthetic sync responses only.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { chromium } = require('playwright-core');
const { render } = require('./harness.cjs');

const root = path.resolve(__dirname, '../..');
const output = path.join(os.tmpdir(), 'smartsync-sync-status-preview');
const executablePath = [process.env.SMARTSYNC_BROWSER,
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    '/usr/bin/chromium', '/usr/bin/google-chrome'
].find(file => file && fs.existsSync(file));
if (!executablePath) throw new Error('Set SMARTSYNC_BROWSER to a local Edge/Chromium executable.');
const css = ['wizard.css', 'ui-improvements.css', 'enhanced-ui.css', 'settings-home.css', 'sync-widget.css']
    .map(file => fs.readFileSync(path.join(root, 'assets/css', file), 'utf8')).join('\n');

(async () => {
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ executablePath, headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => route.abort());
        await page.setContent('<!doctype html><html lang="en"><head><meta charset="utf-8"><style>' +
            'body{margin:20px;background:#f0f0f1;font-family:system-ui}button,input,select{font:inherit}' +
            '.spinner{visibility:hidden;float:right;background:url(data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7)}' +
            css + '</style></head><body>' + render('settings', {
                service_provider: 'bunny', bucket: 'website-media', region: 'DE',
                iam_key: 'website-media.b-cdn.net', iam_secret: 'synthetic-key', bunnyStoragePassword: 'synthetic-storage-password', url: 'website-media.b-cdn.net'
            }) + '</body></html>');
        await page.addScriptTag({ path: require.resolve('jquery') });
        await page.evaluate(() => {
            window.ajaxObject = { ajax_url: '/fixture-ajax', nonce: 'fixture-nonce', settings_strings: {} };
            window.ajaxObject.widget = { scope: 'browser-fixture', providers: { bunny: 'Bunny CDN' } };
            window.fixtureStatus = { status: 'idle', total_files: 0, processed_files: 0 };
            window.fixtureStatusRequests = [];
            window.jQuery.ajax = options => {
                const deferred = window.jQuery.Deferred();
                if (options.data.action === 'check_sync_status') {
                    window.fixtureStatusRequests.push(deferred);
                } else {
                    setTimeout(() => deferred.resolve({ success: true, data: {} }), 0);
                }
                return deferred.promise();
            };
        });
        for (const file of ['confirmations.js', 'settings.js', 'ui-improvements.js', 'sync-widget.js']) {
            await page.addScriptTag({ path: path.join(root, 'assets/js', file) });
        }
        await page.waitForFunction(() => document.querySelector('.smartsync-background-indicator'));
        const card = page.locator('.smartsync-background-indicator');
        assert.equal(await card.isVisible(), false);
        await page.evaluate(() => window.fixtureStatusRequests.shift().resolve({ success: true, data: {
            status: 'processing', type: 'media', direction: 'upload', processed_files: 384, total_files: 1200,
            run_id: 'browser-run', started_at: Math.floor(Date.now()/1000)-120, server_time: Math.floor(Date.now()/1000),
            provider: 'bunny', storage: 'website-media', current_file: 'wp-content/uploads/product-photo.jpg', failed_files: 2
        } }));
        await card.waitFor({ state: 'visible' });
        assert.equal(await page.locator('.smartsync-status-percent').textContent(), '32%');
        assert.equal(await page.locator('.smartsync-status-detail').textContent(), '384 / 1200 files');
        const widths = [320, 390, 768, 1440];
        for (const width of widths) {
            await page.setViewportSize({ width, height: 900 });
            const layout = await card.evaluate(element => {
                const rect = element.getBoundingClientRect();
                return { left: rect.left, right: rect.right, bottom: rect.bottom,
                    width: rect.width, scroll: element.scrollWidth, client: element.clientWidth };
            });
            assert.ok(layout.left >= 0 && layout.right <= width && layout.bottom <= 900, 'Card leaves viewport at ' + width);
            assert.ok(layout.scroll <= layout.client + 1, 'Card content overflows at ' + width);
            assert.equal(await page.locator('.smartsync-status-ring').evaluate(element => getComputedStyle(element).visibility), 'visible');
            await page.screenshot({ path: path.join(output, 'settings-' + width + '.png'), fullPage: true });
        }
        await page.setViewportSize({ width: 1440, height: 900 });
        await card.screenshot({ path: path.join(output, 'sync-status.png') });
        await page.locator('.smartsync-widget-toggle').click();
        await page.locator('#smartsync-widget-details').waitFor({ state: 'visible' });
        assert.equal(await page.locator('#smartsync-widget-details').isVisible(), true);
        await card.screenshot({ path: path.join(output, 'sync-status-expanded.png') });
        for (const width of widths) {
            await page.setViewportSize({ width, height: 600 });
            const box = await card.boundingBox();
            assert.ok(box.x >= 0 && box.x + box.width <= width && box.y >= 0 && box.y + box.height <= 600);
            await page.screenshot({ path: path.join(output, 'expanded-' + width + '.png'), fullPage: true });
        }
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.evaluate(() => {
            const run = { status: 'processing', run_id: 'readable-estimate', type: 'media', direction: 'upload', provider: 'bunny', storage: 'website-media', failed_files: 0, total_files: 13020, processed_files: 0, started_at: 900, server_time: 1000 };
            window.jQuery(document).trigger('smartsync:status', [run]);
            window.jQuery(document).trigger('smartsync:status', [{ ...run, processed_files: 10, server_time: 1010 }]);
        });
        assert.equal(await page.locator('#progress-time').textContent(), '~3 hr 37 min');
        assert.equal(await page.locator('#sync-widget-eta').textContent(), '~3 hr 37 min');
        await page.locator('.smartsync-progress-bar-inner').evaluate(async element => {
            void getComputedStyle(element).width;
            await Promise.all(element.getAnimations().map(animation => animation.finished));
        });
        for (const width of widths) {
            await page.setViewportSize({ width, height: 900 });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'ETA layout overflows at ' + width);
            await page.screenshot({ path: path.join(output, 'readable-estimate-' + width + '.png'), fullPage: true });
        }
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.keyboard.press('Escape');
        await page.locator('#smartsync-widget-details').waitFor({ state: 'hidden' });
        assert.equal(await page.locator('#smartsync-widget-details').isVisible(), false);
        await page.emulateMedia({ reducedMotion: 'reduce' });
        assert.equal(await page.locator('.smartsync-status-ring').evaluate(element => getComputedStyle(element).animationName), 'none');
        await page.evaluate(() => {
            window.jQuery(document).trigger('smartsync:status', [{ status: 'idle' }]);
            window.jQuery(document).trigger('smartsync:running', [true]);
        });
        assert.equal(await page.locator('.smartsync-status-percent').isVisible(), false);
        assert.equal(await page.locator('.smartsync-status-track').isVisible(), false);
        await card.screenshot({ path: path.join(output, 'preparing-status.png') });
        await page.evaluate(() => window.jQuery(document).trigger('smartsync:status', [{
            status: 'processing', direction: 'download', processed_files: 6, total_files: 10
        }]));
        assert.equal(await page.locator('.smartsync-status-title').textContent(), 'Restoring files');
        await page.evaluate(() => window.jQuery(document).trigger('smartsync:status', [{ status: 'completed' }]));
        assert.equal(await card.isVisible(), true);
        assert.equal(await page.locator('.smartsync-widget-terminal').isVisible(), true);
        assert.deepEqual(errors, []);
        const report = { widths, progress: true, preparation: true, restore: true, completion: true, reducedMotion: true, errors };
        fs.writeFileSync(path.join(output, 'report.json'), JSON.stringify(report, null, 2));
        console.log('Sync status browser checks passed. Screenshots: ' + output);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
