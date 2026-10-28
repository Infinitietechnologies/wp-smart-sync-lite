=== SmartSync Lite ===
Contributors: infinitietech, jaysparmar
Tags: media offload, s3, cdn, cloud storage, sync
Requires at least: 6.5
Tested up to: 7.1.3
Requires PHP: 8.1
Stable tag: 1.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload WordPress media to Amazon S3, DigitalOcean Spaces, Wasabi or Bunny Storage/CDN.

== Description ==
SmartSync Lite synchronizes media with **Amazon S3**, **DigitalOcean Spaces**, **Wasabi**, and **Bunny Storage/CDN**. Connect storage, upload media through the background queue, then enable cloud media delivery.

### Lite features
* Provider setup with credential verification and storage discovery. Select existing storage or create a bucket where supported. Bunny requires an existing storage zone and connected public pull zone.
* Incremental media uploads, full resync, local restore, progress reporting and stop controls.
* Media URL delivery through direct storage URLs or a connected Bunny pull zone.
* Attachment originals and generated sizes, with optional local cleanup after successful offloading.
* Per-site media configuration and uploads boundaries on WordPress multisite.

### Pro features — preview only
These features display disabled previews with a **Pro only** badge. Their integration and synchronization implementations are not included in Lite.

* Google Cloud Storage integration — [Pro](https://codecanyon.net/item/wp-smartsync-wordpress-plugin-for-media-assets-cdn-cloud-storage/56335604)
* Cloudflare R2 integration — [Pro](https://codecanyon.net/item/wp-smartsync-wordpress-plugin-for-media-assets-cdn-cloud-storage/56335604)
* Amazon CloudFront CDN integration — [Pro](https://codecanyon.net/item/wp-smartsync-wordpress-plugin-for-media-assets-cdn-cloud-storage/56335604)
* JS and CSS file synchronization — [Pro](https://codecanyon.net/item/wp-smartsync-wordpress-plugin-for-media-assets-cdn-cloud-storage/56335604)

== Installation ==
1. Install the dependency-complete SmartSync Lite ZIP through **Plugins > Add New > Upload Plugin**. For a source checkout, run composer install --no-dev --prefer-dist before uploading the plugin.
2. Activate **SmartSync Lite**.
3. Open **SmartSync Lite > Cloud Config** and connect a supported Lite provider.
4. Upload media, review the results, then enable cloud media delivery.

Credentials must permit the operations selected for the website's storage. Provider account restrictions and public-access controls can affect setup. Do not activate the full and Lite editions together; they share plugin state and class names.

== Frequently Asked Questions ==
= Can I use CloudFront, Google Cloud Storage, R2 or synchronize JS/CSS? =
Those are Pro features. Lite displays previews and upgrade links; it cannot execute those operations.

= Can I switch an existing Pro configuration to Lite? =
Restore offloaded media with the full edition before switching from a Pro provider or CloudFront configuration. Lite preserves legacy settings but does not operate those integrations or rewrite their media URLs. Back up the database and media first.

= What does media sync scan? =
Single-site bulk scans use eligible media file types beneath the WordPress root, excluding private/generated directories. Multisite scans and restores stay inside the current site's uploads and exclude other sites. JS/CSS files are excluded even if old settings contain those extensions.

= Does the plugin measure provider costs? =
Usage reports object counts and bytes; they do not calculate provider bills.

== Changelog ==
= 1.8.0 =
* Introduce SmartSync Lite on the separate lite branch.
* Keep Amazon S3, DigitalOcean Spaces, Wasabi and Bunny media offloading, restore and delivery.
* Show Google Cloud Storage, Cloudflare R2, CloudFront and JS/CSS synchronization as disabled Pro only previews with upgrade links.
* Remove Pro integration code, Google SDK dependencies, CloudFront provisioning/cache code and script/style URL rewriting.
* Reject crafted Pro requests and inherited asset jobs before storage operations; preserve legacy settings without activating Pro integrations.
* Retain the 1.8.0 media reliability, incremental history, recovery and compatibility fixes. See tests/LITE.md in the source checkout for Lite verification.

== Upgrade Notice ==
= 1.8.0 =
SmartSync Lite is a media edition. Restore media from Pro-only configurations using the full edition before switching. Do not activate both editions together.
