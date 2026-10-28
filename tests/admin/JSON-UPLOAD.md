# JSON upload and wizard width

Updated 24 October 2026. All three reported items are fixed; no commits were created.

| Issue | Cause | Status | Fix |
| --- | --- | --- | --- |
| JSON picker looked like an unstyled native control | Only the browser file input was rendered. | Fixed | A single-file drag-and-drop card with a keyboard-accessible picker, filename, size, reading/ready/error states, Replace and Remove controls. Uses browser APIs without adding a third-party upload dependency. |
| Selected JSON had no preview | The wizard only checked filename/size before discovery. | Fixed | Local JSON parsing and service-account field validation, project/email summary, indented JSON and optional sensitive-value reveal. Private keys, key IDs, client IDs and unknown fields are hidden by default. All content is rendered as text. |
| Completion was narrow and centered | A separate 900px cap centered the final workspace beneath a wider stepper; the page also had a 1260px cap. | Fixed | Both caps are removed. The wizard fills its admin content area, completion matches the stepper width, and summary fields adapt from four columns to two/one on smaller screens. |

Fluid layout retained on 24 October 2026: the outer setup container and workspace explicitly use 100% of their available width, with no outer width cap or centered completion card. The stylesheet cache version is `setup-5`. Browser verification passed at 320, 390, 768, 1440 and 1920 pixels, including full-width completion and horizontal-overflow checks.

The selected key stays in memory and is sent only when Connect starts discovery. Replacing/removing it invalidates the accepted discovery token and later steps. Late FileReader callbacks cannot replace a newer selection. The widget participates in the existing request/sync locks; dragging files cannot replace a locked selection. Sensitive previews remask on provider/step changes, connection requests and page exit. Successful saving clears the selected file and preview, even if a sync lock arrives before the save callback finishes.

Saved Google credentials can still be reused without selecting a new file. The preview applies to newly selected files; existing encrypted server credentials and their private paths are never returned for preview. Server validation, encrypted storage, pending-selection expiry and accepted Google selection-token binding remain authoritative and unchanged. No automatic upload, localStorage, sessionStorage or clipboard write is introduced.

## Verification

- `npm test --prefix tests/admin`: 69 passed. Seven new cases cover metadata/redaction, explicit safe reveal, malformed/wrong credentials, file-read failures, replacement/read races, remove/saved reuse, drag/drop/locks and successful cleanup.
- `composer test --working-dir=tests/static`: both PHPStan passes completed without errors.
- `npm run test:json-upload --prefix tests/admin`: real PHP view, maintained assets and browser file APIs in Edge, using synthetic credentials/AJAX. Keyboard file chooser, drag/drop, formatted/masked preview, remove/replace, errors, request locks, accepted-token submission and full-width completion passed at 320/390/768/1440/1920 pixels.
- `npm run test:cloud-setup --prefix tests/admin`: existing six-provider browser regression passed, including request/sync locks and completion.
- Changed PHP/JS syntax and `git diff --check` passed. The translation catalog was regenerated and wizard assets advanced to `setup-4`.

The four runtime files and translation catalog are copied to the installed plugin. Synthetic, credential-free previews/report are in ignored `dist/json-upload-preview/`. No live cloud setup or transfers were performed. The user's existing `Plugin.zip` was retained.
