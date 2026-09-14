param(
    [int]$Limit = 1000,
    [string]$PhpExe = "C:\xampp\php\php.exe"
)

$ErrorActionPreference = "Stop"

$ProjectDir = Split-Path -Parent $PSCommandPath
$WorkerPath = Join-Path $ProjectDir "worker.php"
$StorageDir = Join-Path $ProjectDir "storage"
$LogDir = Join-Path $StorageDir "logs"
$LockFile = Join-Path $StorageDir "worker.lock"

if (!(Test-Path $PhpExe)) {
    $PhpExe = "php"
}

New-Item -ItemType Directory -Force -Path $StorageDir | Out-Null
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

$LogFile = Join-Path $LogDir ("worker-" + (Get-Date -Format "yyyyMMdd") + ".log")

function Write-WorkerLog {
    param([string]$Message)

    $line = "[" + (Get-Date -Format "yyyy-MM-dd HH:mm:ss") + "] " + $Message
    Add-Content -Path $LogFile -Value $line -Encoding UTF8
    Write-Output $line
}

try {
    if (!(Test-Path -LiteralPath $WorkerPath)) { throw 'No se encontro worker.php' }
    Write-WorkerLog "Iniciando worker. Limit=$Limit"
    $output = & $PhpExe $WorkerPath "--limit=$Limit" "--max-seconds=55" 2>&1
    $exitCode = $LASTEXITCODE
    foreach ($line in $output) { Write-WorkerLog ([string]$line) }
    if ($exitCode -ne 0) { throw "El worker finalizo con codigo $exitCode" }
    Write-WorkerLog 'Worker finalizado.'
    exit 0
} catch {
    Write-WorkerLog ("ERROR: " + $_.Exception.Message)
    exit 1
}
