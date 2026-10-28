jQuery(function ($) {
    'use strict';
    const wrapper = $('.smartsync-wrap').first();
    const config = window.ajaxObject;
    if (!wrapper.length || !config) return;
    const strings = config.settings_strings || {};
    const statusTimeout = Number(config.status_timeout_ms) === 180000 ? 180000 : 15000;
    const text = (key, fallback) => strings[key] || fallback || key;
    const options = config.widget || {};
    const ownsPolling = !$('#sync-log-container, #aws-wizard').length;
    const scope = options.scope || window.location.pathname;
    const preferenceKey = 'smartsync.widget.' + scope;
    const timingKey = preferenceKey + '.timing';
    function read(storage, key) { try { const value = JSON.parse(window[storage].getItem(key) || '{}'); return value && typeof value === 'object' && !Array.isArray(value) ? value : {}; } catch (_) { return {}; } }
    function write(storage, key, value) { try { window[storage].setItem(key, JSON.stringify(value)); } catch (_) { /* Private browsing can disable storage. */ } }
    let preference = read('localStorage', preferenceKey);
    let timing = read('sessionStorage', timingKey);
    let current = null;
    let expanded = preference.expanded === true;
    let stopping = false;
    let pollTimer;
    let pollRequest = null;
    let pollRequestGeneration = -1;
    let queuedPoll = false;
    let pollFailures = 0;
    let pollStatusKnown = false;
    let pollActive = false;
    let hideTimer;
    let clockTimer;
    let generation = 0;
    let active = true;
    let clockOffset = 0;
    let actionError = '';
    let exitTimer;
    let leaving = false;
    let focusBeforeWidget;
    const reducedMotion = () => !window.matchMedia || window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const number = value => Number.isFinite(Number(value)) ? Math.max(0, Number(value)) : 0;
    const duration = seconds => {
        seconds = Math.max(0, Math.floor(seconds));
        const hours = Math.floor(seconds / 3600);
        return (hours ? hours + ':' : '') + String(Math.floor(seconds / 60) % 60).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
    };
    const card = $('<aside>').addClass('smartsync-background-indicator').attr({ role: 'region', 'aria-label': text('widget_label', 'Sync status') }).prop('hidden', true);
    const header = $('<button>').attr({ type: 'button', 'aria-controls': 'smartsync-widget-details', 'aria-expanded': 'false' }).addClass('smartsync-widget-toggle');
    const title = $('<span>').addClass('smartsync-status-title');
    const detail = $('<span>').addClass('smartsync-status-detail');
    const percent = $('<span>').addClass('smartsync-status-percent').attr('aria-hidden', 'true');
    const fill = $('<span>').addClass('smartsync-status-fill');
    const track = $('<span>').addClass('smartsync-status-track').attr('aria-hidden', 'true').append(fill);
    const ring = $('<span>').addClass('smartsync-status-ring');
    header.append($('<span>').addClass('smartsync-status-activity').attr('aria-hidden', 'true').append(ring),
        $('<span>').addClass('smartsync-status-copy').append(title, detail), percent,
        $('<span>').addClass('smartsync-status-chevron').attr('aria-hidden', 'true'));
    const panel = $('<div>').attr('id', 'smartsync-widget-details').addClass('smartsync-widget-details');
    const content = $('<div>').addClass('smartsync-widget-content');
    panel.append($('<div>').addClass('smartsync-widget-details-clip').append(content));
    const metadata = $('<dl>').addClass('smartsync-widget-metadata');
    const values = {};
    [['provider', 'Provider'], ['storage', 'Storage'], ['operation', 'Operation'], ['current_file', 'Current file']].forEach(function ([key, label]) {
        values[key] = $('<dd>').attr('id', 'sync-widget-' + key);
        metadata.append($('<div>').append($('<dt>').text(text('widget_' + key, label)), values[key]));
    });
    const metrics = $('<div>').addClass('smartsync-widget-metrics');
    const elapsedLabel = $('<span>').text(text('widget_elapsed', 'Elapsed'));
    const elapsed = $('<strong>').attr('id', 'sync-widget-elapsed');
    const eta = $('<strong>').attr('id', 'sync-widget-eta');
    const failed = $('<strong>').attr('id', 'sync-widget-failed');
    [[elapsed, elapsedLabel], [eta, $('<span>').text(text('widget_eta', 'Time remaining'))], [failed, $('<span>').text(text('widget_failed', 'Failed files'))]].forEach(function ([value, label]) {
        metrics.append($('<div>').append(value, label));
    });
    const error = $('<p>').addClass('smartsync-widget-error').prop('hidden', true);
    const connection = $('<p>').addClass('smartsync-widget-connection').prop('hidden', true).text(text('widget_stale', 'Unable to refresh status. Retrying…'));
    const retryPoll = $('<button>').attr('type', 'button').addClass('smartsync-widget-retry').text(text('check_status', 'Check status')).on('click', function () { pollFailures = 0; poll(); });
    const settingsUrl = config.settings_url || '/wp-admin/admin.php?page=smarl-smart-sync';
    function settingsLink(showLog) {
        const url = new URL($('#sync-log-container').length ? window.location.href : settingsUrl, window.location.href);
        url.hash = showLog ? 'sync-log-container' : '';
        return url.href;
    }
    const logs = $('<a>').attr('href', settingsLink(true)).text(text('widget_logs', 'View logs'));
    logs.on('click', function () { $(this).attr('href', settingsLink(!current || current.status !== 'stopped')); });
    const stop = $('<button>').attr('type', 'button').addClass('smartsync-widget-stop').text(text('widget_stop', 'Stop sync'));
    content.append(metadata, metrics, error, connection);
    const results = logs;
    const dismiss = $('<button>').attr('type', 'button').text(text('widget_dismiss', 'Dismiss notification'));
    const terminalActions = $('<div>').addClass('smartsync-widget-actions').append(logs, stop);
    const footer = $('<div>').addClass('smartsync-widget-footer').append($('<div>').addClass('smartsync-widget-footer-clip').append(terminalActions));
    const announcement = $('<span>').addClass('screen-reader-text smartsync-widget-announcement').attr({ role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true' });
    card.append(header, track, panel, footer, announcement);
    wrapper.append(card);

    function setExpanded(value, save) {
        expanded = value;
        card.toggleClass('is-expanded', value);
        header.attr({ 'aria-expanded': String(value), title: text(value ? 'widget_collapse' : 'widget_expand', value ? 'Collapse sync details' : 'Expand sync details') });
        panel.attr('aria-hidden', String(!value)).prop('inert', !value);
        const footerHidden = !value && current && current.status === 'processing';
        footer.attr('aria-hidden', String(!!footerHidden)).prop('inert', !!footerHidden);
        if (save) { preference.expanded = value; write('localStorage', preferenceKey, preference); }
        window.clearInterval(clockTimer);
        if (value && current && current.status === 'processing') clockTimer = window.setInterval(updateClock, 1000);
        updateClock();
    }
    function identity(data) { return data.run_id || 'legacy-' + data.status + '-' + (data.type || '') + '-' + number(data.total_files); }
    function saveTiming() { write('sessionStorage', timingKey, timing); }
    function estimateLabel(seconds) {
        if (seconds < 60) return text('eta_less_minute', '<1 min');
        const minutes = Math.ceil(seconds / 60);
        if (minutes < 60) return text('eta_minutes', '~%s min').replace('%s', String(minutes));
        if (minutes < 1440) return text('eta_hours', '~%1$s hr %2$s min').replace('%1$s', String(Math.floor(minutes / 60))).replace('%2$s', String(minutes % 60));
        return text('eta_days', '~%1$s d %2$s hr').replace('%1$s', String(Math.floor(minutes / 1440))).replace('%2$s', String(Math.floor(minutes % 1440 / 60)));
    }
    function updateClock() {
        if (!current) return;
        const now = Date.now() - clockOffset;
        const started = number(current.started_at) * 1000;
        const end = number(current.finished_at) * 1000 || now;
        const seconds = Math.max(0, (end - (started || timing.observedAt || now)) / 1000);
        elapsed.text(!started && !number(current.finished_at) && current.status !== 'processing' ? text('widget_unknown', 'Not recorded') : duration(seconds));
        elapsedLabel.text(text(started ? 'widget_elapsed' : 'widget_observed', started ? 'Elapsed' : 'Time observed'));
        const observed = Math.max(0, number(current.processed_files) - number(timing.baselineProcessed));
        // Measure completed progress, not the growing idle time between two polls.
        const observedSeconds = (number(timing.lastProgressAt) - number(timing.baselineAt)) / 1000;
        const remaining = Math.max(0, number(current.total_files) - number(current.processed_files));
        const staleAfter = Math.max(30000, Math.min(90000, number(timing.progressInterval) * 2));
        const recent = now - number(timing.lastProgressAt) < staleAfter;
        const enoughProgress = observed >= 3 && observedSeconds >= 5;
        const estimate = current.status === 'completed' ? 0 : current.status === 'processing' && enoughProgress && recent && number(current.total_files) > 0 && connection.prop('hidden')
            ? Math.round(observedSeconds / observed * remaining) : null;
        const label = current.status === 'failed' || current.status === 'stopped' ? '—' : estimate === null ? text(enoughProgress ? 'eta_updating' : 'widget_calculating', enoughProgress ? 'Updating…' : 'Calculating…') : estimateLabel(estimate);
        eta.text(label).attr('title', text('eta_help', 'Approximate time remaining, based on completed files. File sizes, provider speed and background queue delays can change it.'));
        $(document).trigger('smartsync:timing', [{ elapsed: seconds, eta: estimate, eta_label: label }]);
    }
    function finishHide() {
        if (!leaving) return;
        leaving = false;
        window.clearTimeout(exitTimer);
        card.prop('hidden', true).removeClass('active is-leaving is-entering');
    }
    function hide() {
        window.clearInterval(clockTimer);
        if (card.prop('hidden') || leaving) return;
        leaving = true;
        if (card[0].contains(document.activeElement)) {
            if (focusBeforeWidget && focusBeforeWidget.isConnected) focusBeforeWidget.focus({ preventScroll: true });
            else document.activeElement.blur();
        }
        card.attr('aria-hidden', 'true').prop('inert', true).addClass('is-leaving');
        if (reducedMotion()) finishHide();
        else exitTimer = window.setTimeout(finishHide, 240);
    }
    function show() {
        const entering = card.prop('hidden');
        window.clearTimeout(exitTimer);
        leaving = false;
        card.attr('aria-hidden', 'false').prop('inert', false).prop('hidden', false).addClass('active').removeClass('is-leaving');
        if (entering && !reducedMotion()) {
            card.addClass('is-entering');
            void card[0].offsetHeight;
            card.removeClass('is-entering');
        }
    }
    function render(data) {
        if (!data || !['idle', 'processing', 'completed', 'failed', 'stopped'].includes(data.status)) return;
        window.clearTimeout(hideTimer);
        connection.prop('hidden', true);
        if (data.stopped && current) data = $.extend({}, current, { status: 'stopped', finished_at: (Date.now() - clockOffset) / 1000 });
        if (data.status === 'idle') {
            if (current && current.status === 'stopped' && number(preference.completionUntil) > Date.now()) return;
            current = data; hide(); return;
        }
        const id = identity(data);
        if (current && identity(current) !== id) actionError = '';
        if (number(data.server_time)) clockOffset = Date.now() - number(data.server_time) * 1000;
        const now = Date.now() - clockOffset;
        if (timing.run !== id || number(data.processed_files) < number(timing.baselineProcessed)) {
            timing = { run: id, observedAt: now, baselineAt: now, baselineProcessed: number(data.processed_files), lastProcessed: number(data.processed_files), lastProgressAt: now };
        }
        if (number(data.processed_files) > number(timing.lastProcessed)) {
            timing.progressInterval = Math.max(0, now - number(timing.lastProgressAt));
            timing.lastProgressAt = now;
        }
        timing.lastProcessed = number(data.processed_files); saveTiming();
        current = data;
        const running = data.status === 'processing';
        if (!running && preference.dismissedRun === id) { hide(); return; }
        if (data.status === 'completed' || data.status === 'stopped') {
            if (preference.completionRun !== id) { preference.completionRun = id; preference.completionUntil = Date.now() + 10000; write('localStorage', preferenceKey, preference); }
            const remaining = number(preference.completionUntil) - Date.now();
            if (remaining <= 0) { hide(); return; }
            hideTimer = window.setTimeout(hide, remaining);
        }
        card.toggleClass('is-completed', data.status === 'completed').toggleClass('is-failed', data.status === 'failed').toggleClass('is-stopped', data.status === 'stopped');
        const total = number(data.total_files), processed = number(data.processed_files);
        const progress = total ? Math.min(100, processed / total * 100) : 0;
        title.text(running ? (data.direction === 'upload' ? text('status_uploading', 'Uploading files') : data.direction === 'download' ? text('status_restoring', 'Restoring files') : text('running', 'Sync in progress')) :
            text(data.status === 'completed' ? 'widget_complete' : data.status === 'stopped' ? 'widget_stopped' : 'widget_failure', data.status === 'completed' ? 'Sync complete' : data.status === 'stopped' ? 'Sync stopped' : 'Sync needs attention'));
        if (data.status === 'completed' && data.filtered_media_upload) title.text(text('widget_filtered_complete', 'File-type test complete'));
        let countText = total ? processed + ' / ' + total + ' ' + text('files', 'files') : data.status === 'completed' ? text('widget_no_transfers', 'No transfers needed') : text('sync_background', 'Running in the background');
        if (data.direction === 'upload' && number(data.skipped_files) > 0) countText += ' · ' + number(data.skipped_files) + ' ' + text('unchanged', 'unchanged');
        detail.text(countText);
        percent.prop('hidden', !total).text(progress.toFixed(1).replace(/\.0$/, '') + '%');
        track.prop('hidden', !total); fill.css('width', progress + '%');
        values.provider.text((options.providers || {})[data.provider] || data.provider || text('widget_unknown', 'Not recorded'));
        values.storage.text(data.storage || text('widget_unknown', 'Not recorded'));
        const operationKey = (data.direction === 'download' ? 'widget_restore_' : 'widget_upload_') + (data.type === 'assets' ? 'assets' : 'media');
        values.operation.text(['upload', 'download'].includes(data.direction) ? text(operationKey, data.direction === 'download' ? (data.type === 'assets' ? 'Restoring assets' : 'Restoring media') : (data.type === 'assets' ? 'Uploading assets' : 'Uploading media')) : text('widget_unknown', 'Not recorded'));
        const file = typeof data.current_file === 'string' ? data.current_file : '';
        values.current_file.text(file ? file.split('/').pop() : text('widget_waiting', 'Waiting for an update')).attr('title', file);
        values.current_file.parent().prop('hidden', !running);
        failed.text(data.failed_files === null || data.failed_files === undefined ? text('widget_calculating', 'Calculating…') : number(data.failed_files));
        const errors = Array.isArray(data.errors) ? data.errors : [];
        const message = errors.length ? String(errors[errors.length - 1]) : text('sync_failed', 'Sync failed. Check errors below.');
        error.text(data.status === 'failed' ? message : actionError).prop('hidden', data.status !== 'failed' && !actionError);
        results.text(text(data.status === 'stopped' ? 'widget_settings' : running || data.status === 'failed' ? 'widget_logs' : 'widget_results', data.status === 'stopped' ? 'Sync settings' : running || data.status === 'failed' ? 'View logs' : 'View results'))
            .attr('href', settingsLink(data.status !== 'stopped'));
        stop.prop('hidden', !running).prop('disabled', stopping);
        terminalActions.toggleClass('smartsync-widget-terminal', !running);
        if (running) { dismiss.detach(); if (!stop.parent().is(terminalActions)) terminalActions.append(stop); }
        else { stop.detach(); if (!dismiss.parent().is(terminalActions)) terminalActions.append(dismiss); }
        const spoken = title.text() + '. ' + detail.text() + (data.status === 'failed' ? '. ' + message : '');
        if (announcement.text() !== spoken) announcement.text(spoken);
        setExpanded(expanded, false);
        show();
    }
    header.on('click', function () { setExpanded(!expanded, true); });
    card.on('keydown', function (event) { if (event.key === 'Escape' && expanded) { event.preventDefault(); setExpanded(false, true); header.trigger('focus'); } });
    dismiss.on('click', function () { if (current) { preference.dismissedRun = identity(current); write('localStorage', preferenceKey, preference); } hide(); });
    card.on('focusin', function (event) { if (event.relatedTarget && !card[0].contains(event.relatedTarget)) focusBeforeWidget = event.relatedTarget; });
    card.on('transitionend', function (event) {
        if (event.target === card[0] && event.originalEvent.propertyName === 'opacity' && leaving && Number(window.getComputedStyle(card[0]).opacity) < .01) finishHide();
    });
    $(document).on('smartsync:status', function (_, data) { render(data); });
    $(document).on('smartsync:running', function (_, running) {
        if (running && (!current || current.status !== 'processing')) render({ status: 'processing', total_files: 0, processed_files: 0 });
        if (!running && current && current.status === 'processing') hide();
    });
    $(document).on('smartsync:sync-started', function () { timing = {}; saveTiming(); });
    $(document).on('smartsync:status-error', function (_, issue) {
        if (issue && issue.delayed) connection.text(issue.message);
        connection.prop('hidden', false); updateClock();
    });
    $(document).on('smartsync:stopping', function (_, value) { stopping = !!value; stop.prop('disabled', stopping).text(stopping ? text('stopping', 'Stopping…') : text('widget_stop', 'Stop sync')); });

    function poll() {
        if (!active || stopping || document.hidden || navigator.onLine === false) return;
        window.clearTimeout(pollTimer);
        if (pollRequest) {
            if (pollRequestGeneration !== generation) queuedPoll = true;
            return;
        }
        const token = generation;
        let next = 0;
        function failed(response, delayed) {
            pollStatusKnown = false;
            pollFailures++;
            $(document).trigger('smartsync:status-error', [delayed ? { delayed: true, message: text('status_waiting', 'The server is taking longer to respond. Retrying the status check shortly.') } : null]);
            if (response && (response.status === 401 || response.status === 403) || pollFailures >= 5) {
                const message = text('status_polling_paused', 'Automatic status checks are paused. Use Check status to try again.');
                connection.text(message).append(retryPoll).prop('hidden', false);
                $(document).trigger('smartsync:notice', [{ type: 'warning', key: 'status-polling-paused', message: message }]);
            } else next = 5000 * Math.pow(2, pollFailures - 1);
        }
        const xhr = $.ajax({ url: config.ajax_url, method: 'POST', timeout: statusTimeout, data: { action: 'check_sync_status', nonce: config.nonce, summary: '1' } });
        pollRequest = xhr;
        pollRequestGeneration = token;
        xhr.done(function (response) {
            if (token !== generation) return;
            if (response && response.success && response.data && ['idle', 'processing', 'completed', 'failed'].includes(response.data.status)) {
                pollStatusKnown = true;
                pollActive = response.data.status === 'processing' || response.data.operation_busy === true;
                pollFailures = 0;
                connection.text(text('widget_stale', 'Unable to refresh status. Retrying…'));
                $(document).trigger('smartsync:status', [response.data]);
                if (response.data.status === 'processing' || response.data.operation_busy === true) next = 5000;
            } else failed();
        }).fail(function (response, reason) { if (token === generation) failed(response, reason === 'timeout'); }).always(function () {
            if (pollRequest !== xhr) return;
            pollRequest = null;
            if (queuedPoll) { queuedPoll = false; poll(); return; }
            if (token === generation && active && !document.hidden && navigator.onLine !== false && next) pollTimer = window.setTimeout(poll, next);
        });
    }
    stop.on('click', function () {
        if (stopping || !current || current.status !== 'processing') return;
        if ($('#stop-all-processes').length) { $('#stop-all-processes').trigger('click'); return; }
        const confirmedRun = identity(current);
        const confirmedGeneration = generation;
        window.smartsyncConfirm({ kind: 'stop', message: text('confirm_stop_processes', 'Stop the running sync?') }, function () {
            if (!active || stopping || !current || current.status !== 'processing' || identity(current) !== confirmedRun || generation !== confirmedGeneration) return;
            actionError = ''; error.prop('hidden', true);
            const token = ++generation;
            window.clearTimeout(pollTimer);
            $(document).trigger('smartsync:before-stop');
            $(document).trigger('smartsync:stopping', [true]);
            function failedStop(response) {
                const data = response && response.data;
                actionError = typeof data === 'string' ? data : data && data.message || text('failed_to_stop_processes', 'Unable to stop the sync. Try again.');
                $(document).trigger('smartsync:notice', [{ type: 'error', message: actionError, key: 'sync-stop' }]);
                error.text(actionError).prop('hidden', false);
                setExpanded(true, false);
            }
            $.ajax({ url: config.ajax_url, method: 'POST', timeout: 15000, data: { action: 'stop_all_processes', nonce: config.nonce } }).done(function (response) {
                if (token !== generation) return;
                if (response && response.success) {
                    $(document).trigger('smartsync:sync-state-changed');
                    $(document).trigger('smartsync:status', [{ status: 'idle', stopped: true }]);
                }
                else failedStop(response);
            }).fail(function (xhr) { if (token === generation) failedStop(xhr.responseJSON); }).always(function () {
                if (token !== generation) return;
                $(document).trigger('smartsync:stopping', [false]);
                $(document).trigger('smartsync:stop-finished');
                if (ownsPolling) poll();
            });
        });
    });
    $(window).on('pagehide', function () { active = false; generation++; queuedPoll = false; window.clearTimeout(pollTimer); if (pollRequest && pollRequest.abort) pollRequest.abort(); window.clearTimeout(hideTimer); window.clearTimeout(exitTimer); if (leaving) finishHide(); window.clearInterval(clockTimer); });
    if (ownsPolling) {
        $(document).on('visibilitychange', function () { window.clearTimeout(pollTimer); if (!document.hidden && (!pollStatusKnown || pollActive)) { pollFailures = 0; poll(); } });
        $(window).on('offline', function () { window.clearTimeout(pollTimer); });
        $(window).on('online focus', function (event) {
            if (event.target !== window || (pollStatusKnown && !pollActive)) return;
            pollFailures = 0; poll();
        });
        $(document).on('smartsync:refresh-status', function () { pollStatusKnown = false; pollFailures = 0; poll(); });
    }
    $(window).on('pageshow', function (event) {
        if (!(event.originalEvent && event.originalEvent.persisted)) return;
        active = true; stopping = false;
        if (current) render(current);
        if (ownsPolling) poll();
    });
    if (options.seed) render(options.seed);
    if (ownsPolling) poll();
});
