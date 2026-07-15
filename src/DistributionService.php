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
        extend_runtime();

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
        $stmt = $pdo->prepare('SELECT * FROM beneficiaries WHERE campaign_id = ? ORDER BY id ASC');
        $stmt->execute([$campaignId]);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            throw new \RuntimeException('لا يوجد مستفيدون — ارفع ملف Excel أولاً.');
        }

        $perWindow = max(1, (int) $campaign['per_window_capacity']);
        $numWindows = self::resolveNumWindows($campaign, count($rows), $perWindow);
        $plan = self::plan(count($rows), $numWindows, $perWindow);
        $dayBuckets = $plan['daily_counts'];
        $numDays = $plan['num_days'];
        $dates = self::buildWorkDates((string) $campaign['delivery_start'], $numDays);

        $usedPins = [];
        $sortOrder = 1;
        $idx = 0;
        $summary = $plan;
        $summary['dates'] = $dates;

        $upd = $pdo->prepare('
            UPDATE beneficiaries SET
                mobile = ?,
                disbursement_code = ?, delivery_date = ?, window_num = ?,
                time_from = ?, time_to = ?, message_text = ?,
                day_index = ?, sort_order = ?, updated_at = ?
            WHERE id = ?
        ');

        $pdo->beginTransaction();
        try {
        $genNow = db_now();
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

                $slots = self::buildTimeSlots(
                    $campaign['work_start'],
                    $campaign['work_end'],
                    $windowCount
                );

                foreach ($windowRows as $i => $row) {
                    $slot = $slots[$i];
                    $pin = ParcelCodeHelper::generateRandomPin($usedPins);
                    $code = ParcelCodeHelper::buildDisbursementCode($codePrefix, $codeSuffix, $pin);
                    $mobile = PhoneHelper::normalize($row['mobile']);
                    $message = MessageTemplates::appointment($campaign, $row['name'], $dates[$d], $code, $w + 1);

                    $upd->execute([
                        $mobile,
                        $code,
                        $dates[$d],
                        $w + 1,
                        $slot['from'],
                        $slot['to'],
                        $message,
                        $d + 1,
                        $sortOrder,
                        $genNow,
                        $row['id'],
                    ]);
                    $sortOrder++;
                }
            }
        }

        // ترتيب أبجدي تصاعدي داخل كل يوم/شباك لتسهيل البحث — مع الإبقاء على اليوم والشباك والساعة
        self::reindexSortOrderAlphabetically($pdo, $campaignId);

        $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $deliveryEnd = $dates !== [] ? $dates[array_key_last($dates)] : (string) $campaign['delivery_start'];
        CampaignService::updateSchedule($campaignId, $numDays, $deliveryEnd);
        CampaignService::markGenerated($campaignId);
        if ((int) ($campaign['opening_quantity'] ?? 0) <= 0) {
            CampaignService::updateOpeningQuantity($campaignId, count($rows));
        }
        return $summary;
    }

    /**
     * للعمليات القديمة بدون num_windows: يستنتج من الأيام والسعة السابقة.
     *
     * @param array<string,mixed> $campaign
     */
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

    /**
     * يعيد ترقيم sort_order حسب الكود أبجدياً داخل كل يوم وشباك.
     * لا يغيّر day_index / window_num / المواعيد.
     */
    private static function reindexSortOrderAlphabetically(\PDO $pdo, int $campaignId): void
    {
        $stmt = $pdo->prepare('
            SELECT id FROM beneficiaries
            WHERE campaign_id = ?
            ORDER BY day_index ASC, window_num ASC, disbursement_code ASC, id ASC
        ');
        $stmt->execute([$campaignId]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $upd = $pdo->prepare('UPDATE beneficiaries SET sort_order = ? WHERE id = ?');
        $sortOrder = 1;
        foreach ($ids as $id) {
            $upd->execute([$sortOrder, (int) $id]);
            $sortOrder++;
        }
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

    /**
     * @return list<array{from:string,to:string}>
     */
    private static function buildTimeSlots(string $workStart, string $workEnd, int $count): array
    {
        $startMin = self::toMinutes($workStart);
        $endMin = self::toMinutes($workEnd);
        $hours = max(1, intdiv($endMin - $startMin, 60));
        $hourBuckets = self::splitCount($count, $hours);
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
