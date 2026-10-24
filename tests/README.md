# SmartSync verification

Run commands from the plugin root unless another directory is shown. The isolated PHP suite needs PHP 8.1+, PDO SQLite, and installed root Composer dependencies. The audit executed PHP on 8.3.36.

## WordPress compatibility CI

[Current compatibility verification](WORDPRESS-COMPATIBILITY.md) records the latest stable release, local minimum/latest results and any compatibility repairs.

[wordpress-compatibility.yml](../.github/workflows/wordpress-compatibility.yml) runs on pushes, pull requests and manual dispatch. PHP 8.3 runs the locked Composer/npm regression suites. A MySQL 8.0 matrix installs WordPress 6.5 and [WP-CLI's `latest` stable release](https://developer.wordpress.org/cli/commands/core/download/), verifies core checksums, logs the resolved version and runs `core.php`, `sync.php`, `core_audit2.php` and `sync_audit2.php` serially. The floating entry checks future stable releases whenever CI runs; PHP requirements are unchanged.

Each job uses a runner-temporary `smartsync-provider-workflows/fresh/smartsync-wordpress-audit-fixture/wordpress/` fixture, satisfying all four destructive-test guards. Disposable database/admin values require no repository secrets. WordPress outbound HTTP and automatic cron/updates are disabled after setup. User sites and live cloud accounts are unused; the fixture/service disappear with the runner. Browser, multisite, live-provider and publication checks remain separate. Local validation does not represent a GitHub-hosted execution.

## Isolated regressions

```powershell
php tests/run.php
npm ci --prefix tests/admin --ignore-scripts
npm test --prefix tests/admin
npm run test:docs --prefix tests/admin
```

PHP cases cover media, provider protocols/configuration, synchronization, contained paths, malformed settings, and concurrency. They use actual SDK mock handlers, WordPress doubles, an in-memory SQLite database, and a unique temporary WordPress root. Unmocked HTTP fails; no user site or cloud account is used.

Admin tests render actual PHP views and load maintained CSS/JavaScript with jsdom/jQuery and controlled AJAX/timers. Real browser setup and screenshot locations are in [admin/README.md](admin/README.md).

On Windows, `./tests/path-boundaries.ps1` also checks real junctions: existing and missing restore destinations, source containment and scan exclusion. It creates a fresh temporary root, then removes the junction itself before nonrecursive cleanup.

## Static analysis and dependencies

```powershell
composer install --working-dir=tests/static --no-interaction
composer test --working-dir=tests/static
composer validate --strict --no-check-publish --no-interaction
composer check-platform-reqs --no-interaction
composer audit --locked --no-interaction
```

Static tools have their own development-only lockfile and ignored vendor directory. Two level 5 passes cover all maintained PHP with WordPress/Action Scheduler knowledge and no ignores/baseline: runtime classes target PHP 8.1, and the entrypoint pass includes PHP 8.0 to check its unsupported-runtime guard. Root Composer platform resolution also enforces the PHP 8.1 dependency floor.

Lint maintained PHP with `php -l` and JavaScript with `node --check`. Include changed test sources, and exclude dependency directories from maintained-source counts.

## Disposable WordPress integration

Integration needs WP-CLI, PHP GD, an administrator at user ID 1, and activated SmartSync installed as `wp-content/plugins/smartsync` in a disposable WordPress installation. Scripts block external HTTP, use fake storage, and refuse paths without `smartsync-wordpress-audit-fixture/wordpress/`. The core script deliberately replaces schema and uninstalls/reactivates plugin-owned state. Never use it on a user site or delete plugin files through a junction to this checkout.

```powershell
$fixturePath = 'C:/temp/smartsync-wordpress-audit-fixture/wordpress'
$wpCli = 'C:/temp/wp-cli.phar'
php $wpCli --path=$fixturePath eval-file tests/integration/core.php
php $wpCli --path=$fixturePath eval-file tests/integration/sync.php
```

Use absolute script paths if WP-CLI changes the working directory. Run these scripts serially with browser smoke because they share plugin state. The audit used the official SQLite Database Integration plugin; an isolated MySQL fixture can use the same final directory names.

Core integration verifies old-schema upgrades, GD thumbnails, local cleanup retaining cloud copies, intentional deletion, pending/failed delivery URLs, responsive/stored HTML, lifecycle, uninstall, and reactivation. Sync integration verifies actual queue/options SQL, stored scheduler arguments, batch boundaries, bytes/hashes, duplicate/old callbacks, conditional writes, and real role/nonce behavior. The sync script restores plugin state and removes only uploads it created.

The [audit report](AUDIT.md) records results and practical boundaries. Mocked provider tests do not establish actual account permissions, propagation, or CDN delivery. `.distignore` excludes `tests/` and `.agents/` from release archives.

The [provider follow-up](PROVIDER-WORKFLOWS.md) documents the connection repairs and stronger native AJAX/SDK checks. The separate native Bunny integration fixture must contain `smartsync-provider-workflows/fresh/smartsync-wordpress-audit-fixture/wordpress/`; run `tests/integration/providers.php` through WP-CLI there, after the core and sync checks. It replaces outbound HTTP, uses the actual storage model/registered handlers and executes persisted actions through the real Action Scheduler runner. Keep it separate from a browser fixture while tests mutate plugin state.

The current [second-audit issue register](audit/ISSUES.md) records every confirmed finding, its trigger/cause, repair status and regression evidence. `tests/integration/core_audit2.php` adds six actual WordPress/GD groups, and `tests/integration/sync_audit2.php` adds five actual option/cache/SQL/cancellation groups. Run these serially with the other scripts against the disposable fixture. They are not suitable for a user site.

Optional named-function execution evidence uses Xdebug 3 branch coverage, enabled only in a temporary CLI configuration. Set `xdebug.mode=coverage`, `xdebug.max_nesting_level=1024`, and `auto_prepend_file` to this checkout's `tests/coverage-prepend.php`; set `SMARTSYNC_COVERAGE_DIR` to an empty temporary output directory. Run the PHP suite, Node suite (its PHP render children inherit the configuration), and native integrations, then run `php tests/coverage-report.php COVERAGE_DIRECTORY`. The collector records source SHA-256 at process start, and the report excludes stale or unhashed file captures. No credentials or complete command arguments are recorded. Restore shell-local environment overrides afterward; do not change a site's PHP configuration or ship raw coverage. The generated [inventory](audit/function-coverage.md) measures named function entry, including private/static/lifecycle methods, rather than complete statement/branch/path correctness. JavaScript's separate consistent-source V8 evidence includes callbacks and script wrappers in [its execution map](audit/admin-function-execution.json).

On Windows, `./tests/build-plugin.ps1` packages the current source and installed production Composer dependencies into `dist/smartsync-<version>.zip`. Install root dependencies first. The script refuses to overwrite an existing archive; use `-OutputPath` for another destination. Release archives omit tests, the project skill, Git/editor files and local logs. Versioned ZIPs are ignored by Git.

## Disposable MySQL multisite

Use a separate fresh MySQL-backed fixture whose path contains `smartsync-mysql/smartsync-wordpress-audit-fixture/wordpress/`, initially installed at `http://smartsync-mysql.test`, with the same GD/admin/plugin prerequisites above. Run the core and sync scripts before converting it to multisite. These commands assume a fresh network, where new sites receive IDs 2 and 3:

```powershell
$networkPath = 'C:/temp/smartsync-mysql/smartsync-wordpress-audit-fixture/wordpress'
$networkScript = (Resolve-Path tests/integration/multisite.php).Path
php $wpCli --path=$networkPath plugin deactivate smartsync
php $wpCli --path=$networkPath core multisite-convert --title='SmartSync Audit Network'
php $wpCli --path=$networkPath site create --slug=before --title='Before Network Activation' --porcelain
php $wpCli --path=$networkPath plugin activate smartsync --network
foreach ($fixtureUrl in @('http://smartsync-mysql.test', 'http://smartsync-mysql.test/before/')) {
    php $wpCli --path=$networkPath --url=$fixtureUrl eval-file $networkScript seed
}
php $wpCli --path=$networkPath eval-file $networkScript lifecycle
php $wpCli --path=$networkPath site create --slug=after --title='After Network Activation' --porcelain
php $wpCli --path=$networkPath eval-file $networkScript new-site-before 3
php $wpCli --path=$networkPath --url=http://smartsync-mysql.test/after/ eval-file $networkScript new-site-after 3
php $wpCli --path=$networkPath eval-file $networkScript runtime
php $wpCli --path=$networkPath eval-file $networkScript media
php $wpCli --path=$networkPath eval-file $networkScript security
```

Run `new-site-before` from the main site before any request/CLI command bootstraps the new site; then `new-site-after` confirms lazy schema creation. Before repeating the lifecycle stage, seed every existing site, including `/after/`. The 10 grouped checks cover lifecycle/schema/context restoration, two new-site checks, switched configuration/handlers/SQL tables, media isolation with identical attachment IDs, root-relative delivery and real site-admin permissions. Providers are fake and external HTTP is blocked.
