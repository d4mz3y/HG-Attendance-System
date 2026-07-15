@echo off
setlocal enabledelayedexpansion

echo ========================================
echo   Hogan Guards Attendance System
echo   Starting up...
echo ========================================
echo.

REM Check if Docker is available
docker --version >nul 2>&1
if %errorlevel% equ 0 (
    echo [Docker detected] Starting full stack with Docker Compose...
    cd /d "%~dp0"
    docker compose up --build
    goto :end
)

echo [Local mode] Docker not found. Starting local stack...
echo.

REM Check PHP
php --version >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: PHP is not installed or not in PATH.
    pause
    exit /b 1
)

REM Check Node.js
node --version >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: Node.js is not installed or not in PATH.
    pause
    exit /b 1
)

REM Check npm
npm --version >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: npm is not installed or not in PATH.
    pause
    exit /b 1
)

echo [1/5] Checking environment...
if not exist ".env" (
    if exist ".env.example" (
        echo Creating .env from .env.example...
        copy /Y .env.example .env >nul
        echo.
        echo IMPORTANT: Update your database credentials in .env before continuing!
        echo Press any key to edit .env now, or Ctrl+C to abort...
        pause >nul
        notepad .env
    ) else (
        echo ERROR: .env file not found and no .env.example available.
        pause
        exit /b 1
    )
)

echo [2/5] Installing PHP dependencies...
call composer install --no-interaction --prefer-dist
if %errorlevel% neq 0 (
    echo ERROR: Composer install failed.
    pause
    exit /b 1
)

echo [3/5] Installing Node.js dependencies...
call npm install
if %errorlevel% neq 0 (
    echo ERROR: npm install failed.
    pause
    exit /b 1
)

echo [4/5] Building frontend assets...
call npm run build
if %errorlevel% neq 0 (
    echo ERROR: npm run build failed.
    pause
    exit /b 1
)

echo [5/5] Starting Laravel server...
echo.
echo Server will be available at: http://localhost:8000
echo Scan terminal: http://localhost:8000/scan
echo Admin dashboard: http://localhost:8000/dashboard
echo.
echo Default login: admin / admin123
echo Press Ctrl+C to stop the server.
echo.

php artisan serve --host=0.0.0.0 --port=8000

:end
pause
