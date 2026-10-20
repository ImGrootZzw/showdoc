@echo off
setlocal
title Stop ShowDoc
set "PORT=4999"
if not "%1"=="" set "PORT=%1"
echo Stopping ShowDoc (port %PORT%) ...
for /f "tokens=5" %%p in ('netstat -ano ^| findstr /c:":%PORT% " ^| findstr /c:"LISTENING"') do (
    taskkill /f /pid %%p >nul 2>nul && echo Killed PID=%%p
)
rem also stop php-cgi backend (port 9001)
set "FCGI_PORT=9001"
for /f "tokens=5" %%p in ('netstat -ano ^| findstr /c:":%FCGI_PORT% " ^| findstr /c:"LISTENING"') do (
    taskkill /f /pid %%p >nul 2>nul && echo Killed php-cgi PID=%%p
)
timeout /t 1 /nobreak >nul
netstat -ano | findstr /c:":%PORT% " | findstr /c:"LISTENING" >nul 2>nul
if errorlevel 1 ( echo ShowDoc stopped ) else ( echo Stop failed, port %PORT% still in use )
pause