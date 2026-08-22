param(
    [switch] $NoBrowser
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$artisanPath = Join-Path $projectRoot 'artisan'
$runtimeDirectory = Join-Path $projectRoot 'storage\framework\project-desk-desktop'
$logDirectory = Join-Path $projectRoot 'storage\logs\project-desk-desktop'
$applicationUrl = 'http://127.0.0.1:8010'
$healthUrl = "$applicationUrl/up"
$mutex = [Threading.Mutex]::new($false, 'Local\CloudTech.ProjectDesk.DevelopmentLauncher')
$ownsMutex = $false

function Show-LauncherError {
    param([string] $Message)

    try {
        Add-Type -AssemblyName PresentationFramework
        [System.Windows.MessageBox]::Show(
            $Message,
            'Project Desk',
            [System.Windows.MessageBoxButton]::OK,
            [System.Windows.MessageBoxImage]::Error
        ) | Out-Null
    } catch {
        Write-Error $Message
    }
}

function Test-ApplicationHealth {
    try {
        $response = Invoke-WebRequest -Uri $healthUrl -UseBasicParsing -TimeoutSec 2

        return $response.StatusCode -eq 200
    } catch {
        return $false
    }
}

function Find-ProjectProcess {
    param([string] $Name)

    $pidPath = Join-Path $runtimeDirectory "$Name.pid"

    if (-not (Test-Path -LiteralPath $pidPath)) {
        return $null
    }

    $pidValue = Get-Content -LiteralPath $pidPath -Encoding ASCII -ErrorAction SilentlyContinue
    $processId = 0

    if (-not [int]::TryParse([string] $pidValue, [ref] $processId)) {
        Remove-Item -LiteralPath $pidPath -Force -ErrorAction SilentlyContinue

        return $null
    }

    $process = Get-Process -Id $processId -ErrorAction SilentlyContinue

    if ($null -eq $process -or $process.ProcessName -ne 'php') {
        Remove-Item -LiteralPath $pidPath -Force -ErrorAction SilentlyContinue

        return $null
    }

    return $process
}

function Start-ProjectProcess {
    param(
        [string] $Name,
        [string[]] $Arguments,
        [string] $WorkingDirectory = $projectRoot,
        [bool] $UseArtisan = $true
    )

    $existingProcess = Find-ProjectProcess -Name $Name

    if ($null -ne $existingProcess) {
        return
    }

    $standardOutput = Join-Path $logDirectory "$Name.out.log"
    $standardError = Join-Path $logDirectory "$Name.err.log"
    $processArguments = $Arguments

    if ($UseArtisan) {
        $quotedArtisanPath = '"' + $artisanPath + '"'
        $processArguments = @($quotedArtisanPath) + $Arguments
    }

    $startOptions = @{
        FilePath = $script:phpPath
        ArgumentList = $processArguments
        WorkingDirectory = $WorkingDirectory
        WindowStyle = 'Hidden'
        RedirectStandardOutput = $standardOutput
        RedirectStandardError = $standardError
        PassThru = $true
    }
    $process = Start-Process @startOptions

    Set-Content -LiteralPath (Join-Path $runtimeDirectory "$Name.pid") -Value $process.Id -Encoding ASCII
}

try {
    $ownsMutex = $mutex.WaitOne(0)

    if (-not $ownsMutex) {
        exit 0
    }

    $requiredPaths = @(
        (Join-Path $projectRoot '.env'),
        (Join-Path $projectRoot 'vendor\autoload.php'),
        (Join-Path $projectRoot 'database\database.sqlite'),
        (Join-Path $projectRoot 'public\build\manifest.json'),
        $artisanPath
    )

    $missingPaths = $requiredPaths | Where-Object { -not (Test-Path -LiteralPath $_) }

    if ($missingPaths.Count -gt 0) {
        throw "Project Desk setup is incomplete. Run composer setup once from the project directory.`n`nMissing files:`n$($missingPaths -join "`n")"
    }

    $phpCommand = Get-Command php -ErrorAction Stop
    $script:phpPath = $phpCommand.Source

    New-Item -ItemType Directory -Path $runtimeDirectory -Force | Out-Null
    New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null

    if (-not (Test-ApplicationHealth)) {
        $portOwner = Get-NetTCPConnection -LocalPort 8010 -State Listen -ErrorAction SilentlyContinue |
            Select-Object -First 1

        if ($null -ne $portOwner) {
            throw 'Port 8010 is already used by another application. Close it and try again.'
        }

        $serverRouter = Join-Path $projectRoot 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
        $quotedServerRouter = '"' + $serverRouter + '"'
        Start-ProjectProcess -Name 'web' -UseArtisan $false -WorkingDirectory (Join-Path $projectRoot 'public') -Arguments @(
            '-S',
            '127.0.0.1:8010',
            $quotedServerRouter
        )

        $deadline = [DateTime]::UtcNow.AddSeconds(25)

        do {
            Start-Sleep -Milliseconds 350

            if (Test-ApplicationHealth) {
                break
            }
        } while ([DateTime]::UtcNow -lt $deadline)

        if (-not (Test-ApplicationHealth)) {
            throw "Project Desk could not start. Check the log:`n$logDirectory\web.err.log"
        }
    }

    Start-ProjectProcess -Name 'scheduler' -Arguments @(
        'schedule:work',
        '--whisper',
        '--no-interaction'
    )

    Start-ProjectProcess -Name 'queue' -Arguments @(
        'queue:work',
        'database',
        '--sleep=3',
        '--tries=3',
        '--timeout=120',
        '--memory=256',
        '--no-interaction'
    )

    if (-not $NoBrowser) {
        Start-Process $applicationUrl
    }
} catch {
    Show-LauncherError -Message $_.Exception.Message
    exit 1
} finally {
    if ($ownsMutex) {
        $mutex.ReleaseMutex()
    }

    $mutex.Dispose()
}
