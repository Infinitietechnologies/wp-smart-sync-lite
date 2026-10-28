---
name: smartsync-wordpress
description: Maintain the SmartSync WordPress media-offloading and CDN plugin using its SmartSyncS3 and SMARL codebase conventions. Use for changes or debugging involving its cloud-provider wizard, Action Scheduler sync queue, attachment handling, CDN URLs, settings, or admin UI. Applies to this plugin and its copies, not general WordPress or cloud-storage projects.
---

# SmartSync WordPress

This checkout's `lite` branch is SmartSync Lite. Only Amazon S3, DigitalOcean Spaces, Wasabi and Bunny media operations are functional. Google Cloud Storage, Cloudflare R2, CloudFront/certificate provisioning and JS/CSS asset synchronization are Pro-only previews. Historical references describe the full edition; do not reintroduce those implementations or dependencies into Lite. Keep server, background-worker and UI restrictions consistent.

Use this skill to make scoped changes in the SmartSync plugin while following its actual PHP, database, scheduler, and JavaScript contracts.

## Locate the plugin and choose context

Find `aws-s3-smartsync-wp.php`, `composer.json`, and `src/SMARL_SmartSync.php` in the workspace. The original checkout is `C:/projects/wordpress/smart-sync/Plugin`; use the current checkout when working on a copy. All source paths in these references are relative to that plugin root. Read applicable `AGENTS.md` instructions first.

Read [architecture.md](references/architecture.md) for bootstrap, persistence, dependency, or lifecycle changes. Load only the additional context needed:

- For batch processing, restore, stop/restart, attachment uploads/deletion, or CDN rewriting, read [sync-and-media.md](references/sync-and-media.md).
- For provider configuration, AJAX contracts, custom domains, admin views, JavaScript, or CSS, read [admin-and-providers.md](references/admin-and-providers.md).
- For available checks and task-specific regression cases, read [validation.md](references/validation.md).

References describe the current implementation and its verification boundaries. Recheck relevant code before relying on an observation; the executable regression suites under `tests/` capture the repaired behaviors.

## Trace the complete behavior

Start from the registered WordPress hook or browser request and follow the path through handler, settings model, storage client, and consumer. Method names and comments alone do not establish that a feature is wired or working.

For coupled changes:

- Keep PSR-4 namespace `SmartSyncS3\` and existing `SMARL_`/`SMARLI_` class and file naming consistent. Class declarations must match their autoloaded filenames.
- Add persistent cloud settings to initialized public properties and the appropriate `SMARL_CloudConfig::FIELDS` or `BOOLEAN_FIELDS` map shared by its serializers. Preserve stored key casing or provide a migration. Sync progress has separate validated fields and conditional run-scoped persistence.
- Change AJAX action names, POST fields, JSON payloads, localized objects, view selectors, and JavaScript consumers together when their contracts change. The distinct globals `ajax_object` and `ajaxObject` are both used.
- Preserve the established WordPress-root-relative object key layout unless the task includes migration. Single-site scans begin at `ABSPATH`; multisite media scans/restores stay in the current uploads directory and exclude other sites' reserved upload trees. Retain network permission and MIME-policy guards for restores.
- Use the provider-neutral object APIs for bulk uploads, downloads, attachment handling, deletion, and analytics. All six providers have dispatch coverage; protocol tests still do not establish real account permissions or delivery.
- Retain server-side `manage_options` and nonce checks for privileged AJAX operations. Configuration/domain writes and background work coordinate through the shared lock; preserve run tokens and compare-and-swap progress writes when changing that flow.
- Use the existing text domain `smartsync-lite-media-offloader-and-assets-cdn` for translatable strings. Localize new JavaScript strings through the appropriate PHP object. Match output escaping to its HTML, attribute, URL, or JSON context.

## Keep the change and validation scoped

Edit maintained plugin code in `src/`, `assets/`, or the root entry points. `vendor/` is Composer-managed. `Documentation/assets/` is the separate documentation site's bundled UI, not the plugin's admin UI. There is no Node build pipeline in the reviewed checkout.

For background sync changes, evaluate progress accounting, queue row state, outstanding jobs, and concurrent stop/configuration requests together. For auto-delete changes, verify final metadata upload, response URLs, deferred cleanup, responsive/stored HTML, and intentional cloud deletion as one workflow.

Use local syntax checks and isolated WordPress fixtures or mocked storage clients appropriate to the change. Cloud provisioning, public-access changes, invalidation, real sync, and deletion are external side effects; run them only within the user's authorized task and chosen test resources. Never place real credentials in the skill, test fixtures, logs, or reports.

Report the resulting behavior, files changed, checks actually run, and any remaining runtime limitation. A passing syntax check does not establish successful cloud sync or restore.
