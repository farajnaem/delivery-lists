<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class DeliveryService
{
    public const STATUS_PENDING = 'قيد التسليم';
    /** صيغة شائعة في ملفات Excel القديمة — تُعامل كبانتظار */
    public const STATUS_PENDING_ALT = 'بانتظار الاستلام';
    public const STATUS_DELIVERED = 'مستلم';

    public static function isDeliveredStatus(?string $status): bool
    {
        return trim((string) $status) === self::STATUS_DELIVERED;
    }

    /** أي حالة غير «مستلم» تُعد بانتظار التسليم (بما فيها «بانتظار الاستلام»). */
    public static function isPendingStatus(?string $status): bool
    {
        return !self::isDeliveredStatus($status);
    }

    public static function normalizeReceiptStatus(?string $status): string
    {
        $status = trim((string) $status);
        if ($status === self::STATUS_DELIVERED || $status === 'مستلم') {
            return self::STATUS_DELIVERED;
        }
        // بانتظار الاستلام / قيد التسليم / فارغ / تم التسليم (قوالب قديمة) → موحَّد
        return self::STATUS_PENDING;
    }

    public static function activeCampaigns(): array
    {
        $pdo = Database::getConnection();
        $delivered = $pdo->quote(self::STATUS_DELIVERED);
        $stmt = $pdo->query("
            SELECT c.*,
                   (SELECT COUNT(*) FROM beneficiaries b WHERE b.campaign_id = c.id) AS beneficiary_count,
                   (SELECT COUNT(*) FROM beneficiaries b WHERE b.campaign_id = c.id AND b.receipt_status = {$delivered}) AS delivered_count
            FROM campaigns c
            WHERE c.status = 'generated'
              AND (c.delivery_closed_at IS NULL OR c.delivery_closed_at = '')
            ORDER BY c.delivery_start DESC
        ");
        return $stmt->fetchAll();
    }

    /** كل العمليات المُولَّدة للمخزن — حتى المُنهية يدوياً (للعرض والاستعلام) */
    public static function warehouseCampaigns(): array
    {
        $pdo = Database::getConnection();
        $delivered = $pdo->quote(self::STATUS_DELIVERED);
        $stmt = $pdo->query("
            SELECT c.*,
                   (SELECT COUNT(*) FROM beneficiaries b WHERE b.campaign_id = c.id) AS beneficiary_count,
                   (SELECT COUNT(*) FROM beneficiaries b WHERE b.campaign_id = c.id AND b.receipt_status = {$delivered}) AS delivered_count
            FROM campaigns c
            WHERE c.status = 'generated'
            ORDER BY c.delivery_start DESC
        ");
        return $stmt->fetchAll();
    }

    public static function stockStats(int $campaignId): array
    {
        $pdo = Database::getConnection();
        $campaign = CampaignService::find($campaignId);
        if (!$campaign) {
            throw new RuntimeException('العملية غير موجودة.');
        }

        $total = (int) $pdo->query("SELECT COUNT(*) FROM beneficiaries WHERE campaign_id = {$campaignId}")->fetchColumn();
        $delivered = (int) $pdo->query("
            SELECT COUNT(*) FROM beneficiaries
            WHERE campaign_id = {$campaignId} AND receipt_status = " . $pdo->quote(self::STATUS_DELIVERED) . '
        ')->fetchColumn();
        $pending = $total - $delivered;

        $opening = (int) ($campaign['opening_quantity'] ?? 0);
        if ($opening <= 0) {
            $opening = $total;
        }

        $onTime = (int) $pdo->query("
            SELECT COUNT(*) FROM beneficiaries
            WHERE campaign_id = {$campaignId} AND delivery_type = 'on_time'
        ")->fetchColumn();
        $late = (int) $pdo->query("
            SELECT COUNT(*) FROM beneficiaries
            WHERE campaign_id = {$campaignId} AND delivery_type = 'late'
        ")->fetchColumn();

        $today = date('Y-m-d');
        $todayDelivered = (int) $pdo->query("
            SELECT COUNT(*) FROM beneficiaries
            WHERE campaign_id = {$campaignId}
              AND receipt_status = " . $pdo->quote(self::STATUS_DELIVERED) . "
              AND actual_delivery_date = " . $pdo->quote($today) . '
        ')->fetchColumn();

        $plannedToday = (int) $pdo->query("
            SELECT COUNT(*) FROM beneficiaries
            WHERE campaign_id = {$campaignId}
              AND delivery_date = " . $pdo->quote($today) . '
        ')->fetchColumn();

        return [
            'campaign' => $campaign,
            'total_beneficiaries' => $total,
            'opening_quantity' => $opening,
            'delivered' => $delivered,
            'pending' => $pending,
            'balance' => max(0, $opening - $delivered),
            'on_time' => $onTime,
            'late' => $late,
            'today_delivered' => $todayDelivered,
            'planned_today' => $plannedToday,
            'campaign_active' => self::isCampaignActive($campaign),
        ];
    }

    /** @return array<string, mixed> */
    public static function stockStatsForDisplay(int $campaignId): array
    {
        return ArabicFormat::localizeStock(self::stockStats($campaignId));
    }

    public static function isCampaignActive(array $campaign): bool
    {
        $closedAt = trim((string) ($campaign['delivery_closed_at'] ?? ''));
        return $closedAt === '';
    }

    public static function search(int $campaignId, string $query): ?array
    {
        $query = ArabicFormat::toWesternDigits(trim($query));
        if ($query === '') {
            return null;
        }

        $campaign = CampaignService::find($campaignId);
        if (!$campaign) {
            return null;
        }

        $pdo = Database::getConnection();
        $normalized = preg_replace('/\s+/', '', $query) ?? $query;
        $prefix = (string) ($campaign['parcel_code'] ?? ParcelCodeHelper::DEFAULT_PREFIX);
        $suffix = (string) ($campaign['parcel_code_suffix'] ?? '');
        $codeCandidates = ParcelCodeHelper::matchSearchCandidates($query, $prefix, $suffix);
        $placeholders = implode(', ', array_fill(0, count($codeCandidates), '?'));

        $sql = '
            SELECT b.*, c.delivery_end, c.delivery_start, c.delivery_closed_at, c.name AS campaign_name,
                   c.parcel_code_suffix
            FROM beneficiaries b
            JOIN campaigns c ON c.id = b.campaign_id
            WHERE b.campaign_id = ?
              AND (
                b.national_id = ?
                OR REPLACE(b.national_id, \' \', \'\') = ?
        ';
        $params = [$campaignId, $normalized, $normalized];

        if ($codeCandidates !== []) {
            $sql .= ' OR b.disbursement_code IN (' . $placeholders . ')';
            array_push($params, ...$codeCandidates);
        }

        $sql .= ')
            LIMIT 1
        ';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ? self::enrichForDisplay($row) : null;
    }

    /**
     * @return array{ok: bool, beneficiary?: array, error?: string, already?: bool}
     */
    public static function markDelivered(int $campaignId, int $beneficiaryId, int $userId, ?string $clientId = null): array
    {
        $pdo = Database::getConnection();

        if ($clientId !== null && $clientId !== '') {
            $existing = self::findByClientId($clientId);
            if ($existing) {
                return ['ok' => true, 'beneficiary' => $existing, 'already' => true];
            }
        }

        $campaign = CampaignService::find($campaignId);
        if (!$campaign || ($campaign['status'] ?? '') !== 'generated') {
            return ['ok' => false, 'error' => 'العملية غير جاهزة للتسليم.'];
        }

        if (!self::isCampaignActive($campaign)) {
            return ['ok' => false, 'error' => 'تم إنهاء عملية التسليم — أعد فتحها من متابعة المخزن.'];
        }

        $stmt = $pdo->prepare('SELECT * FROM beneficiaries WHERE id = ? AND campaign_id = ? LIMIT 1');
        $stmt->execute([$beneficiaryId, $campaignId]);
        $beneficiary = $stmt->fetch();
        if (!$beneficiary) {
            return ['ok' => false, 'error' => 'المستفيد غير موجود.'];
        }

        if (($beneficiary['receipt_status'] ?? '') === self::STATUS_DELIVERED) {
            return ['ok' => false, 'error' => 'تم تسليم هذا المستفيد مسبقاً.', 'beneficiary' => $beneficiary, 'already' => true];
        }

        $stats = self::stockStats($campaignId);
        if ($stats['balance'] <= 0) {
            return ['ok' => false, 'error' => 'لا يوجد رصيد كافٍ في المخزن.'];
        }

        $today = date('Y-m-d');
        $plannedDate = $beneficiary['delivery_date'] ?? $today;
        $deliveryType = ($today <= $plannedDate) ? 'on_time' : 'late';
        $now = date('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare('
                UPDATE beneficiaries SET
                    receipt_status = ?,
                    delivered_at = ?,
                    delivered_by = ?,
                    delivery_type = ?,
                    actual_delivery_date = ?,
                    updated_at = ?
                WHERE id = ? AND receipt_status != ?
            ');
            $upd->execute([
                self::STATUS_DELIVERED,
                $now,
                $userId,
                $deliveryType,
                $today,
                $now,
                $beneficiaryId,
                self::STATUS_DELIVERED,
            ]);

            if ($upd->rowCount() === 0) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'تم تسليم هذا المستفيد مسبقاً.', 'already' => true];
            }

            $evt = $pdo->prepare('
                INSERT INTO delivery_events (beneficiary_id, campaign_id, action, delivery_type, delivered_at, delivered_by, client_id)
                VALUES (?, ?, \'delivered\', ?, ?, ?, ?)
            ');
            $evt->execute([$beneficiaryId, $campaignId, $deliveryType, $now, $userId, $clientId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if ($clientId && str_contains($e->getMessage(), 'UNIQUE constraint')) {
                $existing = self::findByClientId($clientId);
                if ($existing) {
                    return ['ok' => true, 'beneficiary' => $existing, 'already' => true];
                }
            }
            throw $e;
        }

        $beneficiary['receipt_status'] = self::STATUS_DELIVERED;
        $beneficiary['delivered_at'] = $now;
        $beneficiary['delivery_type'] = $deliveryType;
        $beneficiary['actual_delivery_date'] = $today;

        try {
            SmsService::queueDeliveryConfirmation($campaignId, $beneficiary, $campaign);
        } catch (\Throwable) {
            // لا نوقف التسليم إذا فشل تجهيز الرسالة
        }

        return ['ok' => true, 'beneficiary' => self::enrichForDisplay(
            $beneficiary,
            (string) ($campaign['parcel_code_suffix'] ?? ''),
            (string) ($campaign['parcel_code'] ?? '')
        ), 'delivery_type' => $deliveryType];
    }

    /** @param array<string, mixed> $beneficiary */
    public static function enrichForDisplay(array $beneficiary, ?string $codeSuffix = null, ?string $codePrefix = null): array
    {
        return ArabicFormat::localizeBeneficiary($beneficiary, $codePrefix, $codeSuffix);
    }

    /** @return list<array<string, mixed>> */
    public static function mapForDisplay(array $rows, ?string $codeSuffix = null, ?string $codePrefix = null): array
    {
        return array_map(
            fn (array $row): array => self::enrichForDisplay($row, $codeSuffix, $codePrefix),
            $rows
        );
    }

    /**
     * @param array<int, array{beneficiary_id: int, client_id?: string}> $items
     * @return array{ok: bool, results: array<int, array>, synced: int, failed: int}
     */
    public static function syncBatch(int $campaignId, int $userId, array $items): array
    {
        $results = [];
        $synced = 0;
        $failed = 0;

        foreach ($items as $item) {
            $beneficiaryId = (int) ($item['beneficiary_id'] ?? 0);
            $clientId = isset($item['client_id']) ? (string) $item['client_id'] : null;
            if ($beneficiaryId <= 0) {
                $failed++;
                $results[] = ['beneficiary_id' => $beneficiaryId, 'ok' => false, 'error' => 'معرّف غير صالح'];
                continue;
            }

            $result = self::markDelivered($campaignId, $beneficiaryId, $userId, $clientId);
            if ($result['ok']) {
                $synced++;
            } else {
                $failed++;
            }
            $results[] = array_merge(['beneficiary_id' => $beneficiaryId], $result);
        }

        return ['ok' => true, 'results' => $results, 'synced' => $synced, 'failed' => $failed];
    }

    public static function recentDeliveries(int $campaignId, int $limit = 20): array
    {
        return self::deliveredBeneficiaries($campaignId, $limit);
    }

    /** @return list<array> */
    public static function deliveredBeneficiaries(int $campaignId, int $limit = 100, int $offset = 0): array
    {
        $campaign = CampaignService::find($campaignId);
        $codeSuffix = (string) ($campaign['parcel_code_suffix'] ?? '');
        $codePrefix = (string) ($campaign['parcel_code'] ?? '');

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT b.id, b.name, b.disbursement_code, b.sort_order, b.national_id, b.delivery_type,
                   b.delivered_at, b.actual_delivery_date, b.delivery_date, b.window_num,
                   u.name AS delivered_by_name
            FROM beneficiaries b
            LEFT JOIN users u ON u.id = b.delivered_by
            WHERE b.campaign_id = ? AND b.receipt_status = ?
            ORDER BY b.delivered_at DESC, b.id DESC
            LIMIT ? OFFSET ?
        ');
        $stmt->bindValue(1, $campaignId, PDO::PARAM_INT);
        $stmt->bindValue(2, self::STATUS_DELIVERED);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->bindValue(4, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return self::mapForDisplay(
            $stmt->fetchAll(),
            $codeSuffix !== '' ? $codeSuffix : null,
            $codePrefix !== '' ? $codePrefix : null
        );
    }

    public static function deliveredCount(int $campaignId): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM beneficiaries
            WHERE campaign_id = ? AND receipt_status = ?
        ');
        $stmt->execute([$campaignId, self::STATUS_DELIVERED]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * إلغاء جميع تسليمات العملية وإعادة المستفيدين لـ «قيد التسليم».
     * للمدير فقط — يتيح حذف أو تنظيف العملية لاحقاً.
     */
    public static function undoAllDeliveries(int $campaignId): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM beneficiaries WHERE campaign_id = ? AND receipt_status = ?');
        $stmt->execute([$campaignId, self::STATUS_DELIVERED]);
        $count = (int) $stmt->fetchColumn();
        if ($count === 0) {
            return 0;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('
                UPDATE beneficiaries SET
                    receipt_status = ?,
                    delivered_at = NULL,
                    delivered_by = NULL,
                    delivery_type = NULL,
                    actual_delivery_date = NULL,
                    delivery_batch_id = NULL,
                    updated_at = ?
                WHERE campaign_id = ? AND receipt_status = ?
            ')->execute([self::STATUS_PENDING, db_now(), $campaignId, self::STATUS_DELIVERED]);

            $pdo->prepare("DELETE FROM delivery_events WHERE campaign_id = ? AND action = 'delivered'")
                ->execute([$campaignId]);

            $pdo->prepare("DELETE FROM sms_outbox WHERE campaign_id = ? AND status = 'pending'")
                ->execute([$campaignId]);

            try {
                $pdo->prepare('
                    UPDATE delivery_batches
                    SET undone_at = COALESCE(undone_at, ?),
                        undo_reason = COALESCE(undo_reason, ?)
                    WHERE campaign_id = ? AND undone_at IS NULL
                ')->execute([db_now(), 'إلغاء جميع التسليمات', $campaignId]);
            } catch (\Throwable) {
                // الجدول قد لا يكون موجوداً بعد على تثبيت قديم جداً
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $count;
    }

    public static function pendingLate(int $campaignId, int $limit = 50): array
    {
        $campaign = CampaignService::find($campaignId);
        $codeSuffix = (string) ($campaign['parcel_code_suffix'] ?? '');
        $codePrefix = (string) ($campaign['parcel_code'] ?? '');

        $pdo = Database::getConnection();
        $today = date('Y-m-d');
        $stmt = $pdo->prepare('
            SELECT id, name, national_id, disbursement_code, sort_order, delivery_date, window_num
            FROM beneficiaries
            WHERE campaign_id = ?
              AND receipt_status != ?
              AND delivery_date < ?
            ORDER BY delivery_date ASC, sort_order ASC
            LIMIT ?
        ');
        $stmt->bindValue(1, $campaignId, PDO::PARAM_INT);
        $stmt->bindValue(2, self::STATUS_DELIVERED);
        $stmt->bindValue(3, $today);
        $stmt->bindValue(4, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return self::mapForDisplay(
            $stmt->fetchAll(),
            $codeSuffix !== '' ? $codeSuffix : null,
            $codePrefix !== '' ? $codePrefix : null
        );
    }

    private static function findByClientId(string $clientId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT b.* FROM delivery_events e
            JOIN beneficiaries b ON b.id = e.beneficiary_id
            WHERE e.client_id = ?
            LIMIT 1
        ');
        $stmt->execute([$clientId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * مستفيدون بانتظار التسليم — للتسليم الجماعي اليدوي (مدير).
     *
     * @return list<array<string, mixed>>
     */
    public static function pendingBeneficiariesForAdmin(int $campaignId): array
    {
        $campaign = CampaignService::find($campaignId);
        $codeSuffix = (string) ($campaign['parcel_code_suffix'] ?? '');
        $codePrefix = (string) ($campaign['parcel_code'] ?? '');
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT id, name, national_id, disbursement_code, sort_order, delivery_date,
                   window_num, day_index, time_from, time_to, receipt_status
            FROM beneficiaries
            WHERE campaign_id = ? AND receipt_status != ?
            ORDER BY day_index ASC, window_num ASC, sort_order ASC, id ASC
        ');
        $stmt->execute([$campaignId, self::STATUS_DELIVERED]);
        return self::mapForDisplay($stmt->fetchAll(), $codeSuffix !== '' ? $codeSuffix : null, $codePrefix !== '' ? $codePrefix : null);
    }

    /**
     * مستفيدون مُسلَّمون — لتصحيح الحالة (مدير).
     *
     * @return list<array<string, mixed>>
     */
    public static function deliveredBeneficiariesForAdmin(int $campaignId): array
    {
        $campaign = CampaignService::find($campaignId);
        $codeSuffix = (string) ($campaign['parcel_code_suffix'] ?? '');
        $codePrefix = (string) ($campaign['parcel_code'] ?? '');
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT b.id, b.name, b.national_id, b.disbursement_code, b.sort_order, b.delivery_date,
                   b.window_num, b.day_index, b.receipt_status, b.delivered_at, b.delivery_batch_id,
                   u.name AS delivered_by_name
            FROM beneficiaries b
            LEFT JOIN users u ON u.id = b.delivered_by
            WHERE b.campaign_id = ? AND b.receipt_status = ?
            ORDER BY b.delivered_at DESC, b.id DESC
        ');
        $stmt->execute([$campaignId, self::STATUS_DELIVERED]);
        return self::mapForDisplay($stmt->fetchAll(), $codeSuffix !== '' ? $codeSuffix : null, $codePrefix !== '' ? $codePrefix : null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listDeliveryBatches(int $campaignId): array
    {
        $pdo = Database::getConnection();
        try {
            $stmt = $pdo->prepare('
                SELECT b.*, u.name AS created_by_name, uu.name AS undone_by_name,
                       (SELECT COUNT(*) FROM beneficiaries x
                        WHERE x.delivery_batch_id = b.id AND x.receipt_status = ?) AS active_count
                FROM delivery_batches b
                LEFT JOIN users u ON u.id = b.created_by
                LEFT JOIN users uu ON uu.id = b.undone_by
                WHERE b.campaign_id = ?
                ORDER BY b.id DESC
            ');
            $stmt->execute([self::STATUS_DELIVERED, $campaignId]);
            return $stmt->fetchAll() ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * تسليم جماعي يدوي — المحدَّدون فقط. بدون SMS جماعي.
     *
     * @param list<int> $beneficiaryIds
     * @return array{ok:bool,error?:string,batch_id?:int,delivered?:int,skipped?:int}
     */
    public static function bulkMarkDelivered(int $campaignId, int $userId, array $beneficiaryIds, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'error' => 'سبب التسليم الجماعي مطلوب.'];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $beneficiaryIds), fn ($id) => $id > 0)));
        if ($ids === []) {
            return ['ok' => false, 'error' => 'لم يتم تحديد أي مستفيد للتسليم.'];
        }

        $campaign = CampaignService::find($campaignId);
        if (!$campaign || ($campaign['status'] ?? '') !== 'generated') {
            return ['ok' => false, 'error' => 'العملية غير جاهزة للتسليم.'];
        }
        if (!self::isCampaignActive($campaign)) {
            return ['ok' => false, 'error' => 'تم إنهاء عملية التسليم — أعد فتحها أولاً.'];
        }

        $pdo = Database::getConnection();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        array_unshift($params, $campaignId);
        $stmt = $pdo->prepare("
            SELECT id, delivery_date, receipt_status
            FROM beneficiaries
            WHERE campaign_id = ? AND id IN ({$placeholders})
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $pending = [];
        foreach ($rows as $row) {
            if (self::isDeliveredStatus($row['receipt_status'] ?? '')) {
                continue;
            }
            $pending[] = $row;
        }
        if ($pending === []) {
            return ['ok' => false, 'error' => 'كل المحددين مُسلَّمون مسبقاً أو غير موجودين.'];
        }

        $stats = self::stockStats($campaignId);
        if ($stats['balance'] < count($pending)) {
            return [
                'ok' => false,
                'error' => 'الرصيد غير كافٍ: المطلوب ' . count($pending) . ' والمتاح ' . (int) $stats['balance'] . '.',
            ];
        }

        $today = date('Y-m-d');
        $now = db_now();
        $skipped = count($ids) - count($pending);

        $pdo->beginTransaction();
        try {
            $insBatch = $pdo->prepare('
                INSERT INTO delivery_batches (campaign_id, reason, delivered_count, created_by, created_at)
                VALUES (?, ?, ?, ?, ?)
            ');
            $insBatch->execute([$campaignId, $reason, count($pending), $userId, $now]);
            $batchId = (int) $pdo->lastInsertId();

            $upd = $pdo->prepare('
                UPDATE beneficiaries SET
                    receipt_status = ?,
                    delivered_at = ?,
                    delivered_by = ?,
                    delivery_type = ?,
                    actual_delivery_date = ?,
                    delivery_batch_id = ?,
                    updated_at = ?
                WHERE id = ? AND campaign_id = ? AND receipt_status != ?
            ');
            $evt = $pdo->prepare('
                INSERT INTO delivery_events (beneficiary_id, campaign_id, action, delivery_type, delivered_at, delivered_by, client_id)
                VALUES (?, ?, \'delivered\', ?, ?, ?, ?)
            ');

            $delivered = 0;
            foreach ($pending as $row) {
                $bid = (int) $row['id'];
                $planned = (string) ($row['delivery_date'] ?? $today);
                $deliveryType = ($today <= $planned) ? 'on_time' : 'late';
                $upd->execute([
                    self::STATUS_DELIVERED,
                    $now,
                    $userId,
                    $deliveryType,
                    $today,
                    $batchId,
                    $now,
                    $bid,
                    $campaignId,
                    self::STATUS_DELIVERED,
                ]);
                if ($upd->rowCount() === 0) {
                    $skipped++;
                    continue;
                }
                $clientId = substr('bulk-' . $batchId . '-' . $bid, 0, 64);
                $evt->execute([$bid, $campaignId, $deliveryType, $now, $userId, $clientId]);
                $delivered++;
            }

            if ($delivered === 0) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'تعذّر تسجيل أي تسليم — ربما تغيّرت الحالات.'];
            }

            $pdo->prepare('UPDATE delivery_batches SET delivered_count = ? WHERE id = ?')
                ->execute([$delivered, $batchId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'ok' => true,
            'batch_id' => $batchId,
            'delivered' => $delivered,
            'skipped' => $skipped,
        ];
    }

    /**
     * التراجع عن دفعة تسليم جماعي بالكامل.
     *
     * @return array{ok:bool,error?:string,undone?:int}
     */
    public static function undoDeliveryBatch(int $campaignId, int $batchId, int $userId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'error' => 'سبب التراجع مطلوب.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM delivery_batches WHERE id = ? AND campaign_id = ? LIMIT 1');
        $stmt->execute([$batchId, $campaignId]);
        $batch = $stmt->fetch();
        if (!$batch) {
            return ['ok' => false, 'error' => 'الدفعة غير موجودة.'];
        }
        if (!empty($batch['undone_at'])) {
            return ['ok' => false, 'error' => 'تم التراجع عن هذه الدفعة مسبقاً.'];
        }

        $now = db_now();
        $pdo->beginTransaction();
        try {
            $idsStmt = $pdo->prepare('
                SELECT id FROM beneficiaries
                WHERE campaign_id = ? AND delivery_batch_id = ? AND receipt_status = ?
            ');
            $idsStmt->execute([$campaignId, $batchId, self::STATUS_DELIVERED]);
            $ids = array_map('intval', array_column($idsStmt->fetchAll(), 'id'));
            if ($ids === []) {
                $pdo->prepare('
                    UPDATE delivery_batches
                    SET undone_at = ?, undone_by = ?, undo_reason = ?
                    WHERE id = ?
                ')->execute([$now, $userId, $reason, $batchId]);
                $pdo->commit();
                return ['ok' => true, 'undone' => 0];
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare('
                UPDATE beneficiaries SET
                    receipt_status = ?,
                    delivered_at = NULL,
                    delivered_by = NULL,
                    delivery_type = NULL,
                    actual_delivery_date = NULL,
                    delivery_batch_id = NULL,
                    updated_at = ?
                WHERE campaign_id = ? AND delivery_batch_id = ? AND receipt_status = ?
            ')->execute([self::STATUS_PENDING, $now, $campaignId, $batchId, self::STATUS_DELIVERED]);

            $delParams = $ids;
            array_unshift($delParams, $campaignId);
            $pdo->prepare("
                DELETE FROM delivery_events
                WHERE campaign_id = ? AND action = 'delivered' AND beneficiary_id IN ({$placeholders})
            ")->execute($delParams);

            $smsParams = $ids;
            array_unshift($smsParams, $campaignId);
            $pdo->prepare("
                DELETE FROM sms_outbox
                WHERE campaign_id = ? AND status = 'pending' AND beneficiary_id IN ({$placeholders})
            ")->execute($smsParams);

            $pdo->prepare('
                UPDATE delivery_batches
                SET undone_at = ?, undone_by = ?, undo_reason = ?
                WHERE id = ?
            ')->execute([$now, $userId, $reason, $batchId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['ok' => true, 'undone' => count($ids)];
    }

    /**
     * تصحيح فردي/متعدد للحالة بعد التسليم.
     *
     * @param list<int> $toDeliver ids قيد التسليم → مستلم
     * @param list<int> $toUndeliver ids مستلم → قيد التسليم
     * @return array{ok:bool,error?:string,delivered?:int,undelivered?:int}
     */
    public static function correctDeliveryStatuses(
        int $campaignId,
        int $userId,
        array $toDeliver,
        array $toUndeliver,
        string $reason,
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'error' => 'سبب التصحيح مطلوب.'];
        }

        $toDeliver = array_values(array_unique(array_filter(array_map('intval', $toDeliver), fn ($id) => $id > 0)));
        $toUndeliver = array_values(array_unique(array_filter(array_map('intval', $toUndeliver), fn ($id) => $id > 0)));
        if ($toDeliver === [] && $toUndeliver === []) {
            return ['ok' => false, 'error' => 'لم يتم تحديد أي مستفيد للتصحيح.'];
        }

        $campaign = CampaignService::find($campaignId);
        if (!$campaign || ($campaign['status'] ?? '') !== 'generated') {
            return ['ok' => false, 'error' => 'العملية غير جاهزة.'];
        }

        $deliveredCount = 0;
        $undeliveredCount = 0;

        if ($toDeliver !== []) {
            if (!self::isCampaignActive($campaign)) {
                return ['ok' => false, 'error' => 'التسليم مغلق — أعد فتحه قبل تسجيل مستلمين جدد.'];
            }
            $result = self::bulkMarkDelivered(
                $campaignId,
                $userId,
                $toDeliver,
                'تصحيح فردي: ' . $reason,
            );
            if (!$result['ok']) {
                return $result;
            }
            $deliveredCount = (int) ($result['delivered'] ?? 0);
        }

        if ($toUndeliver !== []) {
            $pdo = Database::getConnection();
            $now = db_now();
            $placeholders = implode(',', array_fill(0, count($toUndeliver), '?'));

            $pdo->beginTransaction();
            try {
                $sel = $pdo->prepare("
                    SELECT id FROM beneficiaries
                    WHERE campaign_id = ? AND receipt_status = ? AND id IN ({$placeholders})
                ");
                $sel->execute(array_merge([$campaignId, self::STATUS_DELIVERED], $toUndeliver));
                $ids = array_map('intval', array_column($sel->fetchAll(), 'id'));
                if ($ids !== []) {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $pdo->prepare("
                        UPDATE beneficiaries SET
                            receipt_status = ?,
                            delivered_at = NULL,
                            delivered_by = NULL,
                            delivery_type = NULL,
                            actual_delivery_date = NULL,
                            delivery_batch_id = NULL,
                            updated_at = ?
                        WHERE campaign_id = ? AND id IN ({$ph})
                    ")->execute(array_merge([self::STATUS_PENDING, $now, $campaignId], $ids));

                    $pdo->prepare("
                        DELETE FROM delivery_events
                        WHERE campaign_id = ? AND action = 'delivered' AND beneficiary_id IN ({$ph})
                    ")->execute(array_merge([$campaignId], $ids));

                    $pdo->prepare("
                        DELETE FROM sms_outbox
                        WHERE campaign_id = ? AND status = 'pending' AND beneficiary_id IN ({$ph})
                    ")->execute(array_merge([$campaignId], $ids));

                    $evt = $pdo->prepare('
                        INSERT INTO delivery_events (beneficiary_id, campaign_id, action, delivery_type, delivered_at, delivered_by, client_id)
                        VALUES (?, ?, \'undelivered\', NULL, ?, ?, ?)
                    ');
                    foreach ($ids as $bid) {
                        $evt->execute([
                            $bid,
                            $campaignId,
                            $now,
                            $userId,
                            substr('fix-undeliver-' . $bid . '-' . time(), 0, 64),
                        ]);
                    }
                    $undeliveredCount = count($ids);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        return [
            'ok' => true,
            'delivered' => $deliveredCount,
            'undelivered' => $undeliveredCount,
        ];
    }
}
