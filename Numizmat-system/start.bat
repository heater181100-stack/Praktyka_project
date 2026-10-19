@echo off
chcp 65001 >nul
title Numizmatyka NBP - Uruchamianie

echo ========================================================
echo Запуск системы Numizmatyka NBP
echo ========================================================

:: 1. Поиск интерпретатора PHP (в папке проекта, в XAMPP или в системе)
set PHP_BIN=""
if exist "%~dp0php\php.exe" set PHP_BIN="%~dp0php\php.exe"
if %PHP_BIN%=="" if exist "d:\xampp\php\php.exe" set PHP_BIN="d:\xampp\php\php.exe"
if %PHP_BIN%=="" if exist "c:\xampp\php\php.exe" set PHP_BIN="c:\xampp\php\php.exe"
if %PHP_BIN%=="" if exist "C:\Program Files\PHP\php.exe" set PHP_BIN="C:\Program Files\PHP\php.exe"

if %PHP_BIN%=="" (
    where php >nul 2>&1
    if %errorlevel% equ 0 set PHP_BIN=php
)

if %PHP_BIN%=="" (
    echo [ОШИБКА] Интерпретатор PHP не найден!
    echo Убедитесь, что XAMPP установлен на диске D: или C:.
    pause
    exit /b
)

:: 2. Фоновый запуск локального веб-сервера на порту 8000
netstat -ano | findstr :8000 | findstr LISTENING >nul 2>&1
if %errorlevel% neq 0 (
    echo [1/2] Фоновый запуск веб-сервера на http://localhost:8000...
    start /b "" %PHP_BIN% -S localhost:8000 -t "%~dp0public" >nul 2>&1
    timeout /t 2 /nobreak >nul
) else (
    echo [1/2] Сервер на порту 8000 уже работает.
)

:: 3. Открытие системы в обычном браузере
echo [2/2] Открытие страницы в браузере...
start http://localhost:8000

echo ========================================================
echo Система запущена! Страница открыта: http://localhost:8000
echo ========================================================
timeout /t 2 >nul
exit