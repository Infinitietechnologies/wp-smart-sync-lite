param([string]$OutputPath = '')
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$pluginRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$bootstrap = Get-Content -Raw -LiteralPath (Join-Path $pluginRoot 'aws-s3-smartsync-wp.php')
$version = [regex]::Match($bootstrap, 'Version:\s*([0-9]+(?:\.[0-9]+)+)').Groups[1].Value
if (!$version) { throw 'Plugin version is missing.' }
if (!$OutputPath) { $OutputPath = Join-Path $pluginRoot ('dist/smartsync-lite-' + $version + '.zip') }
$archivePath = [IO.Path]::GetFullPath($OutputPath)
foreach ($requiredFile in @('vendor/autoload.php', 'vendor/woocommerce/action-scheduler/action-scheduler.php', 'composer.lock')) {
    if (!(Test-Path -LiteralPath (Join-Path $pluginRoot $requiredFile))) { throw ('Install Composer dependencies before packaging: ' + $requiredFile) }
}
[IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($archivePath)) | Out-Null
# CreateNew preserves any existing release instead of overwriting it silently.
$archiveStream = [IO.File]::Open($archivePath, [IO.FileMode]::CreateNew)
$archive = New-Object IO.Compression.ZipArchive($archiveStream, [IO.Compression.ZipArchiveMode]::Create, $false)
$fileCount = 0
try {
    $sourceFiles = @()
    foreach ($directory in @('src', 'assets', 'languages', 'vendor', 'Documentation')) {
        $sourceFiles += Get-ChildItem -LiteralPath (Join-Path $pluginRoot $directory) -File -Recurse
    }
    $sourceFiles += Get-Item -LiteralPath (Join-Path $pluginRoot 'aws-s3-smartsync-wp.php'), (Join-Path $pluginRoot 'uninstall.php'), (Join-Path $pluginRoot 'composer.json'), (Join-Path $pluginRoot 'composer.lock'), (Join-Path $pluginRoot 'readme.txt')
    foreach ($sourceFile in $sourceFiles) {
        $relative = $sourceFile.FullName.Substring($pluginRoot.Length + 1).Replace('\', '/')
        if ($relative -match '(^|/)(\.idea|\.vscode|\.git|node_modules)(/|$)|(^|/)\.DS_Store$|\.log$') { continue }
        [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $sourceFile.FullName, ('smartsync/' + $relative), [IO.Compression.CompressionLevel]::Optimal) | Out-Null
        $fileCount++
    }
} finally {
    $archive.Dispose()
    $archiveStream.Dispose()
}
$verification = [IO.Compression.ZipFile]::OpenRead($archivePath)
try {
    foreach ($requiredEntry in @('smartsync/aws-s3-smartsync-wp.php', 'smartsync/vendor/autoload.php', 'smartsync/vendor/woocommerce/action-scheduler/action-scheduler.php')) {
        if (!$verification.GetEntry($requiredEntry)) { throw ('Release entry missing: ' + $requiredEntry) }
    }
    foreach ($entry in $verification.Entries) {
        if ($entry.FullName -match '^smartsync/(?:tests|\.agents|\.git|dist)/') { throw ('Development file included: ' + $entry.FullName) }
    }
} finally { $verification.Dispose() }
Write-Output ('Packaged ' + $fileCount + ' files: ' + $archivePath)
Get-FileHash -LiteralPath $archivePath -Algorithm SHA256 | Select-Object Path,Hash
