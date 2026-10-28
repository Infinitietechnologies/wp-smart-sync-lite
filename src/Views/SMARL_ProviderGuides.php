<?php
namespace SmartSyncS3\Views;

if (!defined('ABSPATH')) exit;

/** Provider-specific, translatable setup copy shared by the page and its controller. */
class SMARL_ProviderGuides
{
    public static function all(): array
    {
        $defaults = [
            'keyLabel' => __('Access key ID', 'smartsync-lite-media-offloader-and-assets-cdn'), 'secretLabel' => __('Secret access key', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'keyHint' => __('Paste the access key ID created for this website.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'secretHint' => __('The secret is usually shown only when the key is created.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'keyPlaceholder' => __('Paste your access key ID', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'secretPlaceholder' => __('Paste your secret access key', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'bucketPlaceholder' => __('e.g. my-website-media', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'bucketNameHint' => __('Use a unique name accepted by your provider. A dedicated bucket keeps website files separate.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'regionPlaceholder' => __('e.g. us-east-1', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'storageTitle' => __('Choose a storage bucket', 'smartsync-lite-media-offloader-and-assets-cdn'), 'storageLabel' => __('Storage bucket', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'storageDescription' => __('Choose an existing bucket or create a dedicated bucket for this website.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'storageStep' => __('Choose storage', 'smartsync-lite-media-offloader-and-assets-cdn'), 'connectLabel' => __('Connect and find buckets', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'connecting' => __('Connecting and finding buckets…', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'credentialsRequired' => __('Enter both the access key ID and secret access key.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'saveNote' => __('Use storage dedicated to your public website files, without private data. No files are transferred until you start a sync.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'emptyTitle' => __('No buckets found', 'smartsync-lite-media-offloader-and-assets-cdn'),
            'emptyHelp' => __('Create a bucket below, or create one in your provider dashboard and refresh this list.', 'smartsync-lite-media-offloader-and-assets-cdn'),
        ];
        $providers = [
            'aws' => [
                'bucketNameHint' => __('AWS shares this bucket-name namespace across accounts and regions. Changing regions will not make a taken name available. Add a unique suffix, e.g. my-site-media-a7f3c9.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'keyPlaceholder' => __('e.g. AKIA... from AWS IAM', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'name' => __('Amazon S3', 'smartsync-lite-media-offloader-and-assets-cdn'), 'badge' => __('S3 media storage', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'connectTitle' => __('Connect your AWS account', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'intro' => __('Use an IAM access key with permission to manage this website’s bucket.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'consoleUrl' => 'https://console.aws.amazon.com/iam/', 'consoleLabel' => __('Open AWS IAM', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'docsUrl' => 'https://docs.aws.amazon.com/IAM/latest/UserGuide/access-keys-admin-managed.html',
                'steps' => [
                    [__('Create a dedicated IAM user', 'smartsync-lite-media-offloader-and-assets-cdn'), __('In the AWS console, open IAM → Users. Create or select a user for this website; use its credentials rather than root account keys.', 'smartsync-lite-media-offloader-and-assets-cdn')],
                    [__('Create an access key', 'smartsync-lite-media-offloader-and-assets-cdn'), __('Open the user’s Security credentials → Access keys → Create access key. Copy the access key ID and secret into this form.', 'smartsync-lite-media-offloader-and-assets-cdn')],
                    [__('Check bucket permissions', 'smartsync-lite-media-offloader-and-assets-cdn'), __('The user needs bucket discovery/access, object read/write/delete, and delivery-policy permissions.', 'smartsync-lite-media-offloader-and-assets-cdn')],
                ],
                'tip' => __('Ask your AWS administrator to scope access to this website’s bucket. Lite uses direct S3 delivery.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            ],
            'digitalocean' => [
                'keyPlaceholder' => __('Paste your Spaces access key ID', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'secretPlaceholder' => __('Paste your Spaces secret key', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'regionPlaceholder' => __('e.g. nyc3', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'name' => __('DigitalOcean Spaces', 'smartsync-lite-media-offloader-and-assets-cdn'), 'badge' => __('S3-compatible object storage', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'connectTitle' => __('Connect DigitalOcean Spaces', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'intro' => __('Use a Spaces access key pair, rather than a DigitalOcean personal access token.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'consoleUrl' => 'https://cloud.digitalocean.com/spaces', 'consoleLabel' => __('Open DigitalOcean Spaces', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'docsUrl' => 'https://docs.digitalocean.com/products/spaces/how-to/manage-access/',
                'steps' => [
                    [__('Open your Spaces dashboard', 'smartsync-lite-media-offloader-and-assets-cdn'), __('Sign in to DigitalOcean and open Spaces Object Storage. Create a Space for this website if you do not already have one.', 'smartsync-lite-media-offloader-and-assets-cdn')],
                    [__('Generate Spaces credentials', 'smartsync-lite-media-offloader-and-assets-cdn'), __('Open Spaces access-key management in your control panel. Create a key with access to the intended Space and copy its key ID and secret.', 'smartsync-lite-media-offloader-and-assets-cdn')],
                    [__('Confirm access and location', 'smartsync-lite-media-offloader-and-assets-cdn'), __('Allow the object operations needed for uploads, reads and deletion. The next step lets you choose the Space and its datacenter.', 'smartsync-lite-media-offloader-and-assets-cdn')],
                ],
                'tip' => __('A restricted key may not list every Space. You can enter an existing Space and datacenter manually in the next step.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            ],
            'wasabi' => [
                'keyPlaceholder' => __('Paste your Wasabi access key ID', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'secretPlaceholder' => __('Paste your Wasabi secret key', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'name' => __('Wasabi', 'smartsync-lite-media-offloader-and-assets-cdn'), 'badge' => __('Regional S3-compatible storage', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'connectTitle' => __('Connect your Wasabi account', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'intro' => __('Use a Wasabi access key pair and the exact region of your bucket.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'consoleUrl' => 'https://console.wasabisys.com/', 'consoleLabel' => __('Open Wasabi Console', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'docsUrl' => 'https://docs.wasabi.com/docs/creating-a-user-account-and-access-key',
                'steps' => [
                    [__('Select a Wasabi user', 'smartsync-lite-media-offloader-and-assets-cdn'), __('In Wasabi Console, create or select a sub-user for this website and grant access to its bucket and objects.', 'smartsync-lite-media-offloader-and-assets-cdn')],
                    [__('Create an access key', 'smartsync-lite-media-offloader-and-assets-cdn'), __('Open the user’s Access Keys and create a key pair. Copy the access key and secret here.', 'smartsync-lite-media-offloader-and-assets-cdn')],
                    [__('Copy your bucket region', 'smartsync-lite-media-offloader-and-assets-cdn'), __('Find the region in your bucket’s details, such as us-east-1. Enter the same region below so SmartSync searches the correct location.', 'smartsync-lite-media-offloader-and-assets-cdn')],
                ],
                'tip' => __('Public website delivery must be allowed by your Wasabi account. Check your account policy if public access is restricted.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            ],
            'google' => ['name' => __('Google Cloud Storage', 'smartsync-lite-media-offloader-and-assets-cdn'), 'badge' => __('Pro only', 'smartsync-lite-media-offloader-and-assets-cdn'), 'steps' => []],
            'cloudflare' => ['name' => __('Cloudflare R2', 'smartsync-lite-media-offloader-and-assets-cdn'), 'badge' => __('Pro only', 'smartsync-lite-media-offloader-and-assets-cdn'), 'steps' => []],
            'bunny' => [
                'name' => __('Bunny CDN', 'smartsync-lite-media-offloader-and-assets-cdn'), 'badge' => __('Storage zone + connected pull zone', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'connectTitle' => __('Connect your Bunny account', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'intro' => __('Use your account API key. In the next step, choose a storage zone and its connected delivery zone.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'consoleUrl' => 'https://panel.bunny.net/account', 'consoleLabel' => __('Open Bunny account settings', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'docsUrl' => 'https://bunny.net/faq/',
                'steps' => [
                    [__('Copy your account API key', 'smartsync-lite-media-offloader-and-assets-cdn'), __('Sign in to the Bunny dashboard and open Account settings → API. Copy the account API key, then return to the connection form.', 'smartsync-lite-media-offloader-and-assets-cdn')],
                    [__('Create a storage zone', 'smartsync-lite-media-offloader-and-assets-cdn'), __('Open Storage in Bunny and create or choose a storage zone for this website’s files.', 'smartsync-lite-media-offloader-and-assets-cdn')],
                    [__('Connect a pull zone', 'smartsync-lite-media-offloader-and-assets-cdn'), __('Inside that storage zone, open Connected Pull Zones and create a connected pull zone. It needs a working public hostname, such as your-zone.b-cdn.net.', 'smartsync-lite-media-offloader-and-assets-cdn')],
                ],
                'tip' => __('If the account API key is unavailable to your subaccount, ask the main account owner for access.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'storageTitle' => __('Choose your storage and pull zone', 'smartsync-lite-media-offloader-and-assets-cdn'), 'storageLabel' => __('Storage zone', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'storageStep' => __('Storage & delivery', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'storageDescription' => __('Choose where files will be stored, then select a public pull-zone hostname connected to that storage zone.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'connectLabel' => __('Connect and find storage zones', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'connecting' => __('Connecting and finding storage zones…', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'credentialsRequired' => __('Enter the account API key from your Bunny account settings.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'saveNote' => __('Saving connects these zones to SmartSync. It does not upload or move any files.', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'emptyTitle' => __('No storage zones found', 'smartsync-lite-media-offloader-and-assets-cdn'),
                'emptyHelp' => __('Create a storage zone in Bunny, connect a public pull zone to it, then refresh this list.', 'smartsync-lite-media-offloader-and-assets-cdn'),
            ],
        ];
        foreach ($providers as $key => $provider) $providers[$key] = array_replace($defaults, $provider);
        return $providers;
    }
}
