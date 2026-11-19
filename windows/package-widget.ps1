param([string]$DotNet = 'dotnet', [switch]$NoRestore)

$ErrorActionPreference = 'Stop'
$project = Join-Path $PSScriptRoot 'ColmenaWidget\ColmenaWidget.csproj'
$dist = (New-Item -ItemType Directory -Path (Join-Path $PSScriptRoot 'dist') -Force).FullName
$stage = (New-Item -ItemType Directory -Path (Join-Path $dist ('.stage-' + [guid]::NewGuid().ToString('N')))).FullName

try {
    $publish = Join-Path $stage 'ColmenaWidget'
    $publishArguments = @('publish', $project, '-c', 'Release', '-r', 'win-x64', '--self-contained', 'true', '-o', $publish)
    if ($NoRestore) { $publishArguments += '--no-restore' }
    & $DotNet @publishArguments
    if ($LASTEXITCODE -ne 0) { throw 'Falló la compilación del widget de Colmena.' }
    if (-not (Test-Path -LiteralPath (Join-Path $publish 'ColmenaWidget.exe'))) {
        throw 'La compilación no generó ColmenaWidget.exe.'
    }

    Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'README.md') -Destination (Join-Path $publish 'LEEME.md')
    $archive = Join-Path $stage 'ColmenaWidget-win-x64.zip'
    Compress-Archive -LiteralPath $publish -DestinationPath $archive -CompressionLevel Optimal
    $destination = Join-Path $dist 'ColmenaWidget-win-x64.zip'
    Move-Item -LiteralPath $archive -Destination $destination -Force
    Get-Item -LiteralPath $destination
} finally {
    $resolvedStage = (Resolve-Path -LiteralPath $stage).ProviderPath
    if ($resolvedStage.StartsWith($dist + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
        Remove-Item -LiteralPath $resolvedStage -Recurse -Force
    }
}
