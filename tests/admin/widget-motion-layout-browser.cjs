// Actual plugin views/assets, synthetic status only; no WordPress or provider writes.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { chromium } = require('playwright-core');
const { render } = require('./harness.cjs');
const root = path.resolve(__dirname, '../..');
const output = path.join(os.tmpdir(), 'smartsync-widget-motion-layout');
const executablePath = [process.env.SMARTSYNC_BROWSER, 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/chromium']
    .find(file => file && fs.existsSync(file));
if (!executablePath) throw new Error('Set SMARTSYNC_BROWSER to a Chromium browser.');
const css = ['wizard.css', 'ui-improvements.css', 'enhanced-ui.css', 'cloud-setup.css', 'settings-home.css', 'sync-widget.css']
    .map(file => fs.readFileSync(path.join(root, 'assets/css', file), 'utf8')).join('\n');
const shell = body => '<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0;background:#f0f0f1;font-family:system-ui}.wrap{margin:10px 20px 0 2px}#wpcontent{margin-left:160px;padding:0 20px}button,input,select{font:inherit}@media(max-width:782px){#wpcontent{margin-left:0;padding:0 10px}}' + css + '</style></head><body><div id="wpcontent">' + body + '</div></body></html>';
const widths = [320, 575, 576, 767, 768, 991, 992, 1199, 1200, 1399, 1400, 1920];

(async () => {
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ executablePath, headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, reducedMotion: 'no-preference' });
        const errors = []; page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => route.abort());
        for (const view of ['settings', 'wizard', 'domain']) {
            await page.setContent(shell(render(view, { service_provider: 'bunny' })));
            for (const width of widths) {
                await page.setViewportSize({ width, height: 900 });
                const expected = width >= 1400 ? 1320 : width >= 1200 ? 1140 : width >= 992 ? 960 : width >= 768 ? 720 : null;
                const layout = await page.evaluate(() => {
                    const host = document.querySelector('#wpcontent'); const box = host.getBoundingClientRect(); const style = getComputedStyle(host);
                    const card = document.querySelector('.smartsync-wrap'); const rect = card.getBoundingClientRect();
                    return { width: rect.width, left: rect.left, max: getComputedStyle(card).maxWidth,
                        available: box.width - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight),
                        start: box.left + parseFloat(style.paddingLeft), overflow: document.documentElement.scrollWidth > innerWidth + 1 };
                });
                assert.equal(layout.max, expected ? expected + 'px' : '100%', view + ' cap at ' + width);
                const target = Math.min(layout.available, expected || layout.available);
                assert.ok(Math.abs(layout.width - target) < 1, view + ' width at ' + width);
                assert.ok(Math.abs(layout.left - (layout.start + (layout.available - target) / 2)) < 1, view + ' centering at ' + width);
                assert.equal(layout.overflow, false, view + ' overflow at ' + width);
                if ([320, 768, 1920].includes(width)) await page.screenshot({ path: path.join(output, 'container-' + view + '-' + width + '.png'), fullPage: true });
            }
        }
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.setContent(shell('<button id="outside">Page control</button>' + render('settings')));
        await page.addScriptTag({ path: require.resolve('jquery') });
        await page.evaluate(() => {
            window.ajaxObject = { settings_strings: {}, settings_url: '/settings', widget: { scope: 'motion-fixture', providers: { bunny: 'Bunny CDN' } } };
            window.failure = { status: 'failed', run_id: 'failed-one', provider: 'bunny', storage: 'website-media', direction: 'upload', type: 'assets',
                total_files: 1784, processed_files: 1784, failed_files: 14, errors: ['Failed to upload: wp-includes/js/dist/vendor/react-dom.js. Check provider access and file permissions.'] };
            window.observe = async action => {
                const card = document.querySelector('.smartsync-background-indicator'); const samples = [];
                action();
                const until = performance.now() + 420;
                do {
                    const bounds = card.getBoundingClientRect();
                    samples.push({ height: bounds.height, width: bounds.width, opacity: Number(getComputedStyle(card).opacity), hidden: card.hidden, inert: card.inert,
                        flowHeight: document.querySelector('.smartsync-wrap').getBoundingClientRect().height, documentHeight: document.documentElement.scrollHeight });
                    await new Promise(requestAnimationFrame);
                } while (performance.now() < until);
                return samples;
            };
        });
        await page.addScriptTag({ path: path.join(root, 'assets/js/confirmations.js') });
        await page.addScriptTag({ path: path.join(root, 'assets/js/sync-widget.js') });
        await page.waitForFunction(() => document.querySelector('.smartsync-background-indicator'));
        const flow = await page.evaluate(() => ({ height: document.querySelector('.smartsync-wrap').getBoundingClientRect().height, documentHeight: document.documentElement.scrollHeight }));
        assert.equal(await page.locator('.smartsync-widget-reserve').count(), 0);
        const entering = await page.evaluate(() => window.observe(() => window.jQuery(document).trigger('smartsync:status', [window.failure])));
        assert.ok(entering.some(item => item.opacity > 0 && item.opacity < 1), 'Entrance must interpolate opacity');
        assert.equal(entering.at(-1).opacity, 1);
        const expanded = await page.evaluate(() => window.observe(() => document.querySelector('.smartsync-widget-toggle').click()));
        assert.ok(expanded.some(item => item.height > expanded[0].height + 2 && item.height < expanded.at(-1).height - 2), 'Expansion must interpolate height');
        assert.ok(expanded.some(item => item.width > 322 && item.width < 388), 'Expansion must interpolate width');
        assert.equal(await page.locator('.smartsync-background-indicator a').count(), 1);
        assert.equal(await page.locator('.smartsync-widget-terminal a').textContent(), 'View logs');
        await page.locator('.smartsync-background-indicator').screenshot({ path: path.join(output, 'failure-single-footer.png') });
        const collapsed = await page.evaluate(() => window.observe(() => document.querySelector('.smartsync-widget-toggle').click()));
        assert.ok(collapsed.some(item => item.height < collapsed[0].height - 2 && item.height > collapsed.at(-1).height + 2), 'Collapse must interpolate height');
        assert.equal(await page.locator('#smartsync-widget-details').getAttribute('aria-hidden'), 'true');
        assert.equal(await page.locator('#smartsync-widget-details').evaluate(element => element.inert), true);
        await page.evaluate(async () => {
            const toggle = document.querySelector('.smartsync-widget-toggle'); toggle.click();
            await new Promise(requestAnimationFrame); toggle.click();
        });
        await page.waitForFunction(() => document.querySelector('.smartsync-background-indicator').getBoundingClientRect().width === 320);
        await page.locator('#outside').focus(); await page.locator('.smartsync-widget-toggle').focus();
        const dismissed = await page.evaluate(() => window.observe(() => { document.querySelector('.smartsync-widget-terminal button').focus(); document.querySelector('.smartsync-widget-terminal button').click(); }));
        assert.equal(dismissed[0].hidden, false, 'Dismissal must not remove the card immediately');
        assert.equal(dismissed[0].inert, true);
        assert.ok(dismissed.some(item => !item.hidden && item.opacity > 0 && item.opacity < 1), 'Dismissal must fade');
        assert.equal(dismissed.at(-1).hidden, true);
        for (const samples of [entering, expanded, collapsed, dismissed]) {
            assert.ok(samples.every(sample => sample.flowHeight === flow.height && sample.documentHeight === flow.documentHeight), 'Floating widget must not change content or document height');
        }
        assert.equal(await page.evaluate(() => document.activeElement.id), 'outside');
        await page.evaluate(async () => {
            const update = data => window.jQuery(document).trigger('smartsync:status', [data]);
            update({ ...window.failure, run_id: 'failed-two' });
            await window.observe(() => {});
            document.querySelector('.smartsync-widget-terminal button').click();
            await new Promise(requestAnimationFrame);
            update({ ...window.failure, run_id: 'new-live-run', status: 'processing', processed_files: 2, errors: [] });
            await window.observe(() => {});
        });
        assert.equal(await page.locator('.smartsync-background-indicator').evaluate(element => element.hidden || element.inert), false, 'A stale dismissal must not hide a new run');
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.evaluate(() => window.jQuery(document).trigger('smartsync:status', [{ ...window.failure, run_id: 'reduced-motion-failure' }]));
        const reduced = await page.evaluate(() => {
            const card = document.querySelector('.smartsync-background-indicator'); const duration = getComputedStyle(card).transitionDuration;
            document.querySelector('.smartsync-widget-terminal button').click(); return { duration, hidden: card.hidden };
        });
        assert.equal(reduced.duration, '0s'); assert.equal(reduced.hidden, true);
        await page.setViewportSize({ width: 320, height: 360 });
        await page.evaluate(() => {
            window.jQuery(document).trigger('smartsync:status', [{ ...window.failure, run_id: 'mobile-error', errors: ['Long failure detail '.repeat(100)] }]);
            document.querySelector('.smartsync-widget-toggle').click();
        });
        const mobile = await page.locator('.smartsync-background-indicator').boundingBox();
        assert.ok(mobile.x >= 0 && mobile.y >= 0 && mobile.x + mobile.width <= 320 && mobile.y + mobile.height <= 360);
        assert.ok(await page.locator('.smartsync-widget-content').evaluate(element => element.scrollHeight > element.clientHeight));
        assert.equal(await page.locator('.smartsync-widget-terminal button').isVisible(), true);
        await page.screenshot({ path: path.join(output, 'mobile-scrollable-details.png'), fullPage: true });
        assert.deepEqual(errors, []);
        fs.writeFileSync(path.join(output, 'report.json'), JSON.stringify({ widths, pages: ['settings', 'wizard', 'domain'], centeredContainerMd: true,
            entrance: true, expansion: true, collapse: true, rapidReversal: true, smoothDismissal: true, focusReturn: true, newRunDuringDismissal: true,
            reducedMotion: true, singleFooterLink: true, mobileScroll: true, stablePageLayout: true, errors }, null, 2));
        console.log('Container and widget motion passed: all breakpoints/pages, animated entry/expand/collapse/dismiss, one footer link, interruption, focus, reduced motion and mobile scrolling. Preview: ' + output);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
