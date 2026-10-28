<?php
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__, 2) . '/');
if (!defined('WP_CONTENT_DIR')) define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
if (!defined('SMARL_TEXT_DOMAIN')) define('SMARL_TEXT_DOMAIN', 'smartsync-lite-media-offloader-and-assets-cdn');
if (!defined('SMARL_VERSION')) define('SMARL_VERSION', '1.8.0');
if (!defined('SMARL_ASSET_VERSION_CODE')) define('SMARL_ASSET_VERSION_CODE', SMARL_VERSION);
if (!defined('SMARL_DB_VERSION')) define('SMARL_DB_VERSION', '3');
