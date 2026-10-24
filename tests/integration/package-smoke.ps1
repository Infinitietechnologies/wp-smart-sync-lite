param(
    [Parameter(Mandatory = $true)][string] $FixturePath,
    [Parameter(Mandatory = $true)][string] $WpCli,
    [Parameter(Mandatory = $true)][string] $ExtractedPlugin
)
$ErrorActionPreference = 'Stop'
$fixtureResolved = [IO.Path]::GetFullPath($FixturePath).Replace('\', '/')
$pluginResolved = (Resolve-Path -LiteralPath $ExtractedPlugin).Path.Replace('\', '/')
if (-not $fixtureResolved.Contains('smartsync-provider-workflows/fresh/smartsync-wordpress-audit-fixture/wordpress')) { throw 'Only the separate provider fixture is permitted.' }
if (-not $pluginResolved.Contains('/smartsync-package-smoke-')) { throw 'A separate temporary release extraction is required.' }
if (-not (Test-Path -LiteralPath ($pluginResolved + '/aws-s3-smartsync-wp.php'))) { throw 'Extracted release entrypoint missing.' }
$packageJunction = Join-Path $fixtureResolved 'wp-content/plugins/smartsync-packaged'
if (Test-Path -LiteralPath $packageJunction) { throw 'Package smoke requires a new, unused package plugin path.' }
$originalActive = & php $WpCli "--path=$fixtureResolved" option get active_plugins --format=json
if ($LASTEXITCODE -ne 0) { throw 'Could not snapshot the fixture activation state.' }
$originalPlugins = @($originalActive | ConvertFrom-Json)
if ($originalPlugins.Count -ne 1 -or $originalPlugins[0] -ne 'smartsync/aws-s3-smartsync-wp.php') { throw 'Expected only the original source plugin active.' }
function Invoke-ProviderFixtureWp([string[]] $Arguments) {
    & php $WpCli "--path=$fixtureResolved" @Arguments
    if ($LASTEXITCODE -ne 0) { throw ('Fixture WP-CLI failed: ' + ($Arguments -join ' ')) }
}
New-Item -ItemType Junction -Path $packageJunction -Target $pluginResolved | Out-Null
$sourceDeactivated = $false
try {
    Invoke-ProviderFixtureWp -Arguments @('plugin', 'deactivate', 'smartsync')
    $sourceDeactivated = $true
    Invoke-ProviderFixtureWp -Arguments @('plugin', 'activate', 'smartsync-packaged')
    Invoke-ProviderFixtureWp -Arguments @('eval-file', (Join-Path $PSScriptRoot 'package.php'))
    Invoke-ProviderFixtureWp -Arguments @('eval-file', (Join-Path $PSScriptRoot 'providers.php'))
} finally {
    if ($sourceDeactivated) {
        # Skip loading a potentially broken package so a failed activation remains reversible.
        & php $WpCli "--path=$fixtureResolved" --skip-plugins plugin deactivate smartsync-packaged
        $packageDeactivateCode = $LASTEXITCODE
        & php $WpCli "--path=$fixtureResolved" --skip-plugins plugin activate smartsync
        $sourceActivateCode = $LASTEXITCODE
        $restoredActive = & php $WpCli "--path=$fixtureResolved" option get active_plugins --format=json
        $restoredPlugins = @($restoredActive | ConvertFrom-Json)
        if ($packageDeactivateCode -ne 0 -or $sourceActivateCode -ne 0 -or $restoredPlugins.Count -ne 1 -or $restoredPlugins[0] -ne $originalPlugins[0]) { throw 'The fixture activation state was not fully restored.' }
        Write-Output 'Original source plugin restored as the only active plugin.'
    }
}
Write-Output ('Package smoke complete; inactive owned junction and extraction retained: ' + $packageJunction)
