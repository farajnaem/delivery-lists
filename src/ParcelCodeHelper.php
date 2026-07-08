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
     * كود الصرف للمستفيد = SOCI + ملحق الطرد + رقم تسلسلي (5 خانات).
     * مثال: SOCI + R26 + 1 → SOCIR2600001
     */
    public static function buildDisbursementCode(string $suffix, int $serial): string
    {
        $suffix = self::normalizeSuffix($suffix);
        return self::PREFIX . $suffix . str_pad((string) max(1, $serial), 5, '0', STR_PAD_LEFT);
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
