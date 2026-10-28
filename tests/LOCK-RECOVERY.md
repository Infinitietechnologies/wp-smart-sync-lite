# Idle sync with an occupied operation lock

Local built-in-server recovery: a stopped local sync was idle while an orphaned 900-second lease kept the UI in “Another operation is finishing…”. New leases acquired under PHP's `cli-server` SAPI record their server process ID. Status and acquisition may remove an exact unchanged lease from the same server process only when this request does not own it: the single-request server cannot still be executing its earlier request. Other processes, other SAPIs, and currently owned leases retain the existing protection. Compare-and-delete protects successor locks. A native isolated PHP server regression covers abandoned-request recovery, current ownership, acquisition recovery, and foreign-worker protection.

Implemented 27 October 2026 in the maintained and installed plugins. No commits created.

The reported start failure and idle status are compatible: progress and the shared operation lease are separate. A read-only, plugin-disabled inspection of the local WordPress database confirmed idle progress with no run ID and a still-unexpired `smarl_sync_lock`. A later inspection confirmed that same lease had expired. The inspection did not use the supplied session cookies, run scheduled jobs or start/stop transfers.

The old lease records only its token and expiry, so the precise request that left it cannot be established from that record. A stopped transfer, configuration/attachment work, interrupted request, or failed lease release can retain a lock while bulk progress is idle. The existing default lease lifetime is 15 minutes; the status response omitted this state and the page incorrectly showed Ready with enabled upload controls.

| Issue | Cause | Fixed | How |
| --- | --- | --- | --- |
| Idle/Ready did not explain a blocked start | Status returned bulk progress only, while starts acquire the separate shared lease. | Yes | Status includes `operation_busy` and `retry_after` from an authoritative lease read, without exposing its token. A valid lease with idle/terminal progress shows Another operation is finishing and locks upload/restore controls and modes. The page polls every five seconds until it clears. |
| Interrupted requests could retain their lease until expiry | Normal release lived in finally; native exit/fatal paths can bypass it. | Yes | Track request-owned tokens and their original blog options tables. Release them at early WordPress shutdown and at PHP shutdown. The default WordPress fatal-error message path also cleans up before its error handler exits. Normal releases remove entries from the registry, avoiding extra shutdown queries. |
| Clearing a lock just because progress is idle would permit overlapping work | Stop resets progress before an active file transfer necessarily returns. Other operation types also use the same lease. | Yes | Keep the original TTL, token equality and serialized-value compare-and-delete checks. Cleanup only considers this request's owned leases, never another request's or a successor's token. Stop does not clear a worker's live lease. Capturing the original options table preserves switched-blog ownership. |
| The start error implied a bulk sync existed when one did not | The lock rejection string specifically said a sync was running/stopping. | Yes | Use a provider-neutral finishing-operation message. Client start confirmation also rechecks operation-busy status before submitting. |

Existing expired leases remain safely recoverable by the normal atomic acquisition path. No unexpired production lock was forcibly deleted. Hard process termination, unavailable databases or custom fatal templates that bypass WordPress filters can still prevent cleanup; expiry remains the recovery fallback. `retry_after` is the lease's remaining validity, not an estimate of operation completion.

Verification:

- **224 isolated PHP checks passed.** New process-level fixtures cover native exit, fatal error, an earlier fatal handler that exits before later shutdown callbacks, a successor's lock and switched-blog ownership. Additional cases cover idle lease metadata, token privacy, expiry and Stop during a transfer.
- **95 admin tests passed**, including idle-but-busy controls, recovery to Ready, and a lease acquired while a confirmation is open.
- **Six native WordPress sync/AJAX integration groups passed** against the guarded disposable fixture with fake storage and blocked outbound HTTP.
- The native home-page browser workflow passed at five desktop/mobile widths, including the finishing state. Both PHPStan passes, PHP/JavaScript syntax, translation extraction and whitespace checks passed.

Six runtime/catalog files are synchronized to the installed plugin. The main stylesheet and settings controller use `busy-1` cache versions. No production transfers or provider/configuration writes were performed. The supplied cookies/access token were neither replayed nor saved in project documentation or test fixtures.
