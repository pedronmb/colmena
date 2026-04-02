#Requires -Version 5.1
<#
  Inicializa Git, commit inicial y push a GitHub.
  Requiere Git: https://git-scm.com/download/win

  Uso:
    .\scripts\git-init-and-push.ps1 -RemoteUrl 'https://github.com/USUARIO/REPO.git'
#>
param(
    [Parameter(Mandatory = $true)]
    [string] $RemoteUrl
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $root

$gitExe = $null
if (Get-Command git -ErrorAction SilentlyContinue) {
    $gitExe = "git"
} elseif (Test-Path "C:\Program Files\Git\bin\git.exe") {
    $gitExe = "C:\Program Files\Git\bin\git.exe"
} elseif (Test-Path "C:\Program Files (x86)\Git\bin\git.exe") {
    $gitExe = "C:\Program Files (x86)\Git\bin\git.exe"
}
if (-not $gitExe) {
    Write-Error "Instala Git y vuelve a abrir PowerShell. https://git-scm.com/download/win"
    exit 1
}

function G { & $gitExe @args }

if (-not (Test-Path (Join-Path $root ".git"))) {
    G init
}

G add .
if (G status --porcelain) {
    G commit -m "Initial commit: Colmena"
}

G branch -M main

G remote get-url origin *>$null
if ($LASTEXITCODE -eq 0) {
    G remote set-url origin $RemoteUrl
    Write-Host "Remoto origin actualizado."
} else {
    G remote add origin $RemoteUrl
}

Write-Host "Haciendo push a main..."
G push -u origin main
Write-Host "Listo."
