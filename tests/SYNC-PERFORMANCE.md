# Sync batch scheduling and remaining-time display

Implemented 27 October 2026 in the maintained and installed plugins. No commits created.

| Issue | Cause | Fixed | How |
| --- | --- | --- | --- |
| Normal continuation batches could wait for another queue wake-up | Each completed batch scheduled its successor at `time() + 2`. The Action Scheduler runner only claims due actions; it can exhaust its due queue before those two seconds pass. Its admin async dispatcher is throttled to once per 60 seconds, while its async handler also has a five-second chaining sleep. Which runner handles the action affects the observed delay. | Yes | Schedule a normal continuation at `time()` so it is eligible for the current runner's next claim. Keep the five-second retry for an occupied coordination lease. No vendor/global scheduler filters, batch sizes, concurrency or transfer timeouts changed. |
| An ETA such as `216:50` did not identify its units | The main activity display rendered unlimited minutes and seconds, while the widget used a different clock format. | Yes | Use localized explicit units, such as `~3 hr 37 min`, under Time remaining. Short waits use `<1 min`; longer waits use days/hours. A tooltip explains that this is approximate and includes provider/queue effects. |
| Estimates grew during idle intervals or disagreed between displays | Main activity and the widget computed estimates independently. The widget recomputed throughput against the current clock even without new completed files. | Yes | Main activity consumes the widget's shared numeric and formatted timing event. The rate measures completed progress observations, so unchanged polls do not steadily inflate it. Initial observations show Calculating; stale/disconnected progress shows Updating until fresh observations arrive. |
| Existing isolated PHP tests could not exercise successful uploads | Their in-memory database omitted the upload-history table already used by production schema version 3. This caused broad upload/attachment failures unrelated to the new scheduling change. | Yes | Add the production history columns/composite key to the isolated database fixture. Update the native integration's all-file upload checks to explicitly request Full resync rather than depending on the former non-incremental default. |

The due-continuation change affects uploads and restores for every provider. The existing 15-file cap (two for Google), 20-second between-file work budget, per-file outcome tracking, run token, batch cursor, configuration fingerprint, owner lease and compare-and-swap progress checks remain in place. Slow single-file network requests may still exceed that between-file budget; the change does not claim to measure or remove provider latency, server contention, unrelated queued actions, or blocked loopback/cron requests.

The five-second lock-contention retry is intentional: making a contended job immediately runnable would repeatedly claim work another worker still owns. Stop/cancel and missing-job recovery retain their existing behavior. An already-running PHP request retains its loaded code; subsequent worker requests use the installed change without resetting the run or its upload history.

Verification:

- **217 isolated PHP checks passed**, including immediately due upload/download continuations, retained contention backoff, exact file accounting, stop/concurrent-run protection and the incremental history cases.
- **Six native WordPress sync/AJAX integration groups passed** in the guarded disposable fixture. Actual persisted Action Scheduler continuation timestamps were checked as due; transfers used fake storage, with all outbound HTTP blocked.
- **93 admin tests passed**, including a 13,010-second estimate rendered as `~3 hr 37 min`, shared main/widget output, stable estimates on unchanged polls, stale/recovered progress, resumed-run baselines, unit boundaries and literal translated text.
- Native sync-status, home-page and shared-widget browser workflows passed at their existing desktop/mobile widths. The sync-status workflow includes long explicit ETA values at 320/390/768/1440px. The old immediate visibility assertion now waits for the existing collapse animation to finish.
- Both PHPStan passes, PHP/JavaScript syntax, translation extraction and whitespace checks passed.

No live transfer was started/stopped, no production queue was drained, and no credentials/provider settings were changed. This verifies scheduling eligibility and behavior, not an end-to-end live speed benchmark. Six runtime/catalog files are synchronized to the installed plugin. Main-page styles and the two progress consumers use `eta-1` cache versions; synthetic previews are in `dist/sync-estimate-preview/`.
