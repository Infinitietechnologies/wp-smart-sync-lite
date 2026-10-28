# Shared expandable sync widget

The 27 October motion, single-footer and container-width changes are documented in [Widget motion and responsive layout](WIDGET-MOTION-LAYOUT.md).

Updated 24 October 2026. Status: implemented, verified and applied to the installed plugin. No commits created.

The existing compact progress card is now a collapsible monitor on all three plugin screens: Settings, Cloud Config and Custom CloudFront URL (including its unsupported-provider notice). Its header is a native button with a chevron, keyboard activation and `aria-expanded`; Escape collapses it and returns focus to the header. Expansion is remembered per site/blog/user in browser storage.

The expanded panel shows provider, storage, operation, processed/total progress, exact failed-file count, current filename with the relative path available as a tooltip, elapsed time and estimated time remaining. ETA starts with Calculating and uses progress actually observed; stale/disconnected progress does not produce a confident estimate. Current file and remaining-time displays adapt to terminal states. Status announcements are separate from the interactive panel and do not repeat on every clock tick.

## Issue status

| Gap | Cause | Fixed | Result |
| --- | --- | --- | --- |
| Monitor existed only in Settings | Its construction was inside the logs-page condition. | Yes | A dedicated shared script/style renders it on every plugin screen. |
| Card could not expand | The original indicator was informational markup only. | Yes | Expandable details with persistent preference, keyboard interaction and a compact default. |
| Timing reset on navigation | Only page-local start observations existed. | Yes | New runs persist server start/end timestamps; session observations retain ETA sampling across page changes. |
| Provider, storage, failure count and active file were missing | The response only exposed general progress and bounded logs. | Yes | Credential-free run metadata and an active relative-path/queue-count query extend the existing authorized status response. |
| Terminal status disappeared immediately | The original indicator hid for every non-processing response. | Yes | Success appears briefly with View results; failures remain until acknowledged. Stop provides a short summary and a settings link. |

Existing runs created before this change have no authoritative start timestamp. They display **Time observed** while running; the observed period survives navigation. New runs show **Elapsed** from the persisted server start time. Historical terminal runs without timing show Not recorded. Expansion/acknowledgement remains usable if browser storage is unavailable; persistence then lasts for the current page only.

Settings and Cloud Config retain their polling ownership and publish the shared status events; Custom URL supplies its own single poller. Idle monitoring continues so a run started elsewhere can be discovered. Generation guards ignore late responses after navigation or stopping. Poll failures retain the last known running state and expose a retry notice. Existing capability/nonce checks, run tokens, worker leases and conditional progress writes remain intact.

Stop requires confirmation and uses the existing stop endpoint. Settings delegates to its existing stop controls; other plugin pages coordinate their poll cancellation around the same operation. No real sync was started or stopped during verification. Links use the registered settings URL and logs anchor.

The mobile widget expands upward within the viewport and scrolls its details when necessary. It is a fixed-position overlay and reserves no space in the page layout. The earlier page-bottom spacer was removed on 27 October after it caused unwanted blank space and layout movement. Reduced-motion styling remains supported.

## Verification

- `php tests/run.php`: 202 passed, including new timestamp/context serialization, current-file/failed-count and unknown legacy-context cases.
- `npm test --prefix tests/admin`: 75 passed, including all-page rendering, single polling ownership, navigation preference/timing, site isolation, safe filenames/errors, failure acknowledgement, stop/retry/stale response guards and Escape/completion behaviour.
- `composer test --working-dir=tests/static`: both PHPStan passes completed without errors.
- `npm run test:widget --prefix tests/admin`: Edge loaded actual PHP views and maintained scripts/styles on a mocked origin. Cross-page storage persistence and ETA, all three plugin screens, keyboard controls, stop confirmation, failure acknowledgement, completion and 320/390/768/1440 widths passed.
- `npm run test:sync-status --prefix tests/admin`: compact/expanded browser smoke and reduced motion passed.
- Changed PHP/JS syntax and `git diff --check` passed. Translation catalog regenerated; changed scripts and new widget assets use `widget-1` cache versions.

Nine runtime/catalog files are synchronized to the installed plugin. Synthetic previews/report are in ignored `dist/shared-sync-widget-preview/`. Cloud transfers, provider provisioning and the user's existing `Plugin.zip` were retained.
