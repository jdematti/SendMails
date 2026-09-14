param([Parameter(Mandatory=$true)][string]$ProjectDir, [string]$BackupBase = '')
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path -LiteralPath $ProjectDir).ProviderPath.TrimEnd('\')
if (!(Test-Path -LiteralPath (Join-Path $root 'app\bootstrap.php')) -or !(Test-Path -LiteralPath (Join-Path $root 'storage\db_config.json'))) {
    throw 'La carpeta no es una instalacion configurada de SendMails.'
}
$task = Get-ScheduledTask -TaskName 'SendMails Worker' -ErrorAction SilentlyContinue
$flag = Join-Path $root 'storage\maintenance.flag'
Set-Content -LiteralPath $flag -Value 'Actualizacion en curso' -Encoding ASCII
if ($task) {
    Disable-ScheduledTask -TaskName $task.TaskName | Out-Null
    if ((Get-ScheduledTask -TaskName $task.TaskName).State -eq 'Running') {
        throw 'El worker aun esta enviando. No se lo interrumpio. Espera a que termine y ejecuta otra vez el actualizador; la tarea quedo deshabilitada.'
    }
}
$stream = $null
try {
    $stream = [IO.File]::Open((Join-Path $root 'storage\worker.lock'),[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
} catch {
    throw 'Hay otro proceso de envio activo. Espera a que termine y vuelve a ejecutar el actualizador.'
} finally { if ($stream) { $stream.Dispose() } }
if ($BackupBase -eq '') { $BackupBase = Join-Path $env:LOCALAPPDATA 'SendMails\backups' }
$backup = Join-Path $backupBase ('update-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N'))
if ([IO.Path]::GetFullPath($backup).StartsWith($root + '\',[StringComparison]::OrdinalIgnoreCase)) { throw 'El respaldo debe quedar fuera del sitio web.' }
$reparse = Get-ChildItem -LiteralPath $root -Force -Recurse | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint } | Select-Object -First 1
if ($reparse) { throw 'La instalacion contiene enlaces de archivos. Revisa el respaldo antes de continuar.' }
New-Item -ItemType Directory -Path $backup -Force | Out-Null
Get-ChildItem -LiteralPath $root -Force | ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination $backup -Recurse -Force }
foreach ($name in @('actualizar_produccion.cmd','inicializar_produccion.cmd','diagnosticar_git_produccion.cmd')) {
    $source = Join-Path $root $name
    if (!(Test-Path -LiteralPath $source)) { continue }
    $trackedName = & git -C $root ls-files -- $name
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo consultar Git.' }
    $remoteName = & git -C $root ls-tree --name-only origin/main -- $name
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo consultar origin/main.' }
    if (!$trackedName -and $remoteName) {
        $destinationDir = Join-Path $backup 'scripts-originales'
        New-Item -ItemType Directory -Path $destinationDir -Force | Out-Null
        $destination = [IO.Path]::GetFullPath((Join-Path $destinationDir $name))
        $resolvedSource = (Resolve-Path -LiteralPath $source).ProviderPath
        if (!$resolvedSource.StartsWith($root + '\',[StringComparison]::OrdinalIgnoreCase) -or
            !$destination.StartsWith([IO.Path]::GetFullPath($backup) + '\',[StringComparison]::OrdinalIgnoreCase)) {
            throw 'Ruta de respaldo fuera de la instalacion.'
        }
        Move-Item -LiteralPath $resolvedSource -Destination $destination
    }
}
Write-Host ('Respaldo privado: ' + $backup)
Write-Host 'Mantenimiento activo. La tarea permanecera deshabilitada si la actualizacion falla.'
