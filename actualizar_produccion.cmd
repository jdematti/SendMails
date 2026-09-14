@echo off
setlocal EnableExtensions DisableDelayedExpansion
title Actualizar SendMails

rem Ejecutar una copia temporal permite actualizar este mismo archivo con Git.
if /i "%~1"=="--run" goto run
set "SM_UPDATE_ROOT=%~dp0"
set "SM_UPDATE_COPY=%TEMP%\SendMails-pull-%RANDOM%-%RANDOM%.cmd"
copy /y "%~f0" "%SM_UPDATE_COPY%" >nul
if errorlevel 1 goto copy_failed
(
    "%ComSpec%" /d /c ""%SM_UPDATE_COPY%" --run"
    if errorlevel 1 (
        del /q "%SM_UPDATE_COPY%" >nul 2>&1
        exit /b 1
    ) else (
        del /q "%SM_UPDATE_COPY%" >nul 2>&1
        exit /b 0
    )
)

:run
if not defined SM_UPDATE_ROOT goto copy_failed
pushd "%SM_UPDATE_ROOT%"
if errorlevel 1 goto copy_failed
echo.
echo Actualizando SendMails en:
echo %CD%
echo.

where git >nul 2>&1
if errorlevel 1 (
    echo ERROR: Git no esta instalado o no figura en PATH.
    goto failed
)
if exist "C:\xampp\php\php.exe" set "PATH=C:\xampp\php;%PATH%"
where php >nul 2>&1
if errorlevel 1 (
    echo ERROR: PHP no esta instalado o no figura en PATH.
    goto failed
)

if not exist ".git" (
    echo ERROR: Esta carpeta no tiene un repositorio Git.
    echo Primero hay que preparar el repositorio de produccion.
    goto failed
)
git rev-parse --verify HEAD >nul 2>&1
if errorlevel 1 goto failed
set "SM_UPDATE_BRANCH="
for /f "delims=" %%B in ('git branch --show-current') do set "SM_UPDATE_BRANCH=%%B"
if not "%SM_UPDATE_BRANCH%"=="main" (
    echo ERROR: La carpeta debe estar en la rama main.
    goto failed
)
git diff --quiet
if errorlevel 1 goto dirty
git diff --cached --quiet
if errorlevel 1 goto dirty
call :prepare_composer
if errorlevel 1 goto failed

echo [1/4] Consultando cambios remotos...
git fetch origin
if errorlevel 1 goto failed
git merge-base --is-ancestor HEAD origin/main
if errorlevel 1 (
    echo ERROR: Hay commits locales o historias divergentes.
    echo Se requiere revisar Git antes de actualizar. No se fuerza el historial.
    goto failed
)

echo [2/4] Conservando configuracion privada de facturas...
rem Extrae la clave de versiones anteriores sin imprimirla ni cambiar su valor.
php -r "$p='storage/invoice_crypto.key'; if (getenv('INVOICE_CRYPTO_KEY') !== false && getenv('INVOICE_CRYPTO_KEY') !== '') { exit(0); } if (is_file($p) && trim((string) file_get_contents($p)) !== '') { exit(0); } $s=is_file('app/InvoiceCrypto.php') ? file_get_contents('app/InvoiceCrypto.php') : ''; if (!preg_match('/private const KEY\s*=\s*\x27([^\x27]+)\x27\s*;/', (string) $s, $m)) { fwrite(STDERR, 'Falta la clave de facturas. Copia storage/invoice_crypto.key desde el respaldo privado antes de actualizar.'.PHP_EOL); exit(1); } if (!is_dir('storage') && !mkdir('storage', 0775, true)) { exit(1); } if (file_put_contents($p, $m[1], LOCK_EX) === false) { exit(1); } echo 'Clave anterior conservada en storage/invoice_crypto.key.'.PHP_EOL;"
if errorlevel 1 goto failed

echo [3/4] Actualizando main...
rem Fetch + merge --ff-only equivalen a un pull sin crear commits de merge.
git merge --ff-only origin/main
if errorlevel 1 goto failed

echo [4/4] Instalando dependencias...
if "%SM_COMPOSER_MODE%"=="local" (
    php "%SM_COMPOSER_PHAR%" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
) else (
    call composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
)
if errorlevel 1 (
    echo ERROR: El codigo se actualizo, pero fallo Composer.
    echo Corrige el error indicado y ejecuta nuevamente este archivo.
    goto failed
)

echo.
echo ACTUALIZACION COMPLETADA.
git log -1 --format="%%h %%s"
echo.
echo Las credenciales y claves locales permanecen en storage.
popd
pause
exit /b 0

:dirty
echo ERROR: Hay cambios locales sin commit. No se sobrescribieron.
git status --short
echo Revisa esos cambios antes de volver a actualizar.
goto failed

:failed
echo.
echo ACTUALIZACION NO COMPLETADA. Revisa el error mostrado arriba.
popd
pause
exit /b 1

:copy_failed
echo ERROR: No se pudo preparar el actualizador o acceder a su carpeta.
pause
exit /b 1

:prepare_composer
set "SM_COMPOSER_MODE=global"
where composer >nul 2>&1
if not errorlevel 1 exit /b 0
set "SM_COMPOSER_MODE=local"
set "SM_COMPOSER_PHAR=%CD%\storage\tools\composer.phar"
if exist "%SM_COMPOSER_PHAR%" goto check_local_composer
echo Composer no esta en PATH. Descargando una copia local verificada...
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; $client=$null; $stream=$null; $hash=$null; $tmp=$null; try { [Net.ServicePointManager]::SecurityProtocol=[Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12; $path=[IO.Path]::GetFullPath($env:SM_COMPOSER_PHAR); [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($path)) | Out-Null; $tmp=$path+'.'+[guid]::NewGuid().ToString('N')+'.tmp'; $client=New-Object Net.WebClient; $expected=$client.DownloadString('https://getcomposer.org/download/latest-stable/composer.phar.sha256').Trim(); if ($expected -notmatch '^[a-fA-F0-9]{64}$') { throw 'La suma de verificacion oficial no es valida.' }; $client.DownloadFile('https://getcomposer.org/download/latest-stable/composer.phar',$tmp); $hash=[Security.Cryptography.SHA256]::Create(); $stream=[IO.File]::OpenRead($tmp); $actual=[BitConverter]::ToString($hash.ComputeHash($stream)).Replace('-','').ToLowerInvariant(); $stream.Dispose(); $stream=$null; if ($actual -ne $expected.ToLowerInvariant()) { throw 'La descarga no coincide con la suma SHA-256 oficial. No se ejecutara.' }; [IO.File]::Move($tmp,$path); Write-Host 'Composer descargado y verificado.' } catch { Write-Host ('ERROR al preparar Composer: '+$_.Exception.Message); exit 1 } finally { if ($stream) { $stream.Dispose() }; if ($hash) { $hash.Dispose() }; if ($client) { $client.Dispose() }; if ($tmp -and [IO.File]::Exists($tmp)) { [IO.File]::Delete($tmp) } }"
if errorlevel 1 exit /b 1

:check_local_composer
php "%SM_COMPOSER_PHAR%" --version
if errorlevel 1 (
    echo ERROR: PHP no pudo ejecutar Composer local. Revisa el mensaje anterior.
    exit /b 1
)
exit /b 0
