# Wizard interaction fixes

Updated 24 October 2026. This follow-up addresses the five reported Cloud Config interaction issues. No commits were created.

| Reported issue | Cause | Fixed | Result |
| --- | --- | --- | --- |
| Duplicate loading notification | Discovery and saving wrote the same progress text to the top banner and an action button. | Yes | Progress appears only on the action being performed, with a small activity ring. A separate visually hidden status region announces it for assistive technology. Errors remain visible; completion uses its existing summary. |
| Provider could change during a request or sync | Discovery disabled only three buttons; the wizard did not reflect the server's existing configuration guard for running syncs. | Yes | Discovery/save freeze all wizard controls and step buttons. A running transfer disables the server-rendered fieldset, and status polling maintains the lock until completion/stop. A dedicated notice links to sync settings. The server also rejects discovery during an active sync; existing configuration/coordination guards remain intact. |
| Current provider was ambiguous | The page only identified the provider selected in the draft form. | Yes | A small context row separately identifies the current saved provider/storage and the provider being set up. It updates the saved target only after a successful save. |
| Refresh left dependent controls usable | Bucket/pull-zone fields, radios, credentials and Back/step navigation were not part of the discovery lock. | Yes | One shared operation lock covers discovery, refresh and saving, restores previous control states on success/failure, and retains a separate active-sync lock. Refresh preserves the selected storage/pull zone if it still exists. |
| Step cards could not be clicked | The stepper was decorative list markup. | Yes | Native buttons support mouse, Enter and Space. Completed/reached steps can be revisited; changing credentials invalidates later steps. Unreached steps and completion cannot bypass discovery, validation or saving. |
| Bunny exposed unsupported bucket creation | The scoped choice-layout style overrode the older `d-none` rule. | Yes | A native hidden attribute now removes the bucket-type fieldset for Bunny, whose setup supports existing storage zones only. |

## Verification

- `php tests/run.php`: 187 passed, including the existing active-sync configuration guard and a new all-provider discovery rejection case.
- `npm test --prefix tests/admin`: 62 passed. New cases cover complete request locks/recovery, refresh selection retention, step navigation/validation, saved-versus-draft provider state, active-sync lock/recovery and a discovery/sync-lock race.
- `composer test --working-dir=tests/static`: both PHPStan passes completed without errors.
- `npm run test:cloud-setup --prefix tests/admin`: real PHP view and maintained CSS/JS in Edge, with synthetic AJAX. All six provider forms and 320/390/768/1440 widths passed, including paused discovery/refresh, active-sync locking, click/keyboard step navigation, retry, validation and completion.
- Changed PHP/JS syntax and `git diff --check` passed. Translation catalog regenerated and wizard asset versions advanced to `setup-2`.

Runtime files and the translation catalog are copied to the installed plugin. Credential-free screenshots/report are saved under ignored `dist/cloud-setup-preview/`. No live cloud operations were performed, and the user's existing `Plugin.zip` was retained.

## Input appearance follow-up

The generic admin focus outline was stacking outside the field border and focus shadow, producing the reported double frame. Fixed: wizard text/password/URL/file fields and selects now use lighter borders, a soft surface, and a single focus treatment on the control edge. Password input and Show button share one focus boundary, including validation errors. Keyboard focus remains distinct; OS high-contrast mode retains a system-color outline. Other admin pages retain their existing styles.

Checked the actual PHP view with WordPress form CSS in Edge: normal, keyboard, password-group/error, 390px mobile and high-contrast focus states. The existing six-provider/four-width wizard browser regression also passed. The stylesheet cache version is `setup-3`; the installed stylesheet and enqueue code match the maintained source. Focused-field previews are in ignored `dist/field-focus-preview/`. No commits were created.
