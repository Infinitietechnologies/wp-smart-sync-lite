jQuery(function ($) {
    'use strict';

    const config = window.ajax_object;
    if (!config) return;
    const strings = config.strings || {};
    const statusTimeout = Number(config.status_timeout_ms) === 180000 ? 180000 : 15000;
    const text = (key, fallback) => strings[key] || fallback || key;
    const providers = ['aws', 'digitalocean', 'wasabi', 'bunny'];

    function errorMessage(response, fallback) {
        const data = response && response.data;
        return typeof data === 'string' ? data : (data && data.message) || fallback;
    }

    function request(action, data, options) {
        return $.ajax($.extend({
            url: config.ajax_url,
            method: 'POST',
            data: $.extend({}, data, { action: action, nonce: config.nonce })
        }, options));
    }

    function showMessage(message, kind = 'error') {
        $('#wizard-message').text(message).attr({ 'data-kind': kind, role: kind === 'error' ? 'alert' : 'status' }).prop('hidden', !message);
        if (message && ['error', 'warning'].includes(kind)) $(document).trigger('smartsync:notice', [{ message: String(message), type: kind, key: 'cloud-setup' }]);
    }

    function initializeProviderWizard() {
        if (!$('#aws-wizard').length) return;
        let buckets = [];
        let listingGeneration = 0;
        let saving = false;
        let busy = '';
        let operationButton = $();
        let choiceLabel = '';
        let discoveryReady = false;
        let manualFallbackAvailable = false;
        let syncBlocked = false;
        let syncStatusKnown = false;
        let syncDeferred = false;
        let syncTimer;
        let syncGeneration = 0;
        let syncRequest = null;
        let syncRequestGeneration = -1;
        let syncQueued = false;
        let syncFailures = 0;
        let syncPageActive = true;
        let savedControls = [];
        let currentStep = 1;
        let previousProvider = $('#service_provider').val();
        const credentialDrafts = {};
        let setup = {};
        try { setup = JSON.parse($('#smartsync-provider-guides').text() || '{}'); } catch (_) { /* Older cached markup can still use the existing flow. */ }
        const guide = () => (setup.providers || {})[provider()] || {};
        const ui = (key, fallback) => (setup.labels || {})[key] || fallback;

        function invalidField(selector, message) {
            $('#aws-wizard [aria-invalid]').removeAttr('aria-invalid aria-errormessage');
            $(selector).attr({ 'aria-invalid': 'true', 'aria-errormessage': 'wizard-message' }).trigger('focus');
            showMessage(message);
            return false;
        }

        function updateSetupGuide() {
            $('#setup-guide-title').text(ui('guideAccountTitle', 'Get your account ready'));
            $('#setup-guide-eyebrow').text(ui('guideAccountEyebrow', 'BEFORE YOU CONNECT'));
            $('.setup-provider-guide').each(function () {
                $(this).prop('hidden', $(this).attr('data-provider') !== provider());
            });
        }

        function updateFlow() {
            updateSetupGuide();
            $('#setup-manual-continue').prop('hidden', !manualFallbackAvailable || currentStep !== 1).prop('disabled', !!busy || syncBlocked);
            const steps = [1, 2, 'final'];
            $('.smartsync-cloud-setup').css('--setup-step-count', String(steps.length));
            const position = Math.max(0, steps.indexOf(currentStep));
            $('.stepper .step').removeClass('active completed').removeAttr('aria-current');
            steps.forEach(function (step, index) {
                const item = $('#step-' + step + '-indicator');
                item.toggleClass('active', index === position).toggleClass('completed', index < position);
                item.find('.step-icon span').text(index < position ? '✓' : String(index + 1));
                if (index === position) item.attr('aria-current', 'step');
                const available = step === 1 || (step === 2 && discoveryReady);
                item.find('button').prop('disabled', !!busy || syncBlocked || currentStep === 'final' || !available)
                    .attr('aria-current', index === position ? 'step' : null);
            });
            $('.setup-progress-caption').text(ui('progress', 'Step {current} of {total}').replace('{current}', String(position + 1)).replace('{total}', String(steps.length)));
            $('#step-1-next .setup-button-label').text(guide().connectLabel || text('loading', 'Connect account'));
            $('#step-2-next .setup-button-label').text(ui('save', 'Save connection'));
            $('#refresh-buckets').text(ui('refresh', 'Refresh list'));
            if (busy) {
                const label = busy === 'saving' ? ui('saving', 'Saving connection…') :
                    operationButton.is('#refresh-buckets') ? ui('refreshing', 'Refreshing storage…') : guide().connecting || text('loading_buckets');
                const target = operationButton.find('.setup-button-label, .setup-choice-action');
                if (target.length) target.text(label); else operationButton.text(label);
            }
        }

        const awsRegions = [
            'us-east-1', 'us-east-2', 'us-west-1', 'us-west-2', 'ca-central-1',
            'eu-west-1', 'eu-west-2', 'eu-west-3', 'eu-central-1', 'eu-south-1',
            'eu-south-2', 'eu-north-1', 'me-south-1', 'me-central-1', 'af-south-1',
            'ap-northeast-1', 'ap-northeast-2', 'ap-northeast-3', 'ap-south-1',
            'ap-south-2', 'ap-southeast-1', 'ap-southeast-2', 'sa-east-1',
            'cn-north-1', 'cn-northwest-1', 'us-gov-east-1', 'us-gov-west-1'
        ];
        const digitalOceanRegions = ['nyc1', 'nyc2', 'nyc3', 'ams3', 'sfo2', 'sfo3', 'sgp1', 'lon1', 'fra1', 'tor1'];

        function provider() { return $('#service_provider').val(); }
        function supportsManualBucket() { return ['aws', 'digitalocean', 'wasabi'].includes(provider()); }

        function selectedBucket() {
            const value = $('#aws_bucket_list').val();
            return value !== null && value !== '' ? buckets[Number(value)] : null;
        }

        function fillPullZones() {
            const bucket = selectedBucket();
            const zones = bucket && Array.isArray(bucket.pullzones) ? bucket.pullzones : [];
            const select = $('#pull-zones').empty();
            zones.forEach(function (zone) {
                if (typeof zone === 'string' && zone) select.append($('<option>').val(zone).text(zone));
            });
            if (!select.children().length) select.append($('<option>').val('').text(text('select_pull_zone', 'Select a pull zone.')));
            const issue = bucket && typeof bucket.issue === 'string' ? bucket.issue : '';
            $('#bunny-pull-zone-help').text(issue).prop('hidden', !issue);
            updateFlow();
        }

        function chooseBucketType() {
            const existing = $('input[name="bucket_type"]:checked').val() !== 'new';
            const manual = existing && supportsManualBucket() && $('#manual-bucket-entry').prop('checked');
            $('#existing-bucket-options').toggleClass('d-none', !existing || manual);
            $('#manual-bucket-selector').toggleClass('d-none', !existing || !supportsManualBucket());
            $('#manual-bucket-fields').toggleClass('d-none', !manual);
            $('#manual-bucket-region-field').toggleClass('d-none', false);
            $('#new-bucket-options').toggleClass('d-none', existing);
            $('#pull-zones-container').toggleClass('d-none', provider() !== 'bunny' || !existing);
            $('#storage-empty').prop('hidden', !existing || manual || buckets.length > 0);
            updateFlow();
        }

        function showStep(step) {
            currentStep = step;
            showMessage('');
            $('.setup-guide').prop('hidden', step === 'final');
            $('.setup-workspace').toggleClass('setup-complete', step === 'final');
            $('.wizard-step').addClass('d-none').prop('hidden', true);
            const panel = $('#wizard-step-' + step).removeClass('d-none').prop('hidden', false);
            updateFlow();
            panel.find('h2').attr('tabindex', '-1').trigger('focus');
        }

        function invalidateDiscovery() {
            if (saving || syncBlocked) return;
            listingGeneration++;
            if (busy) restoreControls();
            buckets = [];
            discoveryReady = false;
            manualFallbackAvailable = false;
            $('#aws_bucket_list, #pull-zones').empty();
            $('#bunny-pull-zone-help').text('').prop('hidden', true);
            $('#aws-wizard [aria-invalid]').removeAttr('aria-invalid aria-errormessage');
            $('#aws-wizard').attr('aria-busy', 'false');
            updateFlow();
            showMessage('');
        }

        function restoreControls() {
            savedControls.forEach(function (item) { $(item.element).prop('disabled', item.disabled); });
            savedControls = [];
            if (choiceLabel) operationButton.find('.setup-choice-action').text(choiceLabel);
            operationButton.removeAttr('data-busy');
            operationButton = $();
            choiceLabel = '';
            busy = '';
            $('#setup-operation-status').text('');
            $('#aws-wizard').attr('aria-busy', 'false');
            $('#aws-wizard').attr('data-saving', 'false');
            updateFlow();
            if (syncDeferred && syncPageActive) {
                window.clearTimeout(syncTimer);
                syncTimer = window.setTimeout(checkSetupSync, 1000);
            }
        }

        function beginOperation(kind, button) {
            if (busy || syncBlocked || currentStep === 'final') return false;

            busy = kind;
            operationButton = button;
            choiceLabel = button.find('.setup-choice-action').text();
            savedControls = $('#aws-wizard :input, #wizard-stepper button').toArray().map(function (element) {
                return { element: element, disabled: element.disabled };
            });
            $('#aws-wizard :input, #wizard-stepper button').prop('disabled', true);
            $('#aws-wizard').attr({ 'aria-busy': 'true', 'data-saving': String(kind === 'saving') });
            button.attr('data-busy', 'true');
            showMessage('');
            updateFlow();
            $('#setup-operation-status').text(button.text().trim());
            return true;
        }

        function setSyncBlocked(blocked) {
            syncBlocked = blocked;
            $('#setup-controls').prop('disabled', blocked);
            $('#setup-sync-lock').prop('hidden', !blocked);
            updateFlow();
        }

        function checkSetupSync() {
            if (!syncPageActive || document.hidden || navigator.onLine === false) return;
            window.clearTimeout(syncTimer);
            if (busy) { syncDeferred = true; return; }
            syncDeferred = false;
            if (syncRequest) { if (syncRequestGeneration !== syncGeneration) syncQueued = true; return; }
            const token = syncGeneration;
            let next = 0;
            function failed(response) {
                syncStatusKnown = false;
                syncFailures++;
                $(document).trigger('smartsync:status-error');
                if (response && (response.status === 401 || response.status === 403) || syncFailures >= 5) {
                    showMessage(text('status_polling_paused', 'Automatic status checks are paused. Reload this page to check again.'), 'error');
                } else next = 5000 * Math.pow(2, syncFailures - 1);
            }
            const xhr = $.ajax({ url: config.ajax_url, method: 'POST', timeout: statusTimeout, data: { action: 'check_sync_status', nonce: config.nonce, summary: '1' } });
            syncRequest = xhr;
            syncRequestGeneration = token;
            xhr.done(function (response) {
                if (token !== syncGeneration) return;
                const status = response && response.success && response.data && response.data.status;
                if (['idle', 'processing', 'completed', 'failed'].includes(status)) {
                    syncStatusKnown = true;
                    syncFailures = 0;
                    setSyncBlocked(status === 'processing' || response.data.operation_busy === true);
                    $(document).trigger('smartsync:status', [response.data]);
                    if (syncBlocked) next = 5000;
                } else failed();
            }).fail(function (response) {
                if (token === syncGeneration) failed(response);
            }).always(function () {
                if (syncRequest !== xhr) return;
                syncRequest = null;
                if (syncQueued) { syncQueued = false; checkSetupSync(); return; }
                if (token === syncGeneration && syncPageActive && !document.hidden && navigator.onLine !== false && next) syncTimer = window.setTimeout(checkSetupSync, next);
            });
        }

        function updateProvider() {
            manualFallbackAvailable = false;
            if (busy || syncBlocked) { $('#service_provider').val(previousProvider); return; }

            listingGeneration++;
            buckets = [];
            discoveryReady = false;
            $('#aws_bucket_list, #pull-zones').empty();
            $('#bunny-pull-zone-help').text('').prop('hidden', true);
            $('#step-1-next').prop('disabled', false);
            $('#aws-wizard').attr('aria-busy', 'false');
            const value = provider();
            if (value !== previousProvider) {
                credentialDrafts[previousProvider] = { key: $('#aws_iam_key').val(), secret: $('#aws_iam_secret').val() };
                const draft = credentialDrafts[value] || { key: '', secret: '' };
                $('#aws_iam_key').val(draft.key);
                $('#aws_iam_secret').val(draft.secret);
                previousProvider = value;
            }
            $('[data-setup-copy]').each(function () {
                const copy = guide()[$(this).attr('data-setup-copy')];
                if (typeof copy === 'string') $(this).text(copy);
            });
            $('[data-setup-placeholder]').each(function () {
                const example = guide()[$(this).attr('data-setup-placeholder')];
                if (typeof example === 'string') $(this).attr('placeholder', example);
            });
            $('#new-bucket-help').attr('data-warning', value === 'aws' ? 'true' : null);
            $('.setup-secret input').attr('type', 'password');
            $('.setup-reveal').attr('aria-pressed', 'false').text(ui('show', 'Show'));
            $('#aws-wizard [aria-invalid]').removeAttr('aria-invalid aria-errormessage');
            $('#manual-bucket-entry').prop('checked', false);
            const savedRegion = $('#manual_bucket_region').attr('data-saved-provider') === value ? $('#manual_bucket_region').attr('data-saved-region') : '';
            $('#manual_bucket_region').val(savedRegion || (value === 'digitalocean' ? 'nyc3' : 'us-east-1'));
            $('.config-inputs').addClass('d-none');
            if (['aws', 'digitalocean', 'wasabi'].includes(value)) $('.aws-sdk-inputs').removeClass('d-none');
            if (value === 'bunny') $('.bunny-inputs').removeClass('d-none');
            $('#bucket-type-selector').toggleClass('d-none', value === 'bunny').prop('hidden', value === 'bunny');
            $('#region-selector').toggleClass('d-none', !['aws', 'digitalocean'].includes(value));
            $('#wasabi-region-selector').toggleClass('d-none', value !== 'wasabi');
            $('#aws-discovery-region-selector').toggleClass('d-none', value !== 'aws');
            $('input[name="bucket_type"][value="existing"]').prop('checked', true);
            const regions = value === 'digitalocean' ? digitalOceanRegions : awsRegions;
            const regionSelect = $('#new_bucket_region').empty();
            regions.forEach(function (code) {
                const key = (value === 'digitalocean' ? 'digitalocean_region_' : 'aws_region_') + code.replace(/-/g, '_');
                regionSelect.append($('<option>').val(code).text(strings[key] || code));
            });
            chooseBucketType();
            showMessage('');
            updateFlow();
        }

        function credentialsValid() {
            const value = provider();
            if (!providers.includes(value)) { showMessage(text('invalid_provider', 'Select a Lite storage provider.')); return false; }
            if (value === 'bunny') {
                if (!$('#bunny-api-key').val().trim()) return invalidField('#bunny-api-key', guide().credentialsRequired || text('iam_required'));
            } else if (!$('#aws_iam_key').val().trim() || !$('#aws_iam_secret').val()) {
                return invalidField(!$('#aws_iam_key').val().trim() ? '#aws_iam_key' : '#aws_iam_secret', guide().credentialsRequired || text('iam_required'));
            }
            if (value === 'wasabi' && !/^[a-zA-Z0-9-]+$/.test($('#wasabi_bucket_region').val().trim())) return invalidField('#wasabi_bucket_region', ui('regionRequired', 'Enter the bucket region.'));
            if (value === 'aws' && $('#aws_discovery_region').val().trim() && !/^[a-z]{2}(?:-[a-z]+)+-\d+$/.test($('#aws_discovery_region').val().trim())) return invalidField('#aws_discovery_region', ui('regionRequired', 'Enter the bucket region.'));
            return true;
        }
        function loadBuckets(button) {
            manualFallbackAvailable = false;
            const value = provider();
            const previousBucket = selectedBucket();
            const previousZone = $('#pull-zones').val();
            if (!beginOperation('listing', button)) return;
            const token = ++listingGeneration;
            function listingFailed(response, fallback) {
                buckets = [];
                discoveryReady = false;
                manualFallbackAvailable = supportsManualBucket();
                $('#aws_bucket_list').empty().append($('<option>').val('').text(text('no_buckets', 'No buckets available.')));
                $('#pull-zones').empty();
                showStep(1);
                showMessage(errorMessage(response, fallback));
            }
            const form = new FormData();
            form.append('action', 'list_s3_buckets');
            form.append('nonce', config.nonce);
            form.append('service_provider', value);
            form.append('iam_key', $('#aws_iam_key').val().trim());
            form.append('iam_secret', value === 'bunny' ? $('#bunny-api-key').val().trim() : $('#aws_iam_secret').val());
            if (value === 'wasabi') form.append('bucket_region', $('#wasabi_bucket_region').val().trim());
            if (value === 'aws' && $('#aws_discovery_region').val().trim()) form.append('bucket_region', $('#aws_discovery_region').val().trim());
            $.ajax({ url: config.ajax_url, method: 'POST', data: form, processData: false, contentType: false }).done(function (response) {
                if (token !== listingGeneration) return;
                if (!response || !response.success || !response.data || !Array.isArray(response.data.buckets)) {
                    listingFailed(response, text('load_buckets_failed'));
                    return;
                }
                const validBuckets = response.data.buckets.every(function (bucket) {
                    return bucket && typeof bucket === 'object' && typeof bucket.name === 'string' && bucket.name &&
                        (bucket.region === undefined || (typeof bucket.region === 'string' && /^[a-zA-Z0-9-]*$/.test(bucket.region)));
                });
                if (!validBuckets) {
                    listingFailed(null, text('invalid_bucket_response', 'The bucket response is invalid.'));
                    return;
                }
                buckets = response.data.buckets;
                discoveryReady = true;
                const select = $('#aws_bucket_list').empty();
                buckets.forEach(function (bucket, index) {
                    // Keep provider data out of HTML and option values, especially credentials.
                    select.append($('<option>').val(String(index)).text(bucket.name + (bucket.region ? ' | ' + bucket.region : '')));
                });
                if (!buckets.length) select.append($('<option>').val('').text(text('no_buckets', 'No buckets available.')));
                const previousIndex = previousBucket ? buckets.findIndex(bucket => bucket.name === previousBucket.name && bucket.region === previousBucket.region) : -1;
                if (previousIndex >= 0) select.val(String(previousIndex));
                fillPullZones();
                if (previousIndex >= 0 && selectedBucket() && (selectedBucket().pullzones || []).includes(previousZone)) $('#pull-zones').val(previousZone);
                chooseBucketType();
                showMessage('');
                showStep(2);
            }).fail(function (xhr) {
                if (token !== listingGeneration) return;
                listingFailed(xhr.responseJSON, text('load_buckets_error'));
            }).always(function () {
                if (token === listingGeneration) {
                    restoreControls();
                }
            });
        }

        function configurationData() {
            if (!credentialsValid()) return null;
            const value = provider();
            const bucketType = $('input[name="bucket_type"]:checked').val();
            if (!['existing', 'new'].includes(bucketType) || (value === 'bunny' && bucketType !== 'existing')) return null;
            const selected = selectedBucket();
            const manual = bucketType === 'existing' && supportsManualBucket() && $('#manual-bucket-entry').prop('checked');
            const name = bucketType === 'new' ? $('#new_bucket_name').val().trim() : manual ? $('#manual_bucket_name').val().trim() : selected && selected.name;
            let region = bucketType === 'new' ? $('#new_bucket_region').val() : manual ? $('#manual_bucket_region').val().trim() : selected && selected.region;
            if (!name) { invalidField(bucketType === 'new' ? '#new_bucket_name' : manual ? '#manual_bucket_name' : '#aws_bucket_list', text('bucket_required', 'Choose or enter storage before continuing.')); return null; }
            const pattern = value === 'bunny' ? /^[a-zA-Z0-9-]{2,100}$/ : /^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/;
            if (!pattern.test(name) || name.includes('..') || name.includes('.-') || name.includes('-.') || /^\d+\.\d+\.\d+\.\d+$/.test(name)) { invalidField(bucketType === 'new' ? '#new_bucket_name' : '#manual_bucket_name', text('invalid_bucket_name', 'Enter a valid bucket name.')); return null; }
            if (value === 'wasabi' && bucketType === 'new') region = $('#wasabi_bucket_region').val().trim();
            if (!region || !/^[a-zA-Z0-9-]+$/.test(region)) { invalidField(manual ? '#manual_bucket_region' : '#aws_bucket_list', ui('regionRequired', 'Enter the bucket region.')); return null; }
            const pullZone = $('#pull-zones').val();
            if (value === 'bunny' && !pullZone) { invalidField('#pull-zones', text('select_pull_zone', 'Choose a connected pull zone.')); return null; }
            return { service_provider: value, bucket_name: name, bucket_region: region, bucket_type: bucketType,
                iam_key: value === 'bunny' ? pullZone : $('#aws_iam_key').val().trim(),
                iam_secret: value === 'bunny' ? $('#bunny-api-key').val().trim() : $('#aws_iam_secret').val() };
        }
        function saveConfiguration(button) {
            if (busy || syncBlocked) return;
            const data = configurationData();
            if (!data) return;
            if (!beginOperation('saving', button)) return;
            saving = true;
            const token = listingGeneration;
            let bucketNameRejected = false;
            function saveFailed(response, fallback) {
                const details = response && response.data;
                bucketNameRejected = data.bucket_type === 'new' && details && ['BucketAlreadyExists', 'BucketAlreadyOwnedByYou'].includes(details.code);
                if (bucketNameRejected) {
                    showStep(2);
                    $('#new_bucket_name').attr({ 'aria-invalid': 'true', 'aria-errormessage': 'wizard-message' });
                }
                showMessage(errorMessage(response, fallback));
            }
            request('configure_aws', data).done(function (response) {
                if (token !== listingGeneration) return;
                if (response && response.success) {
                    $('#setup-summary-provider').text(guide().name || data.service_provider);
                    $('#setup-summary-storage').text(data.bucket_name);
                    $('#setup-summary-region').text(data.bucket_region);
                    $('#setup-summary-delivery').text(data.service_provider === 'bunny' ? data.iam_key : ui('directDelivery', 'Direct storage delivery'));
                    $('#setup-active-provider').text(guide().name || data.service_provider);
                    $('#setup-active-storage').text(data.bucket_name);
                    $('.setup-connection-context').prop('hidden', false);
                    showStep('final');
                } else {
                    saveFailed(response, text('configuration_failed'));
                }
            }).fail(function (xhr) {
                if (token !== listingGeneration) return;
                saveFailed(xhr.responseJSON, text('configuration_error', 'Could not save the configuration.'));
            }).always(function () {
                if (token !== listingGeneration) return;
                saving = false;
                restoreControls();
                if (bucketNameRejected) $('#new_bucket_name').trigger('focus');
            });
        }

        $('#service_provider').on('change', updateProvider);
        $('#new_bucket_name').on('input', function () {
            $(this).removeAttr('aria-invalid aria-errormessage');
            showMessage('');
        });
        $('.setup-reveal').on('click', function () {
            const input = $('#' + $(this).attr('data-target'));
            const reveal = input.attr('type') === 'password';
            input.attr('type', reveal ? 'text' : 'password');
            $(this).attr('aria-pressed', String(reveal)).text(reveal ? ui('hide', 'Hide') : ui('show', 'Show'));
        });
        $('#refresh-buckets').on('click', function () { if (!busy && !syncBlocked && credentialsValid()) loadBuckets($(this)); });
        $('#wizard-step-1 input:not([type="file"])').on('keydown', function (event) {
            if (event.key === 'Enter' && !$('#step-1-next').prop('disabled')) { event.preventDefault(); $('#step-1-next').trigger('click'); }
        });
        $('#new_bucket_region, #manual_bucket_region').on('input change', updateFlow);
        $('#aws_iam_key, #aws_iam_secret, #bunny-api-key, #wasabi_bucket_region, #aws_discovery_region').on('input change', invalidateDiscovery);
        $('input[name="bucket_type"]').on('change', chooseBucketType);
        $('#manual-bucket-entry').on('change', chooseBucketType);
        $('#aws_bucket_list').on('change', fillPullZones);
        $('#step-1-next').on('click', function () {
            if (busy || syncBlocked) return;
            showMessage('');
            if (credentialsValid()) loadBuckets($(this));
        });
        $('#setup-manual-continue').on('click', function () {
            if (busy || syncBlocked || !manualFallbackAvailable || !supportsManualBucket() || !credentialsValid()) return;
            manualFallbackAvailable = false;
            discoveryReady = true;
            $('input[name="bucket_type"][value="existing"]').prop('checked', true);
            $('#manual-bucket-entry').prop('checked', true);
            chooseBucketType();
            showStep(2);
        });
        $('#step-2-next').on('click', function () {
            if (busy || syncBlocked) return;
            showMessage('');
            const data = configurationData();
            if (!data) return;
            $('.setup-selected-storage').text(data.bucket_name + ' · ' + data.bucket_region);
            saveConfiguration($(this));
        });
        $('#step-2-back').on('click', function () { if (!busy && !syncBlocked) showStep(1); });
        $('.setup-step-link').on('click', function () {
            if (this.disabled || busy || syncBlocked || currentStep === 'final') return;
            const step = Number($(this).attr('data-setup-step'));
            if (step === 1 || (step === 2 && discoveryReady)) showStep(step);
        });
        $(window).on('pagehide', function () { listingGeneration++; syncGeneration++; syncPageActive = false; syncQueued = false; window.clearTimeout(syncTimer); if (syncRequest && syncRequest.abort) syncRequest.abort();  });
        $(document).on('visibilitychange', function () { if (document.hidden)  window.clearTimeout(syncTimer); if (!document.hidden && (!syncStatusKnown || syncBlocked)) { syncFailures = 0; checkSetupSync(); } });
        $(window).on('offline', function () { window.clearTimeout(syncTimer); });
        $(window).on('online focus', function (event) {
            if (event.target !== window || (syncStatusKnown && !syncBlocked)) return;
            syncFailures = 0; checkSetupSync();
        });
        $(document).on('smartsync:refresh-status', function () { syncStatusKnown = false; syncFailures = 0; checkSetupSync(); });
        $(window).on('pageshow', function (event) {
            if (!(event.originalEvent && event.originalEvent.persisted)) return;
            syncPageActive = true;
            saving = false;
            restoreControls();
            invalidateDiscovery();
            showStep(1);
            checkSetupSync();
        });
        updateProvider();
        $(document).on('smartsync:before-stop', function () { syncGeneration++; window.clearTimeout(syncTimer); });
        $(document).on('smartsync:stop-finished', checkSetupSync);
        $(document).on('smartsync:status', function (_, status) { if (status && status.status === 'idle') setSyncBlocked(status.operation_busy === true); });
        setSyncBlocked(setup.syncRunning === true);
        syncTimer = window.setTimeout(checkSetupSync, 5000);
    }

    initializeProviderWizard();
});
