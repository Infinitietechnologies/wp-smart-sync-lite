# Updating the existing WordPress.org plugin

Listing: https://wordpress.org/plugins/smartsync-lite-media-offloader-and-assets-cdn/

SVN: https://plugins.svn.wordpress.org/smartsync-lite-media-offloader-and-assets-cdn/

The existing published version checked on 2026-10-28 is **1.4**. The prepared update is **1.8.0**, requiring WordPress 6.5+ and PHP 8.1+. GitHub pushes do not update WordPress.org. Publication happens through SVN using a WordPress.org account with **committer** access; appearing in the contributors list alone does not establish that access.

The confirmed account name supplied for this release is `infinitietech`. Enable account two-factor authentication and generate an SVN-specific password under [Account & Security → SVN credentials](https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password). Enter passwords locally into the publishing client, never in chat, source files, command arguments or GitHub commits.

## Prepare the package

Keep the header version and `SMARL_VERSION` in `aws-s3-smartsync-wp.php` aligned with `Stable tag` in `readme.txt`. Use a new version for every published code change; do not overwrite an existing release tag. Preserve the installed basename:

`smartsync-lite-media-offloader-and-assets-cdn/aws-s3-smartsync-wp.php`

Install production dependencies and build from the Lite repository:

```powershell
composer install --no-dev --prefer-dist --no-interaction
./tests/build-plugin.ps1
```

For version 1.8.0 this produces `dist/smartsync-lite-media-offloader-and-assets-cdn.1.8.0.zip`. Its top-level directory matches the WordPress.org slug. The earlier GitHub archive rooted at `smartsync/` is not the directory-update artifact. The build includes Composer dependencies and excludes the standalone documentation website, tests, Git, project skills and local logs. Provider guides in the plugin link directly to official provider documentation. Run the checks in [tests/LITE.md](tests/LITE.md), including the upgrade test against the previously published version.

## Publish through SVN

1. Check out the SVN repository using a Subversion client. Preserve the existing root `assets/` and all previous `tags/`.
2. Extract the release ZIP. Copy the **contents** of its plugin directory into `trunk/`, including `vendor/`. The main PHP file must be directly under `trunk/`.
3. Add new files and schedule retired files for removal. Review the SVN changes; do not leave obsolete Pro implementations or private/development files in `trunk/`.
4. Make a local SVN copy of `trunk/` to the new tag, e.g. `tags/1.8.0/`. Both readmes must say `Stable tag: 1.8.0`.
5. Commit the reviewed `trunk/` and new tag together, using the exact WordPress.org username and the SVN-specific password. A commit publishes code to WordPress.org and can make it available to installed sites.
6. If release confirmation is enabled, use the confirmation email or the plugin's [Advanced / Release Management page](https://wordpress.org/plugins/smartsync-lite-media-offloader-and-assets-cdn/advanced/) to approve 1.8.0.
7. Verify the listing and [plugin information API](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=smartsync-lite-media-offloader-and-assets-cdn) show 1.8.0, then download the WordPress.org ZIP and verify its version, basename and dependencies. Processing and caches may delay the public update.

The corrected local publisher uploads changed runtime files to trunk with `Stable tag: 1.4`, keeping the existing stable release selected. It then uses a server-side SVN copy to create `tags/1.8.0` and updates both readmes to `Stable tag: 1.8.0` in one final transaction. This avoids uploading a second full release tree. A checkpoint permits resuming the final tagging step if necessary. Earlier tags and listing images remain untouched.

The publisher verifies the frozen package and action plan, keeps the computer awake during the transfer, and passes the password through stdin with authentication caching disabled. It does not store the password in the release files. The initial larger working-copy tag attempt was rejected and did not publish a release; use the corrected publisher for the retry.

GitHub Actions remain disabled; this procedure does not enable an automatic publishing workflow.

References: [Using Subversion](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/), [SVN account credentials](https://make.wordpress.org/meta/handbook/tutorials-guides/svn-access/), [readme/version handling](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/), and [release confirmations](https://developer.wordpress.org/plugins/wordpress-org/release-confirmation-emails/).
