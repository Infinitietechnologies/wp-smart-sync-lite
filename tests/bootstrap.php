<?php
/** Isolated WordPress doubles. No installed site or cloud credentials are used. */
declare(strict_types=1);

define('SMARTSYNC_TEST_ROOT', rtrim(str_replace('\\', '/', realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()), '/') . '/smartsync-tests-' . bin2hex(random_bytes(6)));
define('ABSPATH', SMARTSYNC_TEST_ROOT . '/');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
define('ARRAY_A', 'ARRAY_A');
define('OBJECT', 'OBJECT');
define('SMARL_ASSET_VERSION_CODE', 'test');
define('SMARL_TEXT_DOMAIN', 'smartsync-lite-media-offloader-and-assets-cdn');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
require dirname(__DIR__) . '/vendor/autoload.php';

final class SmartSyncTestDatabase {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public string $last_error = '';
    public int $insert_id = 0;
    public PDO $pdo;
    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE wp_smarl_file_sync_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, path TEXT, relative_path TEXT, run_id TEXT DEFAULT \'\', status TEXT DEFAULT \'pending\', size INTEGER DEFAULT -1, created_at TEXT, updated_at TEXT)');
        $this->pdo->exec('CREATE TABLE wp_smarl_upload_history (target_hash TEXT NOT NULL, key_hash TEXT NOT NULL, relative_path TEXT NOT NULL, content_hash TEXT NOT NULL, size INTEGER NOT NULL, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (target_hash, key_hash))');
        $this->pdo->exec('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)');
    }
    public function prepare(string $sql, ...$args): string {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback('/%[sdf]/', function ($m) use (&$i, $args) {
            $value = $args[$i++] ?? null;
            return $m[0] === '%s' ? $this->pdo->quote((string) $value) : (string) (0 + $value);
        }, $sql);
    }
    public function query(string $sql): int|false {
        try { return $this->pdo->exec($sql); } catch (Throwable $e) { $this->last_error = $e->getMessage(); return false; }
    }
    public function get_results(string $sql, string $output = OBJECT): array {
        $this->last_error = '';
        return $this->pdo->query($sql)->fetchAll($output === ARRAY_A ? PDO::FETCH_ASSOC : PDO::FETCH_OBJ);
    }
    public function get_var(string $sql): mixed {
        $v = $this->pdo->query($sql)->fetchColumn();
        if (is_callable($GLOBALS['smartsync_test_sql_read_hook'] ?? null)) ($GLOBALS['smartsync_test_sql_read_hook'])($sql, $v);
        return $v === false ? null : $v;
    }
    public function get_row(string $sql, string $output = OBJECT): mixed { return $this->get_results($sql, $output)[0] ?? null; }
    public function insert(string $table, array $data, mixed $format = null): int|false {
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')';
        try { $s = $this->pdo->prepare($sql); $s->execute(array_values($data)); $this->insert_id = (int) $this->pdo->lastInsertId(); return $s->rowCount(); }
        catch (Throwable $e) { $this->last_error = $e->getMessage(); return false; }
    }
    public function update(string $table, array $data, array $where, mixed $format = null, mixed $whereFormat = null): int|false {
        $set = implode(',', array_map(fn($k) => $k . '=?', array_keys($data)));
        $condition = implode(' AND ', array_map(fn($k) => $k . '=?', array_keys($where)));
        $s = $this->pdo->prepare('UPDATE ' . $table . ' SET ' . $set . ' WHERE ' . $condition);
        $s->execute(array_merge(array_values($data), array_values($where))); return $s->rowCount();
    }
    public function delete(string $table, array $where, mixed $format = null): int|false {
        $condition = implode(' AND ', array_map(fn($k) => $k . '=?', array_keys($where)));
        $s = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE ' . $condition);
        $s->execute(array_values($where)); return $s->rowCount();
    }
    public function get_charset_collate(): string { return ''; }
}

function smartsync_test(string $name, callable $test): void { $GLOBALS['smartsync_tests'][] = [$name, $test]; }
function smartsync_assert_same(mixed $expected, mixed $actual, string $message = ''): void {
    if ($expected !== $actual) { throw new RuntimeException(($message ?: 'Values differ') . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
}
function smartsync_assert_true(bool $condition, string $message = ''): void { if (!$condition) { throw new RuntimeException($message ?: 'Expected true'); } }
function smartsync_assert_throws(callable $callable, string $class = Throwable::class): Throwable {
    try { $callable(); } catch (Throwable $e) { if ($e instanceof $class) { return $e; } throw $e; }
    throw new RuntimeException('Expected exception ' . $class);
}
/** Fibers model wp_die/exit without a catchable exception in application code. */
function smartsync_capture_json(callable $callable): array {
    $fiber = new Fiber($callable);
    $response = $fiber->start();
    if (!is_array($response) || !array_key_exists('success', $response)) { throw new RuntimeException('Handler did not emit a JSON response'); }
    return $response;
}
/** Close directory handles before removing children, including on Windows. */
function smartsync_test_remove_entry(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') { smartsync_test_remove_entry($path . '/' . $name); }
        }
        for ($attempt = 0; $attempt < 10; $attempt++) {
            if (@rmdir($path)) { return; }
            // Windows can retain a just-deleted file until its final handle closes.
            gc_collect_cycles();
            usleep(20000);
            clearstatcache(true, $path);
        }
        throw new RuntimeException('Unable to remove test directory: ' . $path);
    }
    if (!@unlink($path)) { throw new RuntimeException('Unable to remove test file: ' . $path); }
}
function smartsync_test_reset(): void {
    foreach (['smartsync_test_options', 'smartsync_test_hooks', 'smartsync_test_transients', 'smartsync_test_cache', 'smartsync_test_jobs', 'smartsync_test_meta', 'smartsync_test_posts', 'smartsync_test_assets', 'smartsync_test_http_requests'] as $name) { $GLOBALS[$name] = []; }
    $GLOBALS['smartsync_test_http'] = null;
    $GLOBALS['smartsync_test_cache_reads'] = [];
    $GLOBALS['smartsync_test_meta_write_failures'] = [];
    $GLOBALS['smartsync_test_option_read_hook'] = null;
    $GLOBALS['smartsync_test_sql_read_hook'] = null;
    gc_collect_cycles();
    if (is_dir(ABSPATH)) {
        $resolved = str_replace('\\', '/', (string) realpath(ABSPATH));
        if ($resolved !== SMARTSYNC_TEST_ROOT || !preg_match('~/smartsync-tests-[a-f0-9]{12}$~D', $resolved)) { throw new RuntimeException('Refusing cleanup outside the isolated test root'); }
        foreach (scandir(ABSPATH) as $name) {
            if ($name !== '.' && $name !== '..') { smartsync_test_remove_entry(ABSPATH . $name); }
        }
    }
    $GLOBALS['wpdb'] = new SmartSyncTestDatabase();
    $GLOBALS['smartsync_test_can_manage'] = true;
    $GLOBALS['smartsync_test_blog_id'] = 1;
    $GLOBALS['smartsync_test_multisite'] = false;
    $GLOBALS['smartsync_test_can_manage_network'] = false;
    unset($GLOBALS['smartsync_test_upload_dir'], $GLOBALS['smartsync_test_allowed_mimes']);
    unset($GLOBALS['smartsync_test_network_url']);
    $GLOBALS['smartsync_test_valid_nonce'] = true;
    $_GET = $_POST = $_REQUEST = $_FILES = [];
    if (!is_dir(ABSPATH)) { mkdir(ABSPATH, 0777, true); }
    wp_mkdir_p(WP_CONTENT_DIR . '/uploads/2026/10');
}

function maybe_serialize(mixed $v): string { return is_array($v) || is_object($v) ? serialize($v) : (string) $v; }
function maybe_unserialize(mixed $v): mixed { if (!is_string($v)) { return $v; } $r = @unserialize($v); return $r !== false || $v === 'b:0;' ? $r : $v; }
function get_option(string $name, mixed $default = false): mixed {
    $alloptions = $GLOBALS['smartsync_test_cache']['options']['alloptions'] ?? [];
    if (!empty($GLOBALS['smartsync_test_cache_reads']['alloptions']) && array_key_exists($name, $alloptions)) return maybe_unserialize($alloptions[$name]);
    if (!empty($GLOBALS['smartsync_test_cache_reads'][$name]) && array_key_exists($name, $GLOBALS['smartsync_test_cache']['options'] ?? [])) {
        return $GLOBALS['smartsync_test_cache']['options'][$name];
    }
    $db = $GLOBALS['wpdb']; $v = $db->get_var($db->prepare('SELECT option_value FROM wp_options WHERE option_name=%s', $name));
    $result = $v === null ? $default : maybe_unserialize($v);
    if (is_callable($GLOBALS['smartsync_test_option_read_hook'] ?? null)) ($GLOBALS['smartsync_test_option_read_hook'])($name, $result);
    return $result;
}
function add_option(string $name, mixed $value = '', mixed $deprecated = '', mixed $autoload = null): bool {
    if (get_option($name, null) !== null) { return false; }
    $ok = $GLOBALS['wpdb']->insert('wp_options', ['option_name' => $name, 'option_value' => maybe_serialize($value), 'autoload' => 'no']);
    if ($ok !== false) { $GLOBALS['smartsync_test_options'][$name] = $value; }
    return $ok !== false;
}
function update_option(string $name, mixed $value, mixed $autoload = null): bool {
    $GLOBALS['smartsync_test_options'][$name] = $value;
    if (get_option($name, null) === null) { return add_option($name, $value); }
    return $GLOBALS['wpdb']->update('wp_options', ['option_value' => maybe_serialize($value)], ['option_name' => $name]) !== false;
}
function delete_option(string $name): bool { unset($GLOBALS['smartsync_test_options'][$name]); return (bool) $GLOBALS['wpdb']->delete('wp_options', ['option_name' => $name]); }
function set_transient(string $name, mixed $value, int $expiration = 0): bool { $GLOBALS['smartsync_test_transients'][$name] = $value; return true; }
function get_transient(string $name): mixed { return $GLOBALS['smartsync_test_transients'][$name] ?? false; }
function delete_transient(string $name): bool { unset($GLOBALS['smartsync_test_transients'][$name]); return true; }
function wp_cache_get(string $key, string $group = '', mixed ...$args): mixed { return $GLOBALS['smartsync_test_cache'][$group][$key] ?? false; }
function wp_cache_set(string $key, mixed $value, string $group = '', int $expire = 0): bool { $GLOBALS['smartsync_test_cache'][$group][$key] = $value; return true; }
function wp_cache_delete(string $key, string $group = ''): bool { unset($GLOBALS['smartsync_test_cache'][$group][$key]); return true; }
function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool { $GLOBALS['smartsync_test_hooks'][$hook][$priority][] = [$callback, $args]; return true; }
function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool { return add_filter($hook, $callback, $priority, $args); }
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
    $priorities = $GLOBALS['smartsync_test_hooks'][$hook] ?? []; ksort($priorities);
    foreach ($priorities as $callbacks) { foreach ($callbacks as [$callback, $accepted]) { $value = $callback(...array_slice([$value, ...$args], 0, $accepted)); } }
    return $value;
}
function do_action(string $hook, mixed ...$args): void {
    $priorities = $GLOBALS['smartsync_test_hooks'][$hook] ?? []; ksort($priorities);
    foreach ($priorities as $callbacks) { foreach ($callbacks as [$callback, $accepted]) { $callback(...array_slice($args, 0, $accepted)); } }
}
function remove_filter(string $hook, callable $callback, int $priority = 10): bool { foreach ($GLOBALS['smartsync_test_hooks'][$hook][$priority] ?? [] as $i => [$registered]) { if ($registered === $callback) { unset($GLOBALS['smartsync_test_hooks'][$hook][$priority][$i]); } } return true; }
function current_user_can(string $capability): bool { return $capability === 'manage_network_options' ? ($GLOBALS['smartsync_test_can_manage_network'] ?? false) : $GLOBALS['smartsync_test_can_manage']; }
function get_current_user_id(): int { return 1; }
function get_current_blog_id(): int { return $GLOBALS['smartsync_test_blog_id'] ?? 1; }
function is_multisite(): bool { return $GLOBALS['smartsync_test_multisite'] ?? false; }
function is_main_site(): bool { return get_current_blog_id() === 1; }
function check_ajax_referer(string $action, mixed $queryArg = false, bool $stop = true): int|false { if (!$GLOBALS['smartsync_test_valid_nonce']) { wp_send_json_error('Invalid nonce', 403); } return 1; }
function wp_send_json_success(mixed $data = null, ?int $statusCode = null, int $flags = 0): void { Fiber::suspend(['success' => true, 'data' => $data, 'status' => $statusCode ?? 200]); }
function wp_send_json_error(mixed $data = null, ?int $statusCode = null, int $flags = 0): void { Fiber::suspend(['success' => false, 'data' => $data, 'status' => $statusCode ?? 200]); }
function nocache_headers(): void { $GLOBALS['smartsync_test_nocache'] = true; }
function wp_die(mixed $message = '', mixed ...$args): void { wp_send_json_error($message, 403); }
function sanitize_text_field(mixed $v): string { return is_scalar($v) ? trim(strip_tags((string) $v)) : ''; }
function sanitize_key(string $v): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($v)); }
function sanitize_file_name(string $v): string { return basename(str_replace('\\', '/', $v)); }
function wp_unslash(mixed $v): mixed { return is_array($v) ? array_map('wp_unslash', $v) : (is_string($v) ? stripslashes($v) : $v); }
function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
function wp_normalize_path(string $path): string { return str_replace('\\', '/', $path); }
function trailingslashit(string $v): string { return rtrim($v, '/\\') . '/'; }
function untrailingslashit(string $v): string { return rtrim($v, '/\\'); }
function esc_html(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function esc_attr(mixed $v): string { return esc_html($v); }
function __(string $v, string $domain = ''): string { return $v; }
function esc_html__(string $v, string $domain = ''): string { return esc_html($v); }
function esc_attr__(string $v, string $domain = ''): string { return esc_attr($v); }
function esc_html_e(string $v, string $domain = ''): void { echo esc_html($v); }
function esc_attr_e(string $v, string $domain = ''): void { echo esc_attr($v); }
function esc_url_raw(mixed $v, mixed $protocols = null): string { return is_string($v) && !preg_match('/[\x00-\x20]/', $v) ? $v : ''; }
function sanitize_url(mixed $v, mixed $protocols = null): string { return esc_url_raw($v); }
function esc_url(mixed $v): string { return esc_attr(esc_url_raw($v)); }
function wp_kses_post(mixed $v): string { return strip_tags((string) $v, '<b><strong><span><p><div><a><svg><path><img><ul><li>'); }
function wp_kses(mixed $v, array $allowed, mixed ...$args): string { return strip_tags((string) $v, '<' . implode('><', array_keys($allowed)) . '>'); }
function wp_strip_all_tags(mixed $v): string { return strip_tags((string) $v); }
function wp_json_encode(mixed $v, int $flags = 0, int $depth = 512): string|false { return json_encode($v, $flags, $depth); }
function wp_generate_uuid4(): string { return bin2hex(random_bytes(16)); }
function wp_generate_password(int $length = 12, bool $special = true, bool $extra = false): string { return substr(bin2hex(random_bytes($length)), 0, $length); }
function current_time(string $type, bool $gmt = false): string|int { return $type === 'mysql' ? gmdate('Y-m-d H:i:s') : time(); }
function wp_mkdir_p(string $path): bool { return is_dir($path) || mkdir($path, 0777, true); }
function wp_upload_dir(mixed ...$args): array { return array_replace(['basedir' => WP_CONTENT_DIR . '/uploads', 'baseurl' => 'https://example.test/blog/wp-content/uploads', 'path' => WP_CONTENT_DIR . '/uploads/2026/10', 'url' => 'https://example.test/blog/wp-content/uploads/2026/10', 'error' => false], $GLOBALS['smartsync_test_upload_dir'] ?? []); }
function get_allowed_mime_types(mixed $user = null): array { return $GLOBALS['smartsync_test_allowed_mimes'] ?? ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf']; }
function wp_check_filetype(string $filename, ?array $mimes = null): array {
    foreach ($mimes ?? get_allowed_mime_types() as $extensions => $mime) {
        if (preg_match('~\.(' . $extensions . ')$~i', $filename, $match)) { return ['ext' => $match[1], 'type' => $mime]; }
    }
    return ['ext' => false, 'type' => false];
}
function site_url(string $path = '', mixed ...$args): string { return 'https://example.test/blog' . ($path ? '/' . ltrim($path, '/') : ''); }
function home_url(string $path = '', mixed ...$args): string { return site_url($path); }
function network_site_url(string $path = '', mixed ...$args): string { return ($GLOBALS['smartsync_test_network_url'] ?? site_url()) . ($path ? '/' . ltrim($path, '/') : ''); }
function admin_url(string $path = '', mixed ...$args): string { return site_url('wp-admin/' . $path); }
function content_url(string $path = ''): string { return site_url('wp-content/' . ltrim($path, '/')); }
function plugin_dir_path(string $file): string { return trailingslashit(dirname($file)); }
function plugin_dir_url(string $file): string { return site_url('wp-content/plugins/smartsync/'); }
function plugin_basename(string $file): string { return 'smartsync/' . basename($file); }
function get_post(int $id): mixed { return $GLOBALS['smartsync_test_posts'][$id] ?? (object) ['ID' => $id, 'post_type' => 'attachment']; }
function get_posts(array $args = []): array {
    $ids = array_keys(array_filter($GLOBALS['smartsync_test_meta'], static fn($meta) => array_key_exists($args['meta_key'] ?? '', $meta)));
    sort($ids, SORT_NUMERIC);
    $limit = (int) ($args['posts_per_page'] ?? 100);
    return array_slice($ids, max(0, (int) ($args['paged'] ?? 1) - 1) * $limit, $limit);
}
function get_attached_file(int $id, bool $unfiltered = false): string { $v = get_post_meta($id, '_wp_attached_file', true); return str_starts_with($v, '/') || preg_match('/^[A-Za-z]:/', $v) ? $v : wp_upload_dir()['basedir'] . '/' . $v; }
function get_post_meta(int $id, string $key = '', bool $single = false): mixed { return $GLOBALS['smartsync_test_meta'][$id][$key] ?? ($single ? '' : []); }
function update_post_meta(int $id, string $key, mixed $value, mixed ...$args): bool { if (!empty($GLOBALS['smartsync_test_meta_write_failures'][$id][$key])) return false; $GLOBALS['smartsync_test_meta'][$id][$key] = $value; return true; }
function delete_post_meta(int $id, string $key, mixed ...$args): bool { unset($GLOBALS['smartsync_test_meta'][$id][$key]); return true; }
function wp_get_attachment_metadata(int $id, bool $unfiltered = false): mixed { return get_post_meta($id, '_wp_attachment_metadata', true); }
function wp_delete_file(string $file): bool { $path = apply_filters('wp_delete_file', $file); return $path ? @unlink($path) : false; }
function wp_create_nonce(string $action): string { return 'test-nonce'; }
function determine_locale(): string { return 'en_US'; }
function load_plugin_textdomain(mixed ...$args): bool { return true; }
function wp_enqueue_style(string $handle, mixed ...$args): void { $GLOBALS['smartsync_test_assets']['styles'][$handle] = $args; }
function wp_enqueue_script(string $handle, mixed ...$args): void { $GLOBALS['smartsync_test_assets']['scripts'][$handle] = $args; }
function wp_localize_script(string $handle, string $name, array $data): bool { $GLOBALS['smartsync_test_assets']['localized'][$name] = $data; return true; }
function is_admin(): bool { return true; }
function get_current_screen(): object { return (object) ['id' => 'toplevel_page_smarl-smart-sync']; }
function selected(mixed $a, mixed $b = true, bool $display = true): string { $v = (string) $a === (string) $b ? ' selected="selected"' : ''; if ($display) { echo $v; } return $v; }
function checked(mixed $a, mixed $b = true, bool $display = true): string { $v = $a == $b ? ' checked="checked"' : ''; if ($display) { echo $v; } return $v; }
function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10): int { $GLOBALS['smartsync_test_jobs'][] = compact('timestamp', 'hook', 'args', 'group'); return count($GLOBALS['smartsync_test_jobs']); }
function as_unschedule_all_actions(string $hook, mixed $args = null, string $group = ''): void { $GLOBALS['smartsync_test_jobs'] = array_values(array_filter($GLOBALS['smartsync_test_jobs'], fn($job) => $job['hook'] !== $hook || ($group && $job['group'] !== $group))); }
function as_has_scheduled_action(string $hook, mixed $args = null, string $group = ''): bool { return (bool) $GLOBALS['smartsync_test_jobs']; }
final class WP_Error { public function __construct(public string $code = '', public string $message = '') {} public function get_error_message(): string { return $this->message; } public function get_error_code(): string { return $this->code; } }
function is_wp_error(mixed $v): bool { return $v instanceof WP_Error; }
function wp_remote_request(string $url, array $args = []): mixed { $GLOBALS['smartsync_test_http_requests'][] = [$url, $args]; $handler = $GLOBALS['smartsync_test_http']; if (!$handler) { throw new RuntimeException('Unmocked HTTP request blocked: ' . parse_url($url, PHP_URL_HOST)); } return $handler($url, $args); }
function wp_safe_remote_request(string $url, array $args = []): mixed { return wp_remote_request($url, $args); }
function wp_remote_get(string $url, array $args = []): mixed { return wp_remote_request($url, $args + ['method' => 'GET']); }
function wp_safe_remote_get(string $url, array $args = []): mixed { return wp_remote_get($url, $args); }
function wp_remote_retrieve_response_code(mixed $response): int { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body(mixed $response): string { return (string) ($response['body'] ?? ''); }
function wp_remote_retrieve_header(mixed $response, string $header): mixed { return $response['headers'][$header] ?? ''; }
function wp_http_validate_url(string $url): string|false { return filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : false; }
smartsync_test_reset();
