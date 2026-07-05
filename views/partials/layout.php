<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? config('app_name')) ?> — <?= e(config('app_name')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
</head>
<body>
<?php partial('partials/nav'); ?>
<main class="container">
    <?php if ($flash = get_flash()): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <?php require dirname(__DIR__) . '/' . $template . '.php'; ?>
</main>
</body>
</html>
