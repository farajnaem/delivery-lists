<?php

use App\Auth;
use App\RoleHelper;

$role = Auth::role() ?? '';
$canCreate = RoleHelper::canCreateCampaign($role);
$canDeliver = RoleHelper::canDeliver($role);
$canUsers = RoleHelper::canManageUsers($role);
$canDb = RoleHelper::canManageDatabase($role);

$uri = $uri ?? (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$appName = $appName ?? (string) config('app_name');

$campaignsActive = ($uri === '/' || $uri === '/campaigns' || preg_match('#^/campaigns/(view|edit|stock)#', $uri));
$createActive = str_starts_with($uri, '/campaigns/create');
?>
<aside class="sidebar" aria-label="القائمة الرئيسية">
    <a href="<?= e(url('/')) ?>" class="sidebar-brand">
        <span class="brand-mark">DL</span>
        <span class="brand-text">
            <strong><?= e($appName) ?></strong>
            <span>لوحة الإدارة</span>
        </span>
    </a>

    <nav class="sidebar-nav">
        <div class="nav-section">القائمة</div>

        <a class="nav-link <?= $campaignsActive && !$createActive ? 'is-active' : '' ?>" href="<?= e(url('/')) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5z"/></svg>
            <span>العمليات</span>
        </a>

        <?php if ($canCreate): ?>
        <a class="nav-link <?= $createActive ? 'is-active' : '' ?>" href="<?= e(url('/campaigns/create')) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            <span>عملية جديدة</span>
        </a>
        <?php endif; ?>

        <?php if ($canDeliver): ?>
        <a class="nav-link <?= str_starts_with($uri, '/warehouse') ? 'is-active' : '' ?>" href="<?= e(url('/warehouse')) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9.5 12 4l9 5.5V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/><path d="M9 21V12h6v9"/></svg>
            <span>تسليم المخزن</span>
        </a>
        <?php endif; ?>

        <?php if ($canUsers || $canDb): ?>
        <div class="nav-section">الإدارة</div>
        <?php endif; ?>

        <?php if ($canUsers): ?>
        <a class="nav-link <?= str_starts_with($uri, '/users') ? 'is-active' : '' ?>" href="<?= e(url('/users')) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 19v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1"/><circle cx="9.5" cy="7.5" r="3.5"/><path d="M20 19v-1a3.5 3.5 0 0 0-2.5-3.35"/><path d="M16.5 4.2a3.5 3.5 0 0 1 0 6.6"/></svg>
            <span>المستخدمون</span>
        </a>
        <?php endif; ?>

        <?php if ($canDb): ?>
        <a class="nav-link <?= str_starts_with($uri, '/admin/database') ? 'is-active' : '' ?>" href="<?= e(url('/admin/database')) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/></svg>
            <span>نسخ احتياطي</span>
        </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <button type="button" class="btn btn-ghost btn-sm sidebar-collapse-btn" data-sidebar-collapse>
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" style="stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M9 6 3 12l6 6M15 6l6 6-6 6"/></svg>
            <span>طي القائمة</span>
        </button>
    </div>
</aside>
