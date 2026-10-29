# Changelog

## 1.8.0

### Features

- Incremental media synchronization skips unchanged files and retries unsuccessful uploads.
- Sync status widgets, progress notifications and recovery controls make background transfers easier to manage.

### Improvements

- Improved cloud setup, connection checks and saved settings for Amazon S3, DigitalOcean Spaces, Wasabi and Bunny.
- Improved Bunny storage connections and streaming media uploads.
- Improved media restoration and local cleanup, with better protection for changed files and shared attachments.

### Fixes

- Fixed interrupted or stuck syncs, stop controls and outdated progress displays.
- Fixed media ownership tracking when switching storage providers or buckets.

### Compatibility

- Updated for WordPress 7.1 and PHP 8.1 through 8.5.
- Requires WordPress 6.5 or later and PHP 8.1 or later.
