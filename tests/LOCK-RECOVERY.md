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

## 28 October 2026: restarted server and idle recovery

Status: fixed and installed. The reported idle response had a valid 15-minute lease with no run. Read-only inspection found its recorded local-server PID (21460) no longer existed, while the current server was PID 10816. The earlier recovery check handled abandoned requests only when the built-in server PID matched; it could not recognize a hard-stopped or restarted server with a different PID. The acquiring request was not recorded, so its exact interruption reason is unknown.

| Issue | Cause | Fixed | Change |
| --- | --- | --- | --- |
| Idle page stays locked for the remaining lease lifetime after a server interruption | A database expiry/PID alone cannot prove whether a different process still owns the request | Yes | New leases hold a request-owned OS file lock. A released guard on the same host proves abandonment; compare-and-delete removes only that exact lease. Live, missing-proof and other-host guards are preserved. Shutdown still releases ordinary leases. |
| No usable action in the idle-but-busy Activity card | Stop was hidden whenever progress was idle | Yes | Add Recover operation beside Clear logs. Its authorized, nonce-protected endpoint checks for abandonment without resetting a processing run or forcing an active request to unlock. Controls unlock only after fresh status confirms readiness. |
| Stop temporarily retains stale busy state | The response omitted the actual operation lease state | Yes | Stop returns the current busy/expiry information. A finishing request keeps recovery available until its lease releases. |

The diagnosed legacy lease was removed with an exact SQL compare-and-delete after confirming its PID was absent and progress was idle. Cloud configuration, upload history, queued work and progress were preserved. New guard files are host-local and contain no provider credentials. If filesystem ownership cannot be established, expiry remains the fallback.

Verification: 285 isolated PHP checks passed, including real hard process termination and preservation of a concurrent successor. Three focused admin recovery tests and the maintained settings browser workflow passed; the browser checks overview/media/assets at five widths and exercises rejected/successful recovery. Both PHPStan passes and six native WordPress sync/AJAX integration groups passed with mocked cloud operations. Focused recovery checks were repeated after final error-handling changes. No live cloud transfers were started. Nothing was committed or staged.
