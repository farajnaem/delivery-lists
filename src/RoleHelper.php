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

    public static function label(string $role): string
    {
        return self::ROLES[$role] ?? $role;
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

    public static function homePath(string $role): string
    {
        if ($role === 'warehouse_keeper') {
            return '/warehouse';
        }
        return '/';
    }
}
