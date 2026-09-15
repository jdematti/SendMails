param([switch]$Browser)
$ErrorActionPreference='Stop'
$project=Split-Path -Parent $PSScriptRoot
$testName='SendMails_Agility_Test_'+[guid]::NewGuid().ToString('N').Substring(0,12)
$server=$null
$created=$false
$previousDatabase=$env:SENDMAILS_TEST_DATABASE
function Run-Php([string]$File) {
    & php (Join-Path $PSScriptRoot $File)
    if ($LASTEXITCODE -ne 0) { throw ('Fallo: '+$File) }
}
Push-Location $project
try {
    & sqlcmd -S '.\SQLEXPRESS' -E -C -b -Q "CREATE DATABASE [$testName]"
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo crear la base temporal local.' }
    $created=$true
    $env:SENDMAILS_TEST_DATABASE=$testName
    Run-Php 'campaign_attachments_test.php'
    Run-Php 'integration.php'
    Run-Php 'pagination.php'
    Run-Php 'edge_cases.php'
    Run-Php 'management_lists.php'
    Run-Php 'worker_dispatch.php'
    Run-Php 'purge.php'
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'deploy.ps1')
    if ($LASTEXITCODE -ne 0) { throw 'Fallo la prueba de despliegue.' }
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'updater.ps1')
    if ($LASTEXITCODE -ne 0) { throw 'Fallo la prueba del actualizador.' }
    if ($Browser) {
        $log=Join-Path $env:TEMP ($testName+'-http')
        $phpExe=(Get-Command php).Source
        $server=Start-Process -FilePath $phpExe -ArgumentList @('-S','127.0.0.1:8765','tests/fixture_router.php') -WorkingDirectory $project -WindowStyle Hidden -RedirectStandardOutput ($log+'.out') -RedirectStandardError ($log+'.err') -PassThru
        Start-Sleep -Milliseconds 500
        if ($server.HasExited) { throw 'No se pudo iniciar el servidor de pruebas.' }
        & node (Join-Path $PSScriptRoot 'browser.cjs')
        if ($LASTEXITCODE -ne 0) { throw 'Fallo la prueba del navegador.' }
        & node (Join-Path $PSScriptRoot 'browser_attachments.cjs')
        if ($LASTEXITCODE -ne 0) { throw 'Fallo la prueba de vista previa de adjuntos.' }
        & node (Join-Path $PSScriptRoot 'browser_purge_errors.cjs')
        if ($LASTEXITCODE -ne 0) { throw 'Fallo la prueba de errores de purga.' }
        & node (Join-Path $PSScriptRoot 'browser_operations.cjs')
        if ($LASTEXITCODE -ne 0) { throw 'Fallo la prueba de control y purga.' }
    }
    Write-Output 'TODAS LAS PRUEBAS OK. Solo datos ficticios; sin transportes externos ni tareas reales.'
} finally {
    if ($server -and !$server.HasExited) { Stop-Process -Id $server.Id -Force }
    if ($created -and $testName -match '^SendMails_Agility_Test_[a-f0-9]{12}$') {
        & sqlcmd -S '.\SQLEXPRESS' -E -C -b -Q "ALTER DATABASE [$testName] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [$testName]"
        if ($LASTEXITCODE -ne 0) { Write-Warning ('No se pudo eliminar la base temporal '+$testName) }
        $tempRoot=[IO.Path]::GetFullPath($env:TEMP).TrimEnd('\')
        $testStorage=[IO.Path]::GetFullPath((Join-Path $tempRoot $testName))
        if ($testStorage.StartsWith($tempRoot+'\',[StringComparison]::OrdinalIgnoreCase) -and (Split-Path -Leaf $testStorage) -eq $testName) {
            if (Test-Path -LiteralPath $testStorage) { Remove-Item -LiteralPath $testStorage -Recurse -Force }
        } else { throw 'Ruta temporal inesperada; no se elimino.' }
    }
    $env:SENDMAILS_TEST_DATABASE=$previousDatabase
    Pop-Location
}
