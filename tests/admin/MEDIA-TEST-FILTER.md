# Temporary Media file-type filter

Status: implemented for testing; remove after format testing is complete.

CDN testing: after a successful selected-format upload to the current destination, the existing delivery toggle can enable a limited CDN test. This addresses the all-types prerequisite that prevented format testing. Only selected formats with an exact successful upload receipt and unchanged local bytes get cloud URLs. Other formats, unknown files, modified files, and destination mismatches retain local URLs. Attachment URLs, image sources, and content rewriting share this guard. The Media card identifies the active test formats.

The Media sync card now has a compact File types picker. No selection means the existing all-formats behavior. Selecting one or more extensions limits only the next manual **Upload media** request. The choice is neither saved nor applied to automatic Media Library uploads, asset uploads, or cloud restores. A refresh resets it.

The request is validated against the server's supported Media extensions before any sync state changes. The selected extensions are stored only with that run's progress so background batches use the same filter. Incremental receipts and full resync still work normally for selected files. A filtered run does not change Media CDN eligibility or bind an entire attachment manifest, because it cannot prove that the full Media library is uploaded.

Enabling limited delivery persists `mediaCdnTestExtensions` and `mediaCdnTestTarget` in cloud settings so its scope survives navigation and sync progress resets. These are temporary testing fields, validated during deserialization and bound to the stable storage destination. Disabling delivery clears them. Completing a successful all-types upload restores ordinary full-library eligibility and clears the test scope. Credential/configuration fingerprint and public URL checks still apply when entering test mode. A failed run cannot enable it.

Local deletion is disabled in the UI, rejected by its AJAX endpoint, and independently blocked by the cleanup path while a test scope is present. Every local copy is retained. Existing missing offloaded files must be restored before entering limited delivery. No live sync, cloud write, or CDN setting change is performed by the tests.

Removal points: the picker/template/CSS/JS, temporary request validation and filtered-run guard, the two cloud-config test fields, selected-format CDN activation, receipt checks in media rewriting, local-cleanup guards, and related status/localization fields. Disable test delivery and clear its saved scope before removing these guards. Bump asset versions afterward.

Verification: the PHP cases cover selected upload queues, successful scoped activation, retained false full-library eligibility, local-copy guards, unchanged receipt matching, untracked/changed/wrong-format files, and destination changes. The admin tests cover selection/reset, completion guidance, scope display, and cleanup controls. Native browser previews verify scope persistence and controls at mobile/desktop widths. 245 PHP cases, 123 admin cases, and both static-analysis passes passed.
