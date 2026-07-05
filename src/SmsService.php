<?php

declare(strict_types=1);

namespace App;

use PDO;

final class SmsService
{
    public static function buildDeliveryConfirmation(array $campaign, array $beneficiary): string
    {
        $name = trim($beneficiary['name'] ?? '');
        $parcel = trim($campaign['parcel_name'] ?? 'الطرد');
        $warehouse = trim($campaign['warehouse_name'] ?? 'المخزن');
        $code = trim($beneficiary['disbursement_code'] ?? '');

        $parts = [
            'السيد/ ' . $name . '،',
            'تم تسليم ' . $parcel . ' بنجاح من ' . $warehouse . '.',
        ];
        if ($code !== '') {
            $parts[] = 'كود الصرف: ' . $code . '.';
        }
        $parts[] = 'شكراً لتعاونكم.';

        return implode(' ', $parts);
    }

    public static function queueDeliveryConfirmation(int $campaignId, array $beneficiary, array $campaign): int
    {
        $mobile = PhoneHelper::normalize((string) ($beneficiary['mobile'] ?? ''));
        if ($mobile === '') {
            return 0;
        }

        $message = self::buildDeliveryConfirmation($campaign, $beneficiary);
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            INSERT INTO sms_outbox (campaign_id, beneficiary_id, mobile, message_text, status)
            VALUES (?, ?, ?, ?, \'pending\')
        ');
        $stmt->execute([
            $campaignId,
            (int) ($beneficiary['id'] ?? 0),
            $mobile,
            $message,
        ]);
        $id = (int) $pdo->lastInsertId();

        if (self::isEnabled()) {
            self::attemptSend($id);
        }

        return $id;
    }

    public static function isEnabled(): bool
    {
        return filter_var(env('SMS_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)
            && trim((string) env('SMS_WEBHOOK_URL', '')) !== '';
    }

    public static function outbox(int $campaignId, ?string $status = null): array
    {
        $pdo = Database::getConnection();
        $sql = '
            SELECT s.*, b.name AS beneficiary_name, b.disbursement_code
            FROM sms_outbox s
            JOIN beneficiaries b ON b.id = s.beneficiary_id
            WHERE s.campaign_id = ?
        ';
        $params = [$campaignId];
        if ($status !== null) {
            $sql .= ' AND s.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY s.created_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function pendingCount(int $campaignId): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sms_outbox WHERE campaign_id = ? AND status = 'pending'");
        $stmt->execute([$campaignId]);
        return (int) $stmt->fetchColumn();
    }

    public static function attemptSend(int $outboxId): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM sms_outbox WHERE id = ? LIMIT 1');
        $stmt->execute([$outboxId]);
        $row = $stmt->fetch();
        if (!$row || ($row['status'] ?? '') === 'sent') {
            return false;
        }

        $url = trim((string) env('SMS_WEBHOOK_URL', ''));
        $payload = json_encode([
            'mobile' => $row['mobile'],
            'message' => $row['message_text'],
        ], JSON_UNESCAPED_UNICODE);

        $headers = ['Content-Type: application/json'];
        $token = trim((string) env('SMS_WEBHOOK_TOKEN', ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ok = $response !== false && $httpCode >= 200 && $httpCode < 300;
        $upd = $pdo->prepare('
            UPDATE sms_outbox SET status = ?, sent_at = datetime(\'now\') WHERE id = ?
        ');
        $upd->execute([$ok ? 'sent' : 'failed', $outboxId]);

        return $ok;
    }

    public static function sendPendingBatch(int $campaignId, int $limit = 50): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT id FROM sms_outbox
            WHERE campaign_id = ? AND status = 'pending'
            ORDER BY id ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $campaignId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $sent = 0;
        $failed = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (self::attemptSend((int) $id)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
