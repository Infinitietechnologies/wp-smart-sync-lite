jQuery(function ($) {
    'use strict';
    if (!$('.smartsync-wrap').length) return;
    const config = window.smarlNotices || {};
    const duration = Number.isFinite(config.duration) ? Math.max(0, config.duration) : 5000;
    const reducedMotion = () => !window.matchMedia || window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const entries = new Map();
    const region = $('<div>').addClass('smartsync-notifications').attr({ role: 'region', 'aria-label': config.label || 'Notifications' });
    $('body').append(region);
    const paths = {
        error: 'M12 8v5M12 16h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
        warning: 'M12 8v5M12 16h.01M10.3 3.6 2.1 18a2 2 0 0 0 1.7 3h16.4a2 2 0 0 0 1.7-3L13.7 3.6a2 2 0 0 0-3.4 0Z',
        info: 'M12 11v6M12 7h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
        success: 'm7 12 3 3 7-7M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0'
    };
    function schedule(entry) {
        window.clearTimeout(entry.timer);
        if (entry.closing) return;
        if (!duration || entry.paused || entry.item[0].contains(document.activeElement)) return;
        entry.startedAt = Date.now();
        entry.progress.css({ transition: 'none', transform: 'scaleX(' + entry.remaining / duration + ')' });
        void entry.progress[0].offsetWidth;
        entry.progress.css({ transition: 'transform ' + entry.remaining + 'ms linear', transform: 'scaleX(0)' });
        entry.timer = window.setTimeout(function () { dismiss(entry); }, entry.remaining);
    }
    function pause(entry) {
        window.clearTimeout(entry.timer);
        if (entry.startedAt) entry.remaining = Math.max(0, entry.remaining - (Date.now() - entry.startedAt));
        entry.startedAt = 0;
        entry.progress.css({ transition: 'none', transform: 'scaleX(' + (duration ? entry.remaining / duration : 1) + ')' });
    }
    function dismiss(entry) {
        if (entry.closing) return;
        entry.closing = true;
        window.clearTimeout(entry.timer);
        entries.delete(entry.key);
        if (entry.item[0].contains(document.activeElement)) {
            if (entry.origin && entry.origin.isConnected && !entry.origin.disabled) entry.origin.focus({ preventScroll: true });
            else document.activeElement.blur();
        }
        entry.item.attr('aria-hidden', 'true').prop('inert', true).addClass('is-leaving');
        function remove() { window.clearTimeout(entry.exitTimer); entry.item.remove(); }
        if (reducedMotion()) remove();
        else {
            entry.item.on('transitionend', function (event) {
                if (event.target === entry.item[0] && event.originalEvent.propertyName === 'opacity' && Number(window.getComputedStyle(entry.item[0]).opacity) < .01) remove();
            });
            entry.exitTimer = window.setTimeout(remove, 240);
        }
    }
    function show(input) {
        if (!input || typeof input.message !== 'string' || !input.message.trim()) return;
        const type = ['error', 'warning', 'info', 'success'].includes(input.type) ? input.type : 'info';
        const key = String(input.key || type + '|' + input.message);
        const duplicate = entries.get(key) || Array.from(entries.values()).find(entry => entry.type === type && entry.message === input.message);
        if (duplicate) {
            if (duplicate.type === type && duplicate.message === input.message) return;
            duplicate.type = type; duplicate.message = input.message;
            duplicate.item.attr('data-type', type);
            duplicate.path.attr('d', paths[type]);
            duplicate.text.text(input.message).attr('role', ['error', 'warning'].includes(type) ? 'alert' : 'status');
            duplicate.remaining = duration;
            schedule(duplicate); return;
        }
        if (['error', 'warning'].includes(type)) {
            Array.from(entries.values()).filter(entry => !['error', 'warning'].includes(entry.type)).forEach(dismiss);
        }
        if (entries.size >= 3) {
            const replaceable = Array.from(entries.values()).find(entry => !['error', 'warning'].includes(entry.type));
            if (!replaceable && !['error', 'warning'].includes(type)) return;
            dismiss(replaceable || entries.values().next().value);
        }
        const item = $('<div>').addClass('smartsync-notice-item').attr('data-type', type);
        const clip = $('<div>').addClass('smartsync-notice-clip');
        const card = $('<div>').addClass('smartsync-notice-card');
        const svg = $(document.createElementNS('http://www.w3.org/2000/svg', 'svg')).attr({ viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '1.7', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true', focusable: 'false' });
        const path = $(document.createElementNS('http://www.w3.org/2000/svg', 'path')).attr('d', paths[type]); svg.append(path);
        const message = $('<div>').addClass('smartsync-notice-message').attr({ role: ['error', 'warning'].includes(type) ? 'alert' : 'status', 'aria-atomic': 'true' }).text(input.message);
        const close = $('<button>').attr({ type: 'button', 'aria-label': config.dismiss || 'Dismiss notification' }).addClass('smartsync-notice-dismiss').append($('<span>').attr('aria-hidden', 'true').text('×'));
        card.append($('<span>').addClass('smartsync-notice-icon').append(svg), message, close);
        const progress = $('<span>').addClass('smartsync-notice-countdown').attr('aria-hidden', 'true').prop('hidden', !duration);
        card.append(progress);
        item.append(clip.append(card));
        const entry = { key, type, message: input.message, item, path, progress, remaining: duration, startedAt: 0, text: message, origin: document.activeElement, paused: false, closing: false };
        entries.set(key, entry);
        close.on('click', function () { dismiss(entry); });
        item.on('mouseenter focusin', function (event) {
            entry.paused = true; pause(entry);
            if (event.type === 'focusin' && event.relatedTarget && !region[0].contains(event.relatedTarget)) entry.origin = event.relatedTarget;
        });
        item.on('mouseleave focusout', function (event) {
            entry.paused = entry.item.is(':hover');
            if (event.type === 'focusout') window.setTimeout(function () { schedule(entry); }, 0);
            else schedule(entry);
        });
        if (!reducedMotion()) item.addClass('is-entering');
        if (['error', 'warning'].includes(type)) region.prepend(item);
        else region.append(item);
        if (!reducedMotion()) { void item[0].offsetHeight; item.removeClass('is-entering'); }
        schedule(entry);
    }
    $(document).on('smartsync:notice', function (_, input) { show(input); });
    $(document).on('smartsync:log', function (_, entry) {
        if (entry && entry.notice) show($.extend({}, entry.notice, { message: entry.message }));
    });
    $(window).on('pagehide', function () {
        entries.forEach(entry => { pause(entry); window.clearTimeout(entry.exitTimer); });
    });
    $(window).on('pageshow', function (event) { if (event.originalEvent && event.originalEvent.persisted) entries.forEach(schedule); });
});
