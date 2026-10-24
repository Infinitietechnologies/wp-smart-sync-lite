jQuery(function ($) {
    'use strict';

    const config = window.ajaxObject;
    const logContainer = $('#sync-log-container');
    if (!config || !logContainer.length) return;

    const strings = config.settings_strings || {};
    const text = (key, fallback) => strings[key] || fallback || key;
    const buttons = $('.smartsync-wrap .sync-button');
    let pollingTimer;
    let generation = 0;
    let activeType = '';
    let activeDirection = '';
    let starting = false;
    let stopping = false;
    let running = false;
    let lastStatus = '';
    buttons.each(function () {
        $(this).data('smartsync-label', $(this).contents().clone()).prop('disabled', true);
    });

    function request(action, data) {
        return $.ajax({
            url: config.ajax_url,
            method: 'POST',
            data: $.extend({}, data, { action: action, nonce: config.nonce })
        });
    }

    function errorMessage(response, fallback) {
        const data = response && response.data;
        return typeof data === 'string' ? data : (data && data.message) || fallback;
    }

    function appendLog(message, isError, kind) {
        $('#sync-log-placeholder').remove();
        const row = $('<p>').text(String(message || '')).toggleClass('error', !!isError);
        if (kind) row.addClass(kind);
        logContainer.append(row);
        $(document).trigger('smartsync:log', [{ row: row, message: String(message || ''), isError: !!isError, kind: kind }]);
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
            button.prop('disabled', isRunning || starting || stopping);
        });
        $(document).trigger('smartsync:running', [isRunning]);
    }

    function cancelPolling() {
        generation++;
        window.clearTimeout(pollingTimer);
    }

    function checkSyncStatus() {
        window.clearTimeout(pollingTimer);
        const token = generation;
        request('check_sync_status').done(function (response) {
            if (token !== generation) return;
            if (!response || !response.success || !response.data) {
                appendLog(errorMessage(response, text('status_error', 'Could not read sync status.')), true);
                setRunningState(false);
                return;
            }
            const data = response.data;
            const status = data.status;
            setRunningState(status === 'processing', data.type, data.direction);
            $(document).trigger('smartsync:status', [data]);
            if (status === 'processing') {
                const total = Math.max(0, Number(data.total_files) || 0);
                const processed = Math.max(0, Number(data.processed_files) || 0);
                const progress = total ? Math.min(100, processed / total * 100).toFixed(1) : '0.0';
                const direction = data.direction === 'upload' ? text('to_aws') : text('from_aws');
                appendLog(text('progress') + ': ' + progress + '% (' + processed + '/' + total + ' ' + text('files') + ' ' + direction + ')', false, 'info');
                pollingTimer = window.setTimeout(checkSyncStatus, 5000);
            } else if (lastStatus !== status && (status === 'completed' || status === 'failed')) {
                appendLog(text(status === 'completed' ? 'sync_completed' : 'sync_failed'), status === 'failed', status === 'completed' ? 'success' : '');
                (Array.isArray(data.successes) ? data.successes : []).forEach(function (file) {
                    appendLog(text(data.direction === 'upload' ? 'uploaded' : 'downloaded') + ': ' + String(file));
                });
                (Array.isArray(data.errors) ? data.errors : []).forEach(function (error) {
                    appendLog(typeof error === 'string' ? error : error.message || String(error), true);
                });
            }
            lastStatus = status;
        }).fail(function () {
            if (token !== generation) return;
            appendLog(text('status_error', 'Could not read sync status.'), true);
            setRunningState(false);
        });
    }

    buttons.on('click', function (event) {
        event.preventDefault();
        if (starting || stopping || running || activeType) {
            window.alert(activeType + ' ' + text('sync_in_process'));
            return;
        }
        const button = $(this);
        const type = button.data('type');
        const direction = button.data('direction');
        if (!['media', 'assets'].includes(type) || !['upload', 'download'].includes(direction)) return;
        if (!window.confirm(text(direction === 'upload' ? 'confirm_sync_local_to_aws' : 'confirm_sync_aws_to_local'))) return;
        // Acquire the UI lock only after confirmation; a canceled dialog must leave it usable.
        starting = true;
        cancelPolling();
        const token = generation;
        setRunningState(true, type, direction);
        lastStatus = '';
        appendLog(text('starting_sync'));
        $(document).trigger('smartsync:sync-started');
        request(direction === 'upload' ? 'sync_local_to_aws' : 'sync_aws_to_local', { type: type }).done(function (response) {
            if (token !== generation) return;
            starting = false;
            if (response && response.success) {
                appendLog(text('starting_sync_of') + ': ' + ((response.data && response.data.total_files) || 0) + ' ' + text('files'));
                checkSyncStatus();
            } else {
                setRunningState(false);
                appendLog(errorMessage(response, text('failed_to_start_sync')), true);
            }
        }).fail(function () {
            if (token !== generation) return;
            starting = false;
            setRunningState(false);
            appendLog(text('failed_to_start_sync'), true);
        });
    });

    $('#stop-all-processes').on('click', function () {
        if (stopping) return;
        if (!window.confirm(text('confirm_stop_processes'))) return;
        stopping = true;
        buttons.prop('disabled', true);
        const button = $(this).prop('disabled', true);
        request('stop_all_processes').done(function (response) {
            if (response && response.success) {
                cancelPolling();
                starting = false;
                setRunningState(false);
                lastStatus = 'idle';
                $(document).trigger('smartsync:status', [{ status: 'idle', processed_files: 0, total_files: 0 }]);
                appendLog(errorMessage(response, text('stopped', 'Sync stopped.')));
            } else {
                appendLog(errorMessage(response, text('failed_to_stop_processes')), true);
            }
        }).fail(function () {
            appendLog(text('failed_to_stop_processes'), true);
        }).always(function () {
            stopping = false;
            button.prop('disabled', false);
            setRunningState(running, activeType, activeDirection);
        });
    });

    function toggleSetting(element, action, data, successText, failureText) {
        const newState = element.prop('checked');
        element.prop('disabled', true);
        request(action, data).done(function (response) {
            if (response && response.success) {
                appendLog(successText);
            } else {
                element.prop('checked', !newState);
                appendLog(errorMessage(response, failureText), true);
            }
        }).fail(function () {
            element.prop('checked', !newState);
            appendLog(failureText, true);
        }).always(function () { element.prop('disabled', false); });
    }

    $('.use-cloudfront-button').on('change', function () {
        const element = $(this);
        toggleSetting(element, 'toggle_cdn', { useCdn: element.prop('checked'), type: element.data('type') }, text('cdn_setup_success'), text('cdn_setup_failed'));
    });
    $('#auto-delete-media').on('change', function () {
        const element = $(this);
        toggleSetting(element, 'toggle_media_auto_delete', { state: element.prop('checked') }, text(element.prop('checked') ? 'auto_delete_enabled' : 'auto_delete_disabled'), text('auto_delete_failed'));
    });

    $('#clear-cloudfront-cache-button').on('click', function () {
        if (!window.confirm(text('confirm_clear_cache'))) return;
        const button = $(this).prop('disabled', true);
        request('clear_cloudfront_cache').done(function (response) {
            appendLog(response && response.success ? text('cache_cleared') : errorMessage(response, text('failed_to_clear_cache')), !(response && response.success));
        }).fail(function () {
            appendLog(text('failed_to_clear_cache'), true);
        }).always(function () { button.prop('disabled', false); });
    });

    $('#fetch-analytics-button').on('click', function () {
        const button = $(this).prop('disabled', true);
        const fields = $('#total-files, #total-storage, #total-price').text(text('loading'));
        request('fetch_aws_analytics').done(function (response) {
            if (response && response.success && response.data) {
                $('#total-files').text(response.data.total_files);
                $('#total-storage').text(response.data.total_storage);
                const price = response.data.price_in_dollars;
                $('#total-price').text(price === null || price === undefined ? text('cost_unavailable', 'Unavailable') : '~ ' + Number(price).toFixed(2) + ' USD');
            } else {
                fields.text(text('error'));
                appendLog(errorMessage(response, text('failed_to_fetch_analytics')), true);
            }
        }).fail(function () {
            fields.text(text('error'));
            appendLog(text('failed_to_fetch_analytics'), true);
        }).always(function () { button.prop('disabled', false); });
    });

    $(window).on('pagehide', cancelPolling);
    checkSyncStatus();
});
