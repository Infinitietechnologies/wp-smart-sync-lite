jQuery(function ($) {
    'use strict';

    const wrapper = $('.smartsync-wrap').first();
    if (!wrapper.length) return;
    const settings = window.ajaxObject || {};
    const strings = settings.settings_strings || {};
    const text = (key, fallback) => strings[key] || fallback;
    const statusSignalKey = 'smartsync.sync-signal.' + ((settings.widget || {}).scope || window.location.origin);
    $(document).on('smartsync:sync-state-changed', function () {
        try { window.localStorage.setItem(statusSignalKey, String(Date.now()) + '-' + Math.random()); }
        catch (_) { /* Focus/visibility refresh still works when storage is unavailable. */ }
    });
    $(window).on('storage', function (event) {
        if (event.originalEvent && event.originalEvent.key === statusSignalKey) $(document).trigger('smartsync:refresh-status');
    });

    // Tabs are owned by this script on every plugin page.
    const tabs = wrapper.find('.tab-button');
    const urlTabs = wrapper.hasClass('smartsync-settings') && tabs.length > 0;
    function selectTab(tab, focus, updateUrl) {
        if (!tab.length) return;
        tabs.removeClass('active').attr({ 'aria-selected': 'false', tabindex: '-1' });
        tab.addClass('active').attr({ 'aria-selected': 'true', tabindex: '0' });
        wrapper.find('.tab-panel').removeClass('active').prop('hidden', true);
        $('#' + tab.attr('aria-controls')).addClass('active').prop('hidden', false);
        if (urlTabs) wrapper.find('.settings-destination').prop('hidden', tab.attr('data-tab') === 'general');
        if (focus) tab.trigger('focus');
        if (urlTabs && updateUrl) {
            const url = new URL(window.location.href);
            const value = tab.attr('data-tab') === 'general' ? 'overview' : tab.attr('data-tab');
            if (url.searchParams.get('tab') !== value) {
                url.searchParams.set('tab', value);
                window.history.pushState(window.history.state, '', url.href);
            }
        }
    }
    function restoreTab() {
        const value = new URL(window.location.href).searchParams.get('tab');
        const name = value === 'media' || value === 'assets' ? value : 'general';
        selectTab(tabs.filter(function () { return $(this).attr('data-tab') === name; }).first(), false, false);
    }
    if (urlTabs) {
        restoreTab();
        $(window).on('popstate pageshow', restoreTab);
    }
    tabs.on('click', function () { selectTab($(this), false, true); });
    tabs.on('keydown', function (event) {
        const current = tabs.index(this);
        let next;
        if (event.key === 'ArrowRight') next = (current + 1) % tabs.length;
        if (event.key === 'ArrowLeft') next = (current - 1 + tabs.length) % tabs.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = tabs.length - 1;
        if (next === undefined) return;
        event.preventDefault();
        selectTab(tabs.eq(next), true, true);
    });

    if ($('#sync-log-container').length) {
        function progressIcon(name) {
            const paths = { files: 'M14 3H5v18h14V8l-5-5M14 3v5h5M8 12h8M8 16h5', clock: 'M12 8v4l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0', processed: 'M5 12l4 4L19 6' };
            return $('<span>').addClass('smartsync-progress-icon').attr('aria-hidden', 'true').html('<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="' + paths[name] + '"/></svg>');
        }
        let countFormat;
        try { countFormat = new Intl.NumberFormat(document.documentElement.lang || undefined); }
        catch (_) { countFormat = new Intl.NumberFormat(); }
        const progress = $('<div>').addClass('smartsync-progress-container').prop('hidden', true);
        const bar = $('<div>').addClass('smartsync-progress-bar').attr({
            role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100',
            'aria-valuenow': '0', 'aria-label': text('progress', 'Progress')
        });
        const inner = $('<div>').addClass('smartsync-progress-bar-inner');
        bar.append(inner);
        const summary = $('<div>').addClass('smartsync-progress-text').attr('aria-live', 'polite');
        const percentage = $('<span>').addClass('smartsync-progress-percentage');
        const files = $('<span>').addClass('smartsync-progress-files');
        const fileCount = $('<span>');
        files.append(progressIcon('files'), fileCount);
        summary.append(percentage, files);
        const stats = $('<div>').addClass('smartsync-progress-stats');
        const processed = $('<span>').attr('id', 'progress-processed');
        const remaining = $('<span>').attr('id', 'progress-remaining');
        const time = $('<span>').attr({ id: 'progress-time', title: text('eta_help', 'Approximate time remaining, based on completed files. File sizes, provider speed and background queue delays can change it.') }).text(text('widget_calculating', 'Calculating…'));
        [
            [processed, text('processed', 'Processed'), 'processed'],
            [remaining, text('files_remaining', 'Files remaining'), 'files'],
            [time, text('widget_eta', 'Time remaining'), 'clock']
        ].forEach(function (item) {
            stats.append($('<div>').addClass('smartsync-progress-stat').append(
                progressIcon(item[2]),
                $('<div>').addClass('smartsync-progress-stat-copy').append(item[0].addClass('smartsync-progress-stat-value'),
                    $('<div>').addClass('smartsync-progress-stat-label').text(item[1]))
            ));
        });
        progress.append(bar, summary, stats);
        if ($('#settings-progress-slot').length) $('#settings-progress-slot').append(progress);
        else $('#sync-log-container').before(progress);
        let progressRun = '';
        $(document).on('smartsync:sync-started', function () {
            progressRun = '';
            time.text(text('widget_calculating', 'Calculating…'));
        });
        $(document).on('smartsync:status', function (_, status) {
            const total = Math.max(0, Number(status.total_files) || 0);
            const completed = Math.max(0, Number(status.processed_files) || 0);
            const percent = total ? Math.min(100, completed / total * 100) : 0;
            const running = status.status === 'processing';
            progress.prop('hidden', !running);
            if (!running) { progressRun = ''; return; }
            const run = status.run_id || [status.type, status.direction, total].join('|');
            if (run !== progressRun) {
                progressRun = run;
                time.text(text('widget_calculating', 'Calculating…'));
            }
            inner.css('width', percent + '%');
            bar.attr('aria-valuenow', percent.toFixed(1));
            percentage.text(percent.toFixed(1) + '%');
            fileCount.text(text('progress_file_count', '%1$s of %2$s files').replace('%1$s', countFormat.format(completed)).replace('%2$s', countFormat.format(total)));
            processed.text(countFormat.format(completed));
            remaining.text(countFormat.format(Math.max(0, total - completed)));
        });
        $(document).on('smartsync:timing', function (_, timing) {
            time.text(timing.eta_label || text('widget_calculating', 'Calculating…'));
        });
    }

    $(document).on('smartsync:domain-status', function (_, status) {
        const verified = status === 'verified' || status === 'attaching' || status === 'attached';
        const pending = status === 'pending';
        $('#step-domain').toggleClass('active', !pending && !verified).toggleClass('completed', pending || verified);
        $('#step-certificate').toggleClass('active', pending).toggleClass('completed', verified);
        $('#step-attach').toggleClass('active', verified && status !== 'attached').toggleClass('completed', status === 'attached');
        $('.wizard-progress-bar-inner').css('width', status === 'attached' ? '100%' : verified ? '66%' : pending ? '33%' : '0%');
    });
});
