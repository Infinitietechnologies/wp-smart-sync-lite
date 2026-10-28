# AWS discovery TLS failure

Status: local transport issue reproduced and fixed; authenticated AWS access remains unverified.

The local PHP runtime constructed the SDK correctly but an unauthenticated HTTPS request to the S3 eu-north-1 endpoint failed with cURL error 60 (untrusted certificate issuer). The same request succeeded with WordPress's readable CA bundle, returning HTTP 405 for HEAD. No user credentials were used.

The SDK clients now use the WordPress CA bundle when PHP and SSL environment variables do not configure a custom trust store. Explicit CA settings are preserved. Certificate verification is never disabled. This applies to S3-compatible storage, CloudFront, STS, and ACM clients created by SmartSync.

Provider AJAX errors now extract a bounded, safe transport category from exception chains. They identify TLS, DNS, connection, or timeout failures without echoing request headers, credentials, URLs, or provider response bodies. Existing AWS error-code reporting remains available.

Regression checks cover each SDK client's outgoing command HTTP defaults, explicit trust-store precedence, and safe nested exception diagnostics. A successful unauthenticated HTTPS probe confirms the local TLS fix; it does not establish IAM permissions or successful bucket discovery. Retry with a replacement key after revoking any exposed credentials.
