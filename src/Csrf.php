<?php

declare(strict_types=1);

namespace App;

final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function verify(?string $token): bool
    {
        return is_string($token)
            && isset($_SESSION[self::KEY])
            && is_string($_SESSION[self::KEY])
            && $_SESSION[self::KEY] !== ''
            && hash_equals($_SESSION[self::KEY], $token);
    }

    /** رسالة موحّدة عند فشل التحقق — ليست انتهاء حساب المستخدم */
    public static function failureMessage(): string
    {
        return 'انتهت صلاحية النموذج — حدّث الصفحة (F5) ثم أعد المحاولة. هذا ليس عطلاً في حسابك.';
    }

    public static function refresh(): string
    {
        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        return $_SESSION[self::KEY];
    }
}
