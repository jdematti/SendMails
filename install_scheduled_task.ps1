param(
    [string]$TaskName = "SendMails Worker",
    [int]$IntervalMinutes = 15,
    [int]$Limit = 1000,
    [string]$PhpExe = "C:\xampp\php\php.exe"
)

$ErrorActionPreference = "Stop"

$ProjectDir = Split-Path -Parent $PSCommandPath
$RunnerPath = Join-Path $ProjectDir "run_worker.ps1"

if (!(Test-Path $RunnerPath)) {
    throw "No se encontro run_worker.ps1 en $RunnerPath"
}

$powershellExe = Join-Path $env:SystemRoot "System32\WindowsPowerShell\v1.0\powershell.exe"
$argument = '-NoProfile -ExecutionPolicy Bypass -File "' + $RunnerPath + '" -Limit ' + $Limit + ' -PhpExe "' + $PhpExe + '"'

$action = New-ScheduledTaskAction -Execute $powershellExe -Argument $argument -WorkingDirectory $ProjectDir
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes) -RepetitionDuration (New-TimeSpan -Days 3650)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Description "Procesa la cola pendiente de SendMails de forma desatendida." `
    -Force | Out-Null

Write-Output "Tarea '$TaskName' creada/actualizada."
Write-Output "Intervalo: cada $IntervalMinutes minutos."
Write-Output "Limite por corrida: $Limit."
Write-Output "Script: $RunnerPath"
