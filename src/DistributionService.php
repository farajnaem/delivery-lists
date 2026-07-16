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

    /** @return list<int> */
    private static function allocateUniquePins(int $count): array
    {
        if ($count < 1) {
            return [];
        }
        $pool = ParcelCodeHelper::PIN_MAX - ParcelCodeHelper::PIN_MIN + 1;
        if ($count > $pool) {
            throw new \RuntimeException('عدد المستفيدين أكبر من الحد الأقصى لأكواد الصرف (' . number_format($pool) . ').');
        }

        // مجال 7 خانات كبير — عيّنة عشوائية دون بناء مصفوفة كاملة
        $used = [];
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
        [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)));
        return $h * 60 + $m;
    }

    private static function fromMinutes(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return sprintf('%02d:%02d', $h, $m);
    }

}
