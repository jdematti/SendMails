@echo off
setlocal EnableExtensions DisableDelayedExpansion
title Diagnostico Git - SendMails
set "GIT_OPTIONAL_LOCKS=0"
pushd "%~dp0"
if errorlevel 1 goto failed
echo ==== Carpeta ====
echo %CD%
echo.
where git >nul 2>&1
if errorlevel 1 (
    echo ERROR: Git no esta disponible en PATH.
    popd
    goto failed
)
echo ==== Tipo de .git ====
if exist ".git\" (
    echo Directorio .git presente.
) else (
    if exist ".git" (echo Archivo .git presente.) else (echo No existe .git.)
)
echo.
echo ==== Raiz reconocida por Git ====
git --no-pager rev-parse --show-toplevel
if errorlevel 1 (
    echo Git no reconoce un repositorio valido. No se cambio nada.
    popd
    goto failed
)
echo.
echo ==== Estado de archivos versionados ====
git --no-pager status --short --branch --untracked-files=no
echo.
echo ==== Ramas y seguimiento ====
git --no-pager branch -vv
echo.
echo ==== Nombres de remotos ====
git remote
echo.
echo ==== Ultimos commits ====
git --no-pager log -3 --oneline
echo.
echo ==== Referencia local de origin/main ====
git --no-pager show-ref --verify refs/remotes/origin/main
echo.
echo ==== Cantidad de archivos versionados ====
git ls-files | find /c /v ""
echo.
echo Diagnostico terminado. Copia este resultado para revisarlo.
echo No se modificaron archivos, configuracion ni historial.
popd
pause
exit /b 0

:failed
echo.
echo Copia el resultado mostrado para revisarlo.
pause
exit /b 1
