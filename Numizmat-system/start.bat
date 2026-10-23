@echo off
chcp 65001 >nul

set PHP_BIN=""
if exist "%~dp0php\php.exe" set PHP_BIN="%~dp0php\php.exe"
if %PHP_BIN%=="" if exist "d:\xampp\php\php.exe" set PHP_BIN="d:\xampp\php\php.exe"
if %PHP_BIN%=="" if exist "c:\xampp\php\php.exe" set PHP_BIN="c:\xampp\php\php.exe"
if %PHP_BIN%=="" if exist "C:\Program Files\PHP\php.exe" set PHP_BIN="C:\Program Files\PHP\php.exe"

if %PHP_BIN%=="" (
    where php >nul 2>&1
    if %errorlevel% equ 0 set PHP_BIN=php
)

cd /d "%~dp0"

start http://localhost:8000

%PHP_BIN% -S localhost:8000 -t public