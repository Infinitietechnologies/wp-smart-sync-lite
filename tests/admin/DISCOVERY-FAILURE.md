# Failed discovery step navigation

Status: fixed.

Cause: listing failures for S3-compatible providers automatically enabled manual bucket entry, marked discovery ready, and advanced to storage. This fallback could also run for invalid credentials, connection failures, and malformed responses.

Fix: discovery failures stay on credentials with the error visible and the storage step locked. Supported providers offer an explicit manual-entry button. Choosing it opens manual storage entry; saving still performs server-side access checks. Editing credentials or changing providers clears the fallback.

Verification: the admin flow suite covers failure and credential edits for AWS, DigitalOcean, Wasabi, and R2. The existing R2 restricted-key regression now explicitly chooses manual entry before saving.
