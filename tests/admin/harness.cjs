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

async function boot(view, { data = {}, scripts, translations = {}, baseStyles = '' } = {}) {
    const dom = new JSDOM(render(view, data), {
        url: 'https://example.test/wp-admin/admin.php?page=' + ({ settings: 'smarl-smart-sync', wizard: 'smarl-configuration-wizard', domain: 'custom-cloudfront-url' })[view],
        runScripts: 'outside-only', pretendToBeVisual: true
    });
    const { window } = dom;
    if (baseStyles) {
        const style = window.document.createElement('style');
        style.textContent = baseStyles;
        window.document.head.append(style);
    }
    for (const file of ['wizard.css', 'ui-improvements.css', 'enhanced-ui.css']) {
        const style = window.document.createElement('style');
        style.textContent = fs.readFileSync(path.join(root, 'assets/css', file), 'utf8');
        window.document.head.append(style);
    }
    const $ = createJQuery(window);
    window.$ = window.jQuery = $;
    const base = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'fixture-nonce' };
    window.ajaxObject = { ...base, wizard_url: '/wp-admin/admin.php?page=smarl-configuration-wizard', settings_strings: translations };
    window.ajax_object = { ...base, settings_url: '/wp-admin/admin.php?page=smarl-smart-sync', strings: translations };
    const requests = [];
    $.ajax = options => {
        const deferred = $.Deferred();
        const xhr = deferred.promise();
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
    const nativeSetTimeout = window.setTimeout.bind(window);
    const nativeClearTimeout = window.clearTimeout.bind(window);
    let timerId = 100000;
    window.setTimeout = (callback, delay, ...args) => {
        if (delay >= 1000) {
            timers.set(++timerId, () => callback(...args));
            return timerId;
        }
        return nativeSetTimeout(callback, delay, ...args);
    };
    window.clearTimeout = id => {
        if (!timers.delete(id)) nativeClearTimeout(id);
    };
    const defaultScripts = view === 'settings' ? ['settings.js', 'ui-improvements.js', 'enhanced-logs.js'] : ['wizard.js', 'ui-improvements.js'];
    for (const file of scripts || defaultScripts) {
        const source = path.join(root, 'assets/js', file);
        window.eval(fs.readFileSync(source, 'utf8') + '\n//# sourceURL=' + pathToFileURL(source).href);
    }
    window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
    window.dispatchEvent(new window.Event('load'));
    await new Promise(resolve => setTimeout(resolve, 20));
    return {
        window, $, dom, requests, timers, alerts,
        confirm(value) { confirmed = value; },
        latest(action) { return requests.filter(request => request.action === action).at(-1); },
        runTimer() {
            const [id, callback] = timers.entries().next().value || [];
            if (callback) { timers.delete(id); callback(); }
        },
        close() { window.close(); }
    };
}

module.exports = { boot, render };
