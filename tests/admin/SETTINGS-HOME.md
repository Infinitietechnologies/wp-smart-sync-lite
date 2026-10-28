# Main settings page redesign

Implemented 27 October 2026 in the maintained and installed plugins. No commits created.

## Progress summary readability

Fixed: percentage, file counts, remaining files and ETA were all small, low-emphasis text with little visual separation. The percentage is now 20px, remaining/ETA values 17px, counts 13px and supporting labels 12px. An 8px progress track and a lightly tinted metrics strip improve hierarchy. Decorative file/clock SVGs identify the counts and timing; file counts use locale-aware grouping and the localized format “processed of total files.” Narrow screens stack the metrics. The transfer-log disclosure is also 13px.

Verification: all 93 admin tests and the native home/sync-status browser workflows passed, including long ETA values at 320/390/768/1440px and the home workflow at 1920px. Existing widget expand/collapse assertions now wait for the visibility transition. PHP/JavaScript syntax, translation extraction and whitespace checks passed. Four runtime/catalog files are synchronized to the installed plugin; styles and the progress controller use `progress-1` cache versions. Previews are in `dist/progress-summary-preview/`. No transfer, scheduler or ETA logic changed, and no commits were made.

Custom confirmation dialogs now replace native confirmations for upload/full resync, restore, both Stop entry points and CloudFront cache refresh. See [CONFIRMATIONS.md](CONFIRMATIONS.md) for their status, cause/fix documentation, localization, asynchronous state guards and verification.

## Provider context on Media and Assets

Fixed: the provider and storage name were only visible in Overview's connection card, leaving the upload/delivery tabs without destination context. Media and Assets now show one compact, read-only provider/storage line beside the tabs. Overview hides that line to avoid repeating its connection card. Unconfigured sites receive a Connect storage link in the same position. The label follows click, keyboard, URL restoration and browser history tab selection; the saved names are escaped and no credentials are included.

Verification: all 84 admin tests and the native home-page browser workflow passed. Checks cover destination visibility on the Assets deep link, provider/storage values, hiding it on Overview, the unconfigured setup link, URL persistence and responsive layouts at 320/390/768/1440/1920px. PHP/JavaScript syntax, translation extraction and whitespace checks passed. Five runtime/catalog files are synchronized to the installed plugin using `destination-1` stylesheet/tab-controller cache versions. Mocked Media/Assets previews are in `dist/provider-context-preview/`. No live cloud operations or commits were made.

## First-hover button flash

Fixed: primary buttons used a gradient over a transparent background, while inherited hover shorthand removed the gradient and transitioned the base from transparent to blue. This briefly exposed the white card. Main-page primary buttons now keep an opaque blue base and the same translucent sheen in both states, overriding the inherited hover image reset while retaining the subtle color, shadow and movement transitions.

Verification: the native home-page browser workflow passed, including 24 animation-frame samples with normal motion on the first Upload assets hover. Every sampled frame retained an opaque background and the same gradient. Existing responsive, reduced-motion, focus, sync and navigation checks also passed. PHP syntax and whitespace checks passed. The stylesheet and cache registration are synchronized to the installed plugin using `hover-1`. No live cloud operations or commits were made.

## URL tab persistence

Fixed: tab selection only changed the DOM, so refresh always restored Overview. The main settings tabs now use `tab=overview`, `tab=media` or `tab=assets` in the URL. Clicks and keyboard navigation update history without reloading or issuing requests. Initial load, refresh, Back/Forward and restored pages read the URL; missing/unknown values safely show Overview. Other query parameters and the log fragment are preserved.

Fixed: the floating widget's View logs/View results link targeted the base settings URL and could discard the selected tab. On the main page its links now retain the current URL; links from the other plugin pages still target settings normally.

Verification: all 84 admin tests and both native home/widget browser workflows passed. Browser checks cover an Assets deep link, refresh, Back/Forward, invalid values, unrelated query parameters, log anchors and the widget retaining Media. JavaScript/PHP syntax and whitespace checks passed. The two controllers and asset registration file are synchronized to the installed plugin with `tabs-1` cache versions. No cloud operations or commits were made.

## Activity card refinement

Log alignment correction: the independently positioned dot and baseline-aligned text could appear offset, and per-row timestamp widths moved message starts. Rows now use shared dot/timestamp/message columns, a fixed timestamp width and matching 20px text line heights. The dot aligns to the first text line, including wrapped entries; mobile timestamps/messages retain their stacked layout. The five-width native home workflow, syntax and whitespace checks passed. The stylesheet/cache registration are synchronized to the installed plugin with `log-align-1`; no commits were made.

The header Stop sync action also includes a decorative filled stop-square icon, using the same size and spacing as Clear logs. The earlier thin outline resembled an empty checkbox/missing glyph; the filled square now conveys Stop clearly. Its label, confirmation and running-state behavior stay the same. The installed stylesheet uses `stop-fill-1`.

Fixed: Clear logs was inserted beneath the expanded log, far from the Activity header and unavailable while collapsed. The main template now places it on the right of the card header, with the existing conditional Stop sync button immediately before it. The log enhancer reuses that button and retains its old insertion fallback for other views. Clearing only clears the visible log; it makes no request and does not stop or unlock an active transfer.

Fixed: tiny monospace messages and unstructured rows made the transfer log hard to scan. The log now uses 13px interface text, separate machine-readable timestamps, restrained status backgrounds and markers, and a contained scrolling surface. Mobile entries stack timestamps above wrapped messages; the empty state is centered inside the log. The collapsed default, error auto-expansion, safe text rendering and 100-row cap remain intact.

Verification: all 84 admin tests and the shared-widget browser workflow passed. After the final font adjustment, all 13 focused settings tests and the home-page browser workflow passed, including expanded log layouts at 320/390/768/1440/1920px, button order, safe entry decoration, and clearing while active. PHP/JavaScript syntax and whitespace checks passed. Five runtime/catalog files are synchronized to the installed plugin. Styles and log-enhancer assets use the `activity-1` cache version. Mocked previews are in `dist/activity-card-preview/`; no real cloud operations or commits were made.

## Storage usage loading refinement

Fixed: the refresh icon was undersized and aligned as inline text, while replacing the entire button label removed the icon during loading. The usage icon also had a thin, uneven silhouette. The card now uses clearer bar/refresh SVG paths and a centered 16px refresh icon with consistent spacing. Refresh preserves its icon and rotates it while the request is pending.

Fixed: metric values and loading messages shared the same 30px value typography. Loading now uses 12px text and a softly shimmering blue/teal skeleton. The metric height stays stable, the metrics expose `aria-busy`, and success/errors remove all loading styling and restore the refresh action. Reduced motion keeps the skeleton static; high contrast gives it a visible border. Existing request timeout and duplicate-request guards remain intact.

Verification: all 84 admin tests passed, including loading/duplicate-request/success/failure recovery assertions. The native browser workflow passed at 320/390/768/1440/1920px, including loading/loaded layouts, steady metric height and motion preferences. PHP/JavaScript syntax and whitespace checks passed. Five runtime/catalog files are synchronized to the installed plugin using the `usage-1` cache version. Mocked previews are in `dist/usage-loading-preview/`; no real cloud operations or commits were made.

## Upload mode refinement

Fixed: the upload-mode dropdown opened a browser/OS-styled menu that did not match the page. Both Media and Assets now use two inline native radio choices, Incremental (Recommended) and Full resync, with the contextual explanation below. Incremental remains the default. The fieldset preserves request/sync locking, and Full resync retains its confirmation before uploading.

Verification: all 84 admin tests, both PHPStan passes, the shared-widget browser workflow and the complete home-page browser workflow at 320/390/768/1440/1920px passed. Native arrow-key selection, selected-mode help, visible keyboard focus, forced colors and disabled radio controls were checked. No real cloud operations were performed. Five runtime/catalog files are synchronized to the installed plugin; synthetic previews are in `dist/upload-mode-preview/`. The main-page stylesheet and controller use the `mode-1` cache version.

## Visual identity refinement

The first simplified layout felt too plain because the white cards, small headings and text-only navigation lacked a distinctive identity. This is fixed with a compact SmartSync sync mark, consistent decorative SVG icons, segmented navigation, blue/teal metric surfaces, stronger typography, lightly tinted cards and subtle button depth. The overview cards align to the same height. The information, progressive disclosures, soft input focus, container widths and action contracts stay the same.

The SVGs are inline, decorative and excluded from assistive technology; no icon font, network asset or new dependency is required. Reduced motion disables hover movement, and forced-color mode preserves the active navigation state. The `home-2` stylesheet version loads the updated appearance.

Verification for this refinement: 13 focused admin regressions, both PHPStan passes, PHP syntax/whitespace/translation checks and the full home-page browser workflow at 320/390/768/1440/1920px passed. Browser stop actions exercise both the floating widget and the page control, with the overlay collapsed before interacting underneath it. Synthetic previews are in `dist/settings-home-character-preview/`. Four runtime/catalog files are synchronized to the installed plugin; no live cloud operations or commits were made.

## Layout and behavior

The page now has a compact SmartSync header and Overview / Media / Assets navigation, using the existing `container-md` limits. Standalone cards replace the nested outer panel, tab border, card borders and decorative title rules. The main view delegates to `src/Views/templates/settings-home.php`; its stylesheet loads only on the main plugin page.

## Issue status

| Issue | Cause | Fixed | How |
| --- | --- | --- | --- |
| Main page felt crowded | Repeated frames, headings, icon decorations, explanations and a permanent full log competed for attention. | Yes | A concise header, lightweight tabs, standalone cards and a compact Activity section establish a clear hierarchy. |
| Connection information was dense | A shaded label/value table emphasized every setting equally. | Yes | Provider name, storage, region and delivery URL use a simple definition list with one Manage connection action. Unconfigured sites receive one connection prompt. |
| Analytics looked like missing/error data | Three equal No Data rows included a billing figure the provider does not supply. | Yes | Files and storage are the visible metrics; Refresh usage loads them on demand. Optional usage details retain the nullable estimated-cost field, with Unavailable as its default. |
| Media and Assets controls ran together | Delivery switches, cleanup, upload mode and restore actions shared an unstructured full-width panel. | Yes | Separate Cloud delivery and Sync cards; clear native accessible switches; primary upload action; secondary help and restore disclosures. |
| Inputs had a distracting double outline | WordPress/control borders stacked with the shared offset focus outline. | Yes | Filled controls with a transparent resting border and one soft focus treatment. Keyboard focus remains visible, with explicit high-contrast styling. |
| Logs dominated healthy/idle pages | A tall log and explanatory banner appeared under every tab, plus a separate large progress box. | Yes | Transfer logs default to collapsed, with compact progress outside the disclosure. Errors reveal their log; existing widget log links and direct log hashes open it. Stop appears during active/uncertain operations. |
| Identical progress rows repeated | Every status poll appended a row, even if no transfer counts changed. | Yes | Log progress only when run/type/direction/count/failure data change. Status events and polling continue normally. |

Delivery, local cleanup, upload, restore, stop, cache and analytics retain their existing IDs, action names, nonce and server-side guards. Full resync still requires its explicit confirmation. Mode help follows the current selection; the one-time baseline and repair explanation remain under How syncing works. Controls retain their existing request/status locks and toggle rollback on failure.

The Activity status distinguishes preparation, upload/restore, stop, completion, failure and unavailable status. Failed operations open the log automatically. Native log clearing remains bounded to the existing 100-row view, and provider/error content continues to use text nodes. The floating widget remains outside normal page flow and retains its shared navigation, motion, dismissal and timing behaviour.

## Verification

- `npm test --prefix tests/admin`: **79 passed**, including new overview/switch semantics, credential-free markup, compact activity, unchanged-progress suppression, failure/hash disclosure, contextual mode help and existing sync/provider cases.
- `composer test --working-dir=tests/static`: both PHPStan passes passed.
- `npm run test:home`: actual PHP view and maintained assets in Edge/Chromium with synthetic AJAX. Overview, Media, Assets and running-upload layouts passed at **320/390/768/1440/1920px**. Verified keyboard switch activation, toggle rollback, soft input focus, forced colors, analytics, confirmation, sync locks, stop, restore, log links/clearing and the unconfigured state.
- `npm run test:widget`: shared-widget page navigation, timing, keyboard, stop, acknowledgement, completion and upload-mode regression passed.
- `npm run test:widget-layout`: all page container breakpoints, animation/reversal/dismissal, stable floating layout, focus return, reduced motion and mobile scrolling passed with the new main-page stylesheet.
- PHP/JS syntax, translation extraction and `git diff --check` passed. Main-page assets use `home-1` cache versions.

All browser requests were mocked; no production sync was started/stopped and no real cloud changes were made during verification. Seven runtime/catalog files are synchronized to the installed plugin. Synthetic previews/report are in ignored `dist/settings-home-preview/`.
