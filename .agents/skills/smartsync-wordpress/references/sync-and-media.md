# Background sync, attachment handling, and URLs

## Entry points and storage contract

`SMARL_AjaxHandler::initializeActions()` registers authenticated runtime AJAX actions for sync start/status/state/stop, CDN switches, automatic cleanup, analytics and CloudFront invalidation. Every handler checks `manage_options` and `check_ajax_referer('smarl_nonce', 'nonce')`; authentication or a restricted menu alone is insufficient.

The Action Scheduler callback now accepts five arguments:

```text
processS3Batch(batch_number, settings, batch_files, direction, run_id = '')
```

Schedules pass those values under `batch_number`, `settings`, `files`, `direction`, `run_id`, in group `smartsync`. The first three legacy fields remain compatible, but the SQL queue is authoritative; `settings`/`files` payloads are empty. Four-argument jobs without a run token deliberately return without touching a later run. Preserve accepted argument count, order, group and run validation together.

Bulk, restore, attachment and deletion paths use `SMARL_CloudConfig::uploadObject()`, `downloadObject()`, `deleteObject()` and `listObjects()` across AWS, DigitalOcean, Cloudflare, Wasabi, Google and Bunny. Do not introduce an S3-only dispatch for provider-neutral behavior. Listing entries have `key` and `size`; queued items normalize these into `path`, `relative_path`, `size`, `run_id`, `status`.

## Starting and processing a run

`syncLocalToAws()` and `syncAwsToLocal()` delegate to `startSync(direction)`. Start validates the exact `media`/`assets` type, obtains the coordination lock, reloads durable provider/progress state, rejects a concurrent processing run, cancels older queued callbacks and generates a new `runId`. A fingerprint binds the run to the storage provider/bucket/credential configuration.

Single-site uploads scan `ABSPATH`, not only the uploads directory, with a case-insensitive selected extension allowlist. On multisite, media upload/restore is restricted to the current `wp_upload_dir()['basedir']`; the main site excludes reserved `sites/<numeric_id>` subtrees. `scanSyncFiles()` applies this scope while retaining WordPress-root-relative object keys. `scanLocalFiles()` sorts keys and skips symlinks, dot paths, `node_modules` and credential directories. An empty extension list is rejected rather than treated as upload everything.

Restore lists provider objects, filters extensions, validates each contained destination and preserves the expected byte count. `replaceQueue()` replaces durable work rows. Work manifests are no longer stored in the progress option or scheduler arguments. A successful start response means accepted/queued, not verified transfer completion.

Multisite media manifests skip foreign/shared keys and workers recheck every queued key with `isCurrentSiteMediaKey()` before provider work. Shared asset restore requires `manage_network_options` before configuration/listing calls. A site administrator's restore also respects WordPress allowed MIME policy rather than importing disallowed formats such as SVG merely because a filename is on the plugin extension allowlist. Do not replace these guards with a menu restriction or apply the initiating user's capability checks to userless cron workers.

Batch size is normally `SMARL_Constant::$processBatchSize` (15), or 2 for Google. Initial and later callbacks use the same queue selection. Workers select pending rows ordered by `id` with offset 0 for the current run, claim each as `in_progress`, and record `completed` or `failed` from actual provider outcomes. Completed/failed rows disappear from the pending selection, so advancing an OFFSET would skip work. There are no cached SQL pages to invalidate.

The callback checks run identity before and after each transfer, honors the expected `batchNumber`/direction, and rejects a changed storage fingerprint. It uses a coordination lease, retries later when another owner holds it, recovers interrupted `in_progress` rows under the lease, and yields between files after its elapsed-work budget. A single already-started provider transfer can finish before cancellation takes effect.

Downloads write to a temporary file in the target directory, check expected size when known, then rename it into place. Failure/truncation retains the previous destination and removes the temporary file. Keep directory creation, destination containment, expected size and final replacement together.

## State, progress and cancellation

`SMARL_S3SyncSettings` separates UI state (`default`/`syncing`) from execution status (`idle`/`processing`/`completed`/`failed`). Direction and type are independent. Counts come from SQL row states; processed means completed plus failed, while success logs represent successful object keys. Logs are bounded and avoid raw provider exceptions, signed URLs and credentials.

Completion requires every expected row completed, no pending/in-progress/failed rows and no recorded errors. Only successful upload completion grants the corresponding CDN eligibility flag. Failed upload runs revoke that type's eligibility/enabled state; download completion does not grant upload eligibility.

`saveForRun(runId)` uses a durable compare-and-swap against the exact serialized prior option, requiring the same currently processing run. Keep it on worker progress/completion paths: a plain save can resurrect a stopped snapshot after another request resets it. Reloading also clears stale option and `notoptions` cache entries.

`stopAllProcesses()` resets progress/run identity and unschedules batch actions. Old callbacks and in-flight snapshots cannot overwrite a newer run. Status polling can restore a missing current-run callback. The browser may request only the state consistent with actual execution; it cannot manufacture a processing state.

`smarl_sync_lock` uses atomic option insertion, a token and expiry, with owner-checked release and stale-value recovery. Stop/deactivation do not blindly release another worker's live lease. Do not weaken lock ownership or conditional progress persistence when changing retries or polling.

Before disabling media CDN delivery, changing the storage target, or starting another upload scan, paths that would strand tracked offloaded files require restoring those local copies. `hasMissingOffloadedFiles()` inspects `_smarl_offloaded_files` manifests. Repairing credentials for the same provider, bucket, region, endpoint and public URL preserves CDN/domain settings and does not require a restore or provision a new distribution. Keep these guards aligned with provider configuration and CDN controls.

## Path safety

`relativeKeyForPath(path, root = ABSPATH)` and `resolveLocalPath(key, root = ABSPATH)` normalize separators and enforce containment, including existing ancestors of missing destinations. They reject absolute keys, traversal/empty segments, control characters, drive/stream syntax and escaping symlinks. Use them for scans, restore, attachment uploads and deletion; string replacement is not containment validation.

## Attachment generation, delivery and cleanup

The core registers attachment/metadata hooks once. `add_attachment` marks an attachment pending. `wp_update_attachment_metadata` coalesces intermediate or update-only metadata. The final `wp_generate_attachment_metadata` callback runs at `PHP_INT_MAX` and uploads the passed final manifest synchronously, before REST/editor code chooses delivery URLs. Identical final metadata persistence does not repeat successful uploads.

On `switch_blog`, pending/cleanup/completed metadata and local-delivery/deleted-key tracking are saved per blog, while provider configuration and both registered handlers are refreshed. Shutdown processes each queued blog in its own context and restores the caller's context. Identical attachment IDs on different blogs must not collide or share a local-cleanup suppression flag.

Collect files from `get_attached_file(id, true)` and its directory, including generated sizes, `original_image` and `_wp_attachment_backup_sizes`. Never use the current upload month's directory for an older attachment. Validate each size basename and contained path before provider calls.

`_smarl_offloaded_files` tracks successful remote keys; `_smarl_offload_error` records a generic recoverable error. A failed original/size upload retains every local attachment file. Successful uploads do not depend on CDN enable switches.

Only local cleanup is deferred to `shutdown`. It requires all needed files uploaded, `mediaAutoDelete`, enabled media CDN and a valid public base URL. Update-only metadata is uploaded at shutdown; if a getter already returned a local URL for that attachment in the same request, cleanup retains those local files. This prevents a response referencing a file deleted later in that request.

A nesting-safe cleanup context suppresses the plugin's cloud deletion filter and resets in `finally`, including when another deletion filter throws. User attachment deletion remains distinct: `delete_attachment` removes tracked remote-only keys; the bounded `wp_delete_file` filter covers actual uploads-directory file deletion. Per-request key tracking avoids duplicate cloud deletes. Provider failures must not crash unrelated WordPress deletion.

## CDN URL and HTML behavior

`getPublicBaseUrl()` returns a normalized URL including any provider bucket prefix. Do not blindly prepend `https://` to already normalized bases.

The core rewrites only trusted site/network HTTP(S) origins, preserving query/fragment and removing matching installation/virtual site prefixes so URL paths agree with `ABSPATH`-relative object keys. Multisite media maps its current uploads URL to the physical current uploads directory and validates that key's site scope, rather than treating another site's upload as its own. Unrelated hosts, explicit foreign ports, unsafe paths and already rewritten URLs remain unchanged. Asset filters are always registered; assets and media check separate current-blog enable switches.

`getAttachmentUrl(url, attachmentId)` and responsive source filters keep delivery local while a local original is pending or has an offload error. If the original is absent, legacy offloaded media retains CDN delivery. `wp_calculate_image_srcset` rewrites each first-party candidate; `wp_get_attachment_url` alone does not cover responsive image URLs.

Existing content is handled with `WP_HTML_Tag_Processor`, through `the_content` and `wp_content_img_tag`. It edits only media `src`/`srcset`, file-link `href`, and video `poster` attributes for known first-party uploads paths, using attachment IDs or standard thumbnail-to-original lookup where available. Safe root-relative attributes are resolved against the trusted site origin before matching and retain query/fragment; failed media retains its original relative URL. Document-relative, data and external URLs, text and script content stay intact. Avoid raw HTML string replacement. Verify generated responsive HTML and stored markup in real WordPress, including failed uploads and URL selection before deferred cleanup.

## Verification boundaries

Focused regressions live in `tests/cases/core.php` and the sync/provider cases. `tests/integration/core.php` exercises real WordPress schema upgrades, GD metadata, responsive/stored-content rendering, local failure fallback, lifecycle and uninstall/reactivation using a fake storage model. The integration script refuses to run outside the explicitly named disposable fixture and blocks external HTTP.

Provider protocol tests and syntax/static checks do not establish live cloud behavior. Follow `validation.md` and the test READMEs; use chosen test resources only when a user has authorized real provider operations.

`tests/integration/multisite.php` has staged MySQL network lifecycle, new-site initialization, switched runtime/media and site-administrator authorization cases. It requires the explicitly isolated MySQL audit fixture, mocks storage and blocks external HTTP. Run each site's seed stage in its own bootstrap before the network lifecycle stage so Action Scheduler tables exist for that blog. Never initialize or shut down an existing user database server to run these checks.
