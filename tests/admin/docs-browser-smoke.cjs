const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const os = require('node:os');
const { chromium } = require('playwright-core');
const docs = path.resolve(__dirname, '../../Documentation');
const executablePath = process.env.SMARTSYNC_BROWSER || [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    '/usr/bin/chromium', '/usr/bin/google-chrome'
].find(file => fs.existsSync(file));
const contentTypes = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp', '.ico': 'image/x-icon' };
const server = http.createServer((request, response) => {
    let file;
    try { file = path.resolve(docs, '.' + decodeURIComponent(new URL(request.url, 'http://localhost').pathname)); }
    catch (_) { response.writeHead(400).end(); return; }
    if (file === docs) file = path.join(docs, 'index.html');
    if (!file.startsWith(docs + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
        response.writeHead(404).end(); return;
    }
    response.writeHead(200, { 'content-type': contentTypes[path.extname(file)] || 'application/octet-stream' });
    fs.createReadStream(file).pipe(response);
});
(async () => {
    let browser;
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        const base = 'http://127.0.0.1:' + server.address().port;
        browser = await chromium.launch({ executablePath, headless: true });
        const context = await browser.newContext({ reducedMotion: 'reduce', viewport: { width: 1280, height: 900 } });
        await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
        const page = await context.newPage();
        const errors = [];
        const localFailures = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('response', response => {
            if (response.url().startsWith(base) && response.status() >= 400) localFailures.push(response.status() + ' ' + new URL(response.url()).pathname);
        });
        await page.goto(base + '/', { waitUntil: 'networkidle' });
        assert.equal(await page.locator('#docs-sidebar').getAttribute('aria-hidden'), 'false');
        await page.setViewportSize({ width: 375, height: 900 });
        await page.waitForFunction(() => document.querySelector('#docs-sidebar').getAttribute('aria-hidden') === 'true');
        await page.locator('#docs-sidebar-toggler').click();
        await page.locator('#docs-sidebar .scrollto').first().click();
        assert.equal(await page.locator('#docs-sidebar').getAttribute('aria-hidden'), 'true');
        assert.match(page.url(), /#installation$/);
        await page.locator('#docs-sidebar-toggler').click();
        await page.locator('a.scrollto[href="#custom-url"]').click();
        await page.waitForFunction(() => document.querySelector('#custom-url').getBoundingClientRect().top < 150);
        const output = path.join(os.tmpdir(), 'smartsync-admin-browser');
        fs.mkdirSync(output, { recursive: true });
        await page.screenshot({ path: path.join(output, 'documentation-375.png') });
        assert.equal(await page.locator('img').evaluateAll(images => images.filter(image => !image.complete || !image.naturalWidth).length), 0);
        assert.deepEqual(errors, []);
        assert.deepEqual(localFailures, []);
        console.log('PASS: standalone docs local resources, all images, mobile sidebar/root/section navigation; no browser runtime or local HTTP errors. External fonts/video blocked. Screenshot: ' + output);
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
