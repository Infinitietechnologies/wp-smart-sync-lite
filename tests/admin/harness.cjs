const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');
const { JSDOM } = require('jsdom');
const createJQuery = require('jquery');
const root = path.resolve(__dirname, '../..');

function render(view, data = {}) {
    return execFileSync('php', [path.join(__dirname, 'render-view.php'), view, Buffer.from(JSON.stringify(data)).toString('base64')], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

async function boot(view, { data = {}, scripts, translations = {}, baseStyles = '', widget = {}, storage = {}, confirmations = 'auto', statusTimeout = 15000 } = {}) {
    const dom = new JSDOM(render(view, data), {
        url: 'https://example.test/wp-admin/admin.php?page=' + ({ settings: 'smarl-smart-sync', wizard: 'smarl-configuration-wizard', domain: 'custom-cloudfront-url' })[view],
        runScripts: 'outside-only', pretendToBeVisual: true
    });
    const { window } = dom;
    for (const [kind, values] of Object.entries(storage)) for (const [key, value] of Object.entries(values)) window[kind].setItem(key, value);
    window.matchMedia = () => ({ matches: true, addEventListener() {}, removeEventListener() {} });
    window.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
    window.HTMLDialogElement.prototype.close = function () { this.open = false; };
    if (baseStyles) {
        const style = window.document.createElement('style');
        style.textContent = baseStyles;
        window.document.head.append(style);
    }
    for (const file of ['wizard.css', 'ui-improvements.css', 'enhanced-ui.css', 'settings-home.css', 'cloud-setup.css', 'sync-widget.css', 'notifications.css', 'confirmations.css', 'pro-preview.css']) {
        const style = window.document.createElement('style');
        style.textContent = fs.readFileSync(path.join(root, 'assets/css', file), 'utf8');
        window.document.head.append(style);
    }
    const $ = createJQuery(window);
    window.$ = window.jQuery = $;
    const base = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'fixture-nonce', status_timeout_ms: statusTimeout };
    window.ajaxObject = { ...base, wizard_url: '/wp-admin/admin.php?page=smarl-configuration-wizard', settings_strings: translations, widget: { scope: 'fixture-site', providers: { bunny: 'Bunny CDN', aws: 'Amazon S3', google: 'Google Cloud Storage' }, ...widget } };
    window.ajax_object = { ...base, settings_url: '/wp-admin/admin.php?page=smarl-smart-sync', strings: translations };
    const requests = [];
    $.ajax = options => {
        const deferred = $.Deferred();
        const xhr = deferred.promise();
        xhr.abort = () => deferred.reject(xhr, 'abort');
        requests.push({
            options, deferred,
            action: options.data instanceof window.FormData ? options.data.get('action') : options.data.action,
            resolve(data = {}) { deferred.resolve({ success: true, data }); },
            reject(data = 'Request failed') { deferred.resolve({ success: false, data }); },
            fail(data = 'Network failure') { deferred.reject({ responseJSON: { success: false, data } }); }
        });
        return xhr;
    };
    const alerts = [];
    let confirmed = true;
    window.confirm = () => confirmed;
    window.alert = message => alerts.push(message);
    const timers = new Map();
    const pollingTimers = new Map();
    const nativeSetTimeout = window.setTimeout.bind(window);
    const nativeClearTimeout = window.clearTimeout.bind(window);
    let timerId = 100000;
    window.setTimeout = (callback, delay, ...args) => {
        if (view === 'domain' && widget === false && callback.name === 'checkSetupSync') return 0;
        if (delay >= 1000) {
            const id = ++timerId;
            timers.set(id, () => callback(...args));
            if (['checkSyncStatus', 'checkSetupSync', 'poll'].includes(callback.name)) pollingTimers.set(id, timers.get(id));
            return id;
        }
        return nativeSetTimeout(callback, delay, ...args);
    };
    window.clearTimeout = id => {
        pollingTimers.delete(id);
        if (!timers.delete(id)) nativeClearTimeout(id);
    };
    const defaultScripts = ['notifications.js', 'confirmations.js', ...(view === 'settings' ? ['settings.js', 'ui-improvements.js', 'enhanced-logs.js'] : ['wizard.js', 'ui-improvements.js']), ...(widget === false ? [] : ['sync-widget.js'])];
    for (const file of scripts || defaultScripts) {
        const source = path.join(root, 'assets/js', file);
        window.eval(fs.readFileSync(source, 'utf8') + '\n//# sourceURL=' + pathToFileURL(source).href);
    }
    if (confirmations !== 'manual') window.smartsyncConfirm = (options, approve) => { window.document.dispatchEvent(new window.CustomEvent('smartsync:confirmation-open', { detail: options })); if (confirmed) approve(); return true; };
    window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
    window.dispatchEvent(new window.Event('load'));
    await new Promise(resolve => setTimeout(resolve, 20));
    return {
        window, $, dom, requests, timers, pollingTimers, alerts,
        confirm(value) { confirmed = value; },
        refreshStatus() { $(window.document).trigger('smartsync:refresh-status'); },
        latest(action) { return requests.filter(request => request.action === action).at(-1); },
        runTimer() {
            const [id, callback] = (pollingTimers.size ? pollingTimers : timers).entries().next().value || [];
            if (callback) { timers.delete(id); pollingTimers.delete(id); callback(); }
        },
        close() { window.close(); }
    };
}

function googleKey(overrides = {}) {
    return JSON.stringify({ type: 'service_account', project_id: 'example-project', private_key_id: 'fixture-key-id', private_key: '-----BEGIN PRIVATE KEY-----\nfixture-private-value\n-----END PRIVATE KEY-----', client_email: 'media@example-project.iam.gserviceaccount.com', client_id: 'fixture-client-id', token_uri: 'https://oauth2.googleapis.com/token', ...overrides });
}
async function chooseGoogleFile(app, contents = googleKey(), name = 'service-account.json') {
    const input = app.$('#service-json')[0];
    Object.defineProperty(input, 'files', { value: [new app.window.File([contents], name, { type: name.endsWith('.json') ? 'application/json' : 'application/x-pkcs12' })], configurable: true });
    app.$(input).trigger('change');
    await new Promise(resolve => setTimeout(resolve, 30));
}
module.exports = { boot, render, googleKey, chooseGoogleFile };
