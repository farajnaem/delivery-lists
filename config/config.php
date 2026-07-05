<?php

declare(strict_types=1);

if (!function_exists('parseDatabaseUrl')) {
    function parseDatabaseUrl(?string $url): ?array
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);
        if (!preg_match('#^mysql(?:\+mysqli)?://#i', $url)) {
            return null;
        }

        $remainder = preg_replace('#^mysql(?:\+mysqli)?://#i', '', $url);
        $atPos = strrpos($remainder, '@');
        if ($atPos === false) {
            return null;
        }

        $userinfo = substr($remainder, 0, $atPos);
        $hostpart = substr($remainder, $atPos + 1);
        $colonPos = strpos($userinfo, ':');
        if ($colonPos === false) {
            $user = rawurldecode($userinfo);
            $pass = '';
        } else {
            $user = rawurldecode(substr($userinfo, 0, $colonPos));
            $pass = rawurldecode(substr($userinfo, $colonPos + 1));
        }

        if (!preg_match('#^([^:/]+)(?::(\d+))?/([^?]+)#', $hostpart, $matches)) {
            return null;
        }

        $database = rawurldecode($matches[3]);
        if ($database === '') {
            $database = 'default';
        }

        return [
            'driver' => 'mysql',
            'host' => $matches[1],
            'port' => isset($matches[2]) ? (int) $matches[2] : 3306,
            'name' => $database,
            'user' => $user,
            'pass' => $pass,
        ];
    }
}

if (!function_exists('resolveFromMysqlEnv')) {
    function resolveFromMysqlEnv(): ?array
    {
        $host = env('MYSQL_HOST') ?? env('MYSQLHOST');
        if ($host === null || $host === '') {
            return null;
        }

        return [
            'driver' => 'mysql',
            'host' => $host,
            'port' => (int) (env('MYSQL_PORT') ?? env('MYSQLPORT') ?? '3306'),
            'name' => env('MYSQL_DATABASE') ?? env('MYSQLDATABASE') ?? 'default',
            'user' => env('MYSQL_USER') ?? env('MYSQLUSER') ?? 'mysql',
            'pass' => env('MYSQL_PASSWORD') ?? env('MYSQLPASSWORD') ?? env('MYSQL_ROOT_PASSWORD') ?? '',
        ];
    }
}

if (!function_exists('resolveDbConfig')) {
    function resolveDbConfig(): array
    {
        $sqlitePath = env('DB_PATH', dirname(__DIR__) . '/database/delivery.sqlite');
        if ($sqlitePath !== null && !preg_match('~^([A-Za-z]:)?[/\\\\]~', $sqlitePath)) {
            $sqlitePath = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $sqlitePath), '/');
        }

        $db = [
            'driver' => env('DB_DRIVER', 'sqlite'),
            'path' => $sqlitePath ?: dirname(__DIR__) . '/database/delivery.sqlite',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', '3306'),
            'name' => env('DB_NAME', 'delivery_lists'),
            'user' => env('DB_USER', 'root'),
            'pass' => env('DB_PASS', ''),
        ];

        $fromMysql = resolveFromMysqlEnv();
        if ($fromMysql !== null) {
            return array_merge($db, $fromMysql);
        }

        $dbUrl = env('DATABASE_URL') ?? env('MYSQL_URL') ?? env('DB_URL');
        $fromUrl = parseDatabaseUrl($dbUrl);
        if ($fromUrl !== null) {
            return array_merge($db, $fromUrl);
        }

        if ($db['driver'] === 'sqlite') {
            return $db;
        }

        $db['driver'] = 'mysql';
        return $db;
    }
}

$db = resolveDbConfig();

return [
    'app_name' => env('APP_NAME', 'كشوفات التسليم'),
    'app_url' => rtrim(env('APP_URL', 'http://localhost:8090'), '/'),
    'app_debug' => filter_var(env('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOLEAN),
    'timezone' => env('APP_TIMEZONE', 'Asia/Riyadh'),
    'db_driver' => $db['driver'],
    'db_path' => $db['path'],
    'db_host' => $db['host'],
    'db_port' => (string) $db['port'],
    'db_name' => $db['name'],
    'db_user' => $db['user'],
    'db_pass' => $db['pass'],
];
