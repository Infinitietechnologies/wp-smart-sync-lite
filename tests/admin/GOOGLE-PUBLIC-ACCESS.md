# Google public-delivery setup diagnostics

Status: improved and tested. User confirmed that allowing public access resolved their setup failure. No live IAM changes were made by the agent.

Bucket discovery may succeed while saving fails: saving reads the bucket and its IAM policy, then adds unconditional `allUsers` object-viewer access for the plugin's public-delivery URL. Listing permission alone does not authorize these operations. The generic provider error obscured the requirement.

Setup now identifies missing `storage.buckets.get`, `storage.buckets.getIamPolicy`, or `storage.buckets.setIamPolicy` permissions by the failed request stage. Enforced bucket Public access prevention is detected before any IAM write. Known inherited public-prevention and organization-policy failures receive specific, safe instructions. Existing public bindings still avoid unnecessary writes; version-3 IAM conditions and etags remain preserved. Transport errors retain the TLS category. Raw provider bodies and credentials are never displayed. Failed setup does not overwrite the existing saved connection.

Seven actual-SDK/mocked-HTTP save cases cover stage-specific denial, invalid authentication, bucket-level and inherited prevention, and domain-restricted sharing. Existing successful Google setup and conditional IAM policy tests remain part of the PHP suite. The guide now names the required bucket IAM permissions and explains public-delivery restrictions.

This diagnostic change does not disable Google policies, make a live bucket public, or change the existing public-delivery setup contract.
