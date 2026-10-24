@echo off
chcp 65001 >nul
title Numizmatyka NBP - Uruchamianie

set PHP_BIN=""
if exist "%~dp0php\php.exe" set PHP_BIN="%~dp0php\php.exe"
if %PHP_BIN%=="" if exist "d:\xampp\php\php.exe" set PHP_BIN="d:\xampp\php\php.exe"
if %PHP_BIN%=="" if exist "c:\xampp\php\php.exe" set PHP_BIN="c:\xampp\php\php.exe"
if %PHP_BIN%=="" if exist "C:\Program Files\PHP\php.exe" set PHP_BIN="C:\Program Files\PHP\php.exe"

if %PHP_BIN%=="" (
    where php >nul 2>&1
    if %errorlevel% equ 0 set PHP_BIN=php
)

netstat -ano | findstr :8000 | findstr LISTENING >nul 2>&1
if %errorlevel% neq 0 (
    echo Uruchamianie serwera na porcie 8000...
    start /b "" %PHP_BIN% -S localhost:8000 -t "%~dp0public" >nul 2>&1
    timeout /t 2 /nobreak >nul
)

start http://localhost:8000
exit