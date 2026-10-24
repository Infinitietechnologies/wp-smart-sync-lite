<?php
// Isolated WordPress rendering fixture. No bootstrap, credentials, storage, or network calls.
define('ABSPATH', dirname(__DIR__, 2) . '/');
function current_user_can($capability) { return true; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function esc_html__($value, $domain = '') { return esc_html($value); }
function esc_attr__($value, $domain = '') { return esc_attr($value); }
function __($value, $domain = '') { return $value; }
function esc_html_e($value, $domain = '') { echo esc_html($value); }
function esc_attr_e($value, $domain = '') { echo esc_attr($value); }
function wp_kses_post($data) { return $data; }
function wp_kses($data, $allowed) { return $data; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function selected($value, $expected, $echo = true) {
    $result = $value === $expected ? 'selected="selected"' : '';
    if ($echo) echo $result;
    return $result;
}
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function get_option($key, $default = []) { return $default; }
function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/smartsync/'; }
function wp_die($message) { throw new RuntimeException($message); }

require ABSPATH . 'src/Models/SMARL_CustomUrl.php';
require ABSPATH . 'src/Models/SMARL_CloudConfig.php';
require ABSPATH . 'src/Helpers/SMARL_Helper.php';
$data = isset($argv[2]) ? json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR) : [];
$config = \SmartSyncS3\Models\SMARL_CloudConfig::fromArray($data);
$config->customUrl = \SmartSyncS3\Models\SMARL_CustomUrl::fromArray($data['__custom'] ?? []);
$views = [
    'settings' => ['SMARL_SettingsPage', 'renderSettingsPage'],
    'wizard' => ['SMARL_AWSWizard', 'renderAWSWizardPage'],
    'domain' => ['SMARL_CustomUrl', 'renderCustomUrlPage'],
];
[$view, $method] = $views[$argv[1]];
require ABSPATH . 'src/Views/' . $view . '.php';
$class = '\\SmartSyncS3\\Views\\' . $view;
(new $class($config))->$method();
