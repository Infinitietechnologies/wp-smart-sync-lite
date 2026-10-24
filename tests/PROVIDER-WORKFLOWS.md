# Provider connection follow-up — 24 October 2026

Current status: [the second-audit register](audit/ISSUES.md) records the later 43 findings, their disposition and final 1.7.3 verification. This document preserves the earlier 1.7.2 capture.

SmartSync 1.7.2 repairs additional provider setup/delivery failures found after the earlier audit. The previous suites passed but did not sufficiently test actual SDK serialization, native browser-to-WordPress AJAX, conditional IAM policies, or provider response variants. Their mocks missed real workflow blockers.

## Repairs

| Workflow | Failure found | Repaired behavior |
| --- | --- | --- |
| Bunny account discovery | Only a plain array was accepted; missing detail fields could hide a zone, and account errors were generic. | Read positive pages with `Items`/pagination metadata, retain legacy-array compatibility, fetch omitted zone details, reject malformed pagination, clear stale choices, and explain account-key/permission/rate-limit/network failures without exposing secrets. |
| Bunny storage connection | Setup could report success without contacting storage; a fixed region map could choose the wrong host. | Save the provider-reported storage host, validate HTTPS and the Bunny storage domain, then authenticate a root directory request before saving. Failed checks preserve the working configuration and release the lock. |
| Bunny CDN selection | Inactive, suspended, token-protected and explicitly uncertified custom hosts could be offered as working public HTTPS delivery. | Offer eligible public pull zones, prefer system hostnames, and show an actionable reason when a zone has no usable choice. Signing token-protected URLs is outside the supported workflow. |
| Bunny existing installations | Correcting an older region-derived hostname could demand restoring already offloaded files through that wrong hostname first. | Verified metadata can repair the legacy guess for the same account/zone/region/public URL while retaining CDN and cleanup settings. |
| S3-compatible existing bucket | Wizard discovery failure prevented connection even when the credentials could access the chosen bucket. | AWS, DigitalOcean, Wasabi and R2 offer manual existing-bucket/region entry. Backend access checks still run; account-wide discovery is optional. |
| DigitalOcean new bucket | AWS SDK middleware injected the Spaces datacenter slug as an unsupported location constraint. | Keep the actual datacenter in the endpoint and use the required AWS SDK client region `us-east-1`; the serialized bucket-creation body is empty. |
| AWS direct delivery | Dotted bucket names generated HTTPS hosts incompatible with S3 wildcard certificates. China URLs and public-policy resource ARNs used the commercial partition. | Use a regional path-style public URL for dotted names, China DNS suffixes, and partition-specific S3 ARNs. Same-bucket legacy URL correction preserves offloaded-media delivery flags. |
| AWS CloudFront setup | China/GovCloud were offered the commercial OAC/ACM provisioning workflow using the same credentials. | Use direct S3 for these selections and reject unsupported automatic CloudFront setup before any provider operation. |
| Google setup/IAM | Public access could add duplicate viewer bindings, and conditional bucket IAM policies were read without requesting version 3. | Route bucket setup through the model's SDK factory; read policy version 3, preserve conditions/etag and merge unconditional viewer membership. New buckets retain uniform bucket access. |
| CDN and cleanup switches | Stale handler state could restore old credentials/flags or overwrite another configuration update. | Reload durable configuration while holding the same coordination lease used by workers/configuration requests, then save only the requested change. |
| Browser cache/package | Reusing the old asset version could keep a repaired wizard from loading. A source-only checkout omits installed Composer dependencies. | Version 1.7.2 invalidates script caches; the installable ZIP includes production dependencies and excludes development files. |

These contracts were checked against the official [Bunny account API](https://bunny.net/docs/api-reference/core/storage-zone/list-storage-zones) and [storage API](https://bunny.net/docs/storage/http), including the separate account and storage credentials. DigitalOcean documents its required SDK region in [Use Spaces with AWS SDKs](https://docs.digitalocean.com/products/spaces/reference/aws-sdks/). AWS documents the dotted-bucket TLS limitation in [Virtual hosting](https://docs.aws.amazon.com/AmazonS3/latest/userguide/VirtualHosting.html), and the partition constraints in [China CloudFront](https://docs.amazonaws.cn/en_us/aws/latest/userguide/cloudfront.html) and [GovCloud CloudFront](https://docs.aws.amazon.com/govcloud-us/latest/UserGuide/setting-up-cloudfront.html). Google's [getIamPolicy API](https://docs.cloud.google.com/storage/docs/json_api/v1/buckets/getIamPolicy) defines the requested policy version.

## Executed verification

| Layer | Result | What ran |
| --- | --- | --- |
| Isolated PHP | 128 passed | Existing regressions plus complete provider setup/operations, Bunny's 16-file saved-provider pipeline, failed connection preservation, stale writes and legacy repairs. |
| Actual SDK contracts | 8 cases included above | Real SDK clients serialize DO creation, dotted/China S3 URLs, partition policies and Google version-3 IAM requests. No fake S3 factory hides those endpoint/region decisions. |
| Admin/documentation DOM | 30 passed | Actual views/scripts, manual restricted-bucket setup, Bunny connection retry/issue text, partition-specific wizard behavior, existing settings/domain/documentation checks. |
| Native Edge + WordPress AJAX | Passed | Browser requests reach the real `admin-ajax.php`. Wrong account key returns an expected 400; retry, paginated discovery/details, hostname selection, storage probe, persistence, analytics and toggles then pass. 320/1280px screenshots; no JavaScript or unexpected local HTTP failures. |
| Native Bunny WordPress pipeline | 8 groups passed | Actual configuration/storage models and registered nonce-protected AJAX handlers. Stateful outbound HTTP fixtures, actual stored Action Scheduler jobs executed by its queue runner, 16 new plus 330 core media files uploaded byte-for-byte, streamed restore, CDN URLs/flags, real GD attachment cleanup/intentional deletion and provider/application failures. |
| Existing WordPress integration | 8 core + 6 sync passed | Separate freshly installed WordPress 7.1.3/SQLite fixture, serial execution. |
| PHPStan level 5 | Both passes clean | Runtime and bootstrap configurations; no ignored errors or baseline. |
| Packaged plugin | Activation checks + 8 native groups passed | Deactivated source in the isolated fixture, activated a separately extracted package in fresh PHP processes, verified bundled SDK/core origins, hooks/schema/scheduler and repeated the native Bunny pipeline. Restored source as the only active plugin afterward. |
| Syntax and dependencies | Clean | 33 PHP files, 14 JavaScript files and PowerShell test/build scripts; Composer strict validation/platform checks, Composer audit and npm audit passed with no reported advisories. |

The PHP pipeline uses a separate fixture at `smartsync-provider-workflows/fresh/smartsync-wordpress-audit-fixture/wordpress/`. The native browser uses the original disposable fixture and a guarded temporary MU wrapper. Both replace outbound provider HTTP, restore their state and avoid user-site data. The browser's owned PHP server was stopped and its wrapper removed.

Repeatable commands and safeguards are in [README.md](README.md), [admin/README.md](admin/README.md), [integration/providers.php](integration/providers.php), and [integration/package-smoke.ps1](integration/package-smoke.ps1).

## Installable package

`dist/smartsync-1.7.2.zip` contains 7,491 files including production Composer dependencies; tests, `.agents`, Git/editor directories and development dependency trees are excluded. The 28 maintained runtime/asset/language/root-metadata entries were SHA-256 compared individually with the final source.

Archive SHA-256: `8CDDF0EC3B10F4F5E8C045FAB773625F3C7BB65551BA8D6023F3CBDF4B4699EC`.

The archive is generated locally and ignored by Git. [build-plugin.ps1](build-plugin.ps1) can reproduce a package from the source and installed dependencies. The package activation smoke uses a separate extraction and plugin name; it never force-installs through a source junction.

The exact final archive above passed package activation and all eight native provider workflow groups, including actual persisted scheduler execution and file-byte comparison. The source plugin was restored afterward. The temporary HTTP/MySQL listeners on ports 8097 and 33079 are stopped; separate package extraction files remain under the system temporary directory for inspection.

## Remaining verification boundaries

No live Bunny/AWS/Google/DigitalOcean/Wasabi/R2 account credentials or failing deployed-site details were supplied. Actual account permissions, storage acceptance, CDN/DNS/TLS reachability, propagation and provider-side restrictions remain unverified. A storage read probe does not prove every later write will succeed; transfer failures remain failures and do not grant CDN eligibility.

CloudFront provisioning and dotted S3 CloudFront-origin delivery have not been validated against a live distribution. These tests also do not establish production-scale throughput or large-file memory limits. The passing results establish the repaired local workflows and serialized contracts, not a guarantee that every external setup works.
