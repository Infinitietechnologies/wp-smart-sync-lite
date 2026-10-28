# Changelog

## SmartSync Lite 1.8.0

- Create the separate lite edition, branded SmartSync Lite.
- Retain Amazon S3, DigitalOcean Spaces, Wasabi and Bunny media offloading, restoration and delivery.
- Display Google Cloud Storage, Cloudflare R2, Amazon CloudFront and JS/CSS synchronization as disabled **Pro only** previews with upgrade links.
- Remove Google integration/dependencies, R2 connection logic, CloudFront provisioning/certificate/DNS/cache implementations, asset sync controls and script/style URL rewriting.
- Reject Pro requests, inherited asset jobs and injected JS/CSS rows before transfers. Preserve old Pro configuration without activating it.
- Keep the 1.8.0 media reliability fixes. Lite validation is recorded separately in tests/LITE.md.

The entries below describe the historical full edition and its verification, before the Lite branch was created.


## 1.8.0

This release consolidates the changes since the initial Git commit (`feb1f97`, version 1.7.1), including the 1.7.2 and 1.7.3 fixes recorded in `readme.txt`.

### Added

- Incremental synchronization backed by persistent upload history, skipping unchanged files and retrying unsuccessful uploads.
- Synchronization status widgets, recovery controls, notifications, and provider setup guides.
- Regression coverage for provider workflows, CloudFront, request-lock recovery, transfer diagnostics, attachment ownership, and WordPress compatibility.

### Changed

- Refreshed settings, cloud setup, custom-domain screens, subpage headers, and typography.
- Improved provider connection verification, saved Google credentials, Bunny storage endpoints and streaming uploads, and resumable CloudFront provisioning.
- Automatic database schema upgrade from version 2 to 3 for upload history.
- Safe migration of legacy credentials and removal of retired media-test settings.

### Fixed

- Interrupted synchronization, cancellation, abandoned request locks, stale progress/configuration caches, cyclic listings, and unbounded progress logs.
- Cloud-object ownership across storage changes and shared attachments; local cleanup now protects changed files.
- Provider discovery, bucket/region handling, public-access policy updates, and settings persistence.
- Stale admin polling, browser navigation recovery, and active synchronization indicators.
- Premature translation loading during legacy progress recovery on WordPress 7.1.3.

### Compatibility and upgrade

- Requirements remain WordPress 6.5 or later and PHP 8.1 or later.
- Verified 32 supported combinations across WordPress 6.5 through 7.1.3 and PHP 8.1 through 8.5. See [verification evidence](tests/RELEASE-1.8.0-COMPATIBILITY.md).
- PHP regressions passed 285 cases on each of PHP 8.1, 8.2, 8.3, 8.4 and 8.5; 134 admin/documentation DOM tests passed.
- Prepared a compatibility workflow for current WordPress maintenance releases and supported PHP combinations; temporarily disabled at the user's request.
- Database upgrades run automatically. Existing cloud configuration is migrated by the existing upgrade paths.

## Earlier versions

See the [WordPress readme](readme.txt) for the preserved 1.7.3, 1.7.2, 1.7.1, and 1.0 entries. Historical audit reports and archive hashes retain their original version numbers.
