jQuery(function ($) {
    'use strict';

    const config = window.ajax_object;
    if (!config) return;
    const strings = config.strings || {};
    const text = (key, fallback) => strings[key] || fallback || key;
    const providers = ['aws', 'digitalocean', 'wasabi', 'google', 'cloudflare', 'bunny'];

    function errorMessage(response, fallback) {
        const data = response && response.data;
        return typeof data === 'string' ? data : (data && data.message) || fallback;
    }

    function request(action, data) {
        return $.ajax({
            url: config.ajax_url,
            method: 'POST',
            data: $.extend({}, data, { action: action, nonce: config.nonce })
        });
    }

    function showMessage(message) {
        $('#wizard-message').text(message).prop('hidden', !message);
    }

    function initializeProviderWizard() {
        if (!$('#aws-wizard').length) return;
        let buckets = [];
        let listingGeneration = 0;
        let saving = false;
        let savedControls = [];
        let googleSelectionToken = '';
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
        }

        function showStep(step) {
            $('.wizard-step').addClass('d-none').prop('hidden', true);
            const panel = $('#wizard-step-' + step).removeClass('d-none').prop('hidden', false);
            $('.stepper .step').removeClass('active').removeAttr('aria-current');
            $('#step-' + step + '-indicator').addClass('active').attr('aria-current', 'step');
            panel.find('h2').attr('tabindex', '-1').trigger('focus');
        }

        function invalidateDiscovery() {
            if (saving) return;
            listingGeneration++;
            buckets = [];
            googleSelectionToken = '';
            $('#aws_bucket_list, #pull-zones').empty();
            $('#bunny-pull-zone-help').text('').prop('hidden', true);
            $('#step-1-next').prop('disabled', false);
            $('#aws-wizard').attr('aria-busy', 'false');
            showMessage('');
        }

        function restoreControls() {
            savedControls.forEach(function (item) { $(item.element).prop('disabled', item.disabled); });
            savedControls = [];
            $('#aws-wizard').attr('aria-busy', 'false');
        }

        function updateProvider() {
            listingGeneration++;
            buckets = [];
            googleSelectionToken = '';
            $('#aws_bucket_list, #pull-zones').empty();
            $('#bunny-pull-zone-help').text('').prop('hidden', true);
            $('#step-1-next').prop('disabled', false);
            $('#aws-wizard').attr('aria-busy', 'false');
            const value = provider();
            $('#manual-bucket-entry').prop('checked', false);
            const savedRegion = $('#manual_bucket_region').attr('data-saved-provider') === value ? $('#manual_bucket_region').attr('data-saved-region') : '';
            $('#manual_bucket_region').val(savedRegion || (value === 'digitalocean' ? 'nyc3' : value === 'cloudflare' ? 'auto' : 'us-east-1'));
            $('.config-inputs').addClass('d-none');
            if (['aws', 'digitalocean', 'wasabi', 'cloudflare'].includes(value)) $('.aws-sdk-inputs').removeClass('d-none');
            if (value === 'google') $('.google-inputs').removeClass('d-none');
            if (value === 'cloudflare') $('.cloudflare-inputs').removeClass('d-none');
            if (value === 'bunny') $('.bunny-inputs').removeClass('d-none');
            $('#bucket-type-selector').toggleClass('d-none', value === 'bunny');
            $('#region-selector').toggleClass('d-none', !['aws', 'digitalocean'].includes(value));
            $('#wasabi-region-selector').toggleClass('d-none', value !== 'wasabi');
            $('input[name="bucket_type"][value="existing"]').prop('checked', true);
            const regions = value === 'digitalocean' ? digitalOceanRegions : awsRegions;
            const regionSelect = $('#new_bucket_region').empty();
            regions.forEach(function (code) {
                const key = (value === 'digitalocean' ? 'digitalocean_region_' : 'aws_region_') + code.replace(/-/g, '_');
                regionSelect.append($('<option>').val(code).text(strings[key] || code));
            });
            chooseBucketType();
            showMessage('');
        }

        function credentialsValid() {
            const value = provider();
            if (!providers.includes(value)) { showMessage(text('invalid_provider', 'Select a supported provider.')); return false; }
            if (value === 'google') {
                if (!$('#service-json')[0].files.length && $('#service-json').attr('data-has-credentials') !== '1') {
                    showMessage(text('select_service_json', 'Choose a service-account JSON file.')); return false;
                }
            } else if (value === 'bunny') {
                if (!$('#bunny-api-key').val().trim()) { showMessage(text('iam_required')); return false; }
            } else if (!$('#aws_iam_key').val().trim() || !$('#aws_iam_secret').val()) {
                showMessage(text('iam_required')); return false;
            }
            if (value === 'cloudflare') {
                if (!$('#cloudflare-endpoint').val().trim()) { showMessage(text('endpoint_required', 'Enter the storage endpoint.')); return false; }
                if (!$('#cloudflare-url').val().trim()) { showMessage(text('public_url_required', 'Enter the public CDN URL.')); return false; }
            }
            return true;
        }

        function loadBuckets() {
            const value = provider();
            const token = ++listingGeneration;
            $('#step-1-next').prop('disabled', true);
            $('#aws-wizard').attr('aria-busy', 'true');
            showMessage(text('loading_buckets'));
            $('#aws_bucket_list').empty().append($('<option>').val('').text(text('loading_buckets')));
            buckets = [];
            function listingFailed(response, fallback) {
                $('#aws_bucket_list').empty().append($('<option>').val('').text(text('no_buckets', 'No buckets available.')));
                if (supportsManualBucket()) {
                    $('#manual-bucket-entry').prop('checked', true);
                    chooseBucketType();
                    showStep(2);
                } else {
                    showStep(1);
                }
                showMessage(errorMessage(response, fallback));
            }
            const form = new FormData();
            form.append('action', 'list_s3_buckets');
            form.append('nonce', config.nonce);
            form.append('service_provider', value);
            if (value === 'google') {
                if ($('#service-json')[0].files.length) form.append('service_json', $('#service-json')[0].files[0]);
            } else {
                form.append('iam_key', $('#aws_iam_key').val().trim());
                form.append('iam_secret', value === 'bunny' ? $('#bunny-api-key').val().trim() : $('#aws_iam_secret').val());
                if (value === 'cloudflare') form.append('cloudflare_endpoint', $('#cloudflare-endpoint').val().trim());
                if (value === 'wasabi') form.append('bucket_region', $('#wasabi_bucket_region').val().trim());
            }
            $.ajax({ url: config.ajax_url, method: 'POST', data: form, processData: false, contentType: false }).done(function (response) {
                if (token !== listingGeneration) return;
                if (!response || !response.success || !response.data || !Array.isArray(response.data.buckets)) {
                    listingFailed(response, text('load_buckets_failed'));
                    return;
                }
                const validBuckets = response.data.buckets.every(function (bucket) {
                    return bucket && typeof bucket === 'object' && typeof bucket.name === 'string' && bucket.name &&
                        (bucket.region === undefined || typeof bucket.region === 'string');
                });
                if (!validBuckets) {
                    listingFailed(null, text('invalid_bucket_response', 'The bucket response is invalid.'));
                    return;
                }
                buckets = response.data.buckets;
                googleSelectionToken = value === 'google' && typeof response.data.selectionToken === 'string' ? response.data.selectionToken : '';
                const select = $('#aws_bucket_list').empty();
                buckets.forEach(function (bucket, index) {
                    // Keep provider data out of HTML and option values, especially credentials.
                    select.append($('<option>').val(String(index)).text(bucket.name + (bucket.region ? ' | ' + bucket.region : '')));
                });
                if (!buckets.length) select.append($('<option>').val('').text(text('no_buckets', 'No buckets available.')));
                fillPullZones();
                showMessage('');
                showStep(2);
            }).fail(function (xhr) {
                if (token !== listingGeneration) return;
                listingFailed(xhr.responseJSON, text('load_buckets_error'));
            }).always(function () {
                if (token === listingGeneration) {
                    $('#step-1-next').prop('disabled', false);
                    $('#aws-wizard').attr('aria-busy', 'false');
                }
            });
        }

        function configurationData(useCloudFront) {
            if (!credentialsValid()) return null;
            const value = provider();
            if (value === 'google' && $('#service-json')[0].files.length && !googleSelectionToken) {
                showMessage(text('invalid_bucket_response', 'The bucket response is invalid.'));
                return null;
            }
            const bucketType = $('input[name="bucket_type"]:checked').val();
            if (!['existing', 'new'].includes(bucketType) || (value === 'bunny' && bucketType !== 'existing')) return null;
            const selected = selectedBucket();
            const manual = bucketType === 'existing' && supportsManualBucket() && $('#manual-bucket-entry').prop('checked');
            const name = bucketType === 'new' ? $('#new_bucket_name').val().trim() : manual ? $('#manual_bucket_name').val().trim() : selected && selected.name;
            let region = bucketType === 'new' ? $('#new_bucket_region').val() : manual ? $('#manual_bucket_region').val().trim() : selected && selected.region;
            if (!name) { showMessage(text('bucket_required')); return null; }
            const namePattern = value === 'google' ? /^[a-z0-9][a-z0-9._-]{1,220}[a-z0-9]$/ : /^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/;
            if ((bucketType === 'new' || manual) && (!namePattern.test(name) || name.includes('..') ||
                (value !== 'google' && (name.includes('.-') || name.includes('-.'))) || /^\d+\.\d+\.\d+\.\d+$/.test(name))) {
                showMessage(text('invalid_bucket_name', 'Enter a valid bucket name.')); return null;
            }
            if (value === 'google') region = bucketType === 'new' ? 'US' : region || 'US';
            if (value === 'cloudflare') region = 'auto';
            if (value === 'wasabi' && bucketType === 'new') region = $('#wasabi_bucket_region').val().trim();
            if (!region || !/^[a-zA-Z0-9-]+$/.test(region)) { showMessage(text('bucket_required')); return null; }
            const pullZone = $('#pull-zones').val();
            if (value === 'bunny' && (!selected || !Array.isArray(selected.pullzones) || !selected.pullzones.includes(pullZone))) {
                showMessage(text('select_pull_zone', 'Select a pull zone.')); return null;
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

        function saveConfiguration(useCloudFront) {
            if (saving) return;
            const data = configurationData(useCloudFront);
            if (!data) return;
            saving = true;
            const token = listingGeneration;
            savedControls = $('#aws-wizard :input').toArray().map(function (element) {
                return { element: element, disabled: element.disabled };
            });
            $('#aws-wizard :input').prop('disabled', true);
            $('#aws-wizard').attr('aria-busy', 'true');
            request('configure_aws', data).done(function (response) {
                if (token !== listingGeneration) return;
                if (response && response.success) {
                    showMessage(text('configuration_saved'));
                    showStep('final');
                } else {
                    showMessage(errorMessage(response, text('configuration_failed')));
                }
            }).fail(function (xhr) {
                if (token !== listingGeneration) return;
                showMessage(errorMessage(xhr.responseJSON, text('configuration_error', 'Could not save the configuration.')));
            }).always(function () {
                if (token !== listingGeneration) return;
                saving = false;
                restoreControls();
            });
        }

        $('#service_provider').on('change', updateProvider);
        $('#aws_iam_key, #aws_iam_secret, #bunny-api-key, #cloudflare-endpoint, #cloudflare-url, #wasabi_bucket_region, #service-json').on('input change', invalidateDiscovery);
        $('input[name="bucket_type"]').on('change', chooseBucketType);
        $('#manual-bucket-entry').on('change', chooseBucketType);
        $('#aws_bucket_list').on('change', fillPullZones);
        $('#step-1-next').on('click', function () {
            showMessage('');
            if (credentialsValid()) loadBuckets();
        });
        $('#step-2-next').on('click', function () {
            showMessage('');
            const data = configurationData(false);
            if (!data) return;
            if (provider() === 'aws' && !/^(cn-|us-gov-)/.test(data.bucket_region)) {
                showStep(3);
            } else {
                saveConfiguration(false);
            }
        });
        $('#step-2-back').on('click', function () { showStep(1); });
        $('#step-3-back').on('click', function () { showStep(2); });
        $('#use-cloudfront-yes').on('click', function () { saveConfiguration(true); });
        $('#use-cloudfront-no').on('click', function () { saveConfiguration(false); });
        $(window).on('pagehide', function () { listingGeneration++; });
        $(window).on('pageshow', function (event) {
            if (!(event.originalEvent && event.originalEvent.persisted)) return;
            saving = false;
            restoreControls();
            invalidateDiscovery();
            showStep(1);
        });
        updateProvider();
    }

    function initializeCustomDomain() {
        if (!$('#custom-domain-container').length) return;
        let timer;
        let generation = 0;
        let certificateArn = '';
        let attaching = false;

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
            if (token !== generation || !certificateArn) return;
            request('check_dns_record', { certificateArn: certificateArn }).done(function (response) {
                if (token !== generation) return;
                if (!response || !response.success || !response.data || !Array.isArray(response.data.records)) {
                    domainStatus('domain');
                    $('#domain-message').text(errorMessage(response, text('dns_check_error')));
                    $('#check-dns-records').prop('hidden', false);
                    return;
                }
                // ACM can publish validation records after the initial request completes.
                renderRecords(response.data.records);
                if (response.data.verified === true) {
                    domainStatus('verified');
                    $('#domain-message').text(text('all_dns_verified'));
                    $('#check-dns-records').prop('hidden', true);
                } else {
                    domainStatus('pending');
                    timer = window.setTimeout(function () { pollCertificate(token); }, 5000);
                }
            }).fail(function (xhr) {
                if (token !== generation) return;
                domainStatus('domain');
                $('#domain-message').text(errorMessage(xhr.responseJSON, text('dns_check_error')));
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
            if (attaching) return;
            const domain = $('#custom-domain').val().trim().replace(/\.$/, '');
            if (!/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/i.test(domain)) {
                $('#domain-message').text(text('invalid_domain', 'Enter a domain name without a scheme or path.'));
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
                    $('#domain-message').text(errorMessage(response, text('certificate_generation_failed')));
                    return;
                }
                certificateArn = response.data.certificateArn;
                renderRecords(response.data.records);
                domainStatus('pending');
                pollCertificate(token);
            }).fail(function (xhr) {
                if (token === generation) $('#domain-message').text(errorMessage(xhr.responseJSON, text('certificate_generation_error')));
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
                $('#domain-message').text(response && response.success ? text('certificate_attached') : errorMessage(response, text('attachment_failed')));
            }).fail(function (xhr) {
                if (token !== generation) return;
                domainStatus('domain');
                $('#check-dns-records').prop('hidden', false);
                $('#domain-message').text(errorMessage(xhr.responseJSON, text('attachment_error')));
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
            $('#domain-message').text(text('dns_check_error'));
        }
    }

    initializeProviderWizard();
    initializeCustomDomain();
});
