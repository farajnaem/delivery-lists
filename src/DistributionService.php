<?php

declare(strict_types=1);

namespace App;

final class DistributionService
{
    /**
     * خطة التوزيع: إجمالي → أيام → شبابيك (حسب سعة الشباك) → مستفيدين/شباك.
     *
     * مثال: 10000 ÷ 5 أيام = 2000/يوم → 2000 ÷ 500 = 4 شبابيك → 500/شباك → 20 كشف.
     *
     * @return array{
     *   total:int,
     *   num_days:int,
     *   per_window:int,
     *   daily_counts:list<int>,
     *   total_delivery_sheets:int,
     *   days:list<array{day_index:int,beneficiaries:int,windows:int,per_window:list<int>}>
     * }
     */
    public static function plan(int $total, int $numDays, int $perWindow): array
    {
        $numDays = max(1, $numDays);
        $perWindow = max(1, $perWindow);
        $dailyCounts = self::splitCount($total, $numDays);
        $days = [];
        $totalSheets = 0;

        foreach ($dailyCounts as $i => $dayCount) {
            $numWindows = self::windowsForDay($dayCount, $perWindow);
            $windowSizes = self::splitCount($dayCount, $numWindows);
            $totalSheets += $numWindows;
            $days[] = [
                'day_index' => $i + 1,
                'beneficiaries' => $dayCount,
                'windows' => $numWindows,
                'per_window' => $windowSizes,
            ];
        }

        return [
            'total' => $total,
            'num_days' => $numDays,
            'per_window' => $perWindow,
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

        if (!str_starts_with(strtoupper(trim($campaign['parcel_code'])), 'SOCI')) {
            throw new \RuntimeException('كود الطرد يجب أن يبدأ بـ SOCI.');
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM beneficiaries WHERE campaign_id = ? ORDER BY id ASC');
        $stmt->execute([$campaignId]);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            throw new \RuntimeException('لا يوجد مستفيدون — ارفع ملف Excel أولاً.');
        }

        $numDays = max(1, (int) $campaign['num_days']);
        $perWindow = max(1, (int) $campaign['per_window_capacity']);
        $plan = self::plan(count($rows), $numDays, $perWindow);
        $dayBuckets = $plan['daily_counts'];
        $dates = self::buildDates($campaign['delivery_start'], $numDays);

        $codeNum = 1;
        $idx = 0;
        $summary = $plan;
        $summary['dates'] = $dates;

        $upd = $pdo->prepare('
            UPDATE beneficiaries SET
                mobile = ?,
                disbursement_code = ?, delivery_date = ?, window_num = ?,
                time_from = ?, time_to = ?, message_text = ?,
                day_index = ?, sort_order = ?
            WHERE id = ?
        ');

        $pdo->beginTransaction();
        try {
        for ($d = 0; $d < $numDays; $d++) {
            $dayCount = $dayBuckets[$d];
            $dayRows = array_slice($rows, $idx, $dayCount);
            $idx += $dayCount;

            $numWindows = self::windowsForDay($dayCount, $perWindow);
            $windowBuckets = self::splitCount($dayCount, $numWindows);
            $summary['days'][$d]['date'] = $dates[$d];
            $summary['days'][$d]['window_sizes'] = $windowBuckets;

            $rowOffset = 0;

            for ($w = 0; $w < $numWindows; $w++) {
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
                    $code = 'SOCI' . str_pad((string) $codeNum, 5, '0', STR_PAD_LEFT);
                    $mobile = PhoneHelper::normalize($row['mobile']);
                    $message = self::buildMessage($campaign, $row['name'], $dates[$d], $slot, $code, $w + 1);

                    $upd->execute([
                        $mobile,
                        $code,
                        $dates[$d],
                        $w + 1,
                        $slot['from'],
                        $slot['to'],
                        $message,
                        $d + 1,
                        $codeNum,
                        $row['id'],
                    ]);
                    $codeNum++;
                }
            }
        }

        $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        CampaignService::markGenerated($campaignId);
        if ((int) ($campaign['opening_quantity'] ?? 0) <= 0) {
            CampaignService::updateOpeningQuantity($campaignId, count($rows));
        }
        return $summary;
    }

    /** عدد الشبابيك ليوم واحد = ceil(مستفيدي_اليوم ÷ سعة_الشباك). */
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

    /** @return list<string> */
    private static function buildDates(string $start, int $days): array
    {
        $dates = [];
        $ts = strtotime($start) ?: time();
        for ($i = 0; $i < $days; $i++) {
            $dates[] = date('Y-m-d', strtotime("+{$i} day", $ts));
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

    private static function buildMessage(array $campaign, string $name, string $date, array $slot, string $code, int $window): string
    {
        return sprintf(
            'السيد / %s، يرجى التوجه إلى %s لاستلام %s وذلك يوم %s من الساعة %s إلى %s كود %s، شباك %d.',
            $name,
            $campaign['warehouse_name'],
            $campaign['parcel_name'],
            $date,
            $slot['from'],
            $slot['to'],
            $code,
            $window
        );
    }
}
