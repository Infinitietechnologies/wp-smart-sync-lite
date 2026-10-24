<?php
/** Merge Xdebug execution evidence into a named-function inventory; no application hooks run. */
declare(strict_types=1);
$root = dirname(__DIR__);
$input = $argv[1] ?? '';
$output = $argv[2] ?? (__DIR__ . '/audit/function-coverage.md');
if (!is_dir($input)) throw new RuntimeException('Usage: php tests/coverage-report.php COVERAGE_DIRECTORY [REPORT_PATH]');
define('ABSPATH', $root . '/');
require $root . '/vendor/autoload.php';
$sourceFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) if ($file->isFile() && $file->getExtension() === 'php') $sourceFiles[] = $file->getPathname();
$sourceSet = [];
foreach ($sourceFiles as $file) $sourceSet[str_replace('\\', '/', realpath($file) ?: $file)] = true;
foreach ($sourceFiles as $file) require_once $file;
$inventory = [];
foreach (get_declared_classes() as $className) {
    $class = new ReflectionClass($className);
    foreach ($class->getMethods() as $method) {
        $file = $method->getFileName();
        if (!$file || !isset($sourceSet[str_replace('\\', '/', realpath($file) ?: $file)])) continue;
        $inventory[$className . '::' . $method->getName()] = ['name' => $className . '::' . $method->getName(),
            'file' => str_replace('\\', '/', substr($file, strlen($root) + 1)), 'line' => $method->getStartLine(),
            'end' => $method->getEndLine(), 'executed' => false, 'sources' => []];
    }
}
// Bootstrap/uninstall globals are inventoried without executing their lifecycle side effects.
foreach (['aws-s3-smartsync-wp.php', 'uninstall.php'] as $relative) {
    $tokens = token_get_all(file_get_contents($root . '/' . $relative));
    for ($i = 0; $i < count($tokens); $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $start = $tokens[$i][2]; $name = null;
        for ($j = $i + 1; $j < count($tokens); $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) $name = $tokens[$j][1];
            break;
        }
        if ($name) $inventory[$name] = ['name' => $name, 'file' => $relative, 'line' => $start, 'end' => null, 'executed' => false, 'sources' => []];
    }
}
$runs = 0; $captures = 0;
foreach (glob(rtrim($input, '/\\') . '/php-*.json') as $snapshot) {
    $data = json_decode(file_get_contents($snapshot), true, 512, JSON_THROW_ON_ERROR); $captures++; $accepted = false;
    foreach ($data['coverage'] ?? [] as $file => $coverage) {
        $normalized = str_replace('\\', '/', realpath($file) ?: $file);
        // Ignore stale or unhashed captures; every hit must belong to these exact source bytes.
        if (!is_file($file) || ($data['sourceHashes'][$normalized] ?? '') !== hash_file('sha256', $file)) continue;
        $accepted = true;
        foreach ($coverage['functions'] ?? [] as $name => $function) {
            $canonical = str_replace('->', '::', $name);
            if (!isset($inventory[$canonical])) continue;
            foreach ($function['branches'] ?? [] as $branch) {
                if (($branch['hit'] ?? 0) > 0) {
                    $inventory[$canonical]['executed'] = true;
                    $inventory[$canonical]['sources'][$data['scenario'] ?? $data['script'] ?? 'unknown'] = true;
                    break;
                }
            }
        }
    }
    if ($accepted) $runs++;
}
ksort($inventory);
$hit = count(array_filter($inventory, static fn(array $item): bool => $item['executed']));
$lines = ['# Named PHP function execution evidence', '',
    'Generated from Xdebug branch-entry hits whose process-start SHA-256 matches the current source file. Stale or unhashed captures are excluded. This measures execution, not correctness or complete branch/path coverage. Constructors, static/private methods and bootstrap/uninstall globals are included; anonymous callbacks are exercised through their workflows but excluded from this named-function count.', '',
    sprintf('%d of %d named functions/methods executed across %d captured PHP processes.', $hit, count($inventory), $runs), '',
    '| Function / method | Source | Executed | Evidence process |', '| --- | --- | --- | --- |'];
foreach ($inventory as $item) $lines[] = '| `' . $item['name'] . '` | `' . $item['file'] . ':' . $item['line'] . '` | ' . ($item['executed'] ? 'Yes' : '**No — requires additional execution/review evidence**') . ' | ' . implode(', ', array_keys($item['sources'])) . ' |';
if (!is_dir(dirname($output))) mkdir(dirname($output), 0777, true);
file_put_contents($output, implode("\n", $lines) . "\n");
$sourceHashes = [];
foreach (array_merge($sourceFiles, [$root . '/aws-s3-smartsync-wp.php', $root . '/uninstall.php']) as $file) {
    $sourceHashes[str_replace('\\', '/', substr($file, strlen($root) + 1))] = hash_file('sha256', $file);
}
ksort($sourceHashes);
file_put_contents(preg_replace('/\.md$/', '.json', $output), json_encode(['runs' => $runs, 'captures' => $captures, 'executed' => $hit, 'total' => count($inventory), 'sourceHashes' => $sourceHashes, 'functions' => array_values($inventory)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo $hit . '/' . count($inventory) . ' named PHP functions executed; report: ' . $output . PHP_EOL;
