<?php
/** Optional Xdebug collector, enabled only through a temporary CLI configuration. */
$smarlCoverageDirectory = getenv('SMARTSYNC_COVERAGE_DIR');
if (!$smarlCoverageDirectory || !function_exists('xdebug_start_code_coverage')) return;
$smarlCoverageRoot = dirname(__DIR__);
$smarlCoverageScenario = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
$smarlCoverageArguments = $_SERVER['argv'] ?? [];
$smarlCoverageEval = array_search('eval-file', $smarlCoverageArguments, true);
if ($smarlCoverageEval !== false && isset($smarlCoverageArguments[$smarlCoverageEval + 1])) {
    $smarlCoverageScenario = basename(str_replace('\\', '/', $smarlCoverageArguments[$smarlCoverageEval + 1]));
}
$smarlCoverageHashes = [];
$smarlCoverageFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($smarlCoverageRoot . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($smarlCoverageFiles as $smarlCoverageFile) {
    if ($smarlCoverageFile->isFile() && $smarlCoverageFile->getExtension() === 'php') {
        $smarlCoverageHashes[str_replace('\\', '/', $smarlCoverageFile->getRealPath())] = hash_file('sha256', $smarlCoverageFile->getPathname());
    }
}
foreach (['aws-s3-smartsync-wp.php', 'uninstall.php'] as $smarlCoverageFile) {
    $smarlCoverageHashes[str_replace('\\', '/', realpath($smarlCoverageRoot . '/' . $smarlCoverageFile))] = hash_file('sha256', $smarlCoverageRoot . '/' . $smarlCoverageFile);
}
xdebug_set_filter(XDEBUG_FILTER_CODE_COVERAGE, XDEBUG_PATH_INCLUDE, [
    $smarlCoverageRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR,
    $smarlCoverageRoot . DIRECTORY_SEPARATOR . 'aws-s3-smartsync-wp.php',
    $smarlCoverageRoot . DIRECTORY_SEPARATOR . 'uninstall.php',
]);
xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE | XDEBUG_CC_BRANCH_CHECK);
// Append collection after application shutdown hooks, including deferred attachment work.
register_shutdown_function(static function () use ($smarlCoverageDirectory, $smarlCoverageHashes, $smarlCoverageScenario): void {
    register_shutdown_function(static function () use ($smarlCoverageDirectory, $smarlCoverageHashes, $smarlCoverageScenario): void {
    $coverage = xdebug_get_code_coverage();
    xdebug_stop_code_coverage(false);
    if (!is_dir($smarlCoverageDirectory) && !mkdir($smarlCoverageDirectory, 0700, true) && !is_dir($smarlCoverageDirectory)) {
        fwrite(STDERR, "Cannot create coverage output directory.\n");
        return;
    }
    $output = ['script' => basename($_SERVER['SCRIPT_FILENAME'] ?? ''), 'scenario' => $smarlCoverageScenario, 'sourceHashes' => $smarlCoverageHashes, 'coverage' => $coverage];
    file_put_contents($smarlCoverageDirectory . DIRECTORY_SEPARATOR . 'php-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.json', json_encode($output, JSON_THROW_ON_ERROR));
    });
});
