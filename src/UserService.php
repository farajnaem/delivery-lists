<?php

declare(strict_types=1);

namespace App;

use PDO;

final class UserService
{
    public static function all(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query('SELECT id, name, email, role, is_active, created_at FROM users ORDER BY name')->fetchAll();
    }

    public static function create(string $name, string $email, string $password, string $role): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
    }

    public static function count(): int
    {
        $pdo = Database::getConnection();
        return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }
}
