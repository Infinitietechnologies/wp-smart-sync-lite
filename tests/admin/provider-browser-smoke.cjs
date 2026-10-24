// Native browser -> admin-ajax -> WordPress HTTP fixtures. Never fulfills browser AJAX.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { execFileSync, spawn } = require('node:child_process');
const { chromium } = require('playwright-core');

const args = Object.fromEntries(process.argv.slice(2).map(value => value.replace(/^--/, '').split(/=(.*)/s).slice(0, 2)));
const wordpress = args.wordpress || process.env.SMARTSYNC_WP_ROOT;
const wpCli = args['wp-cli'] || process.env.SMARTSYNC_WP_CLI;
const base = args.url || 'http://127.0.0.1:8097';
const origin = new URL(base);
const output = args.output || path.join(os.tmpdir(), 'smartsync-admin-browser');
const executablePath = args.browser || process.env.SMARTSYNC_BROWSER || [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/chromium', '/usr/bin/google-chrome'
].find(file => fs.existsSync(file));
if (!wordpress || !wpCli || !executablePath || !process.argv.includes('--seed-fixture')
    || !wordpress.replace(/\\/g, '/').replace(/\/?$/, '/').includes('/smartsync-wordpress-audit-fixture/wordpress/')
    || !['127.0.0.1', 'localhost', '[::1]'].includes(origin.hostname)) {
    throw new Error('Use a disposable smartsync-wordpress-audit-fixture/wordpress site, loopback URL, --wordpress, --wp-cli, and --seed-fixture.');
}
const fixtureFile = path.resolve(__dirname, '../integration/bunny-http-fixture.php');
const wrapperFile = path.join(wordpress, 'wp-content/mu-plugins/zz-smartsync-bunny-browser-fixture.php');
const wrapper = '<?php\n// Owned by provider-browser-smoke.cjs; contains only synthetic fixture credentials.\ndefine("SMARTSYNC_BUNNY_HTTP_FIXTURE", true);\nrequire base64_decode("' + Buffer.from(fixtureFile).toString('base64') + '");\n';
const optionNames = ['smarl_settings', 'custom_url_option', 'smarl_sync_settings', 'smarl_sync_lock', 'smarl_queue_generation',
    '_transient_smarl_google_credentials_1', '_transient_timeout_smarl_google_credentials_1',
    '_transient_smarl_bunny_zones_1', '_transient_timeout_smarl_bunny_zones_1', 'smarl_bunny_browser_http_calls'];
const phpValue = value => 'json_decode(base64_decode("' + Buffer.from(JSON.stringify(value)).toString('base64') + '"),true)';
function wp(code) {
    return execFileSync('php', [wpCli, '--path=' + wordpress, '--skip-plugins', '--skip-themes', 'eval', code], { encoding: 'utf8', maxBuffer: 1024 * 1024 });
}
function listening(port) {
    return new Promise(resolve => {
        const socket = net.connect({ port, host: '127.0.0.1' });
        socket.once('connect', () => { socket.destroy(); resolve(true); });
        socket.once('error', () => resolve(false));
    });
}
function actionOf(request) {
    const body = request.postData() || '';
    const multipart = body.match(/name="action"[\r\n]+([^\r\n]+)/);
    return multipart ? multipart[1] : new URLSearchParams(body).get('action');
}
async function stopChild(child) {
    if (!child || child.exitCode !== null) return;
    await new Promise(resolve => {
        const timer = setTimeout(() => { child.kill('SIGKILL'); }, 5000);
        child.once('exit', () => { clearTimeout(timer); resolve(); });
        child.kill();
    });
}

(async function () {
    let browser;
    let phpServer;
    let snapshot;
    let optionsSeeded = false;
    let wrapperCreated = false;
    try {
        if (!fs.existsSync(path.join(wordpress, 'wp-content/mu-plugins/smartsync-audit-isolation.php'))) throw new Error('The fixture-wide HTTP isolation MU plugin is required.');
        const snapshotCode = '$names=' + phpValue(optionNames) + '; global $wpdb; $rows=[]; foreach($names as $name){$row=$wpdb->get_row($wpdb->prepare("SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name=%s",$name),ARRAY_A); if($row)$rows[]=$row;} echo wp_json_encode($rows);';
        snapshot = JSON.parse(wp(snapshotCode));
        const pending = Number(wp('global $wpdb; echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook=\'process_s3_sync_batch\' AND status IN (\'pending\',\'in-progress\')");'));
        if (pending) throw new Error('The fixture has existing SmartSync actions; finish them before this connection-only smoke.');
        fs.writeFileSync(wrapperFile, wrapper, { flag: 'wx' });
        wrapperCreated = true;
        optionsSeeded = true;
        wp('delete_option("smarl_settings"); update_option("custom_url_option",[]); update_option("smarl_sync_settings",["s3SyncStatus"=>"idle"]); delete_option("smarl_sync_lock"); delete_transient("smarl_google_credentials_1"); delete_transient("smarl_bunny_zones_1"); update_option("smarl_bunny_browser_http_calls",[],false);');
        const port = Number(origin.port || 80);
        if (!await listening(port)) {
            phpServer = spawn('php', ['-S', '127.0.0.1:' + port, '-t', wordpress], { cwd: wordpress, windowsHide: true, stdio: 'ignore' });
            console.log('Started owned PHP fixture server PID ' + phpServer.pid + ' on ' + port);
            for (let attempt = 0; !await listening(port); attempt++) {
                if (attempt > 50 || phpServer.exitCode !== null) throw new Error('Could not start the disposable PHP fixture server.');
                await new Promise(resolve => setTimeout(resolve, 100));
            }
        }
        const cookies = JSON.parse(wp('$expires=time()+900; echo wp_json_encode([["name"=>AUTH_COOKIE,"value"=>wp_generate_auth_cookie(1,$expires,"auth")],["name"=>LOGGED_IN_COOKIE,"value"=>wp_generate_auth_cookie(1,$expires,"logged_in")]]);'));
        browser = await chromium.launch({ executablePath, headless: true });
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
        await context.addCookies(cookies.map(cookie => ({ ...cookie, url: base, httpOnly: true, sameSite: 'Lax' })));
        // This route only blocks third-party browser resources. All local AJAX reaches PHP unchanged.
        await context.route('**/*', route => new URL(route.request().url()).origin === origin.origin ? route.continue() : route.abort());
        const page = await context.newPage();
        const runtimeErrors = [];
        const observedAjax = [];
        const localFailures = [];
        page.on('pageerror', error => runtimeErrors.push(error.message));
        page.on('response', response => {
            if (!response.url().startsWith(origin.origin)) return;
            const action = response.url().includes('/admin-ajax.php') ? actionOf(response.request()) : null;
            if (action) observedAjax.push({ action, status: response.status() });
            if (response.status() >= 400) localFailures.push({ action, status: response.status(), pathname: new URL(response.url()).pathname });
        });
        const nativeResponse = action => page.waitForResponse(response => response.url().includes('/admin-ajax.php') && actionOf(response.request()) === action);
        await page.goto(base + '/wp-admin/admin.php?page=smarl-configuration-wizard', { waitUntil: 'networkidle' });
        await page.locator('#service_provider').selectOption('bunny');
        await page.locator('#bunny-api-key').fill('fixture-wrong-account-key');
        let responsePromise = nativeResponse('list_s3_buckets');
        await page.locator('#step-1-next').click();
        const wrong = await responsePromise;
        assert.equal(wrong.status(), 400);
        const wrongJson = await wrong.json();
        assert.equal(wrongJson.success, false);
        assert.match(String(wrongJson.data), /account API key/i);
        assert.ok(!JSON.stringify(wrongJson).includes('fixture-wrong-account-key'));
        await page.waitForFunction(() => !document.querySelector('#step-1-next').disabled);
        assert.equal(await page.locator('#wizard-step-1').isVisible(), true);

        await page.locator('#bunny-api-key').fill('fixture-bunny-account-key');
        responsePromise = nativeResponse('list_s3_buckets');
        await page.locator('#step-1-next').click();
        const listing = await responsePromise;
        assert.equal(listing.status(), 200);
        const listingJson = await listing.json();
        assert.equal(listingJson.data.buckets.length, 2);
        assert.ok(!JSON.stringify(listingJson).includes('fixture-bunny-storage-password'));
        await page.locator('#wizard-step-2').waitFor({ state: 'visible' });
        const hosts = await page.locator('#pull-zones option').evaluateAll(options => options.map(option => option.value));
        assert.deepEqual(hosts, ['fixture-browser-zone.b-cdn.net', 'ready.example.test']);
        assert.equal(await page.locator('#pull-zones').inputValue(), 'fixture-browser-zone.b-cdn.net');
        await page.locator('#aws_bucket_list').selectOption('1');
        await page.waitForFunction(() => !document.querySelector('#bunny-pull-zone-help').hidden);
        assert.match(await page.locator('#bunny-pull-zone-help').textContent(), /active public pull zone/);
        await page.locator('#aws_bucket_list').selectOption('0');
        fs.mkdirSync(output, { recursive: true });
        for (const width of [320, 1280]) {
            await page.setViewportSize({ width, height: 900 });
            const overflow = await page.locator('.smartsync-wrap').evaluate(element => element.scrollWidth - element.clientWidth);
            assert.ok(overflow <= 1, 'Native Bunny connection form overflows at ' + width + 'px.');
            await page.screenshot({ path: path.join(output, 'bunny-native-' + width + '.png'), fullPage: true });
        }
        responsePromise = nativeResponse('configure_aws');
        await page.locator('#step-2-next').click();
        const savedResponse = await responsePromise;
        assert.equal(savedResponse.status(), 200);
        assert.equal((await savedResponse.json()).success, true);
        await page.locator('#wizard-step-final').waitFor({ state: 'visible' });
        const saved = JSON.parse(wp('echo wp_json_encode(get_option("smarl_settings"));'));
        assert.equal(saved.service_provider, 'bunny');
        assert.equal(saved.bucket, 'fixture-browser-zone');
        assert.equal(saved.iam_key, 'fixture-browser-zone.b-cdn.net');
        assert.equal(saved.bunnyStorageEndpoint, 'https://la.storage.bunnycdn.com');
        assert.equal(saved.bunnyStoragePassword, 'fixture-bunny-storage-password');
        assert.equal(saved.url, 'fixture-browser-zone.b-cdn.net');
        await page.locator('#complete-setup').click();
        await page.waitForLoadState('networkidle');
        assert.match(await page.locator('.settings-table').textContent(), /BunnyCDN/);
        assert.match(await page.locator('.settings-table').textContent(), /fixture-browser-zone/);
        responsePromise = nativeResponse('fetch_aws_analytics');
        await page.locator('#fetch-analytics-button').click();
        const analyticsResponse = await responsePromise;
        assert.equal(analyticsResponse.status(), 200);
        const analytics = await analyticsResponse.json();
        assert.equal(analytics.success, true);
        assert.equal(analytics.data.total_files, 2);
        assert.equal(analytics.data.total_size, 17);
        responsePromise = nativeResponse('toggle_media_auto_delete');
        await page.locator('#tab-button-media').click();
        await page.locator('.switch label[for="auto-delete-media"]').click();
        const toggleResponse = await responsePromise;
        assert.equal(toggleResponse.status(), 200);
        assert.equal((await toggleResponse.json()).success, true);
        assert.equal(JSON.parse(wp('echo wp_json_encode(get_option("smarl_settings"));')).mediaAutoDelete, true);
        const calls = JSON.parse(wp('echo wp_json_encode(get_option("smarl_bunny_browser_http_calls",[]));'));
        assert.ok(calls.some(call => call.path === '/storagezone' && call.query.includes('page=1')));
        assert.ok(calls.some(call => call.path === '/storagezone' && call.query.includes('page=2')));
        assert.ok(calls.some(call => call.path === '/storagezone/701'));
        assert.ok(calls.some(call => call.host === 'la.storage.bunnycdn.com' && call.path === '/fixture-browser-zone/' && call.credential === 'storage-valid'));
        assert.ok(calls.some(call => call.path === '/fixture-browser-zone/assets/'));
        assert.deepEqual(runtimeErrors, []);
        assert.deepEqual(localFailures, [{ action: 'list_s3_buckets', status: 400, pathname: '/wp-admin/admin-ajax.php' }]);
        console.log('PASS: native WordPress AJAX rejected/retried wrong account key, listed paginated Bunny zones/details, filtered host choices, saved/probed provider-reported storage endpoint, and completed analytics/toggle requests.');
        console.log('Native AJAX: ' + observedAjax.map(item => item.action + ':' + item.status).join(', ') + '. Screenshots: ' + output);
    } finally {
        if (browser) await browser.close();
        await stopChild(phpServer);
        if (snapshot && optionsSeeded) {
            wp('$names=' + phpValue(optionNames) + '; $rows=' + phpValue(snapshot) + '; global $wpdb; foreach($names as $name){$wpdb->delete($wpdb->options,["option_name"=>$name]);wp_cache_delete($name,"options");} foreach($rows as $row){$wpdb->insert($wpdb->options,$row);} wp_cache_delete("alloptions","options");wp_cache_delete("notoptions","options");');
        }
        if (wrapperCreated && fs.readFileSync(wrapperFile, 'utf8') === wrapper) fs.unlinkSync(wrapperFile);
        if (phpServer) console.log('Stopped owned PHP fixture server PID ' + phpServer.pid + '; restored options and removed owned MU wrapper.');
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
