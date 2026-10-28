# Shared confirmation dialogs

Implemented 27 October 2026 in the maintained and installed plugins. No commits created.

Minor layout revision: the icon and title now share one centered header row. Message, destination and action layouts remain the same. The stylesheet uses `dialog-inline-1`; the existing responsive browser workflow was rerun for the revision, and the stylesheet/cache registration were synchronized to the installed plugin.

| Issue | Cause | Fixed | How |
| --- | --- | --- | --- |
| Confirmation styling did not match the plugin | Upload/restore, page Stop, widget Stop and CloudFront cache actions used blocking `window.confirm`. | Yes | All four call sites use `assets/js/confirmations.js` and `confirmations.css`, loaded only on the three plugin pages. The shared component presents a styled modal, contextual icons, destination, explanatory copy and explicit action buttons. |
| Generic OK/Cancel did not describe the decision | Browser-provided labels were not specific to upload, full resync, restore, stop or cache refresh. | Yes | Seven localized presets cover Media/Assets incremental and full uploads, restore, stop and CloudFront refresh. Full resync and restore use warning styling; Stop uses a red action and Keep syncing as its cancel label. |
| A custom asynchronous confirmation could act on stale state | Unlike blocking native confirmations, a custom modal leaves status polling active while awaiting a decision. | Yes | Upload rechecks page/status/run locks. Stop also checks the captured generation and run identity on both main-page and standalone widget paths. Cache refresh rechecks page/activity state. Navigation cancels open or closing dialogs without executing their action. |
| Dialog interactions could submit twice or disrupt focus | Repeated clicks, closing animations and several possible triggers require shared lifecycle ownership. | Yes | One modal is allowed at a time; a closing decision runs once. Cancel starts focused. Tab/Shift+Tab stay within the dialog, Escape/backdrop cancel, and dismissal restores trigger focus. The native dialog top layer makes background controls inert. Page scrolling is locked without removing an existing scrollbar gutter. |

The component uses a styled HTML `dialog` and its modal semantics; it does not override `window.confirm`. Titles, messages, destination and button labels use text nodes. A static SVG supplies the decorative icon. Motion uses a short fade/scale and respects reduced motion; mobile layouts wrap and high-contrast focus remains visible. Missing dialog support fails closed with a notification instead of issuing an action.

Runtime registration/localization lives in `src/SMARL_SmartSync.php`. `settings.js` and `sync-widget.js` depend explicitly on the shared controller. New labels and revised explanation strings are included in `languages/wp-smartsync.pot`. The new assets and changed consumers use `dialog-1` cache versions. No AJAX action, nonce, authorization check or server queue contract changed.

Verification:

- All **91 admin tests** passed. Seven dedicated cases cover literal untrusted text, safe cancel/focus return, overlap/double-click prevention, uploads discovered during confirmation, newer main/widget runs, page navigation during closing, unsupported dialogs and cache confirmation.
- Both PHPStan passes, PHP/JavaScript syntax, translation extraction and whitespace checks passed.
- The native home-page browser workflow checks custom upload, full resync, restore, both Stop entry points and CloudFront refresh. It covers five viewport widths, Tab/Shift+Tab/Escape, initial focus, background inertness, normal/reduced motion, stable page width and cancellation without an action request.
- Shared-widget browser checks cover the three plugin pages, standalone stop/cancel, full resync, navigation and completion. Widget motion/layout checks preserve the fixed overlay and existing container behavior.

Browser requests were mocked. No real transfers were started/stopped and no real CloudFront invalidation was requested. Six runtime/catalog files are synchronized to the installed plugin; previews are in ignored `dist/confirmations-preview/`.
