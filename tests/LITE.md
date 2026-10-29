# SmartSync Lite 1.8.0 verification

The independent [SmartSync Lite repository](https://github.com/Infinitietechnologies/wp-smart-sync-lite), developed on `main`, retains Amazon S3, DigitalOcean Spaces, Wasabi and Bunny **media** operations. Google Cloud Storage, Cloudflare R2, Amazon CloudFront (including custom domains, certificates, DNS and cache invalidation), and JS/CSS synchronization have disabled **Pro only** previews and upgrade links. The maintained Pro integration implementations and Google dependencies are removed.

Lite's complete six-commit source history through `0c6414affa07287734ad2e2ad8ced66cb32e4eab` was preserved when moving from the former `lite` branch of the full-edition repository. Full-edition release tags were not copied into Lite. Repository separation changes project location and documentation, without changing the verified plugin runtime or the existing Lite ZIP.

Legacy settings remain readable for ownership tracking and uninstall cleanup. They do not authorize a Pro client, delivery URL or worker. Existing offloaded media on a Pro-only configuration must be restored using the full edition before switching. Both editions share class names and state; activate only one edition at a time.

WordPress.org release preparation verified the live version is 1.4 and the existing main file is `aws-s3-smartsync-wp.php`. Directory packages now use the existing WordPress.org slug `smartsync-lite-media-offloader-and-assets-cdn`, preserving the installed plugin basename. The directory artifact is `smartsync-lite-media-offloader-and-assets-cdn.1.8.0.zip`. The earlier GitHub-oriented archive below used `smartsync/`; its activation check does not establish a WordPress.org upgrade.

Verified on 2026-10-28:

| Check | Result |
| --- | --- |
| PHP regressions, PHP 8.1 / 8.2 / 8.3 / 8.4 / 8.5 | 223 passed, 0 failed on each runtime |
| Admin DOM regressions | 97 passed, 0 failed; PHP view warnings fail rendering |
| Native WordPress 6.5 / PHP 8.1 | Core, SQL sync, core audit, sync audit and Bunny provider integrations passed |
| Native WordPress 7.1.3 / PHP 8.1 and PHP 8.5 | Same five integrations passed on both runtimes |
| WordPress diagnostics | No unexpected PHP diagnostics; all three debug logs empty |
| PHPStan level 5 | Runtime and entrypoint passes; no baseline or ignored errors |
| Maintained PHP / JavaScript syntax | Passed |
| Composer | Strict validation, platform requirements and locked dependency audit passed |
| Browser previews | Settings, provider setup and CloudFront preview checked at 1280px and 390px; controls disabled, no horizontal overflow |
| Extracted Lite ZIP, WordPress 7.1.3 / PHP 8.5 | Activated successfully; SDKs loaded from the extracted package; core/AJAX/scheduler/schema checks passed; Pro clients absent; no PHP debug output |
| WordPress.org 1.4 → 1.8.0, WordPress 7.1.3 / PHP 8.1 | Installed the downloaded public 1.4 ZIP, seeded synthetic legacy settings, upgraded through the real WordPress plugin installer; retained the installed basename, active state, S3 settings and existing data; schema 3 and an empty debug log verified |
| Staged WordPress.org SVN runtime, revision 3772032 | Exported the pinned server revision; all 3,990 files matched the frozen runtime package by SHA-256, using the interim readme with stable tag 1.4 |
| Published WordPress.org 1.8.0, revision 3772052 | Public API reports 1.8.0; downloaded public ZIP contains exactly 3,990 files, the correct installed basename and dependencies, and every file matches the frozen final release by SHA-256 |

Lite-specific regressions cover crafted provider discovery/configuration, all retired Pro AJAX actions, CloudFront requests, asset upload/restore/delivery requests, inherited asset jobs, injected JS queue rows, preserved legacy Pro settings, absence of asset URL hooks, disabled provider choices, private settings excluded from previews, badges and exact upgrade URLs.

The native WordPress fixtures use real MySQL, WordPress hooks/options/roles/nonces and Action Scheduler. Provider HTTP is mocked or blocked; these checks do not establish live account permissions, billing, provisioning or CDN delivery. Core files were checked against WordPress.org checksums. These are three Lite integration combinations; the older 32-combination report describes the full edition. CI remains intentionally disabled.

Commands: `php tests/run.php`, `npm test --prefix tests/admin`, `composer test --working-dir=tests/static`, `composer validate --strict --no-check-publish`, `composer check-platform-reqs`, `composer audit --locked`, and `./tests/build-plugin.ps1`. Run native integration files through WP-CLI only in their guarded disposable fixture paths, as described in [README.md](README.md).

The earlier GitHub-oriented Lite ZIP contains 6,353 entries. Its maintained files matched that release source. SHA-256: `6e969a3a589beddca31bd8030039516a590587bd4e3e1738ef3a3d4699e1b5a2`.

The locally built WordPress.org update ZIP contains 3,990 runtime entries and uses the existing directory slug. It excludes the standalone documentation website, which the plugin does not load. All runtime files match the previously verified payload; the four operational providers' external-service disclosures remain included. SHA-256: `ee1e7e2041ad6d8b83c1430426686f495a3ab58a4d2036e36c0f529424e48ae5`. The first SVN attempt was rejected during copied-tag creation. The smaller retry timed out in the client with `E175012`, but the server subsequently completed staging at revision 3772032 on 2026-10-29. The final-only publisher created release 1.8.0 at revision 3772052. The public WordPress.org ZIP was downloaded and all 3,990 files matched the final frozen release; its archive SHA-256 is `0ac6559c7c3924e38444927be47aa895dbb525c0f64be0073263aabc56c5f652`. The unused manual GitHub publisher draft was removed; workflows remain disabled and no SVN credential was stored in GitHub. See [RELEASING.md](../RELEASING.md) for publication and credential steps.
