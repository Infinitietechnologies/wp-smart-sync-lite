# Wasabi bucket-region parsing

Status: fixed; no commits created.

The Wasabi credential form was valid, but the selected bucket's region contained an opening `LocationConstraint` XML tag. The installed AWS SDK's location-result mutator can capture that tag when an XML declaration and the element are on the same line. The client subsequently rejected the value as an invalid region, so saving failed despite the visible credential-region field being filled correctly.

Bucket discovery now normalizes plain region values, the legacy EU alias, complete location XML, and this specific SDK wrapper artifact before returning choices to the browser. XML parsing disallows network access and rejects document types/entities, malformed locations, and invalid region codes. Empty locations retain the existing provider-specific fallback. Vendor files are unchanged.

The browser also rejects markup in discovered regions and focuses the existing bucket selector if an existing selection has an invalid location. It no longer targets a hidden new-bucket region control in that case.

The regression uses the real SDK HTTP response parser with mocked Wasabi XML and asserts the exact plain region sent to the UI. Other cases cover XML declarations, empty responses, aliases, malformed/unsafe values, and saving the normalized selection. No live provider requests or cloud changes were made.
