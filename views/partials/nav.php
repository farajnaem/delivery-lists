<?php use App\Auth; use App\RoleHelper; ?>
<?php if (Auth::check()): ?>
<header class="topbar">
    <div class="topbar-inner">
        <a href="<?= e(url('/')) ?>" class="brand"><?= e(config('app_name')) ?></a>
        <nav class="nav-links">
            <a href="<?= e(url('/')) ?>">العمليات</a>
            <?php if (RoleHelper::canDeliver(Auth::role() ?? '')): ?>
            <a href="<?= e(url('/warehouse')) ?>">تسليم المخزن</a>
            <?php endif; ?>
            <?php if (RoleHelper::canManageUsers(Auth::role() ?? '')): ?>
            <a href="<?= e(url('/users')) ?>">المستخدمون</a>
            <?php endif; ?>
            <?php if (RoleHelper::canManageDatabase(Auth::role() ?? '')): ?>
            <a href="<?= e(url('/admin/database')) ?>">نسخ احتياطي</a>
            <?php endif; ?>
            <span class="user-chip"><?= e(Auth::user()['name'] ?? '') ?></span>
            <a href="<?= e(url('/logout')) ?>" class="btn btn-outline btn-sm">خروج</a>
        </nav>
    </div>
</header>
<?php endif; ?>
