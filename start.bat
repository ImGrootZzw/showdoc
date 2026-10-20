@echo off
setlocal enabledelayedexpansion
title ShowDoc One-Click Start
cd /d "%~dp0"

echo ==============================================
echo    ShowDoc v3.9.2  One-Click Start
echo ==============================================

rem ---------- 1. Find PHP ----------
set "PHP_EXE="
for /f "delims=" %%i in ('where php 2^>nul') do set "PHP_EXE=%%i"
if not defined PHP_EXE (
    if exist "%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe" (
        set "PHP_EXE=%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
    )
)
if not defined PHP_EXE (
    for /f "delims=" %%f in ('dir /b /s "%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.*\php.exe" 2^>nul') do set "PHP_EXE=%%f"
)
if not defined PHP_EXE (
    echo [1/6] PHP not found, installing PHP 8.4 automatically ...
    winget install PHP.PHP.8.4 -h --accept-package-agreements --accept-source-agreements --disable-interactivity >nul 2>nul
    if exist "%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe" (
        set "PHP_EXE=%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
    ) else (
        echo [ERROR] PHP install failed. Open a new terminal and run this script again.
        pause
        exit /b 1
    )
)
echo [1/6] PHP ready: !PHP_EXE!

rem ---------- 2. Ensure php.ini and extensions ----------
for %%i in ("!PHP_EXE!") do set "PHP_DIR=%%~dpi"
rem Always run the helper: it generates php.ini if missing, otherwise appends missing upload limits only
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\make-php-ini.ps1" -PhpDir "!PHP_DIR!"
"!PHP_EXE!" -m | findstr /i "pdo_sqlite" >nul
if errorlevel 1 (
    echo [ERROR] pdo_sqlite extension not loaded. Check "!PHP_DIR!php.ini"
    pause
    exit /b 1
)
echo [2/6] PHP extensions OK (pdo_sqlite / gd / mbstring / openssl)

rem ---------- 3. Init install state ----------
if not exist "install\install.lock" (
    echo [3/6] First run, initializing install state, SQLite auto-create tables ...
    echo https://www.showdoc.com.cn/ > install\install.lock
    if exist "server\app\Runtime" rmdir /s /q "server\app\Runtime" >nul 2>nul
)
echo [3/6] Install state OK

rem ---------- 4. Find php-cgi and Caddy ----------
set "PHP_CGI=!PHP_EXE:php.exe=php-cgi.exe!"
if not exist "!PHP_CGI!" (
    echo [4/6] php-cgi.exe not found next to php.exe, searching...
    for /f "delims=" %%f in ('dir /b /s "!PHP_DIR!php-cgi.exe" 2^>nul') do set "PHP_CGI=%%f"
)
if not defined PHP_CGI (
    echo [ERROR] php-cgi.exe not found. It ships with PHP for Windows.
    pause
    exit /b 1
)
echo [4/6] php-cgi ready: !PHP_CGI!

set "CADDY_EXE="
where caddy >nul 2>nul && set "CADDY_EXE=caddy"
if not defined CADDY_EXE (
    if exist "%LOCALAPPDATA%\Microsoft\WinGet\Packages\CaddyServer.Caddy_Microsoft.Winget.Source_8wekyb3d8bbwe\caddy.exe" (
        set "CADDY_EXE=%LOCALAPPDATA%\Microsoft\WinGet\Packages\CaddyServer.Caddy_Microsoft.Winget.Source_8wekyb3d8bbwe\caddy.exe"
    )
)
if not defined CADDY_EXE (
    echo [5/6] Caddy not found, installing Caddy automatically ...
    winget install CaddyServer.Caddy -h --accept-package-agreements --accept-source-agreements --disable-interactivity >nul 2>nul
    if exist "%LOCALAPPDATA%\Microsoft\WinGet\Packages\CaddyServer.Caddy_Microsoft.Winget.Source_8wekyb3d8bbwe\caddy.exe" (
        set "CADDY_EXE=%LOCALAPPDATA%\Microsoft\WinGet\Packages\CaddyServer.Caddy_Microsoft.Winget.Source_8wekyb3d8bbwe\caddy.exe"
    ) else (
        echo [ERROR] Caddy install failed. Install it manually: winget install CaddyServer.Caddy
        pause
        exit /b 1
    )
)
echo [5/6] Caddy ready: !CADDY_EXE!

rem ---------- 5. Start server (Caddy + php-cgi) ----------
set "PORT=4999"
if not "%1"=="" set "PORT=%1"
set "FCGI_PORT=9001"
set "SHOWDOC_PORT=!PORT!"
set "SHOWDOC_FCGI_PORT=!FCGI_PORT!"
set "SHOWDOC_ROOT=%~dp0"
rem kill any old instances on target ports first (prevents duplicate caddy/php-cgi)
for /f "tokens=5" %%p in ('netstat -ano ^| findstr /c:":!PORT! " ^| findstr /c:"LISTENING"') do taskkill /f /pid %%p >nul 2>nul
for /f "tokens=5" %%p in ('netstat -ano ^| findstr /c:":!FCGI_PORT! " ^| findstr /c:"LISTENING"') do taskkill /f /pid %%p >nul 2>nul
rem Caddyfile: trailing backslash in path would escape the newline, trim it
if "%SHOWDOC_ROOT:~-1%"=="\" set "SHOWDOC_ROOT=%SHOWDOC_ROOT:~0,-1%"
echo [6/6] Starting ShowDoc at http://127.0.0.1:!PORT!/ ...

rem Start php-cgi (FastCGI backend, hidden window)
set "PHP_FCGI_MAX_REQUESTS=0"
start "ShowDoc php-cgi" /min "!PHP_CGI!" -b 127.0.0.1:!FCGI_PORT! -c "!PHP_DIR!php.ini"

rem Start Caddy (front server, shows access log)
start "ShowDoc Caddy" /d "%~dp0" cmd /k ""!CADDY_EXE!" run --config "%~dp0Caddyfile""

rem wait for port
ping -n 2 127.0.0.1 >nul
for /l %%i in (1,1,30) do (
    netstat -ano | findstr /c:":!PORT! " ^| findstr /c:"LISTENING" >nul 2>nul
    if not errorlevel 1 goto :ready
    ping -n 2 127.0.0.1 >nul
)
echo [WARN] Server start timeout. Check the "ShowDoc Caddy" window log.
goto :done

:ready
echo.
echo ==============================================
echo    Started OK! Browser opened.
echo    URL : http://127.0.0.1:!PORT!/
echo    User : showdoc / 123456
echo    Stop : run stop.bat or close the two server windows
echo ==============================================
start "" "http://127.0.0.1:!PORT!/"

:done
pause