<?php

declare(strict_types=1);

namespace App;

final class PhoneHelper
{
    /** يحوّل رقم الجوال إلى أرقام فقط بدون صفر في البداية (للتصدير والرسائل). */
    public static function normalize(string $mobile): string
    {
        $digits = preg_replace('/\D/u', '', $mobile) ?? '';
        $digits = ltrim($digits, '0');
        return $digits !== '' ? $digits : '0';
    }

    /** للعرض في Excel كرقم */
    public static function asNumeric(string $mobile): int|float
    {
        $n = self::normalize($mobile);
        return strlen($n) > 15 ? (float) $n : (int) $n;
    }
}
