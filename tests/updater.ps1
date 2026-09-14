$ErrorActionPreference='Stop'
$project=Split-Path -Parent $PSScriptRoot
$fixture=Join-Path $env:TEMP ('sendmails-updater-test-'+[guid]::NewGuid().ToString('N'))
$publisher=Join-Path $fixture 'publisher'
$target=Join-Path $fixture 'production fixture'
$remote=Join-Path $fixture 'origin.git'
$bin=Join-Path $fixture 'bin'
$previousPath=$env:PATH
$previousRoot=$env:SM_UPDATE_ROOT
function G([string[]]$Arguments) { & git -c core.autocrlf=false @Arguments 2>&1 | Out-Null; if($LASTEXITCODE -ne 0){throw 'Git fixture fallo'} }
function Check($value,$message) { if(!$value){throw $message} }
New-Item -ItemType Directory -Path $publisher,$bin -Force | Out-Null
G @('init','-q','-b','main',$publisher)
G @('-C',$publisher,'config','user.name','Fixture')
G @('-C',$publisher,'config','user.email','fixture@example.invalid')
New-Item -ItemType Directory -Path (Join-Path $publisher 'app') -Force | Out-Null
Set-Content -LiteralPath (Join-Path $publisher 'app\bootstrap.php') -Value '<?php' -Encoding ASCII
Set-Content -LiteralPath (Join-Path $publisher '.gitignore') -Value '/storage/' -Encoding ASCII
G @('-C',$publisher,'add','.')
G @('-C',$publisher,'commit','-qm','base')
G @('clone','-q','--bare',$publisher,$remote)
G @('clone','-q',$remote,$target)
G @('-C',$publisher,'remote','add','origin',$remote)
Set-Content -LiteralPath (Join-Path $publisher 'deploy_prepare.ps1') -Value @'
param([string]$ProjectDir)
Set-Content -LiteralPath (Join-Path $ProjectDir 'storage\maintenance.flag') -Value 'fixture'
Write-Output 'Preparacion simulada; sin tareas ni bases reales.'
'@ -Encoding ASCII
Set-Content -LiteralPath (Join-Path $publisher 'install_scheduled_task.ps1') -Value @'
param([int]$IntervalMinutes)
Write-Output 'Tarea simulada.'
'@ -Encoding ASCII
Set-Content -LiteralPath (Join-Path $publisher 'migrate.php') -Value @'
<?php
if (getenv('SENDMAILS_FAIL_MIGRATION') === '1') { fwrite(STDERR, "Fallo de migracion simulado\n"); exit(9); }
echo "Migracion simulada sin SQL\n";
'@ -Encoding ASCII
G @('-C',$publisher,'add','.')
G @('-C',$publisher,'commit','-qm','update')
G @('-C',$publisher,'push','-q','origin','main')
New-Item -ItemType Directory -Path (Join-Path $target 'storage') -Force | Out-Null
Set-Content -LiteralPath (Join-Path $target 'storage\db_config.json') -Value '{"fixture":true}' -Encoding ASCII
Set-Content -LiteralPath (Join-Path $target 'storage\invoice_crypto.key') -Value 'fixture-key' -Encoding ASCII
Set-Content -LiteralPath (Join-Path $bin 'composer.cmd') -Value "@echo off`r`necho Composer simulado`r`nexit /b 0`r`n" -Encoding ASCII
try {
    $env:PATH=$bin+';'+$previousPath
    $env:SM_UPDATE_ROOT=$target+'\'
    $command='""'+(Join-Path $project 'actualizar_produccion.cmd')+'" --run < nul"'
    $ErrorActionPreference='Continue'
    $output=& $env:ComSpec /d /c $command 2>&1
    $code=$LASTEXITCODE
    $ErrorActionPreference='Stop'
    Check ($code -eq 0) ('El actualizador fallo: '+($output -join "`n"))
    Check (($output -join "`n").Contains('ACTUALIZACION COMPLETADA.')) 'Falta confirmacion de exito'
    Check (!(Test-Path -LiteralPath (Join-Path $target 'storage\maintenance.flag'))) 'No retiro mantenimiento'
    Check ((Get-Content -LiteralPath (Join-Path $target 'storage\db_config.json') -Raw).Trim() -eq '{"fixture":true}') 'Modifico configuracion'
    $env:SENDMAILS_FAIL_MIGRATION='1'
    $ErrorActionPreference='Continue'
    $output=& $env:ComSpec /d /c $command 2>&1
    $code=$LASTEXITCODE
    $ErrorActionPreference='Stop'
    Check ($code -ne 0) 'Una migracion fallida no debe reportar exito'
    Check (Test-Path -LiteralPath (Join-Path $target 'storage\maintenance.flag')) 'Debe mantener el sitio pausado si falla la migracion'
    Check (!(($output -join "`n").Contains('ACTUALIZACION COMPLETADA.'))) 'Falso mensaje de exito'
    Write-Output 'UPDATER OK: CMD real, fast-forward, migracion y tarea simuladas, preservacion privada, salida de error y mantenimiento tras fallo.'
} finally {
    $env:PATH=$previousPath
    $env:SM_UPDATE_ROOT=$previousRoot
    Remove-Item Env:SENDMAILS_FAIL_MIGRATION -ErrorAction SilentlyContinue
}
