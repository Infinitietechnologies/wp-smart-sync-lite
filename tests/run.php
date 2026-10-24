<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
foreach (glob(__DIR__ . '/cases/*.php') as $case) { require $case; }
$passed = $failed = 0;
foreach ($GLOBALS['smartsync_tests'] ?? [] as [$name, $test]) {
    smartsync_test_reset();
    try { $test(); $passed++; echo 'PASS ' . $name . PHP_EOL; }
    catch (Throwable $e) { $failed++; fwrite(STDERR, 'FAIL ' . $name . ': ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL); }
}
echo PHP_EOL . $passed . ' passed; ' . $failed . ' failed.' . PHP_EOL;
exit($failed ? 1 : 0);
