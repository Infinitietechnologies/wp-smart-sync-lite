const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const docs = path.resolve(__dirname, '../../Documentation');
const html = fs.readFileSync(path.join(docs, 'index.html'), 'utf8');

test('standalone documentation resolves every local HTML/CSS resource and named anchor', () => {
    const dom = new JSDOM(html);
    try {
        const document = dom.window.document;
        for (const element of document.querySelectorAll('[src], [href]')) {
            for (const attribute of ['src', 'href']) {
                const value = element.getAttribute(attribute);
                if (!value || /^(?:https?:|\/\/|data:|mailto:)/.test(value)) continue;
                if (value.startsWith('#')) {
                    if (value !== '#') assert.ok(document.getElementById(value.slice(1)), 'Missing anchor: ' + value);
                    continue;
                }
                const local = path.resolve(docs, value.split(/[?#]/)[0]);
                assert.ok(fs.existsSync(local), 'Missing resource: ' + value);
            }
        }
        for (const style of document.querySelectorAll('link[rel="stylesheet"][href^="assets/"]')) {
            const file = path.resolve(docs, style.getAttribute('href'));
            const css = fs.readFileSync(file, 'utf8');
            for (const match of css.matchAll(/url\(([^)]+)\)/g)) {
                const url = match[1].trim().replace(/^['"]|['"]$/g, '');
                if (/^(?:https?:|\/\/|data:|#)/.test(url)) continue;
                assert.ok(fs.existsSync(path.resolve(path.dirname(file), url.split(/[?#]/)[0])), 'Missing CSS resource: ' + url);
            }
        }
        assert.equal(document.querySelectorAll('pre code').length, 0);
        assert.equal(document.querySelectorAll('script[src*="highlight"]').length, 0);
        assert.equal(document.querySelectorAll('link[href*="highlight"]').length, 0);
    } finally { dom.window.close(); }
});

test('documentation navigation handles its root link and mobile/desktop visibility without runtime errors', () => {
    const dom = new JSDOM(html, { url: 'https://example.test/docs/', runScripts: 'outside-only' });
    try {
        const { window } = dom;
        const scrolled = [];
        window.HTMLElement.prototype.scrollIntoView = function () { scrolled.push(this.id); };
        window.matchMedia = () => ({ matches: true });
        window.eval(fs.readFileSync(path.join(docs, 'assets/js/docs.js'), 'utf8'));
        const toggler = window.document.getElementById('docs-sidebar-toggler');
        const sidebar = window.document.getElementById('docs-sidebar');
        assert.equal(toggler.getAttribute('aria-expanded'), 'false');
        assert.equal(sidebar.hasAttribute('inert'), true);
        toggler.click();
        assert.equal(toggler.getAttribute('aria-expanded'), 'true');
        window.document.querySelector('#docs-sidebar .scrollto').click();
        assert.equal(scrolled.at(-1), 'installation');
        assert.equal(window.location.hash, '#installation');
        assert.equal(toggler.getAttribute('aria-expanded'), 'false');
        toggler.click();
        window.document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' }));
        assert.equal(toggler.getAttribute('aria-expanded'), 'false');
        assert.equal(window.document.activeElement, toggler);
        window.innerWidth = 1280;
        window.dispatchEvent(new window.Event('resize'));
        assert.equal(sidebar.hasAttribute('inert'), false);
        assert.equal(toggler.getAttribute('aria-expanded'), 'true');
    } finally { dom.window.close(); }
});
