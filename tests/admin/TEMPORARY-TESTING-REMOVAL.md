# Temporary testing cleanup

Status: removed on 28 October 2026.

The temporary Media file-type picker and restricted CDN test mode were added to test individual formats before a full-library sync. Both are removed, including their controls, request handling, upload filtering, scoped URL rewriting, status fields, localizations and styles. Normal incremental uploads, full resync, provider configuration and permanent regression tests remain.

Existing `mediaCdnTestExtensions` and `mediaCdnTestTarget` options are retired under the shared coordination lease. An active old test scope disables Media CDN delivery, full-library eligibility and local cleanup before those fields are removed. Empty legacy scope fields are dropped without altering normal delivery settings. Provider credentials, destination, asset delivery, upload receipts and run progress are preserved. If the lease is occupied or persistence fails, deserialization still applies the safe Media settings and a later load retries cleanup.

Old cached pages submitting the removed format-selection field receive a refresh instruction before any run or queue is changed. Old queued partial-format uploads can finish but cannot establish full-library CDN eligibility. Run a normal incremental Media upload before enabling full-library Media delivery after retiring an active test scope.

Regression coverage: `tests/cases/media_release_cleanup.php`, `tests/admin/media-release-cleanup.test.cjs`, and the maintained `tests/admin/settings-home-browser.cjs` browser workflow. Tests use isolated files/options and mocked cloud operations.

Validation: 281 PHP regression cases passed; both PHPStan passes passed; six native WordPress sync/AJAX integration groups passed with mocked cloud operations; three cleanup admin cases passed; the settings-home browser workflow passed at five widths. Updated ten installed runtime files and verified their hashes. The installed site had no retired option fields remaining. No live cloud transfer was started and nothing was committed or staged.

The older `settings.test.cjs` subset was also attempted: six cases passed and seven failed because its older harness lacks the current confirmation API and its analytics/progress assumptions predate the current page. These failures are outside the temporary-feature cleanup; they are not included in the passing admin checks above. Permanent tests are retained. The PHP fixture was brought up to date with the existing upload-history table and no-cache header double.
