# Admin UI, AJAX, and providers

## Pages and asset contracts

All three pages require manage_options, including an explicit check in their render callbacks:

| Page | Slug | View |
| --- | --- | --- |
| Settings | smarl-smart-sync | src/Views/SMARL_SettingsPage.php |
| Cloud wizard | smarl-configuration-wizard | src/Views/SMARL_AWSWizard.php |
| Custom CloudFront URL | custom-cloudfront-url | src/Views/SMARL_CustomUrl.php |

SMARL_SmartSync::enqueueScripts() loads assets only on these plugin pages. CSS loads wizard.css, ui-improvements.css, then enhanced-ui.css. wizard.js and settings.js depend on jQuery; ui-improvements.js depends on both, and enhanced-logs.js follows it. Asset cache versioning uses SMARL_ASSET_VERSION_CODE.

| Localized global | Script | Fields |
| --- | --- | --- |
| ajax_object | wizard.js | ajax_url, nonce, isLite, strings, wizard_url, settings_url |
| ajaxObject | settings.js | ajax_url, nonce, isLite, wizard_url, settings_strings |

Each script uses its own localized AJAX object. JavaScript strings are localized as plain text, then inserted using text nodes. Keep field names, script dependencies, selectors, and payload consumers synchronized.

Styles use the plugin wrapper or specific plugin component names. Preserve the final cascade: enhanced-ui.css defines border-box sizing, authoritative hidden state, small-screen wrapping, focus outlines, and reduced-motion overrides. In particular, a responsive width:100% combined with content-box padding previously caused horizontal overflow.

## Privileged AJAX and payloads

Both AJAX classes require manage_options and verify check_ajax_referer('smarl_nonce', 'nonce') for browser operations, including stop, DNS polling, and domain attachment. Keep both checks on any new operation. Backend authorization remains necessary even when the UI hides or disables a control.

Responses use WordPress success/data envelopes. Some error responses contain a string and others a message object; consumers handle both. Non-2xx failures also need UI recovery.

| Action | Inputs or successful data |
| --- | --- |
| list_s3_buckets | service_provider, iam_key, iam_secret; optional bucket_region/cloudflare_endpoint; Google optionally uploads one multipart service_json file. Returns buckets:[{name,region,pullzones?}] |
| configure_aws | bucket_type:existing/new, bucket_name, bucket_region, service_provider, iam_key, iam_secret, use_cloudfront; optional cloudflare_endpoint/cloudflare_url |
| sync_local_to_aws / sync_aws_to_local | type:media/assets; start data contains message and total_files |
| check_sync_status | total_files, processed_files, status, direction, errors, successes, type |
| get_sync_state / set_sync_state | Get returns a state string; set accepts only a state consistent with the background job |
| toggle_cdn | type, useCdn boolean |
| toggle_media_auto_delete | state boolean |
| fetch_aws_analytics | total_files, formatted total_storage, raw total_size, nullable price_in_dollars, estimated_cost_available |
| generate_ssl_certificate | domain hostname; returns message, certificateArn, records |
| check_dns_record | certificateArn; returns verified and current records |
| attach_to_cloudfront | Uses saved certificate/domain/distribution, validates them, then updates active url |
| clear_cloudfront_cache | Requests invalidation for the configured AWS CloudFront distribution |

The current analytics response does not claim provider billing costs: price_in_dollars is null and estimated_cost_available is false. Display Unavailable for null; preserve numeric zero if a future supported source supplies it.

## Provider setup and storage dispatch

Provider identifiers are exact lowercase values. All six appear in the wizard and share SMARL_CloudConfig uploadObject(), downloadObject(), deleteObject(), and listObjects() interfaces. Test provider-specific behavior with mocked clients; dispatch coverage does not establish a successful live integration.

| Provider | Client and setup |
| --- | --- |
| aws | AWS SDK S3, with optional CloudFront/OAC, STS, and ACM |
| digitalocean | S3 endpoint selected by region; bucket creation uses the S3 client |
| wasabi | S3 endpoint selected by region; regional listing and bucket creation are exposed in the wizard |
| cloudflare | S3-compatible R2 account endpoint, region auto, path-style addressing; storage endpoint and public CDN URL are distinct validated fields; no object ACL |
| google | Explicit Google service-account credentials and StorageClient; new buckets default to US; existing bucket location comes from listing |
| bunny | WordPress HTTP API for account listing/storage operations; existing storage zone plus connected pullzone required |

S3 getS3Client() returns null for Google and Bunny. Call the common storage methods for cross-provider work rather than assuming every provider has an S3 client. The old Wasabi creation helper is not the active creation path.

Bucket choices are structured objects. The browser caches these objects and uses numeric option values, with names and regions rendered as text. Submit their selected name/region separately; do not restore pipe-delimited parsing. For Bunny, pullzones are constrained to the selected bucket. Its storage password remains server-side in a user-bound, time-limited transient; the browser sends only the account API key in iam_secret and the selected pullzone hostname in iam_key. The saved configuration has a separate bunnyStoragePassword field, with migration for the older combined secret.

Google accepts a JSON upload smaller than 1 MB, validates service-account fields, and stores an encrypted credential envelope in a site-specific private directory. SMARTSYNC_CREDENTIALS_DIR can choose the base directory; the default is under wp-content/smartsync-private with access protection files. Encryption is bound to the site's auth salt and root. The user-bound temporary credential path is held in a transient, then persisted as googleServicePath. Existing valid credentials can be reused without uploading again; the UI exposes only a presence flag. Use the service's migration/cleanup methods when changing credential storage, and never expose key contents or filesystem paths.

CloudFront setup is restricted to AWS on the server and in the wizard. Setup modifies bucket policy/public access and can create external resources; use only authorized resources for live tests. Existing policy statements are retained while SmartSync statements are updated.

## Custom-domain and UI state

Custom URL persistence maps certificateArn to certificate_arn. The view seeds that data in an application/json script with JSON_HEX flags; wizard.js reads text content and parses JSON. Certificate records use DomainName, Type, Name, Value, systemGenerated, and optional ValidationStatus.

Certificate generation validates input before persisting, reuses an eligible certificate for the same domain, and saves the ARN immediately. ACM validation records can arrive asynchronously: polling must render the latest complete records list, not merely update rows from the initial response. The synthetic CloudFront CNAME record also has a verification status.

DNS polling includes the nonce and uses one cancellable timeout after each response. Editing the domain invalidates outstanding callbacks. A failed check exposes Retry DNS Check. Attach is enabled only after verified:true; the backend independently requires an issued matching certificate and the correct distribution CNAME. Attachment preserves aliases, checks certificate coverage for all aliases, and updates distribution config with its ETag.

The real settings functions publish document events:

| Event | Consumer or data |
| --- | --- |
| smartsync:log | Existing safe log row plus message/error/kind metadata; enhanced-logs.js adds its timestamp once |
| smartsync:status | Structured status/count data drives progress and remaining/time displays |
| smartsync:running | Boolean controls the background status indicator |
| smartsync:sync-started | Starts elapsed-time estimation |
| smartsync:domain-status | domain/pending/verified/attaching/attached updates custom-domain progress |

Do not add wrappers around window.appendLog or window.checkSyncStatus: these functions are local to settings.js. Extend the existing events. Log rows are capped and provider strings/filenames are never interpreted as HTML.

Settings locks are acquired after confirmation, all sync buttons are disabled while starting/running/stopping, and failed starts/toggles restore prior state. Poll generations ignore late responses after a stop or restart. Tab controls use matching ARIA relationships, hidden panels, and arrow/Home/End keyboard navigation. Reconfigure and Finish use real admin links with the registered slugs.

For local checks and limitations, see tests/admin/README.md. The Node suite uses PHP-rendered views, actual CSS/JS, jsdom/jQuery, and mocked requests/timers. The optional Edge/Chromium smoke runs against disposable WordPress, intercepts cloud AJAX, blocks external resources, and captures responsive screenshots. Neither establishes live provider provisioning or transfers.
