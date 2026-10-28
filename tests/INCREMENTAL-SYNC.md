# Incremental upload sync

Implemented 24 October 2026. Manual Media and Assets uploads now default to **Incremental**. No commits created.

Incremental sync uploads new, modified, failed and interrupted file transfers. Manual runs now also repair externally deleted or size-mismatched cloud copies. Potential skips need both a successful local receipt with unchanged bytes and a current matching cloud key/size. The progress total counts queued transfers; logs and the shared widget also report verified unchanged files skipped. A fully unchanged run completes without scheduling upload jobs.

The **Upload mode** control appears in both Media and Assets. **Full resync** explicitly confirms re-uploading every selected local file and bypasses inventory verification. It remains useful for same-size remote content replacements and keys that cannot list objects. Normal manual incremental sync lists only when local receipts would otherwise produce skips; it avoids separate existence requests for each unchanged file. Local receipt checks used internally for ownership binding remain local.

Cloud verification is bounded and uses a common candidate prefix. S3-compatible providers use paginated ListObjectsV2 with at most 1,000 objects per page, no SDK retries, and short per-request timeouts. Bunny visits only directory ancestors needed by the candidates and treats an absent directory as missing copies. Google uses prefix listing with a short request timeout and retries disabled. Listing stops early when all candidates have been found. Candidate metadata is retained instead of collecting an entire bucket into memory. The overall verification budget is 15 seconds (subject to PHP execution headroom); an unfinished/failed check produces a safe error, never a successful all-skipped result. Partial verification does not invalidate history. Missing/mismatched receipts are invalidated only after the check finishes.

## Existing uploads and first use

Earlier versions did not store trustworthy content hashes. Logs, completed queue entries, attachment ownership manifests and equal cloud/local sizes cannot establish identical bytes. Untracked files therefore upload once to establish a baseline; subsequent runs skip them when unchanged. This baseline does not list or download the entire cloud storage zone. Automatic Media Library uploads now establish the same history, so manual sync can skip those already uploaded files.

An already running job can finish its current queue; updated workers record successful transfers as they go. Earlier completed transfers without a receipt remain untracked. No live job was started, cancelled or restarted during implementation.

## Issue register

| Issue and trigger | Cause | Fixed | How |
| --- | --- | --- | --- |
| Every manual upload repeated all matching files | Each start replaced the queue with the complete local scan. | Yes | Queue planning excludes files whose SHA-256 and size match a successful destination receipt. Same-size edits with unchanged timestamps are detected. |
| Failed runs repeated their successful files | Success history existed only in the current queue and bounded logs. | Yes | Receipts persist separately from the queue. Successful files survive partial failure and queue replacement; unsuccessful transfers remain eligible for retry. |
| An interrupted replacement could retain an old success record | An earlier upload may have succeeded before the attempted overwrite. | Yes | Invalidate before a provider write, then record only after success, stable post-transfer contents and current-run validation. Sources modified during upload fail safely. |
| Different destinations could share file history | Filenames alone do not identify remote storage. | Yes | Receipts use the stable storage-target fingerprint and exact relative object key. Credential/CDN URL changes retain history; destination and blog tables are isolated. |
| Existing copies had no reliable baseline | Older versions never recorded uploaded content hashes. | Yes | Unknown files upload once; no unverified reconciliation from sizes or logs. |
| Deleted cloud objects appeared unchanged | Local history could not observe external cloud deletions. | Yes | Manual incremental runs verify potential skips against the provider listing and requeue missing or size-mismatched objects. An empty destination queues all selected local files. Full resync still repairs same-size remote replacements. |
| Skipped files were indistinguishable from transfers | Progress represented the full scan. | Yes | Start/status data include scanned/skipped counts and mode. Logs and the widget show unchanged counts. Mode controls lock during status uncertainty, start, transfer and stop. |
| Skipping could prevent attachment target ownership from updating | Binding previously accepted only this run's completed queue rows. | Yes | Binding also accepts destination receipts revalidated against local contents. Unverified manifest keys retain their previous ownership. |

## Persistence and limits

Schema version **3** adds per-blog `smarl_upload_history`, keyed by `(target_hash, key_hash)` and containing the relative key, SHA-256, size and update timestamp. It stores no credentials, public URLs or absolute source paths. Existing queue/configuration data survive upgrade; deactivation retains history and uninstall removes it per blog.

Hashes stream from disk. Scanning and hashing still take local disk time, particularly for large media. Files missing locally cannot be repaired from an upload. The existing requirement to restore tracked offloaded local copies before another upload remains, along with multisite scope, MIME, nonce/capability, lease, run-token and conditional-write guards. Failed receipt writes cannot become successful skips.

## Verification

- `php tests/run.php`: **218 passed**, including the exact **1,784 + 10 = 10 transfers / 1,784 skips** example, all six providers, same-size/time edits, no-op runs, partial failure/retry, interrupted overwrite, edits during transfer, credential/destination/endpoint changes, automatic media receipts, deletion, ownership binding, database failures, assets, malformed mode and per-blog isolation.
- `npm test --prefix tests/admin`: **77 passed**, including defaults, locking, cancelled full-resync confirmation, request payloads, counts, no-op completion and unchanged restore payloads.
- `composer test --working-dir=tests/static`: both PHPStan passes passed.
- Disposable native WordPress: **8 core** and **7 sync/AJAX** groups passed, covering real `dbDelta`, upgrade/uninstall/reactivation, SQL history and persisted Action Scheduler jobs. Cloud calls were mocked.
- `npm run test:widget --prefix tests/admin`: existing shared-widget checks plus both upload modes, confirmed/cancelled full resync, no-op summary and fluid upload controls at **320/390/768/1440/1920 px** passed.
- PHP/JS syntax, whitespace checks and translation extraction passed. Changed admin assets use `incremental-1` cache versions.

Runtime/catalog files are applied to the installed plugin. Synthetic previews are in ignored `dist/incremental-sync-preview/`. No real cloud writes or production transfers were used for testing. The existing `Plugin.zip` is untouched.

External-deletion follow-up: 259 PHP cases and both static-analysis passes passed. New cases cover empty/partially deleted destinations across all six provider dispatch paths, different cloud sizes, unchanged files, listing failures, Full resync without listing permission, S3 pagination/options, Bunny missing directories, and the real Google listing transport. Native SQL integration additionally verifies that one external deletion queues exactly one repair through Action Scheduler. Tests use mocked provider storage and never start a live sync.
