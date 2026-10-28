# Google Cloud Storage TLS trust

Status: fixed and verified locally. Authenticated Google account access has not been tested.

The local PHP 8.3 runtime has no `curl.cainfo`, `openssl.cafile`, or `openssl.capath` configured. Google's default HTTP transport failed with cURL error 60 at both `oauth2.googleapis.com` and `storage.googleapis.com`. The AWS clients already used a WordPress CA fallback, but Google's separate transport did not.

`SMARL_Helper::cloudHttpOptions()` now supplies the shared SDK defaults. Google uses a single configured Guzzle transport for both OAuth and Storage API requests. With no explicit PHP/environment trust-store setting, it selects WordPress's readable `wp-includes/certificates/ca-bundle.crt`. Existing explicit trust settings retain precedence. Missing bundles do not switch off verification. The fix does not modify `php.ini`, dependencies, or machine-wide trust.

Checks:

- Actual Google OAuth token exchange and bucket listing run through the maintained service with generated test credentials and intercepted HTTP. Both outgoing requests use the same verified transport, with finite connection/request timeouts.
- Explicit CA configuration and missing WordPress bundle behavior are covered. Existing AWS CA tests still pass after sharing the option builder.
- 262 PHP regression cases and both PHPStan checks pass.
- Unauthenticated local HEAD probes through the new transport completed verified TLS at both Google hosts, returning HTTP 404 and 400 respectively. The original default transport failed with cURL error 60. Those HTTP responses establish transport trust only, not service-account permissions.

If a server explicitly configures an invalid CA location, correct that configuration rather than disabling verification. For machine-wide local PHP configuration, set `curl.cainfo` and `openssl.cafile` in the loaded `php.ini` to an absolute path to a maintained PEM CA bundle and restart that PHP server. HTTPS-inspecting proxies may require the administrator's trusted root in that bundle. The plugin fallback is sufficient for the tested local runtime without such changes.
