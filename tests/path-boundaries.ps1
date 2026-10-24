# Windows junction regression; uses only a fresh temporary fixture.
$ErrorActionPreference = 'Stop'
$pluginRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$boundaryRoot = Join-Path ([IO.Path]::GetTempPath()) ('smartsync-path-audit-' + [guid]::NewGuid().ToString('N'))
$boundaryBase = Join-Path $boundaryRoot 'wordpress'
$boundaryOutside = Join-Path $boundaryRoot 'outside'
$boundaryJunction = Join-Path $boundaryBase 'escape'
$boundaryScript = Join-Path $boundaryRoot 'check.php'
$phpCode = @'
<?php
$root = str_replace('\\', '/', $argv[1]) . '/';
define('ABSPATH', $root);
define('WP_CONTENT_DIR', $root . 'wp-content');
require $argv[2] . '/vendor/autoload.php';
$failures = 0;
foreach (['escape/private.jpg', 'escape/missing.jpg'] as $key) {
    try { \SmartSyncS3\Helpers\SMARL_Helper::resolveLocalPath($key); echo "FAIL accepted escaping junction: $key\n"; $failures++; }
    catch (InvalidArgumentException $error) { echo "PASS rejected escaping junction: $key\n"; }
}
try { \SmartSyncS3\Helpers\SMARL_Helper::relativeKeyForPath($root . 'escape/private.jpg'); echo "FAIL accepted external source\n"; $failures++; }
catch (InvalidArgumentException $error) { echo "PASS rejected external source through junction\n"; }
$keys = array_column(\SmartSyncS3\Helpers\SMARL_Helper::scanLocalFiles($root, ['jpg']), 'relative_path');
if ($keys !== ['safe.jpg']) { echo "FAIL scan followed junction\n"; $failures++; }
else { echo "PASS scan excludes junction target\n"; }
exit($failures ? 1 : 0);
'@
try {
    New-Item -ItemType Directory -Path $boundaryBase,$boundaryOutside | Out-Null
    [IO.File]::WriteAllText((Join-Path $boundaryOutside 'private.jpg'), 'fixture-private')
    [IO.File]::WriteAllText((Join-Path $boundaryBase 'safe.jpg'), 'fixture-safe')
    New-Item -ItemType Junction -Path $boundaryJunction -Target $boundaryOutside | Out-Null
    [IO.File]::WriteAllText($boundaryScript, $phpCode, [Text.UTF8Encoding]::new($false))
    & php $boundaryScript $boundaryBase $pluginRoot
    if ($LASTEXITCODE -ne 0) { throw 'Path boundary regression failed.' }
} finally {
    # Verify targets and remove the junction itself first; never recurse through it.
    $expectedRoot = [IO.Path]::GetFullPath($boundaryRoot)
    foreach ($cleanupPath in @($boundaryJunction, $boundaryScript, (Join-Path $boundaryOutside 'private.jpg'), (Join-Path $boundaryBase 'safe.jpg'), $boundaryBase, $boundaryOutside)) {
        $absolutePath = [IO.Path]::GetFullPath($cleanupPath)
        if (!$absolutePath.StartsWith($expectedRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Refusing cleanup outside the fixture.' }
    }
    if (Test-Path -LiteralPath $boundaryJunction) { [IO.Directory]::Delete($boundaryJunction) }
    foreach ($cleanupFile in @($boundaryScript, (Join-Path $boundaryOutside 'private.jpg'), (Join-Path $boundaryBase 'safe.jpg'))) {
        if (Test-Path -LiteralPath $cleanupFile) { Remove-Item -LiteralPath $cleanupFile }
    }
    foreach ($cleanupDirectory in @($boundaryBase, $boundaryOutside, $boundaryRoot)) {
        if (Test-Path -LiteralPath $cleanupDirectory) { [IO.Directory]::Delete($cleanupDirectory) }
    }
}
