// Actual PHP view + maintained CSS/JS in Edge, with entirely synthetic cloud responses.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { chromium } = require('playwright-core');
const { render } = require('./harness.cjs');

const root = path.resolve(__dirname, '../..');
const output = path.join(os.tmpdir(), 'smartsync-cloud-setup-preview');
const executablePath = [process.env.SMARTSYNC_BROWSER, 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe'].find(file => file && fs.existsSync(file));
if (!executablePath) throw new Error('Set SMARTSYNC_BROWSER to a local Edge/Chromium executable.');
const css = ['wizard.css', 'ui-improvements.css', 'enhanced-ui.css', 'cloud-setup.css'].map(file => fs.readFileSync(path.join(root, 'assets/css', file), 'utf8')).join('\n');
const fontFile = 'C:/projects/wordpress/main-wordpress/wordpress/wp-includes/fonts/dashicons.woff2';
const iconCss = fs.existsSync(fontFile) ? '@font-face{font-family:dashicons;src:url(data:font/woff2;base64,' + fs.readFileSync(fontFile).toString('base64') + ')}.dashicons{font-family:dashicons;display:inline-block;width:20px;height:20px;font-size:20px;line-height:1}' : '';

(async () => {
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ executablePath, headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1100 }, reducedMotion: 'reduce' });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => route.abort());
        await page.setContent('<!doctype html><html lang="en"><head><meta charset="utf-8"><style>body{margin:20px;background:#f0f0f1;font-family:system-ui}button,input,select{font:inherit}.screen-reader-text{position:absolute;clip-path:inset(50%);width:1px;height:1px;overflow:hidden}' + iconCss + css + '</style></head><body>' + render('wizard', { service_provider: 'bunny', bucket: 'currently-connected' }) + '</body></html>');
        await page.addScriptTag({ path: require.resolve('jquery') });
        await page.evaluate(() => {
            window.ajax_object = { ajax_url: '/fixture-ajax', nonce: 'fixture-nonce', strings: {}, settings_url: '/settings' };
            window.fixtureActions = [];
            window.fixtureFailListing = false;
            window.fixturePauseListing = false;
            window.fixtureFinishListing = null;
            window.fixtureSyncStatus = 'idle';
            window.jQuery.ajax = options => {
                const deferred = window.jQuery.Deferred();
                const data = options.data;
                const action = data instanceof FormData ? data.get('action') : data.action;
                const provider = data instanceof FormData ? data.get('service_provider') : data.service_provider;
                window.fixtureActions.push(action);
                const finish = () => {
                    if (window.fixtureFailListing && action === 'list_s3_buckets') {
                        window.fixtureFailListing = false;
                        deferred.reject({ responseJSON: { success: false, data: 'Check your account API key and try again.' } });
                    } else if (action === 'list_s3_buckets') {
                        deferred.resolve({ success: true, data: { buckets: provider === 'bunny' ? [
                            { name: 'website-media', region: 'DE', pullzones: ['website-media.b-cdn.net'] },
                            { name: 'unconnected-zone', region: 'DE', pullzones: [], issue: 'Connect an active public pull zone to this storage zone.' }
                        ] : [{ name: 'website-media', region: 'us-east-1' }] } });
                    } else if (action === 'check_sync_status') {
                        deferred.resolve({ success: true, data: { status: window.fixtureSyncStatus } });
                    } else deferred.resolve({ success: true, data: 'Connection saved.' });
                };
                if (window.fixturePauseListing && action === 'list_s3_buckets') window.fixtureFinishListing = finish;
                else setTimeout(finish, 80);
                return deferred.promise();
            };
        });
        await page.addScriptTag({ path: path.join(root, 'assets/js/wizard.js') });
        await page.addScriptTag({ path: path.join(root, 'assets/js/ui-improvements.js') });
        await page.waitForFunction(() => document.querySelector('#step-1-next').textContent.includes('storage zones'));
        assert.equal(await page.locator('.setup-page-header h1').textContent(), 'Cloud setup');
        assert.equal(await page.locator('.setup-page-header a').count(), 1);
        assert.equal(await page.locator('.smartsync-breadcrumbs, .setup-page-header .setup-eyebrow, .setup-page-description, #setup-selected-provider').count(), 0);

        const capture = async (name, widths = [320, 390, 768, 1440]) => {
            for (const width of widths) {
                await page.setViewportSize({ width, height: 1100 });
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' overflows at ' + width);
                assert.ok((await page.locator('.setup-page-header').boundingBox()).height < 130, 'Setup header is too tall at ' + width);
                await page.screenshot({ path: path.join(output, name + '-' + width + '.png'), fullPage: true });
            }
        };
        await capture('bunny-connect');
        assert.match(await page.locator('#bunny-api-key').getAttribute('placeholder'), /Bunny/);
        for (const provider of ['aws', 'digitalocean', 'wasabi', 'google', 'cloudflare']) {
            await page.locator('#service_provider').selectOption(provider);
            if (provider !== 'google') {
                assert.ok((await page.locator('#aws_iam_key').getAttribute('placeholder')).trim());
                assert.ok((await page.locator('#aws_iam_secret').getAttribute('placeholder')).trim());
            }
            assert.equal(await page.locator('.setup-provider-guide:not([hidden])').getAttribute('data-provider'), provider);
            assert.equal(await page.locator('#setup-active-provider').textContent(), 'Bunny CDN');
            assert.notEqual(await page.locator('#service_provider option:checked').textContent(), 'Bunny CDN');
            assert.equal(await page.locator('.stepper .step:not([hidden])').count(), provider === 'aws' ? 4 : 3);
            await capture(provider + '-connect', [320, 1440]);
            if (provider === 'aws') {
                await page.locator('#aws_iam_key').fill('synthetic-key');
                await page.locator('#aws_iam_secret').fill('synthetic-secret');
                await page.locator('#step-1-next').click();
                await page.waitForFunction(() => !document.querySelector('#wizard-step-2').hidden && !document.querySelector('#new-bucket-radio').disabled);
                await page.locator('#new-bucket-radio').check();
                assert.match(await page.locator('#new-bucket-help').textContent(), /Changing regions will not make a taken name available/);
                assert.equal(await page.locator('#new-bucket-help').getAttribute('data-warning'), 'true');
                await capture('aws-new-bucket-warning', [320, 1440]);
                await page.locator('#new_bucket_name').fill('website-media');
                await page.locator('#step-2-next').click();
                assert.equal(await page.locator('#wizard-step-3').isVisible(), true);
                const deliveryGuide = page.locator('.setup-provider-guide:not([hidden])');
                assert.equal(await deliveryGuide.getAttribute('data-guide-stage'), 'delivery');
                assert.match(await page.locator('#setup-guide-title').textContent(), /Set up CloudFront delivery/);
                assert.match(await deliveryGuide.locator('.setup-console-link').getAttribute('href'), /cloudfront/);
                await deliveryGuide.locator('.setup-guide-permissions summary').click();
                await capture('aws-cloudfront-guide');
                await page.locator('#step-3-back').click();
                assert.equal(await page.locator('.setup-provider-guide:not([hidden])').getAttribute('data-guide-stage'), 'account');
                await page.locator('#step-2-back').click();
            }
        }
        await page.locator('#service_provider').selectOption('bunny');
        await page.locator('#step-1-next').click();
        assert.equal(await page.locator('#bunny-api-key').getAttribute('aria-invalid'), 'true');
        assert.ok(await page.locator('#bunny-api-key').evaluate(element => element === document.activeElement));
        await page.locator('#bunny-api-key').fill('synthetic-account-key');
        await page.getByRole('button', { name: 'Show', exact: true }).click();
        assert.equal(await page.locator('#bunny-api-key').getAttribute('type'), 'text');
        await page.getByRole('button', { name: 'Hide', exact: true }).click();
        await page.evaluate(() => { window.fixtureFailListing = true; });
        await page.locator('#step-1-next').click();
        await page.waitForFunction(() => document.querySelector('#wizard-message').dataset.kind === 'error');
        assert.equal(await page.locator('#wizard-step-1').isVisible(), true);
        await page.evaluate(() => { window.fixturePauseListing = true; });
        await page.locator('#step-1-next').click();
        assert.equal(await page.locator('#service_provider').isDisabled(), true);
        assert.equal(await page.locator('#bunny-api-key').isDisabled(), true);
        assert.equal(await page.locator('#wizard-message').isVisible(), false);
        assert.equal(await page.locator('button[data-busy="true"]').count(), 1);
        await capture('bunny-connecting', [320, 1440]);
        await page.evaluate(() => { window.fixturePauseListing = false; window.fixtureFinishListing(); });
        await page.locator('#wizard-step-2').waitFor({ state: 'visible' });
        assert.equal(await page.locator('#bucket-type-selector').isVisible(), false);
        const listingCount = await page.evaluate(() => window.fixtureActions.filter(action => action === 'list_s3_buckets').length);
        await page.locator('[data-setup-step="1"]').focus();
        await page.keyboard.press('Enter');
        assert.equal(await page.locator('#wizard-step-1').isVisible(), true);
        await page.locator('[data-setup-step="2"]').click();
        assert.equal(await page.locator('#wizard-step-2').isVisible(), true);
        assert.equal(await page.evaluate(() => window.fixtureActions.filter(action => action === 'list_s3_buckets').length), listingCount);
        await capture('bunny-storage');
        await page.evaluate(() => { window.fixturePauseListing = true; });
        await page.locator('#refresh-buckets').click();
        assert.equal(await page.locator('#aws_bucket_list').isDisabled(), true);
        assert.equal(await page.locator('#pull-zones').isDisabled(), true);
        assert.equal(await page.locator('#step-2-back').isDisabled(), true);
        assert.equal(await page.locator('[data-setup-step="1"]').isDisabled(), true);
        assert.equal(await page.locator('#wizard-message').isVisible(), false);
        assert.match(await page.locator('#refresh-buckets').textContent(), /Refreshing/);
        await capture('bunny-refreshing', [320, 1440]);
        await page.evaluate(() => { window.fixturePauseListing = false; window.fixtureFinishListing(); });
        await page.waitForFunction(() => !document.querySelector('#aws_bucket_list').disabled);
        await page.evaluate(() => { window.fixtureSyncStatus = 'processing'; window.jQuery(document).trigger('smartsync:refresh-status'); });
        await page.waitForFunction(() => document.querySelector('#setup-controls').disabled, null, { timeout: 7000 });
        assert.equal(await page.locator('#setup-sync-lock').isVisible(), true);
        assert.equal(await page.locator('#step-2-next').isDisabled(), true);
        await capture('bunny-sync-locked', [320, 1440]);
        await page.evaluate(() => { window.fixtureSyncStatus = 'idle'; });
        await page.waitForFunction(() => !document.querySelector('#setup-controls').disabled, null, { timeout: 7000 });
        await page.locator('#aws_bucket_list').selectOption('1');
        await page.locator('#step-2-next').click();
        assert.equal(await page.locator('#pull-zones').getAttribute('aria-invalid'), 'true');
        assert.ok(await page.locator('#bunny-pull-zone-help').isVisible());
        await page.locator('#aws_bucket_list').selectOption('0');
        await page.locator('#step-2-next').click();
        await page.locator('#wizard-step-final').waitFor({ state: 'visible' });
        assert.equal(await page.locator('#setup-summary-delivery').textContent(), 'website-media.b-cdn.net');
        assert.equal(await page.locator('#step-final-indicator .step-icon').textContent(), '3');
        await capture('bunny-complete', [320, 1440]);
        assert.deepEqual(errors, []);
        fs.writeFileSync(path.join(output, 'report.json'), JSON.stringify({ providers: 6, widths: [320, 390, 768, 1440], invalidFieldFocus: true, revealRemask: true, connectionRetry: true, storageValidation: true, completion: true, requestLocks: true, activeSyncLock: true, stepClickAndKeyboard: true, providerContext: true, errors }, null, 2));
        console.log('Cloud setup browser passed: six providers, four widths, request/sync locks, provider context, step clicks/keyboard, retry and completion. Screenshots: ' + output);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
