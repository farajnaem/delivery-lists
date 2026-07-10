@echo off
chcp 65001 >nul
cd /d "%~dp0"
echo ========================================
echo   تسليم المخزن — تشغيل تلقائي
echo ========================================
echo.

:: تشغيل السيرفر إن لم يكن يعمل
powershell -NoProfile -Command "try { (Invoke-WebRequest -Uri 'http://localhost:8090/api/mobile/health' -UseBasicParsing -TimeoutSec 2).StatusCode } catch { exit 1 }" >nul 2>&1
if errorlevel 1 (
    echo تشغيل السيرفر على http://localhost:8090 ...
    start "Delivery Server" cmd /c "cd /d %~dp0.. && start-local.bat"
    timeout /t 5 /nobreak >nul
)

:: فتح مشروع أندرويد في Android Studio إن وُجد
set "STUDIO=%ProgramFiles%\Android\Android Studio\bin\studio64.exe"
if exist "%STUDIO%" (
    echo فتح Android Studio...
    start "" "%STUDIO%" "%~dp0"
) else (
    echo Android Studio غير مثبت — ثبّته من:
    echo https://developer.android.com/studio
    echo ثم شغّل هذا الملف مرة أخرى.
    start "" "%~dp0"
)

echo.
echo السيرفر: http://localhost:8090
echo من Android Studio: Run ^> Run 'app'
pause
