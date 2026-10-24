// Run only against a disposable local WordPress fixture. All cloud AJAX is mocked.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright-core');

const argumentsMap = Object.fromEntries(process.argv.slice(2).map(value => value.replace(/^--/, '').split(/=(.*)/s).slice(0, 2)));
const wordpress = argumentsMap.wordpress || process.env.SMARTSYNC_WP_ROOT;
const wpCli = argumentsMap['wp-cli'] || process.env.SMARTSYNC_WP_CLI;
const base = argumentsMap.url || 'http://127.0.0.1:8097';
const output = argumentsMap.output || path.join(os.tmpdir(), 'smartsync-admin-browser');
const executablePath = argumentsMap.browser || process.env.SMARTSYNC_BROWSER || [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    '/usr/bin/chromium', '/usr/bin/google-chrome'
].find(file => fs.existsSync(file));
if (!wordpress || !wpCli || !executablePath || !process.argv.includes('--seed-fixture')) {
    throw new Error('Provide --wordpress=PATH --wp-cli=PATH --seed-fixture and a system Chromium/Edge browser. This writes and restores test-only fixture settings.');
}
if (!['127.0.0.1', 'localhost', '[::1]'].includes(new URL(base).hostname)) throw new Error('Browser smoke requires a loopback fixture URL.');

function wp(code) {
    return execFileSync('php', [wpCli, '--path=' + wordpress, '--skip-plugins', '--skip-themes', 'eval', code], { encoding: 'utf8', maxBuffer: 1024 * 1024 });
}
function phpValue(value) {
    return 'json_decode(base64_decode("' + Buffer.from(JSON.stringify(value)).toString('base64') + '"), true)';
}

(async function () {
    fs.mkdirSync(output, { recursive: true });
    const original = JSON.parse(wp('echo wp_json_encode([get_option("smarl_settings", null), get_option("custom_url_option", null)]);'));
    let browser;
    try {
        // Deliberately invalid test credentials. Requests that could touch a provider are intercepted.
        wp('update_option("smarl_settings", ' + phpValue({
            bucket: 'fixture-bucket', region: 'us-east-1', service_provider: 'aws',
            iam_key: 'fixture-key', iam_secret: 'fixture-secret', use_cloudfront: true,
            cloudfront_url: 'fixture.cloudfront.net', distribution_id: 'FIXTURE',
            url: 'fixture.cloudfront.net', canEnableMediaCDN: true
        }) + '); update_option("custom_url_option", []);');
        const cookies = JSON.parse(wp('$expires=time()+900; echo wp_json_encode([["name"=>AUTH_COOKIE,"value"=>wp_generate_auth_cookie(1,$expires,"auth")],["name"=>LOGGED_IN_COOKIE,"value"=>wp_generate_auth_cookie(1,$expires,"logged_in")]]);'));
        browser = await chromium.launch({ executablePath, headless: true });
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
        await context.addCookies(cookies.map(cookie => ({ ...cookie, url: base, httpOnly: true, sameSite: 'Lax' })));
        const runtimeErrors = [];
        const localFailures = [];
        const cloudRequests = [];
        let dnsChecks = 0;
        let syncChecks = 0;
        const page = await context.newPage();
        page.on('pageerror', error => runtimeErrors.push(error.message));
        page.on('response', response => {
            if (response.url().startsWith(base) && response.status() >= 400) localFailures.push(response.status() + ' ' + new URL(response.url()).pathname);
        });
        await page.route('**/*', async route => {
            const url = new URL(route.request().url());
            if (url.origin !== new URL(base).origin) return route.abort();
            if (!url.pathname.endsWith('/admin-ajax.php')) return route.continue();
            const body = route.request().postData() || '';
            const encoded = new URLSearchParams(body);
            const multipartAction = body.match(/name="action"[\r\n]+([^\r\n]+)/);
            const action = multipartAction ? multipartAction[1] : encoded.get('action');
            if (action) cloudRequests.push(action);
            let data = {};
            if (action === 'check_sync_status') {
                syncChecks++;
                data = { status: syncChecks > 1 ? 'processing' : 'idle', type: 'media', direction: 'upload', total_files: 4, processed_files: 1 };
            } else if (action === 'list_s3_buckets') {
                data = { buckets: [{ name: 'fixture-bucket', region: 'us-east-1', pullzones: ['fixture.b-cdn.net'] }] };
            } else if (action === 'configure_aws') {
                assert.equal(encoded.get('use_cloudfront'), 'true');
            } else if (action === 'sync_local_to_aws') {
                data = { total_files: 4 };
            } else if (action === 'generate_ssl_certificate') {
                data = { certificateArn: 'arn:aws:acm:us-east-1:123456789012:certificate/fixture', records: [
                    { DomainName: 'cdn.example.test', Type: 'CNAME', Name: 'cdn.example.test', Value: 'fixture.cloudfront.net', systemGenerated: true }
                ] };
            } else if (action === 'check_dns_record') {
                dnsChecks++;
                assert.ok(encoded.get('nonce'));
                data = { verified: true, records: [
                    { DomainName: 'cdn.example.test', Type: 'CNAME', Name: '_verify.cdn.example.test', Value: '_fixture.acm-validations.aws', ValidationStatus: 'SUCCESS', systemGenerated: false },
                    { DomainName: 'cdn.example.test', Type: 'CNAME', Name: 'cdn.example.test', Value: 'fixture.cloudfront.net', ValidationStatus: 'SUCCESS', systemGenerated: true }
                ] };
            } else if (action === 'fetch_aws_analytics') {
                data = { total_files: 0, total_storage: '0 B', price_in_dollars: null };
            }
            return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
        });
        page.on('dialog', dialog => dialog.accept());
        const adminPage = slug => base + '/wp-admin/admin.php?page=' + slug;
        async function captureLayout(name, widths) {
            for (const width of widths) {
                await page.setViewportSize({ width, height: 900 });
                const overflow = await page.locator('.smartsync-wrap').first().evaluate(element => ({
                    scroll: element.scrollWidth, client: element.clientWidth, right: element.getBoundingClientRect().right, viewport: window.innerWidth,
                    elements: Array.from(element.querySelectorAll('*')).filter(child => child.getBoundingClientRect().right > window.innerWidth + 1).slice(0, 10).map(child => child.id || child.className || child.tagName)
                }));
                await page.screenshot({ path: path.join(output, name + '-' + width + '.png'), fullPage: true });
                assert.ok(overflow.scroll <= overflow.client + 1 && overflow.right <= overflow.viewport + 1, name + ' layout overflows at ' + width + 'px: ' + JSON.stringify(overflow));
            }
        }

        await page.goto(adminPage('smarl-smart-sync'));
        await page.locator('#sync-local-to-aws').waitFor({ state: 'attached' });
        await page.waitForFunction(() => !document.querySelector('#sync-local-to-aws').disabled);
        assert.match(await page.locator('#reconfigure-aws').getAttribute('href'), /page=smarl-configuration-wizard/);
        await page.locator('#tab-button-media').click();
        assert.equal(await page.locator('#tab-media').isVisible(), true);
        await page.locator('#sync-local-to-aws').click();
        await page.locator('.smartsync-progress-container:not([hidden])').waitFor();
        assert.equal(await page.locator('[role="progressbar"]').getAttribute('aria-valuenow'), '25.0');
        assert.ok(await page.locator('.log-timestamp').count());
        await captureLayout('settings', [320, 768, 1280]);
        await page.locator('#tab-button-general').click();
        await page.locator('#fetch-analytics-button').click();
        await page.waitForFunction(() => document.querySelector('#total-price').textContent === 'Unavailable');
        await page.locator('#reconfigure-aws').click();
        await page.waitForLoadState('networkidle');
        await captureLayout('wizard-credentials', [320, 1280]);
        await page.locator('#step-1-next').click();
        try {
            await page.locator('#step-2-next').waitFor({ state: 'visible', timeout: 5000 });
        } catch (_) {
            await page.screenshot({ path: path.join(output, 'wizard-failure.png'), fullPage: true });
            throw new Error('Wizard listing did not advance. Actions: ' + cloudRequests.join(', ') + '. Message: ' + await page.locator('#wizard-message').textContent() + '. Runtime errors: ' + runtimeErrors.join('; '));
        }
        await page.locator('#step-2-next').click();
        await page.locator('#wizard-step-3').waitFor({ state: 'visible' });
        assert.equal(cloudRequests.includes('configure_aws'), false);
        await page.locator('#use-cloudfront-yes').click();
        await page.locator('#wizard-step-final').waitFor({ state: 'visible' });
        await page.screenshot({ path: path.join(output, 'wizard.png'), fullPage: true });
        await page.locator('#complete-setup').click();
        await page.locator('#sync-log-container').waitFor();

        await page.goto(adminPage('custom-cloudfront-url'));
        await page.waitForLoadState('networkidle');
        await page.locator('#custom-domain').fill('cdn.example.test');
        await page.locator('#generate-certificate').click();
        await page.waitForFunction(() => !document.querySelector('#cloudfront-attachment-button').disabled);
        assert.equal(dnsChecks, 1);
        assert.match(await page.locator('#records').textContent(), /_verify.cdn.example.test/);
        await page.locator('#cloudfront-attachment-button').click();
        await page.waitForFunction(() => document.querySelector('#domain-message').textContent.includes('success'));
        await captureLayout('domain', [320, 768, 1280]);
        assert.deepEqual(runtimeErrors, [], 'Browser runtime errors');
        assert.deepEqual(localFailures, [], 'Local HTTP failures');
        console.log('PASS: real WordPress admin rendering, cookie authentication, settings/progress/logs, analytics, responsive widths, wizard navigation/CloudFront choice, and nonce-bearing domain workflow.');
        console.log('Cloud actions were mocked; external resources were blocked. Screenshots: ' + output);
    } finally {
        if (browser) await browser.close();
        original.forEach((value, index) => {
            const key = index ? 'custom_url_option' : 'smarl_settings';
            wp(value === null ? 'delete_option("' + key + '");' : 'update_option("' + key + '", ' + phpValue(value) + ');');
        });
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
