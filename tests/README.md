# SmartSync verification

Run commands from the plugin root unless another directory is shown. The isolated PHP suite needs PHP 8.1+, PDO SQLite, and installed root Composer dependencies. The audit executed PHP on 8.3.36.

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
