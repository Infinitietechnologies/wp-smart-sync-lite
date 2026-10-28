# Remember the optional AWS discovery region

Status: fixed. The optional connection region was previously sent only with the discovery request, so refreshing or reopening the wizard lost it.

Valid AWS discovery submissions now persist the hint in the site's `smarl_aws_discovery_region` WordPress option, with autoload disabled. Reopening the wizard restores the stored value. Clearing the input and connecting saves an empty string to retain automatic discovery. Invalid hints are rejected before changing the option; malformed stored values render as blank. The option is removed on uninstall.

This is a separate discovery preference. It does not replace the active bucket's region, credentials, provider configuration or upload destination. Discovery still uses the AWS fallback endpoint for an empty hint and the actual selected bucket region for later operations. The hint is stored before the provider request, so a later cloud permission/credential failure does not discard a valid submitted preference.

Checks cover saved/restored hints, blank automatic mode, invalid input, and unchanged active storage configuration. These run alongside the full PHP/admin suites described in INLINE-CLOUDFRONT.md.
