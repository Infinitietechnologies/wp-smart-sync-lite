# Sync status UI update

Updated 24 October 2026. The background sync indicator now uses a compact white card, a dedicated activity ring, a clear upload/restore label, file counts, a percentage and a thin progress bar. It stays within the viewport on narrow screens. New text is translatable and changed asset URLs invalidate browser caches.

| Issue | Cause and effect | Status | Fixed how |
| --- | --- | --- | --- |
| Status looked like a large blue action button | A solid accent background, pulsing shadow and single text row obscured its informational purpose. | Fixed | Quiet bordered card, restrained shadow, separate icon and two levels of text. |
| Spinner appeared as a shaded blob | The component reused WordPress's generic `.spinner` class and mixed its image with plugin background/animation rules. | Fixed | Dedicated CSS ring with isolated class names and reduced-motion support. |
| Floating status lacked useful progress | The indicator only consumed a running boolean despite the existing status event exposing counts/direction. | Fixed | Use the existing status event for upload/restore text, counts and a bounded progress meter. Unknown totals show background activity without a fabricated percentage. |

Progress is reset when a new run begins, retained through temporary polling failures and hidden after stop/completion/failure. Server-side sync processing, polling and transfer contracts are unchanged. No live cloud transfers were started.

## Verification

- `npm test --prefix tests/admin`: 57 passed. Updated coverage checks localized progress, unknown totals, retained progress on polling failure, stop/restart cleanup and independence from WordPress spinner styling.
- `npm run test:sync-status --prefix tests/admin`: headless Edge passed at 320, 390, 768 and 1440 pixels, including upload/preparation/restore/completion states and reduced motion. Uses the actual PHP settings view and maintained assets with synthetic responses.
- `composer test --working-dir=tests/static`: both PHPStan passes completed without errors.
- Changed PHP/JS syntax and `git diff --check` passed. Translation catalog regenerated.

Runtime changes and the translation catalog are applied to the installed plugin copy. Credential-free previews are in ignored `dist/sync-status-preview/`. No commits were created, and the user's existing `Plugin.zip` was retained.
