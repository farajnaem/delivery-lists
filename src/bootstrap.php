<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

function env(string $key, mixed $default = null): mixed
{
    static $fileEnv = null;
    if ($fileEnv === null) {
        $fileEnv = [];
        $envFile = dirname(__DIR__) . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $fileEnv[trim($k)] = trim($v, " \t\"'");
            }
        }
    }

    // Coolify/Docker: المتغيرات الحقيقية في البيئة تتقدم على ملف .env الفارغ
    $fromProcess = getenv($key);
    if ($fromProcess !== false && $fromProcess !== '') {
        return $fromProcess;
    }

    if (array_key_exists($key, $fileEnv) && $fileEnv[$key] !== '') {
        return $fileEnv[$key];
    }

    if ($fromProcess !== false) {
        return $fromProcess;
    }

    return $fileEnv[$key] ?? $default;
}

function config(string $key, mixed $default = null): mixed
{
    static $cfg;
    $cfg ??= require dirname(__DIR__) . '/config/config.php';
    return $cfg[$key] ?? $default;
}

require_once __DIR__ . '/helpers.php';

// Composer PSR-4 autoload for App\

date_default_timezone_set(config('timezone', 'Asia/Riyadh'));

$sessionLifetime = max(3600, (int) env('SESSION_LIFETIME', 28800));
ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
