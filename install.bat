@echo off
:: ══════════════════════════════════════════════════════════════
::  PG Manager — Windows Installer
::  Jalankan: install.bat (double-click atau dari CMD)
:: ══════════════════════════════════════════════════════════════
setlocal EnableDelayedExpansion
title PG Manager Installer

set PORT=8765
set SCRIPT_DIR=%~dp0
set SCRIPT_DIR=%SCRIPT_DIR:~0,-1%
set LOG_FILE=%TEMP%\pgmanager.log
set INFO_FILE=%SCRIPT_DIR%\.pgmanager

echo.
echo  +==========================================+
echo  ^|        PG Manager Installer (Windows)   ^|
echo  +==========================================+
echo.

:: ══════════════════════════════════════════════════════════════
::  1. Cari PHP
:: ══════════════════════════════════════════════════════════════
echo [*] Mencari PHP...

set PHP_BIN=
set PHP_SEARCH_PATHS=^
    php ^
    C:\xampp\php\php.exe ^
    C:\laragon\bin\php\php8.2.0\php.exe ^
    C:\laragon\bin\php\php8.1.0\php.exe ^
    C:\php\php.exe ^
    C:\php8\php.exe ^
    "%ProgramFiles%\PHP\php.exe" ^
    "%ProgramFiles(x86)%\PHP\php.exe"

:: Cek PATH dulu
where php >nul 2>&1
if %errorlevel%==0 (
    for /f "tokens=*" %%i in ('where php 2^>nul') do (
        set PHP_BIN=%%i
        goto :php_found
    )
)

:: Cek lokasi umum
for %%p in (
    "C:\xampp\php\php.exe"
    "C:\laragon\bin\php\php8.2.0\php.exe"
    "C:\laragon\bin\php\php8.1.0\php.exe"
    "C:\laragon\bin\php\php8.0.0\php.exe"
    "C:\php\php.exe"
    "C:\php8\php.exe"
) do (
    if exist %%p (
        set PHP_BIN=%%~p
        goto :php_found
    )
)

:: Cari di Program Files
for /d %%d in ("%ProgramFiles%\PHP*") do (
    if exist "%%d\php.exe" (
        set PHP_BIN=%%d\php.exe
        goto :php_found
    )
)

echo.
echo  [!] PHP tidak ditemukan!
echo.
echo  Pilihan instalasi PHP untuk Windows:
echo.
echo  1. XAMPP (Rekomendasi - paling mudah):
echo     Download: https://www.apachefriends.org/download.html
echo     Install, lalu jalankan ulang install.bat
echo.
echo  2. Laragon (Alternatif ringan):
echo     Download: https://laragon.org/download/
echo.
echo  3. PHP Standalone:
echo     Download: https://windows.php.net/download/
echo     Extract ke C:\php\ lalu tambahkan ke PATH
echo.
pause
exit /b 1

:php_found
echo  [+] PHP ditemukan: %PHP_BIN%

:: Cek versi PHP >= 8
"%PHP_BIN%" -r "exit(PHP_MAJOR_VERSION >= 8 ? 0 : 1);" >nul 2>&1
if %errorlevel% neq 0 (
    echo  [!] PHP versi 8+ diperlukan. Versi saat ini terlalu lama.
    echo      Silakan update PHP lalu jalankan ulang.
    pause
    exit /b 1
)

for /f "tokens=*" %%v in ('"%PHP_BIN%" -r "echo phpversion();"') do set PHP_VER=%%v
echo  [+] PHP %PHP_VER% OK

:: ── Cek ekstensi pdo_pgsql ────────────────────────────────────
"%PHP_BIN%" -m 2>nul | findstr /i "pdo_pgsql" >nul
if %errorlevel% neq 0 (
    echo.
    echo  [!] Ekstensi pdo_pgsql tidak aktif!
    echo.
    echo  Cara mengaktifkan:
    echo  1. Buka file php.ini (biasanya di folder PHP Anda)
    echo     Cari dengan: "%PHP_BIN%" --ini
    echo  2. Cari baris:  ;extension=pdo_pgsql
    echo  3. Hapus tanda titik koma di depannya:  extension=pdo_pgsql
    echo  4. Simpan, lalu jalankan ulang install.bat
    echo.
    echo  Untuk XAMPP: buka XAMPP Control Panel ^> Config ^> php.ini
    echo.
    pause
    exit /b 1
)
echo  [+] Ekstensi pdo_pgsql aktif

:: ══════════════════════════════════════════════════════════════
::  2. Cari pg_dump dan psql
:: ══════════════════════════════════════════════════════════════
echo.
echo [*] Mencari PostgreSQL...

set PG_DUMP=
set PSQL=
set PG_RESTORE=

:: Cek PATH
where pg_dump >nul 2>&1
if %errorlevel%==0 (
    for /f "tokens=*" %%i in ('where pg_dump 2^>nul') do (
        set PG_DUMP=%%i
        goto :pg_found
    )
)

:: Cek lokasi instalasi PostgreSQL umum
for %%v in (17 16 15 14 13 12) do (
    for %%p in (
        "C:\Program Files\PostgreSQL\%%v\bin\pg_dump.exe"
        "C:\PostgreSQL\%%v\bin\pg_dump.exe"
    ) do (
        if exist %%p (
            set PG_DUMP=%%~p
            goto :pg_found
        )
    )
)

:: Cari otomatis di Program Files
for /d %%d in ("%ProgramFiles%\PostgreSQL\*") do (
    if exist "%%d\bin\pg_dump.exe" (
        set PG_DUMP=%%d\bin\pg_dump.exe
        goto :pg_found
    )
)

echo.
echo  [!] PostgreSQL (pg_dump) tidak ditemukan!
echo.
echo  Download PostgreSQL untuk Windows:
echo  https://www.enterprisedb.com/downloads/postgres-postgresql-installer
echo.
echo  Setelah install, jalankan ulang install.bat
echo.
pause
exit /b 1

:pg_found
:: Derive psql dan pg_restore dari pg_dump path
set PG_DIR=%PG_DUMP:pg_dump.exe=%
set PSQL=%PG_DIR%psql.exe
set PG_RESTORE=%PG_DIR%pg_restore.exe

for /f "tokens=3" %%v in ('"%PG_DUMP%" --version 2^>nul') do set PG_VER=%%v
echo  [+] pg_dump %PG_VER%: %PG_DUMP%
echo  [+] psql: %PSQL%

:: ══════════════════════════════════════════════════════════════
::  3. Update path di backup.php
:: ══════════════════════════════════════════════════════════════
set BACKUP_FILE=%SCRIPT_DIR%\pages\backup.php
if exist "%BACKUP_FILE%" (
    :: Gunakan PowerShell untuk replace string (lebih andal dari sed di Windows)
    set PG_DUMP_PS=%PG_DUMP:\=\\%
    set PG_RESTORE_PS=%PG_RESTORE:\=\\%
    set PSQL_PS=%PSQL:\=\\%

    powershell -NoProfile -Command ^
        "$f='%BACKUP_FILE%'; $c=[IO.File]::ReadAllText($f);" ^
        "$c=$c -replace \"define\('PG_DUMP',.*\)\",\"define('PG_DUMP',    '%PG_DUMP_PS%');\";" ^
        "$c=$c -replace \"define\('PG_RESTORE',.*\)\",\"define('PG_RESTORE', '%PG_RESTORE_PS%');\";" ^
        "$c=$c -replace \"define\('PSQL',.*\)\",\"define('PSQL',       '%PSQL_PS%');\";" ^
        "[IO.File]::WriteAllText($f,$c);" >nul 2>&1

    echo  [+] Path pg_dump diperbarui di backup.php
)

:: ══════════════════════════════════════════════════════════════
::  4. Buat folder backups
:: ══════════════════════════════════════════════════════════════
if not exist "%SCRIPT_DIR%\backups" mkdir "%SCRIPT_DIR%\backups"
echo  [+] Folder backups siap

:: ══════════════════════════════════════════════════════════════
::  5. Matikan server lama di port yang sama
:: ══════════════════════════════════════════════════════════════
echo.
echo [*] Mengecek port %PORT%...
for /f "tokens=5" %%p in ('netstat -ano 2^>nul ^| findstr ":%PORT% " ^| findstr "LISTENING"') do (
    echo  [!] Port %PORT% dipakai PID %%p, menghentikan...
    taskkill /PID %%p /F >nul 2>&1
    timeout /t 1 /nobreak >nul
)

:: ══════════════════════════════════════════════════════════════
::  6. Jalankan server PHP
:: ══════════════════════════════════════════════════════════════
echo.
echo [*] Menjalankan server PHP di port %PORT%...

:: Jalankan di background dengan PowerShell
powershell -NoProfile -WindowStyle Hidden -Command ^
    "Start-Process -FilePath '%PHP_BIN%' -ArgumentList '-S','localhost:%PORT%','-t','%SCRIPT_DIR%' -RedirectStandardOutput '%LOG_FILE%' -RedirectStandardError '%LOG_FILE%' -WindowStyle Hidden -PassThru | Select-Object -ExpandProperty Id | Out-File '%TEMP%\pgmanager.pid' -Encoding ASCII"

timeout /t 2 /nobreak >nul

:: Cek apakah server berjalan
for /f "tokens=*" %%p in ('type "%TEMP%\pgmanager.pid" 2^>nul') do set SERVER_PID=%%p

netstat -ano 2>nul | findstr ":%PORT% " | findstr "LISTENING" >nul
if %errorlevel%==0 (
    echo  [+] Server berjalan di http://localhost:%PORT%  (PID: %SERVER_PID%)
) else (
    echo  [!] Server gagal dijalankan. Cek log: %LOG_FILE%
    type "%LOG_FILE%" 2>nul
    pause
    exit /b 1
)

:: ══════════════════════════════════════════════════════════════
::  7. Simpan info ke .pgmanager
:: ══════════════════════════════════════════════════════════════
(
    echo PHP_BIN=%PHP_BIN%
    echo PG_DUMP=%PG_DUMP%
    echo PSQL=%PSQL%
    echo PORT=%PORT%
    echo SERVER_PID=%SERVER_PID%
    echo INSTALL_DATE=%DATE% %TIME%
) > "%INFO_FILE%"

:: ══════════════════════════════════════════════════════════════
::  8. Buka browser
:: ══════════════════════════════════════════════════════════════
echo.
echo  +================================================+
echo  ^|  PG Manager siap digunakan!                   ^|
echo  ^|                                               ^|
echo  ^|  Buka browser ke: http://localhost:%PORT%       ^|
echo  ^|                                               ^|
echo  ^|  Untuk menghentikan server:                   ^|
echo  ^|  double-click stop.bat                        ^|
echo  +================================================+
echo.

start "" "http://localhost:%PORT%"

pause
