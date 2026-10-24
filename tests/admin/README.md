# Admin regression tests

Requires Node.js 18+ and PHP on PATH. From this directory:

    npm ci --ignore-scripts
    npm test

The suite renders the actual PHP views using a minimal WordPress fixture, loads the actual CSS and JavaScript into jsdom with jQuery, and mocks AJAX and long timers. It checks provider choices and payloads, safe DOM/JSON handling, custom-domain verification, sync concurrency and error recovery, logs/progress, analytics, navigation, and keyboard tabs. It makes no cloud requests and uses no real credentials. The fixture's escaping functions approximate WordPress; these tests do not replace integration with WordPress.

## Real browser smoke

Use only a disposable local WordPress installation with SmartSync active and an administrator at user ID 1. Start its local HTTP server first. Provide WP-CLI and a system Edge, Chrome, or Chromium executable; the script discovers common installation paths, or accepts --browser=PATH.

    npm run test:browser -- --wordpress=C:/temp/fixture/wordpress --wp-cli=C:/temp/fixture/wp-cli.phar --url=http://127.0.0.1:8097 --seed-fixture

This generates temporary local authentication cookies, seeds deliberately invalid test cloud settings, and restores the prior settings in finally. It intercepts cloud AJAX, blocks external resources, checks the rendered admin workflows and responsive widths, checks browser runtime errors and local HTTP failures, and saves screenshots in the system temporary directory under smartsync-admin-browser. Use --output=PATH for another screenshot directory. It requires a loopback URL and the explicit --seed-fixture switch.

Cloud provisioning, billing, DNS propagation, storage transfers, and delivery remain outside this browser smoke test.

## Native Bunny connection browser smoke

Unlike the general browser smoke above, this test allows browser requests to reach the real WordPress admin-ajax handlers. Only WordPress's outbound Bunny account/storage HTTP is replaced with synthetic responses.

    npm run test:provider-browser -- --wordpress=C:/temp/smartsync-wordpress-audit-fixture/wordpress --wp-cli=C:/temp/wp-cli.phar --url=http://127.0.0.1:8097 --seed-fixture

Use a disposable fixture with SmartSync active, administrator ID 1, and its smartsync-audit-isolation.php MU plugin blocking external HTTP. The path must contain smartsync-wordpress-audit-fixture/wordpress. Run serially with other fixture tests and with no pending SmartSync actions.

The runner writes a temporary, explicitly guarded MU wrapper for tests/integration/bunny-http-fixture.php. It starts a loopback PHP server only when the target port is free, and stops only that owned process. It snapshots/restores configuration, domain, sync/lock, and relevant transient options; it removes its own wrapper and fake request-log option afterward. Existing server processes are retained.

Coverage includes a native HTTP 400 for the wrong account key, retry, paginated account/details responses, system-host preference and certificate/disabled/suspended/token filtering, a storage connection probe before saving, saved provider-reported endpoint, recognized settings, native analytics and auto-delete toggle requests, and 320/1280 pixel screenshots. The intentional 400 is allowed; other local HTTP failures and JavaScript runtime errors fail the test. No browser AJAX responses are intercepted or fulfilled. External browser resources remain blocked.

This proves the local browser/PHP/WordPress HTTP wiring against fixtures. It does not establish live Bunny account permissions, host/DNS/TLS availability, or provider acceptance.

## Bundled documentation

The regular Node suite also checks Documentation/index.html local HTML/CSS resources, fragment targets, and sidebar behavior. To smoke-test the standalone page in Edge/Chromium:

    npm run test:docs

This starts a temporary loopback HTTP server in the same process, loads the bundled scripts/styles/images, tests mobile navigation, and checks for runtime errors or failing local HTTP responses. External Google Fonts and the YouTube embed are blocked. The browser and temporary server close when the script finishes; no WordPress fixture is needed. A screenshot is saved beside the admin screenshots.

Bundled Bootstrap, FontAwesome, and other template libraries are exercised as loaded dependencies; they were not upgraded or subjected to a separate vendor advisory audit.
