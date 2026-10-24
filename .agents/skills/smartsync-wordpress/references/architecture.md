# Architecture and persisted state

## Source map

| Area | Maintained source | Responsibility |
| --- | --- | --- |
| Bootstrap | `aws-s3-smartsync-wp.php` | Version/runtime checks, Composer, bundled Action Scheduler, lifecycle registration, versioned queue schema |
| Core | `src/SMARL_SmartSync.php` | WordPress hooks, scoped admin assets/localization, attachment upload/cleanup/deletion, safe media and asset URL filters |
| Configuration AJAX | `src/Ajax/SMARL_AwsConfigAjax.php` | Authorized provider configuration, bucket discovery, CloudFront, certificates and DNS |
| Runtime AJAX/jobs | `src/Ajax/SMARL_AjaxHandler.php` | Authorized sync controls, run-scoped queue processing, status, CDN eligibility, analytics and invalidation |
| Cloud model | `src/Models/SMARL_CloudConfig.php` | `smarl_settings` serialization, validated clients and provider-neutral object operations |
| Sync model | `src/Models/SMARL_S3SyncSettings.php` | Validated progress snapshots, run identity and conditional persistence |
| Domain model | `src/Models/SMARL_CustomUrl.php` | `custom_url_option` certificate, domain and DNS records |
| File/URL helpers | `src/Helpers/SMARL_Helper.php` | Extension selection, contained paths, sorted scans, durable SQL queue, coordination lock |
| CloudFront payloads | `src/Helpers/SMARL_AwsHelper.php` | OAC/distribution configuration and unique caller references |
| Provider services | `src/Services/` | Google credential/storage support; separate Wasabi helper |
| Admin rendering/behavior | `src/Views/`, `assets/js/`, `assets/css/` | Restricted admin pages and directly loaded scripts/styles; no Node build pipeline |
| Tests | `tests/` | Isolated PHP/Node regressions, disposable WordPress integration and browser smoke |

## Bootstrap and lifecycle

The bootstrap checks PHP and bundled dependencies before loading Composer and Action Scheduler. Missing requirements produce an administrator notice and an activation error instead of an unconditional missing-file fatal. It constructs `SMARL_SmartSync` and calls `initializeHooks()`, `initializeActions()`, `initializeFilters()` and `initializeViews()`.

The core handles `switch_blog` by loading the current blog's cloud configuration and refreshing both existing registered AJAX handler objects through `setConfiguration()`. It saves/restores pending attachment work, cleanup state and per-request delivery/deletion tracking separately for each blog. Shutdown flushes queued blogs with `switch_to_blog()`/`restore_current_blog()` in `finally`; never reuse one site's provider, progress or attachment ID state in another site context. Asset filters remain registered and check the current configuration at execution time.

Activation initializes settings and calls `smarl_file_sync_queue_create_table()`. `smarl_maybe_upgrade_schema()` runs on `plugins_loaded` and uses `smarl_db_version` to apply schema changes through `dbDelta()` on existing installs. Queue schema version 2 has:

- `id` unsigned bigint primary key; `run_id` varchar(64).
- `path` and `relative_path` text, preserving long root-relative keys.
- Signed `size` bigint, default `-1` for an unknown expected download length.
- `status` varchar(20), timestamps, and `(run_id,status,id)` index.

Deactivation cancels SmartSync batch actions and resets sync progress while retaining cloud configuration. It preserves an active worker's coordination lease; that worker releases its own token, and abandoned leases use expiry recovery. Network activation/deactivation iterate sites and restore blog context in `finally`.

When a site is added after network activation, its schema/version are initialized lazily on its first plugin-loaded request, rather than during site creation itself. The configuration model supplies defaults when that new site's settings option is absent. MySQL multisite tests cover both the pre-bootstrap baseline and the first-request initialization.

WordPress invokes `uninstall.php` directly. It removes current/legacy SmartSync options, its queue, tracked attachment metadata, its own Action Scheduler actions/logs, credential transients and owned local credential files. It preserves unrelated jobs/files and performs no restore or cloud object deletion. In a fixture using a junction to the repository, execute `uninstall_plugin()` or a skip-delete uninstall; never delete the plugin directory through that junction.

## Cloud and domain persistence

`SMARL_CloudConfig` initializes all typed fields. `load()` returns an initialized model even with empty/malformed stored data, reloads option state without stale positive/negative cache entries, and loads `customUrl` separately. Use `isConfigured()` rather than model truthiness to establish readiness.

The explicit `FIELDS` and `BOOLEAN_FIELDS` maps are shared by `fromArray()`/`toArray()`. New persistent properties need a default and the appropriate map entry; preserve stored casing or provide a deliberate migration.

| PHP property | Stored key |
| --- | --- |
| `bucket`, `region`, `url` | Same names |
| `iamKey`, `iamSecret` | `iam_key`, `iam_secret` |
| `cloudfrontUrl`, `distributionId` | `cloudfront_url`, `distribution_id` |
| `useCloudFront`, `urlType`, `serviceProvider` | `use_cloudfront`, `url_type`, `service_provider` |
| `useCdnForMedia`, `useCdnForAssets` | `use_cdn_for_media`, `use_cdn_for_assets` |
| `canEnableMediaCDN`, `canEnableAssetCDN`, `mediaAutoDelete` | Same camelCase names |
| `googleServicePath`, `cloudflareEndpoint`, `cloudflareUrl`, `bunnyStoragePassword` | Same camelCase names |

Bunny's old `apiKey|storagePassword` secret is read compatibly into separate fields. Provider-neutral APIs are `uploadObject(key,path)`, `downloadObject(key,destination)`, `deleteObject(key)`, `listObjects()` returning `key`/`size` entries, and `getPublicBaseUrl()` returning a normalized URL including any bucket prefix. `putS3Object()` is a compatibility wrapper. Avoid routing Google/Bunny through nullable `getS3Client()`.

`SMARL_CustomUrl` maps `certificateArn` to `certificate_arn`, with `domain` and `records` unchanged. Certificate AJAX uses its documented camelCase payload separately.

Google credentials are encrypted with AES-256-GCM and a key derived from `wp_salt('auth')`, stored under `WP_CONTENT_DIR/smartsync-private/site-<hash>` or an absolute `SMARTSYNC_CREDENTIALS_DIR` override. Per-user credential selections use `smarl_google_credentials_<user_id>` transients. Validated older plaintext uploads/temporary files migrate to this storage. Do not change site salts without planning credential replacement; do not expose decrypted data in reports or logs. `cleanupCredentials()` deletes only the helper's owned files.

## Sync persistence and concurrency

`SMARL_S3SyncSettings` stores the small public progress snapshot in `smarl_sync_settings`; full work manifests live in SQL. Its validated loader accepts only supported states/types and typed public fields, ignores old full manifests, and marks an old processing snapshot without a run ID as interrupted.

Important fields include `runId`, `batchNumber`, `cloudFingerprint`, direction/type, total/processed counts and bounded success/error lists. `saveForRun(runId)` reads durable option data and conditionally updates the exact prior serialized value only while that run is still `processing`. This compare-and-swap prevents a stale worker from resurrecting a stopped run or replacing a later run. Keep cache invalidation for both the option and `notoptions` when changing these paths.

`smarl_sync_lock` is an atomically added option with an owner token and expiry. Start, worker and configuration paths coordinate through it. Stale recovery and owner-checked release must preserve compare conditions; a blind option delete can remove another request's lock.

## Dependencies and compatibility

Composer maps `SmartSyncS3\` to `src/`. Runtime metadata agrees on PHP 8.1+, WordPress 6.5+ and plugin/asset/stable version 1.7.1. The WordPress minimum reflects bundled Action Scheduler's declared minimum. Composer pins `config.platform.php` to 8.1.0 so resolution on a newer development runtime retains that floor. Use the lockfile for reproducible installs, and recheck platform requirements/security advisories when dependencies change.

Do not edit `vendor/` directly. There is no plugin JS build pipeline. `Documentation/assets/` is a separate bundled documentation UI, not the WordPress admin application.
