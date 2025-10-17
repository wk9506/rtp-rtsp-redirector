@echo off
chcp 65001 >nul

REM PHP middleware startup script

REM Env
set PHP_HOST=0.0.0.0
set PHP_PORT=8888
set DOCUMENT_ROOT=%~dp0

REM Detect local PHP binary (portable)
set PHP_BIN=php
if exist "%~dp0php\php.exe" (
    set PHP_BIN="%~dp0php\php.exe"
)

REM Check PHP
%PHP_BIN% -v >nul 2>nul
if %errorlevel% neq 0 (
    echo ERROR: PHP not found. Place php.exe under %~dp0php\ or add PHP to PATH.
    echo Example: rtp-rstp\php\php.exe
    pause
    exit /b 1
)

REM Info
echo ==================================================
echo PHP Middleware Startup
echo Listen: %PHP_HOST%:%PHP_PORT%
echo Docroot: %DOCUMENT_ROOT%
echo PHP BIN: %PHP_BIN%
echo ==================================================
echo Starting PHP built-in server...
echo Press Ctrl+C to stop

REM Start server
%PHP_BIN% -S %PHP_HOST%:%PHP_PORT% -t "%DOCUMENT_ROOT%" index.php

pause