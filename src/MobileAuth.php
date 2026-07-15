<?php

declare(strict_types=1);

namespace App;

use PDO;

final class MobileAuth
{
    private const TOKEN_BYTES = 32;
    private const TOKEN_TTL_DAYS = 90;
    private const TOKEN_PREFIX = 'mt1';

    /** @var array{id:int,name:string,email:string,role:string}|null */
    private static ?array $currentUser = null;

    public static function login(string $email, string $password): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([trim($email)]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        $role = (string) ($user['role'] ?? '');
        if (!in_array($role, ['warehouse_keeper', 'admin'], true)) {
            return null;
        }

        $userId = (int) $user['id'];
        $expiresTs = time() + (self::TOKEN_TTL_DAYS * 86400);
        $token = self::mintToken($userId, $expiresTs);
        $expires = date('Y-m-d H:i:s', $expiresTs);

        // نخزّن أيضاً في الجدول لإبطال الجلسات عند الخروج (اختياري)
        try {
            $hash = hash('sha256', $token);
            $ins = $pdo->prepare('
                INSERT INTO mobile_tokens (user_id, token_hash, expires_at)
                VALUES (?, ?, ?)
            ');
            $ins->execute([$userId, $hash, $expires]);
        } catch (\Throwable) {
            // لا نُفشل الدخول إذا فشل التخزين — التوكن الموقّع كافٍ للمصادقة
        }

        return [
            'token' => $token,
            'expires_at' => $expires,
            'user' => [
                'id' => $userId,
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $role,
            ],
        ];
    }

    public static function authenticateRequest(): bool
    {
        $token = self::bearerTokenFromRequest();
        if ($token === null) {
            return false;
        }

        $user = self::userFromSignedToken($token);
        if ($user === null) {
            $user = self::userFromStoredToken($token);
        }
        if ($user === null) {
            return false;
        }

        self::$currentUser = $user;
        return true;
    }

    public static function requireAuth(): void
    {
        if (!self::authenticateRequest()) {
            json_response([
                'ok' => false,
                'error' => 'غير مصرّح — سجّل الدخول',
                'error_code' => 'auth_required',
            ], 401);
        }
    }

    public static function user(): ?array
    {
        return self::$currentUser;
    }

    public static function userId(): ?int
    {
        return self::$currentUser['id'] ?? null;
    }

    public static function logout(string $token): void
    {
        $hash = hash('sha256', $token);
        try {
            $pdo = Database::getConnection();
            $pdo->prepare('DELETE FROM mobile_tokens WHERE token_hash = ?')->execute([$hash]);
        } catch (\Throwable) {
            // تجاهل
        }
    }

    /** @return array{id:int,name:string,email:string,role:string}|null */
    private static function userFromSignedToken(string $token): ?array
    {
        $parsed = self::parseSignedToken($token);
        if ($parsed === null) {
            return null;
        }
        if ($parsed['exp'] < time()) {
            return null;
        }

        return self::loadActiveMobileUser($parsed['user_id']);
    }

    /** @return array{id:int,name:string,email:string,role:string}|null */
    private static function userFromStoredToken(string $token): ?array
    {
        try {
            $hash = hash('sha256', $token);
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('
                SELECT t.user_id, t.expires_at, u.name, u.email, u.role, u.is_active
                FROM mobile_tokens t
                JOIN users u ON u.id = t.user_id
                WHERE t.token_hash = ?
                LIMIT 1
            ');
            $stmt->execute([$hash]);
            $row = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        if (!$row || !(int) ($row['is_active'] ?? 0)) {
            return null;
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }
        if (!in_array((string) ($row['role'] ?? ''), ['warehouse_keeper', 'admin'], true)) {
            return null;
        }

        return [
            'id' => (int) $row['user_id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'role' => (string) $row['role'],
        ];
    }

    /** @return array{id:int,name:string,email:string,role:string}|null */
    private static function loadActiveMobileUser(int $userId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, name, email, role, is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || !(int) ($row['is_active'] ?? 0)) {
            return null;
        }
        $role = (string) ($row['role'] ?? '');
        if (!in_array($role, ['warehouse_keeper', 'admin'], true)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'role' => $role,
        ];
    }

    private static function mintToken(int $userId, int $expiresTs): string
    {
        $payload = $userId . '.' . $expiresTs;
        $sig = hash_hmac('sha256', $payload, self::appSecret());
        return self::TOKEN_PREFIX . '.' . $payload . '.' . $sig;
    }

    /** @return array{user_id:int,exp:int}|null */
    private static function parseSignedToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 4 || $parts[0] !== self::TOKEN_PREFIX) {
            return null;
        }
        if (!ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
            return null;
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $parts[3])) {
            return null;
        }

        $payload = $parts[1] . '.' . $parts[2];
        $expected = hash_hmac('sha256', $payload, self::appSecret());
        if (!hash_equals($expected, strtolower($parts[3]))) {
            return null;
        }

        return [
            'user_id' => (int) $parts[1],
            'exp' => (int) $parts[2],
        ];
    }

    private static function appSecret(): string
    {
        $key = trim((string) env('APP_KEY', ''));
        if ($key !== '') {
            return $key;
        }

        // سر مستقر مشتق من إعدادات السيرفر — يفضّل تعيين APP_KEY في Coolify
        $seed = implode('|', [
            (string) env('APP_URL', 'delivery-lists'),
            (string) env('DB_PASS', ''),
            (string) env('DB_NAME', ''),
            (string) env('DATABASE_URL', ''),
            'mobile-auth-v1',
        ]);

        return hash('sha256', $seed);
    }

    private static function bearerTokenFromRequest(): ?string
    {
        $candidates = [];

        foreach ([
            'HTTP_AUTHORIZATION',
            'REDIRECT_HTTP_AUTHORIZATION',
            'HTTP_X_MOBILE_TOKEN',
            'REDIRECT_HTTP_X_MOBILE_TOKEN',
            'HTTP_X_DELIVERY_TOKEN',
            'REDIRECT_HTTP_X_DELIVERY_TOKEN',
        ] as $key) {
            if (!empty($_SERVER[$key])) {
                $candidates[] = (string) $_SERVER[$key];
            }
        }

        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $name => $value) {
                $lower = strtolower((string) $name);
                if (in_array($lower, ['authorization', 'x-mobile-token', 'x-delivery-token'], true)) {
                    $candidates[] = (string) $value;
                }
            }
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    $lower = strtolower((string) $name);
                    if (in_array($lower, ['authorization', 'x-mobile-token', 'x-delivery-token'], true)) {
                        $candidates[] = (string) $value;
                    }
                }
            }
        }

        foreach ($candidates as $header) {
            $header = trim($header);
            if ($header === '') {
                continue;
            }
            if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
                $candidate = $m[1];
                if (self::looksLikeToken($candidate)) {
                    return $candidate;
                }
            }
            if (self::looksLikeToken($header)) {
                return $header;
            }
        }

        $queryToken = trim((string) ($_GET['mobile_token'] ?? $_GET['mt'] ?? ''));
        if (self::looksLikeToken($queryToken)) {
            return $queryToken;
        }

        return null;
    }

    private static function looksLikeToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        // توكن موقّع جديد: mt1.{uid}.{exp}.{hmac}
        if (preg_match('/^mt1\.\d+\.\d+\.[a-f0-9]{64}$/i', $token)) {
            return true;
        }
        // توكن عشوائي قديم (64 hex)
        if (preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return true;
        }

        return false;
    }
}
