# Cloud setup UI update

## Header simplification — 27 October 2026

Fixed the repeated setup context in the page header. The breadcrumb, uppercase header eyebrow, introductory paragraph, separate back link and draft-provider label competed with the form. The header now contains one SmartSync settings back link, the title **Cloud setup**, and one quiet **Currently connected** line when a saved connection exists. The stepper follows directly below it; provider instructions stay in their relevant step and guide.

The current-connection line remains unchanged while editing a different provider and updates only after a successful save. For a first connection it stays hidden until saving succeeds. The requested `container-md` widths remain in place. Other plugin pages retain their breadcrumbs.

Verification: 76 admin regressions passed; the six-provider browser flow passed at 320/390/768/1440px, including a header-height check below 130px. JSON upload/preview and completion passed at five widths through 1920px. PHP/JS syntax, translation extraction and whitespace checks passed. Six runtime/catalog files are synchronized to the installed plugin, using `header-1` cache versions for the changed setup assets. Synthetic previews are in ignored `dist/compact-setup-header-preview/`. No live cloud operations or commits were made.

## Original setup update

Updated 24 October 2026. This change covers the Cloud Config page; settings, sync processing and provider provisioning APIs retain their existing contracts. No commits were created.

## Result

- A focused connection form, numbered progress cards and a provider-specific setup guide replace the single unstructured panel.
- AWS has account, storage, delivery and completion steps. The five other providers have three steps, including Bunny's combined storage/pull-zone selection. China/GovCloud S3 selections skip the unsupported CloudFront step.
- All six providers explain which credentials to obtain, where to obtain them, what permissions/setup are needed, and link to their dashboard and official documentation. R2 distinguishes its storage endpoint from its public URL. Bunny distinguishes the account key from storage/Stream credentials.
- The main form links directly to the guide, including on mobile. A dedicated stylesheet is scoped to this page and loaded after the existing admin styles.
- Missing fields receive focus and error associations. Connection/save progress is announced, controls recover after failures, empty resource lists explain how to proceed, and the storage list can be refreshed.
- Provider changes retain separate in-memory key drafts and remask secrets. Shared S3 fields no longer accidentally carry one provider's credentials into another provider's request. Nothing is saved to browser storage.
- Google file size/extension checks run before upload. Existing server-side checks remain authoritative.
- Completion shows the provider, storage, region and delivery choice, explicitly states that files have not been synced, and links to sync settings with the next actions.
- Existing AJAX names, nonces, role checks, Google selection-token binding, bucket-access checks and stale-response protections remain in place. Asset query versions invalidate the old wizard script.

## Files

The page class delegates to a typed template closure in `src/Views/templates/cloud-setup.php`. `SMARL_ProviderGuides` owns translatable provider guidance shared with the browser through safely encoded JSON. Frontend behavior remains in `assets/js/wizard.js`, and page styling lives in `assets/css/cloud-setup.css`.

The six runtime files and regenerated translation catalog were copied into `C:/projects/wordpress/main-wordpress/wordpress/wp-content/plugins/Plugin` after confirming that the original installed files matched the source baseline. Existing saved configuration and vendor dependencies were retained. The user's existing `Plugin.zip` was not replaced.

## Issues resolved

| Issue | Cause and effect | Status | Fix |
| --- | --- | --- | --- |
| Provider steps used AWS terminology throughout | Static IAM/bucket/CloudFront labels made Bunny and other provider workflows misleading. | Fixed | Provider-specific labels, three/four-step progress and AWS partition handling. |
| Setup lacked actionable preparation instructions | The form assumed users already knew where to find keys, permissions, storage and delivery resources. | Fixed | Numbered provider guides with dashboard/documentation links and credential distinctions. |
| Provider changes reused shared credential fields | Multiple S3-compatible providers shared the same visible inputs, allowing a previous provider's keys to be submitted. | Fixed | Separate in-memory drafts and remasking when switching providers. |
| Failures and empty lists gave limited direction | Generic messages did not identify the relevant field or explain how to refresh after creating resources. | Fixed | Focused accessible errors, provider-specific empty states, retry and refresh controls. |
| Completion did not clearly distinguish setup from sync | The final state lacked a useful configuration summary and concrete next actions. | Fixed | Provider/storage/delivery summary and explicit steps to configure and run sync. |

## Verification

- `php tests/run.php`: 186 passed, including provider protocol/permission/persistence regressions. No live cloud credentials or resources are used.
- `npm test --prefix tests/admin`: 56 passed, including seven new setup tests for progress/guidance, credential separation, errors/focus, refresh, completion, AWS partitions and Google files.
- `composer test --working-dir=tests/static`: both PHPStan level 5 passes completed without errors.
- `npm run test:cloud-setup --prefix tests/admin`: headless Edge passed all six provider forms, 320/390/768/1440 widths, error focus, reveal/remask, retry, unusable zones and completion. It renders the actual PHP view and source assets with synthetic AJAX responses.
- Installed WordPress 7.1.3: an authenticated read-only browser preview passed the same four widths with no JavaScript errors or missing plugin assets. Cloud AJAX was blocked, credentials were removed from the browser DOM before screenshots, and only the preview's own login session was revoked afterward. An owned preview server was used because an existing development server was unresponsive; the existing process and saved site URLs were retained.
- Changed PHP/JS syntax and `git diff --check` passed.

Credential-free installed-page screenshots are in ignored `dist/cloud-setup-preview/`. Standalone workflow screenshots and their report are under the system temporary directory `smartsync-cloud-setup-preview`.

Live provider provisioning, account permissions, cloud billing and CDN delivery are not established by these UI checks. No live cloud setup, sync or policy change was performed.

## Guide sources

Provider instructions were checked against primary documentation:

- [AWS IAM access keys](https://docs.aws.amazon.com/IAM/latest/UserGuide/access-keys-admin-managed.html)
- [DigitalOcean Spaces access](https://docs.digitalocean.com/products/spaces/how-to/manage-access/)
- [Wasabi users and access keys](https://docs.wasabi.com/docs/creating-a-user-account-and-access-key)
- [Google service-account keys](https://cloud.google.com/iam/docs/keys-create-delete)
- [Cloudflare R2 authentication](https://developers.cloudflare.com/r2/api/tokens/)
- [Bunny account keys and storage/pull zones](https://bunny.net/faq/)
