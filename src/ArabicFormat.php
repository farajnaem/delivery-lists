<?php

declare(strict_types=1);

namespace App;

/**
 * عرض الأرقام بالأرقام العربية (٠١٢٣…) والوقت بنظام 12 ساعة (ص/م).
 * التخزين في قاعدة البيانات يبقى بالأرقام الغربية وصيغة 24 ساعة.
 */
final class ArabicFormat
{
    private const WESTERN = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    private const ARABIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    public static function toArabicDigits(string|int|float|null $value): string
    {
        return str_replace(self::WESTERN, self::ARABIC, (string) ($value ?? ''));
    }

    public static function toWesternDigits(string|int|float|null $value): string
    {
        return str_replace(self::ARABIC, self::WESTERN, (string) ($value ?? ''));
    }

    public static function formatTime12(string $time24): string
    {
        $time24 = self::toWesternDigits(trim($time24));
        if ($time24 === '') {
            return '';
        }

        if (!preg_match('/^(\d{1,2}):(\d{2})/', $time24, $m)) {
            return self::toArabicDigits($time24);
        }

        $hour = (int) $m[1];
        $minute = $m[2];
        $period = $hour < 12 ? 'ص' : 'م';
        $hour12 = $hour % 12;
        if ($hour12 === 0) {
            $hour12 = 12;
        }

        return self::toArabicDigits(sprintf('%d:%s %s', $hour12, $minute, $period));
    }

    public static function formatTimeRange12(string $from, string $to): string
    {
        $from = self::formatTime12($from);
        $to = self::formatTime12($to);
        if ($from === '' || $to === '') {
            return '';
        }

        return 'من الساعة ' . $from . ' إلى ' . $to;
    }

    public static function formatDate(string $date): string
    {
        $western = self::toWesternDigits(trim($date));
        if ($western === '') {
            return '';
        }

        $ts = strtotime($western);
        if ($ts !== false) {
            return self::toArabicDigits(date('Y-m-d', $ts));
        }

        return self::toArabicDigits($western);
    }

    public static function formatDateTime(string $datetime): string
    {
        $western = self::toWesternDigits(trim($datetime));
        if ($western === '') {
            return '';
        }

        $ts = strtotime($western);
        if ($ts === false) {
            return self::toArabicDigits($western);
        }

        $date = self::toArabicDigits(date('Y-m-d', $ts));
        $time = self::formatTime12(date('H:i', $ts));

        return trim($date . ' ' . $time);
    }

    /**
     * تجهيز صف مستفيد للعرض (واجهة، API، رسائل).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function localizeBeneficiary(
        array $row,
        ?string $codePrefix = null,
        ?string $codeSuffix = null
    ): array {
        $code = trim((string) ($row['disbursement_code'] ?? ''));
        $suffix = $codeSuffix ?? (string) ($row['parcel_code_suffix'] ?? '');
        $prefix = $codePrefix ?? (string) ($row['parcel_code'] ?? '');

        if ($code !== '') {
            $row['display_code'] = self::toArabicDigits(
                ParcelCodeHelper::displayForBeneficiary(
                    $code,
                    $suffix !== '' ? $suffix : null,
                    $prefix !== '' ? $prefix : null
                )
            );
        } elseif (!empty($row['display_code'])) {
            $row['display_code'] = self::toArabicDigits((string) $row['display_code']);
        }

        foreach (['national_id', 'mobile', 'sort_order', 'window_num', 'day_index'] as $key) {
            if (isset($row[$key]) && $row[$key] !== '' && $row[$key] !== null) {
                $row[$key] = self::toArabicDigits((string) $row[$key]);
            }
        }

        if (!empty($row['delivery_date'])) {
            $row['delivery_date'] = self::formatDate((string) $row['delivery_date']);
        }
        if (!empty($row['actual_delivery_date'])) {
            $row['actual_delivery_date'] = self::formatDate((string) $row['actual_delivery_date']);
        }
        if (!empty($row['time_from'])) {
            $row['time_from'] = self::formatTime12((string) $row['time_from']);
        }
        if (!empty($row['time_to'])) {
            $row['time_to'] = self::formatTime12((string) $row['time_to']);
        }
        if (!empty($row['delivered_at'])) {
            $row['delivered_at'] = self::formatDateTime((string) $row['delivered_at']);
        }
        if (!empty($row['updated_at'])) {
            $row['updated_at'] = self::formatDateTime((string) $row['updated_at']);
        }

        return $row;
    }

    /** @param array<string, mixed> $campaign */
    public static function localizeCampaignTimes(array $campaign): array
    {
        if (!empty($campaign['work_start'])) {
            $campaign['work_start'] = self::formatTime12((string) $campaign['work_start']);
        }
        if (!empty($campaign['work_end'])) {
            $campaign['work_end'] = self::formatTime12((string) $campaign['work_end']);
        }
        if (!empty($campaign['delivery_start'])) {
            $campaign['delivery_start'] = self::formatDate((string) $campaign['delivery_start']);
        }
        if (!empty($campaign['delivery_end'])) {
            $campaign['delivery_end'] = self::formatDate((string) $campaign['delivery_end']);
        }
        if (!empty($campaign['generated_at'])) {
            $campaign['generated_at'] = self::formatDateTime((string) $campaign['generated_at']);
        }

        return $campaign;
    }

    /** @param array<string, mixed> $stats */
    public static function localizeStock(array $stats): array
    {
        foreach ([
            'total_beneficiaries',
            'opening_quantity',
            'delivered',
            'pending',
            'balance',
            'on_time',
            'late',
            'today_delivered',
            'planned_today',
        ] as $key) {
            if (isset($stats[$key])) {
                $stats[$key] = self::toArabicDigits((string) $stats[$key]);
            }
        }

        return $stats;
    }
}
