<?php

declare(strict_types=1);

namespace App;

use PDO;

final class CampaignService
{
    public static function all(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('
            SELECT c.*, u.name AS creator_name,
                   (SELECT COUNT(*) FROM beneficiaries b WHERE b.campaign_id = c.id) AS beneficiary_count
            FROM campaigns c
            LEFT JOIN users u ON u.id = c.created_by
            ORDER BY c.created_at DESC
        ');
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data, int $userId): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            INSERT INTO campaigns (
                name, parcel_name, parcel_code, delivery_start, delivery_end,
                warehouse_name, warehouse_location, num_days, work_start, work_end,
                per_window_capacity, num_windows, opening_quantity, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['name'],
            $data['parcel_name'],
            $data['parcel_code'],
            $data['delivery_start'],
            $data['delivery_end'],
            $data['warehouse_name'],
            $data['warehouse_location'],
            (int) $data['num_days'],
            $data['work_start'],
            $data['work_end'],
            (int) $data['per_window_capacity'],
            0,
            max(0, (int) ($data['opening_quantity'] ?? 0)),
            'draft',
            $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            UPDATE campaigns SET
                name = ?, parcel_name = ?, parcel_code = ?, delivery_start = ?, delivery_end = ?,
                warehouse_name = ?, warehouse_location = ?, num_days = ?,
                work_start = ?, work_end = ?, per_window_capacity = ?, num_windows = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $data['name'],
            $data['parcel_name'],
            $data['parcel_code'],
            $data['delivery_start'],
            $data['delivery_end'],
            $data['warehouse_name'],
            $data['warehouse_location'],
            (int) $data['num_days'],
            $data['work_start'],
            $data['work_end'],
            (int) $data['per_window_capacity'],
            (int) ($data['num_windows'] ?? 4),
            $id,
        ]);
    }

    public static function markGenerated(int $id): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE campaigns SET status = 'generated', generated_at = datetime('now') WHERE id = ?");
        $stmt->execute([$id]);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM campaigns WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function beneficiaries(int $campaignId, ?int $dayIndex = null, ?int $windowNum = null): array
    {
        $pdo = Database::getConnection();
        $sql = 'SELECT * FROM beneficiaries WHERE campaign_id = ?';
        $params = [$campaignId];
        if ($dayIndex !== null) {
            $sql .= ' AND day_index = ?';
            $params[] = $dayIndex;
        }
        if ($windowNum !== null) {
            $sql .= ' AND window_num = ?';
            $params[] = $windowNum;
        }
        $sql .= ' ORDER BY sort_order ASC, disbursement_code ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function beneficiariesDetailed(int $campaignId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT b.*, u.name AS delivered_by_name
            FROM beneficiaries b
            LEFT JOIN users u ON u.id = b.delivered_by
            WHERE b.campaign_id = ?
            ORDER BY b.sort_order ASC, b.disbursement_code ASC
        ');
        $stmt->execute([$campaignId]);
        return $stmt->fetchAll();
    }

    public static function stats(int $campaignId): array
    {
        $pdo = Database::getConnection();
        $total = (int) $pdo->query("SELECT COUNT(*) FROM beneficiaries WHERE campaign_id = {$campaignId}")->fetchColumn();
        $delivered = (int) $pdo->query("
            SELECT COUNT(*) FROM beneficiaries
            WHERE campaign_id = {$campaignId} AND receipt_status = 'مستلم'
        ")->fetchColumn();
        $days = $pdo->prepare('SELECT day_index, delivery_date, COUNT(*) AS cnt FROM beneficiaries WHERE campaign_id = ? GROUP BY day_index, delivery_date ORDER BY day_index');
        $days->execute([$campaignId]);
        return [
            'total' => $total,
            'delivered' => $delivered,
            'pending' => $total - $delivered,
            'days' => $days->fetchAll(),
        ];
    }

    public static function updateOpeningQuantity(int $id, int $quantity): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE campaigns SET opening_quantity = ? WHERE id = ?');
        $stmt->execute([max(0, $quantity), $id]);
    }
}
