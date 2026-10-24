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

## Bundled documentation

The regular Node suite also checks Documentation/index.html local HTML/CSS resources, fragment targets, and sidebar behavior. To smoke-test the standalone page in Edge/Chromium:

    npm run test:docs

This starts a temporary loopback HTTP server in the same process, loads the bundled scripts/styles/images, tests mobile navigation, and checks for runtime errors or failing local HTTP responses. External Google Fonts and the YouTube embed are blocked. The browser and temporary server close when the script finishes; no WordPress fixture is needed. A screenshot is saved beside the admin screenshots.

Bundled Bootstrap, FontAwesome, and other template libraries are exercised as loaded dependencies; they were not upgraded or subjected to a separate vendor advisory audit.
