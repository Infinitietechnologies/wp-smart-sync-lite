# Bunny analytics timeout

Updated 24 October 2026. Status: **fixed and applied to the installed plugin**. No commits created.

The reported PHP fatal occurred inside WordPress's cURL transport after 120 seconds. Analytics previously called the complete recursive file listing, and each Bunny storage request allowed 120 seconds—the same as this site's PHP execution limit. A stalled request could therefore terminate PHP before a JSON error could be returned. The log alone does not establish why that particular network call stalled; bounded read-only probes subsequently reached both Bunny endpoints successfully.

| Issue | Fixed | How |
| --- | --- | --- |
| Analytics could consume the PHP execution allowance while scanning directories | Yes | Bunny analytics reads the selected zone's `FilesStored` and `StorageUsed` from the account API instead of walking file directories. Requests use the account key, not the storage password. |
| A stalled HTTP request could reach the 120-second fatal | Yes | Each usage request is capped at eight seconds within a twenty-second overall budget, shortened to leave five seconds before a finite PHP execution limit. Pagination is bounded and repeated pages are rejected. PHP and WordPress core time limits were retained. |
| Generic errors concealed useful connection/permission information | Yes | Credential-free provider exceptions become structured AJAX errors with specific connection, authentication, rate-limit or response-validation guidance. Provider response bodies and credentials are never included. |
| The browser could wait indefinitely and mark unavailable billing as an error | Yes | Analytics has a 35-second client timeout, ignores duplicate clicks, restores its button on all outcomes and retains Unavailable for unsupported billing. Successful provider totals are labeled as provider-reported usage. |

Missing or malformed totals are errors, never fabricated zeros. Valid zero totals are preserved. The selected account/zone is validated before reporting usage; zone details are fetched only when list responses omit totals. No recursive storage fallback is used for Bunny analytics. Other providers retain their existing common listing totals, and bulk listing/upload/download paths retain their existing storage credentials and transfer timeouts.

The provider fields were checked against the [official Get Storage Zone schema](https://docs.bunny.net/reference/storagezonepublic_index2) and a read-only response for the configured zone.

## Verification

- `php tests/run.php`: **199 passed**. Ten new cases cover pagination/details, zero/invalid metrics, zone identity, bounded transport failures, safe authentication/rate-limit errors, malformed/cyclic responses, remaining PHP allowance, non-Bunny totals, pagination limits and account-key requirements.
- `npm test --prefix tests/admin`: **70 passed**, including timeout/duplicate-click recovery, actionable provider errors, finite request options and unavailable billing.
- `composer test --working-dir=tests/static`: both PHPStan passes completed without errors. Changed PHP/JS syntax and `git diff --check` passed; translation catalog regenerated.
- A read-only invocation of the installed `wp_ajax_fetch_cloud_analytics` action under native WordPress/WP-CLI, with an administrator capability and valid nonce, returned `success: true` in **0.83 seconds**. Bunny reported zero files and zero bytes at that check. A before/after settings hash confirmed the saved configuration was unchanged. No cloud resources, transfers or sync jobs were created.
- Native provider/browser HTTP fixtures were updated to supply account usage totals and reject a recursive analytics crawl; those separate native suites were not rerun for this change.

Five runtime files and the translation catalog match the installed plugin copy. The settings script cache version is `analytics-2`. The user's existing `Plugin.zip` was retained.
