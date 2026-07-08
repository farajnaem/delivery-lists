@echo off
chcp 65001 >nul
title كشوفات التسليم — تشغيل أوفلاين
cd /d "%~dp0"

echo ========================================
echo   كشوفات التسليم — وضع أوفلاين محلي
echo   لا يحتاج إنترنت بعد تثبيت المتطلبات
echo ========================================
echo.

if not exist .env (
    echo إنشاء ملف .env من القالب...
    copy .env.example .env >nul
)

REM تأكد من SQLite للعمل المحلي بدون سيرفر خارجي
findstr /B /C:"APP_URL=http://localhost:8090" .env >nul 2>&1
if errorlevel 1 (
    echo APP_URL=http://localhost:8090>>.env
)

if not exist vendor\composer\autoload_real.php (
    echo.
    echo [تحذير] مجلد vendor غير موجود — يحتاج إنترنت مرة واحدة لتشغيل:
    echo   start-local.bat
    echo.
    pause
    exit /b 1
)

echo تهيئة قاعدة البيانات المحلية...
php database\install.php
if errorlevel 1 (
    echo فشل تهيئة قاعدة البيانات — تأكد من تثبيت PHP.
    pause
    exit /b 1
)

echo.
echo التشغيل على: http://localhost:8090
echo من الجوال على نفس الشبكة: http://[IP-الجهاز]:8090
echo.
echo لإيقاف الخادم: أغلق هذه النافذة أو اضغط Ctrl+C
echo.

start "" "http://localhost:8090"
php -S 0.0.0.0:8090 -t public public/router.php
