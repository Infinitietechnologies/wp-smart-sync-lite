# Provider-neutral analytics request

Updated 24 October 2026. Status: **fixed**.

The settings page posted `fetch_aws_analytics` because its action and handler names survived from the original AWS implementation. The implementation already used `SMARL_CloudConfig::listObjects()`, which dispatches to the connected provider. For Bunny, it uses the Bunny storage endpoint and storage-zone password.

The browser now posts `fetch_cloud_analytics`, handled by `fetchCloudAnalytics()`. The former action/method remain compatibility aliases for cached scripts and integrations. Both paths enforce the same administrator capability and nonce. The settings script's asset version is advanced to `analytics-1`. Counts, storage totals and unavailable billing behavior retain their existing contracts.

Verification: 189 PHP tests and 62 admin tests passed; both PHPStan passes and changed PHP/JS syntax checks passed. New cases exercise both registered actions across all six provider identifiers and verify that the actual Bunny storage transport supplies analytics. Integration/browser fixtures were updated to use the new canonical action and clear both registered hooks; native integration suites were not rerun for this naming change. All verification used fixtures; no live provider analytics request was made.

The three runtime files are synchronized to the installed plugin. No commits were created, and the user's existing `Plugin.zip` was retained.
