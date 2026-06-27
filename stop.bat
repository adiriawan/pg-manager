@echo off
:: ══════════════════════════════════════════════════════════════
::  PG Manager — Windows Stop Script
::  Jalankan: stop.bat (double-click atau dari CMD)
:: ══════════════════════════════════════════════════════════════
setlocal EnableDelayedExpansion
title PG Manager — Stop

set PORT=8765
set SCRIPT_DIR=%~dp0
set SCRIPT_DIR=%SCRIPT_DIR:~0,-1%
set INFO_FILE=%SCRIPT_DIR%\.pgmanager

echo.
echo  +==========================================+
echo  ^|        PG Manager — Stop (Windows)      ^|
echo  +==========================================+
echo.

set STOPPED=0

:: ── Coba baca PID dari .pgmanager ────────────────────────────
if exist "%INFO_FILE%" (
    for /f "tokens=1,2 delims==" %%a in (%INFO_FILE%) do (
        if "%%a"=="SERVER_PID" set SAVED_PID=%%b
    )
    if defined SAVED_PID (
        taskkill /PID %SAVED_PID% /F >nul 2>&1
        if !errorlevel!==0 (
            echo  [+] Server PID %SAVED_PID% dihentikan.
            set STOPPED=1
        )
    )
    del "%INFO_FILE%" >nul 2>&1
)

:: ── Fallback: cari proses di port 8765 ───────────────────────
for /f "tokens=5" %%p in ('netstat -ano 2^>nul ^| findstr ":%PORT% " ^| findstr "LISTENING"') do (
    taskkill /PID %%p /F >nul 2>&1
    echo  [+] Proses di port %PORT% (PID %%p) dihentikan.
    set STOPPED=1
)

if "%STOPPED%"=="0" (
    echo  [!] Tidak ada server PG Manager yang sedang berjalan.
)

echo.
echo  Untuk menjalankan kembali: double-click install.bat
echo.
pause
