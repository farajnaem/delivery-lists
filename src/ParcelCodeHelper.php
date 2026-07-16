<?php

declare(strict_types=1);

namespace App;

final class ParcelCodeHelper
{
    public const DEFAULT_PREFIX = 'SOCI';
    /** @deprecated استخدم DEFAULT_PREFIX */
    public const PREFIX = self::DEFAULT_PREFIX;
    public const PIN_MIN = 1000000;
    public const PIN_MAX = 9999999;
    public const PIN_WIDTH = 7;

    public static function normalizePrefix(string $prefix): string
    {
        $prefix = strtoupper(trim($prefix));
        return preg_replace('/[^A-Z0-9]/', '', $prefix) ?? '';
    }

    public static function validatePrefix(string $prefix): bool
    {
        return self::normalizePrefix($prefix) !== '';
    }

    /** تطبيع ملحق قديم (اختياري للتوافق مع البيانات السابقة). */
    public static function normalizeSuffix(string $suffix): string
    {
        $suffix = strtoupper(trim($suffix));
        return preg_replace('/[^A-Z0-9]/', '', $suffix) ?? '';
    }

    public static function validateSuffix(string $suffix): bool
    {
        return self::normalizeSuffix($suffix) !== '';
    }

    /** عرض كود الطرد (البادئة فقط). */
    public static function formatParcelCode(string $prefix, string $suffix = ''): string
    {
        $prefix = self::normalizePrefix($prefix);
        if ($prefix === '') {
            $prefix = self::DEFAULT_PREFIX;
        }

        return $prefix;
    }

    /**
     * كود الصرف الداخلي = كود الطرد + 7 أرقام (مع أصفار يسار عند الحاجة).
     * مثال: REC + 4829103 → REC4829103
     *
     * المعامل $suffix يُتجاهل في الصيغة الجديدة ويُبقى للتوافق مع الاستدعاءات القديمة.
     */
    public static function buildDisbursementCode(string $prefix, string $suffix, int|string $pin): string
    {
        $prefix = self::normalizePrefix($prefix);
        if ($prefix === '') {
            $prefix = self::DEFAULT_PREFIX;
        }

        return $prefix . self::padPin($pin);
    }

    /**
     * يتأكد أن كل أكواد الصرف مميزة داخل الطرد (الكود الكامل والرقم المعروض).
     *
     * @param list<string> $codes
     */
    public static function assertUniqueDisbursementCodes(array $codes, string $prefix, string $suffix = ''): void
    {
        $full = [];
        $display = [];
        $suffixOpt = $suffix !== '' ? $suffix : null;
        $prefixOpt = $prefix !== '' ? $prefix : null;

        foreach ($codes as $code) {
            $code = strtoupper(preg_replace('/\s+/', '', trim($code)) ?? trim($code));
            if ($code === '') {
                continue;
            }
            if (isset($full[$code])) {
                throw new \RuntimeException('كود الصرف مكرّر داخل الطرد: ' . $code);
            }
            $full[$code] = true;

            $pin = self::displayForBeneficiary($code, $suffixOpt, $prefixOpt);
            if ($pin !== '' && isset($display[$pin])) {
                throw new \RuntimeException('رقم كود الصرف مكرّر داخل الطرد: ' . $pin);
            }
            if ($pin !== '') {
                $display[$pin] = true;
            }
        }
    }

    /** رقم عشوائي فريد داخل الطرد (7 خانات) غير سهل التخمين. */
    public static function generateRandomPin(array &$used): int
    {
        $attempts = 0;
        $maxAttempts = max(50000, (self::PIN_MAX - self::PIN_MIN) * 2);
        do {
            $pin = random_int(self::PIN_MIN, self::PIN_MAX);
            $attempts++;
            if ($attempts > $maxAttempts) {
                throw new \RuntimeException('تعذّر توليد أكواد صرف فريدة — قلّل عدد المستفيدين.');
            }
        } while (isset($used[$pin]) || self::isGuessablePin($pin));

        $used[$pin] = true;
        return $pin;
    }

    /** أرقام متسلسلة أو مكرّرة يسهل تخمينها في المخزن. */
    public static function isGuessablePin(int $pin): bool
    {
        if ($pin < self::PIN_MIN || $pin > self::PIN_MAX) {
            return true;
        }

        $s = (string) $pin;
        if ($s === str_repeat($s[0], strlen($s))) {
            return true;
        }

        $asc = true;
        $desc = true;
        for ($i = 1, $len = strlen($s); $i < $len; $i++) {
            $prev = (int) $s[$i - 1];
            $cur = (int) $s[$i];
            if ($cur !== $prev + 1) {
                $asc = false;
            }
            if ($cur !== $prev - 1) {
                $desc = false;
            }
        }

        return $asc || $desc;
    }

    /** 7 خانات مع أصفار على اليسار (للكشوف والكود الداخلي). */
    public static function padPin(int|string $pin): string
    {
        $n = (int) self::normalizePin($pin);
        if ($n < 0) {
            $n = 0;
        }

        return str_pad((string) $n, self::PIN_WIDTH, '0', STR_PAD_LEFT);
    }

    /** أرقام فقط بدون أصفار يسار (للرسائل وتسليم المخزن). */
    public static function normalizePin(int|string $pin): string
    {
        $digits = preg_replace('/\D/', '', (string) $pin) ?? '';
        if ($digits === '') {
            return '0';
        }

        return (string) (int) $digits;
    }

    public static function pinAsInt(string $disbursementCode, ?string $suffix = null, ?string $prefix = null): int
    {
        return (int) self::extractPin($disbursementCode, $suffix, $prefix);
    }

    public static function displayForBeneficiary(string $disbursementCode, ?string $suffix = null, ?string $prefix = null): string
    {
        $pin = self::extractPin($disbursementCode, $suffix, $prefix);
        return $pin === '' ? $disbursementCode : $pin;
    }

    /**
     * العرض الكامل في الكشوف: كود الطرد + 7 أرقام.
     * مثال: SOCI4829103
     */
    public static function displayFull(string $disbursementCode, ?string $suffix = null, ?string $prefix = null): string
    {
        $parsed = self::parseCode($disbursementCode, $suffix, $prefix);
        if ($parsed === null) {
            return strtoupper(preg_replace('/\s+/', '', $disbursementCode) ?? $disbursementCode);
        }

        $usePrefix = self::normalizePrefix((string) ($prefix ?? ''));
        if ($usePrefix === '') {
            $usePrefix = self::DEFAULT_PREFIX;
            if (preg_match('/^([A-Z]+)/', strtoupper($disbursementCode), $m)) {
                $usePrefix = $m[1];
            }
        }

        return $usePrefix . self::padPin($parsed['pin']);
    }

    public static function extractPin(string $disbursementCode, ?string $suffix = null, ?string $prefix = null): string
    {
        $parsed = self::parseCode($disbursementCode, $suffix, $prefix);
        if ($parsed === null || $parsed['pin'] === '') {
            return '';
        }

        return self::normalizePin($parsed['pin']);
    }

    /**
     * @return array{suffix: string, pin: string, is_legacy: bool}|null
     */
    public static function parseCode(string $code, ?string $suffix = null, ?string $prefix = null): ?array
    {
        $code = strtoupper(preg_replace('/\s+/', '', $code) ?? $code);
        if ($code === '') {
            return null;
        }

        if ($prefix !== null && $prefix !== '') {
            $prefix = self::normalizePrefix($prefix);
            if ($prefix !== '' && str_starts_with($code, $prefix)) {
                $rest = substr($code, strlen($prefix));
                $parsed = self::splitSuffixAndPin($rest, $suffix);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        if (str_starts_with($code, self::DEFAULT_PREFIX)) {
            $rest = substr($code, strlen(self::DEFAULT_PREFIX));
            $parsed = self::splitSuffixAndPin($rest, $suffix);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        // صيغة جديدة أو قديمة: حروف ثم أرقام في النهاية
        if (preg_match('/^([A-Z0-9]*?)(\d+)$/', $code, $m) && $m[2] !== '') {
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
                    return ['suffix' => $suffix, 'pin' => $pin, 'is_legacy' => true];
                }
            }
        }

        // صيغة جديدة بعد البادئة: أرقام فقط (مع أصفار يسار)
        if ($rest !== '' && ctype_digit($rest)) {
            return ['suffix' => '', 'pin' => $rest, 'is_legacy' => false];
        }

        if (preg_match('/^([A-Z0-9]*?)(\d+)$/', $rest, $m) && $m[2] !== '') {
            return ['suffix' => $m[1], 'pin' => $m[2], 'is_legacy' => true];
        }

        return null;
    }

    /** @deprecated استخدم displayForBeneficiary */
    public static function displaySerial(int $serial): string
    {
        return (string) max(1, $serial);
    }

    public static function extractSuffixFromLegacy(string $parcelCode): string
    {
        $code = strtoupper(trim($parcelCode));
        if (!str_starts_with($code, self::DEFAULT_PREFIX)) {
            return self::normalizeSuffix($parcelCode);
        }
        $rest = substr($code, strlen(self::DEFAULT_PREFIX));
        return self::normalizeSuffix($rest);
    }

    public static function matchSearchCandidates(string $query, string $prefix, string $suffix): array
    {
        $normalized = preg_replace('/\s+/', '', ArabicFormat::toWesternDigits(trim($query))) ?? ArabicFormat::toWesternDigits(trim($query));
        if ($normalized === '') {
            return [];
        }

        $upper = strtoupper($normalized);
        $candidates = [$upper];
        $prefix = self::normalizePrefix($prefix);
        if ($prefix === '') {
            $prefix = self::DEFAULT_PREFIX;
        }

        if (preg_match('/^\d+$/', $normalized)) {
            $candidates[] = self::buildDisbursementCode($prefix, $suffix, (int) $normalized);
            $candidates[] = $prefix . self::padPin($normalized);
            $candidates[] = self::normalizePin($normalized);
        }

        $parsed = self::parseCode($upper, $suffix !== '' ? $suffix : null, $prefix);
        if ($parsed !== null) {
            $candidates[] = self::buildDisbursementCode($prefix, $parsed['suffix'], $parsed['pin']);
            $candidates[] = $prefix . self::padPin($parsed['pin']);
            $candidates[] = self::normalizePin($parsed['pin']);
            if ($parsed['suffix'] !== '') {
                $candidates[] = $parsed['suffix'] . self::normalizePin($parsed['pin']);
            }
        }

        return array_values(array_unique($candidates));
    }
}
