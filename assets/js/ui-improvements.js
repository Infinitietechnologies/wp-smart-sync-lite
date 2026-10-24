jQuery(function ($) {
    'use strict';

    const wrapper = $('.smartsync-wrap').first();
    if (!wrapper.length) return;
    const settings = window.ajaxObject || {};
    const strings = settings.settings_strings || {};
    const text = (key, fallback) => strings[key] || fallback;

    const page = new URLSearchParams(window.location.search).get('page');
    if (['smarl-smart-sync', 'smarl-configuration-wizard', 'custom-cloudfront-url'].includes(page)) {
        const navigation = $('<nav>').addClass('smartsync-breadcrumbs').attr('aria-label', text('breadcrumb', 'Breadcrumb'));
        navigation.append($('<a>').attr('href', new URL('index.php', window.location.href).href).text(text('dashboard', 'Dashboard')));
        navigation.append($('<span>').addClass('separator').attr('aria-hidden', 'true').text('›'));
        if (page !== 'smarl-smart-sync') {
            navigation.append($('<a>').attr('href', new URL('admin.php?page=smarl-smart-sync', window.location.href).href).text(text('settings_title', 'SmartSync Settings')));
            navigation.append($('<span>').addClass('separator').attr('aria-hidden', 'true').text('›'));
        }
        navigation.append($('<span>').attr('aria-current', 'page').text(wrapper.find('h1').first().text()));
        wrapper.prepend(navigation);
    }

    // Tabs are owned by this script on every plugin page.
    const tabs = wrapper.find('.tab-button');
    function selectTab(tab, focus) {
        tabs.removeClass('active').attr({ 'aria-selected': 'false', tabindex: '-1' });
        tab.addClass('active').attr({ 'aria-selected': 'true', tabindex: '0' });
        wrapper.find('.tab-panel').removeClass('active').prop('hidden', true);
        $('#' + tab.attr('aria-controls')).addClass('active').prop('hidden', false);
        if (focus) tab.trigger('focus');
    }
    tabs.on('click', function () { selectTab($(this), false); });
    tabs.on('keydown', function (event) {
        const current = tabs.index(this);
        let next;
        if (event.key === 'ArrowRight') next = (current + 1) % tabs.length;
        if (event.key === 'ArrowLeft') next = (current - 1 + tabs.length) % tabs.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = tabs.length - 1;
        if (next === undefined) return;
        event.preventDefault();
        selectTab(tabs.eq(next), true);
    });

    if ($('#sync-log-container').length) {
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
        summary.append(percentage, files);
        const stats = $('<div>').addClass('smartsync-progress-stats');
        const processed = $('<span>').attr('id', 'progress-processed');
        const remaining = $('<span>').attr('id', 'progress-remaining');
        const time = $('<span>').attr('id', 'progress-time');
        [
            [processed, text('processed', 'Processed')],
            [remaining, text('remaining', 'Remaining')],
            [time, text('estimated_time', 'Est. Time')]
        ].forEach(function (item) {
            stats.append($('<div>').addClass('smartsync-progress-stat').append(
                item[0].addClass('smartsync-progress-stat-value'),
                $('<div>').addClass('smartsync-progress-stat-label').text(item[1])
            ));
        });
        progress.append(bar, summary, stats);
        $('#sync-log-container').before(progress);
        const indicator = $('<div>').addClass('smartsync-background-indicator').attr({ role: 'status', 'aria-live': 'polite' }).append(
            $('<span>').addClass('spinner').attr('aria-hidden', 'true'),
            $('<span>').text(text('running', 'Sync in progress'))
        );
        wrapper.append(indicator);
        let startTime = 0;
        $(document).on('smartsync:sync-started', function () { startTime = Date.now(); });
        $(document).on('smartsync:running', function (_, isRunning) {
            indicator.toggleClass('active', !!isRunning);
        });
        $(document).on('smartsync:status', function (_, status) {
            const total = Math.max(0, Number(status.total_files) || 0);
            const completed = Math.max(0, Number(status.processed_files) || 0);
            const percent = total ? Math.min(100, completed / total * 100) : 0;
            const running = status.status === 'processing';
            progress.prop('hidden', !running);
            indicator.toggleClass('active', running);
            if (!running) { startTime = 0; return; }
            if (!startTime) startTime = Date.now();
            inner.css('width', percent + '%');
            bar.attr('aria-valuenow', percent.toFixed(1));
            percentage.text(percent.toFixed(1) + '%');
            files.text(completed + '/' + total + ' ' + text('files', 'files'));
            processed.text(completed);
            remaining.text(Math.max(0, total - completed));
            const elapsed = (Date.now() - startTime) / 1000;
            const seconds = completed && elapsed >= 1 ? Math.round(elapsed / completed * Math.max(0, total - completed)) : null;
            time.text(seconds === null ? '--:--' : Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0'));
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
