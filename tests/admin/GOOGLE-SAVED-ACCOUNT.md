# Saved Google service-account card

Status: implemented and validated. The previously inconspicuous helper note is replaced with a prominent saved-account card, project ID, service-account email, reuse instructions and Replace key action. The empty dropzone is hidden until replacement is requested. Removing a candidate restores the saved-account card; selecting a replacement does not change the active connection until it is saved.

An expandable saved-values preview displays formatted JSON with sensitive fields masked. Raw private keys, key IDs, client IDs and unknown sensitive values are not embedded in the initial HTML or script data. The explicit Show sensitive values action fetches the active site's credential through a new privileged, nonce-checked read-only AJAX action. An opaque reference binds the request to the saved key displayed by the page, and stale references fail without returning credentials. The endpoint accepts no user-supplied file path, bypasses caching, and makes no external provider calls.

Revealed values render only as text. Hiding or closing the preview, replacing a file, leaving the step, switching providers, hiding the browser tab, starting an operation or navigating away remasks the values and cancels pending reveal requests. No revealed value is saved in local/session storage. Failures restore the reveal control and show the normal plugin notification.

Coverage includes initial-HTML secret absence, saved metadata, explicit reveal/hide, script-safe rendering, replacement/removal, stale requests/references, nonce and capability rejection, active encrypted-credential lookup, and cache prevention. Actual browser checks cover keyboard disclosure and file picking, plus overflow and readability at 320/390/768/1440/1920 px. All credentials and cloud responses in these checks are synthetic. Screenshots are in the system temp `smartsync-google-saved-preview` folder.

The original service-account JSON preview and P12 upload behavior remain available for replacement keys. A saved credential that cannot be read gets a clear replacement message instead of blank account values.
