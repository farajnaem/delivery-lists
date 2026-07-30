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
        $prefix = ParcelCodeHelper::normalizePrefix($data['parcel_code'] ?? ParcelCodeHelper::DEFAULT_PREFIX);
        $suffix = ParcelCodeHelper::normalizeSuffix($data['parcel_code_suffix'] ?? '');
        $stmt = $pdo->prepare('
            INSERT INTO campaigns (
                name, pipeline_name, parcel_name, parcel_code, parcel_code_suffix, delivery_start, delivery_end,
                warehouse_name, warehouse_location, num_days, work_start, work_end,
                per_window_capacity, num_windows, opening_quantity, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['name'],
            $data['pipeline_name'] ?? '',
            $data['parcel_name'],
            $prefix,
            $suffix,
            $data['delivery_start'],
            $data['delivery_end'],
            $data['warehouse_name'],
            $data['warehouse_location'],
            (int) $data['num_days'],
            $data['work_start'],
            $data['work_end'],
            (int) $data['per_window_capacity'],
            max(1, (int) ($data['num_windows'] ?? 4)),
            max(0, (int) ($data['opening_quantity'] ?? 0)),
            'draft',
            $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $pdo = Database::getConnection();
        $prefix = ParcelCodeHelper::normalizePrefix($data['parcel_code'] ?? ParcelCodeHelper::DEFAULT_PREFIX);
        $suffix = ParcelCodeHelper::normalizeSuffix($data['parcel_code_suffix'] ?? '');
        $stmt = $pdo->prepare('
            UPDATE campaigns SET
                name = ?, pipeline_name = ?, parcel_name = ?, parcel_code = ?, parcel_code_suffix = ?,
                delivery_start = ?, delivery_end = ?,
                warehouse_name = ?, warehouse_location = ?, num_days = ?,
                work_start = ?, work_end = ?, per_window_capacity = ?, num_windows = ?, opening_quantity = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $data['name'],
            $data['pipeline_name'] ?? '',
            $data['parcel_name'],
            $prefix,
            $suffix,
            $data['delivery_start'],
            $data['delivery_end'],
            $data['warehouse_name'],
            $data['warehouse_location'],
            (int) $data['num_days'],
            $data['work_start'],
            $data['work_end'],
            (int) $data['per_window_capacity'],
            max(1, (int) ($data['num_windows'] ?? 4)),
            max(0, (int) ($data['opening_quantity'] ?? 0)),
            $id,
        ]);
    }

    /** يحدّث أيام التسليم وتاريخ النهاية بعد التوليد (أيام عمل فعلية). */
    public static function updateSchedule(int $id, int $numDays, string $deliveryEnd): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE campaigns SET num_days = ?, delivery_end = ? WHERE id = ?');
        $stmt->execute([max(1, $numDays), $deliveryEnd, $id]);
    }

    public static function markGenerated(int $id): void
    {
        $pdo = Database::getConnection();
        $camp = self::find($id);
        $now = db_now();
        // أول انتقال من مسودة → مولَّد: اقفل التسليم حتى يضغط المدير/المنسّق «بدء»
        if (($camp['status'] ?? '') !== 'generated') {
            $stmt = $pdo->prepare("
                UPDATE campaigns SET
                    status = 'generated',
                    generated_at = ?,
                    delivery_enabled = 0,
                    delivery_opens_at = NULL,
                    delivery_closed_at = NULL
                WHERE id = ?
            ");
            $stmt->execute([$now, $id]);
            return;
        }
        $stmt = $pdo->prepare("UPDATE campaigns SET status = 'generated', generated_at = COALESCE(generated_at, ?) WHERE id = ?");
        $stmt->execute([$now, $id]);
    }

    public static function resetToDraft(int $id): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            UPDATE campaigns SET
                status = 'draft',
                generated_at = NULL,
                delivery_enabled = 0,
                delivery_opens_at = NULL,
                delivery_closed_at = NULL
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }

    /** حذف جميع المستفيدين وإعادة العملية لمسودة (تنظيف). */
    public static function clearBeneficiaries(int $id): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM beneficiaries WHERE campaign_id = ?');
        $stmt->execute([$id]);
        $count = (int) $stmt->fetchColumn();

        $pdo->prepare('DELETE FROM beneficiaries WHERE campaign_id = ?')->execute([$id]);
        self::resetToDraft($id);
        return $count;
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM campaigns WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function deliveredCount(int $id): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM beneficiaries WHERE campaign_id = ? AND receipt_status = 'مستلم'");
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
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

    /**
     * قائمة مستفيدين قابلة للبحث (كل الحالات: معيّن / غير معيّن / مستلم).
     *
     * @return array{rows:list<array>,total:int,page:int,per_page:int}
     */
    public static function searchAllBeneficiaries(
        int $campaignId,
        string $query = '',
        int $page = 1,
        int $perPage = 100,
    ): array {
        $pdo = Database::getConnection();
        $page = max(1, $page);
        $perPage = max(20, min(500, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = 'b.campaign_id = ?';
        $params = [$campaignId];
        $query = ArabicFormat::toWesternDigits(trim($query));
        if ($query !== '') {
            $nid = ArabicFormat::normalizeNationalId($query);
            $nidExpr = ArabicFormat::sqlNormalizeNationalIdExpr('b.national_id');
            $where .= " AND (
                {$nidExpr} = ?
                OR b.name LIKE ?
                OR b.mobile LIKE ?
                OR b.disbursement_code LIKE ?
                OR CAST(b.sort_order AS CHAR) = ?
            )";
            $like = '%' . $query . '%';
            array_push($params, $nid, $like, $like, $like, $nid);
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM beneficiaries b WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "
            SELECT b.*, u.name AS delivered_by_name
            FROM beneficiaries b
            LEFT JOIN users u ON u.id = b.delivered_by
            WHERE {$where}
            ORDER BY
              CASE WHEN b.day_index IS NULL OR b.day_index = 0 THEN 1 ELSE 0 END,
              b.day_index ASC, b.sort_order ASC, b.id ASC
            LIMIT {$perPage} OFFSET {$offset}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public static function stats(int $campaignId): array
    {
        $pdo = Database::getConnection();
        $total = (int) $pdo->query("SELECT COUNT(*) FROM beneficiaries WHERE campaign_id = {$campaignId}")->fetchColumn();
        $delivered = (int) $pdo->query("
            SELECT COUNT(*) FROM beneficiaries
            WHERE campaign_id = {$campaignId} AND receipt_status = 'مستلم'
        ")->fetchColumn();
        $assigned = (int) $pdo->query("
            SELECT COUNT(*) FROM beneficiaries
            WHERE campaign_id = {$campaignId}
              AND day_index IS NOT NULL AND day_index > 0
              AND disbursement_code IS NOT NULL AND disbursement_code != ''
        ")->fetchColumn();
        $days = $pdo->prepare('
            SELECT day_index, delivery_date, COUNT(*) AS cnt,
                   COUNT(DISTINCT window_num) AS windows,
                   MIN(time_from) AS work_start,
                   MAX(time_to) AS work_end
            FROM beneficiaries
            WHERE campaign_id = ?
              AND day_index IS NOT NULL AND day_index > 0
            GROUP BY day_index, delivery_date
            ORDER BY day_index
        ');
        $days->execute([$campaignId]);
        return [
            'total' => $total,
            'delivered' => $delivered,
            'pending' => $total - $delivered,
            'assigned' => $assigned,
            'unassigned' => max(0, $total - $assigned),
            'days' => $days->fetchAll(),
        ];
    }

    public static function unassignedBeneficiaries(int $campaignId, ?int $limit = null): array
    {
        $pdo = Database::getConnection();
        $sql = '
            SELECT id, name, mobile
            FROM beneficiaries
            WHERE campaign_id = ?
              AND (
                    day_index IS NULL OR day_index = 0
                    OR disbursement_code IS NULL OR disbursement_code = \'\'
                  )
            ORDER BY id ASC
        ';
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$campaignId]);
        return $stmt->fetchAll();
    }

    public static function maxDayIndex(int $campaignId): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(day_index), 0) FROM beneficiaries WHERE campaign_id = ?');
        $stmt->execute([$campaignId]);
        return (int) $stmt->fetchColumn();
    }

    public static function maxSortOrder(int $campaignId): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM beneficiaries WHERE campaign_id = ?');
        $stmt->execute([$campaignId]);
        return (int) $stmt->fetchColumn();
    }

    /** آخر تاريخ توزيع معتمد، أو null إن لم يُعتمد أي يوم. */
    public static function lastAssignedDate(int $campaignId): ?string
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT delivery_date
            FROM beneficiaries
            WHERE campaign_id = ?
              AND day_index IS NOT NULL AND day_index > 0
              AND delivery_date IS NOT NULL AND delivery_date != \'\'
            ORDER BY day_index DESC, delivery_date DESC
            LIMIT 1
        ');
        $stmt->execute([$campaignId]);
        $date = $stmt->fetchColumn();
        return $date ? (string) $date : null;
    }

    public static function updateOpeningQuantity(int $id, int $quantity): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE campaigns SET opening_quantity = ? WHERE id = ?');
        $stmt->execute([max(0, $quantity), $id]);
    }

    public static function closeDelivery(int $id): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE campaigns SET delivery_closed_at = ? WHERE id = ?');
        $stmt->execute([db_now(), $id]);
    }

    public static function reopenDelivery(int $id): void
    {
        $pdo = Database::getConnection();
        // إعادة الفتح بعد الإنهاء الإداري = مفتوح فوراً
        $stmt = $pdo->prepare('
            UPDATE campaigns SET
                delivery_closed_at = NULL,
                delivery_enabled = 1,
                delivery_opens_at = NULL
            WHERE id = ?
        ');
        $stmt->execute([$id]);
    }

    /**
     * بدء التسليم الآن أو جدولته لتاريخ/وقت لاحق.
     * @param string|null $opensAt صيغة Y-m-d\TH:i أو Y-m-d H:i:s — فارغ = الآن
     */
    public static function startDelivery(int $id, ?string $opensAt = null): void
    {
        $pdo = Database::getConnection();
        $camp = self::find($id);
        if (!$camp || ($camp['status'] ?? '') !== 'generated') {
            throw new \RuntimeException('العملية غير جاهزة للتسليم.');
        }
        if (trim((string) ($camp['delivery_closed_at'] ?? '')) !== '') {
            throw new \RuntimeException('التسليم مُنهى إدارياً — أعد فتحه من حساب المدير أولاً.');
        }

        $opensAt = trim((string) $opensAt);
        if ($opensAt === '') {
            $stmt = $pdo->prepare('
                UPDATE campaigns SET delivery_enabled = 1, delivery_opens_at = NULL, delivery_closed_at = NULL
                WHERE id = ?
            ');
            $stmt->execute([$id]);
            return;
        }

        $opensAt = str_replace('T', ' ', $opensAt);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $opensAt)) {
            $opensAt .= ':00';
        }
        $ts = strtotime($opensAt);
        if ($ts === false) {
            throw new \RuntimeException('وقت بدء التسليم غير صالح.');
        }

        if ($ts <= time()) {
            $stmt = $pdo->prepare('
                UPDATE campaigns SET delivery_enabled = 1, delivery_opens_at = NULL, delivery_closed_at = NULL
                WHERE id = ?
            ');
            $stmt->execute([$id]);
            return;
        }

        $stmt = $pdo->prepare('
            UPDATE campaigns SET delivery_enabled = 1, delivery_opens_at = ?, delivery_closed_at = NULL
            WHERE id = ?
        ');
        $stmt->execute([date('Y-m-d H:i:s', $ts), $id]);
    }

    /** إيقاف مؤقت قبل/أثناء التشغيل (ليس إنهاءً إدارياً نهائياً). */
    public static function lockDelivery(int $id): void
    {
        $pdo = Database::getConnection();
        $camp = self::find($id);
        if (!$camp || ($camp['status'] ?? '') !== 'generated') {
            throw new \RuntimeException('العملية غير جاهزة.');
        }
        if (trim((string) ($camp['delivery_closed_at'] ?? '')) !== '') {
            throw new \RuntimeException('التسليم مُنهى إدارياً — استخدم إعادة الفتح من المدير.');
        }
        $stmt = $pdo->prepare('
            UPDATE campaigns SET delivery_enabled = 0, delivery_opens_at = NULL
            WHERE id = ?
        ');
        $stmt->execute([$id]);
    }

    public static function isDeliveryOpen(array $campaign): bool
    {
        return DeliveryService::isCampaignActive($campaign);
    }

    /**
     * @return array{state:string,label:string,detail:string}
     */
    public static function deliveryGateStatus(array $campaign): array
    {
        if (trim((string) ($campaign['delivery_closed_at'] ?? '')) !== '') {
            return [
                'state' => 'closed',
                'label' => 'مُنهى',
                'detail' => 'أُنهي يدوياً منذ ' . (string) $campaign['delivery_closed_at'],
            ];
        }

        $enabled = array_key_exists('delivery_enabled', $campaign)
            ? (int) $campaign['delivery_enabled'] === 1
            : true;

        if (!$enabled) {
            return [
                'state' => 'locked',
                'label' => 'لم يبدأ',
                'detail' => 'التسليم مقفل — اضغط بدء التسليم أو حدّد ساعة الفتح.',
            ];
        }

        $opensAt = trim((string) ($campaign['delivery_opens_at'] ?? ''));
        if ($opensAt !== '') {
            $ts = strtotime($opensAt);
            if ($ts !== false && $ts > time()) {
                return [
                    'state' => 'scheduled',
                    'label' => 'مجدول',
                    'detail' => 'يفتح تلقائياً عند: ' . $opensAt,
                ];
            }
        }

        return [
            'state' => 'open',
            'label' => 'مفتوح',
            'detail' => 'يمكن لأمناء المخزن تسجيل التسليم الآن.',
        ];
    }

    /** كود الطرد الكامل للعرض. */
    public static function parcelLabel(array $campaign): string
    {
        return ParcelCodeHelper::formatParcelCode(
            (string) ($campaign['parcel_code'] ?? ParcelCodeHelper::DEFAULT_PREFIX),
            (string) ($campaign['parcel_code_suffix'] ?? '')
        );
    }
}
