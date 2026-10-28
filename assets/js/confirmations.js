(function () {
    'use strict';
    let active = null;
    let sequence = 0;
    const fallback = {
        upload_media: ['Upload media?', 'Upload media'], upload_assets: ['Upload assets?', 'Upload assets'],
        full_media: ['Resync all media?', 'Full resync'], full_assets: ['Resync all assets?', 'Full resync'],
        restore: ['Restore local media?', 'Restore files'], stop: ['Stop this sync?', 'Stop sync'],
        cache: ['Refresh CloudFront cache?', 'Clear cache']
    };
    function element(tag, className, text) {
        const node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }
    window.smartsyncConfirm = function (options, approve) {
        if (active) return false;
        const strings = window.smarlDialogs || {};
        const preset = (strings.presets || {})[options.kind] || {};
        const defaults = fallback[options.kind] || ['Continue?', 'Continue'];
        const trigger = document.activeElement;
        const dialog = element('dialog', 'smartsync-confirm-dialog');
        const id = 'smartsync-confirm-' + (++sequence);
        dialog.setAttribute('aria-labelledby', id + '-title');
        dialog.setAttribute('aria-describedby', id + '-message');
        dialog.dataset.tone = options.kind === 'stop' ? 'danger' : /^(full_|restore)/.test(options.kind) ? 'warning' : 'info';
        const body = element('div', 'smartsync-confirm-body');
        const icon = element('span', 'smartsync-confirm-icon');
        icon.setAttribute('aria-hidden', 'true');
        const path = options.kind === 'stop' ? 'M7 7h10v10H7z' : options.kind === 'restore' ? 'M12 4v12M7 11l5 5 5-5M4 17v3h16v-3' : options.kind === 'cache' ? 'M20 4v5h-5M4 20v-5h5M5.6 8a7 7 0 0 1 11.35-2.95L20 9M4 15l3.05 3.95A7 7 0 0 0 18.4 16' : 'M12 16V4M7 9l5-5 5 5M4 16v4h16v-4';
        // Only this constant SVG markup is interpreted as HTML; all supplied text uses textContent.
        icon.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="' + path + '"/></svg>';
        const heading = element('h2', 'smartsync-confirm-title', preset.title || defaults[0]);
        heading.id = id + '-title';
        const message = element('p', 'smartsync-confirm-message', options.message);
        message.id = id + '-message';
        body.append(icon, heading, message);
        if (options.context) {
            const context = element('div', 'smartsync-confirm-context');
            context.id = id + '-context';
            context.append(element('span', '', strings.destination || 'Destination'), element('strong', '', options.context));
            body.append(context);
            dialog.setAttribute('aria-describedby', message.id + ' ' + context.id);
        }
        const actions = element('div', 'smartsync-confirm-actions');
        const cancel = element('button', 'smartsync-confirm-cancel', options.kind === 'stop' ? strings.keep_syncing || 'Keep syncing' : strings.cancel || 'Cancel');
        const accept = element('button', 'smartsync-confirm-accept', preset.action || defaults[1]);
        cancel.type = accept.type = 'button';
        actions.append(cancel, accept);
        dialog.append(body, actions);
        document.body.append(dialog);
        if (typeof dialog.showModal !== 'function') {
            dialog.remove();
            if (window.jQuery) window.jQuery(document).trigger('smartsync:notice', [{ type: 'error', message: strings.unavailable || 'The confirmation could not open. Refresh the page and try again.' }]);
            return false;
        }
        let closing = false;
        let closeTimer;
        const reduced = !window.matchMedia || window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        function dispose(accepted, restoreFocus) {
            window.clearTimeout(closeTimer);
            dialog.close();
            dialog.remove();
            document.documentElement.classList.remove('smartsync-confirm-open', 'smartsync-confirm-gutter');
            active = null;
            if (restoreFocus && trigger && trigger.isConnected && !trigger.disabled) trigger.focus({ preventScroll: true });
            if (accepted) approve();
        }
        function finish(accepted) {
            if (closing) return;
            closing = true;
            dialog.classList.remove('is-open');
            const close = function () { dispose(accepted, true); };
            if (reduced) close();
            else closeTimer = window.setTimeout(close, 180);
        }
        active = { cancel: function () { closing = true; dispose(false, false); } };
        cancel.addEventListener('click', function () { finish(false); });
        accept.addEventListener('click', function () { finish(true); });
        dialog.addEventListener('cancel', function (event) { event.preventDefault(); finish(false); });
        dialog.addEventListener('click', function (event) {
            const rect = dialog.getBoundingClientRect();
            if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) finish(false);
        });
        dialog.addEventListener('keydown', function (event) {
            if (event.key !== 'Tab') return;
            if (event.shiftKey && document.activeElement === cancel) { event.preventDefault(); accept.focus(); }
            else if (!event.shiftKey && document.activeElement === accept) { event.preventDefault(); cancel.focus(); }
        });
        dialog.showModal();
        document.documentElement.classList.toggle('smartsync-confirm-gutter', window.innerWidth > document.documentElement.clientWidth);
        document.documentElement.classList.add('smartsync-confirm-open');
        void dialog.offsetWidth;
        dialog.classList.add('is-open');
        cancel.focus();
        document.dispatchEvent(new CustomEvent('smartsync:confirmation-open', { detail: { kind: options.kind, message: options.message } }));
        return true;
    };
    window.addEventListener('pagehide', function () { if (active) active.cancel(); });
})();
