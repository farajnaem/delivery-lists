<?php

use App\Auth;

$isGuestLayout = !empty($guest) || !Auth::check();
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$appName = (string) config('app_name');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? $appName) ?> — <?= e($appName) ?></title>
    <link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
</head>
<body>
<?php if ($isGuestLayout): ?>
<div class="app-shell is-guest" data-app-shell>
    <main class="guest-wrap">
        <div style="width:min(100%,420px)">
            <?php if ($flash = get_flash()): ?>
            <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>
            <?php require dirname(__DIR__) . '/' . $template . '.php'; ?>
        </div>
    </main>
</div>
<?php else: ?>
<div class="app-shell" data-app-shell>
    <div class="sidebar-backdrop" data-sidebar-close></div>
    <?php partial('partials/sidebar', ['uri' => $uri, 'appName' => $appName]); ?>
    <div class="app-main">
        <?php partial('partials/topbar', ['appName' => $appName]); ?>
        <main class="app-content">
            <?php if ($flash = get_flash()): ?>
            <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>
            <?php require dirname(__DIR__) . '/' . $template . '.php'; ?>
        </main>
        <footer class="app-footer">
            <?= e($appName) ?> · نظام إدارة كشوفات التسليم
        </footer>
    </div>
</div>
<script src="<?= e(asset('/assets/js/app.js')) ?>" defer></script>
<?php endif; ?>
</body>
</html>
