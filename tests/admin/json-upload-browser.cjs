// Actual PHP view and browser file APIs; cloud requests are synthetic.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { chromium } = require('playwright-core');
const { render, googleKey } = require('./harness.cjs');
const root = path.resolve(__dirname, '../..');
const output = path.join(os.tmpdir(), 'smartsync-json-upload-preview');
const executablePath = [process.env.SMARTSYNC_BROWSER, 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    '/usr/bin/chromium', '/usr/bin/google-chrome'].find(file => file && fs.existsSync(file));
if (!executablePath) throw new Error('Set SMARTSYNC_BROWSER to a local Edge/Chromium executable.');
const css = ['wizard.css', 'ui-improvements.css', 'enhanced-ui.css', 'cloud-setup.css'].map(file => fs.readFileSync(path.join(root, 'assets/css', file), 'utf8')).join('\n');

(async () => {
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ executablePath, headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1200 }, reducedMotion: 'reduce' });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => route.abort());
        await page.setContent('<!doctype html><html lang="en"><head><meta charset="utf-8"><style>body{margin:20px;background:#f0f0f1;font-family:system-ui}button,input,select{font:inherit}.screen-reader-text{position:absolute;clip-path:inset(50%);width:1px;height:1px;overflow:hidden}' + css + '</style></head><body>' + render('wizard', { service_provider: 'google' }) + '</body></html>');
        await page.addScriptTag({ path: require.resolve('jquery') });
        await page.evaluate(() => {
            window.ajax_object = { ajax_url: '/fixture-ajax', nonce: 'fixture-nonce', strings: {} };
            window.fixtureActions = [];
            window.fixturePause = false;
            window.fixtureResume = null;
            window.jQuery.ajax = options => {
                const deferred = window.jQuery.Deferred();
                const form = options.data instanceof FormData;
                const action = form ? options.data.get('action') : options.data.action;
                window.fixtureActions.push({ action, fileCount: form ? options.data.getAll('service_json').length : 0,
                    filename: form && options.data.get('service_json') ? options.data.get('service_json').name : '',
                    token: !form ? options.data.google_selection_token : '' });
                const finish = () => deferred.resolve({ success: true, data: action === 'check_sync_status' ? { status: 'idle' } :
                    action === 'list_s3_buckets' ? { buckets: [{ name: 'website-media', region: 'US' }], selectionToken: 'browser-selected-token' } : {} });
                if (window.fixturePause && action === 'list_s3_buckets') window.fixtureResume = finish;
                else setTimeout(finish, 40);
                return deferred.promise();
            };
        });
        await page.addScriptTag({ path: path.join(root, 'assets/js/wizard.js') });
        await page.addScriptTag({ path: path.join(root, 'assets/js/ui-improvements.js') });
        await page.waitForFunction(() => document.querySelector('#service_provider').value === 'google' && getComputedStyle(document.querySelector('.smartsync-cloud-setup')).getPropertyValue('--setup-step-count').trim() === '3');
        const widths = [320, 390, 768, 1440, 1920];
        const capture = async (name, sizes = widths) => {
            for (const width of sizes) {
                await page.setViewportSize({ width, height: 1200 });
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' overflows at ' + width);
                await page.screenshot({ path: path.join(output, name + '-' + width + '.png'), fullPage: true });
            }
        };
        await capture('google-upload-empty', [320, 1440]);
        await page.locator('#service-json').setInputFiles({ name: 'service-account.p12', mimeType: 'application/x-pkcs12', buffer: Buffer.from('private-binary-fixture') });
        assert.equal(await page.locator('#google-p12-fields').isVisible(), true);
        assert.equal(await page.locator('#google-file-preview').isVisible(), false);
        assert.equal(await page.locator('#google-json-code').textContent(), '');
        await page.locator('#step-1-next').click();
        assert.equal(await page.evaluate(() => document.activeElement.id), 'google-p12-email');
        await page.locator('#google-p12-email').fill('media@my-project.iam.gserviceaccount.com');
        await page.locator('#google-p12-project').fill('my-project');
        await page.locator('#google-p12-password').fill('fixture-password');
        await capture('google-p12-fields');
        await page.locator('#google-file-remove').click();
        assert.equal(await page.locator('#google-p12-fields').isVisible(), false);
        assert.equal(await page.locator('#google-p12-password').inputValue(), '');
        const picker = page.waitForEvent('filechooser');
        await page.locator('#google-file-browse').focus();
        await page.keyboard.press('Enter');
        await (await picker).setFiles({ name: 'service-account.json', mimeType: 'application/json', buffer: Buffer.from(googleKey()) });
        await page.waitForFunction(() => document.querySelector('#google-upload').dataset.state === 'ready');
        assert.equal(await page.locator('#google-file-project').textContent(), 'example-project');
        assert.doesNotMatch(await page.locator('#google-json-code').textContent(), /fixture-private-value|fixture-key-id/);
        assert.equal(await page.evaluate(() => window.fixtureActions.filter(item => item.action !== 'check_sync_status').length), 0);
        await capture('google-json-preview');
        await page.locator('#google-json-reveal').click();
        assert.match(await page.locator('#google-json-code').textContent(), /fixture-private-value/);
        await page.locator('#service_provider').selectOption('aws');
        await page.locator('#service_provider').selectOption('google');
        assert.doesNotMatch(await page.locator('#google-json-code').textContent(), /fixture-private-value/);
        await page.locator('#google-file-remove').click();
        assert.equal(await page.locator('#google-file-dropzone').isVisible(), true);
        assert.equal(await page.locator('#google-json-code').textContent(), '');
        await page.locator('#service-json').setInputFiles({ name: 'broken.json', mimeType: 'application/json', buffer: Buffer.from('{broken') });
        await page.waitForFunction(() => document.querySelector('#google-upload').dataset.state === 'error');
        assert.match(await page.locator('#google-file-error').textContent(), /invalid JSON/);
        await capture('google-upload-error', [320, 1440]);
        const dataTransfer = await page.evaluateHandle(contents => {
            const transfer = new DataTransfer();
            transfer.items.add(new File([contents], 'dropped-service-account.json', { type: 'application/json' }));
            return transfer;
        }, googleKey({ project_id: 'replacement-project' }));
        await page.locator('#google-upload').dispatchEvent('dragenter', { dataTransfer });
        await page.locator('#google-upload').dispatchEvent('drop', { dataTransfer });
        await dataTransfer.dispose();
        await page.waitForFunction(() => document.querySelector('#google-upload').dataset.state === 'ready');
        assert.equal(await page.locator('#google-file-project').textContent(), 'replacement-project');
        await page.evaluate(() => { window.fixturePause = true; });
        await page.locator('#step-1-next').click();
        assert.equal(await page.locator('#google-file-remove').isDisabled(), true);
        assert.equal(await page.locator('#google-file-replace').isDisabled(), true);
        assert.equal(await page.locator('#google-json-reveal').isDisabled(), true);
        assert.deepEqual(await page.evaluate(() => window.fixtureActions.filter(item => item.action === 'list_s3_buckets').map(item => [item.fileCount, item.filename])), [[1, 'dropped-service-account.json']]);
        await page.evaluate(() => { window.fixtureResume(); });
        await page.locator('#wizard-step-2').waitFor({ state: 'visible' });
        await page.locator('#step-2-next').click();
        await page.locator('#wizard-step-final').waitFor({ state: 'visible' });
        assert.equal(await page.evaluate(() => window.fixtureActions.find(item => item.action === 'configure_aws').token), 'browser-selected-token');
        assert.equal(await page.locator('#google-json-code').textContent(), '');
        assert.equal(await page.locator('#wizard-message').isVisible(), false);
        for (const width of widths) {
            await page.setViewportSize({ width, height: 1200 });
            const layout = await page.evaluate(() => ({ card: document.querySelector('#wizard-step-final').getBoundingClientRect().width, steps: document.querySelector('#wizard-stepper').getBoundingClientRect().width }));
            assert.ok(Math.abs(layout.card - layout.steps) < 2, 'Completion does not span the wizard at ' + width);
        }
        await capture('google-complete');
        assert.deepEqual(errors, []);
        fs.writeFileSync(path.join(output, 'report.json'), JSON.stringify({ widths, keyboardPicker: true, dragAndDrop: true, localPreview: true, maskedValues: true, replaceRemove: true, invalidJson: true, requestLocks: true, selectedToken: true, fullWidthCompletion: true, errors }, null, 2));
        console.log('JSON/P12 uploader browser passed: P12 fields and validation, keyboard picker, drag/drop, safe JSON preview, errors, request locks and full-width completion at five widths. Screenshots: ' + output);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
