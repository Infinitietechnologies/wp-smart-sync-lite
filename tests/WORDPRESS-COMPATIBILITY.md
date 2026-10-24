# WordPress compatibility verification

Verified on 24 October 2026. WordPress.org's [stable version API](https://api.wordpress.org/core/version-check/1.7/), [download page](https://wordpress.org/download/) and [7.1.3 release announcement](https://wordpress.org/news/2026/10/wordpress-7-1-3-maintenance-and-security-release/) identify **7.1.3** as the latest stable release.

SmartSync requires **WordPress 6.5 or later** and now explicitly declares **Tested up to: 7.1.3** in `readme.txt`. The minimum is an installation floor, not an upper version limit. PHP requirements remain **8.1 or later**; these executions used the existing PHP 8.3.36 runtime.

## Native verification

| WordPress | Database | Checks | Result |
| --- | --- | --- | --- |
| 7.1.3 latest stable | SQLite Database Integration | Official en_US checksums; `core.php` 8, `sync.php` 6, `core_audit2.php` 6 and `sync_audit2.php` 5 groups | All 25 groups passed after WP-COMP-001; zero new debug-log bytes, CLI warnings, notices, deprecations or fatals. |
| 6.5 exact declared minimum | Isolated MySQL 26.10.0 | Official en_US checksums; `core.php` 8, `sync.php` 6, `core_audit2.php` 6 and `sync_audit2.php` 5 groups | All 25 groups passed again after WP-COMP-001; debugging enabled and no debug log produced. |

Fixtures are disposable local WordPress installations with fake storage and blocked external cloud traffic. Checks exercise activation/update/uninstall, actual GD metadata and companion files, attachment URLs and deletion, SQL queues, Action Scheduler, authorization/nonces, recovery, caches and cancellation. They do not establish real cloud-account permissions, public DNS or CDN deployment. The earlier [whole-code audit](audit/ISSUES.md) and its provider/browser results remain recorded separately.

## Continued compatibility checks

[wordpress-compatibility.yml](../.github/workflows/wordpress-compatibility.yml) adds push, pull-request and manual GitHub Actions checks. The integration matrix installs WordPress **6.5** and WP-CLI's **latest stable** version using MySQL 8.0 and PHP 8.3. It verifies official core checksums and runs the four native suites serially. A separate job runs the locked-dependency PHP and admin/documentation regressions. The latest matrix resolves again on each workflow run.

The workflow passed official actionlint 1.7.12 validation, and all nine shell blocks passed `bash -n`. Hosted GitHub Actions has not been executed: these changes have not been committed or pushed. No schedule, release publication or live-cloud access was added. CI metadata is excluded from WordPress release packaging.

## Final disposition

Both the current stable release and exact declared minimum passed all 25 native groups after the repair below. The isolated PHP suite passed 186 cases, both PHPStan passes passed, and the source-hash-validated inventory still records 214/214 named PHP function entries after the repair. PHP requirements remain unchanged. The dedicated temporary MySQL server is stopped after verification; fixtures and logs are retained for reproduction. No commit or push was made.

Current installable archive: [smartsync-1.7.3-wp-7.1.3.zip](../dist/smartsync-1.7.3-wp-7.1.3.zip), **7,491 entries / 29,140,620 bytes**. All **2,392 nonvendor files** match the final source byte for byte, including the compatibility repair and updated readme. Production Composer dependencies are included; tests, project skills, CI/Git metadata and local logs are excluded. SHA-256: `31E2879FA8B70240E4FF8F59FBD1EF4F16AB9CEE532E5FBAB5288427368D9439`. The earlier ZIPs retain their historical audit identities; use this archive for the compatibility follow-up.

## WP-COMP-001: early translation during legacy progress recovery

**Status: Fixed. Fixed: Yes.** On WordPress 7.1.3 with a valid SmartSync language pack and interrupted legacy progress, plugin bootstrap emitted an `E_USER_NOTICE` from `_load_textdomain_just_in_time()`. `SMARL_AjaxHandler::__construct()` eagerly called `SMARL_S3SyncSettings::load()`, whose recovery diagnostic uses `__()` before `init`. `setConfiguration()` could repeat that work during an early blog switch. This is the early translation behavior documented in WordPress's [internationalization improvements](https://make.wordpress.org/core/2024/10/21/i18n-improvements-6-7/), retained in the installed 7.1.3 `wp-includes/l10n.php` implementation.

The repair removes only those two eager `load()` calls. Each progress-reading operational entry point already reloads after authorization: status, state get/set, sync start and batch execution. Stop resets progress directly; analytics, toggles and invalidation do not read it. Recovery and its translated diagnostic therefore remain intact when an operation needs them. No English-only fallback, diagnostic code substitution, PHP requirement change or translation-string change was introduced. Merely moving `load_plugin_textdomain()` would not repair the early `__()` call; current WordPress defers actual loading from that registration function.

Two focused regressions in [wordpress_compatibility.php](cases/wordpress_compatibility.php) failed before the repair and pass afterward. They assert that construction/blog configuration leave legacy state untouched, unauthorized status requests do no migration, and authorized status/state operations recover it normally. The combined isolated PHP suite then passed **186/186** cases.

The native before/after proof used the original disposable SQLite WordPress 7.1.3 fixture, with `WP_DEBUG` enabled:

1. Snapshot the exact `smarl_sync_settings` option bytes and `autoload` value. Create a new synthetic SmartSync `en_US.mo` pack containing the recovery diagnostic translated as `Fixture translated: restart synchronization.` Refuse to overwrite an existing pack or MU file.
2. Install a uniquely named temporary MU observer whose PHP error handler captures the actual matching `E_USER_NOTICE`; unrelated PHP errors retain normal handling. Save legacy processing progress with an empty run ID and direction.
3. Bootstrap WordPress in a fresh WP-CLI process. Before repair, capture notice severity **1024** identifying premature SmartSync translation loading; the persisted recovery diagnostic is the synthetic translation.
4. Repeat after repair in another fresh process. Bootstrap leaves progress `processing` and emits **zero** matching notices. An administrator with a real nonce then invokes the registered `checkSyncStatus()` callback after `init`; its actual WordPress JSON response reports `failed` and the same synthetic translated diagnostic. A Fiber-backed AJAX die handler captures the JSON without replacing WordPress authorization or translation APIs.
5. Restore and verify the exact original option bytes/autoload and remove only the owned MO/MU files. Both before/after runs completed this cleanup. Local proof scripts and the original notice capture were retained in the temporary `smartsync-wp71-i18n-240659a4019d` evidence directory; no fixture credentials or production data were used.
