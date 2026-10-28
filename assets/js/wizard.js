jQuery(function ($) {
    'use strict';

    const config = window.ajax_object;
    if (!config) return;
    const strings = config.strings || {};
    const statusTimeout = Number(config.status_timeout_ms) === 180000 ? 180000 : 15000;
    const text = (key, fallback) => strings[key] || fallback || key;
    const providers = ['aws', 'digitalocean', 'wasabi', 'google', 'cloudflare', 'bunny'];

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
        let deliveryVisited = false;
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
        let googleSelectionToken = '';
        let googleFile = null;
        let googleDocument = null;
        let googleReader = null;
        let googleReadVersion = 0;
        let googleFileState = 'empty';
        let googleFileError = '';
        let googleRevealed = false;
        let googleSavedRequest = null;
        let googleSavedGeneration = 0;
        let googleSavedRevealed = false;
        let googleSavedPreview = {};
        try { googleSavedPreview = JSON.parse($('#google-saved-preview').text() || '{}'); } catch (_) { /* No saved preview on older pages. */ }
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

        function hasDeliveryStep() {
            if (provider() !== 'aws') return false;
            const bucket = selectedBucket();
            const region = $('input[name="bucket_type"]:checked').val() === 'new' ? $('#new_bucket_region').val() :
                $('#manual-bucket-entry').prop('checked') ? $('#manual_bucket_region').val() : bucket && bucket.region;
            return !/^(cn-|us-gov-)/.test(region || 'us-east-1');
        }

        function updateSetupGuide() {
            const delivery = provider() === 'aws' && currentStep === 3;
            const stage = delivery ? 'delivery' : 'account';
            const content = delivery ? guide().deliveryGuide || {} : {};
            $('#setup-guide-title').text(content.title || ui('guideAccountTitle', 'Get your account ready'));
            $('#setup-guide-eyebrow').text(content.eyebrow || ui('guideAccountEyebrow', 'BEFORE YOU CONNECT'));
            $('.setup-provider-guide').each(function () {
                $(this).prop('hidden', $(this).attr('data-provider') !== provider() || ($(this).attr('data-guide-stage') || 'account') !== stage);
            });
        }

        function updateFlow() {
            updateSetupGuide();
            $('#setup-manual-continue').prop('hidden', !manualFallbackAvailable || currentStep !== 1).prop('disabled', !!busy || syncBlocked);
            const steps = hasDeliveryStep() ? [1, 2, 3, 'final'] : [1, 2, 'final'];
            $('#step-3-indicator').prop('hidden', !steps.includes(3));
            $('.smartsync-cloud-setup').css('--setup-step-count', String(steps.length));
            const position = Math.max(0, steps.indexOf(currentStep));
            $('.stepper .step').removeClass('active completed').removeAttr('aria-current');
            steps.forEach(function (step, index) {
                const item = $('#step-' + step + '-indicator');
                item.toggleClass('active', index === position).toggleClass('completed', index < position);
                item.find('.step-icon span').text(index < position ? '✓' : String(index + 1));
                if (index === position) item.attr('aria-current', 'step');
                const available = step === 1 || (step === 2 && discoveryReady) || (step === 3 && discoveryReady && deliveryVisited);
                item.find('button').prop('disabled', !!busy || syncBlocked || currentStep === 'final' || !available)
                    .attr('aria-current', index === position ? 'step' : null);
            });
            $('.setup-progress-caption').text(ui('progress', 'Step {current} of {total}').replace('{current}', String(position + 1)).replace('{total}', String(steps.length)));
            $('#step-1-next .setup-button-label').text(guide().connectLabel || text('loading', 'Connect account'));
            $('#step-2-next .setup-button-label').text(hasDeliveryStep() ? ui('reviewDelivery', 'Review delivery options') : ui('save', 'Save connection'));
            $('#refresh-buckets').text(ui('refresh', 'Refresh list'));
            if (busy) {
                const label = busy === 'saving' ? ui('saving', 'Saving connection…') :
                    operationButton.is('#refresh-buckets') ? ui('refreshing', 'Refreshing storage…') : guide().connecting || text('loading_buckets');
                const target = operationButton.find('.setup-button-label, .setup-choice-action');
                if (target.length) target.text(label); else operationButton.text(label);
            }
            if (!busy && provider() === 'google') $('#step-1-next').prop('disabled', googleFileState === 'reading');
        }

        function renderGooglePreview() {
            googleRevealed = !!googleDocument && googleRevealed;
            $('#google-json-reveal').attr('aria-pressed', String(googleRevealed)).text(googleRevealed ? ui('googleHideValues', 'Hide sensitive values') : ui('googleShowValues', 'Show sensitive values'));
            $('#google-json-note').text(googleRevealed ? ui('googleRevealedNote', 'Sensitive values are visible. Hide them before sharing your screen.') : ui('googlePrivateNote', 'Sensitive values are hidden. This preview stays in your browser.'));
            if (!googleDocument) { $('#google-json-code').text(''); return; }
            const publicFields = ['type', 'project_id', 'client_email', 'auth_uri', 'token_uri', 'auth_provider_x509_cert_url', 'client_x509_cert_url', 'universe_domain'];
            const preview = Object.create(null);
            Object.keys(googleDocument).forEach(function (key) {
                preview[key] = publicFields.includes(key) && typeof googleDocument[key] === 'string' ? googleDocument[key] : ui('googleHidden', '[Hidden]');
            });
            try { $('#google-json-code').text(JSON.stringify(googleRevealed ? googleDocument : preview, null, 2)); }
            catch (_) { $('#google-json-code').text(ui('googlePreviewFailed', 'This JSON is too deeply nested to preview.')); }
        }

        function maskGoogleSaved() {
            googleSavedGeneration++;
            const pending = googleSavedRequest;
            googleSavedRequest = null;
            if (pending && pending.abort) pending.abort();
            googleSavedRevealed = false;
            $('#google-saved-code').text(JSON.stringify(googleSavedPreview, null, 2));
            $('#google-saved-reveal').prop('disabled', !!busy || syncBlocked).removeAttr('aria-busy')
                .attr('aria-pressed', 'false').text(ui('googleShowValues', 'Show sensitive values'));
            $('#google-saved-note').text(ui('googleSavedPrivateNote', 'Sensitive values are hidden. Reveal them only when needed.'));
        }

        function maskGooglePreview() { googleRevealed = false; renderGooglePreview(); maskGoogleSaved(); }
        function isGoogleP12() { return !!googleFile && /\.p12$/i.test(googleFile.name); }

        function googleUploadError(message, focus) {
            googleFileError = message;
            $('#google-file-error').text(message).prop('hidden', !message);
            $('#service-json, #google-file-browse, #google-file-replace').attr({ 'aria-invalid': 'true', 'aria-errormessage': 'google-file-error' });
            if (message) $(document).trigger('smartsync:notice', [{ message: String(message), type: 'warning', key: 'cloud-setup' }]);
            if (focus) $(googleFile ? '#google-file-replace' : '#google-file-browse').trigger('focus');
            return false;
        }

        function selectGoogleFile(file, focus, force) {
            if (!force && (saving || syncBlocked)) return;
            const version = ++googleReadVersion;
            if (googleReader) googleReader.abort();
            googleReader = null;
            invalidateDiscovery();
            googleFile = file || null;
            const saved = $('#service-json').attr('data-has-credentials') === '1' && !file;
            $('#google-saved-account').prop('hidden', !saved);
            $('#google-upload, #google-key-help').prop('hidden', saved);
            googleDocument = null;
            googleFileError = '';
            googleFileState = file ? 'reading' : 'empty';
            $('#google-upload').attr('data-state', googleFileState).removeClass('is-dragover');
            $('#google-file-error').text('').prop('hidden', true);
            $('#google-file-preview').prop('hidden', true);
            $('#google-file-card').prop('hidden', !file);
            $('#google-file-dropzone').prop('hidden', !!file);
            $('#google-file-name').text(file ? file.name : '').attr('title', file ? file.name : '');
            $('#google-file-meta').text(file ? (file.size < 1024 ? file.size + ' B' : (file.size / 1024).toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' KB') : '');
            $('#google-file-status').text(file ? ui('googleReading', 'Reading JSON…') : '');
            $('#google-file-project, #google-file-account').text('');
            $('#google-p12-fields').prop('hidden', !isGoogleP12());
            $('#google-p12-email, #google-p12-project, #google-p12-password').val('').removeAttr('aria-invalid aria-errormessage');
            $('.setup-upload-filetype').text(isGoogleP12() ? 'P12' : '{ }');
            maskGooglePreview();
            updateFlow();
            if (!file) { $('#service-json').val(''); if (focus && currentStep === 1) $('#google-file-browse').trigger('focus'); return; }

            function failed(message) {
                if (version !== googleReadVersion) return;
                googleReader = null;
                googleFileState = 'error';
                $('#google-upload').attr('data-state', 'error');
                $('#google-file-status').text('');
                googleUploadError(message, focus && provider() === 'google' && currentStep === 1);
                updateFlow();
            }
            if (file.size > 1024 * 1024) { failed(ui('googleTooLarge', 'Choose a JSON or P12 file no larger than 1 MB.')); return; }
            if (!/\.(json|p12)$/i.test(file.name)) { failed(ui('googleInvalidFile', 'Choose a service-account file with a .json or .p12 extension.')); return; }
            if (isGoogleP12()) {
                googleFileState = 'ready';
                $('#google-upload').attr('data-state', 'ready');
                $('#google-file-status').text(ui('googleP12Selected', 'P12 selected · enter the account details below'));
                updateFlow();
                if (focus && provider() === 'google' && currentStep === 1 && !busy && !syncBlocked) $('#google-p12-email').trigger('focus');
                return;
            }
            const reader = new FileReader();
            googleReader = reader;
            reader.onerror = function () { failed(ui('googleReadFailed', 'This file could not be read. Choose it again or try another JSON key.')); };
            reader.onload = function () {
                if (version !== googleReadVersion) return;
                let data;
                try { data = JSON.parse(String(reader.result).replace(/^\uFEFF/, '')); }
                catch (_) { failed(ui('googleInvalidJson', 'This file contains invalid JSON. Choose the original service-account key downloaded from Google Cloud.')); return; }
                if (!data || typeof data !== 'object' || Array.isArray(data) || data.type !== 'service_account' ||
                    typeof data.project_id !== 'string' || !data.project_id.trim() ||
                    typeof data.client_email !== 'string' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.client_email) ||
                    typeof data.private_key !== 'string' || !data.private_key.includes('-----BEGIN PRIVATE KEY-----') ||
                    !['https://oauth2.googleapis.com/token', 'https://accounts.google.com/o/oauth2/token'].includes(data.token_uri)) {
                    failed(ui('googleInvalidKey', 'This is not a service-account key. Choose a JSON key with a project ID, service-account email, private key and Google token URL.'));
                    return;
                }
                googleReader = null;
                googleDocument = data;
                googleFileState = 'ready';
                $('#google-upload').attr('data-state', 'ready');
                $('#google-file-status').text(ui('googleReady', 'Ready to connect'));
                $('#google-file-project').text(data.project_id);
                $('#google-file-account').text(data.client_email);
                $('#google-file-preview').prop('hidden', false);
                renderGooglePreview();
                updateFlow();
                if (focus && provider() === 'google' && currentStep === 1 && !busy && !syncBlocked) $('#google-file-replace').trigger('focus');
            };
            reader.readAsText(file);
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
        function supportsManualBucket() { return ['aws', 'digitalocean', 'wasabi', 'cloudflare'].includes(provider()); }

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
            $('#manual-bucket-region-field').toggleClass('d-none', provider() === 'cloudflare');
            $('#new-bucket-options').toggleClass('d-none', existing);
            $('#pull-zones-container').toggleClass('d-none', provider() !== 'bunny' || !existing);
            $('#storage-empty').prop('hidden', !existing || manual || buckets.length > 0);
            updateFlow();
        }

        function showStep(step) {
            currentStep = step;
            if (step !== 1) maskGooglePreview();
            if (step === 3) deliveryVisited = true;
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
            discoveryReady = deliveryVisited = false;
            manualFallbackAvailable = false;
            googleSelectionToken = '';
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
            maskGooglePreview();
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
            if (blocked) maskGooglePreview();
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
            maskGooglePreview();
            listingGeneration++;
            buckets = [];
            discoveryReady = deliveryVisited = false;
            googleSelectionToken = '';
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
            $('#manual_bucket_region').val(savedRegion || (value === 'digitalocean' ? 'nyc3' : value === 'cloudflare' ? 'auto' : 'us-east-1'));
            $('.config-inputs').addClass('d-none');
            if (['aws', 'digitalocean', 'wasabi', 'cloudflare'].includes(value)) $('.aws-sdk-inputs').removeClass('d-none');
            if (value === 'google') $('.google-inputs').removeClass('d-none');
            if (value === 'cloudflare') $('.cloudflare-inputs').removeClass('d-none');
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
            if (!providers.includes(value)) { showMessage(text('invalid_provider', 'Select a supported provider.')); return false; }
            if (value === 'google') {
                if (googleFileState === 'reading') return false;
                if (googleFileState === 'error') return googleUploadError(googleFileError, true);
                if (!googleFile && $('#service-json').attr('data-has-credentials') !== '1') return googleUploadError(text('select_service_json', 'Choose a service-account JSON or P12 file.'), true);
                if (isGoogleP12()) {
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test($('#google-p12-email').val().trim())) return invalidField('#google-p12-email', ui('googleP12EmailRequired', 'Enter the service-account email for this P12 key.'));
                    if (!/^[a-z][a-z0-9:._-]{4,126}[a-z0-9]$/.test($('#google-p12-project').val().trim())) return invalidField('#google-p12-project', ui('googleP12ProjectRequired', 'Enter the Google Cloud project ID, not its display name or project number.'));
                }
            } else if (value === 'bunny') {
                if (!$('#bunny-api-key').val().trim()) return invalidField('#bunny-api-key', guide().credentialsRequired || text('iam_required'));
            } else if (!$('#aws_iam_key').val().trim() || !$('#aws_iam_secret').val()) {
                return invalidField(!$('#aws_iam_key').val().trim() ? '#aws_iam_key' : '#aws_iam_secret', guide().credentialsRequired || text('iam_required'));
            }
            if (value === 'cloudflare') {
                if (!$('#cloudflare-endpoint').val().trim()) return invalidField('#cloudflare-endpoint', text('endpoint_required', 'Enter the storage endpoint.'));
                if (!$('#cloudflare-url').val().trim()) return invalidField('#cloudflare-url', text('public_url_required', 'Enter the public CDN URL.'));
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
                googleSelectionToken = '';
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
            if (value === 'google') {
                if (googleFile) form.append('service_json', googleFile);
                if (isGoogleP12()) {
                    form.append('google_p12_email', $('#google-p12-email').val().trim());
                    form.append('google_p12_project', $('#google-p12-project').val().trim());
                    form.append('google_p12_password', $('#google-p12-password').val());
                }
            } else {
                form.append('iam_key', $('#aws_iam_key').val().trim());
                form.append('iam_secret', value === 'bunny' ? $('#bunny-api-key').val().trim() : $('#aws_iam_secret').val());
                if (value === 'cloudflare') form.append('cloudflare_endpoint', $('#cloudflare-endpoint').val().trim());
                if (value === 'wasabi') form.append('bucket_region', $('#wasabi_bucket_region').val().trim());
                if (value === 'aws' && $('#aws_discovery_region').val().trim()) form.append('bucket_region', $('#aws_discovery_region').val().trim());
            }
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
                googleSelectionToken = value === 'google' && typeof response.data.selectionToken === 'string' ? response.data.selectionToken : '';
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

        function configurationData(useCloudFront) {
            if (!credentialsValid()) return null;
            const value = provider();
            if (value === 'google' && googleFile && !googleSelectionToken) {
                showMessage(text('invalid_bucket_response', 'The bucket response is invalid.'));
                return null;
            }
            const bucketType = $('input[name="bucket_type"]:checked').val();
            if (!['existing', 'new'].includes(bucketType) || (value === 'bunny' && bucketType !== 'existing')) return null;
            const selected = selectedBucket();
            const manual = bucketType === 'existing' && supportsManualBucket() && $('#manual-bucket-entry').prop('checked');
            const name = bucketType === 'new' ? $('#new_bucket_name').val().trim() : manual ? $('#manual_bucket_name').val().trim() : selected && selected.name;
            let region = bucketType === 'new' ? $('#new_bucket_region').val() : manual ? $('#manual_bucket_region').val().trim() : selected && selected.region;
            if (!name) { invalidField(bucketType === 'new' ? '#new_bucket_name' : manual ? '#manual_bucket_name' : '#aws_bucket_list', text('bucket_required', 'Choose or enter storage before continuing.')); return null; }
            const namePattern = value === 'google' ? /^[a-z0-9][a-z0-9._-]{1,220}[a-z0-9]$/ : /^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/;
            if ((bucketType === 'new' || manual) && (!namePattern.test(name) || name.includes('..') ||
                (value !== 'google' && (name.includes('.-') || name.includes('-.'))) || /^\d+\.\d+\.\d+\.\d+$/.test(name))) {
                invalidField(bucketType === 'new' ? '#new_bucket_name' : '#manual_bucket_name', text('invalid_bucket_name', 'Enter a valid bucket name.')); return null;
            }
            if (value === 'google') region = bucketType === 'new' ? 'US' : region || 'US';
            if (value === 'cloudflare') region = 'auto';
            if (value === 'wasabi' && bucketType === 'new') region = $('#wasabi_bucket_region').val().trim();
            if (!region || !/^[a-zA-Z0-9-]+$/.test(region)) { invalidField(manual ? '#manual_bucket_region' : bucketType === 'existing' ? '#aws_bucket_list' : '#new_bucket_region', ui('regionRequired', 'Enter the bucket region.')); return null; }
            const pullZone = $('#pull-zones').val();
            if (value === 'bunny' && (!selected || !Array.isArray(selected.pullzones) || !selected.pullzones.includes(pullZone))) {
                invalidField('#pull-zones', text('select_pull_zone', 'Select a pull zone.')); return null;
            }
            return {
                bucket_type: bucketType, bucket_name: name, bucket_region: region,
                service_provider: value, use_cloudfront: value === 'aws' && useCloudFront,
                iam_key: value === 'bunny' ? pullZone : value === 'google' ? '' : $('#aws_iam_key').val().trim(),
                iam_secret: value === 'bunny' ? $('#bunny-api-key').val().trim() : value === 'google' ? '' : $('#aws_iam_secret').val(),
                cloudflare_endpoint: value === 'cloudflare' ? $('#cloudflare-endpoint').val().trim() : '',
                cloudflare_url: value === 'cloudflare' ? $('#cloudflare-url').val().trim() : '',
                google_selection_token: value === 'google' ? googleSelectionToken : ''
            };
        }

        function saveConfiguration(useCloudFront, button) {
            if (busy || syncBlocked) return;
            const data = configurationData(useCloudFront);
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
                    $('#setup-summary-delivery').text(data.service_provider === 'bunny' ? data.iam_key : data.service_provider === 'cloudflare' ? data.cloudflare_url :
                        data.use_cloudfront ? ui('cloudfrontDelivery', 'Amazon CloudFront') : ui('directDelivery', 'Direct storage delivery'));
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
                if (currentStep === 'final' && data.service_provider === 'google') {
                    $('#service-json').attr('data-has-credentials', '1');
                    selectGoogleFile(null, false, true);
                }
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
        $('#aws_iam_key, #aws_iam_secret, #bunny-api-key, #cloudflare-endpoint, #cloudflare-url, #wasabi_bucket_region, #aws_discovery_region, #google-p12-email, #google-p12-project, #google-p12-password').on('input change', invalidateDiscovery);
        $('#service-json').on('change', function () { selectGoogleFile(this.files[0], true); });
        $('#google-file-browse, #google-file-replace').on('click', function () {
            if (!busy && !syncBlocked) $('#service-json').trigger('click');
        });
        $('#google-saved-replace').on('click', function () {
            if (busy || syncBlocked) return;
            maskGoogleSaved();
            $('#google-upload, #google-key-help').prop('hidden', false);
            $('#service-json').trigger('click');
        });
        $('#google-saved-reveal').on('click', function () {
            if (busy || syncBlocked || provider() !== 'google' || googleFile || googleSavedRequest) return;
            if (googleSavedRevealed) { maskGoogleSaved(); return; }
            const token = ++googleSavedGeneration;
            const reference = $('#google-saved-account').attr('data-reference');
            const button = $(this).prop('disabled', true).attr('aria-busy', 'true').text(ui('googleSavedLoading', 'Loading sensitive values…'));
            googleSavedRequest = request('get_saved_google_credentials', { credential_reference: reference }, { timeout: statusTimeout, cache: false });
            googleSavedRequest.done(function (response) {
                if (token !== googleSavedGeneration || provider() !== 'google' || currentStep !== 1 || googleFile) return;
                if (!response || !response.success || !response.data || response.data.reference !== reference ||
                    !response.data.credentials || typeof response.data.credentials !== 'object' || Array.isArray(response.data.credentials)) {
                    showMessage(errorMessage(response, ui('googleSavedRevealFailed', 'The saved key values could not be loaded. Try again or reload this page.')));
                    return;
                }
                $('#google-saved-code').text(JSON.stringify(response.data.credentials, null, 2));
                googleSavedRevealed = true;
                $('#google-saved-note').text(ui('googleRevealedNote', 'Sensitive values are visible. Hide them before sharing your screen.'));
            }).fail(function (xhr) {
                if (token === googleSavedGeneration) showMessage(errorMessage(xhr.responseJSON, ui('googleSavedRevealFailed', 'The saved key values could not be loaded. Try again or reload this page.')));
            }).always(function () {
                if (token !== googleSavedGeneration) return;
                googleSavedRequest = null;
                button.prop('disabled', !!busy || syncBlocked).removeAttr('aria-busy').attr('aria-pressed', String(googleSavedRevealed))
                    .text(googleSavedRevealed ? ui('googleHideValues', 'Hide sensitive values') : ui('googleShowValues', 'Show sensitive values'));
            });
        });
        $('#google-saved-details').on('toggle', function () { if (!this.open) maskGoogleSaved(); });
        $('#google-file-remove').on('click', function () { if (!busy && !syncBlocked) selectGoogleFile(null, true); });
        $('#google-json-reveal').on('click', function () { if (!busy && !syncBlocked) { googleRevealed = !googleRevealed; renderGooglePreview(); } });
        let dragDepth = 0;
        $('#google-upload').on('dragenter dragover dragleave drop', function (event) {
            event.preventDefault();
            const nativeEvent = event.originalEvent;
            if (busy || syncBlocked) return;
            if (event.type === 'dragenter') dragDepth++;
            if (event.type === 'dragleave') dragDepth = Math.max(0, dragDepth - 1);
            $(this).toggleClass('is-dragover', event.type !== 'drop' && (event.type === 'dragover' || dragDepth > 0));
            if (event.type !== 'drop') return;
            dragDepth = 0;
            const files = nativeEvent && nativeEvent.dataTransfer && nativeEvent.dataTransfer.files;
            if (!files || !files.length) return;
            if (files.length !== 1) {
                $('#google-file-error').text(ui('googleOneFile', 'Choose one JSON or P12 file at a time. Your current selection has been kept.')).prop('hidden', false);
                return;
            }
            $('#service-json').val('');
            selectGoogleFile(files[0], false);
        });
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
            const data = configurationData(false);
            if (!data) return;
            $('.setup-selected-storage').text(data.bucket_name + ' · ' + data.bucket_region);
            if (provider() === 'aws' && !/^(cn-|us-gov-)/.test(data.bucket_region)) {
                showStep(3);
            } else {
                saveConfiguration(false, $(this));
            }
        });
        $('#step-2-back').on('click', function () { if (!busy && !syncBlocked) showStep(1); });
        $('#step-3-back').on('click', function () { if (!busy && !syncBlocked) showStep(2); });
        $('.setup-step-link').on('click', function () {
            if (this.disabled || busy || syncBlocked || currentStep === 'final') return;
            const step = Number($(this).attr('data-setup-step'));
            if (step === 1 || (step === 2 && discoveryReady)) showStep(step);
            if (step === 3 && discoveryReady && deliveryVisited) {
                const data = configurationData(false);
                if (data && hasDeliveryStep()) {
                    $('.setup-selected-storage').text(data.bucket_name + ' · ' + data.bucket_region);
                    showStep(3);
                }
            }
        });
        $('#use-cloudfront-yes').on('click', function () { saveConfiguration(true, $(this)); });
        $('#use-cloudfront-no').on('click', function () { saveConfiguration(false, $(this)); });
        $(window).on('pagehide', function () { listingGeneration++; syncGeneration++; syncPageActive = false; syncQueued = false; window.clearTimeout(syncTimer); if (syncRequest && syncRequest.abort) syncRequest.abort(); maskGooglePreview(); });
        $(document).on('visibilitychange', function () { if (document.hidden) maskGooglePreview(); window.clearTimeout(syncTimer); if (!document.hidden && (!syncStatusKnown || syncBlocked)) { syncFailures = 0; checkSetupSync(); } });
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

    function initializeCloudFrontSetup() {
        const card = $('#cloudfront-setup-card');
        if (!card.length) return;
        const page = $('.smartsync-domain-page');
        let labels = {};
        try { labels = JSON.parse($('#cloudfront-inline-labels').text() || '{}'); } catch (_) { /* Older cached markup. */ }
        const copy = (key, fallback) => labels[key] || fallback;
        let operation = 'setup';
        let pending = null;
        let generation = 0;
        let ready = false;
        let locked = card.attr('data-sync-running') === 'true';
        const button = $('#setup-custom-cloudfront');
        function message(value, kind = 'info') {
            $('#cloudfront-setup-message').text(value).attr({ 'data-kind': kind, role: kind === 'error' ? 'alert' : 'status' }).prop('hidden', !value);
            if (kind === 'error') $(document).trigger('smartsync:notice', [{ message: String(value), type: 'error', key: 'cloudfront-setup' }]);
        }
        function refresh() {
            button.prop('disabled', !!pending || locked || ready).prop('hidden', ready)
                .text(pending ? copy(operation === 'check' ? 'checking' : 'settingUp', 'Setting up CloudFront…') : copy(operation === 'check' ? 'check' : 'setup', 'Set up CloudFront'));
            button.attr('data-busy', pending ? 'true' : null);
            card.attr('aria-busy', String(!!pending));
            $('#continue-custom-domain').prop('hidden', !ready);
            $('#cloudfront-setup-sync-lock').prop('hidden', !locked);
        }
        button.on('click', function () {
            if (pending || locked || ready) return;
            const token = ++generation;
            message('');
            const xhr = request('setup_custom_cloudfront', {
                operation: operation,
                configuration_reference: card.attr('data-configuration-reference')
            }, { timeout: 180000 });
            pending = xhr;
            refresh();
            xhr.done(function (response) {
                if (token !== generation) return;
                const data = response && response.data;
                if (!response || !response.success || !data || !['ready', 'deploying'].includes(data.state)
                    || typeof data.distribution_id !== 'string' || !data.distribution_id
                    || typeof data.domain !== 'string' || !/^[a-z0-9-]+\.cloudfront\.net$/i.test(data.domain)) {
                    message(errorMessage(response, copy('failed', 'CloudFront setup could not finish. Review the error and retry to resume setup.')), 'error');
                    return;
                }
                $('#cloudfront-setup-result').prop('hidden', false);
                $('#cloudfront-setup-distribution').text(data.distribution_id);
                $('#cloudfront-setup-domain, #custom-domain-cloudfront-host').text(data.domain);
                if (typeof data.configuration_reference === 'string') card.attr('data-configuration-reference', data.configuration_reference);
                ready = data.state === 'ready';
                if (!ready) operation = 'check';
                message(copy(ready ? 'ready' : 'deploying', ready ? 'CloudFront is ready. Continue to choose your custom domain.' : 'CloudFront is deploying. Check its deployment when you are ready.'), ready ? 'success' : 'info');
            }).fail(function (xhr) {
                if (token === generation) message(errorMessage(xhr.responseJSON, copy('failed', 'CloudFront setup could not finish. Review the error and retry to resume setup.')), 'error');
            }).always(function () { if (token === generation) { pending = null; refresh(); } });
        });
        $('#continue-custom-domain').on('click', function () {
            if (!ready || pending) return;
            page.attr('data-cloudfront-ready', 'true');
            card.prop('hidden', true);
            $('#custom-url-flow').prop('hidden', false);
            $('#custom-domain-fields').prop('disabled', false);
            $(document).trigger('smartsync:cloudfront-ready');
            $('#custom-domain').trigger('focus');
        });
        $(document).on('smartsync:status', function (_, status) {
            if (!status || pending || ready) return;
            if (status.status === 'processing') locked = true;
            else if (!status.operation_busy) locked = false;
            refresh();
        });
        $(window).on('pagehide', function () { generation++; const xhr = pending; pending = null; if (xhr && xhr.abort) xhr.abort(); refresh(); });
        refresh();
    }

    function initializeCustomDomain() {
        if (!$('#custom-domain-container').length) return;
        let timer;
        let generation = 0;
        let certificateArn = '';
        let attaching = false;
        const domainReady = () => $('.smartsync-domain-page').attr('data-cloudfront-ready') !== 'false';
        function domainMessage(message, type = 'error') {
            $('#domain-message').text(message);
            if (message) $(document).trigger('smartsync:notice', [{ message: String(message), type: type, key: 'custom-domain' }]);
        }

        function domainStatus(status) {
            attaching = status === 'attaching';
            $('#custom-domain, #generate-certificate, #check-dns-records').prop('disabled', attaching);
            $('#cloudfront-attachment-button').prop('disabled', status !== 'verified');
            $(document).trigger('smartsync:domain-status', [status]);
        }
        function cancelPolling() {
            generation++;
            window.clearTimeout(timer);
            domainStatus('domain');
        }
        function renderRecords(records) {
            const list = $('<ul>');
            records.forEach(function (record) {
                if (!record || typeof record !== 'object') return;
                const item = $('<li>');
                ['DomainName', 'Type', 'Name', 'Value'].forEach(function (field) {
                    const label = field === 'DomainName' ? 'domain' : field.toLowerCase();
                    item.append($('<strong>').text(text(label) + ': '), document.createTextNode(String(record[field] || '')), $('<br>'));
                });
                item.append($('<span>').addClass('dns-status').text(text(record.ValidationStatus === 'SUCCESS' ? 'verified' : 'pending_validation')));
                list.append(item);
            });
            $('#records').empty().append($('<h3>').text(text('dns_validation_records')), list);
        }
        function pollCertificate(token) {
            if (!domainReady() || token !== generation || !certificateArn) return;
            request('check_dns_record', { certificateArn: certificateArn }).done(function (response) {
                if (token !== generation) return;
                if (!response || !response.success || !response.data || !Array.isArray(response.data.records)) {
                    domainStatus('domain');
                    domainMessage(errorMessage(response, text('dns_check_error')));
                    $('#check-dns-records').prop('hidden', false);
                    return;
                }
                // ACM can publish validation records after the initial request completes.
                renderRecords(response.data.records);
                if (response.data.verified === true) {
                    domainStatus('verified');
                    domainMessage(text('all_dns_verified'), 'success');
                    $('#check-dns-records').prop('hidden', true);
                } else {
                    domainStatus('pending');
                    timer = window.setTimeout(function () { pollCertificate(token); }, 5000);
                }
            }).fail(function (xhr) {
                if (token !== generation) return;
                domainStatus('domain');
                domainMessage(errorMessage(xhr.responseJSON, text('dns_check_error')));
                $('#check-dns-records').prop('hidden', false);
            });
        }

        $('#custom-domain').on('input', function () {
            if (attaching) return;
            cancelPolling();
            certificateArn = '';
            $('#records, #domain-message').empty();
            $('#check-dns-records').prop('hidden', true);
            $('#generate-certificate').prop('disabled', false);
        });
        $('#generate-certificate').on('click', function () {
            if (attaching || !domainReady()) return;
            const domain = $('#custom-domain').val().trim().replace(/\.$/, '');
            if (!/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/i.test(domain)) {
                domainMessage(text('invalid_domain', 'Enter a domain name without a scheme or path.'), 'warning');
                return;
            }
            cancelPolling();
            const token = generation;
            certificateArn = '';
            $('#generate-certificate').prop('disabled', true);
            $('#records, #domain-message').empty();
            $('#check-dns-records').prop('hidden', true);
            request('generate_ssl_certificate', { domain: domain }).done(function (response) {
                if (token !== generation) return;
                if (!response || !response.success || !response.data || !response.data.certificateArn || !Array.isArray(response.data.records)) {
                    domainMessage(errorMessage(response, text('certificate_generation_failed')));
                    return;
                }
                certificateArn = response.data.certificateArn;
                renderRecords(response.data.records);
                domainStatus('pending');
                pollCertificate(token);
            }).fail(function (xhr) {
                if (token === generation) domainMessage(errorMessage(xhr.responseJSON, text('certificate_generation_error')));
            }).always(function () {
                if (token === generation) $('#generate-certificate').prop('disabled', false);
            });
        });
        $('#check-dns-records').on('click', function () {
            if (attaching || !certificateArn) return;
            window.clearTimeout(timer);
            $(this).prop('hidden', true);
            pollCertificate(generation);
        });
        $('#cloudfront-attachment-button').on('click', function () {
            if ($(this).prop('disabled') || !certificateArn) return;
            const token = generation;
            domainStatus('attaching');
            request('attach_to_cloudfront').done(function (response) {
                if (token !== generation) return;
                domainStatus(response && response.success ? 'attached' : 'domain');
                $('#check-dns-records').prop('hidden', !!(response && response.success));
                domainMessage(response && response.success ? text('certificate_attached') : errorMessage(response, text('attachment_failed')), response && response.success ? 'success' : 'error');
            }).fail(function (xhr) {
                if (token !== generation) return;
                domainStatus('domain');
                $('#check-dns-records').prop('hidden', false);
                domainMessage(errorMessage(xhr.responseJSON, text('attachment_error')));
            });
        });
        $(window).on('pagehide', cancelPolling);
        $(window).on('pageshow', function (event) {
            if (!(event.originalEvent && event.originalEvent.persisted)) return;
            cancelPolling();
            $('#check-dns-records').prop('hidden', true);
            if (certificateArn) {
                domainStatus('pending');
                pollCertificate(generation);
            }
        });
        function resumeCertificate() {
            if (!domainReady()) return;
            domainStatus('domain');
            try {
            const initial = JSON.parse($('#initial_certificates').text() || '{}');
            if (initial.certificate_arn && Array.isArray(initial.records)) {
                certificateArn = initial.certificate_arn;
                renderRecords(initial.records);
                domainStatus('pending');
                pollCertificate(generation);
            }
            } catch (_) {
                domainMessage(text('dns_check_error'));
            }
        }
        domainStatus('domain');
        $(document).on('smartsync:cloudfront-ready', resumeCertificate);
        resumeCertificate();
    }

    initializeProviderWizard();
    initializeCloudFrontSetup();
    initializeCustomDomain();
});
