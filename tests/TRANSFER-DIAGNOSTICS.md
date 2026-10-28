# Actionable, credential-safe file transfer errors

Implemented 27 October 2026 in the maintained and installed plugins. No commits created.

## Reported three failures

Read-only inspection confirmed the last run was failed after processing 312 files: 309 queue rows completed and three failed. The reported local files currently exist and are readable:

| File | Local bytes | Read-only Bunny response |
| --- | --- | --- |
| `wp-includes/images/icon-library/audio.svg` | 297 | HTTP 404 |
| `wp-includes/js/tinymce/skins/wordpress/images/pagebreak-2x.png` | 835 | HTTP 404 |
| `wp-includes/js/tinymce/skins/wordpress/images/playlist-audio.png` | 440 | HTTP 404 |

These observations do not identify the original failure. The old worker discarded its exception and saved only the same generic provider/permissions message for every category. The successful majority establishes that this run was not uniformly failing, but it cannot prove a transient network, provider, filesystem or database cause for these three requests. Current readability also does not prove readability at the original transfer time. Historical exception details cannot be recovered from the saved strings.

The displayed matching log times were added when the browser received the finished run's saved error list. They do not establish that the three failures occurred simultaneously.

## Fix status

| Issue | Cause | Fixed | How |
| --- | --- | --- | --- |
| Every failure suggested access/file permissions | The batch catch intentionally discarded all exceptions to avoid persisting credentials and signed URLs. | Yes | Preserve messages from the existing deliberately public `SMARL_ProviderException` class. Other exceptions are reduced to allowlisted HTTP categories or the current transfer stage. Raw SDK messages and response bodies remain excluded. |
| Bunny transport failures did not distinguish timeout/DNS/connect/TLS | Both WordPress HTTP and streamed transport paths replaced every transport error with one generic message. | Yes | Extract only the numeric cURL error category and create a fixed localized explanation. Unknown errors retain the safe generic explanation. Bunny rate limiting receives its own retry advice; HTTP exceptions retain numeric status codes. |
| Local/history/queue failures looked like remote permission failures | The worker did not distinguish validation, source reads, provider transfer, source verification, history persistence and completion persistence. | Yes | Track the stage and produce a fixed safe reason for each. Source changes and database history failures now receive the appropriate explanation. |

Examples include “The Bunny storage request timed out,” “The provider denied access,” and “The upload history could not be updated.” An unknown failure still gets a cautious generic explanation rather than a guessed cause. These explanations apply to subsequent attempts; existing saved generic errors are unchanged.

Incremental retry behavior remains the same: failed/interrupted transfers lack successful receipts and are queued again; confirmed unchanged successes are skipped. This change does not automatically retry requests, change credentials/permissions, grant CDN eligibility or mark failed rows successful.

Verification: **234 isolated PHP checks passed**, including transport categories, Bunny HTTP 403/429/503, raw vendor-message secrecy, source changes, database write failure and an incremental retry after a timeout. Both PHPStan passes, PHP syntax/translation extraction/whitespace checks and six native WordPress sync/AJAX integration groups passed. Integration and regression transfers used fake storage with outbound HTTP blocked.

The production investigation used plugin-disabled database reads, local read checks, and three bounded authenticated GET requests to the reported Bunny objects. No live files were uploaded, deleted or retried, and no live queue was started/stopped. The two changed PHP runtime files and translation catalog are synchronized to the installed plugin. No vendor files or dependencies changed.
