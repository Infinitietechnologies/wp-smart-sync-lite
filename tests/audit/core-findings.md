# Core audit, second pass

Scope: every method in `src/SMARL_SmartSync.php`, every function and bootstrap callback in `aws-s3-smartsync-wp.php`, and every function/top-level path in `uninstall.php`. This pass preserves the earlier uncommitted 1.7.2 work and makes no Git mutations or commits. Review date: 2026-10-24.

## Confirmed issues and disposition

### CORE-001 — Dynamic asset requests were redirected to absent objects

- Status: fixed. Fixed: **yes**.
- Trigger/reproduction: enable asset CDN and pass WordPress `wp-admin/load-scripts.php?load=jquery` or `load-styles.php` through the script/style source filters. The old result was a CDN URL ending in `.php`; bulk asset sync never uploads PHP.
- Cause/impact: `replaceBaseUrl()` rewrote every same-origin script/style URL without checking the synchronized extension allowlist, breaking dynamic bundles and generated CSS/JS endpoints.
- Location/fix: `SMARL_SmartSync::replaceBaseUrl()` now requires a selected static asset extension before calling the origin/path rewriter. Normal `.js` query strings still rewrite.
- Evidence: initially failing `CORE-001` case in `tests/cases/core_audit2.php`; actual `script_loader_src` assertion in `tests/integration/core_audit2.php`.

### CORE-002 — Custom upload hosts could lose working delivery after cleanup

- Status: fixed. Fixed: **yes**.
- Trigger/reproduction: configure WordPress uploads at `https://media.example.test/photos` with files physically under the WordPress uploads directory, then successfully offload with automatic local cleanup. Previously the upload URL stayed on the original host while the local file was removed.
- Cause/impact: `rewriteLocalUrl()` trusted only the site/network origin and treated URL paths as physical root-relative keys. A configured uploads host/path can legitimately differ from both.
- Location/fix: `rewriteLocalUrl()` recognizes the configured uploads origin, enforces its path boundary/ports, resolves its relative suffix against the actual uploads directory, and derives the existing WordPress-root object key. Unrelated hosts still stay unchanged, and multisite scope checks remain in place.
- Evidence: initially failing `CORE-002` unit case; real WordPress `upload_dir` filter, generated GD attachment and cleanup in the second-pass integration.

### CORE-003 — Deferred cleanup could delete bytes never uploaded

- Status: fixed. Fixed: **yes**.
- Trigger/reproduction: change an original or thumbnail after the final metadata upload but before shutdown, or change a file while the provider reads it. Previously shutdown removed the changed local file while the remote object contained older bytes.
- Cause/impact: cleanup remembered file paths only. Successful upload was treated as proof for any later content at the same path, causing data loss.
- Location/fix: `uploadAttachment()`, `attachmentFileHash()`, `flushCurrentBlogUploads()` record SHA-256 before/after each upload and verify the complete current attachment file set/hashes before deleting any local member. A mismatch retains all remaining files, clears successful-request deduplication and records a recoverable offload error.
- Evidence: initially failing post-upload mutation regression; additional during-upload mutation/retry case; actual GD original and thumbnail changed after upload in the integration.

### CORE-004 — Identical metadata suppressed changed media bytes

- Status: fixed. Fixed: **yes**.
- Trigger/reproduction: upload an attachment, replace its contents in place, and save/generate an identical metadata array. The previous metadata-only hash caused the second upload to be skipped.
- Cause/impact: same dimensions/names do not imply the same image bytes; cloud delivery stayed stale and later cleanup could remove the replacement.
- Location/fix: `queueAttachmentUpload()` uses `hasUnchangedAttachment()` to compare the file set and verified hashes as well as metadata. Successful duplicate persistence still avoids duplicate transfers; changed bytes retry.
- Evidence: initially failing `CORE-004` case; same-metadata actual GD retry integration.

### CORE-005 — Media work bypassed configuration/worker coordination

- Status: fixed. Fixed: **yes**.
- Trigger/reproduction: hold a configuration/worker lease while generating an attachment, or change persisted storage/delivery settings between final upload and deferred cleanup. Old media work proceeded using the request's earlier configuration.
- Cause/impact: attachment upload/deletion/cleanup did not participate in the shared lease, and cleanup did not bind its proof to a storage/delivery target. Local files could be removed after the active delivery target changed.
- Location/fix: upload, destructive cleanup, intentional deletion and deferred deletion acquire/release the existing owner-checked lease in `finally`. `refreshMediaConfiguration()` observes changed durable configuration; per-cleanup target fingerprints reject obsolete proofs. Lock contention retains local files and permits later retry. Per-blog runtime fields include the new state.
- Evidence: initially failing held-lease and changed-target cases; actual persisted target change integration. This verifies serialized behavior, not parallel operating-system scheduling stress or a single transfer lasting beyond the lease duration.

### CORE-006 — A vetoed local deletion could still delete its cloud copy

- Status: fixed. Fixed: **yes**.
- Trigger/reproduction: a later `wp_delete_file` filter returns an empty path. The old SmartSync filter already sent cloud DELETE even though WordPress retained the local file.
- Cause/impact: a pre-deletion filter was interpreted as proof that deletion had completed, removing a cloud copy that could still be serving the attachment.
- Location/fix: `deleteFromS3()` records only a proven, owned key/path/target. `flushCurrentBlogUploads()` sends remote deletion only after the requested path is actually absent, its target still matches, and path checks still pass. `delete_attachment` remains an explicit intentional operation. Automatic local offload cleanup continues to suppress cloud deletion.
- Evidence: initially failing deletion-veto case; real WordPress veto/removal integration. Existing context-restoration regression now executes `wp_delete_file()` plus the deferred flush instead of assuming a direct filter call completed deletion.

### CORE-007 — A manifest could authorize deletion in a replacement target

- Status: fixed across core/provider/runtime coordination. Fixed: **yes**.
- Trigger/reproduction: upload only to `old-target`, restore locals and switch storage, then delete the attachment. A controlled native Bunny request demonstrated DELETE against `replacement-target` for a key that belonged only to the old target.
- Cause/impact: `_smarl_offloaded_files` contained keys without storage provenance. The same key in a new bucket/zone could identify an unrelated object.
- Location/fix: successful attachment uploads record `_smarl_offload_target` using shared `SMARL_Helper::storageTargetFingerprint()`. Core requires matching ownership for manifest and generic file deletion. Partial replacement-target uploads preserve the prior ownership until a full verified copy succeeds. Untagged ambiguous legacy manifests prefer retention. Provider migration binds legacy manifests to the previous target before switching; successful bulk copy retags only when every exact manifest key completed in the current run. Those shared helper/provider changes are owned by the root/provider agents.
- Evidence: initially failing controlled native Bunny DELETE case, partial migration and untagged legacy cases; actual WordPress replacement-target deletion test confirms no native HTTP request is made. Shared helper/provider suites cover legacy binding and complete-run retagging.
- Remaining boundary: remote objects with no provable legacy ownership may remain after deletion. They are retained rather than guessed to belong to the current target; a verified upload or migration binding establishes provenance.

### CORE-008 — Source-format and animated media companions were omitted

- Status: fixed. Fixed: **yes**.
- Trigger/reproduction: attachment metadata contains `source_image`, `animated_video` or `animated_video_poster`, and content requests that companion through media CDN rewriting. Its URL could rewrite before its object was uploaded.
- Cause/impact: `attachmentFiles()` included sizes/backups/`original_image` only. The tested WordPress 7.1.3 `wp_delete_attachment_files()` explicitly recognizes these additional companion fields.
- Location/fix: `attachmentFiles()` includes these basename-only companions in the same validated upload, ownership, hash, cleanup and intentional-delete workflow.
- Evidence: initially failing companion case; actual WordPress metadata integration with source-format, video and poster companion bytes. Codec conversion itself is not tested; transfer bookkeeping is.

### CORE-009 — Deleted obsolete keys could make restore requirements impossible

- Status: fixed. Fixed: **yes**.
- Trigger/reproduction: remove an obsolete thumbnail from current metadata and delete its local/cloud file. The old key remained in `_smarl_offloaded_files`, so the missing-local guard demanded restoring an object intentionally removed from the cloud.
- Cause/impact: successful object deletion did not update ownership bookkeeping, blocking later upload/CDN/configuration workflows.
- Location/fix: `deleteCloudKey()` calls `forgetCloudKeyOwnership()` only after a successful provider DELETE. It removes the exact key from matching-target owner manifests and invalidates corresponding request state. Owners are gathered before changing paginated query results.
- Evidence: initially failing isolated obsolete-thumbnail case with `hasMissingOffloadedFiles()`; actual WordPress manifest assertion in the second-pass integration.

### CORE-010 — Deleting one attachment could remove another attachment's cloud object

- Status: fixed. Fixed: **yes**.
- Trigger/reproduction: two attachment records reference the same physical file/key. The unchanged extracted 1.7.2 baseline was loaded under a temporary class name and deleting the first attachment removed the cloud object still owned by the second.
- Cause/impact: manifest deletion assumed one owner per object key, breaking the remaining attachment's cloud delivery.
- Location/fix: `hasOtherCloudOwner()` checks exact matching-target ownership before intentional deletion. Shared keys remain protected from the same request's subsequent generic local-file deletion. The last attachment owner can still delete the object.
- Evidence: baseline probe printed `CONFIRMED CORE-010 baseline 1.7.2 deleted the object while attachment 214 still owns its key`; focused unit regression and real WordPress two-attachment deletion workflow.

## Validation performed and limits

- `tests/cases/core_audit2.php`: 14 focused cases covering the findings and their retry/legacy/shared-owner boundaries. Full-suite totals change as the other audit agents add independent tests; the root report records the final combined total.
- `tests/integration/core_audit2.php`: six grouped real WordPress checks: dynamic/custom upload delivery; changed bytes/identical metadata; target/veto/provenance; companion files; obsolete/shared ownership; legacy wrappers.
- `tests/integration/core.php`: existing eight groups, including real schema upgrade, responsive/stored HTML, partial failure, lifecycle and uninstall/reactivation. Uninstall assertions now include `_smarl_offload_target` and `smarl_cloudfront_pending`.
- `tests/integration/providers.php`: native Bunny eight-group pipeline uses real model/AJAX/SQL/Action Scheduler with stateful local HTTP. Repeated after core changes to verify cross-layer compatibility.
- Real integration uses only the separate fresh WordPress 7.1.3 SQLite fixture under `smartsync-provider-workflows/fresh/`; no user site, credentials, live cloud requests, commits or Git mutations. HTTP is blocked except explicitly mocked Bunny responses. SDK fakes throw if a real S3 client is requested.
- Supporting Xdebug snapshots were collected into `smartsync-audit-coverage/run-audit2`; final frozen 1.7.3 source runs use the separate `smartsync-audit-coverage/run-audit2-verified` directory. This is execution evidence; it does not prove all branches or true parallel scheduling. See the root-generated function coverage report for measured totals.
- Generic deletion is deferred and best effort. Provider failures/lock contention retain remote objects; there is no durable remote-delete retry/outbox in this change. Cross-process file writers are not governed by the plugin lease. Live account permissions, CDN propagation, PHP 8.1 runtime execution and enormous networks/attachments remain outside these tests.

## Per-method review and execution map

Every listed method/function was read in full. “Executed” identifies an actual named test path; it is not a claim that every conditional branch ran. Private methods are exercised through their public workflow.

The table covers all **45 named `SMARL_SmartSync` methods**. The final source-hash-validated inventory confirms **214/214 named PHP functions executed** across the maintained plugin; anonymous callbacks and individual branches remain subject to the explicit limits below.

| Method/function | Review result and executed evidence | Explicit boundary |
| --- | --- | --- |
| `SMARL_SmartSync::__construct` | Executed by isolated and real WP bootstraps; initializes current-blog configuration and both handlers. | Entrypoint argument is a file path; directory-style external callers are not supported/tested. |
| `initializeFilters` | Executed by bootstraps; attachment/srcset/content/asset/deletion/metadata contracts checked. | Third-party filter ordering permutations are not exhaustive. |
| `initializeActions` | Executed by real bootstrap; scheduler/AJAX/media/blog hooks traced. | No live cron transport. |
| `loadPluginTextDomain` | Direct real WP wrapper group plus bootstrap. | Non-English translation catalogs not installed/tested. |
| `switchBlogConfiguration` | Existing isolated blog-state regression; prior durable multisite integration covers current handlers/context. New fields included in blog state. | This pass's fresh SQLite fixture is single-site; network branches are not rerun there. |
| `initializeViews` and its callback | Real WP `plugins_loaded` registers all three view handlers. UI agents own screen tests. | Rendering/browser behavior delegated to UI audit. |
| `registerAutoload` | Direct real WP legacy group; readable autoload is idempotent. | Missing-file no-op reviewed, not forced by changing installed files. |
| `initializeHooks` | Real activation/deactivation/uninstall fixture flow and package smoke. | Unsupported-runtime activation callback is separately noted below. |
| `onPluginUninstall` | Direct real WP legacy group; intentionally inert because WP executes `uninstall.php`. | It deliberately does not uninstall data itself. |
| `onPluginUpdate` | Direct real WP matching-plugin/schema refresh; upgrader object identity preserved. | Inert legacy API, not registered as the update driver; normal schema upgrade is `plugins_loaded`. Malformed external caller payloads not fuzzed. |
| `onPluginDeactivation` | Related/unrelated plugin calls in real legacy group. | Network-wide static hook path has prior network evidence, not a fresh single-site branch. |
| `onPluginActivation` | Related/unrelated plugin calls in real legacy group. | Same network boundary as above. |
| `getAttachmentUrl` | Unit and real success/pending/failure/custom-origin/shared-media delivery. | Legacy remote-only objects without local fallback cannot be repaired if their remote object was externally removed. |
| `rewriteImageSources` | Existing unit and real responsive HTML/srcset. | Unusual third-party source descriptor structures only retain unaffected fields. |
| `shouldUseLocalAttachment` | Pending/failure, successful retry, local-original-present and remote-only tests. | External deletion of both local and remote copies cannot be recovered. |
| `rewriteContentImageTag` | Real stored image/root-relative/pending/failure integration. | Third-party custom data attributes are outside the supported attribute set. |
| `rewriteContentMedia` and srcset callback | Actual HTML tag processor; image/link/source/audio/video scope, external/text/data preservation in existing real tests. | Exotic malformed HTML/srcset grammar is not exhaustively fuzzed. |
| `rewriteContentMediaUrl` | Absolute/root-relative lookup, attachment class/size lookup and local failure paths executed. | Document-relative URLs remain unchanged intentionally. |
| `generateUrlFromBase` | Existing normalization/invalid-base/local-cleanup tests. | Public reachability is not checked here. |
| `replaceBaseUrl` | CORE-001 dynamic/static source tests plus real WordPress source filter. | Only extensions selected for static asset sync rewrite. |
| `rewriteLocalUrl` | First-party/external/subdirectory/query/fragment/custom uploads; existing multisite mapping. | Live CDN origin behavior not tested. |
| `deActivateHook` | Actual deactivation; jobs canceled, configuration and live lease retained. | Prior network tests cover exception/context restoration; not all DB-failure permutations. |
| `activateRegisterHook` | Actual activation, repeatable schema and prior network context tests. | Site creation still uses lazy schema initialization on first plugin bootstrap. |
| `enqueueScripts` | Existing capability/page/localized-contract unit case; real UI smoke owned by UI agent. | Locale text/catalog accuracy not asserted. |
| `s3UploadNewMedia` | Real `wp_insert_attachment` dispatch in all image workflows. | No file is deleted on this initial hook. |
| `queueAttachmentUpload` | Intermediate/final/update-only/duplicate/changed-byte unit and real flows. | No durable automatic retry is introduced for an attachment upload rejected by lock contention. |
| `completeAttachmentUpload` | Actual final metadata filter plus partial/mutation/retry cases. | Providers remain synchronous at final metadata generation. |
| `flushAttachmentUploads` | Real shutdown-style explicit flush; isolated blog state and prior multisite queued-blog flush. | Cross-blog execution is not exercised in the new single-site fixture. |
| `flushCurrentBlogUploads` | Hash/set/target checks, local delivery retention, cleanup exceptions, vetoed/confirmed generic deletion. | Lease expiry during an exceptionally long single attachment and external filesystem writers are not stress-tested. |
| `refreshMediaConfiguration` | Changed durable settings and unchanged injected storage paths executed. | Non-plugin writers bypassing all WordPress option/cache conventions are not simulated. |
| `mediaTargetFingerprint` | Target/URL change and normal/credential-preserving runs. | Storage canonicalization is the shared helper contract, covered by root tests. |
| `attachmentFileHash` | Real GD originals/sizes, before/during/after mutation and duplicate checks. | Large-file I/O throughput not benchmarked. |
| `hasUnchangedAttachment` | Exact duplicate, changed bytes, changed file set and remote-only absence cases. | Deduplication remains request-local. |
| `attachmentUploadFailed` | Provider/lock/path/hash/target failures and retry cases. | Error text is generic and contains no provider secrets. |
| `uploadAttachment` | Success/partial/missing/path/mutation/provenance/companion workflows executed. | Partial replacement targets may retain orphan copies until a later verified upload/deletion; old ownership is preserved safely. |
| `canCleanLocalMedia` | Enabled/disabled/invalid public URL/local delivery cases. | A syntactically valid URL is not proof of live CDN propagation. |
| `attachmentFiles` | Originals, sizes, backups, original/source/video companions, old months and traversal rejection. | Codec generation/third-party custom metadata fields outside this documented set are not inferred. |
| `localObjectKey` | Containment/missing path and existing multisite scope tests. | Root helper owns platform-specific junction/containment implementation and tests. |
| `deleteAttachmentObjects` | Normal, old-target, untagged, unsafe manifest and shared-owner/last-owner tests. | Remote failure retains objects rather than crashing WordPress. |
| `hasCloudKeyProvenance` | Current target/untagged/wrong target and generic deletion cases. | Unknown ownership is intentionally insufficient for deletion. |
| `cloudKeyOwners` | Real WP serialized-key LIKE query and exact PHP membership checks. | Large ownership sets are paginated, not load-tested at network scale. |
| `hasOtherCloudOwner` | Two actual attachments and last-owner deletion. | Shared owners on other providers/targets do not confer ownership in the active target. |
| `forgetCloudKeyOwnership` | Obsolete exact key removal; real WP manifest updates; gather-before-update pagination reviewed. | Remote deletion and postmeta writes are not a distributed transaction; persistent DB failure has no outbox/retry mechanism. |
| `deleteCloudKey` | Successful, deduplicated, partial failure and retained shared/foreign objects. | Live provider access failures are mocked; no cloud deletion was executed. |
| `deleteFromS3` | Automatic cleanup suppression, unrelated/unsafe paths, veto/confirmed deletion and shared key protection. | Bulk-only objects with no attachment provenance are retained. |
| `smarl_file_sync_queue_create_table` | Actual legacy schema upgrade, repeat, activation and package smoke. | Real MySQL schema was verified in the prior audit; this pass uses SQLite integration. |
| `smarl_maybe_upgrade_schema` | Matching/stale schema versions through bootstrap and real legacy update wrapper. | Permission-denied DDL fault injection not performed. |
| Bootstrap top-level success path | Real WordPress/source/package loading; Composer and AS required, all initialization methods called. | PHP runtime in executed fixtures is 8.3.36. |
| Bootstrap dependency/runtime failure callbacks | Read in full; readable dependency/PHP floor guard and escaped admin/activation notices traced. | Missing dependencies and PHP below 8.1 were not induced in a running installed fixture. |
| `smarl_uninstall_legacy_credential` | Actual legacy JSON credential path removed; unrelated file retained in existing uninstall integration. | Former temporary credential root, every symlink/error branch and unreadable-file behavior not all executed. |
| `smarl_uninstall_site` | Actual options/table/meta/transient/AS cleanup, including new target and CloudFront recovery map, then reactivation. | External persistent object-cache implementation not connected. |
| Uninstall top-level/network/autoload cleanup | Actual single-site uninstall and provider-owned credential cleanup; prior multisite context evidence reviewed. | New audit did not restart the prior MySQL server or run live network/cloud cleanup. |

No additional confirmed defect was found in the remaining reviewed bootstrap/lifecycle/uninstall methods. Unexecuted branches above remain explicit verification limits, not implied passing tests.
