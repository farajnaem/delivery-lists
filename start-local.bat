@echo off
chcp 65001 >nul
cd /d "%~dp0"
if not exist .env copy .env.example .env
if not exist vendor\composer\autoload_real.php (
    echo Installing dependencies...
    if exist composer.phar (
        php composer.phar install --no-interaction
    ) else (
        php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
        php composer-setup.php --quiet
        del composer-setup.php
        php composer.phar install --no-interaction
    )
)
php database\install.php
echo.
echo Starting server at http://localhost:8090
php -S localhost:8090 -t public public/router.php
