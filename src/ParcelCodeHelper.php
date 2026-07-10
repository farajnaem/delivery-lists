<?php

declare(strict_types=1);

namespace App;

final class ParcelCodeHelper
{
    public const PREFIX = 'SOCI';

    /** تطبيع ملحق كود الطرد (أحرف وأرقام فقط، أحرف كبيرة). */
    public static function normalizeSuffix(string $suffix): string
    {
        $suffix = strtoupper(trim($suffix));
        return preg_replace('/[^A-Z0-9]/', '', $suffix) ?? '';
    }

    public static function validateSuffix(string $suffix): bool
    {
        return self::normalizeSuffix($suffix) !== '';
    }

    /** عرض كود الطرد الكامل: SOCI + الملحق. */
    public static function formatParcelCode(string $suffix): string
    {
        $suffix = self::normalizeSuffix($suffix);
        return $suffix === '' ? self::PREFIX : self::PREFIX . $suffix;
    }

    /**
     * كود الصرف الداخلي = SOCI + ملحق الطرد + رقم تسلسلي (بدون أصفار).
     * مثال: SOCI + R26 + 1 → SOCIR261
     */
    public static function buildDisbursementCode(string $suffix, int $serial): string
    {
        $suffix = self::normalizeSuffix($suffix);
        return self::PREFIX . $suffix . (string) max(1, $serial);
    }

    /** الرقم التسلسلي الظاهر للمستفيد وأمين المخزن في الرسائل والبحث. */
    public static function displaySerial(int $serial): string
    {
        return (string) max(1, $serial);
    }

    /** استخراج الملحق من كود طرد قديم (مثل SOCI-R26 → R26). */
    public static function extractSuffixFromLegacy(string $parcelCode): string
    {
        $code = strtoupper(trim($parcelCode));
        if (!str_starts_with($code, self::PREFIX)) {
            return self::normalizeSuffix($parcelCode);
        }
        $rest = substr($code, strlen(self::PREFIX));
        return self::normalizeSuffix($rest);
    }
}
