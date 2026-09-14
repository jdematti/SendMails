$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$fixture = Join-Path $env:TEMP ('sendmails-deploy-test-' + [guid]::NewGuid().ToString('N'))
$publisher = Join-Path $fixture 'publisher'
$target = Join-Path $fixture 'production fixture'
$remote = Join-Path $fixture 'origin.git'
$backups = Join-Path $fixture 'private-backups'
function Assert-True($condition,$message) { if (!$condition) { throw $message } }
function Invoke-Git([string[]]$Arguments) { & git @Arguments 2>&1 | Out-Null; if ($LASTEXITCODE -ne 0) { throw ('Git fallo: ' + ($Arguments -join ' ')) } }
foreach ($script in @('deploy_prepare.ps1','install_scheduled_task.ps1','run_worker.ps1')) {
    $tokens = $null; $errors = $null
    [Management.Automation.Language.Parser]::ParseFile((Join-Path $project $script),[ref]$tokens,[ref]$errors) | Out-Null
    Assert-True ($errors.Count -eq 0) ('PowerShell invalido: ' + $script)
}
New-Item -ItemType Directory -Path $publisher -Force | Out-Null
Invoke-Git @('init','-q','-b','main',$publisher)
Invoke-Git @('-C',$publisher,'config','user.name','Fixture')
Invoke-Git @('-C',$publisher,'config','user.email','fixture@example.invalid')
New-Item -ItemType Directory -Path (Join-Path $publisher 'app') -Force | Out-Null
Set-Content -LiteralPath (Join-Path $publisher 'app\bootstrap.php') -Value '<?php'
Set-Content -LiteralPath (Join-Path $publisher '.gitignore') -Value '/storage/'
Invoke-Git @('-C',$publisher,'add','.')
Invoke-Git @('-C',$publisher,'commit','-qm','base')
Invoke-Git @('clone','-q','--bare',$publisher,$remote)
Invoke-Git @('clone','-q',$remote,$target)
Invoke-Git @('-C',$publisher,'remote','add','origin',$remote)
foreach ($name in @('actualizar_produccion.cmd','inicializar_produccion.cmd','diagnosticar_git_produccion.cmd')) {
    Set-Content -LiteralPath (Join-Path $publisher $name) -Value '@echo new'
    Set-Content -LiteralPath (Join-Path $target $name) -Value '@echo old untracked'
}
Invoke-Git @('-C',$publisher,'add','.')
Invoke-Git @('-C',$publisher,'commit','-qm','deployment scripts')
Invoke-Git @('-C',$publisher,'push','-q','origin','main')
Invoke-Git @('-C',$target,'fetch','-q')
New-Item -ItemType Directory -Path (Join-Path $target 'storage') -Force | Out-Null
Set-Content -LiteralPath (Join-Path $target 'storage\db_config.json') -Value '{"fixture":true}'
$global:sendMailsFixtureTaskState='Ready'
$global:sendMailsFixtureDisabled=$false
function Get-ScheduledTask { param($TaskName,$ErrorAction) [pscustomobject]@{TaskName=$TaskName;State=$global:sendMailsFixtureTaskState} }
function Disable-ScheduledTask { param($TaskName) $global:sendMailsFixtureDisabled=$true }
& (Join-Path $project 'deploy_prepare.ps1') -ProjectDir $target -BackupBase $backups
Assert-True $global:sendMailsFixtureDisabled 'Debe deshabilitar la tarea'
Assert-True (Test-Path -LiteralPath (Join-Path $target 'storage\maintenance.flag')) 'Debe activar mantenimiento'
Assert-True (!(Test-Path -LiteralPath (Join-Path $target 'actualizar_produccion.cmd'))) 'Debe conservar scripts no rastreados fuera de la copia antes del merge'
$backup = Get-ChildItem -LiteralPath $backups -Directory | Select-Object -First 1
Assert-True (Test-Path -LiteralPath (Join-Path $backup.FullName 'scripts-originales\actualizar_produccion.cmd')) 'Falta respaldo del actualizador original'
Assert-True ((Get-Content -LiteralPath (Join-Path $target 'storage\db_config.json') -Raw).Trim() -eq '{"fixture":true}') 'No debe cambiar configuracion privada'
Invoke-Git @('-C',$target,'merge','-q','--ff-only','origin/main')
Assert-True ((Get-Content -LiteralPath (Join-Path $target 'actualizar_produccion.cmd') -Raw).Trim() -eq '@echo new') 'El merge debe instalar los scripts'
$global:sendMailsFixtureTaskState='Running'
$rejected=$false
try { & (Join-Path $project 'deploy_prepare.ps1') -ProjectDir $target -BackupBase $backups } catch { $rejected=$true }
Assert-True $rejected 'No debe actualizar mientras la tarea envia'
$global:sendMailsFixtureTaskState='Ready'
$lock=[IO.File]::Open((Join-Path $target 'storage\worker.lock'),[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
try {
    $rejected=$false
    try { & (Join-Path $project 'deploy_prepare.ps1') -ProjectDir $target -BackupBase $backups } catch { $rejected=$true }
    Assert-True $rejected 'No debe actualizar mientras otro worker retiene el bloqueo'
} finally { $lock.Dispose() }
Write-Output 'DEPLOY OK: sintaxis PowerShell, respaldo, scripts no rastreados, fast-forward, conservacion de configuracion, tarea en curso y bloqueo activo.'
function New-ScheduledTaskPrincipal { param($UserId,$LogonType,$RunLevel) [pscustomobject]@{UserId=$UserId;LogonType=$LogonType;RunLevel=$RunLevel} }
function New-ScheduledTaskAction { param($Execute,$Argument,$WorkingDirectory) [pscustomobject]@{Execute=$Execute;Argument=$Argument;WorkingDirectory=$WorkingDirectory} }
function New-ScheduledTaskTrigger { param([switch]$Once,$At,$RepetitionInterval,$RepetitionDuration) [pscustomobject]@{Interval=$RepetitionInterval} }
function New-ScheduledTaskSettingsSet { param($MultipleInstances,[switch]$AllowStartIfOnBatteries,[switch]$DontStopIfGoingOnBatteries,[switch]$StartWhenAvailable,$ExecutionTimeLimit) [pscustomobject]@{MultipleInstances=$MultipleInstances} }
function Register-ScheduledTask { param($TaskName,$Action,$Trigger,$Principal,$Settings,$Description,[switch]$Force) $global:sendMailsRegisteredTask=@{Principal=$Principal;Trigger=$Trigger;Settings=$Settings} }
& (Join-Path $project 'install_scheduled_task.ps1')
Assert-True ($global:sendMailsRegisteredTask.Principal.UserId -eq 'SYSTEM') 'Debe funcionar sin sesion'
Assert-True ($global:sendMailsRegisteredTask.Principal.LogonType -eq 'ServiceAccount') 'Tipo de inicio de sesion incorrecto'
Assert-True ($global:sendMailsRegisteredTask.Trigger.Interval.TotalMinutes -eq 1) 'Debe consultar cada minuto'
Assert-True ($global:sendMailsRegisteredTask.Settings.MultipleInstances -eq 'IgnoreNew') 'Debe evitar tareas superpuestas'
Write-Output 'SCHEDULER OK: SYSTEM, sin sesion, cada minuto, sin tareas superpuestas. Cmdlets simulados; no se instalo ninguna tarea.'
Write-Output ('Fixture aislada: ' + $fixture)
