@echo off
setlocal EnableExtensions DisableDelayedExpansion
title Inicializar SendMails en produccion
set "SM_INIT_FILE=%~f0"
set "SM_INIT_ROOT=%~dp0"
rem PowerShell carga todo el script antes de sincronizar los archivos.
(
    powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$text = [IO.File]::ReadAllText($env:SM_INIT_FILE); & ([ScriptBlock]::Create(($text -split '(?m)^# SM_INIT_POWERSHELL\r?$', 2)[1]))"
    if errorlevel 1 (
        echo.
        echo INICIALIZACION NO COMPLETADA. Revisa el error mostrado.
        pause
        exit /b 1
    ) else (
        echo.
        pause
        exit /b 0
    )
)
# SM_INIT_POWERSHELL
$ErrorActionPreference = 'Stop'
$remote = 'https://github.com/jdematti/SendMails.git'
$script:root = [IO.Path]::GetFullPath($env:SM_INIT_ROOT).TrimEnd('\')
$script:rootPrefix = $root + '\'
$backup = $null
$stage = $null
$copyStarted = $false
$invalidGit = $false

function Invoke-InitGit {
    param([string[]]$Arguments, [switch]$AllowFailure)
    $savedPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& $script:gitExe @Arguments 2>&1)
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $savedPreference
    }
    if ($AllowFailure) {
        return [pscustomobject]@{ ExitCode = $code; Output = ($output | ForEach-Object { [string]$_ }) -join [Environment]::NewLine }
    }
    if ($code -ne 0) {
        throw ('Git no pudo completar la operacion: ' + (($output | ForEach-Object { [string]$_ }) -join [Environment]::NewLine))
    }
    $output | ForEach-Object { [string]$_ }
}

function Get-ProductionPath {
    param([string]$RelativePath)
    $path = [IO.Path]::GetFullPath((Join-Path $script:root $RelativePath))
    if (!$path.StartsWith($script:rootPrefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw ('Ruta fuera de la carpeta del proyecto: ' + $RelativePath)
    }
    return $path
}

function Get-PrivateFileHash {
    param([string]$Path)
    $algorithm = [Security.Cryptography.SHA256]::Create()
    $stream = $null
    try {
        $stream = [IO.File]::OpenRead($Path)
        return [Convert]::ToBase64String($algorithm.ComputeHash($stream))
    } finally {
        if ($stream) { $stream.Dispose() }
        $algorithm.Dispose()
    }
}

try {
    Write-Host ''
    Write-Host ('Carpeta de produccion: ' + $root)
    Write-Host ('Repositorio: ' + $remote)
    $gitCommand = Get-Command git.exe -ErrorAction Stop
    $script:gitExe = $gitCommand.Source
    if ($env:GIT_DIR -or $env:GIT_WORK_TREE -or $env:GIT_COMMON_DIR) {
        throw 'Hay variables GIT_DIR, GIT_WORK_TREE o GIT_COMMON_DIR definidas. Ejecuta el CMD desde una sesion sin esas variables.'
    }
    $gitPath = Get-ProductionPath '.git'
    if (Test-Path -LiteralPath $gitPath) {
        if (!(Test-Path -LiteralPath $gitPath -PathType Container)) {
            throw '.git es un archivo que puede apuntar a otro repositorio o worktree. Requiere revision manual; no se modifico.'
        }
        $probe = Invoke-InitGit -Arguments @('-C', $root, 'rev-parse', '--is-inside-work-tree') -AllowFailure
        if ($probe.ExitCode -eq 0) {
            throw 'Git reconoce un repositorio existente. No se reinicializo. Utiliza actualizar_produccion.cmd si ya esta configurado.'
        }
        if ($probe.Output -notmatch 'not a git repository') {
            throw ('No se pudo comprobar .git. No se modificara ante errores de permisos u otros problemas: ' + $probe.Output)
        }
        $invalidGit = $true
        Write-Host 'Git no reconoce .git como repositorio. Se conservara en el respaldo antes de instalar el repositorio nuevo.'
    }
    foreach ($required in @('app/bootstrap.php', 'storage/db_config.json')) {
        if (!(Test-Path -LiteralPath (Get-ProductionPath $required) -PathType Leaf)) {
            throw ('No se encontro ' + $required + '. Coloca este CMD en la carpeta de la instalacion existente de SendMails.')
        }
    }
    $rootItem = Get-Item -LiteralPath $root -Force
    if ($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint) {
        throw 'La carpeta es un enlace o junction. Utiliza la ruta real de la instalacion.'
    }
    $linked = Get-ChildItem -LiteralPath $root -Force -Recurse | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint } | Select-Object -First 1
    if ($linked) {
        throw ('Hay un enlace o junction dentro del proyecto. Debe revisarse antes de copiar: ' + $linked.FullName)
    }

    Write-Host '[1/5] Descargando main en una carpeta temporal...'
    $stage = Join-Path ([IO.Path]::GetTempPath()) ('SendMails-init-' + [guid]::NewGuid().ToString('N'))
    Invoke-InitGit -Arguments @('clone', '--branch', 'main', '--single-branch', '--', $remote, $stage) | Out-Host
    $tree = @(Invoke-InitGit -Arguments @('-C', $stage, 'ls-tree', '-r', 'HEAD'))
    if ($tree | Where-Object { $_ -match '^(120000|160000) ' }) {
        throw 'El remoto contiene enlaces o submodulos que requieren una instalacion manual.'
    }
    $paths = @(Invoke-InitGit -Arguments @('-C', $stage, '-c', 'core.quotepath=false', 'ls-tree', '-r', '--name-only', 'HEAD'))
    foreach ($relative in $paths) {
        if ($relative -match '^(?i:vendor/|storage/(?!\.htaccess$|default_template\.html$)|\.env(?:$|\.)|\.git/|\.codex/|\.agents/)') {
            throw ('El remoto intenta versionar configuracion o archivos privados: ' + $relative)
        }
        $destination = Get-ProductionPath $relative
        if (Test-Path -LiteralPath $destination -PathType Container) {
            throw ('Existe una carpeta donde el remoto necesita un archivo: ' + $relative)
        }
    }
    if ($paths -notcontains '.gitignore' -or $paths -notcontains 'app/bootstrap.php') {
        throw 'El remoto no contiene la estructura esperada de SendMails.'
    }

    Write-Host '[2/5] Respaldando toda la instalacion, incluida la configuracion privada...'
    if ([string]::IsNullOrEmpty($env:LOCALAPPDATA)) {
        throw 'No se pudo determinar la carpeta privada de respaldos del usuario de Windows.'
    }
    $backup = [IO.Path]::GetFullPath((Join-Path $env:LOCALAPPDATA ('SendMails\backups\' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N'))))
    if ($backup.StartsWith($rootPrefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'El respaldo debe quedar fuera de la carpeta del proyecto.'
    }
    New-Item -ItemType Directory -Path $backup | Out-Null
    foreach ($item in Get-ChildItem -LiteralPath $root -Force) {
        Copy-Item -LiteralPath $item.FullName -Destination $backup -Recurse -Force
    }
    Write-Host ('RESPALDO PRIVADO: ' + $backup)

    Write-Host '[3/5] Conservando la clave de facturas...'
    $keyPath = Get-ProductionPath 'storage/invoice_crypto.key'
    $hasKey = (Test-Path -LiteralPath $keyPath -PathType Leaf) -and ![string]::IsNullOrWhiteSpace([IO.File]::ReadAllText($keyPath))
    if (!$hasKey -and [string]::IsNullOrEmpty($env:INVOICE_CRYPTO_KEY)) {
        $cryptoPath = Get-ProductionPath 'app/InvoiceCrypto.php'
        $oldCode = if (Test-Path -LiteralPath $cryptoPath -PathType Leaf) { [IO.File]::ReadAllText($cryptoPath) } else { '' }
        $match = [regex]::Match($oldCode, 'private\s+const\s+KEY\s*=\s*''([^'']+)''\s*;')
        if (!$match.Success) {
            throw 'Falta la clave de facturas. Copia storage/invoice_crypto.key desde el respaldo privado y vuelve a ejecutar este CMD.'
        }
        [IO.File]::WriteAllText($keyPath, $match.Groups[1].Value, [Text.UTF8Encoding]::new($false))
        Copy-Item -LiteralPath $keyPath -Destination (Join-Path $backup 'storage/invoice_crypto.key') -Force
        Write-Host 'Clave anterior conservada sin cambiar su valor.'
    }

    Write-Host '[4/5] Sincronizando codigo e inicializando Git...'
    if ($invalidGit) {
        # Verificar otra vez antes de mover: no apartar un repositorio reparado durante la descarga.
        $probe = Invoke-InitGit -Arguments @('-C', $root, 'rev-parse', '--is-inside-work-tree') -AllowFailure
        if ($probe.ExitCode -eq 0 -or $probe.Output -notmatch 'not a git repository') {
            throw 'El estado de .git cambio durante la preparacion. Se detuvo la inicializacion para conservarlo.'
        }
        $resolvedGit = (Resolve-Path -LiteralPath $gitPath).ProviderPath
        $savedGit = [IO.Path]::GetFullPath((Join-Path $backup 'git-invalido-original'))
        $backupPrefix = [IO.Path]::GetFullPath($backup).TrimEnd('\') + '\'
        if ($resolvedGit -ne (Get-ProductionPath '.git') -or !$savedGit.StartsWith($backupPrefix, [StringComparison]::OrdinalIgnoreCase)) {
            throw 'No se pudieron verificar las rutas para conservar .git.'
        }
        if ((Get-Item -LiteralPath $resolvedGit -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) {
            throw '.git es un enlace o junction. No se modifico.'
        }
        if (Test-Path -LiteralPath $savedGit) { throw 'El destino de respaldo de .git ya existe.' }
        $copyStarted = $true
        Move-Item -LiteralPath $resolvedGit -Destination $savedGit -Force
        Write-Host ('.git anterior conservado en: ' + $savedGit)
    }
    $copyStarted = $true
    foreach ($relative in $paths) {
        $destination = Get-ProductionPath $relative
        $parent = Split-Path -Parent $destination
        if (!(Test-Path -LiteralPath $parent)) {
            New-Item -ItemType Directory -Path $parent -Force | Out-Null
        }
        Copy-Item -LiteralPath (Join-Path $stage $relative) -Destination $destination -Force
    }
    Copy-Item -LiteralPath (Join-Path $stage '.git') -Destination (Get-ProductionPath '.git') -Recurse -Force

    Write-Host '[5/5] Comprobando repositorio y configuracion...'
    Invoke-InitGit -Arguments @('-C', $root, 'diff', '--exit-code') | Out-Host
    Invoke-InitGit -Arguments @('-C', $root, 'diff', '--cached', '--exit-code') | Out-Host
    foreach ($private in @('storage/db_config.json', 'storage/branch_secret.key', 'storage/invoice_crypto.key')) {
        $original = Join-Path $backup $private
        $current = Get-ProductionPath $private
        if (Test-Path -LiteralPath $original -PathType Leaf) {
            if ((Get-PrivateFileHash $original) -ne (Get-PrivateFileHash $current)) {
                throw ('La configuracion privada no coincide con el respaldo: ' + $private)
            }
        }
        Invoke-InitGit -Arguments @('-C', $root, 'check-ignore', '--', $private) | Out-Null
    }
    Invoke-InitGit -Arguments @('-C', $root, 'status', '--short', '--branch') | Out-Host
    Write-Host ''
    Write-Host 'INICIALIZACION COMPLETADA: main conectada a origin/main.'
    Write-Host 'Ahora ejecuta actualizar_produccion.cmd para instalar dependencias y completar la actualizacion.'
    Write-Host ('Respaldo de archivos: ' + $backup)
    Write-Host 'No se modificaron las bases de datos ni se enviaron mensajes.'
    exit 0
} catch {
    Write-Host ''
    Write-Host ('ERROR: ' + $_.Exception.Message) -ForegroundColor Red
    if ($backup) { Write-Host ('Respaldo de archivos: ' + $backup) }
    if ($copyStarted) {
        Write-Host 'La copia de codigo comenzo. Puede haber archivos actualizados; revisa el error antes de usar la aplicacion. El respaldo permite recuperar la instalacion anterior.'
    }
    exit 1
}
