// Maintained PHP views in a real browser; AWS responses are synthetic and all network access is blocked.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { chromium } = require('playwright-core');
const { render } = require('./harness.cjs');
const root = path.resolve(__dirname, '../..');
const output = path.join(os.tmpdir(), 'smartsync-inline-cloudfront-preview');
const executablePath = [process.env.SMARTSYNC_BROWSER, 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe'].find(file => file && fs.existsSync(file));
if (!executablePath) throw new Error('Set SMARTSYNC_BROWSER to a local browser executable.');
const css = ['wizard.css', 'ui-improvements.css', 'enhanced-ui.css', 'custom-domain.css'].map(file => fs.readFileSync(path.join(root, 'assets/css', file), 'utf8')).join('\n');
(async () => {
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ executablePath, headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1200 }, reducedMotion: 'reduce' });
        const errors = []; page.on('pageerror', error => errors.push(error.message));
        const html = render('domain', { service_provider: 'aws', bucket: 'website-media', region: 'eu-north-1', iam_key: 'fixture-key', iam_secret: 'fixture-secret', url: 'website-media.s3.eu-north-1.amazonaws.com' });
        assert.doesNotMatch(html, /fixture-key|fixture-secret/);
        const fixtureHtml = '<!doctype html><html lang="en"><head><meta charset="utf-8"><style>body{margin:20px;background:#f0f0f1;font-family:system-ui}button,input{font:inherit}' + css + '</style></head><body>' + html + '</body></html>';
        await page.route('**/*', route => route.request().isNavigationRequest() ? route.fulfill({ contentType: 'text/html', body: fixtureHtml }) : route.abort());
        await page.goto('https://example.test/wp-admin/admin.php?page=custom-cloudfront-url');
        await page.addScriptTag({ path: require.resolve('jquery') });
        await page.evaluate(() => {
            window.ajax_object = { ajax_url: '/fixture-ajax', nonce: 'fixture-nonce', strings: {} };
            window.ajaxObject = { settings_strings: {} };
            window.fixtureActions = [];
            window.fixtureDeployed = false;
            window.jQuery.ajax = options => {
                const deferred = window.jQuery.Deferred();
                window.fixtureActions.push(options.data.action);
                setTimeout(() => deferred.resolve({ success: true, data: options.data.action === 'setup_custom_cloudfront'
                    ? { state: window.fixtureDeployed ? 'ready' : 'deploying', distribution_id: 'EINLINE123', domain: 'dinline.cloudfront.net', configuration_reference: options.data.configuration_reference }
                    : options.data.action === 'generate_ssl_certificate' ? { certificateArn: 'arn:aws:acm:us-east-1:123456789012:certificate/fixture', records: [] }
                        : { verified: false, records: [] } }), 40);
                const xhr = deferred.promise(); xhr.abort = () => deferred.reject({ status: 0 }, 'abort'); return xhr;
            };
        });
        await page.addScriptTag({ path: path.join(root, 'assets/js/wizard.js') });
        await page.addScriptTag({ path: path.join(root, 'assets/js/ui-improvements.js') });
        await page.evaluate(() => new Promise(resolve => window.jQuery(resolve)));
        assert.equal(await page.locator('.smartsync-breadcrumbs').count(), 0);
        assert.equal(await page.locator('.smartsync-subpage-header .smartsync-back-link svg').count(), 1);
        const headerStyles = target => target.evaluate(() => {
            const title = getComputedStyle(document.querySelector('.smartsync-subpage-header h1'));
            const link = getComputedStyle(document.querySelector('.smartsync-back-link'));
            return { title: ['fontSize', 'fontWeight', 'lineHeight', 'color', 'letterSpacing', 'margin', 'padding', 'borderBottomWidth'].map(key => title[key]),
                link: ['fontSize', 'color', 'gap', 'margin', 'display'].map(key => link[key]) };
        });
        const cloudPage = await browser.newPage({ viewport: { width: 1440, height: 1200 } });
        await cloudPage.setContent('<style>' + css + fs.readFileSync(path.join(root, 'assets/css/cloud-setup.css'), 'utf8') + '</style>' + render('wizard', { service_provider: 'aws' }));
        assert.deepEqual(await headerStyles(page), await headerStyles(cloudPage), 'Subpage headers use different typography or spacing');
        await cloudPage.close();
        const capture = async name => {
            for (const width of [320, 390, 768, 1440, 1920]) {
                await page.setViewportSize({ width, height: 1200 });
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' overflow at ' + width);
                await page.screenshot({ path: path.join(output, name + '-' + width + '.png'), fullPage: true });
            }
        };
        await capture('setup');
        assert.equal(await page.locator('#custom-url-flow').isVisible(), false);
        await page.locator('#setup-custom-cloudfront').click();
        await page.waitForFunction(() => document.querySelector('#setup-custom-cloudfront').textContent === 'Check deployment');
        await capture('deploying');
        assert.equal(await page.evaluate(() => window.fixtureActions.length), 1);
        await page.evaluate(() => { window.fixtureDeployed = true; });
        await page.locator('#setup-custom-cloudfront').click();
        await page.locator('#continue-custom-domain').waitFor({ state: 'visible' });
        await page.locator('#continue-custom-domain').click();
        assert.equal(await page.locator('#custom-url-flow').isVisible(), true);
        assert.equal(await page.locator('#custom-domain-fields').isDisabled(), false);
        await capture('custom-domain');
        await page.locator('#custom-domain').fill('cdn.example.test');
        await page.locator('#generate-certificate').click();
        await page.waitForFunction(() => window.fixtureActions.includes('check_dns_record'));
        await page.locator('#custom-domain').fill('changed.example.test'); // Cancels this synthetic DNS workflow.
        assert.deepEqual(errors, []);
        console.log('Inline CloudFront browser passed: setup, manual deployment check, same-page custom URL continuation and certificate workflow at five widths. Screenshots: ' + output);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
