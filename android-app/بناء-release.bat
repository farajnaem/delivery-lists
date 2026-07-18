@echo off
chcp 65001 >nul
cd /d "%~dp0"

echo === بناء APK Release لتطبيق التسليم ===
echo.

if not exist "keystore.properties" (
  echo [1/3] لا يوجد keystore.properties — سيتم إنشاؤه مع مفتاح جديد...
  if not exist "delivery-release.keystore" (
    where keytool >nul 2>&1
    if errorlevel 1 (
      echo خطأ: keytool غير موجود. ثبّت JDK 17 وأضفه إلى PATH.
      pause
      exit /b 1
    )
    echo أنشئ كلمة مرور قوية واحفظها. ستُكتب في keystore.properties فقط ^(خارج Git^).
    set /p STORE_PASS=كلمة مرور الـ keystore: 
    if "%STORE_PASS%"=="" (
      echo لم تُدخل كلمة مرور.
      pause
      exit /b 1
    )
    keytool -genkeypair -v -keystore delivery-release.keystore -alias delivery -keyalg RSA -keysize 2048 -validity 10000 -storepass "%STORE_PASS%" -keypass "%STORE_PASS%" -dname "CN=REC Delivery, OU=REC, O=REC, L=Gaza, C=PS"
    if errorlevel 1 (
      echo فشل إنشاء المفتاح.
      pause
      exit /b 1
    )
    (
      echo storeFile=delivery-release.keystore
      echo storePassword=%STORE_PASS%
      echo keyAlias=delivery
      echo keyPassword=%STORE_PASS%
    ) > keystore.properties
    echo تم إنشاء delivery-release.keystore و keystore.properties
    echo احفظ نسخة احتياطية من الملفين في مكان آمن خارج المشروع.
  ) else (
    echo وُجد delivery-release.keystore بدون keystore.properties
    echo أنشئ keystore.properties من المثال: keystore.properties.example
    pause
    exit /b 1
  )
) else (
  echo [1/3] keystore.properties موجود.
)

echo.
echo [2/3] نسخ المشروع لمسار إنجليزي ثم assembleRelease...
echo ^(مسار المجلد العربي يمنع Kotlin من الترجمة^)
set "ASCII_BUILD=C:\rec-delivery-android"
if exist "%ASCII_BUILD%" rmdir /s /q "%ASCII_BUILD%"
mkdir "%ASCII_BUILD%"
robocopy "%~dp0." "%ASCII_BUILD%" /E /XD .gradle build app\build .idea /NFL /NDL /NJH /NJS /nc /ns /np
if errorlevel 8 (
  echo فشل نسخ المشروع.
  pause
  exit /b 1
)
copy /Y "%~dp0keystore.properties" "%ASCII_BUILD%\keystore.properties" >nul
copy /Y "%~dp0delivery-release.keystore" "%ASCII_BUILD%\delivery-release.keystore" >nul

pushd "%ASCII_BUILD%"
call gradlew.bat assembleRelease --no-daemon
if errorlevel 1 (
  popd
  echo فشل البناء.
  pause
  exit /b 1
)
popd

echo.
echo [3/3] نسخ الـ APK إلى مجلد المشروع...
if not exist "%~dp0app\build\outputs\apk\release" mkdir "%~dp0app\build\outputs\apk\release"
copy /Y "%ASCII_BUILD%\app\build\outputs\apk\release\app-release.apk" "%~dp0app\build\outputs\apk\release\app-release.apk" >nul
copy /Y "%ASCII_BUILD%\app\build\outputs\apk\release\app-release.apk" "%ASCII_BUILD%\app-release.apk" >nul

echo.
echo تم البناء بنجاح.
echo الملف:
echo   %~dp0app\build\outputs\apk\release\app-release.apk
echo   %ASCII_BUILD%\app-release.apk
echo.
explorer "%ASCII_BUILD%"
pause
