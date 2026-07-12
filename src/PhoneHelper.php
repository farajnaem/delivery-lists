<?php

declare(strict_types=1);

namespace App;

final class PhoneHelper
{
    public const CARRIER_JAWWAL = 'jawwal';
    public const CARRIER_OOREDOO = 'ooredoo';
    public const CARRIER_OTHER = 'other';

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

    /** الرقم المحلي بدون 972، لاستخدامه في تحديد شركة الاتصالات. */
    public static function localNumber(string $mobile): string
    {
        $number = self::normalize($mobile);
        return str_starts_with($number, '972') ? substr($number, 3) : $number;
    }

    public static function carrier(string $mobile): string
    {
        $local = self::localNumber($mobile);
        if (str_starts_with($local, '59')) {
            return self::CARRIER_JAWWAL;
        }
        if (str_starts_with($local, '56')) {
            return self::CARRIER_OOREDOO;
        }
        return self::CARRIER_OTHER;
    }

    /**
     * رقم الإرسال في كشف الرسائل:
     * جوال يبقى محلياً (59...)، وأوريدو يصبح دولياً (97256...).
     */
    public static function messageRecipient(string $mobile): string
    {
        $local = self::localNumber($mobile);
        if (self::carrier($local) === self::CARRIER_OOREDOO) {
            return '972' . $local;
        }
        return $local;
    }
}
