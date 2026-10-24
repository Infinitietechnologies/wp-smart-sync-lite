# Provider and configuration audit, 24 October 2026

The review read every method in the six files listed below and traced the registered AJAX routes, common object APIs, credential lifecycle and actual installed AWS/Google/Guzzle SDKs. Existing uncommitted 1.7.2 work was preserved. No commit, Git mutation, cloud account access, real credential or live cloud mutation was used. Findings below are additional to the preceding provider audit.

## Findings

| ID | Status / fixed? | Trigger / reproduction | Root cause and impact | Files / functions | Repair and executable evidence |
| --- | --- | --- | --- | --- | --- |
| PRV-001 | Fixed / Yes | Upload a 64 MiB Bunny object in PHP with `memory_limit=32M`. The original implementation fatally exhausted memory before reaching the fake HTTP handler. | `uploadObject()` read the complete source into a PHP string; ordinary video files could terminate automatic or batch offload. | `src/Models/SMARL_CloudConfig.php`: `uploadObject`, `bunnyRequest`, `getBunnyStreamClient` | Files above 1 MiB use a file resource through Guzzle's cURL upload handler; smaller files retain the existing WordPress HTTP path. Sources close on success, provider rejection, redirect and transport failure. HTTPS endpoint allowlisting, no redirects, bounded timeouts and sanitized errors remain. Tests `PRV-001 Bunny streams a 64 MiB file with bounded memory and closes the source` and `PRV-001 Bunny streaming rejects provider errors and sanitizes transport failures` pass, including an isolated **32M** subprocess. PHP cURL is required for this large-file path; its absence produces a specific administrator error. |
| PRV-002 | Fixed / Yes | Load legacy Google credentials with a SQLite trigger rejecting the `smarl_settings` update. Before repair the upload JSON was deleted while the durable option still referenced it. | Migration removed the only readable credential file before proving its replacement path was durably saved. A database error stranded authentication on the next request. | `SMARL_CloudConfig::load`; `SMARLI_GoogleService::migrateCredentials`, `deleteLegacyCredentialFile`, `credentialsNeedMigration` | Migration retains the source, verifies the new path directly from the database, then removes only an eligible owned legacy JSON. Failed persistence returns the original path, retains its bytes and removes an unused new encrypted copy. Uncertain verification retains both copies. Tests `PRV-002 Google migration preserves active legacy credentials when persistence fails` and `PRV-002 Google migration durably switches paths before deleting the readable source`. |
| PRV-003 | Fixed / Yes | Hold the shared sync/configuration lease, then load settings pointing at legacy Google JSON. Previously a routine load could still migrate/save a stale configuration despite another request owning the lease. | An implicit write in `CloudConfig::load` bypassed coordination and could overwrite a concurrent provider or sync change. | `SMARL_CloudConfig::load` | Only actual migrations acquire the shared lease. Migration re-reads the configuration directly from the database under that lease and honors the newer provider/path. An occupied lease leaves the readable original intact. Invalid migration prerequisites leave configuration inspectable. Test `PRV-003 Google migration respects another configuration or worker lock`. |
| PRV-004 | Fixed / Yes | S3-compatible listing returns continuation tokens A, B, A while each page remains truncated. | The old guard compared only the immediately preceding token, so a longer pagination cycle could loop until request timeout or memory exhaustion. | `SMARL_CloudConfig::listObjects` | Record every returned continuation token and reject any repetition before another provider request. Empty/malformed truncated tokens still fail. Test `PRV-004 non-adjacent S3 continuation cycles fail after the first repeated token` verifies exactly three requests through the installed AWS SDK. The separate legacy helper listing is tracked by the root SYNC audit. |
| PRV-005 | Fixed / Yes | Make WordPress `update_option()` return false for a changed model value. Previously `configureAws` could announce success and delete the previous Google credential file even though the durable setting still referenced it. | `CloudConfig::save` and `CustomUrl::save` ignored silent persistence failure; false also legitimately denotes an unchanged value. | `SMARL_CloudConfig::save`; `SMARL_CustomUrl::save`; `SMARL_AwsConfigAjax::configureAws` caller | Verify the actual durable database value after saving, allowing unchanged saves and throwing a credential-free administrator error on mismatch. Cleanup after configuration cannot delete still-active credentials after failure. Tests `PRV-005 failed model option writes are errors while unchanged saves remain valid` and `PRV-005 failed configuration persistence cannot delete still-active Google credentials`. The test-only namespaced option wrapper models WordPress's false-return contract without changing the shared harness. |
| PRV-006 | Fixed / Yes | Two overlapping Google upload/discovery requests finish in a different order from the user's accepted bucket selection. | Both used one user transient for the most recent path. Ignoring a stale browser response did not undo its server-side write, and a later upload could delete the first still-selected pending credential. | `SMARLI_GoogleService::moveFileTemp`, `getTempPath`, `hasPendingSelections`; `SMARL_AwsConfigAjax::listS3Buckets`, `configureAws`; UI counterpart in `assets/js/wizard.js` | Each discovery reserves an opaque 32-hex token and its exact encrypted path in a user-bound, expiring selection map. The accepted response supplies `selectionToken`; configure submits `google_selection_token` and resolves only that selection. Valid selections pin their files. Missing/expired tokens cannot substitute a newer pending upload. Saved-account reuse ignores unrelated uploads; legacy pre-token pending credentials remain compatible. Expired pending files are pruned on subsequent upload, preserving the active saved path. Tests `PRV-006 overlapping Google discoveries keep independently selected credentials alive`, `PRV-006 expired or missing Google selection cannot substitute the latest pending upload`, and `PRV-006 saved Google account reuse ignores an unrelated newer pending upload`; UI agent added the stale-response token case. |
| PRV-007 | Fixed / Yes | Repair credentials for the same Google bucket using credentials that receive a bucket-read denial. Previously this path made no SDK call and replaced/deleted the working credential anyway. | The same-target shortcut intentionally skipped provisioning but also skipped the read-only connection check used by S3 and Bunny. | `SMARL_CloudConfig::verifyGoogleConnection`; `SMARL_AwsConfigAjax::configureAws` | A credentials-only Google repair must successfully read bucket existence before saving; it does not change IAM or recreate the bucket. Test `PRV-007 Google credential repair verifies bucket access before replacing working credentials` uses the real Google SDK and its expected Guzzle exception contract, verifies the GET and retains the active path on 403. This proves the read prerequisite, not every account permission. |
| PRV-008 | Mitigated / Yes for untracked allocation and duplicate retry; no atomic cloud rollback | Create an OAC/distribution, then fail a later S3 policy write or local option save. Previously the resources were untracked and another attempt created duplicates. | Several remote APIs and local database writes have no shared transaction. The old flow discovered account/policy-read errors only after allocation and retained no durable IDs. | `SMARL_AwsConfigAjax::readBucketPolicy`, `saveCloudFrontCheckpoint`, `configureCloudFront`; uninstall option cleanup coordinated with root | Read STS account and existing bucket policy before allocation. Verify a credential-free recovery map `smarl_cloudfront_pending` before creating anything; save each known OAC/distribution ID immediately. Its key includes account/bucket/region; stable OAC name and distribution caller reference survive ambiguous failures. Retry validates recorded resource identity/origin and reuses those resources. Receipts remain after success so a final settings-write failure cannot lose them. If checkpoint persistence fails after allocation, the safe error reports the known AWS IDs for reconciliation. Tests `PRV-008 failed CloudFront policy setup retains resource IDs and retries without duplicate provisioning` and `PRV-008 CloudFront checkpoint failures abort creation or report already-created resource IDs` verify one creation each, durable records, no false saved configuration and lease release. **Remaining boundary:** abandoning setup can leave the tracked resources active; automatic disable/deploy/delete is not implemented. A provider response lost before an ID becomes known may require manual reconciliation by its stable name/caller reference. AWS deletion requires disabling the distribution and a matching ETag; see [DeleteDistribution](https://docs.aws.amazon.com/cloudfront/latest/APIReference/API_DeleteDistribution.html). |
| PRV-009 | Fixed / Yes | Configure CloudFront for a valid 63-character S3 bucket. The old OAC name was 84 characters. | Appending `-oac-` and 16 random hex characters exceeded CloudFront's documented 64-character name limit, so valid buckets could fail provisioning. | `SMARL_AwsHelper::generateOACArray` | Limit the bucket prefix to 43 characters while retaining the complete random suffix. `PRV-009 CloudFront OAC names fit the official limit for the longest valid S3 bucket` verifies 64 characters, uniqueness and actual AWS command serialization. Primary contract: [CreateOriginAccessControl](https://docs.aws.amazon.com/cloudfront/latest/APIReference/API_CreateOriginAccessControl.html). |

PRV-008 uses AWS's existing caller-reference uniqueness contract rather than claiming create retries are transactional; see [CreateDistribution](https://docs.aws.amazon.com/cloudfront/latest/APIReference/API_CreateDistribution.html). No reported finding depends on an unverified cloud-account experiment.

## Per-method review and execution map

Every method below was read and its caller traced. `Unit` means entered by the isolated PHP regression suite, often with the **actual SDK serializer/iterator and mocked transport**. `SDK construction` means the real factory was instantiated without sending a request. `Legacy` means a callable compatibility helper that is not the active wizard path. These are method-entry and behavior examples, not complete branch/path coverage or proof of live credentials, DNS propagation, billing or cloud delivery.

The covered `run.php` process `php-896-6493e265.json` entered **87/87 owned named methods**: CloudConfig 32, CustomUrl 6, GoogleService 17, Wasabi manager 1, AwsHelper 2, AwsConfigAjax 29. The root merges these raw collector files into its overall coverage report. Anonymous callbacks and file entry guards are outside this named-method total. No owned method is left wholly unexecuted in that captured run; the live-only boundaries still apply individually below.

### `src/Models/SMARL_CloudConfig.php`

| Method | Review / execution evidence | Remaining boundary |
| --- | --- | --- |
| `fromArray` | Unit: initialized defaults, malformed option values, legacy Bunny secret split, provider round trips. | Historic configurations not represented by supplied fixtures. |
| `toArray` | Unit: exact model round trips and configuration fingerprints. | External option-filter behavior. |
| `save` | Unit: unchanged save, failed write, credential-preserving configuration failure (PRV-005). | Real database outage/recovery characteristics. |
| `load` | Unit: cache refresh, malformed settings and Google migration success/failure/lease (PRV-002/003). | Multi-process object-cache implementations; root real-WordPress suites cover operational cache behavior. |
| `delete` | Legacy/unit: compatibility wrapper removes the isolated option. | No remote deletion is implied. |
| `init` | Legacy/unit: creates empty isolated settings. | Existing WordPress option/autoload migration. |
| `isConfigured` | Unit: provider-specific prerequisites and unconfigured operation rejection. | It checks local prerequisites, not every remote permission. |
| `normalizePublicBaseUrl` | Unit: HTTPS normalization, bucket path prefixes and malicious/malformed URL rejection. | DNS and certificate availability. |
| `getPublicBaseUrl` | Unit: common upload/restore/CDN/configuration workflows. | Live public delivery. |
| `validateCloudflareEndpoint` | Unit: account host allowlist, jurisdiction variants and foreign/path/query rejection. | Account existence and permissions. |
| `getS3Client` | SDK construction/serialization: AWS, DO, Wasabi, R2 endpoints, DO create payload, dotted/China AWS hosts. | Live signing/region/account acceptance. |
| `credentials` | Unit indirectly through real SDK factories with fixture credentials. | Real IAM policies and secret rotation. |
| `getCloudfrontClient` | SDK construction: real client and commercial global region. | Live CloudFront API authorization. |
| `getStsClient` | SDK construction: default and explicit region, fake SDK commands during provisioning. | Real account identity/trust. |
| `getAcmClient` | SDK construction: real client in us-east-1. | Actual ACM issuance and quotas. |
| `createDigitalOceanSpace` | Legacy/unit: success/failure through actual S3 SDK command handler, safe error text. | Active wizard uses `AwsConfigAjax::createBucket`; live Spaces permission prerequisites. |
| `validateObjectKey` | Unit: traversal, absolute paths, Windows paths, control characters and valid exact keys. | Future changes to filesystem/cloud naming rules. |
| `uploadObject` | Unit: six-provider dispatch, exact object keys/ACL rules, partial failures and PRV-001 large-file streaming. | Actual network transfer/checksums; single PutObject limits for very large S3 objects. |
| `putS3Object` | Legacy/unit: delegates exact key/file to the common uploader. | No separate active upload path. |
| `downloadObject` | Unit: S3 SaveAs, Google actual SDK destination, Bunny streaming download; root atomic restore tests. | Real disconnect/transport behavior. |
| `deleteObject` | Unit: S3 exact key, Bunny missing-object allowance, Google missing-object tolerance. | Remote versioning/retention policies. |
| `listObjects` | Unit: empty/multi-page S3, non-adjacent cycles, GCS SDK iterator, Bunny nested directories. | Entire manifests are still accumulated in memory; enormous buckets and adversarial provider responses are not exhaustively tested. |
| `getGoogleStorageClient` | SDK construction: generated fixture-only RSA key, actual service/client, no request. | Live token exchange. |
| `googleBucket` | Unit indirectly through actual SDK bucket objects and operations. | Real bucket existence. |
| `createGoogleBucket` | Unit: SDK-serialized create, location and uniform bucket access in new-bucket workflow. | Project policy, quotas and billing. |
| `configureGooglePublicAccess` | Unit: policy version 3, preserves conditional grants/etag and merges public membership. | Organization public-access prevention and live concurrent IAM writers. |
| `verifyGoogleConnection` | Unit: same-target success and denied bucket read (PRV-007). | Read success does not prove write/delete permission. |
| `getBunnyStorageEndpoint` | Unit: region mapping, provider metadata endpoint and legacy regional repair. | Provider's future endpoint changes. |
| `validateBunnyStorageEndpoint` | Unit: HTTPS storage-domain allowlist and foreign credential-destination rejection. | TLS/DNS routing at runtime. |
| `verifyBunnyConnection` | Unit: root listing success, denied credentials and malformed directory response. | Account/storage readiness. |
| `getBunnyStreamClient` | Real client construction, cURL prerequisite reviewed; installed CurlFactory uses bounded read callbacks for large bodies. | A live HTTPS upload through cURL was not performed. |
| `bunnyRequest` | Unit: HTTP status/error/redirect handling, auth headers, path encoding, small/big PUT, GET/DELETE and source cleanup. | Real Bunny transfer and availability. |

### `src/Services/SMARLI_GoogleService.php`

| Method | Review / execution evidence | Remaining boundary |
| --- | --- | --- |
| `__construct` | SDK construction with generated RSA key; explicit credentials and project, no global environment mutation. | Live OAuth/JWT exchange. |
| `getBuckets` | Unit: real Google SDK two-page iterator, names and locations. | Real project visibility and denied list permissions. |
| `getCredentialsDirectory` | Unit: default owned directory and cleanup; source review covers absolute override/symlink guards. | Windows/Unix permission and every custom-directory failure branch. |
| `encryptionKey` | Unit indirectly: encrypted store/decrypt and tamper failure. | WordPress salt rotation intentionally makes old envelopes unreadable. |
| `additionalAuthenticatedData` | Unit indirectly: authenticated envelope round trips. | Moving the installation root requires replacing/remigrating credentials. |
| `storeCredentials` | Unit indirectly: upload/migration encryption, marker files, no raw private key in persisted file. | Disk-full/short-write/randomness failure branches and real webserver deny configuration. |
| `moveFileTemp` | Unit: upload validation, active-path retention, pinning/selection races and expired cleanup. | Actual PHP HTTP upload origin is doubled in unit tests. |
| `getServiceJSON` | Unit: raw/encrypted JSON, external token URL rejection, malformed envelope and tampering. | It validates service-account shape, not remote permission or every PEM cryptographic error. |
| `getProjectId` | Unit: valid and invalid credential reads. | Project accessibility. |
| `getTempPath` | Unit: legacy transient, exact selection, missing/expired/malformed token. | Real transient eviction timing. |
| `hasPendingSelections` | Unit: empty and active selection maps in configure tests. | Provider does not depend on a background transient cleanup job. |
| `credentialsNeedMigration` | Unit: raw legacy source and encrypted durable copy. | Every outside-root override/symlink scenario is not dynamically covered. |
| `migrateCredentials` | Unit: direct compatibility helper and durable model migration with retained original (PRV-002). | External filesystem loss during migration. |
| `deleteLegacyCredentialFile` | Unit indirectly: eligible upload removed only after durable save; source review covers old temporary/owned private roots. | Arbitrary outside-owned credential sources are deliberately retained. |
| `deleteCredentialFile` | Unit: valid owned file and unrelated file exclusion. | Platform ACL denial and symlink races. |
| `cleanupCredentials` | Unit: only owned credential files removed; unrelated fixture retained. | Root uninstall integration covers lifecycle invocation. |
| `createPublicBucket` | Legacy/unit: actual SDK create + version-3 IAM GET/PUT and preserved etag. | Active wizard uses CloudConfig methods; remote IAM/create failures can leave a created bucket. |

### `src/Ajax/SMARL_AwsConfigAjax.php`

| Method | Review / execution evidence | Remaining boundary |
| --- | --- | --- |
| `__construct` | Unit and root WordPress integration initialize callbacks with model defaults or injected model. | None claimed beyond exercised inputs. |
| `setConfiguration` | Core blog-switch tests and root runtime integration refresh callbacks. | Other plugins' blog-switch hooks. |
| `initializeActions` | Unit/root integration route registration and authorization. | Real admin network environment. |
| `authorize` | Unit: every config/domain endpoint rejects missing capability or nonce before side effects. | Real sessions/nonces exercised separately by root. |
| `input` | Unit: defaults, trim, malformed request field rejection. | Not every arbitrary input encoding. |
| `newConfiguration` | Unit: actual factory used by Bunny discovery; provider tests override for transport injection. | No remote operation is implied by construction. |
| `sendError` | Unit: safe provider messages/statuses, generic exception redaction, selected AWS error codes. | All possible third-party exception classes are not enumerated. |
| `requireCloudFront` | Unit: non-AWS/unconfigured/distribution absence rejection. | Existing externally deleted distributions. |
| `requireFreshCloudFront` | Unit: stale provider/custom-domain snapshots and missing config rejected under lease. | Independent non-plugin AWS console changes. |
| `validateDomain` | Unit: invalid schemes/paths/IPs and normal hostnames. | IDN conversion is not added. |
| `validateAwsConfiguration` | Unit: supported provider/types/name/credentials/region guards. | Provider-side reserved names/quotas. |
| `createBucket` | Unit: new AWS/DO/Wasabi/R2/GCS flows, real SDK arguments and waiters. | Actual bucket provisioning latency/permissions. |
| `readBucketPolicy` | Unit: policy preservation and CloudFront preflight; existing NoSuchPolicy handling reviewed. | External writers can change policy between requests. |
| `setBucketPolicy` | Unit: preserve unrelated statements, replace plugin-owned statement and partition ARNs. | Policy size/quota and external concurrent writers. |
| `configurePublicBucket` | Unit: AWS public-access flags and GCS IAM policy version/membership. | Account/organization public-access prevention. |
| `saveCloudFrontCheckpoint` | Unit: successful durable receipt and failures before/after OAC allocation (PRV-008). | Storage that fails after returning success still depends on database durability. |
| `configureCloudFront` | Unit: actual AWS command validation, account/policy preflight, IDs/checkpoints and retry reuse (PRV-008). | Live distribution deployment, abandoned-resource cleanup, lost remote responses and dotted S3 origin behavior. |
| `directPublicUrl` | Unit: all provider workflows, dotted AWS path style, China partition, Wasabi/GCS path prefixes. | Actual public host delivery. |
| `sameExistingTarget` | Unit: remote-only credential repair, legacy AWS URL correction, Bunny metadata repair, changed-target rejection. | Does not certify all new credentials' permissions. |
| `configureAws` | Unit: six-provider new/existing flow, lease/processing guards, persistence and repair failure, selection binding. | Remote provisioning is not atomic; see PRV-008. |
| `bunnyZones` | Unit: official paginated envelope, details fallback, hostname eligibility and malformed pagination. | Future account API schema changes. |
| `bunnyAccountGet` | Unit: auth/rate-limit/transport/malformed response errors and no redirects. | Actual account key scopes and availability. |
| `listS3Buckets` | Unit: S3/DO/Wasabi/R2/Bunny schemas and authorization; Google iterator tested through the real service separately. | A live Google upload→OAuth→list AJAX request is not performed. |
| `certificateRecords` | Unit: missing-yet-pending ACM records and later ready records plus distribution CNAME. | DNS propagation. |
| `generateSslCertificate` | Unit: asynchronous ARN persistence, reuse/no sleeps, invalid domains and stale snapshots. | Actual issuance, ACM limits and DNS ownership. |
| `domainMatchesCertificate` | Unit: exact/wildcard coverage and existing-alias rejection during attachment. | All certificate SAN combinations are not enumerated. |
| `distributionDnsVerified` | Unit: normalized exact CNAME comparison; namespace DNS test double. | Real resolvers, propagation and CNAME flattening. |
| `checkDnsRecord` | Unit: requested ARN binding, issued matching cert, current DNS and refreshed records. | Live DNS/ACM. |
| `updateCloudFrontAlternateDomain` | Unit: idempotent alias merge, certificate covering every alias and ETag preservation. | Actual distribution deployment and alias ownership conflicts. |

### Remaining models/helpers

| File / method | Review / execution evidence | Remaining boundary |
| --- | --- | --- |
| `SMARL_CustomUrl::__construct` | Unit: defaults and supplied ARN/domain/records. | No live cloud dependency. |
| `SMARL_CustomUrl::load` | Unit: malformed option fallback and domain workflows. | External option filters. |
| `SMARL_CustomUrl::fromArray` | Unit: wrong scalar/record shapes safely normalized. | Does not independently authenticate a certificate. |
| `SMARL_CustomUrl::reset` | Unit: provider migration resets old domain fields only after saved config. | Database failure is reported. |
| `SMARL_CustomUrl::save` | Unit: durable unchanged and failed saves (PRV-005). | Real database outage. |
| `SMARL_CustomUrl::toArray` | Unit: round trips and snapshot comparison. | No extra behavior implied. |
| `SMARL_AwsHelper::generateOACArray` | Unit: unique names, actual create serialization and max bucket length (PRV-009). | Live OAC quotas. |
| `SMARL_AwsHelper::createCloudfrontArray` | Unit: actual create command validation, origin/OAC/query-string settings and unique caller refs. | Live deployment; dotted bucket origin remains a stated unverified boundary. |
| `SMARLI_WasabiBucketManager::createBucket` | Legacy/unit: wrong-provider rejection, failed create, successful actual SDK waiter. | Active wizard uses AwsConfigAjax; live Wasabi public-access account restrictions remain prerequisites. |

## Checks and limits

- Added all provider audit regressions in `tests/cases/provider_audit2.php`; existing shared test files were not edited by this agent.
- Focused second-audit provider execution: **20 passed / 0 failed**. The combined suite reached 168 passed / 0 failed before the final OAC-name case and later parallel root/core additions. The most recent combined covered run at this agent's handoff was 178 passed / 4 failed; all four were newly added root sync recovery/log/cancellation reproductions under repair. The collector entered all 87 owned methods. Root runs the final combined suite after all agents finish.
- PHPStan level 5 runtime and plugin-entrypoint analyses both passed after the provider changes. The isolated 64 MiB upload passed under PHP `memory_limit=32M`.
- No real cloud credentials, billing, provider permissions, CDN propagation, production DNS, quotas or remote destructive behavior were exercised. S3 objects beyond single-PutObject limits and extremely large in-memory listing manifests remain scalability boundaries rather than claimed tested capacities.
