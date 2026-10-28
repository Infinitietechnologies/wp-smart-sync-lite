# Bucket-name conflict guidance

Status: implemented in maintained and installed plugins; no commits created.

AWS returned `BucketAlreadyExists`, but the plugin displayed a generic credentials/permissions error. Known name conflicts now return HTTP 409 with a safe structured code and a clear message: choose another name, or use existing storage for `BucketAlreadyOwnedByYou`. Raw provider responses and credentials are excluded. Configuration is preserved and no alternate bucket is selected automatically.

The AWS new-bucket form displays an amber warning describing its shared namespace across accounts and regions and suggesting a unique suffix. This plugin uses ordinary shared-namespace bucket creation. A provider-specific guide supplies this copy, so other providers retain their own general naming guidance. The warning is associated with the name field through its existing description.

After a creation conflict, the wizard returns to storage, marks and focuses the name field after unlocking controls, and permits a corrected retry. Editing clears the invalid-field state. Regression cases cover both conflict codes, saved configuration and lock preservation, and retry completion. The native browser workflow checks the warning at 320 and 1440 pixels. PHP cases and static analysis passed; cloud operations were mocked.

AWS reference: https://docs.aws.amazon.com/AmazonS3/latest/userguide/gpbucketnamespaces.html
