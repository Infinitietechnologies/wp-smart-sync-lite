jQuery(function ($) {
    'use strict';

    const config = window.ajaxObject;
    const logContainer = $('#sync-log-container');
    if (!config || !logContainer.length) return;

    const strings = config.settings_strings || {};
    const statusTimeout = Number(config.status_timeout_ms) === 180000 ? 180000 : 15000;
    const text = (key, fallback) => strings[key] || fallback || key;
    const buttons = $('.smartsync-wrap .sync-button');
    let pollingTimer;
    let statusRequest = null;
    let statusRequestGeneration = -1;
    let queuedStatus = false;
    let statusFailures = 0;
    let generation = 0;
    let activeType = '';
    let activeDirection = '';
    let starting = false;
    let stopping = false;
    let recovering = false;
    let running = false;
    let statusKnown = false;
    let operationBusy = false;
    let pageActive = true;
    let lastStatusError = '';
    let lastStatus = '';
    let lastProgress = '';
    let lastStatusRun = '';
    const retryStatusButton = $('<button>').attr({ type: 'button', id: 'retry-sync-status' }).addClass('settings-button settings-button-secondary').text(text('check_status', 'Check status')).prop('hidden', true).prependTo('.settings-activity-actions');
    retryStatusButton.on('click', function () { statusFailures = 0; checkSyncStatus(); });
    buttons.each(function () {
        $(this).data('smartsync-label', $(this).contents().clone()).prop('disabled', true);
    });

    function request(action, data, timeout) {
        return $.ajax({
            url: config.ajax_url,
            method: 'POST',
            timeout: timeout || 0,
            data: $.extend({}, data, { action: action, nonce: config.nonce })
        });
    }

    function errorMessage(response, fallback) {
        const data = response && response.data;
        return typeof data === 'string' ? data : (data && data.message) || fallback;
    }

    function appendLog(message, isError, kind, notice) {
        $('#sync-log-placeholder').remove();
        const row = $('<p>').text(String(message || '')).toggleClass('error', !!isError);
        if (kind) row.addClass(kind);
        logContainer.append(row);
        if (isError) $('#settings-activity-details').prop('open', true);
        const notification = notice === false ? null : typeof notice === 'string' ? { type: notice } : notice || (isError ? { type: 'error' } : kind === 'success' ? { type: 'success' } : null);
        $(document).trigger('smartsync:log', [{ row: row, message: String(message || ''), isError: !!isError, kind: kind, notice: notification }]);
        logContainer.children('p').slice(0, -100).remove();
        logContainer.scrollTop(logContainer[0].scrollHeight);
    }

    function setRunningState(isRunning, type, direction) {
        running = isRunning;
        activeType = isRunning ? type || activeType : '';
        activeDirection = isRunning ? direction || activeDirection : '';
        $('#current-sync-state').val(activeType);
        buttons.each(function () {
            const button = $(this);
            const active = isRunning && button.data('type') === type && button.data('direction') === direction;
            button.empty().append(active ? document.createTextNode(text('syncing', 'Syncing...')) : button.data('smartsync-label').clone());
            button.prop('disabled', !statusKnown || operationBusy || isRunning || starting || stopping);
        });
        $('.smartsync-wrap .sync-mode').prop('disabled', !statusKnown || operationBusy || isRunning || starting || stopping);
        $('#stop-all-processes').prop('hidden', statusKnown && !isRunning && !starting && !stopping);
        $('#recover-sync-operation').prop('hidden', !statusKnown || !operationBusy || isRunning || starting || stopping).prop('disabled', recovering);
        if (!statusKnown) activityStatus('unknown');
        else if (stopping) activityStatus('stopping');
        else if (starting) activityStatus('starting');
        else if (operationBusy && !isRunning) activityStatus('busy');
        else if (isRunning) activityStatus('processing', { type: type, direction: direction });
        $(document).trigger('smartsync:running', [isRunning]);
    }

    function activityStatus(state, data) {
        data = data || {};
        const labels = { idle: ['activity_ready', 'Ready'], starting: ['activity_starting', 'Preparing sync…'],
            stopping: ['stopping', 'Stopping…'], stopped: ['activity_stopped', 'Sync stopped'],
            completed: ['activity_completed', 'Last sync completed'], failed: ['activity_failed', 'Needs attention'],
            unknown: ['activity_unknown', 'Status unavailable'], busy: ['activity_busy', 'Another operation is finishing…'] };
        let label;
        if (state === 'processing' && ['upload', 'download'].includes(data.direction)) {
            const key = (data.direction === 'download' ? 'widget_restore_' : 'widget_upload_') + (data.type === 'assets' ? 'assets' : 'media');
            label = text(key, text('running', 'Sync in progress'));
        } else {
            const entry = labels[state] || ['running', 'Sync in progress'];
            label = text(entry[0], entry[1]);
        }
        $('#settings-activity-status').attr('data-state', state).text(label);
        if (state === 'failed') $('#settings-activity-details').prop('open', true);
    }

    function cancelPolling() {
        generation++;
        window.clearTimeout(pollingTimer);
        queuedStatus = false;
    }

    function scheduleStatus(delay) {
        if (pageActive && !document.hidden && navigator.onLine !== false) pollingTimer = window.setTimeout(checkSyncStatus, delay);
    }

    function checkSyncStatus() {
        if (!pageActive || document.hidden || navigator.onLine === false) return;
        window.clearTimeout(pollingTimer);
        if (statusRequest) {
            if (statusRequestGeneration !== generation) queuedStatus = true;
            return;
        }
        const token = generation;
        function retryStatus(response, authenticationFailed, delayed) {
            statusKnown = false;
            statusFailures++;
            const message = delayed ? text('status_waiting', 'The server is taking longer to respond. Retrying the status check shortly.') : errorMessage(response, text('status_error', 'Could not read sync status.'));
            if (message !== lastStatusError) appendLog(message, !delayed, delayed ? 'warning' : '', delayed ? { type: 'warning', key: 'status-delayed' } : undefined);
            lastStatusError = message;
            $(document).trigger('smartsync:status-error', [{ message: message, delayed: !!delayed }]);
            // An unavailable status endpoint does not establish that the server stopped.
            setRunningState(running, activeType, activeDirection);
            if (authenticationFailed || statusFailures >= 5) {
                retryStatusButton.prop({ hidden: false, disabled: false });
                $(document).trigger('smartsync:notice', [{ type: 'warning', key: 'status-polling-paused', message: text('status_polling_paused', 'Automatic status checks are paused. Use Check status to try again.') }]);
            } else scheduleStatus(5000 * Math.pow(2, statusFailures - 1));
        }
        const xhr = request('check_sync_status', { summary: '1' }, statusTimeout);
        statusRequest = xhr;
        statusRequestGeneration = token;
        retryStatusButton.prop('disabled', true);
        xhr.done(function (response) {
            if (token !== generation) return;
            if (!response || !response.success || !response.data || !['idle', 'processing', 'completed', 'failed'].includes(response.data.status)) {
                retryStatus(response);
                return;
            }
            statusKnown = true;
            statusFailures = 0;
            retryStatusButton.prop({ hidden: true, disabled: false });
            lastStatusError = '';
            const data = response.data;
            const status = data.status;
            operationBusy = data.operation_busy === true;
            setRunningState(status === 'processing', data.type, data.direction);
            $(document).trigger('smartsync:status', [data]);
            if (status === 'processing') {
                const total = Math.max(0, Number(data.total_files) || 0);
                const processed = Math.max(0, Number(data.processed_files) || 0);
                const progress = total ? Math.min(100, processed / total * 100).toFixed(1) : '0.0';
                const direction = data.direction === 'upload' ? text('to_aws') : text('from_aws');
                const progressKey = [data.run_id || '', data.type, data.direction, processed, total, data.failed_files || 0].join('|');
                if (progressKey !== lastProgress) appendLog(text('progress') + ': ' + progress + '% (' + processed + '/' + total + ' ' + text('files') + ' ' + direction + ')', false, 'info');
                lastProgress = progressKey;
                scheduleStatus(5000);
            } else if ((lastStatus !== status || lastStatusRun !== String(data.run_id || '')) && (status === 'completed' || status === 'failed')) {
                appendLog(text(status === 'completed' ? 'sync_completed' : 'sync_failed'), status === 'failed', status === 'completed' ? 'success' : '', status === 'failed' ? { type: 'error', key: 'sync-failure-' + (data.run_id || 'legacy') } : false);
                if (data.direction === 'upload' && Number(data.scanned_files) > 0) appendLog(uploadSummary(data));
                (Array.isArray(data.successes) ? data.successes : []).forEach(function (file) {
                    appendLog(text(data.direction === 'upload' ? 'uploaded' : 'downloaded') + ': ' + String(file));
                });
                (Array.isArray(data.errors) ? data.errors : []).forEach(function (error) {
                    appendLog(typeof error === 'string' ? error : error.message || String(error), true, '', false);
                });
            }
            lastStatus = status;
            lastStatusRun = String(data.run_id || '');
            if (status !== 'processing') lastProgress = '';
            if (status !== 'processing' && operationBusy) scheduleStatus(5000);
        }).fail(function (xhr, reason) {
            if (token !== generation) return;
            retryStatus(xhr.responseJSON, xhr.status === 401 || xhr.status === 403, reason === 'timeout');
        }).always(function () {
            if (statusRequest !== xhr) return;
            statusRequest = null;
            if (queuedStatus) { queuedStatus = false; checkSyncStatus(); }
        });
    }

    function recoverStartFailure(response) {
        starting = false;
        statusKnown = false;
        setRunningState(false);
        appendLog(errorMessage(response, text('failed_to_start_sync')), true);
        // A failed/lost response may follow a successful start or an existing run's 409.
        checkSyncStatus();
    }

    function uploadSummary(data) {
        return text('sync_summary', 'Uploads: %1$s · Unchanged: %2$s').replace('%1$s', String(Math.max(0, Number(data.total_files) || 0))).replace('%2$s', String(Math.max(0, Number(data.skipped_files) || 0)));
    }

    buttons.on('click', function (event) {
        event.preventDefault();
        if (operationBusy && !running) {
            appendLog(text('activity_busy', 'Another operation is finishing…'), false, 'warning', { type: 'warning', key: 'operation-busy' });
            return;
        }
        if (starting || stopping || running || activeType) {
            appendLog(text('sync_in_progress', 'A sync is already in progress.'), false, 'warning', { type: 'warning', key: 'sync-active' });
            return;
        }
        const button = $(this);
        const type = button.data('type');
        const direction = button.data('direction');
        const mode = $('#sync-mode-' + type + ' input:checked').val() || 'incremental';
        if (type !== 'media' || !['upload', 'download'].includes(direction)) return;
        const destination = $('.settings-destination-provider').length ? $('.settings-destination-provider').text() + ' / ' + $('.settings-destination-storage').text() : '';
        window.smartsyncConfirm({
            kind: direction === 'download' ? 'restore' : (mode === 'full' ? 'full_' : 'upload_') + type,
            message: text(direction === 'upload' ? (mode === 'full' ? 'confirm_full_resync' : 'confirm_sync_local_to_aws') : 'confirm_sync_aws_to_local'),
            context: destination
        }, function () {
            if (!pageActive || !statusKnown || operationBusy || starting || stopping || running || activeType) return;
            // Acquire the UI lock only after confirmation; a canceled dialog must leave it usable.
            starting = true;
            cancelPolling();
            const token = generation;
            setRunningState(true, type, direction);
            lastStatus = '';
            lastProgress = '';
            appendLog(text('starting_sync'));
            $(document).trigger('smartsync:sync-started');
            const payload = direction === 'upload' ? { type: type, mode: mode } : { type: type };
            request(direction === 'upload' ? 'sync_local_to_aws' : 'sync_aws_to_local', payload).done(function (response) {
                if (token !== generation) return;
                starting = false;
                if (response && response.success) {
                    $(document).trigger('smartsync:sync-state-changed');
                    appendLog(direction === 'upload' ? uploadSummary(response.data || {}) : text('starting_sync_of') + ': ' + ((response.data && response.data.total_files) || 0) + ' ' + text('files'), false, '', { type: 'info', key: 'sync-start' });
                    checkSyncStatus();
                } else {
                    recoverStartFailure(response);
                }
            }).fail(function (xhr) {
                if (token !== generation) return;
                recoverStartFailure(xhr.responseJSON);
            });
        });
    });

    $('#stop-all-processes').on('click', function () {
        if (stopping) return;
        const trigger = $(this);
        const confirmedGeneration = generation;
        const confirmedRun = lastStatusRun;
        window.smartsyncConfirm({ kind: 'stop', message: text('confirm_stop_processes') }, function () {
            if (!pageActive || stopping || generation !== confirmedGeneration || lastStatusRun !== confirmedRun) return;
            stopping = true;
            activityStatus('stopping');
            $(document).trigger('smartsync:stopping', [true]);
            let token = generation;
            buttons.prop('disabled', true);
            $('.smartsync-wrap .sync-mode').prop('disabled', true);
            const button = trigger.prop('disabled', true);
            request('stop_all_processes').done(function (response) {
                if (token !== generation) return;
                if (response && response.success) {
                    cancelPolling();
                    $(document).trigger('smartsync:sync-state-changed');
                    token = generation;
                    starting = false;
                    statusKnown = true;
                    operationBusy = response.data.operation_busy === true;
                    setRunningState(false);
                    lastStatus = 'idle';
                    $(document).trigger('smartsync:status', [{ status: 'idle', stopped: !operationBusy, operation_busy: operationBusy, processed_files: 0, total_files: 0 }]);
                    if (operationBusy) scheduleStatus(5000);
                    appendLog(errorMessage(response, text('stopped', 'Sync stopped.')));
                } else {
                    appendLog(errorMessage(response, text('failed_to_stop_processes')), true);
                }
            }).fail(function (xhr) {
                if (token === generation) appendLog(errorMessage(xhr.responseJSON, text('failed_to_stop_processes')), true);
            }).always(function () {
                if (token !== generation) return;
                stopping = false;
                $(document).trigger('smartsync:stopping', [false]);
                button.prop('disabled', false);
                setRunningState(running, activeType, activeDirection);
            });
        });
    });

    $('#recover-sync-operation').on('click', function () {
        if (recovering || !statusKnown || !operationBusy || running || starting || stopping) return;
        recovering = true;
        const button = $(this).prop('disabled', true);
        request('recover_sync_operation').done(function (response) {
            if (!pageActive) return;
            appendLog(errorMessage(response, text('recovery_failed', 'Unable to recover the operation. Please try again.')), !(response && response.success), response && response.success ? 'success' : '');
        }).fail(function (xhr) {
            if (pageActive) appendLog(errorMessage(xhr.responseJSON, text('recovery_failed', 'Unable to recover the operation. Please try again.')), true);
        }).always(function () {
            recovering = false;
            button.prop('disabled', false);
            if (pageActive) { statusFailures = 0; checkSyncStatus(); }
        });
    });

    function toggleSetting(element, action, data, successText, failureText) {
        const newState = element.prop('checked');
        element.prop('disabled', true);
        request(action, data).done(function (response) {
            if (response && response.success) {
                appendLog(successText, false, 'success');
            } else {
                element.prop('checked', !newState);
                appendLog(errorMessage(response, failureText), true);
            }
        }).fail(function (xhr) {
            element.prop('checked', !newState);
            appendLog(errorMessage(xhr.responseJSON, failureText), true);
        }).always(function () { element.prop('disabled', false); });
    }

    $('.use-cloudfront-button').on('change', function () {
        const element = $(this);
        toggleSetting(element, 'toggle_cdn', { useCdn: element.prop('checked'), type: element.data('type') }, text('cdn_setup_success'), text('cdn_setup_failed'));
    });
    $('.sync-mode').on('change', function () {
        const full = $(this).find('input:checked').val() === 'full';
        $('#' + $(this).attr('aria-describedby')).text(full ? text('sync_hint_full', 'Uploads every selected local file again.') : text('sync_hint_incremental', 'Skips unchanged files and retries unsuccessful uploads.'));
    });
    $('#auto-delete-media').on('change', function () {
        const element = $(this);
        toggleSetting(element, 'toggle_media_auto_delete', { state: element.prop('checked') }, text(element.prop('checked') ? 'auto_delete_enabled' : 'auto_delete_disabled'), text('auto_delete_failed'));
    });



    $('#fetch-analytics-button').on('click', function () {
        if (this.disabled) return;
        const button = $(this).prop('disabled', true);
        const label = button.find('.settings-action-label');
        const originalLabel = label.text();
        button.addClass('is-loading');
        label.text(text('fetching_analytics', 'Fetching usage…'));
        const metrics = $('.settings-usage-metrics').attr('aria-busy', 'true');
        const fields = $('#total-files, #total-storage').addClass('settings-metric-loading').text(text('loading', 'Loading…'));
        $('#total-price').text(text('cost_unavailable', 'Unavailable'));
        request('fetch_cloud_analytics', {}, 35000).done(function (response) {
            if (response && response.success && response.data) {
                $('#total-files').text(response.data.total_files);
                $('#total-storage').text(response.data.total_storage);
                const price = response.data.price_in_dollars;
                $('#total-price').text(price === null || price === undefined ? text('cost_unavailable', 'Unavailable') : '~ ' + Number(price).toFixed(2) + ' USD');
                if (response.data.usage_source === 'provider') $('#analytics-description').text(text('provider_reported_usage', 'Usage totals reported by your storage provider.'));
                appendLog(text('usage_updated', 'Storage usage updated.'), false, 'success');
            } else {
                fields.text(text('error'));
                appendLog(errorMessage(response, text('failed_to_fetch_analytics')), true);
            }
        }).fail(function (xhr, status) {
            fields.text(text('error'));
            appendLog(status === 'timeout' ? text('analytics_timeout', 'The storage usage request timed out. Check your connection and try again.') : errorMessage(xhr.responseJSON, text('failed_to_fetch_analytics')), true);
        }).always(function () {
            fields.removeClass('settings-metric-loading');
            metrics.attr('aria-busy', 'false');
            label.text(originalLabel);
            button.removeClass('is-loading').prop('disabled', false);
        });
    });

    $(window).on('pagehide', function () {
        pageActive = false;
        cancelPolling();
        if (statusRequest && statusRequest.abort) statusRequest.abort();
    });
    $(document).on('visibilitychange', function () {
        window.clearTimeout(pollingTimer);
        if (!document.hidden && (!statusKnown || running || operationBusy)) { statusFailures = 0; checkSyncStatus(); }
    });
    $(window).on('offline', function () { window.clearTimeout(pollingTimer); });
    $(window).on('online focus', function (event) {
        if (event.target !== window || (statusKnown && !running && !operationBusy)) return;
        statusFailures = 0; checkSyncStatus();
    });
    $(document).on('smartsync:refresh-status', function () { statusKnown = false; statusFailures = 0; checkSyncStatus(); });
    $(window).on('pageshow', function (event) {
        if (!(event.originalEvent && event.originalEvent.persisted)) return;
        pageActive = true;
        starting = stopping = false;
        statusKnown = false;
        $('#stop-all-processes').prop('disabled', false);
        cancelPolling();
        setRunningState(running, activeType, activeDirection);
        checkSyncStatus();
    });
    $(document).on('smartsync:status', function (_, data) { if (data) activityStatus(data.stopped ? 'stopped' : data.operation_busy && data.status !== 'processing' ? 'busy' : data.status, data); });
    function openRequestedLog() {
        if (window.location.hash === '#sync-log-container') $('#settings-activity-details').prop('open', true);
    }
    $(window).on('hashchange', openRequestedLog);
    $(document).on('click', '.smartsync-background-indicator a', function () {
        const destination = new URL(this.href, window.location.href);
        if (destination.hash === '#sync-log-container' && destination.pathname === window.location.pathname && destination.search === window.location.search) $('#settings-activity-details').prop('open', true);
    });
    openRequestedLog();
    checkSyncStatus();
});
