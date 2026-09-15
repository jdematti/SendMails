param(
    [Parameter(Mandatory=$true)][string]$ProjectDir,
    [string]$BackupBase = '',
    [ValidateRange(0,600)][int]$WorkerWaitSeconds = 180
)
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path -LiteralPath $ProjectDir).ProviderPath.TrimEnd('\')
if (!(Test-Path -LiteralPath (Join-Path $root 'app\bootstrap.php')) -or !(Test-Path -LiteralPath (Join-Path $root 'storage\db_config.json'))) {
    throw 'La carpeta no es una instalacion configurada de SendMails.'
}
$task = Get-ScheduledTask -TaskName 'SendMails Worker' -ErrorAction SilentlyContinue
$flag = Join-Path $root 'storage\maintenance.flag'
Set-Content -LiteralPath $flag -Value 'Actualizacion en curso' -Encoding ASCII
if ($task) {
    Disable-ScheduledTask -TaskName $task.TaskName -TaskPath $task.TaskPath | Out-Null
}
$wait = [Diagnostics.Stopwatch]::StartNew()
$nextNotice = 0
$waited = $false
while ($true) {
    $taskBusy = $false
    if ($task) {
        $currentTask = Get-ScheduledTask -TaskName $task.TaskName -TaskPath $task.TaskPath -ErrorAction Stop
        if (!$currentTask) { throw 'No se pudo comprobar el estado de la tarea del worker.' }
        $taskBusy = $currentTask.State -in @('Running','Queued')
    }
    $lockBusy = $false
    $stream = $null
    try {
        $stream = [IO.File]::Open((Join-Path $root 'storage\worker.lock'),[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
    } catch [IO.IOException] {
        # Solo una infraccion de uso compartido/bloqueo indica un worker ocupado.
        $errorCode = $_.Exception.HResult -band 0xffff
        if ($errorCode -notin @(32,33)) { throw }
        $lockBusy = $true
    } finally { if ($stream) { $stream.Dispose() } }
    if (!$taskBusy -and !$lockBusy) { break }
    if ($wait.Elapsed.TotalSeconds -ge $WorkerWaitSeconds) {
        throw ('El worker sigue en ejecucion o mantiene su bloqueo tras esperar ' + $WorkerWaitSeconds + ' segundos. No se lo interrumpio ni se actualizo el codigo. El mantenimiento sigue activo y la tarea sigue deshabilitada. Revisa SendMails Worker y storage\logs en el servidor; cuando termine, ejecuta nuevamente el actualizador.')
    }
    if ($wait.Elapsed.TotalSeconds -ge $nextNotice) {
        $remaining = [Math]::Ceiling($WorkerWaitSeconds - $wait.Elapsed.TotalSeconds)
        Write-Host ('Esperando que termine el worker, sin interrumpirlo... quedan hasta ' + $remaining + ' segundos.')
        $nextNotice = $wait.Elapsed.TotalSeconds + 10
    }
    $waited = $true
    Start-Sleep -Milliseconds 1000
}
$wait.Stop()
if ($waited) { Write-Host 'Worker finalizado. Continuando con el respaldo y la actualizacion.' }
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
