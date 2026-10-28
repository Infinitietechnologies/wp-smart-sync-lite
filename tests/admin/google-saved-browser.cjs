// Real PHP view and browser file APIs; account values and all HTTP responses are synthetic.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { chromium } = require('playwright-core');
const { render, googleKey } = require('./harness.cjs');
const root = path.resolve(__dirname, '../..');
const output = path.join(os.tmpdir(), 'smartsync-google-saved-preview');
const executablePath = [process.env.SMARTSYNC_BROWSER, 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe'].find(file => file && fs.existsSync(file));
if (!executablePath) throw new Error('Set SMARTSYNC_BROWSER to a local Edge/Chromium executable.');
const css = ['wizard.css', 'ui-improvements.css', 'enhanced-ui.css', 'cloud-setup.css'].map(file => fs.readFileSync(path.join(root, 'assets/css', file), 'utf8')).join('\n');
const credentials = JSON.parse(googleKey());

(async () => {
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ executablePath, headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1200 }, reducedMotion: 'reduce' });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => route.abort());
        const html = render('wizard', { service_provider: 'google', bucket: 'website-media', __google_credentials: credentials });
        assert.ok(!html.includes('fixture-private-value') && !html.includes('fixture-key-id') && !html.includes('fixture-client-id'));
        await page.setContent('<!doctype html><html lang="en"><head><meta charset="utf-8"><style>body{margin:20px;background:#f0f0f1;font-family:system-ui}button,input,select{font:inherit}.screen-reader-text{position:absolute;clip-path:inset(50%);width:1px;height:1px;overflow:hidden}' + css + '</style></head><body>' + html + '</body></html>');
        await page.addScriptTag({ path: require.resolve('jquery') });
        await page.evaluate(credentials => {
            window.ajax_object = { ajax_url: '/fixture-ajax', nonce: 'fixture-nonce', strings: {} };
            window.fixtureActions = [];
            window.jQuery.ajax = options => {
                const deferred = window.jQuery.Deferred();
                const action = options.data instanceof FormData ? options.data.get('action') : options.data.action;
                window.fixtureActions.push(action);
                setTimeout(() => deferred.resolve({ success: true, data: action === 'get_saved_google_credentials'
                    ? { reference: options.data.credential_reference, credentials } : { status: 'idle' } }), 30);
                const xhr = deferred.promise();
                xhr.abort = () => deferred.reject({ status: 0 }, 'abort');
                return xhr;
            };
        }, credentials);
        await page.addScriptTag({ path: path.join(root, 'assets/js/wizard.js') });
        await page.waitForFunction(() => getComputedStyle(document.querySelector('.smartsync-cloud-setup')).getPropertyValue('--setup-step-count').trim() === '3');
        assert.equal(await page.locator('#google-saved-account').isVisible(), true);
        assert.equal(await page.locator('#google-upload').isVisible(), false);
        assert.equal(await page.evaluate(() => window.fixtureActions.includes('get_saved_google_credentials')), false);
        await page.screenshot({ path: path.join(output, 'saved-account-1440.png'), fullPage: true });
        await page.locator('#google-saved-details summary').focus();
        await page.keyboard.press('Enter');
        for (const width of [320, 390, 768, 1440, 1920]) {
            await page.setViewportSize({ width, height: 1200 });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Saved account overflow at ' + width);
            assert.equal(JSON.parse(await page.locator('#google-saved-code').textContent()).private_key, '[Hidden]');
            await page.screenshot({ path: path.join(output, 'saved-values-masked-' + width + '.png'), fullPage: true });
        }
        await page.locator('#google-saved-reveal').click();
        await page.waitForFunction(() => document.querySelector('#google-saved-reveal').getAttribute('aria-pressed') === 'true');
        assert.match(await page.locator('#google-saved-code').textContent(), /fixture-private-value/);
        await page.locator('#google-saved-details summary').click();
        await page.waitForFunction(() => !document.querySelector('#google-saved-code').textContent.includes('fixture-private-value'));
        const picker = page.waitForEvent('filechooser');
        await page.locator('#google-saved-replace').click();
        await (await picker).setFiles({ name: 'replacement.json', mimeType: 'application/json', buffer: Buffer.from(googleKey()) });
        await page.waitForFunction(() => document.querySelector('#google-upload').dataset.state === 'ready');
        assert.equal(await page.locator('#google-saved-account').isVisible(), false);
        await page.locator('#google-file-remove').click();
        assert.equal(await page.locator('#google-saved-account').isVisible(), true);
        assert.equal(await page.locator('#google-upload').isVisible(), false);
        assert.deepEqual(errors, []);
        console.log('Saved Google account browser passed: metadata, keyboard disclosure, explicit reveal, remasking, replacement and five responsive widths. Screenshots: ' + output);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
