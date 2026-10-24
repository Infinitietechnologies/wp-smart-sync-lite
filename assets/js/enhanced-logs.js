jQuery(function ($) {
    'use strict';
    const log = $('#sync-log-container');
    if (!log.length) return;
    const strings = (window.ajaxObject || {}).settings_strings || {};
    // Decorate the actual log row; never parse provider messages or filenames as HTML.
    $(document).on('smartsync:log', function (_, entry) {
        entry.row.prepend($('<span>').addClass('log-timestamp').text('[' + new Date().toLocaleTimeString() + '] '));
    });
    const clear = $('<button>').attr({ id: 'clear-logs-button', type: 'button' }).addClass('secondary-btn').text(strings.clear_logs || 'Clear Logs');
    log.after(clear);
    clear.on('click', function () {
        log.empty().append($('<p>').attr('id', 'sync-log-placeholder').text(strings.no_logs || 'No logs available. Start a sync operation to see logs here.'));
    });
});
