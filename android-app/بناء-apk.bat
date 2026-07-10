@echo off
chcp 65001 >nul
cd /d "%~dp0"
echo.
echo  ========================================
echo    تنزيل وتثبيت Android SDK
echo    مع عرض التقدم والحجم
echo  ========================================
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-sdk.ps1"
if errorlevel 1 (
    echo.
    echo فشل التثبيت — راجع الرسائل أعلاه.
    pause
    exit /b 1
)
echo.
echo ========================================
echo   بناء APK...
echo ========================================
call gradlew.bat assembleDebug
if errorlevel 1 (
    echo فشل البناء.
    pause
    exit /b 1
)
echo.
echo ========================================
echo   تم البناء بنجاح!
echo   الملف: app\build\outputs\apk\debug\app-debug.apk
echo ========================================
explorer "app\build\outputs\apk\debug"
pause
