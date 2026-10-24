# Admin, views and standalone documentation: second audit

Audit date: 24 October 2026. Existing uncommitted 1.7.2 work was retained. No Git mutation or commit was made. This pass directly read every maintained function/callback in the four admin scripts, two documentation wrappers and three view classes, traced their consumers, and added adversarial execution cases beyond the earlier suite.

## Findings and current status

Each UI-001 through UI-013 case failed against the pre-fix implementation before its repair. Current executable reproductions are in tests/admin/audit2.test.cjs. Findings identify concrete failures; passing coverage is not proof of every possible provider, browser, network condition or accessibility environment.

| ID | Status / fixed? | Trigger and reproduction | Cause and impact | Files / functions | How repaired and evidence |
| --- | --- | --- | --- | --- | --- |
| UI-001 | Fixed / Yes | Start discovery with account A, edit credentials while its response is pending, then let A return. | Only provider changes invalidated response generations; old-account buckets became the current selection under newly entered credentials. | assets/js/wizard.js: invalidateDiscovery, loadBuckets, credential input handlers | Credential/file/endpoint/region changes clear accepted bucket state and invalidate old callbacks. Matching test verifies a fresh retry, with no stale bucket rendered. |
| UI-002 | Fixed / Yes | Start configuration, then change the visible bucket/radio fields while the save is pending; or navigate away/back before its response. | Only buttons/provider select were disabled, and save callbacks had no lifecycle token. The screen could show a different target from the one saved, or a late completion after returning. | wizard.js: saveConfiguration, restoreControls, pagehide/pageshow handlers | Freeze every form control, preserve each prior disabled state, expose aria-busy, ignore obsolete save callbacks, and return restored pages to credential validation. Save-error and navigation cases pass. |
| UI-003 | Fixed / Yes | Receive processing status, then a temporary failed status request. | The error path permanently stopped polling, unlocked sync controls and left progress inconsistent with a still-running job. | assets/js/settings.js: checkSyncStatus, retryStatus, setRunningState | Keep the last known execution state, lock starts while status is unknown, retry after five seconds and suppress duplicate identical error logs. Retry then idle/completion cases pass. |
| UI-004 | Fixed / Yes | The server starts a job but its response is lost, or another job causes a start conflict. | A failed start response was treated as evidence that no server job existed. No status recovery followed. | settings.js: recoverStartFailure and start callbacks | Re-read server status before enabling another start. Regression simulates a transport error followed by a processing job. |
| UI-005 | Fixed / Yes | Navigate away from a settings page and restore it from the browser back/forward cache. | pagehide canceled polling, but no pageshow recovery existed; pending start/stop flags could also remain stuck. | settings.js: cancelPolling, pagehide/pageshow and stop callbacks | Reset transient browser locks, invalidate old callbacks and fetch current status on persisted pageshow. Event-driven lifecycle regression passes; actual browser BFCache eviction policy remains browser-controlled. |
| UI-006 | Fixed / Yes | Start attaching a verified domain, then edit that domain or generate another certificate before attachment finishes. | Attachment disabled only its own button, permitting competing operations while the server was updating the distribution. | wizard.js: domainStatus and custom-domain handlers | Freeze domain/generate/retry controls while attachment is active; release on success/error. Regression verifies the competing controls and recovery. |
| UI-007 | Fixed / Yes | A settings endpoint returns HTTP 400/409 with required corrective instructions, such as restoring removed files before disabling CDN. | Transport failure handlers discarded responseJSON and displayed only a generic message, hiding the action needed to proceed. | settings.js: toggleSetting, start/stop, cache and analytics failure callbacks | Extract structured/string server errors while retaining text-only rendering and rollback. Regression verifies the restore instruction and literal HTML safety. |
| UI-008 | Fixed / Yes | Open a page after 90/100 files were processed, then receive another unchanged count five seconds later. | ETA divided this page's elapsed time by the run's complete historical count, reporting about one second despite no observed progress. | assets/js/ui-improvements.js: sync-started/status callbacks | Baseline the first observed count and estimate only from subsequent progress; unchanged counts show --:--. Regression then advances one file and verifies the measured estimate. |
| UI-009 | Fixed / Yes | Open mobile documentation navigation, then a same-breakpoint resize or delayed load occurs; alternatively shrink desktop while a menu link has focus. | Every resize/load reapplied collapsed state, and hiding an inert sidebar did not move its focus. | Documentation/assets/js/docs.js: responsiveSidebar, setSidebarVisible | Change default visibility only when crossing the desktop breakpoint; focus the toggler when hiding a focused sidebar. Resize/focus, Escape and navigation tests pass. |
| UI-010 | Fixed / Yes | Restore a custom-domain page from BFCache while DNS/generation was pending. | DNS polling never restarted, and generation's stale callback could leave its button disabled forever. | wizard.js: custom-domain pagehide/pageshow, cancelPolling | Invalidate prior callbacks, release transient controls and revalidate a known certificate. If no ARN was received, the user can retry Generate; the backend's reuse logic handles the saved certificate. Lifecycle regression passes. |
| UI-011 | Fixed / Yes | A previously verified certificate/DNS state becomes invalid and attachment is rejected. | The failure path unconditionally restored verified UI state and enabled Attach without offering another DNS check. | wizard.js: attachment done/fail callbacks | Disable Attach and offer Retry DNS Check after a rejected/lost attachment response; only a new verified response enables it. Rejection/recheck regression passes. |
| UI-012 | Fixed / Yes | Bucket discovery takes time while the first step remains visible. | Only Next became disabled; its loading option was on a hidden step and no status/busy announcement explained the wait. | wizard.js: loadBuckets, invalidation and completion paths | Announce the existing localized loading message and aria-busy; clear them on current completion or credential invalidation. Regression verifies visible text and busy-state reset. |
| UI-013 | Fixed / Yes | Display the running background indicator on a WordPress admin page. | The component reused WordPress's spinner class but never overrode its default hidden visibility/right float, so the advertised busy glyph stayed invisible. | assets/css/enhanced-ui.css: background indicator spinner rule | Explicit scoped visible/non-floating state matches the component's own active class. A CSS composition regression reproduces WordPress's defaults and verifies computed styles. |

Cross-scope issue PRV-006 was reported to the provider owner: concurrent Google uploads could overwrite the single user's temporary credential selection out of order. The UI now caches only the accepted response's opaque selectionToken, clears it with credential changes and sends google_selection_token. New uploads cannot save without a token. The adversarial late-A/accepted-B browser-data test passes; server credential lifetime/ownership and backend completion status are recorded in the provider owner's findings, rather than being claimed solely from this client test.

## Function-by-function review and execution map

The durable machine-readable map is [admin-function-execution.json](admin-function-execution.json). It lists every observed maintained JavaScript function/callback/script entry by file, line and column, plus each PHP View method. Every listed entry was directly reviewed. The final consistent-source V8 capture executed 124/124 entries across all six scripts; Xdebug executed all 12/12 View methods. Script wrappers and anonymous callbacks are included in the V8 count. This is function-entry coverage, not a statement of 100% branch, statement or platform coverage.

| File | Named function / method | Review and executed behavior |
| --- | --- | --- |
| settings.js | request | Own localized config, POST nonce, HTTP success/error and transport paths |
| settings.js | errorMessage | String/object/fallback messages; DOM stays text-only |
| settings.js | appendLog | Error/type classes, timestamp event, 100-row cap, safe filenames |
| settings.js | setRunningState | Matching type/direction label, original content restoration, unknown/running/starting/stopping locks |
| settings.js | cancelPolling | Clears timer and invalidates prior callbacks on stop/navigation/restart |
| settings.js | checkSyncStatus | Idle, processing, completed, failed, zero totals, counts, terminal errors and stale responses |
| settings.js | retryStatus | Repeated transient error, retained lock and timer recovery |
| settings.js | recoverStartFailure | Uncertain start re-reads the actual server state |
| settings.js | toggleSetting | Both original checkbox states restored on logical/HTTP errors; server guidance retained |
| wizard.js | errorMessage | Discovery/configuration/domain string and message-object failures |
| wizard.js | request | Nonce-bearing configuration, generation, polling and attachment |
| wizard.js | showMessage | Accessible visible/hidden plain-text status |
| wizard.js | initializeProviderWizard | All six options, initial prefill, credential/page lifecycle wiring |
| wizard.js | provider | Current selected identifier feeds validation/dispatch |
| wizard.js | supportsManualBucket | S3-compatible manual discovery fallback; excludes Bunny/Google |
| wizard.js | selectedBucket | Numeric option indexes resolve cached structured selections |
| wizard.js | fillPullZones | Selected-zone host list, empty/issue messages and safe option construction |
| wizard.js | chooseBucketType | Existing/new/manual visibility, region fields and Bunny pullzones |
| wizard.js | showStep | Step panels, focus and aria-current, back/final transitions |
| wizard.js | invalidateDiscovery | Credential/file changes invalidate responses and reset stale lists/tokens |
| wizard.js | restoreControls | Restores exact pre-save disabled state, including navigation recovery |
| wizard.js | updateProvider | Provider-specific fields, region choices, credential-selection reset |
| wizard.js | credentialsValid | Required credentials/JSON, saved Google account, R2 endpoint/public URL, unsupported provider guards |
| wizard.js | loadBuckets | Single multipart file, current/obsolete successes and errors, loading state, bucket schema checks |
| wizard.js | listingFailed | Manual S3 fallback or return to Bunny/Google credentials, safe guidance |
| wizard.js | configurationData | New/existing/manual shape, provider naming/region constraints, Google token and per-zone Bunny hostname |
| wizard.js | saveConfiguration | Correct CloudFront choice, entire-form lock, failure recovery and obsolete completion rejection |
| wizard.js | initializeCustomDomain | Supported-page guard, initial safe JSON, input/generate/retry/attach lifecycle |
| wizard.js | domainStatus | Verified-only attachment, competing control locks and progress events |
| wizard.js | cancelPolling | Invalidates old domain responses and clears timer |
| wizard.js | renderRecords | Plain-text current record list, asynchronous ACM records and validation status |
| wizard.js | pollCertificate | Nonce, sequential pending checks, issued status, failure/retry and lifecycle tokens |
| ui-improvements.js | selectTab | Click, arrow/Home/End focus, selected state and hidden panel consistency |
| docs.js | setSidebarVisible | Classes, aria-expanded, inert/aria-hidden and focus restoration |
| docs.js | responsiveSidebar | Initial mobile/desktop and breakpoint changes without spurious collapse |
| SMARL_SettingsPage | __construct | Typed configuration injection through the rendering fixture |
| SMARL_SettingsPage | loadView | Registers its admin_menu callback in the fixture and real WordPress |
| SMARL_SettingsPage | addAdminSubMenu | Registers correct settings slug/capability |
| SMARL_SettingsPage | renderSettingsPage | Allowed/denied rendering, safe values, switches, links/tabs/log controls and conditional invalidation |
| SMARL_AWSWizard | __construct | Typed configuration injection |
| SMARL_AWSWizard | loadView | Registers its admin_menu callback |
| SMARL_AWSWizard | addAdminSubMenu | Correct parent, slug, capability and callback |
| SMARL_AWSWizard | renderAWSWizardPage | Allowed/denied rendering, provider credentials, safe saved fields and manual/new/existing controls |
| SMARL_CustomUrl | __construct | Typed configuration injection |
| SMARL_CustomUrl | loadView | Registers its admin_menu callback |
| SMARL_CustomUrl | addAdminSubMenu | Correct parent, slug, capability and callback |
| SMARL_CustomUrl | renderCustomUrlPage | Allowed/denied, unsupported configuration, safe JSON, domain and retry controls |

Anonymous callbacks and entry wrappers are individually listed in the JSON map. Their review/execution groups are:

| File / callbacks | Execution evidence |
| --- | --- |
| settings: initialization, label restoration, start click/done/fail, terminal successes/errors iteration | settings.test.cjs and audit2.test.cjs |
| settings: stop click/done/fail/always, toggle done/fail/always, both change handlers | settings.test.cjs and audit2.test.cjs |
| settings: invalidation click/done/fail/always, analytics click/done/fail/always, pagehide/pageshow | settings.test.cjs and audit2.test.cjs |
| wizard: provider/file changes, regions/options validation/rendering, discovery done/fail/always | wizard.test.cjs and audit2.test.cjs |
| wizard: full-form snapshot/restore iteration, configuration done/fail/always, all back/next/yes/no handlers, pagehide/pageshow | wizard.test.cjs and audit2.test.cjs |
| wizard: domain input, generation done/fail/always, DNS done/fail/timer, record/field iteration, retry and attachment done/fail | domain.test.cjs and audit2.test.cjs |
| improvements: text lookup, tab click/keyboard, progress stats assembly, started/running/status and domain-status handlers | settings/domain suites and audit2.test.cjs |
| enhanced logs: entry wrapper, safe timestamp event and Clear button handler | settings.test.cjs |
| documentation: script entry, toggler, Escape and each linked-section click handler | docs.test.cjs and audit2.test.cjs |
| optional highlighter: script entry and modern/legacy/inactive/missing-library branches | audit2.test.cjs; currently not linked from index.html |

## Evidence, retained boundaries and current limitations

- Node suite after these changes: 49 passed, 0 failed. It includes 19 focused second-audit cases (13 defects, the Google selection race and five method/flow/optional-library checks).
- Final raw V8 directory: system temporary smartsync-audit-coverage/js-admin-audit2-final. Earlier js-admin-audit2 records precede later source edits and must not be mixed by offsets.
- PHP method capture: system temporary smartsync-audit-coverage/run-audit2. The temporary PHP_INI_SCAN_DIR loads the collector for child view-rendering processes and the local HTTP server; the global PHP configuration is untouched.
- CSS review covered the full wizard.css -> ui-improvements.css -> enhanced-ui.css cascade, plugin scoping, hidden/display state, box sizing, focus, mobile wrapping, repeated legacy selectors and reduced-motion overrides. CSS has no callable function map; it is parsed by the DOM suite and exercised by the responsive Edge smoke.
- Existing docs tests validate all active local HTML/CSS resources/fragments and real-image loading. Historical screenshots and provider-console click sequences remain examples; no live external console was contacted.
- Browser event simulations directly exercise persisted pagehide/pageshow, stale responses and focus. A particular browser's decision to retain or evict a page from BFCache is not controlled by the unit test.
- Actual Edge smoke and native Bunny smoke use a disposable WordPress fixture and fake cloud HTTP/resources. They do not establish provider permissions, provisioning acceptance, DNS/TLS propagation or actual transfers over the internet.
- Live screen-reader output, other browser engines, localization layouts outside the fixtures, forced OS high-contrast modes and third-party script/CSS combinations were not exhaustively executed.
- The source retains legacy duplicate CSS rules and bundled documentation libraries; no new malfunction from those rules was reproduced in this pass. They were not rewritten or blindly upgraded.

## Final browser and cleanup results

The updated scripts passed the actual Edge/WordPress general smoke: settings, progress/logging, analytics, reconfigure/Finish navigation, the AWS CloudFront choice and custom-domain generation/DNS/attachment. Settings/domain layouts were checked at 320/768/1280px and wizard credentials at 320/1280px. There were no JavaScript runtime or unexpected local HTTP failures. Cloud AJAX was mocked in this general smoke.

The native Bunny browser smoke also passed with browser AJAX reaching actual WordPress/PHP handlers: rejected-key discovery returned the intended HTTP 400, followed by successful discovery, configuration, status, analytics and auto-delete requests. Only outbound Bunny HTTP was replaced with guarded fixtures. It exercised paginated metadata, public hostname selection, a real server-side storage probe and persisted endpoint recognition. The only local HTTP failure was the intentional invalid-key response.

The standalone documentation Edge smoke passed with all local resources/images and mobile navigation working. External fonts/video were blocked. Screenshots from all three runs remain under the system temporary smartsync-admin-browser directory.

The final UI-013 spinner-only CSS rule was added after these Edge runs and verified by the computed-style regression composed with WordPress's documented-in-source hidden/right-floating defaults. It is not presented as an additional browser run.

PHP server PID 18136 was started hidden for this audit and stopped afterward; the native provider runner correctly retained that existing process for its owner to stop. Port 8097 has no remaining listener. Both browser runners restored their option snapshots, the native runner removed its own MU wrapper/request log, and no plugin junction or user data was deleted. The documentation runner closed its own temporary server. Temporary coverage environment variables were limited to their execution shells and child processes.

Cross-scope server fixes remain owned by their corresponding audit ledgers.
