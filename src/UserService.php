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

    public static function find(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, name, email, role, is_active, created_at FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function emailExists(string $email, ?int $exceptId = null): bool
    {
        $pdo = Database::getConnection();
        $sql = 'SELECT 1 FROM users WHERE email = ?';
        $params = [$email];
        if ($exceptId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $exceptId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    public static function create(string $name, string $email, string $password, string $role): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
    }

    public static function update(int $id, string $name, string $email, string $role, bool $isActive, ?string $password = null): void
    {
        $pdo = Database::getConnection();
        if ($password !== null && $password !== '') {
            $stmt = $pdo->prepare('
                UPDATE users SET name = ?, email = ?, role = ?, is_active = ?, password_hash = ?
                WHERE id = ?
            ');
            $stmt->execute([$name, $email, $role, $isActive ? 1 : 0, password_hash($password, PASSWORD_DEFAULT), $id]);
            return;
        }

        $stmt = $pdo->prepare('
            UPDATE users SET name = ?, email = ?, role = ?, is_active = ?
            WHERE id = ?
        ');
        $stmt->execute([$name, $email, $role, $isActive ? 1 : 0, $id]);
    }

    public static function deactivate(int $id): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function adminCount(): int
    {
        $pdo = Database::getConnection();
        return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
    }

    public static function count(): int
    {
        $pdo = Database::getConnection();
        return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }
}
