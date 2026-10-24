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
| list_s3_buckets | service_provider, iam_key, iam_secret; optional bucket_region/cloudflare_endpoint; Google optionally uploads one multipart service_json file. Returns buckets:[{name,region,pullzones?,issue?}] and selectionToken (nonempty for a newly uploaded Google credential selection) |
| configure_aws | bucket_type:existing/new, bucket_name, bucket_region, service_provider, iam_key, iam_secret, use_cloudfront; optional cloudflare_endpoint/cloudflare_url and google_selection_token from the accepted Google discovery response |
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
| digitalocean | Datacenter selected by endpoint; SDK region is `us-east-1` to prevent its middleware injecting an invalid bucket location |
| wasabi | S3 endpoint selected by region; regional listing and bucket creation are exposed in the wizard |
| cloudflare | S3-compatible R2 account endpoint, region auto, path-style addressing; storage endpoint and public CDN URL are distinct validated fields; no object ACL |
| google | Explicit Google service-account credentials and StorageClient; new buckets default to US; existing bucket location comes from listing |
| bunny | WordPress HTTP API for account listing and ordinary storage operations; uploads above 1 MiB stream through Guzzle/cURL; existing storage zone plus connected pullzone required |

S3 getS3Client() returns null for Google and Bunny. Call the common storage methods for cross-provider work rather than assuming every provider has an S3 client. The old Wasabi creation helper is not the active creation path.

Bucket choices are structured objects. The browser caches these objects and uses numeric option values, with names and regions rendered as text. Submit their selected name/region separately; do not restore pipe-delimited parsing. For Bunny, pullzones are constrained to the selected bucket. Its storage password remains server-side in a user-bound, time-limited transient; the browser sends only the account API key in iam_secret and the selected pullzone hostname in iam_key. The saved configuration has a separate bunnyStoragePassword field, with migration for the older combined secret.

Bunny discovery requests positive pages and accepts both legacy arrays and paginated `Items` responses, fetching detail metadata when the list omits credentials/connected zones. Offer enabled, unsuspended, public pull zones; prefer Bunny system hostnames and reject custom hosts explicitly lacking certificates. Unusable zones include a plain-text `issue` for the UI. Save a validated `bunnyStorageEndpoint` from `StorageHostname`, restricted to HTTPS Bunny storage hosts. Probe the storage root with the zone password before persisting. Older region-derived endpoints may be repaired from verified metadata for the same account, zone, region and public URL while retaining remote-only delivery flags.

Bunny uploadObject() buffers files up to 1 MiB through the existing WordPress HTTP path and streams larger sources through getBunnyStreamClient() and Guzzle's cURL handler. Keep the source as a resource and close it in finally on success or failure; reading an entire large file into a string previously exhausted PHP memory. Large uploads require the PHP cURL extension and return an actionable error if it is missing. The streaming path retains the validated Bunny hostname, storage-password header, disabled redirects, finite timeouts, status checks and credential-free transport errors. Downloads continue to use the WordPress HTTP streaming destination. The 64 MiB/32M regression proves local memory behavior, not live Bunny acceptance.

AWS, DigitalOcean, Wasabi and R2 can enter an existing bucket/region manually when account-wide discovery is forbidden. This bypasses discovery only; server-side bucket access and configuration checks still apply. Bunny/Google listing failures return to the credential step. CDN/cleanup writes use the coordination lock and reload durable settings so stale callbacks cannot restore old credentials or flags.

AWS direct public URLs use regional path-style addressing for dotted buckets and the China DNS suffix where applicable. Bucket policies use the correct commercial/China/GovCloud ARN partition. Automatic CloudFront setup uses commercial AWS credentials/regions; China/GovCloud selections use direct S3 and the backend rejects unsupported provisioning before provider calls. Google policy reads request IAM version 3, preserve conditions/etag and merge unconditional object-viewer members instead of adding duplicate bindings.

Google accepts a JSON upload up to 1 MiB, validates service-account fields, and stores an encrypted credential envelope in a site-specific private directory. SMARTSYNC_CREDENTIALS_DIR can choose the base directory; the default is under wp-content/smartsync-private with access protection files. Encryption is bound to the site's auth salt and root. Legacy migration acquires the coordination lease, reloads current durable settings, retains the readable source until its encrypted replacement path is durably saved, then removes only an eligible owned legacy file. Use the service's migration/cleanup methods when changing credential storage, and never expose key contents or filesystem paths.

Google discovery calls moveFileTemp(true), which reserves the exact encrypted path behind a random selection token in the user-bound smarl_google_selections_<user-id> transient. Selections expire after one hour; valid selections pin their files, and subsequent uploads prune expired pending files while retaining the active saved credential. list_s3_buckets returns selectionToken alongside buckets. wizard.js retains only the latest accepted response token, clears it on credential/file/provider changes, and submits google_selection_token to configure_aws. The backend resolves that exact selection and rejects expired or invalid tokens; it must never substitute a newer pending upload. Existing saved Google credentials can be reused without a new file/token, ignoring unrelated pending uploads. Legacy pre-token temporary credentials retain compatibility when no active token selections exist. The older smarl_google_credentials_<user-id> transient remains a compatibility pointer; it is insufficient to identify an accepted overlapping discovery response.

Credentials-only repair of the same storage target preserves CDN flags, distribution and custom-domain state and skips resource/policy creation; the public URL also stays the same except for the explicit legacy AWS TLS/partition correction described above. S3-compatible repair checks bucket access, Bunny probes its storage root, and Google verifyGoogleConnection() must successfully read bucket existence before replacing saved credentials. A read probe does not prove all later write/delete permissions. Actual target changes remain blocked until tracked offloaded media is restored locally; before an allowed migration, the configuration lease also protects binding legacy attachment manifests to their previous storage target. Cloud and custom-domain model saves verify the durable database value, so a failed write cannot report success or delete still-active Google credentials.

CloudFront setup is restricted to AWS on the server and in the wizard. Setup modifies bucket policy/public access and can create external resources; use only authorized resources for live tests. Existing policy statements are retained while SmartSync statements are updated.

Before allocating CloudFront resources, setup checks STS account identity and reads the existing S3 bucket policy. It durably verifies a credential-free smarl_cloudfront_pending recovery map keyed by account/bucket/region, then records each known OAC/distribution ID immediately. Receipts also retain the OAC name, distribution caller reference and domain; they remain after success so a later settings-write failure cannot lose the resource identity. Retry validates a saved OAC/distribution's identity and origin before reusing it. Preserve the stable OAC name/caller reference and the 64-character OAC name limit when changing provisioning. If checkpoint persistence fails after allocation, the safe administrator error identifies the known AWS resources for reconciliation. Do not add credentials to receipts or overwrite working smarl_settings after a provisioning failure. Uninstall removes the local receipt option without deleting cloud resources.

CloudFront recovery is a partial mitigation rather than an atomic rollback. Abandoning setup can leave tracked resources active, and a lost provider response before an ID is known can require manual reconciliation by its stable name/caller reference. Automatic disable/deploy/delete cleanup is not implemented; live deployment and dotted S3 CloudFront-origin delivery remain unverified boundaries.

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
