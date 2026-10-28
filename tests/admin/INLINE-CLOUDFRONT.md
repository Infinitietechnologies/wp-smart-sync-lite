# Inline CloudFront preparation on the custom-URL page

Status: implemented. The old page stopped at an error notice when AWS S3 was configured without CloudFront, forcing users back through the provider wizard.

The page now offers setup using the current site's saved AWS connection and selected bucket. A new privileged `setup_custom_cloudfront` action creates or resumes the existing credential-free CloudFront recovery checkpoint. Pending deployment returns an explicit Check deployment action; there is no automatic deployment polling. Once AWS reports Deployed, setup grants the distribution object-read access and saves its distribution ID, domain and enabled flag. Continue then unlocks the existing certificate/DNS/custom-domain flow on the same page. Refresh uses the persisted CloudFront configuration or resumes the existing recovery records on the next explicit setup request.

Safety and preservation:

- Every action requires manage_options and the existing nonce, an unchanged opaque configuration reference, an idle sync and the shared configuration lease. China/GovCloud and non-AWS storage are rejected before provisioning.
- Saved credentials stay on the server. Client requests contain only the reference and setup/check operation. Check-only requests cannot start a new distribution.
- Provisioning and recovery reuse the normal wizard's stable OAC name, distribution caller reference and immediately persisted resource IDs. Individual inline AWS operations have bounded connection/request timeouts and no SDK retries.
- While deploying, the active storage configuration and bucket access policy remain unchanged. Once deployed, only the CloudFront read grant is added. The existing SmartSync public-read policy and unrelated statements remain intact; the bucket's public-access-block settings stay unchanged.
- The existing S3 delivery URL, CDN eligibility/enable flags, local-cleanup flag, upload receipts, custom-domain records and sync results are retained. The custom-domain attachment flow remains responsible for changing the delivery URL. This differs deliberately from the provider wizard's initial CloudFront setup, which blocks direct public S3 access.
- Failed policy or database writes keep the working S3 configuration and resource checkpoint, permitting retry without duplicate provisioning. Initial page load performs no AWS writes.

Validation: actual SDK clients with intercepted AWS operations cover deployment, activation, policy/database failures, idempotent retries, stale configuration, active/finishing work, check-only requests, provider/partition guards, credential secrecy and unchanged delivery settings. Full PHP suite: 281 passing cases. Full admin suite: 136 passing cases. Both static checks pass. The real browser check covers setup, manual deployment check, same-page continuation and certificate requests at 320/390/768/1440/1920 px. All AWS responses in these checks are synthetic; live provisioning/deployment is not established.

Screenshots: system temp `smartsync-inline-cloudfront-preview`.
