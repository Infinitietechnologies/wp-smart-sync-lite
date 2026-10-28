# Action notifications

Implemented 27 October 2026 in the maintained and installed plugins. No commits created.

The CDN prerequisite message and other actionable errors now appear in viewport notification bars, in addition to their existing log or inline message. The bars are fixed below the WordPress toolbar, so they remain visible when the user is farther down a page. They add no page spacer or layout height.

## Issue status

| Issue | Cause | Fixed | How |
| --- | --- | --- | --- |
| Important messages were easy to miss | Settings action failures appeared only as small transfer-log rows, potentially outside the viewport. | Yes | Prominent red notification bars display the actual server message while preserving the original log/rollback. |
| Warnings and action feedback lacked a consistent surface | Progress, validation, request errors and success messages had different presentation paths. | Yes | A shared notification component supports error, warning, information and success levels. Setup validation, custom-domain outcomes and standalone widget stop failures use the same surface. |
| Repeated requests could flood notifications | Polls and per-file results are numerous; each is not a separate user action. | Yes | Identical active notices deduplicate; keyed messages update in place. Progress and individual file results remain log-only. A failed run produces one summary, and completion keeps the existing widget summary. |
| Old success messages could compete with a new error | Recent actions may complete close together. | Yes | Errors/warnings take priority and replace older transient feedback. At most three active notices are retained; critical notices are not displaced by new informational ones. |

All notification types close after five seconds and show a shrinking countdown bar at the bottom. Pointer/keyboard interaction pauses the timer and bar; leaving resumes the remaining time. Dismissal is animated unless reduced motion is requested. Notifications do not receive automatic focus; keyboard dismissal returns focus to the prior control. Error/warning messages use alert semantics; information/success use polite status semantics. Icons, text and color communicate severity, and all message content is inserted as text.

The component listens to `smartsync:notice` with `{ message, type, key? }`. Settings also annotate the existing `smartsync:log` event with optional `notice` metadata. A false notification option keeps detailed file results in the log. The component uses its own localized `smarlNotices` labels and loads only on the plugin's three admin pages. It has no provider calls or persistent settings.

## Verification

- `npm test --prefix tests/admin`: **84 passed**. New cases cover the exact CDN prerequisite message, rollback/log retention, safe text, deduplication, bounded stacking, severity replacement, focus/dismissal, log-only progress/file details, new failed-run identity, wizard validation and custom-domain validation.
- `npm run test:home`: browser workflows passed at **320/390/768/1440/1920px**, including the actual CDN message in a visible alert, dismissal, automatic informational expiry, persistent warnings and existing sync/restore/stop/usage controls. Requests were synthetic.
- `npm run test:widget`: shared widget navigation, timing, acknowledgement, stop, completion and upload-mode checks passed across all plugin pages with the notification assets loaded.
- Both PHPStan passes, PHP/JS syntax, translation extraction and whitespace checks passed.

Seven runtime/catalog files are synchronized to the installed plugin, using `notice-1` asset cache versions. Previews/report are in ignored `dist/notifications-preview/`. No live sync or cloud operations were performed.
