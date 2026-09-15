param([int]$Minutes=30)
$ErrorActionPreference='Stop'
$project=Split-Path -Parent $PSScriptRoot
$testName='SendMails_Agility_Test_'+[guid]::NewGuid().ToString('N').Substring(0,12)
$testStorage=[IO.Path]::GetFullPath((Join-Path $env:TEMP $testName))
$artifacts=Join-Path $project 'storage\development-artifacts\usability-20260915'
$server=$null
$created=$false
$previousDatabase=$env:SENDMAILS_TEST_DATABASE
$noTransport='mail,fsockopen,pfsockopen,stream_socket_client,curl_exec,exec,shell_exec,system,passthru,proc_open,popen'
Push-Location $project
try {
    & sqlcmd -S '.\SQLEXPRESS' -E -C -b -Q "CREATE DATABASE [$testName]"
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo crear la base temporal.' }
    $created=$true
    $env:SENDMAILS_TEST_DATABASE=$testName
    foreach ($script in @('integration.php','edge_cases.php','smoke_setup.php')) {
        & php -d ('disable_functions='+$noTransport) (Join-Path $PSScriptRoot $script)
        if ($LASTEXITCODE -ne 0) { throw ('Fallo: '+$script) }
    }
    New-Item -ItemType Directory -Force -Path $artifacts | Out-Null
    $server=Start-Process -FilePath (Get-Command php).Source -ArgumentList @('-d',('disable_functions='+$noTransport),'-d','allow_url_fopen=0','-S','127.0.0.1:8766','tests/smoke_router.php') -WorkingDirectory $project -WindowStyle Hidden -RedirectStandardOutput (Join-Path $artifacts 'server.out') -RedirectStandardError (Join-Path $artifacts 'server.err') -PassThru
    Start-Sleep -Milliseconds 500
    if ($server.HasExited) { throw 'No se inicio el servidor visual.' }
    @{database=$testName;storage=$testStorage;url='http://127.0.0.1:8766/';pid=$server.Id} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $artifacts 'fixture.json')
    Write-Output 'REVISION VISUAL LISTA: http://127.0.0.1:8766/ (solo fixtures, transportes bloqueados).'
    $deadline=(Get-Date).AddMinutes([Math]::Max(1,[Math]::Min(60,$Minutes)))
    while ((Get-Date) -lt $deadline -and !(Test-Path -LiteralPath (Join-Path $testStorage 'review.stop'))) { Start-Sleep -Seconds 1 }
} finally {
    if ($server -and !$server.HasExited) { Stop-Process -Id $server.Id -Force }
    if ($created -and $testName -match '^SendMails_Agility_Test_[a-f0-9]{12}$') {
        & sqlcmd -S '.\SQLEXPRESS' -E -C -b -Q "ALTER DATABASE [$testName] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [$testName]"
        if ($LASTEXITCODE -ne 0) { Write-Warning ('No se pudo eliminar '+$testName) }
        $tempRoot=[IO.Path]::GetFullPath($env:TEMP).TrimEnd('\')
        if (!$testStorage.StartsWith($tempRoot+'\',[StringComparison]::OrdinalIgnoreCase) -or (Split-Path -Leaf $testStorage) -ne $testName) { throw 'Ruta inesperada; no se elimino.' }
        if (Test-Path -LiteralPath $testStorage) { Remove-Item -LiteralPath $testStorage -Recurse -Force }
    }
    $env:SENDMAILS_TEST_DATABASE=$previousDatabase
    Pop-Location
}
