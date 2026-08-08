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

    /**
     * حذف مستفيد — للمستلم ممنوع؛ لغير المعيّن أو قيد التسليم (غير مستلم) مسموح.
     *
     * @return array{ok:bool,error?:string,name?:string}
     */
    public static function deleteBeneficiary(int $campaignId, int $beneficiaryId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM beneficiaries WHERE id = ? AND campaign_id = ? LIMIT 1');
        $stmt->execute([$beneficiaryId, $campaignId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => false, 'error' => 'المستفيد غير موجود في هذه العملية.'];
        }
        if (DeliveryService::isDeliveredStatus($row['receipt_status'] ?? '')) {
            return ['ok' => false, 'error' => 'لا يمكن حذف مستفيد مستلم — ألغِ التسليم أولاً إن لزم.'];
        }

        $name = (string) ($row['name'] ?? '');
        try {
            $pdo->prepare('DELETE FROM delivery_events WHERE beneficiary_id = ? AND campaign_id = ?')
                ->execute([$beneficiaryId, $campaignId]);
        } catch (\Throwable) {
        }
        try {
            $pdo->prepare('DELETE FROM sms_outbox WHERE beneficiary_id = ? AND campaign_id = ?')
                ->execute([$beneficiaryId, $campaignId]);
        } catch (\Throwable) {
        }
        $pdo->prepare('DELETE FROM beneficiaries WHERE id = ? AND campaign_id = ?')
            ->execute([$beneficiaryId, $campaignId]);

        return ['ok' => true, 'name' => $name];
    }

    /**
     * حذف مجموعة مستفيدين غير معيّنين (نفس قيود الحذف الفردي).
     *
     * @param list<int|string> $beneficiaryIds
     * @return array{ok:bool,error?:string,deleted?:int,skipped?:int}
     */
    public static function deleteBeneficiariesMany(int $campaignId, array $beneficiaryIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $beneficiaryIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return ['ok' => false, 'error' => 'لم يتم تحديد أي مستفيد للحذف.'];
        }

        $deleted = 0;
        $skipped = 0;
        foreach ($ids as $beneficiaryId) {
            $result = self::deleteBeneficiary($campaignId, $beneficiaryId);
            if (!empty($result['ok'])) {
                $deleted++;
            } else {
                $skipped++;
            }
        }

        if ($deleted < 1) {
            return [
                'ok' => false,
                'error' => 'تعذّر حذف المحددين — قد يكونون معيّنين أو مستلمين.',
                'deleted' => 0,
                'skipped' => $skipped,
            ];
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
        ];
    }

    /**
     * حذف غير المعيّنين حسب أرقام الهوية (مثلاً من ملف Excel مرفوع بالخطأ).
     *
     * @param list<string> $nationalIds
     * @return array{ok:bool,error?:string,deleted?:int,skipped?:int,matched?:int}
     */
    public static function deleteUnassignedByNationalIds(int $campaignId, array $nationalIds): array
    {
        $normalized = [];
        foreach ($nationalIds as $nid) {
            $n = ArabicFormat::normalizeNationalId((string) $nid);
            if ($n !== '') {
                $normalized[$n] = true;
            }
        }
        $list = array_keys($normalized);
        if ($list === []) {
            return ['ok' => false, 'error' => 'لا توجد هويات صالحة للحذف.'];
        }

        $pdo = Database::getConnection();
        $beneficiaryIds = [];
        foreach (array_chunk($list, 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare(
                "SELECT id FROM beneficiaries WHERE campaign_id = ? AND national_id IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$campaignId], $chunk));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $beneficiaryIds[] = (int) $id;
            }
        }

        if ($beneficiaryIds === []) {
            return ['ok' => false, 'error' => 'لا يوجد في هذه العملية أي مستفيد مطابق لهويات الملف.', 'matched' => 0];
        }

        $result = self::deleteBeneficiariesMany($campaignId, $beneficiaryIds);
        $result['matched'] = count($beneficiaryIds);

        return $result;
    }

    /**
     * حذف كل غير المعيّنين من آخر دفعة مضافة (نفس منطق فلتر unassigned_today).
     *
     * @return array{ok:bool,error?:string,deleted?:int,skipped?:int,matched?:int}
     */
    public static function deleteUnassignedAddedOnDate(int $campaignId, ?string $date = null): array
    {
        $pdo = Database::getConnection();
        $delivered = DeliveryService::STATUS_DELIVERED;
        $unassignedExpr = '(day_index IS NULL OR day_index = 0 OR disbursement_code IS NULL OR disbursement_code = \'\')';
        $where = 'campaign_id = ?';
        $params = [$campaignId];
        self::appendUnassignedLatestBatchFilter($pdo, $campaignId, $unassignedExpr, $delivered, $where, $params, '');

        $stmt = $pdo->prepare("SELECT id FROM beneficiaries WHERE {$where}");
        $stmt->execute($params);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            return ['ok' => false, 'error' => 'لا يوجد غير معيّنين من آخر دفعة مضافة للحذف.', 'matched' => 0];
        }

        $result = self::deleteBeneficiariesMany($campaignId, $ids);
        $result['matched'] = count($ids);

        return $result;
    }

    /**
     * فلتر آخر يوم إضافة لغير المعيّنين (أحدث COALESCE(created_at, updated_at) + بلا ختم زمني).
     *
     * @param-out string $where
     * @param-out list<mixed> $params
     */
    private static function appendUnassignedLatestBatchFilter(
        PDO $pdo,
        int $campaignId,
        string $unassignedExpr,
        string $delivered,
        string &$where,
        array &$params,
        string $alias = 'b'
    ): void {
        $p = $alias !== '' ? $alias . '.' : '';
        // تكييف تعبير غير المعيّنين إذا جاء ببادئة b.
        $ua = $unassignedExpr;
        if ($alias === '' && str_contains($unassignedExpr, 'b.')) {
            $ua = str_replace('b.', '', $unassignedExpr);
        } elseif ($alias !== '' && !str_contains($unassignedExpr, 'b.')) {
            $ua = preg_replace(
                '/\b(day_index|disbursement_code)\b/',
                $alias . '.$1',
                $unassignedExpr
            ) ?? $unassignedExpr;
        }

        $where .= " AND {$ua} AND ({$p}receipt_status IS NULL OR {$p}receipt_status != ?)";
        $params[] = $delivered;

        $maxStmt = $pdo->prepare("
            SELECT MAX(COALESCE(created_at, updated_at))
            FROM beneficiaries
            WHERE campaign_id = ?
              AND (day_index IS NULL OR day_index = 0 OR disbursement_code IS NULL OR disbursement_code = '')
              AND (receipt_status IS NULL OR receipt_status != ?)
              AND (
                (created_at IS NOT NULL AND CAST(created_at AS CHAR) != '')
                OR (updated_at IS NOT NULL AND CAST(updated_at AS CHAR) != '')
              )
        ");
        $maxStmt->execute([$campaignId, $delivered]);
        $maxTs = $maxStmt->fetchColumn();

        $days = [date('Y-m-d'), date('Y-m-d', strtotime('-1 day'))];
        if (is_string($maxTs) && preg_match('/^(\d{4}-\d{2}-\d{2})/', $maxTs, $m)) {
            $days[] = $m[1];
        }
        $days = array_values(array_unique($days));

        $parts = [];
        foreach ($days as $day) {
            $parts[] = "CAST(COALESCE({$p}created_at, {$p}updated_at) AS CHAR) LIKE ?";
            $params[] = $day . '%';
        }
        $parts[] = "(
            ({$p}created_at IS NULL OR CAST({$p}created_at AS CHAR) = '')
            AND ({$p}updated_at IS NULL OR CAST({$p}updated_at AS CHAR) = '')
        )";

        $where .= ' AND (' . implode(' OR ', $parts) . ')';
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
     * $filter:
     * - '' : الكل
     * - anomaly : غير معيّن لكن عليه أثر تسليم في السيرفر
     * - unassigned : غير معيّن وغير مستلم
     * - late : معيّن موعده قبل اليوم وما استلم (متأخر لم يحضر)
     * - duplicates : هويات مكررة داخل العملية
     * - no_mobile : معيّن قيد التسليم بدون جوال (غالباً غائب عن كشف الرسائل)
     * - today : مُسلَّمون اليوم (للمطابقة الميدانية)
     * - delivered_no_mobile : مستلم وجواله فاضي (ما يدخل كشف الرسائل)
     * - arabic_id : هوية مخزّنة بأرقام عربية/هندية (بحث الهوية يفشل والكود ينجح)
     *
     * @return array{rows:list<array>,total:int,page:int,per_page:int,filter:string}
     */
    public static function searchAllBeneficiaries(
        int $campaignId,
        string $query = '',
        int $page = 1,
        int $perPage = 100,
        string $filter = '',
    ): array {
        $pdo = Database::getConnection();
        $page = max(1, $page);
        $perPage = max(20, min(500, $perPage));
        $offset = ($page - 1) * $perPage;
        $filter = strtolower(trim($filter));
        $allowed = ['', 'all', 'anomaly', 'unassigned', 'unassigned_today', 'late', 'no_mobile', 'today', 'delivered_no_mobile', 'arabic_id', 'duplicates'];
        if (!in_array($filter, $allowed, true)) {
            $filter = '';
        }
        if ($filter === 'all') {
            $filter = '';
        }

        $delivered = DeliveryService::STATUS_DELIVERED;
        $unassignedExpr = '(b.day_index IS NULL OR b.day_index = 0 OR b.disbursement_code IS NULL OR b.disbursement_code = \'\')';
        $assignedExpr = '(b.day_index IS NOT NULL AND b.day_index > 0 AND b.disbursement_code IS NOT NULL AND b.disbursement_code != \'\')';
        $arabicIdExpr = self::sqlNationalIdHasIndicDigits('b.national_id');
        $today = date('Y-m-d');

        $where = 'b.campaign_id = ?';
        $params = [$campaignId];
        $query = trim(ArabicFormat::toWesternDigits(trim($query)));
        $idList = $query !== '' ? self::extractNationalIdList($query) : [];

        // لصق مجموعة هويات: عرضها دفعة واحدة (مع فلتر غير المعيّنين إن طُلب)
        if (count($idList) >= 2) {
            $perPage = max($perPage, min(500, max(50, count($idList) * 3)));
            $offset = ($page - 1) * $perPage;
            $placeholders = implode(',', array_fill(0, count($idList), '?'));
            $where .= " AND b.national_id IN ({$placeholders})";
            array_push($params, ...$idList);
        } elseif ($query !== '') {
            $nid = ArabicFormat::normalizeNationalId($query);
            $nameLike = '%' . $query . '%';
            // مسار سريع بدون REPLACE على العمود (كان يبطئ البحث جداً على كشوف كبيرة)
            $parts = ['b.name LIKE ?'];
            $params[] = $nameLike;
            if ($nid !== '') {
                $parts[] = 'b.national_id = ?';
                $parts[] = 'b.national_id LIKE ?';
                array_push($params, $nid, '%' . $nid . '%');
                // إن بقي رقم عربي مخزَّن: مطابقة مباشرة للنص المدخل بعد التغريب فقط على المدخل
                if ($nid !== $query) {
                    $parts[] = 'b.national_id = ?';
                    $params[] = $query;
                }
            }
            // كود الصرف فقط إذا بدا الاستعلام كرقم/كود وليس اسماً عربياً طويلاً
            if ($nid !== '' || preg_match('/^[A-Za-z0-9_-]{3,}$/u', $query)) {
                $parts[] = 'b.disbursement_code LIKE ?';
                $params[] = '%' . $query . '%';
            }
            // مركز الإيواء
            $parts[] = 'b.shelter_name LIKE ?';
            $params[] = $nameLike;
            $where .= ' AND (' . implode(' OR ', $parts) . ')';
        }

        if ($filter === 'anomaly') {
            $where .= " AND {$unassignedExpr} AND (
                b.receipt_status = ?
                OR (b.delivered_at IS NOT NULL AND CAST(b.delivered_at AS CHAR) != '')
                OR b.delivered_by IS NOT NULL
                OR (b.actual_delivery_date IS NOT NULL AND b.actual_delivery_date != '')
                OR EXISTS (
                    SELECT 1 FROM delivery_events e
                    WHERE e.beneficiary_id = b.id AND e.campaign_id = b.campaign_id
                      AND e.action = 'delivered'
                )
            )";
            $params[] = $delivered;
        } elseif ($filter === 'unassigned') {
            $where .= " AND {$unassignedExpr} AND (b.receipt_status IS NULL OR b.receipt_status != ?)";
            $params[] = $delivered;
        } elseif ($filter === 'late') {
            $where .= " AND (b.receipt_status IS NULL OR b.receipt_status != ?)
              AND b.delivery_date IS NOT NULL AND b.delivery_date != ''
              AND b.delivery_date < ?";
            array_push($params, $delivered, $today);
        } elseif ($filter === 'unassigned_today') {
            // آخر يوم إضافة فعلي لغير المعيّنين (وليس فقط تقويم «اليوم» — يصلح اختلاف المنطقة/الـ migrate)
            self::appendUnassignedLatestBatchFilter($pdo, $campaignId, $unassignedExpr, $delivered, $where, $params, 'b');
            $perPage = max($perPage, 200);
            $offset = ($page - 1) * $perPage;
        } elseif ($filter === 'duplicates') {
            // بدون تطبيع REPLACE — التجميع على القيمة المخزّنة (بعد migrate الهويات موحّدة)
            $where .= " AND b.national_id IS NOT NULL AND TRIM(b.national_id) != '' AND EXISTS (
                SELECT 1 FROM beneficiaries b2
                WHERE b2.campaign_id = b.campaign_id
                  AND b2.id != b.id
                  AND b2.national_id = b.national_id
            )";
        } elseif ($filter === 'no_mobile') {
            $where .= " AND {$assignedExpr}
              AND (b.receipt_status IS NULL OR b.receipt_status != ?)
              AND (b.mobile IS NULL OR TRIM(b.mobile) = '' OR TRIM(b.mobile) = '0')";
            $params[] = $delivered;
        } elseif ($filter === 'today') {
            $where .= ' AND b.receipt_status = ? AND (
                b.actual_delivery_date = ?
                OR CAST(b.delivered_at AS CHAR) LIKE ?
            )';
            array_push($params, $delivered, $today, $today . '%');
        } elseif ($filter === 'delivered_no_mobile') {
            $where .= " AND b.receipt_status = ?
              AND (b.mobile IS NULL OR TRIM(b.mobile) = '' OR TRIM(b.mobile) = '0')";
            $params[] = $delivered;
        } elseif ($filter === 'arabic_id') {
            $where .= " AND {$arabicIdExpr}";
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM beneficiaries b WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $orderBy = match ($filter) {
            'today' => 'b.delivered_at DESC, b.id DESC',
            'late' => 'b.delivery_date ASC, b.sort_order ASC, b.id ASC',
            'unassigned_today' => 'b.created_at DESC, b.id DESC',
            'duplicates' => 'b.national_id ASC, b.id ASC',
            default => 'b.id DESC',
        };

        $sql = "
            SELECT b.*, u.name AS delivered_by_name
            FROM beneficiaries b
            LEFT JOIN users u ON u.id = b.delivered_by
            WHERE {$where}
            ORDER BY {$orderBy}
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
            'filter' => $filter,
            'id_list_count' => count($idList) >= 2 ? count($idList) : 0,
        ];
    }

    /**
     * استخراج قائمة هويات من نص ملصوق (سطر / فاصلة / مسافة).
     *
     * @return list<string>
     */
    private static function extractNationalIdList(string $query): array
    {
        $western = ArabicFormat::toWesternDigits($query);
        $tokens = preg_split('/[\s,;|\/\\\\]+/u', $western, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ids = [];
        foreach ($tokens as $token) {
            $nid = ArabicFormat::normalizeNationalId($token);
            $nid = preg_replace('/[^\d]/u', '', $nid) ?? $nid;
            if ($nid !== '' && preg_match('/^\d{5,15}$/', $nid)) {
                $ids[$nid] = true;
            }
        }

        return array_keys($ids);
    }

    /** تعبير SQL: رقم الهوية يحتوي أرقاماً عربية/فارسية. */
    private static function sqlNationalIdHasIndicDigits(string $column): string
    {
        $digits = [
            '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩',
            '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹',
        ];
        $parts = [];
        foreach ($digits as $d) {
            $parts[] = "{$column} LIKE '%{$d}%'";
        }
        return '(' . implode(' OR ', $parts) . ')';
    }

    /** عدد غير المعيّنين الذين عليهم أثر تسليم. */
    public static function countDeliveryAnomalies(int $campaignId): int
    {
        return (int) self::searchAllBeneficiaries($campaignId, '', 1, 20, 'anomaly')['total'];
    }

    /**
     * عدّادات مراجعة بمسار واحد سريع (بدل 7 استعلامات ثقيلة كانت تجمّد الصفحة).
     *
     * @return array{today:int,anomaly:int,arabic_id:int,delivered_no_mobile:int,no_mobile:int,unassigned:int,duplicates:int}
     */
    public static function reviewCounts(int $campaignId): array
    {
        $pdo = Database::getConnection();
        $delivered = DeliveryService::STATUS_DELIVERED;
        $today = date('Y-m-d');
        $unassigned = '(day_index IS NULL OR day_index = 0 OR disbursement_code IS NULL OR disbursement_code = \'\')';
        $assigned = '(day_index IS NOT NULL AND day_index > 0 AND disbursement_code IS NOT NULL AND disbursement_code != \'\')';

        $stmt = $pdo->prepare("
            SELECT
              SUM(CASE WHEN {$unassigned} AND (receipt_status IS NULL OR receipt_status != ?) THEN 1 ELSE 0 END) AS unassigned,
              SUM(CASE WHEN receipt_status = ? AND (
                    actual_delivery_date = ? OR CAST(delivered_at AS CHAR) LIKE ?
                  ) THEN 1 ELSE 0 END) AS today,
              SUM(CASE WHEN {$assigned} AND (receipt_status IS NULL OR receipt_status != ?)
                    AND (mobile IS NULL OR TRIM(mobile) = '' OR TRIM(mobile) = '0') THEN 1 ELSE 0 END) AS no_mobile,
              SUM(CASE WHEN receipt_status = ?
                    AND (mobile IS NULL OR TRIM(mobile) = '' OR TRIM(mobile) = '0') THEN 1 ELSE 0 END) AS delivered_no_mobile,
              SUM(CASE WHEN {$unassigned} AND (
                    receipt_status = ?
                    OR (delivered_at IS NOT NULL AND CAST(delivered_at AS CHAR) != '')
                    OR delivered_by IS NOT NULL
                    OR (actual_delivery_date IS NOT NULL AND actual_delivery_date != '')
                  ) THEN 1 ELSE 0 END) AS anomaly
            FROM beneficiaries
            WHERE campaign_id = ?
        ");
        $stmt->execute([
            $delivered,
            $delivered, $today, $today . '%',
            $delivered,
            $delivered,
            $delivered,
            $campaignId,
        ]);
        $row = $stmt->fetch() ?: [];

        $dupStmt = $pdo->prepare('
            SELECT COUNT(*) FROM beneficiaries b
            WHERE b.campaign_id = ?
              AND b.national_id IS NOT NULL AND TRIM(b.national_id) != \'\'
              AND EXISTS (
                SELECT 1 FROM beneficiaries b2
                WHERE b2.campaign_id = b.campaign_id
                  AND b2.id != b.id
                  AND b2.national_id = b.national_id
              )
        ');
        $dupStmt->execute([$campaignId]);

        return [
            'today' => (int) ($row['today'] ?? 0),
            'anomaly' => (int) ($row['anomaly'] ?? 0),
            'arabic_id' => 0,
            'delivered_no_mobile' => (int) ($row['delivered_no_mobile'] ?? 0),
            'no_mobile' => (int) ($row['no_mobile'] ?? 0),
            'unassigned' => (int) ($row['unassigned'] ?? 0),
            'duplicates' => (int) $dupStmt->fetchColumn(),
        ];
    }

    /**
     * تعديل بيانات مرشح (اسم / هوية / جوال).
     * للمستلم: الاسم والجوال فقط. لغير المستلم: الثلاثة مع منع تكرار الهوية.
     *
     * @return array{ok:bool,error?:string}
     */
    public static function updateBeneficiary(int $campaignId, int $beneficiaryId, array $data): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM beneficiaries WHERE id = ? AND campaign_id = ? LIMIT 1');
        $stmt->execute([$beneficiaryId, $campaignId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => false, 'error' => 'المستفيد غير موجود في هذه العملية.'];
        }

        $name = trim((string) ($data['name'] ?? ''));
        $mobile = PhoneHelper::normalize((string) ($data['mobile'] ?? ''));
        $nid = ArabicFormat::normalizeNationalId($data['national_id'] ?? '');
        $delivered = DeliveryService::isDeliveredStatus($row['receipt_status'] ?? '');

        if ($name === '') {
            return ['ok' => false, 'error' => 'الاسم مطلوب.'];
        }

        if ($delivered) {
            $pdo->prepare('UPDATE beneficiaries SET name = ?, mobile = ?, updated_at = ? WHERE id = ? AND campaign_id = ?')
                ->execute([$name, $mobile, db_now(), $beneficiaryId, $campaignId]);
            return ['ok' => true];
        }

        if ($nid === '') {
            return ['ok' => false, 'error' => 'رقم الهوية مطلوب.'];
        }

        $dup = $pdo->prepare('
            SELECT id FROM beneficiaries
            WHERE campaign_id = ? AND national_id = ? AND id != ?
            LIMIT 1
        ');
        $dup->execute([$campaignId, $nid, $beneficiaryId]);
        if ($dup->fetch()) {
            return ['ok' => false, 'error' => 'رقم الهوية مكرر لمستفيد آخر في نفس العملية.'];
        }

        $pdo->prepare('
            UPDATE beneficiaries
            SET name = ?, national_id = ?, mobile = ?, updated_at = ?
            WHERE id = ? AND campaign_id = ?
        ')->execute([$name, $nid, $mobile, db_now(), $beneficiaryId, $campaignId]);

        return ['ok' => true];
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
        $deliveredStatus = DeliveryService::STATUS_DELIVERED;
        // لكل يوم مخطط:
        // - مستلم: من مخطط هذا اليوم واستلم في الموعد
        // - غير مستلم: من مخطط هذا اليوم وما استلم بعد
        // - مستلم من المتأخرين: استلم في تاريخ هذا اليوم وموعده كان يوماً سابقاً
        $days = $pdo->prepare('
            SELECT b.day_index, b.delivery_date, COUNT(*) AS cnt,
                   COUNT(DISTINCT b.window_num) AS windows,
                   MIN(b.time_from) AS work_start,
                   MAX(b.time_to) AS work_end,
                   SUM(CASE
                         WHEN b.receipt_status = ?
                          AND COALESCE(b.delivery_type, \'\') != \'late\'
                         THEN 1 ELSE 0
                       END) AS delivered,
                   SUM(CASE
                         WHEN b.receipt_status IS NULL OR b.receipt_status != ?
                         THEN 1 ELSE 0
                       END) AS pending,
                   (
                     SELECT COUNT(*)
                     FROM beneficiaries late_b
                     WHERE late_b.campaign_id = b.campaign_id
                       AND late_b.receipt_status = ?
                       AND late_b.actual_delivery_date IS NOT NULL
                       AND late_b.actual_delivery_date != \'\'
                       AND late_b.actual_delivery_date = b.delivery_date
                       AND late_b.delivery_date IS NOT NULL
                       AND late_b.delivery_date != \'\'
                       AND late_b.delivery_date < b.delivery_date
                   ) AS delivered_late
            FROM beneficiaries b
            WHERE b.campaign_id = ?
              AND b.day_index IS NOT NULL AND b.day_index > 0
            GROUP BY b.campaign_id, b.day_index, b.delivery_date
            ORDER BY b.day_index
        ');
        $days->execute([
            $deliveredStatus,
            $deliveredStatus,
            $deliveredStatus,
            $campaignId,
        ]);

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
        // المستلمون لا يُعاد تعيينهم أبداً — حتى لو بلا يوم/كود (استلام يدوي سابق).
        $delivered = DeliveryService::STATUS_DELIVERED;
        $sql = '
            SELECT id, name, mobile
            FROM beneficiaries
            WHERE campaign_id = ?
              AND (receipt_status IS NULL OR receipt_status != ?)
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
        $stmt->execute([$campaignId, $delivered]);
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
