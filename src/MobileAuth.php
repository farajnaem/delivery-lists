<?php

declare(strict_types=1);

namespace App;

use PDO;

final class MobileAuth
{
    private const TOKEN_BYTES = 32;
    private const TOKEN_TTL_DAYS = 90;

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

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $hash = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', strtotime('+' . self::TOKEN_TTL_DAYS . ' days'));

        $ins = $pdo->prepare('
            INSERT INTO mobile_tokens (user_id, token_hash, expires_at)
            VALUES (?, ?, ?)
        ');
        $ins->execute([(int) $user['id'], $hash, $expires]);

        return [
            'token' => $token,
            'expires_at' => $expires,
            'user' => [
                'id' => (int) $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $role,
            ],
        ];
    }

    public static function authenticateRequest(): bool
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return false;
        }

        $hash = hash('sha256', $m[1]);
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT t.*, u.name, u.email, u.role
            FROM mobile_tokens t
            JOIN users u ON u.id = t.user_id
            WHERE t.token_hash = ? AND u.is_active = 1
            LIMIT 1
        ');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }

        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            return false;
        }

        if (!in_array((string) ($row['role'] ?? ''), ['warehouse_keeper', 'admin'], true)) {
            return false;
        }

        self::$currentUser = [
            'id' => (int) $row['user_id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'role' => $row['role'],
        ];

        return true;
    }

    public static function requireAuth(): void
    {
        if (!self::authenticateRequest()) {
            json_response(['ok' => false, 'error' => 'غير مصرّح — سجّل الدخول'], 401);
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
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM mobile_tokens WHERE token_hash = ?')->execute([$hash]);
    }
}
