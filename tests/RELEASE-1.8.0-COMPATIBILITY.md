# SmartSync 1.8.0 compatibility verification

Verified locally on 28 October 2026 against the uncommitted 1.8.0 release preparation. All 32 supported WordPress/PHP combinations passed the five native integration suites, with `WP_DEBUG` and `E_ALL` enabled, zero PHP diagnostics and empty WordPress debug logs. Production plugin code needed no compatibility changes.

## Versions and results

The [WordPress stable-version API](https://api.wordpress.org/core/version-check/1.7/) and [release archive](https://wordpress.org/download/releases/) identified 7.1.3 as the latest stable release. The matrix includes the newest maintenance release of every branch from the plugin's 6.5 minimum, plus the exact 6.5 minimum. Combinations follow the [official WordPress/PHP support table](https://make.wordpress.org/core/handbook/references/php-compatibility-and-wordpress-versions/).

| WordPress | PHP 8.1 | PHP 8.2 | PHP 8.3 | PHP 8.4 | PHP 8.5 |
| --- | --- | --- | --- | --- | --- |
| 6.5 (exact minimum) | Pass | Pass | Pass | Outside core support | Outside core support |
| 6.5.13 | Pass | Pass | Pass | Outside core support | Outside core support |
| 6.6.10 | Pass | Pass | Pass | Outside core support | Outside core support |
| 6.7.10 | Pass | Pass | Pass | Pass | Outside core support |
| 6.8.11 | Pass | Pass | Pass | Pass | Outside core support |
| 6.9.10 | Pass | Pass | Pass | Pass | Pass |
| 7.0.7 | Pass | Pass | Pass | Pass | Pass |
| 7.1.3 | Pass | Pass | Pass | Pass | Pass |

| Actual PHP runtime | Isolated regressions | Composer platform requirements | Maintained PHP syntax |
| --- | --- | --- | --- |
| 8.1.34 | 285 passed, 0 failed | Pass | 23 files passed |
| 8.2.35 | 285 passed, 0 failed | Pass | 23 files passed |
| 8.3.36 | 285 passed, 0 failed | Pass | 23 files passed |
| 8.4.27 | 285 passed, 0 failed | Pass | 23 files passed |
| 8.5.12 | 285 passed, 0 failed | Pass | 23 files passed |

Portable Windows x64 PHP builds came from the [official Windows release manifest](https://downloads.php.net/~windows/releases/releases.json); downloaded archives matched their published SHA-256 hashes. Each runtime used its own temporary configuration and extensions. WordPress core files matched the official version-specific checksum API before native verification. The database was a new, isolated MySQL 26.10.0 instance bound to loopback, with a fresh database for each combination.

## Checks performed

- `php tests/run.php` on every PHP runtime: 285 cases per runtime, including all six provider dispatch paths, incremental upload history, credential handling, attachment lifecycle and request-lock recovery.
- Native `core.php`, `sync.php`, `core_audit2.php`, `sync_audit2.php` and `providers.php` on each supported combination. These cover schema upgrades, real GD attachment metadata, responsive delivery, cleanup/deletion, SQL queues, actual persisted Action Scheduler jobs, authorization/nonces, concurrency/recovery and stateful mocked Bunny HTTP transfers.
- Admin/documentation DOM regressions: 134 passed, 0 failed on Node 24 and PHP 8.3.
- Both PHPStan level 5 passes: no errors. Composer metadata validation and all five runtime platform checks passed.
- Workflow validation: actionlint passed and all seven multiline shell blocks passed `bash -n`.
- After the PHP 8.5 fixture adjustments, the native sync and provider suites also passed again on the exact WordPress 6.5 / PHP 8.1 minimum, with a clean debug log.

## Test infrastructure repaired

- Added the missing upload-history table and `nocache_headers()` double to the isolated fixtures; aligned static schema metadata with version 3.
- Restored admin fixture dependencies and helpers for provider guides, saved Google credentials, file selection, notifications, confirmations, shared status widgets, polling and saved preferences. Updated older expectations to the current UI behavior.
- Changed the deliberate provider upload-failure scenario to full resync, so incremental skipping cannot bypass the intended failing upload. Native schema checks now also verify upload-history columns.
- Replaced obsolete fixture `imagedestroy()` calls with releasing the GD object, and reset simulated HTTP status through WordPress's `status_header()` API for PHP 8.5.
- Added a guarded MU fixture for WordPress.org update-metadata responses, keeping installation independent of blocked update traffic. Other HTTP remains subject to the existing integration fixtures and network blocking.
- WP-CLI 2.12.0's bundled CLI libraries emitted PHP 8.5 deprecations. PHP 8.5 therefore uses the official `3.0.0-alpha-e536289` build, pinned to [build commit 5c13eacf](https://github.com/wp-cli/builds/tree/5c13eacf0ae82e66868cf4fe2bc3e8b5b03fd5ee), with SHA-256 `1f2a947aa2fc8f729e15d1e76987706373b3485691852829cb4fd3594d517991`. PHP 8.1–8.4 used WP-CLI 2.12.0. Diagnostics were kept enabled; no vendor files or production error-reporting settings were patched.

## Reproduction and limits

The workflow is temporarily disabled at the user's request and retained with a `.disabled` extension. When enabled, the [CI workflow](../.github/workflows/wordpress-compatibility.yml.disabled) resolves stable maintenance releases on each run, checks PHP 8.1–8.5 and runs the same five native suites on MySQL 8.0. It includes the pinned PHP 8.5 runner and fails on PHP diagnostics or a nonempty WordPress debug log. GitHub-hosted execution has not been performed during this local verification.

[Machine-readable results](release-1.8.0-results.json) preserve the 32 native outcomes and their local log filenames. Raw runtime manifests, official checksum responses and logs are retained under `%TEMP%/smartsync-release-180-520c8beebf154660b7d766cf305a9b5e`.

Cloud traffic was mocked; these results do not verify live account permissions, provisioning, DNS or CDN delivery. This matrix covers single-site WordPress; earlier multisite and browser evidence remains separate. Nothing was committed, pushed or published by this verification.
