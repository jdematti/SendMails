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

$lockStream = $null

try {
    $lockStream = [System.IO.File]::Open($LockFile, [System.IO.FileMode]::OpenOrCreate, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None)
} catch {
    Write-WorkerLog "Ya hay una ejecucion del worker activa. Se omite esta corrida."
    exit 0
}

try {
    $lockStream.SetLength(0)
    $lockText = "PID=$PID; START=" + (Get-Date -Format "yyyy-MM-dd HH:mm:ss")
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($lockText)
    $lockStream.Write($bytes, 0, $bytes.Length)
    $lockStream.Flush()

    if (!(Test-Path $WorkerPath)) {
        throw "No se encontro worker.php en $WorkerPath"
    }

    Write-WorkerLog "Iniciando worker. Limit=$Limit"
    $output = & $PhpExe $WorkerPath "--limit=$Limit" 2>&1
    $exitCode = $LASTEXITCODE

    foreach ($line in $output) {
        Write-WorkerLog ([string]$line)
    }

    if ($exitCode -ne 0) {
        throw "El worker finalizo con codigo $exitCode"
    }

    Write-WorkerLog "Worker finalizado correctamente."
    exit 0
} catch {
    Write-WorkerLog ("ERROR: " + $_.Exception.Message)
    exit 1
} finally {
    if ($lockStream -ne $null) {
        $lockStream.Close()
        $lockStream.Dispose()
    }
}
