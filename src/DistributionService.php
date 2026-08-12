<?php

declare(strict_types=1);

namespace App;

final class DistributionService
{
    /**
     * خطة التوزيع: إجمالي → أيام (من طاقة الشبابيك) → شبابيك ثابتة → مستفيدين/شباك.
     *
     * مثال: 8000، 4 شبابيك، 400/شباك → يومي 1600 → 5 أيام عمل → 20 كشف.
     *
     * @return array{
     *   total:int,
     *   num_days:int,
     *   num_windows:int,
     *   per_window:int,
     *   daily_capacity:int,
     *   daily_counts:list<int>,
     *   total_delivery_sheets:int,
     *   days:list<array{day_index:int,beneficiaries:int,windows:int,per_window:list<int>}>
     * }
     */
    public static function plan(int $total, int $numWindows, int $perWindow): array
    {
        $numWindows = max(1, $numWindows);
        $perWindow = max(1, $perWindow);
        $dailyCapacity = $numWindows * $perWindow;
        $numDays = $total > 0 ? max(1, (int) ceil($total / $dailyCapacity)) : 1;
        $dailyCounts = self::splitCount($total, $numDays);
        $days = [];
        $totalSheets = 0;

        foreach ($dailyCounts as $i => $dayCount) {
            $windowsThisDay = $dayCount > 0 ? $numWindows : 0;
            $windowSizes = $windowsThisDay > 0
                ? self::splitCount($dayCount, $windowsThisDay)
                : [];
            $totalSheets += $windowsThisDay;
            $days[] = [
                'day_index' => $i + 1,
                'beneficiaries' => $dayCount,
                'windows' => $windowsThisDay,
                'per_window' => $windowSizes,
            ];
        }

        return [
            'total' => $total,
            'num_days' => $numDays,
            'num_windows' => $numWindows,
            'per_window' => $perWindow,
            'daily_capacity' => $dailyCapacity,
            'daily_counts' => $dailyCounts,
            'total_delivery_sheets' => $totalSheets,
            'days' => $days,
        ];
    }

    public static function generate(int $campaignId): array
    {
        extend_runtime(1800);
        @ignore_user_abort(true);

        $campaign = CampaignService::find($campaignId);
        if (!$campaign) {
            throw new \RuntimeException('العملية غير موجودة.');
        }

        $stats = CampaignService::stats($campaignId);
        if ((int) ($stats['assigned'] ?? 0) > 0) {
            throw new \RuntimeException(
                'يوجد أيام توزيع معتمدة مسبقاً. استخدم «اعتماد يوم توزيع» حتى لا تتغير أكواد ورسائل الأيام السابقة. '
                . 'لإعادة توزيع الكل من الصفر يجب ألا يكون هناك مستفيدون معيّنون لأيام.'
            );
        }

        if (!ParcelCodeHelper::validatePrefix((string) ($campaign['parcel_code'] ?? ''))) {
            throw new \RuntimeException('أدخل كود الطرد (حرف أو مجموعة حروف مثل SOCI أو REC).');
        }

        $codePrefix = (string) ($campaign['parcel_code'] ?? ParcelCodeHelper::DEFAULT_PREFIX);
        $codeSuffix = (string) ($campaign['parcel_code_suffix'] ?? '');

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, name, mobile FROM beneficiaries WHERE campaign_id = ? ORDER BY id ASC');
        $stmt->execute([$campaignId]);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            throw new \RuntimeException('لا يوجد مستفيدون — ارفع ملف Excel أولاً.');
        }

        $total = count($rows);
        $perWindow = max(1, (int) $campaign['per_window_capacity']);
        $numWindows = self::resolveNumWindows($campaign, $total, $perWindow);
        $plan = self::plan($total, $numWindows, $perWindow);
        $dayBuckets = $plan['daily_counts'];
        $numDays = $plan['num_days'];
        $dates = self::buildWorkDates((string) $campaign['delivery_start'], $numDays);

        $pins = self::allocateUniquePins($total);
        if (count($pins) !== count(array_unique($pins))) {
            throw new \RuntimeException('تعذّر توليد أكواد صرف فريدة — قلّل عدد المستفيدين.');
        }
        $pinIdx = 0;
        $assignedCodes = [];
        $sortOrder = 1;
        $idx = 0;
        $summary = $plan;
        $summary['dates'] = $dates;
        $genNow = db_now();

        /** @var list<array{0:string,1:string,2:string,3:int,4:string,5:string,6:string,7:int,8:int,9:string,10:int}> */
        $batch = [];

        $upd = $pdo->prepare('
            UPDATE beneficiaries SET
                mobile = ?,
                disbursement_code = ?, delivery_date = ?, window_num = ?,
                time_from = ?, time_to = ?, message_text = ?,
                day_index = ?, sort_order = ?, updated_at = ?
            WHERE id = ?
        ');

        $flushBatch = static function () use ($pdo, $upd, &$batch): void {
            if ($batch === []) {
                return;
            }
            $pdo->beginTransaction();
            try {
                foreach ($batch as $params) {
                    $upd->execute($params);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            $batch = [];
        };

        for ($d = 0; $d < $numDays; $d++) {
            $dayCount = $dayBuckets[$d];
            $dayRows = array_slice($rows, $idx, $dayCount);
            $idx += $dayCount;

            $windowsThisDay = (int) ($plan['days'][$d]['windows'] ?? 0);
            $windowBuckets = $windowsThisDay > 0
                ? self::splitCount($dayCount, $windowsThisDay)
                : [];
            $summary['days'][$d]['date'] = $dates[$d];
            $summary['days'][$d]['window_sizes'] = $windowBuckets;

            $rowOffset = 0;

            for ($w = 0; $w < $windowsThisDay; $w++) {
                $windowCount = $windowBuckets[$w];
                $windowRows = array_slice($dayRows, $rowOffset, $windowCount);
                $rowOffset += $windowCount;

                if ($windowCount === 0) {
                    continue;
                }

                // أكواد + توزيع ساعات بالتساوي، ثم ترتيب الكشف أبجدياً حسب الاسم
                $prepared = [];
                foreach ($windowRows as $row) {
                    if ($pinIdx >= count($pins)) {
                        throw new \RuntimeException('تعذّر توليد أكواد صرف فريدة — عدد المستفيدين أكبر من المتاح.');
                    }
                    $pin = $pins[$pinIdx++];
                    $code = ParcelCodeHelper::buildDisbursementCode($codePrefix, $codeSuffix, $pin);
                    $assignedCodes[] = $code;
                    $prepared[] = [
                        'id' => (int) $row['id'],
                        'name' => (string) $row['name'],
                        'mobile' => PhoneHelper::normalize($row['mobile']),
                        'code' => $code,
                    ];
                }

                $slots = self::buildTimeSlots(
                    $campaign['work_start'],
                    $campaign['work_end'],
                    count($prepared)
                );
                foreach ($prepared as $i => &$item) {
                    $item['time_from'] = $slots[$i]['from'];
                    $item['time_to'] = $slots[$i]['to'];
                }
                unset($item);

                usort($prepared, static fn ($a, $b) => self::compareNames($a['name'], $b['name']));

                foreach ($prepared as $item) {
                    $message = MessageTemplates::appointment(
                        $campaign,
                        $item['name'],
                        $dates[$d],
                        $item['code'],
                        $w + 1,
                        $item['time_from'],
                        $item['time_to']
                    );
                    $batch[] = [
                        $item['mobile'],
                        $item['code'],
                        $dates[$d],
                        $w + 1,
                        $item['time_from'],
                        $item['time_to'],
                        $message,
                        $d + 1,
                        $sortOrder,
                        $genNow,
                        $item['id'],
                    ];
                    $sortOrder++;

                    if (count($batch) >= 250) {
                        $flushBatch();
                    }
                }
            }
        }

        $flushBatch();

        ParcelCodeHelper::assertUniqueDisbursementCodes($assignedCodes, $codePrefix, $codeSuffix);

        $dupStmt = $pdo->prepare('
            SELECT disbursement_code, COUNT(*) AS c
            FROM beneficiaries
            WHERE campaign_id = ? AND disbursement_code IS NOT NULL AND disbursement_code != \'\'
            GROUP BY disbursement_code
            HAVING c > 1
            LIMIT 1
        ');
        $dupStmt->execute([$campaignId]);
        $dup = $dupStmt->fetch();
        if ($dup) {
            throw new \RuntimeException(
                'كود الصرف مكرّر في قاعدة البيانات: ' . (string) ($dup['disbursement_code'] ?? '')
            );
        }

        $deliveryEnd = $dates !== [] ? $dates[array_key_last($dates)] : (string) $campaign['delivery_start'];
        CampaignService::updateSchedule($campaignId, $numDays, $deliveryEnd);
        CampaignService::markGenerated($campaignId);
        if ((int) ($campaign['opening_quantity'] ?? 0) <= 0) {
            CampaignService::updateOpeningQuantity($campaignId, $total);
        }
        return $summary;
    }

    /**
     * اعتماد يوم توزيع واحد من المستفيدين غير المعيّنين فقط.
     * لا يمس الأيام السابقة (أكواد / رسائل / مواعيد).
     * يمكن تمرير ساعات عمل خاصة بهذا اليوم؛ وإلا تُستخدم ساعات العملية.
     *
     * $selectionMode:
     * - registration: أول غير المعيّنين حسب ترتيب التسجيل (id ASC)
     * - random: سحب عشوائي من غير المعيّنين
     *
     * @return array{
     *   day_index:int,
     *   date:string,
     *   beneficiaries:int,
     *   windows:int,
     *   per_window:list<int>,
     *   work_start:string,
     *   work_end:string,
     *   unassigned_remaining:int,
     *   selection_mode:string
     * }
     */
    public static function generateDay(
        int $campaignId,
        int $beneficiaryCount,
        int $numWindows,
        ?string $deliveryDate = null,
        ?string $workStart = null,
        ?string $workEnd = null,
        string $selectionMode = 'registration',
    ): array {
        extend_runtime(600);
        @ignore_user_abort(true);

        $campaign = CampaignService::find($campaignId);
        if (!$campaign) {
            throw new \RuntimeException('العملية غير موجودة.');
        }
        if (!ParcelCodeHelper::validatePrefix((string) ($campaign['parcel_code'] ?? ''))) {
            throw new \RuntimeException('أدخل كود الطرد (حرف أو مجموعة حروف مثل SOCI أو REC).');
        }

        $beneficiaryCount = max(1, $beneficiaryCount);
        $numWindows = max(1, $numWindows);
        $selectionMode = self::normalizeSelectionMode($selectionMode);
        [$dayWorkStart, $dayWorkEnd] = self::resolveDayWorkHours($campaign, $workStart, $workEnd);

        $unassigned = CampaignService::unassignedBeneficiaries($campaignId);
        $available = count($unassigned);
        if ($available < 1) {
            throw new \RuntimeException('لا يوجد مستفيدون غير معيّنين — ارفع Excel أو انتظر دفعة جديدة.');
        }
        if ($beneficiaryCount > $available) {
            throw new \RuntimeException("المتبقي غير المعيّن {$available} فقط — لا يمكن اعتماد {$beneficiaryCount}.");
        }

        if ($selectionMode === 'random') {
            shuffle($unassigned);
        }
        $dayRows = array_slice($unassigned, 0, $beneficiaryCount);
        $dayIndex = CampaignService::maxDayIndex($campaignId) + 1;
        $date = self::resolveNextDayDate($campaign, $deliveryDate);
        $windowBuckets = self::splitCount($beneficiaryCount, $numWindows);
        $codePrefix = (string) ($campaign['parcel_code'] ?? ParcelCodeHelper::DEFAULT_PREFIX);
        $codeSuffix = (string) ($campaign['parcel_code_suffix'] ?? '');
        $usedPins = self::usedPinsForCampaign($campaignId, $codePrefix, $codeSuffix);
        $pins = self::allocateUniquePins($beneficiaryCount, $usedPins);
        $pinIdx = 0;
        $assignedCodes = [];
        $sortOrder = CampaignService::maxSortOrder($campaignId) + 1;
        $genNow = db_now();
        $pdo = Database::getConnection();

        $upd = $pdo->prepare('
            UPDATE beneficiaries SET
                mobile = ?,
                disbursement_code = ?, delivery_date = ?, window_num = ?,
                time_from = ?, time_to = ?, message_text = ?,
                day_index = ?, sort_order = ?, updated_at = ?
            WHERE id = ? AND campaign_id = ?
              AND (day_index IS NULL OR day_index = 0 OR disbursement_code IS NULL OR disbursement_code = \'\')
        ');

        $pdo->beginTransaction();
        try {
            $rowOffset = 0;
            for ($w = 0; $w < $numWindows; $w++) {
                $windowCount = $windowBuckets[$w];
                $windowRows = array_slice($dayRows, $rowOffset, $windowCount);
                $rowOffset += $windowCount;
                if ($windowCount === 0) {
                    continue;
                }

                $prepared = [];
                foreach ($windowRows as $row) {
                    $pin = $pins[$pinIdx++];
                    $code = ParcelCodeHelper::buildDisbursementCode($codePrefix, $codeSuffix, $pin);
                    $assignedCodes[] = $code;
                    $prepared[] = [
                        'id' => (int) $row['id'],
                        'name' => (string) $row['name'],
                        'mobile' => PhoneHelper::normalize($row['mobile']),
                        'code' => $code,
                    ];
                }

                $slots = self::buildTimeSlots(
                    $dayWorkStart,
                    $dayWorkEnd,
                    count($prepared)
                );
                foreach ($prepared as $i => &$item) {
                    $item['time_from'] = $slots[$i]['from'];
                    $item['time_to'] = $slots[$i]['to'];
                }
                unset($item);

                usort($prepared, static fn ($a, $b) => self::compareNames($a['name'], $b['name']));

                foreach ($prepared as $item) {
                    $message = MessageTemplates::appointment(
                        $campaign,
                        $item['name'],
                        $date,
                        $item['code'],
                        $w + 1,
                        $item['time_from'],
                        $item['time_to']
                    );
                    $upd->execute([
                        $item['mobile'],
                        $item['code'],
                        $date,
                        $w + 1,
                        $item['time_from'],
                        $item['time_to'],
                        $message,
                        $dayIndex,
                        $sortOrder,
                        $genNow,
                        $item['id'],
                        $campaignId,
                    ]);
                    if ($upd->rowCount() < 1) {
                        throw new \RuntimeException('تعذّر اعتماد أحد المستفيدين (ربما تغيّرت حالته). أعد المحاولة.');
                    }
                    $sortOrder++;
                }
            }

            ParcelCodeHelper::assertUniqueDisbursementCodes($assignedCodes, $codePrefix, $codeSuffix);

            $dupStmt = $pdo->prepare('
                SELECT disbursement_code, COUNT(*) AS c
                FROM beneficiaries
                WHERE campaign_id = ? AND disbursement_code IS NOT NULL AND disbursement_code != \'\'
                GROUP BY disbursement_code
                HAVING c > 1
                LIMIT 1
            ');
            $dupStmt->execute([$campaignId]);
            $dup = $dupStmt->fetch();
            if ($dup) {
                throw new \RuntimeException(
                    'كود الصرف مكرّر في قاعدة البيانات: ' . (string) ($dup['disbursement_code'] ?? '')
                );
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $maxDay = CampaignService::maxDayIndex($campaignId);
        CampaignService::updateSchedule($campaignId, max(1, $maxDay), $date);
        CampaignService::markGenerated($campaignId);
        $total = (int) (CampaignService::stats($campaignId)['total'] ?? 0);
        if ((int) ($campaign['opening_quantity'] ?? 0) <= 0 && $total > 0) {
            CampaignService::updateOpeningQuantity($campaignId, $total);
        }

        $remaining = (int) (CampaignService::stats($campaignId)['unassigned'] ?? 0);

        return [
            'day_index' => $dayIndex,
            'date' => $date,
            'beneficiaries' => $beneficiaryCount,
            'windows' => $numWindows,
            'per_window' => $windowBuckets,
            'work_start' => $dayWorkStart,
            'work_end' => $dayWorkEnd,
            'unassigned_remaining' => $remaining,
            'selection_mode' => $selectionMode,
        ];
    }

    /** @return 'registration'|'random' */
    public static function normalizeSelectionMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return $mode === 'random' ? 'random' : 'registration';
    }

    /**
     * إلغاء آخر يوم معتمد فقط (الأعلى day_index).
     * يعيد مستفيديه إلى غير المعيّنين دون لمس الأيام السابقة.
     * محظور إن وُجد أي مستلم في ذلك اليوم.
     *
     * @return array{
     *   day_index:int,
     *   date:string,
     *   beneficiaries:int,
     *   unassigned_remaining:int,
     *   remaining_days:int
     * }
     */
    public static function cancelLastDay(int $campaignId): array
    {
        $campaign = CampaignService::find($campaignId);
        if (!$campaign) {
            throw new \RuntimeException('العملية غير موجودة.');
        }

        $dayIndex = CampaignService::maxDayIndex($campaignId);
        if ($dayIndex < 1) {
            throw new \RuntimeException('لا يوجد يوم معتمد لإلغائه.');
        }

        $pdo = Database::getConnection();

        $metaStmt = $pdo->prepare('
            SELECT delivery_date, COUNT(*) AS cnt
            FROM beneficiaries
            WHERE campaign_id = ? AND day_index = ?
            GROUP BY delivery_date
            ORDER BY cnt DESC
            LIMIT 1
        ');
        $metaStmt->execute([$campaignId, $dayIndex]);
        $meta = $metaStmt->fetch() ?: null;
        if (!$meta || (int) ($meta['cnt'] ?? 0) < 1) {
            throw new \RuntimeException('لا يوجد يوم معتمد لإلغائه.');
        }
        $dayDate = (string) ($meta['delivery_date'] ?? '');
        $count = (int) $meta['cnt'];

        $deliveredStmt = $pdo->prepare('
            SELECT COUNT(*) FROM beneficiaries
            WHERE campaign_id = ? AND day_index = ? AND receipt_status = ?
        ');
        $deliveredStmt->execute([$campaignId, $dayIndex, DeliveryService::STATUS_DELIVERED]);
        $delivered = (int) $deliveredStmt->fetchColumn();
        if ($delivered > 0) {
            throw new \RuntimeException(
                "لا يمكن إلغاء اليوم {$dayIndex}: يوجد {$delivered} مستفيد مستلم. ألغِ التسليمات أولاً ثم أعد المحاولة."
            );
        }

        $idStmt = $pdo->prepare('SELECT id FROM beneficiaries WHERE campaign_id = ? AND day_index = ?');
        $idStmt->execute([$campaignId, $dayIndex]);
        $beneficiaryIds = array_map('intval', $idStmt->fetchAll(\PDO::FETCH_COLUMN));

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare('
                UPDATE beneficiaries SET
                    disbursement_code = NULL,
                    delivery_date = NULL,
                    window_num = NULL,
                    time_from = NULL,
                    time_to = NULL,
                    message_text = NULL,
                    day_index = NULL,
                    sort_order = NULL,
                    delivery_batch_id = NULL,
                    updated_at = ?
                WHERE campaign_id = ? AND day_index = ?
                  AND (receipt_status IS NULL OR receipt_status != ?)
            ');
            $upd->execute([db_now(), $campaignId, $dayIndex, DeliveryService::STATUS_DELIVERED]);

            $leftStmt = $pdo->prepare('SELECT COUNT(*) FROM beneficiaries WHERE campaign_id = ? AND day_index = ?');
            $leftStmt->execute([$campaignId, $dayIndex]);
            if ((int) $leftStmt->fetchColumn() > 0) {
                throw new \RuntimeException('تعذّر إلغاء اليوم بالكامل (ربما تغيّرت حالة التسليم). أعد المحاولة.');
            }

            if ($beneficiaryIds !== []) {
                $placeholders = implode(',', array_fill(0, count($beneficiaryIds), '?'));
                $pdo->prepare("
                    DELETE FROM sms_outbox
                    WHERE campaign_id = ? AND beneficiary_id IN ({$placeholders})
                ")->execute(array_merge([$campaignId], $beneficiaryIds));

                try {
                    $pdo->prepare("
                        DELETE FROM delivery_events
                        WHERE campaign_id = ? AND beneficiary_id IN ({$placeholders})
                    ")->execute(array_merge([$campaignId], $beneficiaryIds));
                } catch (\Throwable) {
                    // الجدول قد لا يوجد في بيئات قديمة
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $maxDay = CampaignService::maxDayIndex($campaignId);
        if ($maxDay < 1) {
            CampaignService::resetToDraft($campaignId);
            $start = (string) ($campaign['delivery_start'] ?? date('Y-m-d'));
            CampaignService::updateSchedule($campaignId, 1, $start);
        } else {
            $lastDate = CampaignService::lastAssignedDate($campaignId)
                ?? (string) ($campaign['delivery_start'] ?? date('Y-m-d'));
            CampaignService::updateSchedule($campaignId, $maxDay, $lastDate);
        }

        $remaining = (int) (CampaignService::stats($campaignId)['unassigned'] ?? 0);

        return [
            'day_index' => $dayIndex,
            'date' => $dayDate,
            'beneficiaries' => $count,
            'unassigned_remaining' => $remaining,
            'remaining_days' => $maxDay,
        ];
    }

    /** تاريخ اليوم التالي المقترح (يتخطى الجمعة). */
    public static function suggestNextDayDate(array $campaign): string
    {
        return self::resolveNextDayDate($campaign, null);
    }

    private static function resolveNextDayDate(array $campaign, ?string $requested): string
    {
        $requested = trim((string) $requested);
        if ($requested !== '') {
            $ts = strtotime($requested);
            if ($ts === false) {
                throw new \RuntimeException('تاريخ اليوم غير صالح.');
            }
            if ((int) date('N', $ts) === 5) {
                throw new \RuntimeException('لا يمكن اعتماد يوم جمعة — اختر يوماً آخر.');
            }
            return date('Y-m-d', $ts);
        }

        $campaignId = (int) ($campaign['id'] ?? 0);
        $last = $campaignId > 0 ? CampaignService::lastAssignedDate($campaignId) : null;
        if ($last) {
            $ts = strtotime($last . ' +1 day') ?: time();
        } else {
            $ts = strtotime((string) ($campaign['delivery_start'] ?? 'now')) ?: time();
        }

        $guard = 0;
        while ((int) date('N', $ts) === 5 && $guard < 14) {
            $ts = strtotime('+1 day', $ts) ?: ($ts + 86400);
            $guard++;
        }
        return date('Y-m-d', $ts);
    }

    /**
     * @return array<int, true> pins already used in campaign
     */
    private static function usedPinsForCampaign(int $campaignId, string $prefix, string $suffix): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT disbursement_code
            FROM beneficiaries
            WHERE campaign_id = ?
              AND disbursement_code IS NOT NULL AND disbursement_code != ''
        ");
        $stmt->execute([$campaignId]);
        $used = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $code = (string) ($row['disbursement_code'] ?? '');
            $pin = ParcelCodeHelper::pinAsInt($code, $suffix !== '' ? $suffix : null, $prefix !== '' ? $prefix : null);
            if ($pin >= ParcelCodeHelper::PIN_MIN) {
                $used[$pin] = true;
            }
        }
        return $used;
    }

    /** @return list<int> */
    private static function allocateUniquePins(int $count, array $used = []): array
    {
        if ($count < 1) {
            return [];
        }
        $pool = ParcelCodeHelper::PIN_MAX - ParcelCodeHelper::PIN_MIN + 1;
        if ($count > $pool - count($used)) {
            throw new \RuntimeException('عدد المستفيدين أكبر من الحد الأقصى لأكواد الصرف المتاحة.');
        }

        $pins = [];
        for ($i = 0; $i < $count; $i++) {
            $pins[] = ParcelCodeHelper::generateRandomPin($used);
        }

        return $pins;
    }

    /** للعمليات القديمة بدون num_windows: يستنتج من الأيام والسعة السابقة. */
    public static function resolveNumWindows(array $campaign, int $total, int $perWindow): int
    {
        $numWindows = (int) ($campaign['num_windows'] ?? 0);
        if ($numWindows >= 1) {
            return $numWindows;
        }

        $legacyDays = max(1, (int) ($campaign['num_days'] ?? 1));
        $approxDaily = $total > 0 ? (int) ceil($total / $legacyDays) : $perWindow;
        return self::windowsForDay($approxDaily, $perWindow);
    }

    /** عدد الشبابيك ليوم واحد = ceil(مستفيدي_اليوم ÷ سعة_الشباك) — للتوافق مع العمليات القديمة. */
    public static function windowsForDay(int $dayCount, int $perWindow): int
    {
        if ($dayCount <= 0) {
            return 0;
        }
        return max(1, (int) ceil($dayCount / $perWindow));
    }

    /** @return list<int> */
    public static function splitCount(int $total, int $parts): array
    {
        if ($parts < 1) {
            $parts = 1;
        }
        if ($total <= 0) {
            return array_fill(0, $parts, 0);
        }
        $base = intdiv($total, $parts);
        $remainder = $total % $parts;
        $result = [];
        for ($i = 0; $i < $parts; $i++) {
            $result[] = $base + ($i < $remainder ? 1 : 0);
        }
        return $result;
    }

    /**
     * أيام عمل متتالية من تاريخ البداية مع تخطّي الجمعة.
     *
     * @return list<string>
     */
    public static function buildWorkDates(string $start, int $workDays): array
    {
        $dates = [];
        $ts = strtotime($start) ?: time();
        $guard = 0;
        while (count($dates) < $workDays && $guard < 500) {
            // N: 1=Mon … 5=Fri … 7=Sun
            if ((int) date('N', $ts) !== 5) {
                $dates[] = date('Y-m-d', $ts);
            }
            $ts = strtotime('+1 day', $ts) ?: ($ts + 86400);
            $guard++;
        }
        return $dates;
    }

    /** مقارنة أسماء عربية تصاعدياً (ألف → ياء). */
    public static function compareNames(string $a, string $b): int
    {
        $a = trim($a);
        $b = trim($b);
        if (class_exists(\Collator::class)) {
            static $collator = null;
            if ($collator === null) {
                $collator = new \Collator('ar');
            }
            $cmp = $collator->compare($a, $b);
            return is_int($cmp) ? $cmp : strcmp($a, $b);
        }

        return strcmp($a, $b);
    }

    /**
     * يقسم العدد على الأجزاء بالتساوي، والباقي على الأجزاء الأخيرة.
     * مثال: 400 على 6 ساعات → 66,66,67,67,67,67
     *
     * @return list<int>
     */
    public static function splitCountEndHeavy(int $total, int $parts): array
    {
        if ($parts < 1) {
            $parts = 1;
        }
        if ($total <= 0) {
            return array_fill(0, $parts, 0);
        }
        $base = intdiv($total, $parts);
        $remainder = $total % $parts;
        $result = [];
        for ($i = 0; $i < $parts; $i++) {
            $result[] = $base + ($i >= $parts - $remainder ? 1 : 0);
        }
        return $result;
    }

    /**
     * ساعات عمل اليوم: من الطلب أو افتراضياً من إعدادات العملية.
     *
     * @return array{0:string,1:string} [work_start, work_end] بصيغة HH:MM
     */
    private static function resolveDayWorkHours(array $campaign, ?string $workStart, ?string $workEnd): array
    {
        $start = trim((string) ($workStart ?? ''));
        $end = trim((string) ($workEnd ?? ''));
        if ($start === '') {
            $start = substr((string) ($campaign['work_start'] ?? '09:00'), 0, 5);
        } else {
            $start = substr($start, 0, 5);
        }
        if ($end === '') {
            $end = substr((string) ($campaign['work_end'] ?? '15:00'), 0, 5);
        } else {
            $end = substr($end, 0, 5);
        }

        if (!preg_match('/^\d{1,2}:\d{2}$/', $start) || !preg_match('/^\d{1,2}:\d{2}$/', $end)) {
            throw new \RuntimeException('توقيت العمل غير صالح.');
        }

        $startMin = self::toMinutes($start);
        $endMin = self::toMinutes($end);
        if ($endMin <= $startMin) {
            throw new \RuntimeException('وقت نهاية العمل يجب أن يكون بعد وقت البداية.');
        }
        if (($endMin - $startMin) < 60) {
            throw new \RuntimeException('فترة العمل يجب ألا تقل عن ساعة واحدة.');
        }

        return [self::fromMinutes($startMin), self::fromMinutes($endMin)];
    }

    /**
     * @return list<array{from:string,to:string}>
     */
    private static function buildTimeSlots(string $workStart, string $workEnd, int $count): array
    {
        $startMin = self::toMinutes($workStart);
        $endMin = self::toMinutes($workEnd);
        $hours = max(1, intdiv($endMin - $startMin, 60));
        // الباقي على الساعات الأخيرة (مثل 67 في آخر ساعات بدل الأولى)
        $hourBuckets = self::splitCountEndHeavy($count, $hours);
        $slots = [];
        $cursor = $startMin;

        foreach ($hourBuckets as $bucket) {
            $from = self::fromMinutes($cursor);
            $to = self::fromMinutes($cursor + 60);
            for ($i = 0; $i < $bucket; $i++) {
                $slots[] = ['from' => $from, 'to' => $to];
            }
            $cursor += 60;
        }

        return $slots;
    }

    private static function toMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5) . ':00'));
        return $h * 60 + $m;
    }

    private static function fromMinutes(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return sprintf('%02d:%02d', $h, $m);
    }

}
