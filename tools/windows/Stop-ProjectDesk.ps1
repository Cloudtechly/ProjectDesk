$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runtimeDirectory = Join-Path $projectRoot 'storage\framework\project-desk-desktop'
$processNames = @('web', 'scheduler', 'queue')

foreach ($processName in $processNames) {
    $pidPath = Join-Path $runtimeDirectory "$processName.pid"

    if (-not (Test-Path -LiteralPath $pidPath)) {
        continue
    }

    $processId = Get-Content -LiteralPath $pidPath -Encoding ASCII -ErrorAction SilentlyContinue
    $process = Get-Process -Id $processId -ErrorAction SilentlyContinue

    if ($null -ne $process -and $process.ProcessName -eq 'php') {
        Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue
    }

    Remove-Item -LiteralPath $pidPath -Force -ErrorAction SilentlyContinue
}

Write-Output 'Project Desk background processes stopped.'
