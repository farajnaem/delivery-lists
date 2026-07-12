<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

function env(string $key, mixed $default = null): mixed
{
    static $loaded = false;
    if (!$loaded) {
        $envFile = dirname(__DIR__) . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $_ENV[trim($k)] = trim($v, " \t\"'");
            }
        }
        $loaded = true;
    }

    return $_ENV[$key] ?? getenv($key) ?: $default;
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
