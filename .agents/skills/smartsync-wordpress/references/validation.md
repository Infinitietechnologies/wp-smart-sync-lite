# Verification in this codebase

Read `tests/README.md` for commands, `tests/AUDIT.md` for audit results, and `tests/admin/README.md` for browser setup. Tests/development tools are excluded from release archives by `.distignore`.

Read `tests/WORDPRESS-COMPATIBILITY.md` for current minimum/latest stable WordPress verification. The compatibility workflow runs PHP/admin regressions and native MySQL integrations for exact WordPress 6.5 and WP-CLI's `latest` stable target. Validate the workflow locally with actionlint when editing it; a local YAML check does not mean GitHub-hosted jobs ran. Check native logs with WordPress debugging enabled, including a language pack and legacy progress when changing initialization or translated recovery messages.

Read `tests/PROVIDER-WORKFLOWS.md` for the connection follow-up and release verification. `tests/cases/provider_contract_review.php` exercises actual SDK serialization; `provider_workflows.php` covers complete provider setup/operations and stale configuration writes. Keep these checks beyond request-shaped mock success cases.

## Local checks

From the plugin root:

```powershell
php tests/run.php
npm ci --prefix tests/admin --ignore-scripts
npm test --prefix tests/admin
npm run test:docs --prefix tests/admin
composer install --working-dir=tests/static --no-interaction
composer test --working-dir=tests/static
composer validate --strict --no-check-publish --no-interaction
composer check-platform-reqs --no-interaction
composer audit --locked --no-interaction
```

PHP tests use WordPress doubles, actual provider SDK mock handlers, a real SQLite queue, and isolated temporary files. AJAX response capture suspends a Fiber to model WordPress exit without injecting catchable exceptions into production code. Unmocked HTTP fails; no cloud credentials are used.

Admin tests render actual PHP views and load maintained JavaScript/CSS in jsdom. Browser smoke additionally checks WordPress-rendered admin in Edge/Chrome at narrow/wide widths, with cloud AJAX intercepted.

The Node suite also checks bundled documentation resources and navigation; `test:docs` runs its standalone page in a real local browser with external fonts/video blocked. On Windows, `./tests/path-boundaries.ps1` verifies actual junction containment and scan exclusion, then removes its own fixture without recursive deletion.

`test:provider-browser` uses native WordPress admin AJAX with only outbound Bunny HTTP replaced, checking credential rejection/retry, discovery, storage probing, saved state and settings. Its guarded fixture starts/stops only its own local server and restores options. `tests/integration/providers.php` runs native Bunny configuration, real persisted Action Scheduler jobs, transfers, CDN flags and attachment lifecycle in the separate provider fixture. `package.php` verifies an extracted release; `build-plugin.ps1` creates a dependency-complete installable archive excluding development files.

Two PHPStan level 5 passes use WordPress/Action Scheduler knowledge and actual vendor autoloading. Runtime classes target PHP 8.1; entrypoint analysis also covers PHP 8.0 to check its rejection guard. There are no ignores or baseline.

Lint changed PHP with `php -l` and JavaScript with `node --check`. Exclude `vendor/`, `tests/static/vendor/`, `tests/admin/node_modules/`, and third-party documentation bundles from maintained-source counts.

## Actual WordPress checks

`tests/integration/core.php` and `tests/integration/sync.php` execute through WP-CLI and refuse ordinary site paths. The disposable path must contain `smartsync-wordpress-audit-fixture/wordpress/`. Core integration uninstalls/recreates plugin state: never use a real site or delete plugin files through a fixture junction pointing at the repository.

Core cases cover real GD metadata, old-month originals/sizes, upload before response URLs, deferred cleanup, responsive/stored HTML, failed upload fallback, schema upgrades, lifecycle and uninstall/reactivation. Sync cases exercise actual options/queue SQL, stored scheduler arguments, boundaries, bytes/hashes, old jobs, CAS progress writes and real role/nonce checks. Both use fake storage and block external HTTP.

`tests/integration/multisite.php` adds staged checks on a separate MySQL network: activation/deactivation and exception context, new-site schema initialization, switched configuration/handlers/SQL tables, overlapping attachment IDs, root-relative HTML delivery and real site-administrator permissions. Follow the exact fixture path and stage order in `tests/README.md`; seed each site in its own bootstrap before repeating lifecycle checks.

## Cases to preserve

| Area | Observable checks |
| --- | --- |
| Persistence | Empty/corrupt/older options, typed defaults, stored casing, encrypted credential migration, positive/negative cache invalidation |
| Upload/restore | Zero and 1/10/11/15/16/31 files; Google two-file batches; all six dispatch paths; exact keys/bytes; no duplicate transfers |
| Queue/jobs | Ordered pending selection, row transitions, new runs, duplicate/legacy callbacks, stale/corrupt locks, interrupted rows, lost-action recovery, truthful completion |
| Concurrency | Stop during transfer, stale snapshots after stop/new run, CAS cache invalidation, configuration/domain locks and stale-request rejection |
| Failures | Missing sources, provider/HTTP failures, partial batches, truncated restore, invalid keys, database errors; no leaked secrets or false eligibility |
| Attachments | Originals/sizes/backups, old months, regeneration, final upload before URL response, deferred cleanup retaining cloud copies and already returned local URLs |
| Delivery | First-party/subdirectory mapping, foreign URLs/ports, query/fragment, responsive srcset, stored image/link/audio/video HTML, pending/failed fallback |
| Providers | Every endpoint role/nonce; structured buckets/pullzones; R2 endpoint/ACL; Wasabi; Google IAM/encrypted files; bound certificates/DNS; credential repair |
| Admin | Confirmation cancel, stop/start ordering, stale polling, toggle rollback, safe DOM/JSON, real log/progress events, zero totals, keyboard tabs, mobile overflow |
| Lifecycle | Update-time schema upgrades, deactivate without losing config/active lease, uninstall only owned state/files/jobs, retained unrelated resources |
| Multisite | Per-blog configuration/progress/attachment state, switched shutdown context, current uploads only, main-site child-directory exclusion, worker row rechecks, network-only shared asset restore, allowed MIME policy |

Use focused checks for later edits instead of repeating every suite without cause. Report fake protocol tests, actual WordPress integration, browser behavior, dependency advisories, and live cloud verification separately. Local passes do not establish account provisioning, DNS propagation, billing, or real CDN delivery.
