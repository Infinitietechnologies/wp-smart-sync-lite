<?php
/** Isolated MySQL multisite lifecycle checks; stages run through wp eval-file. */
if (!defined('WP_CLI') || !WP_CLI || !is_multisite() || strpos(str_replace('\\', '/', ABSPATH), 'smartsync-mysql/smartsync-wordpress-audit-fixture/wordpress/') === false) {
    throw new RuntimeException('This test requires the isolated SmartSync MySQL multisite audit fixture.');
}

use SmartSyncS3\Models\SMARL_S3SyncSettings;
use SmartSyncS3\SMARL_SmartSync;

final class SmartSyncNetworkMediaStorage extends \SmartSyncS3\Models\SMARL_CloudConfig {
    public array $uploads = [];
    public array $deletes = [];
    public bool $failUpload = false;
    public function __construct(int $blogId) {
        $this->url = 'https://cdn-site-' . $blogId . '.example.test';
        $this->useCdnForMedia = $this->mediaAutoDelete = true;
    }
    public function isConfigured(): bool { return true; }
    public function uploadObject(string $key, string $path): void {
        if ($this->failUpload) throw new RuntimeException('Expected fixture upload failure');
        $this->uploads[$key] = file_get_contents($path);
    }
    public function deleteObject(string $key): void { $this->deletes[] = $key; }
    public function getS3Client(string $region = ''): ?\Aws\S3\S3Client { throw new RuntimeException('Multisite tests must not construct a real storage client.'); }
}

final class SmartSyncNetworkGuardStorage extends \SmartSyncS3\Models\SMARL_CloudConfig {
    public array $objects = [];
    public int $listCalls = 0;
    public function __construct() {
        $this->bucket = 'network-fixture-only'; $this->iamKey = 'fixture'; $this->iamSecret = 'fixture';
        $this->url = 'cdn-network-fixture.example.test';
    }
    public function listObjects(): array { $this->listCalls++; return $this->objects; }
    public function getS3Client(string $region = ''): ?\Aws\S3\S3Client { throw new RuntimeException('Network guard tests must not construct a real storage client.'); }
    public function uploadObject(string $key, string $path): void { throw new RuntimeException('Network guard tests do not execute file transfers.'); }
    public function downloadObject(string $key, string $path): void { throw new RuntimeException('Network guard tests do not execute file transfers.'); }
}

add_filter('pre_http_request', static fn() => new WP_Error('smarl_fixture_http_blocked', 'External HTTP is blocked in this integration test.'), PHP_INT_MAX);

function smarl_network_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function smarl_network_context(): array {
    global $wpdb;
    return [get_current_blog_id(), $wpdb->prefix, $GLOBALS['_wp_switched_stack'], ms_is_switched()];
}
function smarl_network_schema(int $blogId): void {
    global $wpdb;
    $table = $wpdb->get_blog_prefix($blogId) . 'smarl_file_sync_queue';
    $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
    $byName = array_column($columns, null, 'Field');
    foreach (['id', 'run_id', 'path', 'relative_path', 'size', 'status', 'created_at', 'updated_at'] as $column) {
        smarl_network_assert(isset($byName[$column]), "Blog {$blogId} is missing queue column {$column}");
    }
    smarl_network_assert($byName['path']['Type'] === 'text' && $byName['relative_path']['Type'] === 'text', 'Queue paths must remain TEXT.');
    smarl_network_assert(strpos($byName['size']['Type'], 'bigint') === 0 && strpos($byName['size']['Type'], 'unsigned') === false && (string) $byName['size']['Default'] === '-1', 'Restore size must be signed with the unknown-length default.');
    $index = $wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name = 'run_status'", ARRAY_A);
    usort($index, static fn($first, $second) => (int) $first['Seq_in_index'] <=> (int) $second['Seq_in_index']);
    smarl_network_assert(array_column($index, 'Column_name') === ['run_id', 'status', 'id'], 'Run/status index columns are incorrect.');
    smarl_network_assert(get_blog_option($blogId, 'smarl_db_version') === SMARL_DB_VERSION, 'Per-blog schema version was not recorded.');
}

$stage = $args[0] ?? 'lifecycle';
global $wpdb;
$plugin = 'smartsync/aws-s3-smartsync-wp.php';

if ($stage === 'seed') {
    $id = get_current_blog_id();
    $names = ['smarl_settings', 'custom_url_option', 'smarl_sync_settings', 'smarl_sync_lock'];
    $snapshot = [];
    foreach ($names as $name) $snapshot[$name] = get_option($name, null);
    update_option('smarl_network_fixture_snapshot', $snapshot, false);
    update_option('smarl_settings', ['bucket' => 'network-fixture-' . $id]);
    update_option('custom_url_option', ['domain' => 'site-' . $id . '.example.test', 'records' => []]);
    $state = new SMARL_S3SyncSettings();
    $state->runId = 'network-fixture-' . $id;
    $state->s3SyncStatus = 'processing';
    $state->syncState = 'syncing';
    $state->s3SyncDirection = 'upload';
    $state->save();
    update_option('smarl_sync_lock', ['token' => 'network-owner-' . $id, 'expires' => time() + 900]);
    $table = $wpdb->prefix . 'smarl_file_sync_queue';
    $wpdb->insert($table, ['run_id' => 'network-fixture-' . $id, 'path' => ABSPATH . 'network-fixture.jpg', 'relative_path' => 'network-fixture.jpg', 'status' => 'pending']);
    foreach ([['batch_number' => 1, 'settings' => [], 'files' => [], 'direction' => 'upload', 'run_id' => $state->runId], [1, [], [], 'upload']] as $position => $jobArgs) {
        smarl_network_assert(as_schedule_single_action(time() + 3600, 'process_s3_sync_batch', $jobArgs, $position === 0 ? 'smartsync' : '') > 0, 'Could not seed a per-blog sync action.');
    }
    smarl_network_assert(as_schedule_single_action(time() + 3600, 'smarl_network_fixture_other', [], 'fixture') > 0, 'Could not seed the unrelated action.');
    WP_CLI::success('Seeded isolated network lifecycle state on blog ' . $id);
    return;
}

if ($stage === 'new-site-before' || $stage === 'new-site-after') {
    $id = (int) ($args[1] ?? 0);
    smarl_network_assert($id > 1, 'A newly created blog ID is required.');
    $table = $wpdb->get_blog_prefix($id) . 'smarl_file_sync_queue';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    if ($stage === 'new-site-before') {
        smarl_network_assert(!$exists && get_blog_option($id, 'smarl_db_version', null) === null, 'The pre-bootstrap baseline unexpectedly initialized SmartSync.');
        WP_CLI::success('New network site awaits its first plugin bootstrap, as designed.');
    } else {
        smarl_network_assert($exists, 'First network-site bootstrap did not create the SmartSync queue.');
        smarl_network_schema($id);
        WP_CLI::success('First network-site bootstrap creates the correct MySQL schema and version.');
    }
    return;
}

if ($stage === 'runtime') {
    $siteIds = array_map('intval', get_sites(['fields' => 'ids', 'number' => 0]));
    smarl_network_assert(count($siteIds) >= 2, 'Runtime switching needs two sites.');
    $core = null;
    foreach (($GLOBALS['wp_filter']['wp_delete_file']->callbacks ?? []) as $callbacks) {
        foreach ($callbacks as $callback) {
            $callable = $callback['function'] ?? null;
            if (is_array($callable) && ($callable[0] ?? null) instanceof SMARL_SmartSync) {
                $core = $callable[0];
                break 2;
            }
        }
    }
    smarl_network_assert($core instanceof SMARL_SmartSync, 'Could not find the registered core callbacks.');
    $property = new ReflectionProperty($core, 'config');
    $originalConfig = $property->getValue($core);
    $snapshots = [];
    foreach (array_slice($siteIds, 0, 2) as $id) {
        $snapshots[$id] = ['settings' => get_blog_option($id, 'smarl_settings', null), 'progress' => get_blog_option($id, 'smarl_sync_settings', null)];
        update_blog_option($id, 'smarl_settings', ['url' => 'https://cdn-site-' . $id . '.example.test', 'use_cdn_for_media' => true, 'use_cdn_for_assets' => $id === $siteIds[1]]);
        $progress = new SMARL_S3SyncSettings();
        $progress->runId = 'runtime-blog-' . $id;
        update_blog_option($id, 'smarl_sync_settings', $progress->toArray());
        switch_to_blog($id);
        try {
            $wpdb->insert($wpdb->prefix . 'smarl_file_sync_queue', ['run_id' => 'runtime-blog-' . $id, 'path' => ABSPATH . 'runtime-' . $id . '.jpg', 'relative_path' => 'runtime-' . $id . '.jpg']);
            smarl_network_assert(as_schedule_single_action(time() + 3600, 'smarl_network_runtime_other', ['blog' => $id], 'fixture') > 0, 'Runtime scheduler fixture could not be created.');
        } finally { restore_current_blog(); }
    }
    $property->setValue($core, \SmartSyncS3\Models\SMARL_CloudConfig::load());
    try {
        smarl_network_assert(strpos($core->getAttachmentUrl(wp_upload_dir()['baseurl'] . '/runtime.jpg'), 'https://cdn-site-' . $siteIds[0] . '.example.test/') === 0, 'Initial blog CDN configuration did not load.');
        switch_to_blog($siteIds[1]);
        try {
            smarl_network_assert(strpos($core->getAttachmentUrl(wp_upload_dir()['baseurl'] . '/runtime.jpg'), 'https://cdn-site-' . $siteIds[1] . '.example.test/wp-content/uploads/sites/' . $siteIds[1] . '/') === 0, 'Subdirectory-network upload URLs did not map to root-relative cloud keys.');
            smarl_network_assert(strpos(apply_filters('script_loader_src', site_url('/wp-includes/js/runtime.js'), 'fixture'), 'https://cdn-site-' . $siteIds[1] . '.example.test/wp-includes/js/runtime.js') === 0, 'The destination blog did not enable its own asset CDN filter.');
            $otherUrl = network_site_url('/wp-content/uploads/sites/' . $siteIds[2] . '/runtime.jpg');
            smarl_network_assert($core->getAttachmentUrl($otherUrl) === $otherUrl, 'A different subsite upload was mapped to the current provider.');
            $model = $property->getValue($core);
            foreach (['ajaxHandlers', 'awsConfigAjax'] as $field) {
                $handler = (new ReflectionProperty($core, $field))->getValue($core);
                smarl_network_assert((new ReflectionProperty($handler, 'appConfig'))->getValue($handler) === $model, 'A registered handler retained another blog configuration.');
                if ($field === 'ajaxHandlers') {
                    $state = (new ReflectionProperty($handler, 's3SyncSettings'))->getValue($handler);
                    smarl_network_assert($state->runId === 'runtime-blog-' . $siteIds[1], 'Registered runtime handler retained another blog progress.');
                    $registered = array_merge(...array_map('array_values', $GLOBALS['wp_filter']['wp_ajax_fetch_aws_analytics']->callbacks));
                    smarl_network_assert(in_array([$handler, 'fetchAwsAnalytics'], array_column($registered, 'function'), true), 'Refresh replaced the handler without updating its registered callback.');
                }
            }
            $rows = \SmartSyncS3\Helpers\SMARL_Helper::getFileSyncQueueData(10, 0, 'runtime-blog-' . $siteIds[1]);
            smarl_network_assert(count($rows) === 1 && $rows[0]['relative_path'] === 'runtime-' . $siteIds[1] . '.jpg', 'Switched queue queries used another blog table.');
            smarl_network_assert((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}actionscheduler_actions` WHERE hook='smarl_network_runtime_other' AND status='pending'") === 1, 'Switched scheduler queries used another blog table.');
        } finally { restore_current_blog(); }
        smarl_network_assert(strpos($core->getAttachmentUrl(wp_upload_dir()['baseurl'] . '/runtime.jpg'), 'https://cdn-site-' . $siteIds[0] . '.example.test/') === 0, 'Restoring blog context did not restore its CDN configuration.');
        WP_CLI::success('Switched-blog media/assets use the current site configuration and correct network upload keys.');
    } finally {
        foreach ($snapshots as $id => $snapshot) {
            switch_to_blog($id);
            try {
                $wpdb->delete($wpdb->prefix . 'smarl_file_sync_queue', ['run_id' => 'runtime-blog-' . $id]);
                as_unschedule_all_actions('smarl_network_runtime_other');
                foreach (['settings' => 'smarl_settings', 'progress' => 'smarl_sync_settings'] as $key => $name) {
                    if ($snapshot[$key] === null) delete_option($name);
                    else update_option($name, $snapshot[$key]);
                }
            } finally { restore_current_blog(); }
        }
        $core->switchBlogConfiguration(get_current_blog_id());
    }
    return;
}

if ($stage === 'media') {
    $siteIds = array_slice(array_map('intval', get_sites(['fields' => 'ids', 'number' => 0])), 0, 2);
    smarl_network_assert(count($siteIds) === 2, 'Media switching needs two sites.');
    $core = null;
    foreach (($GLOBALS['wp_filter']['wp_delete_file']->callbacks ?? []) as $callbacks) {
        foreach ($callbacks as $callback) {
            $callable = $callback['function'] ?? null;
            if (is_array($callable) && ($callable[0] ?? null) instanceof SMARL_SmartSync) { $core = $callable[0]; break 2; }
        }
    }
    smarl_network_assert($core instanceof SMARL_SmartSync, 'Could not find the registered core.');
    $models = [];
    foreach ($siteIds as $id) $models[$id] = new SmartSyncNetworkMediaStorage($id);
    $inject = static function (int $id) use ($core, $models): void {
        if (!isset($models[$id])) return;
        (new ReflectionProperty($core, 'config'))->setValue($core, $models[$id]);
        foreach (['awsConfigAjax', 'ajaxHandlers'] as $field) (new ReflectionProperty($core, $field))->getValue($core)->setConfiguration($models[$id]);
    };
    add_action('switch_blog', $inject, 20);
    $inject(get_current_blog_id());
    $attachments = [];
    $files = [];
    $urls = [];
    try {
        foreach ($siteIds as $id) {
            switch_to_blog($id);
            try {
                $uploads = wp_upload_dir();
                $file = $uploads['path'] . '/smarl-network-shared-id.jpg';
                file_put_contents($file, 'fixture bytes for blog ' . $id);
                $files[$id] = $file;
                $post = wp_insert_attachment(['import_id' => 990001, 'post_mime_type' => 'image/jpeg', 'post_title' => 'Network same-ID fixture'], $file, 0, true);
                smarl_network_assert(!is_wp_error($post) && $post === 990001, 'The attachment fixture must reuse the same ID on both blogs.');
                $attachments[$id] = $post;
                wp_update_attachment_metadata($post, ['file' => get_post_meta($post, '_wp_attached_file', true), 'width' => 100, 'height' => 100, 'sizes' => []]);
                $urls[$id] = $uploads['url'] . '/smarl-network-shared-id.jpg';
                if ($id === $siteIds[0]) smarl_network_assert($core->getAttachmentUrl($urls[$id], $post) === $urls[$id], 'Pending local URL should remain available until upload.');
            } finally { restore_current_blog(); }
        }
        $context = smarl_network_context();
        $core->flushAttachmentUploads();
        smarl_network_assert(smarl_network_context() === $context, 'Cross-blog shutdown flush did not restore its original context.');
        foreach ($siteIds as $id) {
            $key = \SmartSyncS3\Helpers\SMARL_Helper::relativeKeyForPath($files[$id]);
            smarl_network_assert($models[$id]->uploads === [$key => 'fixture bytes for blog ' . $id], 'Pending attachment data leaked across identical post IDs.');
            smarl_network_assert($models[$id]->deletes === [], 'Cross-blog local cleanup deleted a remote object.');
            smarl_network_assert(get_blog_option($id, 'smarl_settings', []) === [], 'Fake storage escaped into persisted blog configuration.');
        }
        smarl_network_assert(is_file($files[$siteIds[0]]) && !is_file($files[$siteIds[1]]), 'Request-local delivery flags leaked between blogs sharing a post ID.');
        WP_CLI::log('PASS switched-blog pending media and local cleanup remain isolated for identical attachment IDs');

        switch_to_blog($siteIds[1]);
        try {
            $post = $attachments[$siteIds[1]];
            $relative = wp_parse_url($urls[$siteIds[1]], PHP_URL_PATH);
            $content = '<p>Literal ' . $relative . '</p><img class="fixture-relative wp-image-' . $post . '" src="' . esc_attr($relative . '?v=1#ready') . '" srcset="' . esc_attr($relative . ' 1x, ' . $relative . ' 2x') . '"><a href="' . esc_attr($relative . '?download=1#file') . '">Download</a><img class="fixture-document" src="document-relative.jpg"><img class="fixture-external" src="//other.example.test/image.jpg"><img class="fixture-data" src="data:image/gif;base64,R0lGODlhAQABAIAAAAUEBA==">';
            $rendered = apply_filters('the_content', $content);
            $tags = new WP_HTML_Tag_Processor($rendered);
            smarl_network_assert($tags->next_tag(['class_name' => 'fixture-relative']), 'Relative image fixture disappeared.');
            smarl_network_assert(strpos($tags->get_attribute('src'), 'https://cdn-site-' . $siteIds[1] . '.example.test/') === 0 && str_ends_with($tags->get_attribute('src'), '?v=1#ready'), 'Root-relative image src did not preserve safe CDN path/query/fragment.');
            smarl_network_assert(substr_count($tags->get_attribute('srcset'), 'https://cdn-site-' . $siteIds[1] . '.example.test/') === 2, 'Root-relative srcset candidates were not rewritten.');
            smarl_network_assert($tags->next_tag('A') && strpos($tags->get_attribute('href'), 'https://cdn-site-' . $siteIds[1] . '.example.test/') === 0 && str_ends_with($tags->get_attribute('href'), '?download=1#file'), 'Root-relative attachment link was not rewritten safely.');
            foreach (['fixture-document' => 'document-relative.jpg', 'fixture-external' => '//other.example.test/image.jpg', 'fixture-data' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAUEBA=='] as $class => $source) {
                smarl_network_assert($tags->next_tag(['class_name' => $class]) && $tags->get_attribute('src') === $source, 'Content rewriting changed a document-relative, external or data URL.');
            }
            file_put_contents($files[$siteIds[1]], 'retry fixture');
            $models[$siteIds[1]]->failUpload = true;
            $core->queueAttachmentUpload(['file' => get_post_meta($post, '_wp_attached_file', true), 'retry' => true], $post);
            $core->flushAttachmentUploads();
            $failed = $core->rewriteContentImageTag('<img src="' . esc_attr($relative . '?v=2#local') . '">', '', $post);
            $failedTag = new WP_HTML_Tag_Processor($failed);
            smarl_network_assert($failedTag->next_tag('IMG') && $failedTag->get_attribute('src') === $relative . '?v=2#local' && is_file($files[$siteIds[1]]), 'Failed root-relative media did not preserve local delivery.');
        } finally { restore_current_blog(); }
        WP_CLI::log('PASS root-relative stored media rewrites safely after offload and retains local delivery after a failed retry');
    } finally {
        foreach ($attachments as $id => $post) {
            switch_to_blog($id);
            try { wp_delete_attachment($post, true); }
            finally { restore_current_blog(); }
        }
        foreach ($files as $file) if (is_file($file)) @unlink($file);
        remove_action('switch_blog', $inject, 20);
        $core->switchBlogConfiguration(get_current_blog_id());
    }
    WP_CLI::success('2 MySQL multisite media/runtime cases passed; all storage calls were mocked.');
    return;
}

if ($stage === 'security') {
    smarl_network_assert(method_exists(\SmartSyncS3\Helpers\SMARL_Helper::class, 'isCurrentSiteMediaKey'), 'Multisite media scope helpers are required for this stage.');
    if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
    add_filter('wp_die_ajax_handler', static fn() => static function ($message = '', $title = '', $args = []) { Fiber::suspend(['message' => $message, 'args' => $args]); }, PHP_INT_MAX);
    $siteIds = array_map('intval', get_sites(['fields' => 'ids', 'number' => 0]));
    smarl_network_assert(count($siteIds) >= 3, 'Network security tests need the main site and two subsites.');
    $originalUser = get_current_user_id();
    $user = wp_create_user('smarl_site_admin_' . wp_generate_password(8, false), wp_generate_password(32), 'fixture-' . wp_generate_password(8, false) . '@example.test');
    smarl_network_assert(!is_wp_error($user), 'Could not create the isolated site administrator.');
    add_user_to_blog($siteIds[1], $user, 'administrator');
    switch_to_blog($siteIds[1]);
    $options = [];
    foreach (['smarl_settings', 'smarl_sync_settings', 'smarl_sync_lock'] as $name) $options[$name] = get_option($name, null);
    $queue = $wpdb->get_results("SELECT * FROM `{$wpdb->prefix}smarl_file_sync_queue`", ARRAY_A);
    $storage = new SmartSyncNetworkGuardStorage();
    $storage->save();
    \SmartSyncS3\Ajax\SMARL_AjaxHandler::resetSyncState(new SMARL_S3SyncSettings());
    delete_option('smarl_sync_lock');
    $handler = new \SmartSyncS3\Ajax\SMARL_AjaxHandler($storage);
    $capture = static function (string $type) use ($handler): array {
        $_POST = $_REQUEST = ['type' => $type, 'nonce' => wp_create_nonce('smarl_nonce')];
        http_response_code(200);
        ob_start();
        try {
            $fiber = new Fiber(static fn() => $handler->syncAwsToLocal());
            $fiber->start();
            $body = ob_get_contents();
        } finally { ob_end_clean(); }
        return ['json' => json_decode($body, true), 'status' => http_response_code()];
    };
    try {
        wp_set_current_user($user);
        smarl_network_assert(current_user_can('manage_options') && !current_user_can('manage_network_options'), 'Fixture must represent a site administrator, not a network administrator.');
        $storage->objects = [['key' => 'wp-includes/js/network-escape.js', 'size' => 1]];
        $denied = $capture('assets');
        smarl_network_assert(($denied['json']['success'] ?? true) === false && $denied['status'] === 403 && $storage->listCalls === 0, 'A site administrator could start a shared asset restore.');
        WP_CLI::log('PASS actual site administrator cannot restore shared assets or call the provider before network authorization');

        $uploads = wp_upload_dir();
        $ownKey = \SmartSyncS3\Helpers\SMARL_Helper::relativeKeyForPath($uploads['basedir'] . '/scope-own.jpg');
        $otherKey = 'wp-content/uploads/sites/' . $siteIds[2] . '/scope-other.jpg';
        $storage->objects = [['key' => $ownKey, 'size' => 1], ['key' => $otherKey, 'size' => 1], ['key' => 'wp-content/themes/scope-shared.jpg', 'size' => 1], ['key' => substr($ownKey, 0, -4) . '.svg', 'size' => 1]];
        $allowed = $capture('media');
        smarl_network_assert(($allowed['json']['success'] ?? false) === true && $allowed['json']['data']['total_files'] === 1, 'A site media restore included another site or shared files.');
        $rows = $wpdb->get_results("SELECT relative_path FROM `{$wpdb->prefix}smarl_file_sync_queue`", ARRAY_A);
        smarl_network_assert(array_column($rows, 'relative_path') === [$ownKey], 'A site media restore queued a foreign object key.');
        smarl_network_assert(\SmartSyncS3\Helpers\SMARL_Helper::isCurrentSiteMediaKey($ownKey) && !\SmartSyncS3\Helpers\SMARL_Helper::isCurrentSiteMediaKey($otherKey), 'Multisite scope validation disagrees with the durable queue.');
        WP_CLI::log('PASS actual site media restore queues only its upload directory while preserving WordPress-root-relative keys');
    } finally {
        wp_set_current_user($originalUser);
        as_unschedule_all_actions('process_s3_sync_batch');
        $wpdb->query("DELETE FROM `{$wpdb->prefix}smarl_file_sync_queue`");
        foreach ($queue as $row) $wpdb->insert($wpdb->prefix . 'smarl_file_sync_queue', $row);
        foreach ($options as $name => $value) { if ($value === null) delete_option($name); else update_option($name, $value); }
        restore_current_blog();
        require_once ABSPATH . 'wp-admin/includes/ms.php';
        wpmu_delete_user($user);
    }
    WP_CLI::success('2 MySQL multisite authorization/scope cases passed; no storage transfer ran.');
    return;
}

smarl_network_assert($stage === 'lifecycle', 'Unknown multisite test stage.');
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$siteIds = array_map('intval', get_sites(['fields' => 'ids', 'number' => 0]));
smarl_network_assert(count($siteIds) >= 2 && is_plugin_active_for_network($plugin), 'Network activation and a second site are required.');
$before = [];
foreach ($siteIds as $id) {
    smarl_network_schema($id);
    $before[$id] = ['settings' => get_blog_option($id, 'smarl_settings'), 'domain' => get_blog_option($id, 'custom_url_option'), 'lock' => get_blog_option($id, 'smarl_sync_lock'), 'rows' => $wpdb->get_results('SELECT * FROM `' . $wpdb->get_blog_prefix($id) . 'smarl_file_sync_queue` ORDER BY id', ARRAY_A)];
    smarl_network_assert(is_array(get_blog_option($id, 'smarl_network_fixture_snapshot')), 'Each site needs its own seed-stage bootstrap.');
}

try {
    switch_to_blog($siteIds[1]);
    try {
        $context = smarl_network_context();
        SMARL_SmartSync::activateRegisterHook(true);
        smarl_network_assert(smarl_network_context() === $context, 'Network activation did not restore nested blog context.');
    } finally { restore_current_blog(); }
    foreach ($siteIds as $id) {
        smarl_network_schema($id);
        smarl_network_assert(get_blog_option($id, 'smarl_settings') === $before[$id]['settings'], 'Repeated network activation replaced configuration.');
        smarl_network_assert($wpdb->get_results('SELECT * FROM `' . $wpdb->get_blog_prefix($id) . 'smarl_file_sync_queue` ORDER BY id', ARRAY_A) === $before[$id]['rows'], 'Repeated activation altered queued work.');
    }
    WP_CLI::log('PASS MySQL network activation creates every blog schema and repeat activation preserves data/context');

    switch_to_blog($siteIds[1]);
    try {
        $context = smarl_network_context();
        deactivate_plugins($plugin, false, true);
        smarl_network_assert(smarl_network_context() === $context, 'Network deactivation did not restore nested blog context.');
    } finally { restore_current_blog(); }
    smarl_network_assert(!is_plugin_active_for_network($plugin), 'Network deactivation did not update activation state.');
    foreach ($siteIds as $id) {
        $prefix = $wpdb->get_blog_prefix($id);
        smarl_network_assert(get_blog_option($id, 'smarl_settings') === $before[$id]['settings'] && get_blog_option($id, 'custom_url_option') === $before[$id]['domain'], 'Network deactivation erased per-blog configuration.');
        smarl_network_assert(get_blog_option($id, 'smarl_sync_lock') === $before[$id]['lock'], 'Network deactivation released another worker lease.');
        smarl_network_assert(get_blog_option($id, 'smarl_sync_settings') === (new SMARL_S3SyncSettings())->toArray(), 'Network deactivation did not reset per-blog progress.');
        smarl_network_assert((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$prefix}actionscheduler_actions` WHERE hook='process_s3_sync_batch' AND status IN ('pending','in-progress')") === 0, 'Per-blog sync actions survived network deactivation.');
        smarl_network_assert((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$prefix}actionscheduler_actions` WHERE hook='smarl_network_fixture_other' AND status='pending'") > 0, 'Network deactivation canceled an unrelated action.');
        smarl_network_assert($wpdb->get_results("SELECT * FROM `{$prefix}smarl_file_sync_queue` ORDER BY id", ARRAY_A) === $before[$id]['rows'], 'Network deactivation removed queued audit records.');
    }
    WP_CLI::log('PASS actual network deactivation cancels both job groups per blog while retaining settings, rows, leases and unrelated jobs');

    foreach (['activation', 'deactivation'] as $operation) {
        $hook = $operation === 'activation' ? 'pre_update_option_smarl_db_version' : 'pre_update_option_smarl_sync_settings';
        $throw = static function ($value) use ($siteIds) {
            if (get_current_blog_id() === $siteIds[0]) throw new RuntimeException('Expected isolated lifecycle failure');
            return $value;
        };
        if ($operation === 'activation') delete_blog_option($siteIds[0], 'smarl_db_version');
        else update_blog_option($siteIds[0], 'smarl_sync_settings', ['s3SyncStatus' => 'processing', 'runId' => 'exception-fixture']);
        switch_to_blog($siteIds[1]);
        $context = smarl_network_context();
        add_filter($hook, $throw);
        $caught = false;
        try {
            if ($operation === 'activation') SMARL_SmartSync::activateRegisterHook(true);
            else SMARL_SmartSync::deActivateHook(true);
        } catch (RuntimeException $error) { $caught = $error->getMessage() === 'Expected isolated lifecycle failure'; }
        finally { remove_filter($hook, $throw); }
        smarl_network_assert($caught && smarl_network_context() === $context, 'Lifecycle exception did not restore nested blog context.');
        restore_current_blog();
    }
    WP_CLI::log('PASS network lifecycle exceptions restore blog ID, database prefix and the complete switch stack');
} finally {
    foreach ($siteIds as $id) {
        switch_to_blog($id);
        try {
            as_unschedule_all_actions('process_s3_sync_batch');
            as_unschedule_all_actions('smarl_network_fixture_other');
            $wpdb->delete($wpdb->prefix . 'smarl_file_sync_queue', ['run_id' => 'network-fixture-' . $id]);
            $snapshot = get_option('smarl_network_fixture_snapshot', []);
            foreach ($snapshot as $name => $value) {
                if ($value === null) delete_option($name);
                else update_option($name, $value);
            }
            delete_option('smarl_network_fixture_snapshot');
        } finally { restore_current_blog(); }
    }
    $activation = activate_plugin($plugin, '', true);
    smarl_network_assert(!is_wp_error($activation), 'Could not restore network activation.');
}
WP_CLI::success('3 MySQL multisite lifecycle cases passed; network activation restored.');
