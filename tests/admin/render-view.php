<?php
// Isolated WordPress rendering fixture. No bootstrap, credentials, storage, or network calls.
define('ABSPATH', dirname(__DIR__, 2) . '/');
function current_user_can($capability) { return $GLOBALS['smartsync_view_allowed'] ?? true; }
function add_action($hook, $callback) { $GLOBALS['smartsync_view_actions'][$hook][] = $callback; }
function add_menu_page(...$arguments) { $GLOBALS['smartsync_view_menu'] = $arguments; return 'fixture-menu'; }
function add_submenu_page(...$arguments) { $GLOBALS['smartsync_view_menu'] = $arguments; return 'fixture-menu'; }
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
function get_option($key, $default = []) {
    if ($key === 'smarl_aws_discovery_region') return $GLOBALS['smartsync_view_data']['__aws_discovery_region'] ?? $default;
    return $key === 'smarl_sync_settings' && !empty($GLOBALS['smartsync_view_data']['__sync_running']) ? ['s3SyncStatus' => 'processing'] : $default;
}
function wp_salt($scheme = 'auth') { return 'isolated-render-fixture-salt'; }
function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/smartsync/'; }
function wp_die($message) { throw new RuntimeException($message); }

require ABSPATH . 'src/Models/SMARL_CustomUrl.php';
require ABSPATH . 'src/Models/SMARL_CloudConfig.php';
require ABSPATH . 'src/Helpers/SMARL_Helper.php';
require ABSPATH . 'src/Views/SMARL_ProviderGuides.php';
require ABSPATH . 'src/Services/SMARLI_GoogleService.php';
$data = isset($argv[2]) ? json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR) : [];
$GLOBALS['smartsync_view_data'] = $data;
$GLOBALS['smartsync_view_allowed'] = ($data['__can_manage'] ?? true) === true;
$config = \SmartSyncS3\Models\SMARL_CloudConfig::fromArray($data);
if (isset($data['__google_credentials'])) {
    $credentialFile = tempnam(sys_get_temp_dir(), 'smartsync-render-');
    file_put_contents($credentialFile, json_encode($data['__google_credentials'], JSON_THROW_ON_ERROR));
    $config->googleServicePath = $credentialFile;
    register_shutdown_function(static function () use ($credentialFile) { unlink($credentialFile); });
}
$config->customUrl = \SmartSyncS3\Models\SMARL_CustomUrl::fromArray($data['__custom'] ?? []);
$views = [
    'settings' => ['SMARL_SettingsPage', 'renderSettingsPage'],
    'wizard' => ['SMARL_AWSWizard', 'renderAWSWizardPage'],
    'domain' => ['SMARL_CustomUrl', 'renderCustomUrlPage'],
];
[$view, $method] = $views[$argv[1]];
require ABSPATH . 'src/Views/' . $view . '.php';
$class = '\\SmartSyncS3\\Views\\' . $view;
$instance = new $class($config);
if (!empty($data['__inspect_menu'])) {
    $instance->loadView();
    foreach ($GLOBALS['smartsync_view_actions']['admin_menu'] ?? [] as $callback) $callback();
    echo wp_json_encode($GLOBALS['smartsync_view_menu']);
} else {
    $instance->$method();
}
