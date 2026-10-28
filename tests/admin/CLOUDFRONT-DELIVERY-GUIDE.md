# CloudFront delivery-step guidance

Status: fixed. The right-hand panel previously kept showing IAM credential instructions on the AWS delivery-choice step, so it never explained how to complete CloudFront setup.

The guide now follows the current wizard step. AWS delivery shows a CloudFront-specific heading, four setup steps, an expandable list of the additional IAM actions used by this implementation, a CloudFront distributions console link and the official AWS getting-started guide. It explains automatic distribution/origin-access-control creation or recovery, bucket access changes, deployment, the automatically saved cloudfront.net address, uploading files and enabling cloud delivery. Custom domains are identified as optional follow-up work. Going back restores the account instructions, other providers keep their existing guides, and completion keeps the guide hidden.

The permission list describes the actual create/read CloudFront and S3 policy/public-access operations in the maintained setup path, alongside existing bucket/object permissions. STS GetCallerIdentity is described as an account lookup rather than a permission that must be granted.

Verification: 30 relevant admin checks passed, including navigation to delivery and back without issuing a configuration request. The actual browser check passed across six providers and four viewport widths, including the CloudFront guide with its permission disclosure open. PHP syntax, JavaScript syntax, both PHPStan checks and whitespace checks passed. All provider responses were synthetic; no CloudFront resources or bucket policies were created or changed by these checks.

Screenshots: system temp `smartsync-cloud-setup-preview/aws-cloudfront-guide-*.png`.

Reference: [AWS CloudFront with an S3 origin and origin access control](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/GettingStarted.SimpleDistribution.html).
