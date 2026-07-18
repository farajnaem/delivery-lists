<?php

use App\Auth;
use App\RoleHelper;

$user = Auth::user() ?? [];
$name = (string) ($user['name'] ?? '');
$role = (string) ($user['role'] ?? '');
$initial = $name !== '' ? mb_substr($name, 0, 1) : 'U';
$roleLabel = \App\RoleHelper::label($role);
?>
<header class="topbar">
    <button type="button" class="icon-btn mobile-menu-btn" data-sidebar-open aria-label="فتح القائمة">
        <svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>

    <div class="topbar-search">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="search" placeholder="بحث في الجدول…" data-global-search aria-label="بحث">
    </div>

    <div class="topbar-actions">
        <button type="button" class="icon-btn" title="الإشعارات (قريباً)" aria-label="الإشعارات">
            <svg viewBox="0 0 24 24"><path d="M6 17h12l-1.2-1.2A2 2 0 0 1 16 14.4V11a4 4 0 1 0-8 0v3.4a2 2 0 0 1-.8 1.4L6 17z"/><path d="M10 19a2 2 0 0 0 4 0"/></svg>
        </button>

        <div class="user-menu" data-dropdown>
            <button type="button" class="user-menu-trigger" data-dropdown-trigger aria-haspopup="true">
                <span class="user-avatar"><?= e($initial) ?></span>
                <span class="meta">
                    <strong><?= e($name) ?></strong>
                    <span><?= e($roleLabel) ?></span>
                </span>
            </button>
            <div class="dropdown" data-dropdown-menu>
                <a href="<?= e(url('/')) ?>">العمليات</a>
                <?php if (RoleHelper::canDeliver($role)): ?>
                <a href="<?= e(url('/warehouse')) ?>">تسليم المخزن</a>
                <?php endif; ?>
                <a href="<?= e(url('/logout')) ?>">تسجيل الخروج</a>
            </div>
        </div>
    </div>
</header>
