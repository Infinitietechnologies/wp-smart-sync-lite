=== SmartSync ===
Contributors: infinitietech, jaysparmar
Tags: aws, s3, cloudfront, cdn, sync
Requires at least: 6.5
Tested up to: 7.1.3
Requires PHP: 8.1
Stable tag: 1.7.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SmartSync synchronizes WordPress media and assets with cloud storage and supports optional CDN delivery.

== Description ==
SmartSync supports **Amazon S3**, **DigitalOcean Spaces**, **Cloudflare R2**, **Wasabi**, **Google Cloud Storage**, and **Bunny Storage/CDN**. Configure a provider through the WordPress admin wizard, synchronize media or assets, and enable their CDN URLs independently. Optional CloudFront and ACM custom-domain setup is available for AWS.

### Features:
- **Provider Configuration** – Select an existing bucket or create one through the wizard where supported. Bunny requires an existing storage zone with a connected pull zone.
- **Optional CloudFront Setup** – Create an AWS CloudFront distribution and configure a custom domain with an ACM certificate when selected.
- **Synchronization Management** – Upload or restore files through a background queue with progress, error reporting, and stop controls.
- **Media and Asset Delivery** – Enable CDN delivery separately after successful synchronization.
- **Attachment Offloading** – Upload originals and generated sizes; optional local cleanup requires successful uploads and enabled media CDN delivery.

== Installation ==
1. Download the plugin or clone it from GitHub.
   For a source checkout, run `composer install --no-dev --prefer-dist` in the plugin directory to install the required dependencies before uploading or activating it.
2. Upload the plugin to the `/wp-content/plugins/` directory, or install it via **Plugins > Add New** in WordPress.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. Open **SmartSync Settings > Cloud Config**, select your provider, and supply its access credentials or Google service-account JSON.
5. Synchronize the required media/assets, review the results, then enable their cloud delivery switches.

== How It Works ==
1. **Provider Setup** – Validate credentials and select or create the storage resources requested in the wizard.
2. **Background Sync** – Queue uploads or downloads and monitor their actual outcomes.
3. **CDN Delivery** – Enable the configured public cloud URL for media and/or assets after synchronization. CloudFront is optional for AWS.
4. **Attachment Lifecycle** – Offload new or regenerated attachment files, retain local copies on upload failure, and distinguish local cleanup from intentional cloud deletion.

Single-site bulk scans use eligible file types beneath the WordPress installation root, with private/generated directories excluded. Multisite media scans and restores stay inside the current site's uploads; the main site excludes child-site upload directories. Restoring shared assets requires a network administrator, and ordinary site-admin restores respect WordPress file-type restrictions. Analytics reports object counts and sizes, not provider billing costs.

== How to Generate AWS IAM Keys ==
1. Log in to the [AWS Management Console](https://aws.amazon.com/console/).
2. Navigate to **IAM (Identity and Access Management)**.
3. Click **Users** → Select or create a user.
4. Go to the **Security credentials** tab.
5. Click **Create access key** → Copy and store the keys securely.

> **Permissions depend on selected operations:** Credentials must allow the bucket/object operations you choose. Creating public bucket delivery or CloudFront resources also involves bucket policies, CloudFront, STS, and ACM. SmartSync does not provide a universal IAM policy; provider account restrictions and public-access controls can affect setup.

== Optional CloudFront Delivery ==
- AWS configurations can use CloudFront instead of direct bucket delivery.
- Custom domains use ACM certificates and require certificate-validation and distribution CNAME records.
- Media and asset delivery switches remain separate from provider setup and synchronization.

== Frequently Asked Questions ==
= Does this plugin support multi-region S3 buckets? =
Yes, the plugin allows you to configure S3 buckets in different AWS regions.

= Is CloudFront mandatory? =
No, you can use the plugin with S3 alone, but CloudFront improves performance.

== Screenshots ==
1. **SmartSync Dashboard** – View sync status and settings.
2. **AWS Integration Panel** – Enter IAM credentials for authentication.

== Changelog ==
= 1.7.3 =
* Preserve cloud-object ownership across storage changes and shared attachments; protect changed local files during cleanup.
* Repair interrupted sync recovery, cancellation, coordination caches, cyclic listings and bounded progress logs.
* Stream large Bunny uploads, verify credential changes and settings persistence, and retain CloudFront provisioning receipts for retries.
* Recover admin polling and browser navigation, invalidate stale provider discovery, and show active synchronization indicators.

= 1.7.2 =
- Verify Bunny storage access before saving and distinguish account API keys from storage credentials.
- Handle paginated Bunny zones, regional storage hostnames, and active HTTPS-ready public pull zones.
- Allow existing S3-compatible buckets to be entered without account-wide listing permission.
- Correct DigitalOcean SDK region handling for new Spaces buckets.
- Correct dotted S3 HTTPS URLs, China endpoints, partition-specific bucket policies, and unsupported automatic CloudFront setup.
- Preserve newer provider settings when changing CDN or local-cleanup switches.
- Read Google conditional IAM policies safely and merge public-read membership without duplicate role bindings.

= 1.7.1 =
- Repair restore queues, progress, cancellation, and provider dispatch.
- Keep cloud copies during local media cleanup and retain local files after upload failures.
- Support attachment size regeneration and media outside the current upload month.
- Restrict CDN rewriting and admin assets to the appropriate site URLs and plugin pages.
- Harden configuration requests, credential storage, lifecycle cleanup, and dependencies.

= 1.0 =
- Initial release with S3 and CloudFront support.

== Upgrade Notice ==
= 1.0 =
- First stable version.

== License ==
This plugin is licensed under **GPLv2 or later**.  
For details: [GNU GPL v2](https://www.gnu.org/licenses/gpl-2.0.html).

== Localization ==
To generate a `.pot` file for translations, run:
```sh
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
php wp-cli.phar i18n make-pot . languages/wp-smartsync.pot --exclude=vendor,tests,.agents,Documentation --domain=smartsync-lite-media-offloader-and-assets-cdn
```

== Requirements and data handling ==

WordPress 6.5 or later is required. This release has been tested with WordPress 7.1.3, the latest stable release verified on 24 October 2026. Compatibility checks also track the latest stable WordPress version in CI.

PHP 8.1 or later and the bundled Composer dependencies are required. For a source checkout, run `composer install` using the included lockfile before installing the plugin.

Automatic local media cleanup runs after metadata generation finishes, only after all attachment files upload successfully and a media CDN URL is enabled. User attachment deletion removes the corresponding cloud objects. Keep an independent backup before enabling offloading or removing the plugin.

Deactivation stops queued synchronization and retains configuration. Uninstall removes SmartSync settings, local credentials, queue data, and offload metadata. It does not download files or delete cloud objects; restore any locally removed media before uninstalling.

== External Services ==

This plugin connects to third-party cloud storage and CDN services to manage file uploads, synchronization, and asset delivery. The services used are:

AWS (Amazon Web Services)

This plugin integrates with AWS S3 and related APIs for storing and retrieving media assets and configuration data.

- Data Sent: Bucket name, region, access credentials (user-supplied), and file metadata.
- When: During initial configuration, file uploads, or API-based operations.
- Terms: https://aws.amazon.com/service-terms/
- Privacy Policy: https://aws.amazon.com/privacy/

Cloudflare R2

Cloudflare R2 is used to store media files and access them via Cloudflare’s object storage API.

- Data Sent: Bucket name, region, access credentials (user-supplied), and file metadata.
- When: When configured and used for uploads, downloads, or deletions.
- Terms: https://www.cloudflare.com/terms/
- Privacy Policy: https://www.cloudflare.com/privacypolicy/

DigitalOcean Spaces

DigitalOcean Spaces provides cloud object storage for files uploaded via the plugin.

- Data Sent: Space name, region, access credentials (user-supplied), and file metadata.
- When: When performing file uploads or configuring DigitalOcean Spaces storage.
- Terms: https://www.digitalocean.com/legal/terms
- Privacy Policy: https://www.digitalocean.com/legal/privacy-policy

Google Cloud Storage

The plugin optionally integrates with Google Cloud Storage or Google APIs for storing, syncing, or managing user media files.

- Data Sent: Bucket name, access credentials (user-supplied), file metadata, and API requests based on plugin functionality.
- When: When the Google storage option is configured and used for upload/download operations.
- Terms: https://cloud.google.com/terms/
- Privacy Policy: https://policies.google.com/privacy
