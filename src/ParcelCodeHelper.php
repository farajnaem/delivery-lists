<?php

declare(strict_types=1);

namespace App;

final class ParcelCodeHelper
{
    public const PREFIX = 'SOCI';
    public const PIN_MIN = 1;
    public const PIN_MAX = 99999;

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
     * كود الصرف الداخلي = SOCI + ملحق الطرد + رقم عشوائي (بدون أصفار يسار).
     * مثال: SOCI + R26 + 48291 → SOCIR2648291
     */
    public static function buildDisbursementCode(string $suffix, int|string $pin): string
    {
        $suffix = self::normalizeSuffix($suffix);
        $pin = self::normalizePin($pin);
        return self::PREFIX . $suffix . $pin;
    }

    /** توليد رقم عشوائي فريد داخل العملية (1–99999). */
    public static function generateRandomPin(array &$used): int
    {
        $attempts = 0;
        do {
            $pin = random_int(self::PIN_MIN, self::PIN_MAX);
            $attempts++;
            if ($attempts > 50000) {
                throw new \RuntimeException('تعذّر توليد أكواد صرف فريدة — قلّل عدد المستفيدين.');
            }
        } while (isset($used[$pin]));

        $used[$pin] = true;
        return $pin;
    }

    /** تحويل الرقم إلى صيغة مخزّنة/معروضة بدون أصفار يسار. */
    public static function normalizePin(int|string $pin): string
    {
        $digits = preg_replace('/\D/', '', (string) $pin) ?? '';
        if ($digits === '') {
            return '0';
        }

        return (string) (int) $digits;
    }

    /** الرقم كعدد صحيح للعرض والتصدير. */
    public static function pinAsInt(string $disbursementCode, ?string $suffix = null): int
    {
        return (int) self::extractPin($disbursementCode, $suffix);
    }

    /** الأرقام الظاهرة للمستفيد وأمين المخزن والشبابيك (بدون أصفار يسار). */
    public static function displayForBeneficiary(string $disbursementCode, ?string $suffix = null): string
    {
        $pin = self::extractPin($disbursementCode, $suffix);
        return $pin === '' ? $disbursementCode : $pin;
    }

    /** الكود الكامل للتقارير: الملحق + الرقم (مثل R2648291). */
    public static function displayFull(string $disbursementCode, ?string $suffix = null): string
    {
        $parsed = self::parseCode($disbursementCode, $suffix);
        if ($parsed === null) {
            return strtoupper(preg_replace('/\s+/', '', $disbursementCode) ?? $disbursementCode);
        }

        return $parsed['suffix'] . self::normalizePin($parsed['pin']);
    }

    public static function extractPin(string $disbursementCode, ?string $suffix = null): string
    {
        $parsed = self::parseCode($disbursementCode, $suffix);
        if ($parsed === null || $parsed['pin'] === '') {
            return '';
        }

        return self::normalizePin($parsed['pin']);
    }

    /**
     * @return array{suffix: string, pin: string, is_legacy: bool}|null
     */
    public static function parseCode(string $code, ?string $suffix = null): ?array
    {
        $code = strtoupper(preg_replace('/\s+/', '', $code) ?? $code);
        if ($code === '') {
            return null;
        }

        if (str_starts_with($code, self::PREFIX)) {
            $rest = substr($code, strlen(self::PREFIX));
            $parsed = self::splitSuffixAndPin($rest, $suffix);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        if (preg_match('/^([A-Z0-9]+?)(\d+)$/', $code, $m)) {
            return ['suffix' => $m[1], 'pin' => $m[2], 'is_legacy' => false];
        }

        return null;
    }

    /**
     * @return array{suffix: string, pin: string, is_legacy: bool}|null
     */
    private static function splitSuffixAndPin(string $rest, ?string $suffix): ?array
    {
        if ($suffix !== null && $suffix !== '') {
            $suffix = self::normalizeSuffix($suffix);
            if ($suffix !== '' && str_starts_with($rest, $suffix)) {
                $pin = substr($rest, strlen($suffix));
                if ($pin !== '' && ctype_digit($pin)) {
                    return ['suffix' => $suffix, 'pin' => $pin, 'is_legacy' => false];
                }
            }
        }

        if (preg_match('/^([A-Z0-9]*?)(\d+)$/', $rest, $m) && $m[2] !== '') {
            return ['suffix' => $m[1], 'pin' => $m[2], 'is_legacy' => false];
        }

        return null;
    }

    /** @deprecated استخدم displayForBeneficiary */
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

    /** مطابقة استعلام البحث مع كود مخزّن. */
    public static function matchSearchCandidates(string $query, string $suffix): array
    {
        $normalized = preg_replace('/\s+/', '', trim($query)) ?? trim($query);
        if ($normalized === '') {
            return [];
        }

        $upper = strtoupper($normalized);
        $candidates = [$upper];

        if (preg_match('/^\d+$/', $normalized)) {
            $candidates[] = self::buildDisbursementCode($suffix, (int) $normalized);
        }

        $parsed = self::parseCode($upper, $suffix);
        if ($parsed !== null) {
            $candidates[] = self::buildDisbursementCode($parsed['suffix'], $parsed['pin']);
            $candidates[] = $parsed['suffix'] . self::normalizePin($parsed['pin']);
        }

        return array_values(array_unique($candidates));
    }
}
