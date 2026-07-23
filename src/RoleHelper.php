<?php

declare(strict_types=1);

namespace App;

final class RoleHelper
{
    public const ROLES = [
        'admin' => 'مدير النظام',
        'coordinator' => 'منسّق التوزيع',
        'reviewer' => 'مراجع',
        'viewer' => 'مشاهد',
        'warehouse_keeper' => 'أمين المخزن',
    ];

    /** @var array<string, list<string>> */
    public const PERMISSIONS = [
        'admin' => [
            'إدارة المستخدمين (إضافة/تعديل/تعطيل)',
            'إنشاء وتعديل العمليات وتوليد الكشوف',
            'تصدير Excel والتقارير',
            'متابعة المخزن وإنهاء/إعادة فتح التسليم',
            'تسليم من المخزن (ويب + تطبيق)',
            'تسليم جماعي يدوي وتصحيح الحالات والتراجع عن الدفعات',
            'إلغاء التسليمات',
            'نسخ احتياطي لقاعدة البيانات',
        ],
        'coordinator' => [
            'إنشاء وتعديل العمليات وتوليد الكشوف',
            'تصدير Excel',
            'متابعة المخزن (عرض فقط — بدون إنهاء التسليم)',
        ],
        'reviewer' => [
            'معاينة العمليات والمخزن',
            'تصدير Excel',
        ],
        'viewer' => [
            'معاينة العمليات والمخزن فقط',
        ],
        'warehouse_keeper' => [
            'تسليم من المخزن (ويب + تطبيق أندرويد)',
            'عرض العمليات المُولَّدة فقط بعد تسجيل الدخول',
        ],
    ];

    public static function label(string $role): string
    {
        return self::ROLES[$role] ?? $role;
    }

    /** @return list<string> */
    public static function permissions(string $role): array
    {
        return self::PERMISSIONS[$role] ?? [];
    }

    public static function canManageUsers(string $role): bool
    {
        return $role === 'admin';
    }

    public static function canCreateCampaign(string $role): bool
    {
        return in_array($role, ['admin', 'coordinator'], true);
    }

    public static function canEditCampaign(string $role): bool
    {
        return in_array($role, ['admin', 'coordinator'], true);
    }

    public static function canExport(string $role): bool
    {
        return in_array($role, ['admin', 'coordinator', 'reviewer'], true);
    }

    public static function canDeliver(string $role): bool
    {
        return in_array($role, ['admin', 'warehouse_keeper'], true);
    }

    public static function canViewStock(string $role): bool
    {
        return in_array($role, ['admin', 'coordinator', 'reviewer', 'viewer'], true);
    }

    public static function canManageDatabase(string $role): bool
    {
        return $role === 'admin';
    }

    /** إنهاء وإعادة فتح عملية التسليم — مدير النظام فقط */
    public static function canCloseDelivery(string $role): bool
    {
        return $role === 'admin';
    }

    /** إلغاء التسليمات — مدير النظام فقط */
    public static function canCancelDeliveries(string $role): bool
    {
        return $role === 'admin';
    }

    /** تسليم جماعي يدوي + تصحيح فردي + تراجع دفعة — مدير النظام فقط */
    public static function canBulkDeliver(string $role): bool
    {
        return $role === 'admin';
    }

    public static function homePath(string $role): string
    {
        if ($role === 'warehouse_keeper') {
            return '/warehouse';
        }
        return '/';
    }
}
