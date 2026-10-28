const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');
const { chromium } = require('playwright-core');
const { render } = require('./harness.cjs');
const root = path.resolve(__dirname, '../..');
const executablePath = [process.env.SMARTSYNC_BROWSER, 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe', '/usr/bin/chromium'].find(file => file && fs.existsSync(file));
const waitFor = async predicate => {
    const deadline = Date.now() + 5000;
    while (!predicate()) {
        if (Date.now() >= deadline) throw new Error('Fixture startup timed out');
        await new Promise(resolve => setTimeout(resolve, 20));
    }
};
(async () => {
    const socket = net.createServer();
    await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
    const port = socket.address().port;
    await new Promise(resolve => socket.close(resolve));
    const environment = { ...process.env }; delete environment.PHP_CLI_SERVER_WORKERS;
    const server = spawn('php', ['-S', '127.0.0.1:' + port, path.join(root, 'tests/fixtures/status-delay.php')], { cwd: root, env: environment, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    let log = ''; server.stderr.on('data', data => { log = (log + data).slice(-10000); });
    let browser;
    try {
        await waitFor(() => log.includes('Development Server'));
        browser = await chromium.launch({ executablePath, headless: true });
        const page = await browser.newPage();
        const origin = 'http://127.0.0.1:' + port;
        await page.route('**/*', route => {
            const url = new URL(route.request().url());
            if (url.origin !== origin) return route.abort();
            if (url.pathname === '/fixture-page') return route.fulfill({ contentType: 'text/html', body: render('settings') });
            return route.continue();
        });
        await page.goto(origin + '/fixture-page');
        await page.addScriptTag({ path: require.resolve('jquery') });
        await page.evaluate(() => { window.ajaxObject = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'fixture', status_timeout_ms: 180000, settings_strings: {} }; });
        const first = page.waitForResponse(response => response.url().endsWith('admin-ajax.php'));
        await page.addScriptTag({ path: path.join(root, 'assets/js/settings.js') });
        await first;
        await page.waitForFunction(() => document.querySelector('#settings-activity-status').dataset.state === 'processing');
        await page.evaluate(() => { window.fixtureHold = fetch('/hold'); });
        await waitFor(() => log.includes('SMARTSYNC_STATUS_HOLD_STARTED'));
        const started = Date.now();
        const response = page.waitForResponse(item => item.url().endsWith('admin-ajax.php'), { timeout: 30000 });
        await page.evaluate(() => window.jQuery(document).trigger('smartsync:refresh-status'));
        assert.equal((await response).status(), 200);
        assert.ok(Date.now() - started >= 15000, 'Status must have waited beyond the old timeout');
        assert.equal(await page.locator('#sync-log-container p.error').count(), 0);
        assert.equal(await page.locator('#settings-activity-status').getAttribute('data-state'), 'processing');
        console.log('Native PHP single-request server: status waited behind a 17-second operation and recovered without cancellation or losing progress.');
    } finally {
        if (browser) await browser.close();
        server.kill();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
